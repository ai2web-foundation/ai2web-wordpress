<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the AI2Web manifest from the live WordPress site + detected integrations.
 * "Describe your website once" - here, auto-derived from what's installed.
 */
final class Ai2Web_Manifest
{
    /** @return array<string,mixed> */
    public static function build(): array
    {
        $has_woo = class_exists('WooCommerce');

        $capabilities = [
            'content' => ['enabled' => true, 'endpoint' => '/ai2w/content'],
            'search'  => ['enabled' => true, 'endpoint' => '/ai2w/search'],
        ];
        $transports = [
            'rest'  => ['enabled' => true, 'base' => '/ai2w'],
            'feeds' => ['json_feed' => '/ai2w/content', 'rss' => '/feed/'],
        ];

        if ($has_woo) {
            $capabilities['commerce'] = ['enabled' => true, 'endpoint' => '/ai2w/products', 'checkout' => false, 'returns' => true];
            $capabilities['events']   = ['enabled' => true, 'endpoint' => '/ai2w/events'];
            $transports['acp'] = ['enabled' => false, 'endpoint' => '/ai2w/acp']; // enable when an ACP connector is present
        }

        $form_actions = Ai2Web_Forms::detect_actions();
        if (!empty($form_actions)) {
            $capabilities['actions'] = ['enabled' => true, 'endpoint' => '/ai2w/actions'];
        }

        $manifest = [
            'protocol' => 'ai2w',
            'version' => '0.1',
            'site' => [
                'name' => get_bloginfo('name'),
                'url' => home_url(),
                'type' => $has_woo ? 'ecommerce' : 'content',
                'description' => get_bloginfo('description'),
                'languages' => [get_bloginfo('language')],
            ],
            'identity' => [
                'privacy_policy' => get_privacy_policy_url() ?: null,
            ],
            'capabilities' => $capabilities,
            'transports' => $transports,
            'auth' => ['methods' => ['none']],
        ];

        // Support contact is OPT-IN: we do NOT publish admin_email to anonymous callers
        // by default (PII/spam). Site owners provide a public address via this filter.
        $support = (string) apply_filters('ai2web_support_contact', '');
        if ($support !== '') {
            $manifest['contact'] = ['support' => sanitize_email($support)];
        }

        if (!empty($form_actions)) {
            $manifest['actions'] = $form_actions;
            $manifest['consent'] = ['requires_user_approval_for' => ['support_ticket']];
        }
        if ($has_woo) {
            $manifest['events'] = [
                'endpoint' => '/ai2w/events',
                'delivery' => ['webhook', 'poll'],
                'types' => Ai2Web_WooCommerce::event_types(),
            ];
        }

        /**
         * Filter the generated manifest - themes/plugins can add capabilities, actions or x-* extensions.
         * @param array<string,mixed> $manifest
         */
        return apply_filters('ai2web_manifest', $manifest);
    }

    /** @return array<int,array<string,mixed>> Recent content as structured items. */
    public static function content(): array
    {
        $out = [];
        $posts = get_posts(['numberposts' => 20, 'post_type' => ['post', 'page'], 'post_status' => 'publish']);
        foreach ($posts as $p) {
            $out[] = [
                'id' => $p->ID,
                'type' => $p->post_type,
                'title' => get_the_title($p),
                'url' => get_permalink($p),
                'excerpt' => wp_strip_all_tags(get_the_excerpt($p)),
                'published' => get_post_time('c', true, $p),
            ];
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public static function search(string $query): array
    {
        if ($query === '') {
            return [];
        }
        $out = [];
        foreach (get_posts(['s' => $query, 'numberposts' => 20, 'post_status' => 'publish']) as $p) {
            $out[] = ['title' => get_the_title($p), 'url' => get_permalink($p), 'type' => $p->post_type];
        }
        return $out;
    }

    /**
     * Capability negotiation (spec §5) - self-contained mirror of Ai2Web\Negotiator.
     * @param array<string,mixed> $m
     * @param array<string,mixed> $agent
     * @return array<string,mixed>
     */
    public static function negotiate(array $m, array $agent): array
    {
        $endpoint_of = static fn(string $name, mixed $v): string => (is_array($v) && is_string($v['endpoint'] ?? null)) ? $v['endpoint'] : "/ai2w/$name";

        $site_caps = [];
        foreach (($m['capabilities'] ?? []) as $k => $v) {
            if ($v === true || (is_array($v) && ($v['enabled'] ?? false))) {
                $site_caps[] = $k;
            }
        }
        $want = $agent['capabilities'] ?? $site_caps;
        $caps = array_values(array_intersect($site_caps, $want));
        $unsupported = array_values(array_diff($want, $site_caps));

        // Only enabled transports are negotiable (parity with Negotiator/negotiate.ts).
        $site_t = [];
        foreach (($m['transports'] ?? []) as $k => $v) {
            if (is_array($v) && ($v['enabled'] ?? false) === true) {
                $site_t[] = $k;
            }
        }
        $want_t = $agent['transports'] ?? $site_t;
        $transport = null;
        foreach ($want_t as $t) {
            if (in_array($t, $site_t, true)) { $transport = $t; break; }
        }

        // Auth by intersection, preferring oauth2 (parity with Negotiator).
        $site_auth = $m['auth']['methods'] ?? ['none'];
        $want_auth = $agent['auth'] ?? $site_auth;
        $auth = null;
        if (in_array('oauth2', $site_auth, true) && in_array('oauth2', $want_auth, true)) {
            $auth = 'oauth2';
        } else {
            foreach ($want_auth as $a) {
                if (in_array($a, $site_auth, true)) { $auth = $a; break; }
            }
            if ($auth === null && in_array('none', $site_auth, true)) { $auth = 'none'; }
        }

        // Per-capability endpoint map.
        $endpoints = [];
        foreach ($caps as $c) {
            $endpoints[$c] = $endpoint_of($c, $m['capabilities'][$c]);
        }
        if ($transport !== null && is_array($m['transports'][$transport] ?? null) && isset($m['transports'][$transport]['endpoint'])) {
            $endpoints[$transport] = $m['transports'][$transport]['endpoint'];
        }

        return ['negotiated' => ['transport' => $transport, 'capabilities' => $caps, 'auth' => $auth, 'endpoints' => $endpoints], 'unsupported' => $unsupported];
    }
}
