<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agentic Commerce Protocol (ACP, spec 2026-04-17) checkout sessions, backed by WooCommerce.
 *
 * This is the customer-facing agentic-checkout surface: a shopper's agent (e.g. ChatGPT Instant
 * Checkout) drives a real WooCommerce cart through the ACP session lifecycle -
 * create -> update (items, shipping, coupons, address) -> complete -> (cancel) - and the store
 * projects live WooCommerce pricing, shipping rates, coupons and tax back as ACP CheckoutSession
 * JSON. Money is always an integer in the currency's minor units (cents), per the ACP schema.
 *
 * Payment: ACP completes payment by handing the merchant a delegated/shared payment token
 * (e.g. a Stripe Shared Payment Token) in payment_data. Charging that token needs the store's PSP
 * wiring, so completion runs through the `ai2web_acp_complete_payment` filter: a site that has
 * configured a handler charges via the token; with no handler the session degrades safely to a
 * pending WooCommerce order plus that order's own secure pay link (the customer pays in the
 * browser, and the agent never handles card data) - consistent with the rest of AI2Web.
 *
 * A checkout session is backed by a WooCommerce draft order; the order IS the state (items,
 * coupons, shipping, addresses), so build_session() rebuilds the ACP view from it on every call.
 */
final class Ai2Web_ACP
{
    public const SPEC_VERSION = '2026-04-17';
    private const SESSION_TTL = 3600;                 // session lifetime (seconds)
    private const MAP_PREFIX  = 'ai2web_acp_sess_';   // transient: session_id -> order_id
    private const IDEM_PREFIX = 'ai2web_acp_idem_';   // transient: idempotency key -> session_id
    private const MAX_ITEMS   = 50;

    /** ISO 4217 currencies with no minor unit (amounts are whole units, not cents). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function enabled(): bool
    {
        return Ai2Web_Commerce::checkout_enabled() && (bool) Ai2Web_Settings::get('acp', true);
    }

    /**
     * Route an /ai2w/acp/* request. $id is the checkout_session_id (or null for the collection);
     * $action is a trailing 'complete' / 'cancel' (or null).
     *
     * @param array<string,mixed> $body
     * @return array{status:int,body:mixed,headers:array<string,string>}
     */
    public static function dispatch(string $method, ?string $id, ?string $action, array $body): array
    {
        if (!self::enabled()) {
            return self::err(404, 'not_found', 'ACP checkout is not enabled.');
        }

        if ($id === null) {
            return $method === 'POST'
                ? self::create($body)
                : self::err(405, 'invalid_request', 'Use POST to create a checkout session.');
        }
        if ($action === 'complete') {
            return $method === 'POST' ? self::complete($id, $body) : self::err(405, 'invalid_request', 'Use POST to complete.');
        }
        if ($action === 'cancel') {
            return $method === 'POST' ? self::cancel($id, $body) : self::err(405, 'invalid_request', 'Use POST to cancel.');
        }
        if ($method === 'GET') {
            return self::get($id);
        }
        if ($method === 'POST') {
            return self::update($id, $body);
        }
        return self::err(405, 'invalid_request', 'Unsupported method for a checkout session.');
    }

    // --- Lifecycle ---------------------------------------------------------

    /** @param array<string,mixed> $body @return array{status:int,body:mixed,headers:array<string,string>} */
    private static function create(array $body): array
    {
        // Idempotency: a repeated key returns the original session instead of a second order.
        $idem = self::idempotency_key();
        if ($idem !== '') {
            $existing = get_transient(self::IDEM_PREFIX . md5($idem));
            if (is_string($existing) && ($order = self::order_for($existing)) !== null) {
                return self::ok(200, self::build_session($order, $existing), $idem, true);
            }
        }

        $lines = isset($body['line_items']) && is_array($body['line_items']) ? $body['line_items'] : [];
        if (empty($lines)) {
            return self::err(400, 'invalid', 'At least one line item is required.', 'line_items');
        }
        if (count($lines) > self::MAX_ITEMS) {
            return self::err(400, 'invalid', 'Too many line items.', 'line_items');
        }

        $order = wc_create_order(['status' => 'checkout-draft', 'created_via' => 'ai2web-acp']);
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return self::err(502, 'order_failed', 'Could not start a checkout session.');
        }
        if (isset($body['currency']) && is_string($body['currency']) && $body['currency'] !== '') {
            $order->set_currency(strtoupper(sanitize_text_field($body['currency'])));
        }

