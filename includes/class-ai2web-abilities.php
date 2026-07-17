<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers AI2Web's declared actions as WordPress Abilities (Abilities API, WP 6.9+/7.0), so the
 * same ownership-verified, approval-gated actions are available to WordPress's own AI Client and
 * the MCP Adapter, not only to external agents via /ai2w.
 *
 * Two surfaces, one definition:
 *   - /ai2w/actions/*  : public, anonymous, ownership + approval gated (the open protocol surface).
 *   - WP Abilities     : the authenticated WP-native surface. permission_callback requires a logged
 *                        in user, so exposure runs through WordPress's own auth (the abilities REST
 *                        routes already return 401 to anonymous callers).
 *
 * Registration is guarded by function_exists so the plugin stays compatible with older WordPress.
 */
final class Ai2Web_Abilities
{
    public static function boot(): void
    {
        // Always attach: the wp_abilities_api_init hook only fires when the Abilities API is
        // present, and wp_register_ability() is not yet defined at plugins_loaded, so guarding
        // the add_action here would skip registration entirely on WordPress 6.9+/7.0.
        add_action('wp_abilities_api_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        if (!Ai2Web_Settings::get('enabled', true)) {
            return;
        }

        // Abilities must use a registered category. Map to the WordPress-provided categories that
        // are guaranteed present for these action types (commerce actions only exist when
        // WooCommerce, and its `woocommerce` category, are active).
        $form_actions = method_exists('Ai2Web_Forms', 'action_names') ? Ai2Web_Forms::action_names() : [];

        foreach (Ai2Web_Actions::declared() as $a) {
            $action = (string) ($a['name'] ?? '');
            if ($action === '') {
                continue;
            }
            $approval = !empty($a['requires_user_approval']);
            $risk = (string) ($a['risk'] ?? 'low');
            wp_register_ability('ai2web/' . str_replace('_', '-', $action), [
                'label' => ucwords(str_replace('_', ' ', $action)),
                'description' => (string) ($a['description'] ?? ''),
                'category' => in_array($action, $form_actions, true) ? 'content' : 'woocommerce',
                'input_schema' => is_array($a['input_schema'] ?? null) ? $a['input_schema'] : ['type' => 'object'],
                'output_schema' => ['type' => 'object', 'additionalProperties' => true],
                'execute_callback' => static function ($input) use ($action) {
                    $res = Ai2Web_Actions::run($action, is_array($input) ? $input : []);
                    return is_array($res) ? ($res['body'] ?? null) : null;
                },
                // Authenticated WP-native surface: WordPress gates who may run abilities.
                'permission_callback' => static function () {
                    return is_user_logged_in();
                },
                // show_in_rest lives under meta; annotations hint the AI about side effects.
                'meta' => [
                    'show_in_rest' => true,
                    'annotations' => [
                        'readonly' => !$approval,
                        'idempotent' => !$approval,
                        'destructive' => $risk === 'high',
                    ],
                ],
            ]);
        }
    }
}
