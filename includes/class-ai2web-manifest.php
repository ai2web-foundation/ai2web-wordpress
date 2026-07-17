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

        if (Ai2Web_MCP::enabled()) {
            $transports['mcp'] = ['enabled' => true, 'endpoint' => '/ai2w/mcp'];
        }

        $actions = Ai2Web_Actions::declared();
        if (!empty($actions)) {
            $capabilities['actions'] = ['enabled' => true, 'endpoint' => '/ai2w/actions'];
        }

        $manifest = [
            'protocol' => 'ai2w',
            'version' => '0.2',
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

        $approval = [];
        if (!empty($actions)) {
            $manifest['actions'] = $actions;
            foreach ($actions as $a) {
                if (!empty($a['requires_user_approval'])) {
                    $approval[] = (string) $a['name'];
                }
            }
            $approval = array_values(array_unique($approval));
            if (!empty($approval)) {
                $manifest['consent'] = ['requires_user_approval_for' => $approval];
            }
        }
        if ($has_woo) {
            $manifest['events'] = [
                'endpoint' => '/ai2w/events',
                'delivery' => ['webhook', 'poll'],
                'types' => Ai2Web_WooCommerce::event_types(),
            ];
        }

        // v0.2 optional modules (additive; a minimal manifest stays valid without them).

        // Governance: a default rate limit plus the consent mode derived from approval actions.
        $consent_mode = [];
        foreach ($approval as $name) {
            $consent_mode[$name] = 'explicit';
        }
        $manifest['governance'] = apply_filters('ai2web_governance', [
            'rate_limits' => ['requests' => 60, 'window_seconds' => 60],
            'data_scope' => 'public_content' . ($has_woo ? '_and_own_orders' : ''),
            // Cast so an empty consent map serialises as {} (an object), not [] (an array).
            'consent_mode' => (object) $consent_mode,
        ]);

        // Usage policy: protective defaults; site owners can relax them via the filter.
        $manifest['usage_policy'] = apply_filters('ai2web_usage_policy', [
            'bulk_extraction' => false,
            'model_training' => false,
        ]);

        // Legal is opt-in: we never assert a jurisdiction or compliance the owner has not declared.
        $legal = apply_filters('ai2web_legal', []);
        if (is_array($legal) && !empty($legal)) {
            $manifest['legal'] = $legal;
        }

        // Knowledge: point agents at the structured content (and catalog) sources.
        $knowledge = [
            ['id' => 'content', 'name' => 'Site content', 'kind' => 'articles', 'ref' => '/ai2w/content', 'format' => 'json'],
        ];
        if ($has_woo) {
            $knowledge[] = ['id' => 'catalog', 'name' => 'Product catalog', 'kind' => 'catalog', 'ref' => '/ai2w/products', 'format' => 'json'];
        }
        $manifest['knowledge'] = apply_filters('ai2web_knowledge', $knowledge);

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
