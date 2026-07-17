<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects popular form plugins and exposes a generic contact action.
 *
 * The action is approval-gated: an agent gets a preview first, and only on confirm:true is
 * the enquiry delivered by email to the site's support/admin address (never to an arbitrary
 * recipient, so this is not an open relay). Deliveries are rate limited per IP.
 */
final class Ai2Web_Forms
{
    private const RATE_KEY = 'ai2web_contact_rl_';
    private const RATE_MAX = 5;       // enquiries per window, per IP
    private const RATE_WINDOW = 600;  // 10 minutes

    /** @return array<string,bool> Map of detected form providers. */
    public static function detect(): array
    {
        return [
            'contact_form_7' => defined('WPCF7_VERSION'),
            'gravity_forms'  => class_exists('GFForms'),
            'wpforms'        => function_exists('wpforms'),
            'fluent_forms'   => defined('FLUENTFORM_VERSION'),
            'elementor_forms' => did_action('elementor/loaded') > 0,
        ];
    }

    /** @return array<int,array<string,mixed>> Declared actions for the manifest. */
    public static function declared_actions(): array
    {
        $providers = array_keys(array_filter(self::detect()));
        if (empty($providers)) {
            return [];
        }
        return [[
            'name' => 'submit_contact',
            'description' => 'Send a contact/enquiry message to the site. Approval required; on confirmation it is emailed to the site\'s support address.',
            'method' => 'POST',
            'endpoint' => '/ai2w/actions/submit-contact',
            'requires_auth' => false,
            'requires_user_approval' => true,
            'risk' => 'medium',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['email', 'message'],
            ],
        ]];
    }

    /** @return string[] */
    public static function action_names(): array
    {
        return array_map(static fn(array $a): string => (string) $a['name'], self::declared_actions());
    }

    /**
     * Run a form action. Returns ['status'=>int,'body'=>mixed].
     *
     * @param array<string,mixed> $input
     * @return array{status:int,body:mixed}
     */
    public static function run(string $name, array $input): array
    {
        if ($name !== 'submit_contact' || self::declared_actions() === []) {
            return self::err(404, 'unsupported_capability', "Unknown action '$name'.");
        }

        $email = isset($input['email']) ? sanitize_email((string) $input['email']) : '';
        $message = isset($input['message']) ? sanitize_textarea_field((string) $input['message']) : '';
        $from_name = isset($input['name']) ? sanitize_text_field((string) $input['name']) : '';
        if ($email === '' || !is_email($email) || $message === '') {
            return self::err(400, 'invalid_request', 'A valid email and a message are required.');
        }

        // Approval gate: preview unless confirmed.
        if (empty($input['confirm']) || $input['confirm'] !== true) {
            return ['status' => 200, 'body' => [
                'preview' => true,
                'action' => 'submit_contact',
                'risk' => 'medium',
                'message' => 'This enquiry needs explicit user approval. Resend with confirm:true to send it to the site.',
                'proposed' => ['name' => $from_name, 'email' => $email, 'message' => $message],
            ]];
        }

        if (!self::rate_ok()) {
            return self::err(429, 'rate_limited', 'Too many enquiries. Try again later.');
        }

        $to = Ai2Web_Settings::get('support_email', '');
        $to = is_string($to) && $to !== '' ? $to : get_option('admin_email');
        $subject = sprintf(
            /* translators: %s: site name */
            __('[AI2Web] New enquiry via AI agent - %s', 'ai2web'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
        $lines = [
            sprintf(__('Name: %s', 'ai2web'), $from_name !== '' ? $from_name : __('(not given)', 'ai2web')),
            sprintf(__('Email: %s', 'ai2web'), $email),
            '',
            $message,
            '',
            __('Sent via the AI2Web contact action.', 'ai2web'),
        ];
        $sent = wp_mail($to, $subject, implode("\n", $lines), ['Reply-To: ' . $from_name . ' <' . $email . '>']);

        if (!$sent) {
            return self::err(502, 'delivery_failed', 'The enquiry could not be delivered. Please contact the site directly.');
        }
        return ['status' => 200, 'body' => [
            'action' => 'submit_contact',
            'status' => 'sent',
            'reference' => 'ai2w_' . wp_generate_password(10, false, false),
            'message' => __('Your message has been sent to the site.', 'ai2web'),
        ]];
    }

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
