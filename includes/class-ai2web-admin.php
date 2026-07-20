<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin settings page: toggles, support email, and a live AI Readiness Score computed from
 * the site's generated manifest (scored locally, so it works on staging/localhost too).
 */
final class Ai2Web_Admin
{
    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'register']);
    }

    public static function menu(): void
    {
        add_options_page(
            __('AI2Web', 'ai2web'),
            __('AI2Web', 'ai2web'),
            'manage_options',
            'ai2web',
            [self::class, 'render']
        );
    }

    public static function register(): void
    {
        register_setting('ai2web_group', Ai2Web_Settings::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [Ai2Web_Settings::class, 'sanitize'],
            'default' => Ai2Web_Settings::defaults(),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Ai2Web_Settings::all();
        $manifest = Ai2Web_Manifest::build();
        $score = self::score($manifest);
        $has_woo = class_exists('WooCommerce');
        $pretty = (bool) get_option('permalink_structure');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('AI2Web', 'ai2web') . '</h1>';
        echo '<p>' . esc_html__('Describe your site once so AI agents can discover, understand and act on it.', 'ai2web') . '</p>';

        if (!$pretty) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                /* translators: %s: Permalinks settings URL */
                wp_kses(__('AI2Web needs pretty permalinks. Set them under <a href="%s">Settings &rarr; Permalinks</a> (any option except &ldquo;Plain&rdquo;).', 'ai2web'), ['a' => ['href' => []]]),
                esc_url(admin_url('options-permalink.php'))
            );
            echo '</p></div>';
        }

        // Readiness panel.
        $band = $score['score'] >= 80 ? '#1f883d' : ($score['score'] >= 50 ? '#bf8700' : '#cf222e');
        echo '<div class="card" style="max-width:760px;padding:16px 20px">';
        echo '<h2 style="margin-top:0">' . esc_html__('AI Readiness', 'ai2web') . '</h2>';
        echo '<p style="font-size:15px">';
        echo '<span style="font-size:34px;font-weight:700;color:' . esc_attr($band) . ';vertical-align:middle">' . esc_html((string) $score['score']) . '</span>';
        echo ' <span style="color:#666">/ 100</span> &nbsp; ';
        echo '<span style="display:inline-block;border:1px solid #ccd0d4;border-radius:999px;padding:2px 12px;font-size:13px">' . esc_html($score['tier']) . '</span>';
        echo '</p>';
        echo '<ul style="margin:10px 0 0;columns:2;column-gap:28px">';
        foreach ($score['checks'] as $c) {
            $mark = $c['ok'] ? '&#10003;' : '&#9888;';
            $color = $c['ok'] ? '#1f883d' : '#bf8700';
            echo '<li style="margin:3px 0;list-style:none">';
            echo '<span style="color:' . esc_attr($color) . ';font-weight:700">' . $mark . '</span> ';
            echo esc_html($c['label']);
            if (!$c['ok'] && $c['hint'] !== '') {
                echo ' <span style="color:#888">- ' . esc_html($c['hint']) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';

        $home = home_url('/ai2w');
        echo '<p style="margin-top:14px">';
        echo esc_html__('Manifest:', 'ai2web') . ' <a href="' . esc_url($home) . '" target="_blank"><code>' . esc_html($home) . '</code></a>';
        if (!empty($s['mcp_enabled'])) {
            $mcp = home_url('/ai2w/mcp');
            echo ' &nbsp;&middot;&nbsp; ' . esc_html__('MCP endpoint:', 'ai2web') . ' <code>' . esc_html($mcp) . '</code>';
        }
        echo '</p>';
        echo '<p><a class="button" href="' . esc_url('https://ai2web.dev/validator?url=' . rawurlencode(home_url('/'))) . '" target="_blank" rel="noopener">' . esc_html__('Open in the AI2Web Validator', 'ai2web') . '</a></p>';
        echo '</div>';

        // Settings form.
        echo '<form method="post" action="options.php" style="margin-top:20px">';
        settings_fields('ai2web_group');
        $opt = Ai2Web_Settings::OPTION;
        echo '<table class="form-table" role="presentation"><tbody>';

        self::checkbox_row($opt, 'enabled', !empty($s['enabled']), __('Enable AI2Web', 'ai2web'), __('Serve the /ai2w manifest and endpoints.', 'ai2web'));

        echo '<tr><th scope="row"><label for="ai2web_support">' . esc_html__('Support email', 'ai2web') . '</label></th><td>';
        echo '<input name="' . esc_attr($opt) . '[support_email]" id="ai2web_support" type="email" class="regular-text" value="' . esc_attr((string) $s['support_email']) . '" placeholder="help@example.com" />';
        echo '<p class="description">' . esc_html__('Published in the manifest as the public support contact. Leave empty to omit. Recommended for a higher score.', 'ai2web') . '</p>';
        echo '</td></tr>';

        self::checkbox_row($opt, 'mcp_enabled', !empty($s['mcp_enabled']), __('MCP endpoint', 'ai2web'), __('Expose /ai2w/mcp so AI assistants (Claude, ChatGPT connectors) can use your actions directly.', 'ai2web'));

        self::checkbox_row($opt, 'agent_service', !empty($s['agent_service']), __('Agent service', 'ai2web'), __('Expose /ai2w/agent, a natural-language endpoint answered by WordPress\'s built-in AI Client using the provider you connected. It only appears in the manifest when a provider is available.', 'ai2web'));
        if (!function_exists('wp_ai_client_prompt')) {
            echo '<tr><th scope="row"></th><td><p class="description">' . esc_html__('The WordPress AI Client was not detected (needs WordPress 7.0+ with a connected provider), so the agent service is not exposed yet.', 'ai2web') . '</p></td></tr>';
        }

        self::checkbox_row($opt, 'oauth2', !empty($s['oauth2']), __('OAuth2 (PKCE)', 'ai2web'), __('Let agents authenticate via an OAuth2 authorization-code + PKCE flow, where a logged-in user approves access. Anonymous, ownership-gated access remains the fallback.', 'ai2web'));

        if ($has_woo) {
            self::checkbox_row($opt, 'commerce_actions', !empty($s['commerce_actions']), __('WooCommerce actions', 'ai2web'), __('Product search, stock checks and order tracking (order tracking is verified by billing email).', 'ai2web'));
            self::checkbox_row($opt, 'returns_refunds', !empty($s['returns_refunds']), __('Return / refund requests', 'ai2web'), __('Let agents log return and refund requests for you to action. Never issues a refund automatically.', 'ai2web'));
            self::checkbox_row($opt, 'checkout', !empty($s['checkout']), __('Agent checkout', 'ai2web'), __('Let agents assemble a cart into a pending order and return a secure payment link. The customer pays in the browser; the agent never handles payment.', 'ai2web'));
            if (!empty($s['checkout'])) {
                self::checkbox_row($opt, 'acp', !empty($s['acp']), __('ACP checkout (Agentic Commerce Protocol)', 'ai2web'), __('Expose ACP checkout sessions at /ai2w/acp/checkout_sessions and a product feed at /ai2w/acp/feed, so shopper agents (e.g. ChatGPT Instant Checkout) can run a full cart -> shipping -> coupon -> pay flow. Without a payment handler configured, completing a session returns a pending order and its secure pay link.', 'ai2web'));
            }
        } else {
            echo '<tr><th scope="row">' . esc_html__('WooCommerce', 'ai2web') . '</th><td><p class="description">' . esc_html__('Not detected. Install WooCommerce to expose product, order and returns actions.', 'ai2web') . '</p></td></tr>';
        }

        // Contact forms: surface detected providers so the user sees the submit_contact action was added.
        $form_labels = [
            'contact_form_7'  => 'Contact Form 7',
            'gravity_forms'   => 'Gravity Forms',
            'wpforms'         => 'WPForms',
            'fluent_forms'    => 'Fluent Forms',
            'elementor_forms' => 'Elementor Forms',
        ];
        $forms_detected = array_keys(array_filter(Ai2Web_Forms::detect()));
        if (!empty($forms_detected)) {
            $names = array_map(static fn(string $p): string => $form_labels[$p] ?? $p, $forms_detected);
            echo '<tr><th scope="row">' . esc_html__('Contact forms', 'ai2web') . '</th><td><p class="description">'
                . esc_html(sprintf(
                    /* translators: %s: comma-separated list of detected form plugins */
                    __('Detected: %s. Exposing a submit_contact action (approval-gated, delivered to your support email).', 'ai2web'),
                    implode(', ', $names)
                ))
                . '</p></td></tr>';
        } else {
            echo '<tr><th scope="row">' . esc_html__('Contact forms', 'ai2web') . '</th><td><p class="description">'
                . esc_html__('No form plugin detected. Install Contact Form 7, WPForms, Gravity Forms or Fluent Forms to expose a contact action.', 'ai2web')
                . '</p></td></tr>';
        }

        echo '</tbody></table>';
        submit_button();
        echo '</form>';
        echo '</div>';
    }

    private static function checkbox_row(string $opt, string $key, bool $checked, string $label, string $desc): void
    {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td>';
        echo '<label><input type="checkbox" name="' . esc_attr($opt) . '[' . esc_attr($key) . ']" value="1" ' . checked($checked, true, false) . ' /> ';
        echo esc_html__('Enabled', 'ai2web') . '</label>';
        echo '<p class="description">' . esc_html($desc) . '</p>';
        echo '</td></tr>';
    }

    /**
     * Local port of the AI2Web scoring algorithm (spec sections 9 and 11), kept in sync with
     * the reference validator. Returns score, tier and per-check results.
     *
     * @param array<string,mixed> $m
     * @return array{score:int,tier:string,checks:array<int,array{ok:bool,label:string,hint:string}>}
     */
    public static function score(array $m): array
    {
        $has = static function ($v): bool {
            return $v === true || (is_array($v) && ($v['enabled'] ?? false) === true);
        };
        $cap = static fn(string $n) => $m['capabilities'][$n] ?? null;

        $errors = 0;
        if (($m['protocol'] ?? '') !== 'ai2w') {
            $errors++;
        }
        if (!preg_match('/^\d+\.\d+(\.\d+)?$/', (string) ($m['version'] ?? ''))) {
            $errors++;
        }
        foreach (['name', 'url', 'type'] as $k) {
            if (empty($m['site'][$k])) {
                $errors++;
            }
        }
        if (empty($m['capabilities'])) {
            $errors++;
        }

        $actions_exist = $has($cap('actions'))
            || (!empty($m['actions']) && is_array($m['actions']))
            || $has($cap('commerce')) || $has($cap('booking'));

        $commerce = $cap('commerce');
        $checkout_ok = !$has($commerce) || (is_array($commerce) && ($commerce['checkout'] ?? false) === true);
        $oauth_ok = isset($m['auth']['methods']) && is_array($m['auth']['methods'])
            && in_array('oauth2', $m['auth']['methods'], true)
            && (($m['auth']['oauth2']['pkce'] ?? false) === true);
        $consent_declared = !empty($m['consent']['requires_user_approval_for']);

        $checks = [
            ['ok' => $errors === 0, 'pts' => 30, 'label' => __('Valid discovery manifest', 'ai2web'), 'hint' => __('fix manifest errors', 'ai2web')],
            ['ok' => $has($cap('content')), 'pts' => 6, 'label' => __('Content', 'ai2web'), 'hint' => __('expose a content module', 'ai2web')],
            ['ok' => $has($cap('commerce')) || $has($cap('booking')) || $has($cap('services')), 'pts' => 6, 'label' => __('Products / services / booking', 'ai2web'), 'hint' => __('add WooCommerce or a services module', 'ai2web')],
            ['ok' => $has($cap('search')), 'pts' => 4, 'label' => __('Search', 'ai2web'), 'hint' => __('add a search capability', 'ai2web')],
            ['ok' => $actions_exist, 'pts' => 5, 'label' => __('Actions', 'ai2web'), 'hint' => __('declare actions', 'ai2web')],
            ['ok' => $has($cap('events')), 'pts' => 6, 'label' => __('Events', 'ai2web'), 'hint' => __('publish subscribable events', 'ai2web')],
            ['ok' => !empty($m['agent_service']['enabled']), 'pts' => 4, 'label' => __('Agent service', 'ai2web'), 'hint' => __('expose /ai2w/agent', 'ai2web')],
            ['ok' => $checkout_ok, 'pts' => 4, 'label' => __('Checkout', 'ai2web'), 'hint' => __('commerce present but checkout missing', 'ai2web')],
            ['ok' => !empty($m['transports']['mcp']['enabled']), 'pts' => 8, 'label' => __('MCP transport', 'ai2web'), 'hint' => __('enable the MCP endpoint', 'ai2web')],
            ['ok' => !empty($m['transports']['rest']['enabled']) || !empty($m['transports']['feeds']), 'pts' => 4, 'label' => __('REST / feeds', 'ai2web'), 'hint' => __('expose REST or feeds', 'ai2web')],
            ['ok' => !$actions_exist || $oauth_ok, 'pts' => 8, 'label' => __('OAuth2 + PKCE', 'ai2web'), 'hint' => __('protected actions need oauth2 + pkce', 'ai2web')],
            ['ok' => !$actions_exist || $consent_declared, 'pts' => 7, 'label' => __('Consent declared', 'ai2web'), 'hint' => __('declare consent for sensitive actions', 'ai2web')],
            ['ok' => !empty($m['identity']), 'pts' => 4, 'label' => __('Identity', 'ai2web'), 'hint' => __('add identity (privacy policy)', 'ai2web')],
            ['ok' => !empty($m['contact']), 'pts' => 4, 'label' => __('Contact', 'ai2web'), 'hint' => __('add a support email above', 'ai2web')],
        ];

        $score = 0;
        $out = [];
        foreach ($checks as $c) {
            if ($c['ok']) {
                $score += $c['pts'];
            }
            $out[] = ['ok' => (bool) $c['ok'], 'label' => $c['label'], 'hint' => $c['ok'] ? '' : $c['hint']];
        }
        $score = min(100, $score);

        $basic = $errors === 0;
        $standard = $basic && !empty($m['transports']) && (!$actions_exist || $consent_declared) && !empty($m['contact']);
        $enterprise = $standard && !empty($m['identity']) && !empty($m['auth']) && !empty($m['rate_limits']);
        $tier = $enterprise ? 'Enterprise' : ($standard ? 'Standard' : ($basic ? 'Basic' : 'Invalid'));

        return ['score' => $score, 'tier' => $tier, 'checks' => $out];
    }
}
