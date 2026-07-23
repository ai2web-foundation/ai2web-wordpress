<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * AP2 (Agent Payments Protocol, Google, v0.2.0) merchant surface, backed by WooCommerce.
 *
 * AP2's classic mandate trio is Intent -> Cart -> Payment. The merchant's role is to receive an
 * IntentMandate, price it against the catalogue as a CartContents (a W3C PaymentRequest), and
 * digitally SIGN it into a CartMandate - a short-lived guarantee of the items and price. Later the
 * merchant receives a user-signed PaymentMandate that binds to that cart and settles the order.
 *
 * This implements the merchant side faithfully at the data-model level (mandate objects match the
 * AP2 v0.2.0 Pydantic models; amounts are decimal major units per the classic PaymentCurrencyAmount)
 * and signs the cart as an RS256 JWT (the spec permits RSA; we publish the public key as a JWKS so
 * the payment side can verify). It is exposed as a REST binding and a minimal A2A JSON-RPC
 * `message/send` endpoint (the AP2 transport), with an agent card advertising the AP2 extension.
 *
 * Settlement of the PaymentMandate runs through the `ai2web_ap2_settle_payment` filter; with no
 * handler it degrades safely to a pending WooCommerce order plus its secure pay link.
 *
 * Note: full cryptographic verification of the user's SD-JWT-VC authorization (which is issued by
 * the buyer's credentials provider and verified by the payment network) is out of scope here; the
 * merchant verifies the cart binding (id + total + expiry) and leaves network settlement to the
 * handler.
 */
final class Ai2Web_AP2
{
    public const EXTENSION_URI = 'https://github.com/google-agentic-commerce/ap2/v1';
    public const VERSION = '0.2.0';
    private const CART_TTL = 900;                 // signed-cart lifetime (seconds)
    private const CART_PREFIX = 'ai2web_ap2_cart_';
    private const KEY_OPTION = 'ai2web_ap2_key';
    private const MAX_ITEMS = 20;
    private const RATE_MAX = 30;      // cart-signings / settlements per window, per IP
    private const RATE_WINDOW = 600;  // 10 minutes

    public static function enabled(): bool
    {
        return class_exists('WooCommerce')
            && Ai2Web_Commerce::checkout_enabled()
            && (bool) Ai2Web_Settings::get('ap2', false)
            && function_exists('openssl_sign');
    }

    // --- REST / A2A entry points ------------------------------------------

    /** @param array<string,mixed> $body @return array{status:int,body:mixed} */
    public static function dispatch_cart(array $body): array
    {
        $intent = isset($body['intent_mandate']) && is_array($body['intent_mandate']) ? $body['intent_mandate'] : $body;
        return self::build_cart_mandate($intent);
    }

    /** @param array<string,mixed> $body @return array{status:int,body:mixed} */
    public static function dispatch_payment(array $body): array
    {
        $payment = isset($body['payment_mandate']) && is_array($body['payment_mandate']) ? $body['payment_mandate'] : $body;
        return self::settle_payment($payment);
    }

