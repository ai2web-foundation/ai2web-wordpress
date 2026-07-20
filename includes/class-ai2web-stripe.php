<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stripe Shared Payment Token (SPT) handler for ACP checkout completion.
 *
 * This is the piece that makes a store genuinely buyable in-agent (e.g. ChatGPT Instant Checkout):
 * when an ACP checkout session is completed, the agent hands us a Shared Payment Token scoped to
 * this purchase; we redeem it by creating and confirming a Stripe PaymentIntent for the order
 * total. Per Stripe's agentic-commerce API the token is passed as
 * payment_method_data[shared_payment_granted_token] with confirm=true; Stripe clones the buyer's
 * payment method behind the token and charges it, enforcing the token's amount/scope controls.
 *
 * It hooks the `ai2web_acp_complete_payment` filter that Ai2Web_ACP::complete() applies:
 *   - returns true  -> charged; the ACP layer marks the WooCommerce order paid.
 *   - returns WP_Error -> declined; the ACP layer returns an ACP payment_declined error.
 *   - returns the incoming value (null) -> not configured; the ACP layer keeps its safe fallback
 *     (a pending order + the order's own secure pay link, so the agent never handles card data).
 *
 * The Stripe secret key is NEVER stored in the plugin's options. It is resolved from (in order):
 * the `ai2web_acp_stripe_secret_key` filter, an AI2WEB_STRIPE_SECRET_KEY constant (recommended,
 * set in wp-config.php), or the official WooCommerce Stripe gateway's own saved key.
 */
final class Ai2Web_Stripe
{
    private const ENDPOINT = 'https://api.stripe.com/v1/payment_intents';

    /** ISO 4217 currencies with no minor unit (amounts are whole units, not cents). */
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    public static function boot(): void
    {
        add_filter('ai2web_acp_complete_payment', [self::class, 'complete'], 10, 4);
    }

    /** True when a Stripe secret key is resolvable, i.e. in-agent charging is active. */
    public static function available(): bool
    {
        return self::secret_key() !== '' && (bool) apply_filters('ai2web_acp_stripe_enabled', true);
    }

    /**
     * Redeem an ACP Shared Payment Token by charging a Stripe PaymentIntent for the order total.
     *
     * @param mixed                $result  Decision from a prior handler (null = undecided).
     * @param WC_Order             $order   The order to charge.
     * @param array<string,mixed>  $payment ACP payment_data.
     * @param string               $token   The shared payment token (spt_...).
     * @return true|WP_Error|mixed true on success, WP_Error on decline, or $result if not handled.
     */
    public static function complete($result, $order, $payment, $token)
    {
        // Respect a decision an earlier handler already made.
        if ($result === true || $result === false || $result instanceof WP_Error) {
            return $result;
        }
        if (!apply_filters('ai2web_acp_stripe_enabled', true)) {
            return $result;
        }
        $key = self::secret_key();
        if ($key === '' || !$order instanceof WC_Order) {
            return $result; // not configured - ACP keeps its pay-link fallback
        }
        if (!is_string($token) || $token === '') {
            return new WP_Error('missing_payment_token', __('No payment token was provided.', 'ai2web'));
        }

        $currency = $order->get_currency();
        $amount = self::to_minor((float) $order->get_total(), $currency);
        if ($amount <= 0) {
            return new WP_Error('invalid_amount', __('The order total is not payable.', 'ai2web'));
        }

        $body = [
            'amount' => $amount,
            'currency' => strtolower($currency),
            'confirm' => 'true',
            'description' => sprintf('AI2Web ACP order %s', $order->get_order_number()),
            'payment_method_data' => ['shared_payment_granted_token' => $token],
            'metadata' => [
                'ai2web_order_id' => (string) $order->get_id(),
                'ai2web_checkout_session' => (string) $order->get_meta('_ai2web_acp_session'),
            ],
        ];
        if ($order->get_billing_email() !== '') {
            $body['receipt_email'] = $order->get_billing_email();
        }

        $headers = [
            'Authorization' => 'Bearer ' . $key,
            'Content-Type' => 'application/x-www-form-urlencoded',
            // Per-order key so a retried completion never double-charges.
            'Idempotency-Key' => 'ai2w_acp_' . $order->get_id(),
        ];
        $version = (string) apply_filters('ai2web_acp_stripe_api_version', '');
        if ($version !== '') {
            $headers['Stripe-Version'] = $version;
        }

        $resp = wp_remote_post(self::ENDPOINT, [
            'headers' => $headers,
            'body' => http_build_query($body, '', '&'),
            'timeout' => 25,
        ]);
        if (is_wp_error($resp)) {
            return new WP_Error('payment_processor_unreachable', __('Could not reach the payment processor. Please try again.', 'ai2web'));
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);

        if ($code >= 400 || !is_array($data)) {
            $message = is_array($data) && isset($data['error']['message'])
                ? (string) $data['error']['message']
                : __('The payment was declined.', 'ai2web');
            return new WP_Error('payment_declined', $message);
        }

        $status = (string) ($data['status'] ?? '');
        if (in_array($status, ['succeeded', 'processing'], true)) {
            if (isset($data['id']) && is_string($data['id'])) {
                $order->set_transaction_id($data['id']);
                $order->add_order_note(sprintf(
                    /* translators: 1: Stripe PaymentIntent id, 2: status */
                    __('AI2Web (ACP): charged via Stripe Shared Payment Token. PaymentIntent %1$s (%2$s).', 'ai2web'),
                    $data['id'],
                    $status
                ), false);
            }
            return true;
        }

        // requires_action / requires_payment_method / requires_confirmation, etc: not completed.
        return new WP_Error('payment_incomplete', sprintf(
            /* translators: %s: PaymentIntent status */
            __('The payment could not be completed (%s).', 'ai2web'),
            $status !== '' ? $status : 'unknown'
        ));
    }

    /**
     * Resolve the Stripe secret key without persisting it in plugin options. Priority: filter,
     * then an AI2WEB_STRIPE_SECRET_KEY constant, then the official WooCommerce Stripe gateway.
     */
    public static function secret_key(): string
    {
        $key = (string) apply_filters('ai2web_acp_stripe_secret_key', '');
        if ($key !== '') {
            return $key;
        }
        if (defined('AI2WEB_STRIPE_SECRET_KEY') && is_string(AI2WEB_STRIPE_SECRET_KEY) && AI2WEB_STRIPE_SECRET_KEY !== '') {
            return AI2WEB_STRIPE_SECRET_KEY;
        }
        $settings = get_option('woocommerce_stripe_settings');
        if (is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes') {
            $test = ($settings['testmode'] ?? 'no') === 'yes';
            $k = $test ? ($settings['test_secret_key'] ?? '') : ($settings['secret_key'] ?? '');
            if (is_string($k) && $k !== '') {
                return $k;
            }
        }
        return '';
    }

    private static function to_minor(float $amount, string $currency): int
    {
        $factor = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 1 : 100;
        return (int) round($amount * $factor);
    }
}
