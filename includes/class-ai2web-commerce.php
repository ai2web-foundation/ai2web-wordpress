<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WooCommerce commerce actions for AI agents: product lookup/search, order tracking,
 * return and refund requests.
 *
 * Security model (important): order-scoped actions never trust an order id alone. The
 * caller must also supply the billing email, and it must match the order, so an agent
 * cannot enumerate other customers' orders. Lookups are rate limited per IP. Refunds and
 * returns are REQUESTS only: they add an order note for the merchant and never move money
 * from an unauthenticated endpoint. Money-moving is left to the merchant in wp-admin.
 */
final class Ai2Web_Commerce
{
    private const RATE_KEY = 'ai2web_rl_';
    private const RATE_MAX = 20;      // lookups per window, per IP
    private const RATE_WINDOW = 600;  // 10 minutes

    public static function available(): bool
    {
        return class_exists('WooCommerce') && Ai2Web_Settings::get('commerce_actions', true);
    }

    private static function returns_enabled(): bool
    {
        return self::available() && Ai2Web_Settings::get('returns_refunds', true);
    }

    public static function checkout_enabled(): bool
    {
        return self::available() && Ai2Web_Settings::get('checkout', true);
    }

    /**
     * Declared action definitions for the manifest (and MCP tools).
     * @return array<int,array<string,mixed>>
     */
    public static function declared_actions(): array
    {
        if (!self::available()) {
            return [];
        }

        $order_props = [
            'order_id' => ['type' => 'string', 'description' => 'The order number.'],
            'email' => ['type' => 'string', 'description' => 'Billing email on the order (verifies ownership).'],
        ];

        $actions = [
            [
                'name' => 'search_products',
                'description' => 'Search the store catalogue by keyword. Returns matching products with price and availability.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/search-products',
                'requires_auth' => false, 'requires_user_approval' => false, 'risk' => 'low',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search keywords.'],
                ], 'required' => ['query']],
            ],
            [
                'name' => 'check_stock',
                'description' => 'Check availability, price and stock level for a product by SKU or id.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/check-stock',
                'requires_auth' => false, 'requires_user_approval' => false, 'risk' => 'low',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'sku' => ['type' => 'string', 'description' => 'Product SKU.'],
                    'product_id' => ['type' => 'integer', 'description' => 'Product id (alternative to SKU).'],
                ], 'required' => []],
            ],
            [
                'name' => 'track_order',
                'description' => 'Get the status of an order. Requires the order number and the billing email on the order.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/track-order',
                'requires_auth' => false, 'requires_user_approval' => false, 'risk' => 'medium',
                'input_schema' => ['type' => 'object', 'properties' => $order_props, 'required' => ['order_id', 'email']],
            ],
        ];

        if (self::returns_enabled()) {
            $actions[] = [
                'name' => 'check_return_status',
                'description' => 'Check whether a return or refund request already exists for an order.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/check-return-status',
                'requires_auth' => false, 'requires_user_approval' => false, 'risk' => 'low',
                'input_schema' => ['type' => 'object', 'properties' => $order_props, 'required' => ['order_id', 'email']],
            ];
            $actions[] = [
                'name' => 'start_return',
                'description' => 'Request a return for an order. Logs the request for the merchant to action; does not process it automatically.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/start-return',
                'requires_auth' => false, 'requires_user_approval' => true, 'risk' => 'medium',
                'input_schema' => ['type' => 'object', 'properties' => $order_props + [
                    'reason' => ['type' => 'string', 'description' => 'Why the customer wants to return.'],
                ], 'required' => ['order_id', 'email', 'reason']],
            ];
            $actions[] = [
                'name' => 'request_refund',
                'description' => 'Request a refund for an order. High risk: requires explicit user approval. Logs the request for the merchant; never issues a refund automatically.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/request-refund',
                'requires_auth' => false, 'requires_user_approval' => true, 'risk' => 'high',
                'input_schema' => ['type' => 'object', 'properties' => $order_props + [
                    'reason' => ['type' => 'string', 'description' => 'Why the customer wants a refund.'],
                ], 'required' => ['order_id', 'email']],
            ];
        }

        if (self::checkout_enabled()) {
            $actions[] = [
                'name' => 'start_checkout',
                'description' => 'Assemble a cart and create a pending order, returning a secure payment link the customer opens to pay. The agent never handles payment; no money moves until the customer pays in the browser.',
                'method' => 'POST', 'endpoint' => '/ai2w/actions/start-checkout',
                'requires_auth' => false, 'requires_user_approval' => true, 'risk' => 'medium',
                'input_schema' => ['type' => 'object', 'properties' => [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Line items to purchase.',
                        'items' => ['type' => 'object', 'properties' => [
                            'product_id' => ['type' => 'integer', 'description' => 'Product id.'],
                            'sku' => ['type' => 'string', 'description' => 'Product SKU (alternative to product_id).'],
                            'quantity' => ['type' => 'integer', 'description' => 'Quantity (default 1).'],
                        ]],
                    ],
                    'email' => ['type' => 'string', 'description' => 'Optional customer email to attach to the order.'],
                    'confirm' => ['type' => 'boolean', 'description' => 'Set true to create the order and return a pay link; omit for a preview.'],
                ], 'required' => ['items']],
            ];
        }

        return $actions;
    }

    /** @return string[] Action names this class handles. */
    public static function action_names(): array
    {
        return array_map(static fn(array $a): string => $a['name'], self::declared_actions());
    }

    /**
     * Transport-agnostic action runner. Returns ['status'=>int,'body'=>mixed] so both the
     * REST router and the MCP endpoint can share one implementation.
     *
     * @param array<string,mixed> $input
     * @return array{status:int,body:mixed}
     */
    public static function run(string $name, array $input): array
    {
        if (!self::available()) {
            return self::err(404, 'unsupported_capability', 'Commerce actions are not enabled.');
        }
        switch ($name) {
            case 'search_products':
                return self::search_products($input);
            case 'check_stock':
                return self::check_stock($input);
            case 'track_order':
                return self::track_order($input);
            case 'check_return_status':
                return self::returns_enabled() ? self::check_return_status($input) : self::err(404, 'unsupported_capability', "Unknown action '$name'.");
            case 'start_return':
                return self::returns_enabled() ? self::return_or_refund($input, 'return') : self::err(404, 'unsupported_capability', "Unknown action '$name'.");
            case 'request_refund':
                return self::returns_enabled() ? self::return_or_refund($input, 'refund') : self::err(404, 'unsupported_capability', "Unknown action '$name'.");
            case 'start_checkout':
                return self::checkout_enabled() ? self::start_checkout($input) : self::err(404, 'unsupported_capability', "Unknown action '$name'.");
        }
        return self::err(404, 'unsupported_capability', "Unknown action '$name'.");
    }

    /** @param array<string,mixed> $input @return array{status:int,body:mixed} */
    private static function search_products(array $input): array
    {
        $query = isset($input['query']) ? sanitize_text_field((string) $input['query']) : '';
        if ($query === '') {
            return self::err(400, 'invalid_request', 'A search query is required.');
        }
        $items = [];
        $posts = get_posts([
            'post_type' => 'product', 's' => $query,
            'numberposts' => 20, 'post_status' => 'publish',
        ]);
        foreach ($posts as $p) {
            $product = function_exists('wc_get_product') ? wc_get_product($p->ID) : null;
            if ($product) {
                $items[] = self::product_summary($product);
            }
        }
        return ['status' => 200, 'body' => ['query' => $query, 'count' => count($items), 'results' => $items]];
    }

    /** @param array<string,mixed> $input @return array{status:int,body:mixed} */
    private static function check_stock(array $input): array
    {
        $product = null;
        if (!empty($input['sku'])) {
            $id = wc_get_product_id_by_sku(sanitize_text_field((string) $input['sku']));
            $product = $id ? wc_get_product($id) : null;
        } elseif (!empty($input['product_id'])) {
            $product = wc_get_product(absint($input['product_id']));
        } else {
            return self::err(400, 'invalid_request', 'Provide a sku or product_id.');
        }
        if (!$product || $product->get_status() !== 'publish') {
            return self::err(404, 'not_found', 'No published product matches that SKU or id.');
        }
        return ['status' => 200, 'body' => self::product_summary($product) + [
            'stock_quantity' => $product->get_stock_quantity(),
            'backorders_allowed' => $product->backorders_allowed(),
        ]];
    }

    /** @param array<string,mixed> $input @return array{status:int,body:mixed} */
    private static function track_order(array $input): array
    {
        $order = self::verify_order($input, $err);
        if (!$order) {
            return $err;
        }
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = ['name' => $item->get_name(), 'quantity' => $item->get_quantity()];
        }
        return ['status' => 200, 'body' => [
            'order_id' => $order->get_order_number(),
            'status' => $order->get_status(),
            'status_label' => wc_get_order_status_name($order->get_status()),
            'date_created' => $order->get_date_created() ? $order->get_date_created()->date('c') : null,
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'items' => $items,
        ]];
    }

    /** @param array<string,mixed> $input @return array{status:int,body:mixed} */
    private static function check_return_status(array $input): array
    {
        $order = self::verify_order($input, $err);
        if (!$order) {
            return $err;
        }
        $requests = $order->get_meta('_ai2web_requests');
        $requests = is_array($requests) ? $requests : [];
        return ['status' => 200, 'body' => [
            'order_id' => $order->get_order_number(),
            'has_open_request' => !empty($requests),
            'requests' => array_values($requests),
        ]];
    }

    /**
     * Shared handler for start_return / request_refund. Approval-gated: returns a preview
     * unless confirm:true. On confirm, records a request (order note + meta); never moves money.
     *
     * @param array<string,mixed> $input
     * @return array{status:int,body:mixed}
     */
    private static function return_or_refund(array $input, string $kind): array
    {
        $order = self::verify_order($input, $err);
        if (!$order) {
            return $err;
        }
        $reason = isset($input['reason']) ? sanitize_textarea_field((string) $input['reason']) : '';
        if ($kind === 'return' && $reason === '') {
            return self::err(400, 'invalid_request', 'A reason is required to request a return.');
        }

        $risk = $kind === 'refund' ? 'high' : 'medium';
        if (empty($input['confirm']) || $input['confirm'] !== true) {
            return ['status' => 200, 'body' => [
                'preview' => true,
                'action' => $kind === 'refund' ? 'request_refund' : 'start_return',
                'risk' => $risk,
                'message' => 'This request needs explicit user approval. Resend with confirm:true to log it for the merchant. It does not process a ' . $kind . ' automatically.',
                'proposed' => ['order_id' => $order->get_order_number(), 'reason' => $reason],
            ]];
        }

        // Confirmed: record the request for the merchant. No money is moved here.
        $ref = 'ai2w_' . wp_generate_password(10, false, false);
        $requests = $order->get_meta('_ai2web_requests');
        $requests = is_array($requests) ? $requests : [];
        $requests[] = [
            'ref' => $ref,
            'type' => $kind,
            'reason' => $reason,
            'status' => 'requested',
            'created' => gmdate('c'),
        ];
        $order->update_meta_data('_ai2web_requests', $requests);
        $order->add_order_note(
            sprintf(
                /* translators: 1: refund or return, 2: reason, 3: reference */
                __('AI2Web: customer requested a %1$s via an AI agent. Reason: %2$s. Reference: %3$s. Review and action in WooCommerce.', 'ai2web'),
                $kind,
                $reason !== '' ? $reason : __('(none given)', 'ai2web'),
                $ref
            )
        );
        $order->save();

        return ['status' => 200, 'body' => [
            'action' => $kind === 'refund' ? 'request_refund' : 'start_return',
            'order_id' => $order->get_order_number(),
            'status' => 'requested',
            'reference' => $ref,
            'message' => __('Your request has been logged. The store will review it and be in touch.', 'ai2web'),
        ]];
    }

    /**
     * Assemble a cart into a pending order and return WooCommerce's own secure payment URL.
     * Approval-gated: preview unless confirm:true. No payment is taken here; the customer pays
     * in the browser via the returned link, so the agent never handles payment details.
     *
     * @param array<string,mixed> $input
     * @return array{status:int,body:mixed}
     */
    private static function start_checkout(array $input): array
    {
        if (!self::rate_ok()) {
            return self::err(429, 'rate_limited', 'Too many requests. Try again later.');
        }
        $items = isset($input['items']) && is_array($input['items']) ? $input['items'] : [];
        if (empty($items)) {
            return self::err(400, 'invalid_request', 'At least one item is required.');
        }
        if (count($items) > 20) {
            return self::err(400, 'invalid_request', 'Too many line items (max 20).');
        }

        // Resolve and validate every line before creating anything.
        $resolved = [];
        $total = 0.0;
        foreach ($items as $line) {
            if (!is_array($line)) {
                return self::err(400, 'invalid_request', 'Each item must be an object.');
            }
            $qty = isset($line['quantity']) ? absint($line['quantity']) : 1;
            if ($qty < 1 || $qty > 100) {
                return self::err(400, 'invalid_request', 'Quantity must be between 1 and 100.');
            }
            $product = null;
            if (!empty($line['product_id'])) {
                $product = wc_get_product(absint($line['product_id']));
            } elseif (!empty($line['sku'])) {
                $id = wc_get_product_id_by_sku(sanitize_text_field((string) $line['sku']));
                $product = $id ? wc_get_product($id) : null;
            }
            if (!$product || $product->get_status() !== 'publish' || !$product->is_purchasable()) {
                return self::err(404, 'not_found', 'A product could not be found or is not purchasable.');
            }
            if (!$product->has_enough_stock($qty)) {
                return self::err(409, 'out_of_stock', sprintf('Not enough stock for %s.', $product->get_name()));
            }
            $line_total = (float) $product->get_price() * $qty;
            $total += $line_total;
            $resolved[] = ['product' => $product, 'qty' => $qty, 'line_total' => $line_total];
        }

        $currency = get_woocommerce_currency();
        $summary = array_map(static fn(array $r): array => [
            'product_id' => $r['product']->get_id(),
            'title' => $r['product']->get_name(),
            'quantity' => $r['qty'],
            'line_total' => wc_format_decimal($r['line_total'], wc_get_price_decimals()),
        ], $resolved);

        // Approval gate: preview the cart unless confirmed.
        if (empty($input['confirm']) || $input['confirm'] !== true) {
            return ['status' => 200, 'body' => [
                'preview' => true,
                'action' => 'start_checkout',
                'risk' => 'medium',
                'message' => 'This will create a pending order and return a secure payment link. No payment is taken until the customer pays via the link. Resend with confirm:true to proceed.',
                'items' => $summary,
                'estimated_total' => wc_format_decimal($total, wc_get_price_decimals()),
                'currency' => $currency,
            ]];
        }

        // Confirmed: create the pending order. No payment is processed here.
        $order = wc_create_order(['status' => 'pending', 'created_via' => 'ai2web']);
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return self::err(502, 'order_failed', 'The order could not be created. Please try again.');
        }
        foreach ($resolved as $r) {
            $order->add_product($r['product'], $r['qty']);
        }
        $email = isset($input['email']) ? sanitize_email((string) $input['email']) : '';
        if ($email !== '' && is_email($email)) {
            $order->set_billing_email($email);
        }
        $order->calculate_totals();
        $order->add_order_note(__('AI2Web: pending order created via an AI agent. Customer pays via the returned link.', 'ai2web'));
        $order->save();

        return ['status' => 200, 'body' => [
            'action' => 'start_checkout',
            'status' => 'pending_payment',
            'order_id' => $order->get_order_number(),
            'total' => $order->get_total(),
            'currency' => $order->get_currency(),
            'payment_url' => $order->get_checkout_payment_url(),
            'message' => __('A pending order was created. Open the payment link to complete the purchase. No payment has been taken yet.', 'ai2web'),
        ]];
    }

    /**
     * Look up an order and verify the caller owns it (billing email match). Rate limited.
     * On failure, sets $err to a ready-to-return error array and returns null. Errors are
     * deliberately generic so callers cannot probe which order ids exist.
     *
     * @param array<string,mixed> $input
     * @param-out array{status:int,body:mixed} $err
     */
    private static function verify_order(array $input, &$err): ?WC_Order
    {
        if (!self::rate_ok()) {
            $err = self::err(429, 'rate_limited', 'Too many lookups. Try again later.');
            return null;
        }
        $order_id = isset($input['order_id']) ? preg_replace('/[^0-9]/', '', (string) $input['order_id']) : '';
        $email = isset($input['email']) ? sanitize_email((string) $input['email']) : '';
        if ($order_id === '' || $email === '' || !is_email($email)) {
            $err = self::err(400, 'invalid_request', 'A valid order_id and email are required.');
            return null;
        }
        $order = wc_get_order(absint($order_id));
        if (!$order instanceof WC_Order || strtolower(trim($order->get_billing_email())) !== strtolower($email)) {
            // Same response whether the order is missing or the email is wrong.
            $err = self::err(404, 'not_found', 'No order matches that number and email.');
            return null;
        }
        $err = ['status' => 200, 'body' => null];
        return $order;
    }

    /** @param WC_Product $product @return array<string,mixed> */
    private static function product_summary($product): array
    {
        return [
            'id' => $product->get_id(),
            'sku' => $product->get_sku(),
            'title' => $product->get_name(),
            'url' => get_permalink($product->get_id()),
            'price' => $product->get_price(),
            'currency' => get_woocommerce_currency(),
            'availability' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
        ];
    }

    /** Simple per-IP throttle for order lookups. */
    private static function rate_ok(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = self::RATE_KEY . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_MAX) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    /** @return array{status:int,body:mixed} */
    private static function err(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message, 'retryable' => $status === 429]]];
    }
}