    /**
     * Minimal A2A JSON-RPC `message/send` binding: extract the AP2 mandate from a DataPart and
     * return a CartMandate / PaymentReceipt as an agent message artifact.
     * @param array<string,mixed> $body
     * @return array{status:int,body:mixed}
     */
    public static function dispatch_a2a(array $body): array
    {
        $id = $body['id'] ?? null;
        $method = (string) ($body['method'] ?? '');
        if ($method !== 'message/send' && $method !== 'message/stream') {
            return ['status' => 200, 'body' => self::rpc_error($id, -32601, "Unsupported method: $method")];
        }
        $parts = $body['params']['message']['parts'] ?? [];
        $intent = null;
        $payment = null;
        if (is_array($parts)) {
            foreach ($parts as $part) {
                $data = is_array($part) && isset($part['data']) && is_array($part['data']) ? $part['data'] : [];
                if (isset($data['ap2.mandates.IntentMandate'])) {
                    $intent = $data['ap2.mandates.IntentMandate'];
                }
                if (isset($data['ap2.mandates.PaymentMandate'])) {
                    $payment = $data['ap2.mandates.PaymentMandate'];
                }
            }
        }
        if (is_array($intent)) {
            $r = self::build_cart_mandate($intent);
            $key = 'ap2.mandates.CartMandate';
            $name = 'cart-mandate';
        } elseif (is_array($payment)) {
            $r = self::settle_payment($payment);
            $key = 'ap2.PaymentReceipt';
            $name = 'payment-receipt';
        } else {
            return ['status' => 200, 'body' => self::rpc_error($id, -32602, 'No AP2 mandate DataPart found.')];
        }
        // Return a proper A2A Task (message/send yields a Task with artifacts).
        $context = isset($body['params']['message']['contextId']) && is_string($body['params']['message']['contextId'])
            ? $body['params']['message']['contextId'] : 'ctx_' . wp_generate_password(16, false, false);
        $ok = $r['status'] >= 200 && $r['status'] < 300;
        return ['status' => 200, 'body' => [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'kind' => 'task',
                'id' => 'task_' . wp_generate_password(20, false, false),
                'contextId' => $context,
                'status' => ['state' => $ok ? 'completed' : 'failed', 'timestamp' => gmdate('c')],
                'artifacts' => [[
                    'artifactId' => 'art_' . wp_generate_password(16, false, false),
                    'name' => $name,
                    'parts' => [['kind' => 'data', 'data' => [$key => $r['body']]]],
                ]],
            ],
        ]];
    }

    /** @return array<string,mixed> A2A agent card advertising the AP2 extension. */
    public static function agent_card(): array
    {
        return [
            'protocolVersion' => '0.3.0',
            'name' => get_bloginfo('name') . ' (AI2Web AP2)',
            'description' => sprintf('AP2 merchant endpoint for %s.', get_bloginfo('name')),
            'url' => home_url('/ai2w/ap2'),
            'preferredTransport' => 'JSONRPC',
            'defaultInputModes' => ['json'],
            'defaultOutputModes' => ['json'],
            'capabilities' => ['extensions' => [
                ['uri' => self::EXTENSION_URI, 'required' => true, 'description' => 'Agent Payments Protocol (AP2)'],
            ]],
            'skills' => [[
                'id' => 'create_cart',
                'name' => 'Create Cart',
                'description' => 'Return a merchant-signed CartMandate for an IntentMandate.',
                'tags' => ['merchant', 'cart', 'ap2'],
            ]],
            'ap2' => [
                'cart_endpoint' => home_url('/ai2w/ap2/cart'),
                'payment_endpoint' => home_url('/ai2w/ap2/payment'),
                'jwks_uri' => home_url('/ai2w/ap2/jwks'),
            ],
        ];
    }

    /** @return array<string,mixed> JWKS with the merchant's cart-signing public key. */
    public static function jwks(): array
    {
        $k = self::keys();
        if (empty($k)) {
            return ['keys' => []];
        }
        return ['keys' => [[
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $k['kid'],
            'n' => $k['n'],
            'e' => $k['e'],
        ]]];
    }

    // --- Core: build + sign the CartMandate -------------------------------

    /**
     * @param array<string,mixed> $intent AP2 IntentMandate.
     * @return array{status:int,body:mixed}
     */
    private static function build_cart_mandate(array $intent): array
    {
        if (!function_exists('wc_get_product')) {
            return self::err(404, 'unsupported', 'Commerce is not available.');
        }
        // Throttle per IP: each cart is an RSA signing operation, so cap anonymous CPU abuse.
        if (!self::rate_ok()) {
            return self::err(429, 'rate_limited', 'Too many requests. Try again later.');
        }
        // Honour intent_expiry.
        if (!empty($intent['intent_expiry'])) {
            $exp = strtotime((string) $intent['intent_expiry']);
            if ($exp !== false && $exp < time()) {
                return self::err(410, 'intent_expired', 'The intent has expired.');
            }
        }
        // Honour the merchants allow-list, if the buyer scoped the intent.
        $merchants = $intent['merchants'] ?? null;
        if (is_array($merchants) && !empty($merchants)) {
            $me = [
                strtolower(trim((string) get_bloginfo('name'))),
                strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
                strtolower(rtrim(home_url('/'), '/')),
            ];
            $match = false;
            foreach ($merchants as $m) {
                if (in_array(strtolower(trim((string) $m)), $me, true)) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return self::err(409, 'merchant_mismatch', 'This intent is addressed to other merchants.');
            }
        }

        // Resolve items: explicit items (with quantities), then SKUs, else a catalogue search.
        $items = self::resolve_items($intent);
        if (isset($items['status'])) {
            return $items; // error passthrough
        }
        if (empty($items)) {
            return self::err(404, 'no_match', 'No purchasable product matched the intent.');
        }

        $currency = get_woocommerce_currency();
        $display_items = [];
        $lines = [];
        $total = 0.0;
        foreach ($items as $item) {
            $product = $item['product'];
            $qty = $item['qty'];
            $line_total = (float) $product->get_price() * $qty;
            $label = $qty > 1 ? sprintf('%s x%d', $product->get_name(), $qty) : $product->get_name();
            $display_items[] = ['label' => $label, 'amount' => self::amount($line_total, $currency)];
            $total += $line_total;
            $lines[] = ['product_id' => $product->get_id(), 'qty' => $qty];
        }

        $cart_id = 'cart_' . wp_generate_password(20, false, false);
        $details_id = 'pr_' . wp_generate_password(20, false, false);
        $contents = [
            'id' => $cart_id,
            'user_cart_confirmation_required' => true,
            'payment_request' => [
                'method_data' => apply_filters('ai2web_ap2_payment_methods', [
                    ['supported_methods' => 'card', 'data' => new stdClass()],
                ]),
                'details' => [
                    'id' => $details_id,
                    'display_items' => $display_items,
                    'total' => ['label' => __('Total', 'ai2web'), 'amount' => self::amount($total, $currency)],
                ],
                'options' => ['request_shipping' => true],
            ],
            'cart_expiry' => gmdate('c', time() + self::CART_TTL),
            'merchant_name' => get_bloginfo('name'),
        ];

        // Remember the cart so a later PaymentMandate can be settled against it.
        set_transient(self::CART_PREFIX . $details_id, [
            'lines' => $lines,
            'total' => round($total, wc_get_price_decimals()),
            'currency' => $currency,
            'cart_id' => $cart_id,
        ], self::CART_TTL);

        $jwt = self::sign_cart($contents);
        if ($jwt === '') {
            return self::err(500, 'signing_unavailable', 'The merchant could not sign the cart.');
        }

        return ['status' => 200, 'body' => [
            'contents' => $contents,
            'merchant_authorization' => $jwt,
        ]];
    }

    /**
     * Resolve the intent to cart items with quantities. Precedence: an explicit `items` list
     * (each {sku|product_id, quantity} - an AP2-compatible extension for multi-item/quantity
     * carts), then `skus` (quantity 1 each), else a catalogue search over the description.
     *
     * @param array<string,mixed> $intent
     * @return array<int,array{product:WC_Product,qty:int}>|array{status:int,body:mixed}
     */
    private static function resolve_items(array $intent)
    {
        $out = [];

        $items = $intent['items'] ?? null;
        if (is_array($items) && !empty($items)) {
            foreach (array_slice($items, 0, self::MAX_ITEMS) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $ref = (string) ($it['sku'] ?? $it['product_id'] ?? '');
                $qty = isset($it['quantity']) ? max(1, absint($it['quantity'])) : 1;
                $product = self::resolve_one($ref);
                if ($product === null) {
                    return self::err(404, 'no_match', sprintf('Item "%s" is unavailable.', $ref));
                }
                $out[] = ['product' => $product, 'qty' => $qty];
            }
            if (empty($out)) {
                return self::err(400, 'invalid_intent', 'No resolvable items.');
            }
            return $out;
        }

        $skus = $intent['skus'] ?? null;
        if (is_array($skus) && !empty($skus)) {
            foreach (array_slice($skus, 0, self::MAX_ITEMS) as $sku) {
                $product = self::resolve_one((string) $sku);
                if ($product !== null) {
                    $out[] = ['product' => $product, 'qty' => 1];
                }
            }
            if (empty($out)) {
                return self::err(404, 'no_match', 'None of the requested SKUs are available.');
            }
            return $out;
        }

        $desc = isset($intent['natural_language_description']) ? sanitize_text_field((string) $intent['natural_language_description']) : '';
        if ($desc === '') {
            return self::err(400, 'invalid_intent', 'The intent needs a natural_language_description, skus or items.');
        }
        $found = get_posts(['post_type' => 'product', 's' => $desc, 'numberposts' => 1, 'post_status' => 'publish']);
        foreach ($found as $post) {
            $product = self::resolve_one((string) $post->ID);
            if ($product !== null) {
                $out[] = ['product' => $product, 'qty' => 1];
            }
        }
        return $out;
    }

    /** Resolve a numeric id or SKU to a purchasable, non-variable product, or null. */
    private static function resolve_one(string $ref): ?WC_Product
    {
        $ref = trim($ref);
        if ($ref === '') {
            return null;
        }
        $product = ctype_digit($ref) ? wc_get_product((int) $ref) : null;
        if (!$product instanceof WC_Product) {
            $id = wc_get_product_id_by_sku($ref);
            $product = $id ? wc_get_product($id) : null;
        }
        if (!$product instanceof WC_Product || $product->get_status() !== 'publish'
            || !$product->is_purchasable() || $product->is_type('variable')) {
            return null;
        }
        return $product;
    }

    // --- Core: settle a PaymentMandate ------------------------------------

    /**
     * @param array<string,mixed> $payment AP2 PaymentMandate.
     * @return array{status:int,body:mixed}
     */
    private static function settle_payment(array $payment): array
    {
        $contents = isset($payment['payment_mandate_contents']) && is_array($payment['payment_mandate_contents'])
            ? $payment['payment_mandate_contents'] : [];
        if (!self::rate_ok()) {
            return self::err(429, 'rate_limited', 'Too many requests. Try again later.');
        }
        $details_id = isset($contents['payment_details_id']) ? (string) $contents['payment_details_id'] : '';
        if ($details_id === '') {
            return self::err(400, 'invalid', 'payment_details_id is required.');
        }
        $cart = get_transient(self::CART_PREFIX . $details_id);
        if (!is_array($cart)) {
            return self::err(404, 'cart_not_found', 'The referenced cart is unknown or has expired.');
        }
        // Verify the payment total matches the cart the merchant signed.
        $claimed = $contents['payment_details_total']['amount']['value'] ?? null;
        if ($claimed !== null && abs((float) $claimed - (float) $cart['total']) > 0.001) {
            return self::err(409, 'total_mismatch', 'The payment total does not match the signed cart.');
        }
        // Claim the cart race-safely: only the caller whose delete actually removes the transient
        // proceeds, so two concurrent settlements cannot both create an order for the same cart.
        if (!delete_transient(self::CART_PREFIX . $details_id)) {
            return self::err(409, 'already_settled', 'This cart has already been settled.');
        }

        $order = wc_create_order(['status' => 'pending', 'created_via' => 'ai2web-ap2']);
        if (is_wp_error($order) || !$order instanceof WC_Order) {
            return self::err(502, 'order_failed', 'The order could not be created.');
        }
        foreach ($cart['lines'] as $l) {
            $product = wc_get_product((int) $l['product_id']);
            if ($product instanceof WC_Product) {
                $order->add_product($product, (int) ($l['qty'] ?? 1));
            }
        }
        // Buyer / shipping from the PaymentResponse, where present.
        $pr = isset($contents['payment_response']) && is_array($contents['payment_response']) ? $contents['payment_response'] : [];
        if (!empty($pr['payer_email']) && is_email((string) $pr['payer_email'])) {
            $order->set_billing_email(sanitize_email((string) $pr['payer_email']));
        }
        if (!empty($pr['payer_name'])) {
            [$first, $last] = self::split_name((string) $pr['payer_name']);
            $order->set_billing_first_name($first);
            $order->set_billing_last_name($last);
        }
        self::apply_contact_address($order, $pr['shipping_address'] ?? null);
        $order->update_meta_data('_ai2web_ap2_payment_mandate', (string) ($contents['payment_mandate_id'] ?? ''));
        $order->calculate_totals();

        // Settlement seam. Default (no handler) leaves the order pending with a pay link.
        $settled = apply_filters('ai2web_ap2_settle_payment', null, $order, $payment);
        if ($settled === true) {
            $order->set_status('processing');
            $order->payment_complete();
            $order->add_order_note(__('AI2Web (AP2): settled via a payment handler.', 'ai2web'), false);
            $order->save();
            $status = 'completed';
        } elseif ($settled instanceof WP_Error) {
            return self::err(402, 'payment_declined', $settled->get_error_message() ?: 'Payment was declined.');
        } else {
            $order->add_order_note(__('AI2Web (AP2): order created via an AP2 agent; awaiting payment via the returned link.', 'ai2web'), false);
            $order->save();
            $status = 'created';
        }

        return ['status' => 200, 'body' => [
            'type' => 'ap2.PaymentReceipt',
            'payment_mandate_id' => (string) ($contents['payment_mandate_id'] ?? ''),
            'order_id' => (string) $order->get_id(),
            'order_number' => $order->get_order_number(),
            'status' => $status,
            'receipt_url' => $status === 'completed' ? $order->get_view_order_url() : $order->get_checkout_payment_url(),
        ]];
    }

    // --- Signing ----------------------------------------------------------

    /**
     * Sign CartContents into the merchant_authorization JWT (RS256). The cart_hash claim is a
     * base64url SHA-256 over the canonical JSON of the contents, guaranteeing integrity.
     *
     * @param array<string,mixed> $contents
     */
    private static function sign_cart(array $contents): string
    {
        $keys = self::keys();
        if (empty($keys)) {
            return '';
        }
        $now = time();
        $claims = [
            'iss' => rtrim(home_url('/'), '/'),
            'sub' => (string) $contents['id'],
            'aud' => (string) apply_filters('ai2web_ap2_audience', 'ap2-network'),
            'iat' => $now,
            'exp' => $now + self::CART_TTL,
            'jti' => wp_generate_password(24, false, false),
            'cart_hash' => self::b64url(hash('sha256', self::canonical($contents), true)),
        ];
        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $keys['kid']];
        $signing_input = self::b64url((string) wp_json_encode($header)) . '.' . self::b64url((string) wp_json_encode($claims));
        $sig = '';
        if (!openssl_sign($signing_input, $sig, $keys['private'], OPENSSL_ALGO_SHA256)) {
            return '';
        }
        return $signing_input . '.' . self::b64url($sig);
    }

    /**
     * Resolve the RSA signing key (private PEM + public JWKS parts). Priority: a filter, an
     * AI2WEB_AP2_PRIVATE_KEY constant, then a generated key persisted in options. The cart-signing
     * key guarantees price/authenticity of an offer; it never touches payment credentials.
     *
     * @return array{private:string,kid:string,n:string,e:string}|array{}
     */
    private static function keys(): array
    {
        $pem = (string) apply_filters('ai2web_ap2_private_key', '');
        if ($pem === '' && defined('AI2WEB_AP2_PRIVATE_KEY') && is_string(AI2WEB_AP2_PRIVATE_KEY)) {
            $pem = AI2WEB_AP2_PRIVATE_KEY;
        }
        if ($pem === '') {
            $stored = get_option(self::KEY_OPTION);
            if (is_array($stored) && !empty($stored['private'])) {
                $pem = (string) $stored['private'];
            }
        }
        if ($pem === '') {
            $pem = self::generate_key();
            if ($pem === '') {
                return [];
            }
            update_option(self::KEY_OPTION, ['private' => $pem], false);
        }

        $res = openssl_pkey_get_private($pem);
        if ($res === false) {
            return [];
        }
        $details = openssl_pkey_get_details($res);
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            return [];
        }
        return [
            'private' => $pem,
            'kid' => substr(hash('sha256', (string) ($details['key'] ?? $pem)), 0, 16),
            'n' => self::b64url($details['rsa']['n']),
            'e' => self::b64url($details['rsa']['e']),
        ];
    }

    private static function generate_key(): string
    {
        if (!function_exists('openssl_pkey_new')) {
            return '';
        }
        $cfg = self::openssl_config();
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + $cfg);
        if ($res === false) {
            return '';
        }
        $pem = '';
        if (!openssl_pkey_export($res, $pem, null, $cfg)) {
            return '';
        }
        return $pem;
    }

    /**
     * OpenSSL config args for key generation. On Linux hosts the default config is found
     * automatically and this is empty; some Windows/misconfigured setups need an explicit
     * openssl.cnf, resolvable via the `ai2web_ap2_openssl_config` filter or the OPENSSL_CONF env.
     *
     * @return array{config?:string}
     */
    private static function openssl_config(): array
    {
        $cfg = (string) apply_filters('ai2web_ap2_openssl_config', '');
        if ($cfg === '') {
            $env = getenv('OPENSSL_CONF');
            $cfg = is_string($env) ? $env : '';
        }
        return ($cfg !== '' && is_readable($cfg)) ? ['config' => $cfg] : [];
    }

    // --- Small utilities ---------------------------------------------------

    /** @return array<string,mixed> AP2 PaymentCurrencyAmount: decimal major units, ISO 4217. */
    private static function amount(float $value, string $currency): array
    {
        return ['currency' => strtoupper($currency), 'value' => round($value, wc_get_price_decimals())];
    }

    /** @param mixed $address AP2 ContactAddress. */
    private static function apply_contact_address(WC_Order $order, $address): void
    {
        if (!is_array($address)) {
            return;
        }
        $lines = isset($address['address_line']) && is_array($address['address_line']) ? $address['address_line'] : [];
        $shipping = array_filter([
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'address_1' => isset($lines[0]) ? sanitize_text_field((string) $lines[0]) : '',
            'address_2' => isset($lines[1]) ? sanitize_text_field((string) $lines[1]) : '',
            'city' => isset($address['city']) ? sanitize_text_field((string) $address['city']) : '',
            'state' => isset($address['region']) ? sanitize_text_field((string) $address['region']) : '',
            'postcode' => isset($address['postal_code']) ? sanitize_text_field((string) $address['postal_code']) : '',
            'country' => isset($address['country']) ? strtoupper(sanitize_text_field((string) $address['country'])) : '',
        ], static fn($v) => $v !== '');
        if (!empty($shipping)) {
            $order->set_address($shipping, 'shipping');
        }
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

    /** Per-IP throttle for cart signing and settlement (anonymous flood / CPU-abuse guard). */
    private static function rate_ok(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'ai2web_ap2_rl_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_MAX) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    private static function b64url(string $bin): string
    {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding of a JWS signing input / signature / hash, not obfuscation.
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /**
     * JCS (RFC 8785) canonicalisation, so a cart_hash computed here is byte-identical to the AI2Web
     * SDKs (PHP/Python/Go/.NET/Ruby): object keys sorted, no whitespace, minimal string escaping,
     * integers without a decimal point, currency amounts as a short decimal.
     *
     * @param mixed $data
     */
    private static function canonical($data): string
    {
        if ($data === null) {
            return 'null';
        }
        if (is_bool($data)) {
            return $data ? 'true' : 'false';
        }
        if (is_int($data)) {
            return (string) $data;
        }
        if (is_float($data)) {
            if (is_finite($data) && $data === floor($data) && abs($data) < 1e15) {
                return (string) (int) $data;
            }
            return rtrim(rtrim(sprintf('%.2f', $data), '0'), '.');
        }
        if (is_string($data)) {
            return self::jcs_string($data);
        }
        if ($data instanceof stdClass) {
            $data = (array) $data;
        }
        if (is_array($data)) {
            // array_is_list() is PHP 8.1+/WP 6.5+; use a version-safe equivalent for wider support.
            if ($data === array_values($data)) {
                return '[' . implode(',', array_map([self::class, 'canonical'], $data)) . ']';
            }
            $keys = array_map('strval', array_keys($data));
            sort($keys, SORT_STRING);
            $parts = [];
            foreach ($keys as $k) {
                $parts[] = self::jcs_string($k) . ':' . self::canonical($data[$k]);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return 'null';
    }

    private static function jcs_string(string $s): string
    {
        $out = '"';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $o = ord($c);
            if ($c === '"') {
                $out .= '\\"';
            } elseif ($c === '\\') {
                $out .= '\\\\';
            } elseif ($o === 0x08) {
                $out .= '\\b';
            } elseif ($o === 0x09) {
                $out .= '\\t';
            } elseif ($o === 0x0A) {
                $out .= '\\n';
            } elseif ($o === 0x0C) {
                $out .= '\\f';
            } elseif ($o === 0x0D) {
                $out .= '\\r';
            } elseif ($o < 0x20) {
                $out .= sprintf('\\u%04x', $o);
            } else {
                $out .= $c;
            }
        }
        return $out . '"';
    }

    /** @return array{status:int,body:mixed} */
    private static function err(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message]]];
    }

    /** @return array<string,mixed> JSON-RPC error envelope. */
    private static function rpc_error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