        $err = self::apply_line_items($order, $lines, true);
        if ($err !== null) {
            self::discard($order);
            return $err;
        }
        self::apply_buyer($order, $body['buyer'] ?? null);
        self::apply_fulfillment_details($order, $body['fulfillment_details'] ?? null);
        $cerr = self::apply_discounts($order, $body['discounts'] ?? null);
        if ($cerr !== null) {
            self::discard($order);
            return $cerr;
        }

        $session_id = 'cs_' . wp_generate_password(24, false, false);
        $order->update_meta_data('_ai2web_acp_session', $session_id);
        $order->calculate_totals();
        $order->save();

        set_transient(self::MAP_PREFIX . $session_id, $order->get_id(), self::SESSION_TTL);
        if ($idem !== '') {
            set_transient(self::IDEM_PREFIX . md5($idem), $session_id, self::SESSION_TTL);
        }
        return self::ok(201, self::build_session($order, $session_id), $idem);
    }

    /** @return array{status:int,body:mixed,headers:array<string,string>} */
    private static function get(string $id): array
    {
        $order = self::order_for($id);
        if ($order === null) {
            return self::err(404, 'not_found', 'No such checkout session.');
        }
        return self::ok(200, self::build_session($order, $id));
    }

    /** @param array<string,mixed> $body @return array{status:int,body:mixed,headers:array<string,string>} */
    private static function update(string $id, array $body): array
    {
        $order = self::order_for($id);
        if ($order === null) {
            return self::err(404, 'not_found', 'No such checkout session.');
        }
        if (self::is_terminal($order)) {
            return self::err(409, 'invalid', 'This checkout session can no longer be modified.');
        }

        if (isset($body['line_items']) && is_array($body['line_items'])) {
            self::clear_products($order);
            $err = self::apply_line_items($order, $body['line_items'], false);
            if ($err !== null) {
                return $err;
            }
        }
        self::apply_fulfillment_details($order, $body['fulfillment_details'] ?? null);
        if (array_key_exists('discounts', $body)) {
            self::clear_coupons($order);
            $cerr = self::apply_discounts($order, $body['discounts']);
            if ($cerr !== null) {
                return $cerr;
            }
        }
        if (isset($body['selected_fulfillment_options']) && is_array($body['selected_fulfillment_options'])) {
            self::apply_selected_fulfillment($order, $body['selected_fulfillment_options']);
        }

        $order->calculate_totals();
        $order->save();
        return self::ok(200, self::build_session($order, $id));
    }

    /** @param array<string,mixed> $body @return array{status:int,body:mixed,headers:array<string,string>} */
    private static function complete(string $id, array $body): array
    {
        $order = self::order_for($id);
        if ($order === null) {
            return self::err(404, 'not_found', 'No such checkout session.');
        }
        if (self::is_terminal($order)) {
            return self::err(409, 'invalid', 'This checkout session is already finalised.');
        }

        $buyer = isset($body['buyer']) && is_array($body['buyer']) ? $body['buyer'] : [];
        $email = isset($buyer['email']) ? sanitize_email((string) $buyer['email']) : '';
        if ($email === '' || !is_email($email)) {
            return self::err(400, 'invalid', 'A buyer email is required to complete checkout.', 'buyer.email');
        }
        $payment = isset($body['payment_data']) && is_array($body['payment_data']) ? $body['payment_data'] : [];
        if (empty($payment)) {
            return self::err(400, 'invalid', 'payment_data is required to complete checkout.', 'payment_data');
        }
        self::apply_buyer($order, $buyer);
        if (isset($body['order_notes']) && is_string($body['order_notes']) && $body['order_notes'] !== '') {
            $order->add_order_note(sprintf(__('AI2Web (ACP) buyer note: %s', 'ai2web'), sanitize_textarea_field($body['order_notes'])), true);
        }
        $order->calculate_totals();

        $token = self::payment_token($payment);

        // Payment handler seam. Default (no handler): null. A site that has wired a PSP returns
        // true on success, or false / WP_Error on decline. We only treat a hard boolean as a
        // configured attempt; null means "no handler installed" and we degrade to a pay link.
        $charged = apply_filters('ai2web_acp_complete_payment', null, $order, $payment, $token);

        if ($charged === true) {
            $order->set_status('processing');
            $order->payment_complete();
            $order->add_order_note(__('AI2Web (ACP): paid via delegated payment token.', 'ai2web'), false);
            $order->update_meta_data('_ai2web_acp_state', 'completed');
            $order->save();
            return self::ok(200, self::build_session($order, $id));
        }

        if ($charged instanceof WP_Error) {
            return self::err(402, 'payment_declined', $charged->get_error_message() ?: 'Payment was declined.');
        }
        if ($charged === false) {
            return self::err(402, 'payment_declined', 'Payment was declined.');
        }

        // No PSP handler configured: create a real pending order and hand back its secure pay link.
        // The customer completes payment in the browser; no card data ever reaches the agent.
        $order->set_status('pending');
        $order->add_order_note(__('AI2Web (ACP): order created via an AI agent; awaiting payment via the returned link.', 'ai2web'), false);
        $order->save();
        $session = self::build_session($order, $id);
        return self::ok(200, $session);
    }

    /** @param array<string,mixed> $body @return array{status:int,body:mixed,headers:array<string,string>} */
    private static function cancel(string $id, array $body): array
    {
        $order = self::order_for($id);
        if ($order === null) {
            return self::err(404, 'not_found', 'No such checkout session.');
        }
        if (!self::is_paid($order)) {
            $order->update_meta_data('_ai2web_acp_state', 'canceled');
            $order->set_status('cancelled');
            $order->save();
        }
        delete_transient(self::MAP_PREFIX . $id);
        return self::ok(200, self::build_session($order, $id));
    }

    // --- WooCommerce mutation helpers -------------------------------------

    /**
     * Resolve and add ACP line items to the order. On create, ACP Items may omit quantity
     * (defaults to 1); on update, LineItems carry quantity.
     *
     * @param array<int,mixed> $lines
     * @return array{status:int,body:mixed,headers:array<string,string>}|null error, or null on success
     */
    private static function apply_line_items(WC_Order $order, array $lines, bool $creating): ?array
    {
        if (count($lines) > self::MAX_ITEMS) {
            return self::err(400, 'invalid', 'Too many line items.', 'line_items');
        }
        $added = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                return self::err(400, 'invalid', 'Each line item must be an object.', 'line_items');
            }
            $ref = '';
            // Prefer the nested item.id (ACP), then a bare id, then a sku.
            if (isset($line['item']['id'])) {
                $ref = (string) $line['item']['id'];
            } elseif (isset($line['id'])) {
                $ref = (string) $line['id'];
            } elseif (isset($line['sku'])) {
                $ref = (string) $line['sku'];
            }
            $qty = isset($line['quantity']) ? absint($line['quantity']) : 1;
            if ($qty < 1 || $qty > 100) {
                return self::err(400, 'invalid', 'Quantity must be between 1 and 100.', 'line_items');
            }
            $product = self::resolve_product($ref);
            if ($product && $product->is_type('variable')) {
                return self::err(400, 'invalid', sprintf('Choose a variation for "%s".', $product->get_name()), 'line_items');
            }
            if (!$product || $product->get_status() !== 'publish' || !$product->is_purchasable()) {
                return self::err(404, 'not_found', sprintf('Item "%s" is unavailable.', $ref), 'line_items');
            }
            if (!$product->has_enough_stock($qty)) {
                return self::err(409, 'out_of_stock', sprintf('Not enough stock for %s.', $product->get_name()), 'line_items');
            }
            $order->add_product($product, $qty);
            $added++;
        }
        if ($creating && $added === 0) {
            return self::err(400, 'invalid', 'No purchasable line items.', 'line_items');
        }
        return null;
    }

    private static function resolve_product(string $ref): ?WC_Product
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        if (ctype_digit($ref)) {
            $p = wc_get_product((int) $ref);
            if ($p instanceof WC_Product) {
                return $p;
            }
        }
        $id = wc_get_product_id_by_sku($ref);
        return $id ? (wc_get_product($id) ?: null) : null;
    }

    /** @param mixed $buyer */
    private static function apply_buyer(WC_Order $order, $buyer): void
    {
        if (!is_array($buyer)) {
            return;
        }
        if (isset($buyer['email']) && is_email((string) $buyer['email'])) {
            $order->set_billing_email(sanitize_email((string) $buyer['email']));
        }
        if (isset($buyer['first_name'])) {
            $order->set_billing_first_name(sanitize_text_field((string) $buyer['first_name']));
        }
        if (isset($buyer['last_name'])) {
            $order->set_billing_last_name(sanitize_text_field((string) $buyer['last_name']));
        }
        if (isset($buyer['phone_number'])) {
            $order->set_billing_phone(sanitize_text_field((string) $buyer['phone_number']));
        }
    }

    /** @param mixed $details ACP FulfillmentDetails { name, phone_number, email, address }. */
    private static function apply_fulfillment_details(WC_Order $order, $details): void
    {
        if (!is_array($details)) {
            return;
        }
        $addr = isset($details['address']) && is_array($details['address']) ? $details['address'] : null;
        if ($addr === null) {
            return;
        }
        $name = isset($details['name']) ? sanitize_text_field((string) $details['name']) : (string) ($addr['name'] ?? '');
        [$first, $last] = self::split_name($name);
        $shipping = array_filter([
            'first_name' => $first,
            'last_name' => $last,
            'address_1' => isset($addr['line_one']) ? sanitize_text_field((string) $addr['line_one']) : '',
            'address_2' => isset($addr['line_two']) ? sanitize_text_field((string) $addr['line_two']) : '',
            'city' => isset($addr['city']) ? sanitize_text_field((string) $addr['city']) : '',
            'state' => isset($addr['state']) ? sanitize_text_field((string) $addr['state']) : '',
            'postcode' => isset($addr['postal_code']) ? sanitize_text_field((string) $addr['postal_code']) : '',
            'country' => isset($addr['country']) ? strtoupper(sanitize_text_field((string) $addr['country'])) : '',
        ], static fn($v) => $v !== '');
        $order->set_address($shipping, 'shipping');
        // Mirror to billing where billing is still empty, so tax/pricing has an address to use.
        if ($order->get_billing_country() === '' && isset($shipping['country'])) {
            $order->set_address($shipping, 'billing');
        }
        // Recompute available shipping and default-select the cheapest if none chosen yet.
        self::ensure_shipping_selected($order);
    }

    /** @param mixed $discounts ACP DiscountsRequest { codes: string[] }. */
    private static function apply_discounts(WC_Order $order, $discounts): ?array
    {
        if (!is_array($discounts)) {
            return null;
        }
        $codes = isset($discounts['codes']) && is_array($discounts['codes']) ? $discounts['codes'] : [];
        foreach ($codes as $code) {
            if (!is_string($code) || $code === '') {
                continue;
            }
            $applied = $order->apply_coupon(wc_format_coupon_code(sanitize_text_field($code)));
            if (is_wp_error($applied)) {
                return self::err(400, 'invalid', $applied->get_error_message(), 'discounts.codes');
            }
        }
        return null;
    }

    /** @param array<int,mixed> $selected ACP SelectedFulfillmentOption[]. */
    private static function apply_selected_fulfillment(WC_Order $order, array $selected): void
    {
        foreach ($selected as $sel) {
            if (is_array($sel) && isset($sel['option_id']) && is_string($sel['option_id'])) {
                self::set_shipping_rate($order, sanitize_text_field($sel['option_id']));
                return;
            }
        }
    }

    // --- Shipping ----------------------------------------------------------

    /**
     * Compute the WooCommerce shipping rates available for this order's destination.
     * @return array<string,WC_Shipping_Rate> keyed by rate id
     */
    private static function shipping_rates(WC_Order $order): array
    {
        if (!function_exists('wc_shipping_enabled') || !wc_shipping_enabled()) {
            return [];
        }
        if ($order->get_shipping_country() === '') {
            return [];
        }
        $contents = [];
        $cost = 0.0;
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
            if (!$product instanceof WC_Product || !$product->needs_shipping()) {
                continue;
            }
            $line = (float) $item->get_total();
            $cost += $line;
            $contents[$item_id] = [
                'data' => $product,
                'quantity' => $item->get_quantity(),
                'line_total' => $line,
            ];
        }
        if (empty($contents)) {
            return []; // fully virtual order - no shipping needed
        }
        $package = [
            'contents' => $contents,
            'contents_cost' => $cost,
            'applied_coupons' => $order->get_coupon_codes(),
            'user' => ['ID' => get_current_user_id()],
            'destination' => [
                'country' => $order->get_shipping_country(),
                'state' => $order->get_shipping_state(),
                'postcode' => $order->get_shipping_postcode(),
                'city' => $order->get_shipping_city(),
                'address' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
            ],
        ];
        try {
            $calc = WC()->shipping()->calculate_shipping_for_package($package);
            return isset($calc['rates']) && is_array($calc['rates']) ? $calc['rates'] : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Attach the WooCommerce shipping rate with the given id to the order. */
    private static function set_shipping_rate(WC_Order $order, string $rate_id): void
    {
        $rates = self::shipping_rates($order);
        if (!isset($rates[$rate_id])) {
            return;
        }
        self::clear_shipping($order);
        $item = new WC_Order_Item_Shipping();
        $rate = $rates[$rate_id];
        $item->set_method_title($rate->get_label());
        $item->set_method_id($rate->get_method_id());
        if (method_exists($item, 'set_instance_id')) {
            $item->set_instance_id($rate->get_instance_id());
        }
        $item->set_total((string) $rate->get_cost());
        $item->set_taxes($rate->get_taxes());
        $order->add_item($item);
    }

    /** Default-select the cheapest shipping rate if the order needs shipping and none is set. */
    private static function ensure_shipping_selected(WC_Order $order): void
    {
        foreach ($order->get_items('shipping') as $s) {
            return; // already has a shipping line
        }
        $rates = self::shipping_rates($order);
        if (empty($rates)) {
            return;
        }
        uasort($rates, static fn($a, $b) => (float) $a->get_cost() <=> (float) $b->get_cost());
        $first = array_key_first($rates);
        if ($first !== null) {
            self::set_shipping_rate($order, (string) $first);
        }
    }

    // --- ACP CheckoutSession projection -----------------------------------

    /**
     * Build the ACP CheckoutSession JSON view of a WooCommerce order.
     * @return array<string,mixed>
     */
    private static function build_session(WC_Order $order, string $session_id): array
    {
        $currency = $order->get_currency();

        $line_items = [];
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
            $pid = $product instanceof WC_Product ? $product->get_id() : 0;
            $unit = $product instanceof WC_Product ? self::to_minor((float) $product->get_price(), $currency) : 0;
            $li = [
                'id' => (string) $item_id,
                'item' => ['id' => (string) $pid, 'name' => $item->get_name(), 'unit_amount' => $unit],
                'quantity' => (int) $item->get_quantity(),
                'name' => $item->get_name(),
                'unit_amount' => $unit,
                'totals' => [[
                    'type' => 'total',
                    'display_text' => $item->get_name(),
                    'amount' => self::to_minor((float) $item->get_total(), $currency),
                ]],
            ];
            if ($product instanceof WC_Product) {
                $li['product_id'] = (string) $pid;
                if ($product->get_sku() !== '') {
                    $li['sku'] = $product->get_sku();
                }
                if ($product->is_type('variation')) {
                    $li['variant_id'] = (string) $pid;
                }
                $li['availability_status'] = $product->is_in_stock() ? 'in_stock' : 'out_of_stock';
            }
            $line_items[] = $li;
        }

        $session = [
            'id' => $session_id,
            'protocol' => ['version' => self::SPEC_VERSION],
            'status' => self::status_of($order),
            'currency' => strtolower($currency),
            'line_items' => $line_items,
            'fulfillment_options' => self::fulfillment_options($order, $currency),
            'selected_fulfillment_options' => self::selected_fulfillment($order),
            'totals' => self::totals($order, $currency),
            'messages' => self::messages($order),
            'links' => self::links(),
            'created_at' => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'updated_at' => $order->get_date_modified() ? $order->get_date_modified()->date('c') : null,
        ];

        $buyer = self::buyer($order);
        if (!empty($buyer)) {
            $session['buyer'] = $buyer;
        }
        $fd = self::fulfillment_details($order);
        if (!empty($fd)) {
            $session['fulfillment_details'] = $fd;
        }
        // A pending/paid order is purchasable in the browser - expose its secure pay link.
        if (in_array($order->get_status(), ['pending', 'processing', 'completed', 'on-hold'], true)) {
            $session['continue_url'] = $order->get_checkout_payment_url();
        }
        // Expose the ACP order object once the session has been committed to an order (i.e. it is
        // no longer an in-progress draft): after complete (pending/paid) or cancel.
        if ($order->get_status() !== 'checkout-draft') {
            $session['order'] = self::order_object($order);
        }
        return $session;
    }

    /** @return array<int,array<string,mixed>> */
    private static function fulfillment_options(WC_Order $order, string $currency): array
    {
        $out = [];
        foreach (self::shipping_rates($order) as $rate_id => $rate) {
            $out[] = [
                'type' => 'shipping',
                'id' => (string) $rate_id,
                'title' => $rate->get_label(),
                'carrier' => $rate->get_method_id(),
                'totals' => [[
                    'type' => 'fulfillment',
                    'display_text' => $rate->get_label(),
                    'amount' => self::to_minor((float) $rate->get_cost(), $currency),
                ]],
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function selected_fulfillment(WC_Order $order): array
    {
        $out = [];
        foreach ($order->get_items('shipping') as $s) {
            if ($s instanceof WC_Order_Item_Shipping) {
                $out[] = ['type' => 'shipping', 'option_id' => $s->get_method_id() . ':' . $s->get_instance_id(), 'item_ids' => []];
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    private static function totals(WC_Order $order, string $currency): array
    {
        $subtotal = (float) $order->get_subtotal();
        $discount = (float) $order->get_total_discount();
        $shipping = (float) $order->get_shipping_total();
        $tax = (float) $order->get_total_tax();
        $total = (float) $order->get_total();

        $totals = [[
            'type' => 'items_base_amount',
            'display_text' => __('Items', 'ai2web'),
            'amount' => self::to_minor($subtotal, $currency),
        ]];
        if ($discount > 0) {
            $totals[] = ['type' => 'discount', 'display_text' => __('Discount', 'ai2web'), 'amount' => self::to_minor($discount, $currency)];
        }
        $totals[] = ['type' => 'subtotal', 'display_text' => __('Subtotal', 'ai2web'), 'amount' => self::to_minor(max(0.0, $subtotal - $discount), $currency)];
        if ($shipping > 0 || !empty($order->get_items('shipping'))) {
            $totals[] = ['type' => 'fulfillment', 'display_text' => __('Shipping', 'ai2web'), 'amount' => self::to_minor($shipping, $currency)];
        }
        if ($tax > 0) {
            $totals[] = ['type' => 'tax', 'display_text' => __('Tax', 'ai2web'), 'amount' => self::to_minor($tax, $currency)];
        }
        $totals[] = ['type' => 'total', 'display_text' => __('Total', 'ai2web'), 'amount' => self::to_minor($total, $currency)];
        return $totals;
    }

    /** @return array<int,array<string,mixed>> */
    private static function messages(WC_Order $order): array
    {
        $messages = [];
        if ($order->get_shipping_country() === '' && self::order_needs_shipping($order)) {
            $messages[] = [
                'type' => 'info',
                'severity' => 'info',
                'resolution' => 'requires_buyer_input',
                'content_type' => 'plain',
                'content' => __('Provide a shipping address to see delivery options and final totals.', 'ai2web'),
            ];
        }
        return $messages;
    }

    /** @return array<int,array<string,string>> */
    private static function links(): array
    {
        $links = [];
        $privacy = get_privacy_policy_url();
        if ($privacy) {
            $links[] = ['type' => 'privacy_policy', 'url' => $privacy];
        }
        if (function_exists('wc_get_page_id')) {
            $terms = wc_get_page_id('terms');
            if ($terms > 0) {
                $links[] = ['type' => 'terms_of_use', 'url' => (string) get_permalink($terms)];
            }
        }
        return $links;
    }

    /** @return array<string,mixed> */
    private static function buyer(WC_Order $order): array
    {
        $buyer = [];
        if ($order->get_billing_email() !== '') {
            $buyer['email'] = $order->get_billing_email();
        }
        if ($order->get_billing_first_name() !== '') {
            $buyer['first_name'] = $order->get_billing_first_name();
        }
        if ($order->get_billing_last_name() !== '') {
            $buyer['last_name'] = $order->get_billing_last_name();
        }
        if ($order->get_billing_phone() !== '') {
            $buyer['phone_number'] = $order->get_billing_phone();
        }
        return $buyer;
    }

    /** @return array<string,mixed> */
    private static function fulfillment_details(WC_Order $order): array
    {
        if ($order->get_shipping_address_1() === '' && $order->get_shipping_postcode() === '') {
            return [];
        }
        return [
            'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
            'address' => array_filter([
                'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                'line_one' => $order->get_shipping_address_1(),
                'line_two' => $order->get_shipping_address_2(),
                'city' => $order->get_shipping_city(),
                'state' => $order->get_shipping_state(),
                'country' => $order->get_shipping_country(),
                'postal_code' => $order->get_shipping_postcode(),
            ], static fn($v) => $v !== ''),
        ];
    }

    /** @return array<string,mixed> ACP Order object (present once created/paid). */
    private static function order_object(WC_Order $order): array
    {
        return [
            'type' => 'order',
            'id' => (string) $order->get_id(),
            'checkout_session_id' => (string) $order->get_meta('_ai2web_acp_session'),
            'order_number' => $order->get_order_number(),
            'permalink_url' => self::is_paid($order) ? $order->get_view_order_url() : $order->get_checkout_payment_url(),
            'status' => self::order_status($order),
        ];
    }

    // --- Status helpers ----------------------------------------------------

    private static function status_of(WC_Order $order): string
    {
        if ((string) $order->get_meta('_ai2web_acp_state') === 'canceled' || $order->get_status() === 'cancelled') {
            return 'canceled';
        }
        if (self::is_paid($order)) {
            return 'completed';
        }
        // A pending order (payment-link fallback) is awaiting the buyer's browser payment.
        if ($order->get_status() === 'pending') {
            return 'ready_for_payment';
        }
        $has_items = count($order->get_items()) > 0;
        $needs_ship = self::order_needs_shipping($order);
        $ready = $has_items
            && $order->get_billing_email() !== ''
            && (!$needs_ship || !empty($order->get_items('shipping')));
        return $ready ? 'ready_for_payment' : 'not_ready_for_payment';
    }

    private static function order_status(WC_Order $order): string
    {
        return match ($order->get_status()) {
            'processing', 'completed' => 'confirmed',
            'cancelled' => 'canceled',
            default => 'created',
        };
    }

    private static function is_paid(WC_Order $order): bool
    {
        return in_array($order->get_status(), ['processing', 'completed', 'on-hold'], true) || $order->is_paid();
    }

    private static function is_terminal(WC_Order $order): bool
    {
        return $order->get_status() === 'cancelled' || (string) $order->get_meta('_ai2web_acp_state') === 'canceled' || self::is_paid($order);
    }

    private static function order_needs_shipping(WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            $product = $item instanceof WC_Order_Item_Product ? $item->get_product() : null;
            if ($product instanceof WC_Product && $product->needs_shipping()) {
                return true;
            }
        }
        return false;
    }

    // --- Product feed (ACP feed spec) -------------------------------------

    /**
     * ACP product feed: an array of Product objects, each with one or more Variants. Powers
     * catalogue ingestion by buying agents. GET /ai2w/acp/feed.
     * @return array<int,array<string,mixed>>
     */
    public static function feed(int $limit = 100): array
    {
        if (!function_exists('wc_get_products')) {
            return [];
        }
        $currency = get_woocommerce_currency();
        $out = [];
        foreach (wc_get_products(['limit' => $limit, 'status' => 'publish']) as $product) {
            if (!$product instanceof WC_Product) {
                continue;
            }
            $variants = [];
            if ($product instanceof WC_Product_Variable) {
                foreach ($product->get_children() as $vid) {
                    $v = wc_get_product($vid);
                    if ($v instanceof WC_Product_Variation && $v->get_status() === 'publish') {
                        $variants[] = self::feed_variant($v, $currency, true);
                    }
                }
            }
            if (empty($variants)) {
                $variants[] = self::feed_variant($product, $currency, false);
            }
            $out[] = array_filter([
                'id' => (string) $product->get_id(),
                'title' => $product->get_name(),
                'description' => ['plain' => wp_strip_all_tags($product->get_short_description() ?: $product->get_description())],
                'url' => (string) get_permalink($product->get_id()),
                'media' => self::feed_media($product),
                'variants' => $variants,
            ], static fn($v) => $v !== [] && $v !== '');
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function feed_variant(WC_Product $v, string $currency, bool $is_variation): array
    {
        $variant = [
            'id' => (string) $v->get_id(),
            'title' => $v->get_name(),
            'price' => ['amount' => self::to_minor((float) $v->get_price(), $currency), 'currency' => strtoupper($currency)],
            'availability' => ['available' => $v->is_in_stock(), 'status' => $v->is_in_stock() ? 'in_stock' : 'out_of_stock'],
            'url' => (string) get_permalink($v->get_id()),
        ];
        if ($v->get_sku() !== '') {
            $variant['barcodes'] = [['type' => 'sku', 'value' => $v->get_sku()]];
        }
        if ($is_variation && $v instanceof WC_Product_Variation) {
            $opts = [];
            foreach ($v->get_variation_attributes() as $key => $value) {
                if ($value === '') {
                    continue;
                }
                $opts[] = ['name' => wc_attribute_label(str_replace('attribute_', '', (string) $key)), 'value' => (string) $value];
            }
            if ($opts) {
                $variant['variant_options'] = $opts;
            }
        }
        return $variant;
    }

    /** @return array<int,array<string,mixed>> */
    private static function feed_media(WC_Product $product): array
    {
        $media = [];
        $img = wp_get_attachment_image_url($product->get_image_id(), 'full');
        if ($img) {
            $media[] = ['type' => 'image', 'url' => $img];
        }
        return $media;
    }

    // --- Small utilities ---------------------------------------------------

    private static function to_minor(float $amount, string $currency): int
    {
        $factor = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100;
        return (int) round($amount * $factor);
    }

    private static function order_for(string $session_id): ?WC_Order
    {
        $order_id = get_transient(self::MAP_PREFIX . $session_id);
        if (!$order_id) {
            return null;
        }
        $order = wc_get_order((int) $order_id);
        if (!$order instanceof WC_Order || (string) $order->get_meta('_ai2web_acp_session') !== $session_id) {
            return null;
        }
        return $order;
    }

    private static function clear_products(WC_Order $order): void
    {
        foreach ($order->get_items() as $item_id => $item) {
            $order->remove_item($item_id);
        }
    }

    private static function clear_coupons(WC_Order $order): void
    {
        foreach ($order->get_coupon_codes() as $code) {
            $order->remove_coupon($code);
        }
    }

    private static function clear_shipping(WC_Order $order): void
    {
        foreach ($order->get_items('shipping') as $item_id => $item) {
            $order->remove_item($item_id);
        }
    }

    private static function discard(WC_Order $order): void
    {
        $order->delete(true);
    }

    /** @param array<string,mixed> $payment */
    private static function payment_token(array $payment): string
    {
        if (isset($payment['instrument']['credential']['token']) && is_string($payment['instrument']['credential']['token'])) {
            return sanitize_text_field($payment['instrument']['credential']['token']);
        }
        return '';
    }

    /** @return array{0:string,1:string} */
    private static function split_name(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $name, 2) ?: [$name];
        return [$parts[0], $parts[1] ?? ''];
    }

    private static function idempotency_key(): string
    {
        return isset($_SERVER['HTTP_IDEMPOTENCY_KEY']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_IDEMPOTENCY_KEY'])), 0, 255) : '';
    }

    /**
     * @param array<string,mixed> $body
     * @return array{status:int,body:mixed,headers:array<string,string>}
     */
    private static function ok(int $status, array $body, string $idem = '', bool $replayed = false): array
    {
        $headers = ['API-Version' => self::SPEC_VERSION];
        if ($idem !== '') {
            $headers['Idempotency-Key'] = $idem;
            if ($replayed) {
                $headers['Idempotent-Replayed'] = 'true';
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    /** @return array{status:int,body:mixed,headers:array<string,string>} */
    private static function err(int $status, string $code, string $message, string $param = ''): array
    {
        $body = ['type' => $status >= 500 ? 'service_error' : 'invalid_request', 'code' => $code, 'message' => $message];
        if ($param !== '') {
            $body['param'] = '$.' . $param;
        }
        return ['status' => $status, 'body' => $body, 'headers' => ['API-Version' => self::SPEC_VERSION]];
    }
}
