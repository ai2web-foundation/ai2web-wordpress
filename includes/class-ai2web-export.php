<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Export adapters (RFC-0015): project the AI2Web manifest into other discovery formats,
 * so agents that speak llms.txt or a generic agent.json can use the site without parsing
 * ai2w first. Self-contained so the plugin needs no Composer. Mirrors @ai2web/core export.
 */
final class Ai2Web_Export
{
    /** @param mixed $v */
    private static function enabled($v): bool
    {
        return $v === true || (is_array($v) && (($v['enabled'] ?? null) === true));
    }

    /**
     * @param array<string,mixed> $m
     * @return list<string>
     */
    private static function enabled_capabilities(array $m): array
    {
        $out = [];
        foreach (($m['capabilities'] ?? []) as $k => $v) {
            if (self::enabled($v)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * Plain-text summary and links a model can read for content and guidance. Reads only.
     *
     * @param array<string,mixed> $m
     */
    public static function llms_txt(array $m): string
    {
        $site = $m['site'] ?? [];
        $base = rtrim((string) ($site['url'] ?? ''), '/');
        $lines = ['# ' . (string) ($site['name'] ?? '')];
        if (!empty($site['description'])) {
            $lines[] = '';
            $lines[] = '> ' . $site['description'];
        }

        $caps = self::enabled_capabilities($m);
        if ($caps) {
            $lines[] = '';
            $lines[] = '## Capabilities';
            foreach ($caps as $c) {
                $lines[] = '- ' . $c;
            }
        }

        $knowledge = $m['knowledge'] ?? [];
        if ($knowledge) {
            $lines[] = '';
            $lines[] = '## Knowledge';
            foreach ($knowledge as $k) {
                $ref = (string) ($k['ref'] ?? '');
                if (!str_starts_with($ref, 'http')) {
                    $ref = $base . (str_starts_with($ref, '/') ? '' : '/') . $ref;
                }
                $lines[] = '- [' . ($k['name'] ?? $k['id'] ?? '') . '](' . $ref . ')';
            }
        }

        $actions = $m['actions'] ?? [];
        if ($actions) {
            $lines[] = '';
            $lines[] = '## Actions';
            foreach ($actions as $a) {
                $lines[] = '- ' . ($a['name'] ?? '') . ': ' . ($a['description'] ?? '');
            }
        }

        $lines[] = '';
        $lines[] = '## Discovery';
        $lines[] = '- Manifest: ' . $base . '/ai2w';
        return implode("\n", $lines) . "\n";
    }

    /**
     * OAuth 2.0 Protected Resource metadata (RFC 9728), served at
     * /.well-known/oauth-protected-resource. MCP clients read this to discover which
     * authorization server guards the resource before starting a flow.
     *
     * Returns null when the site does not advertise oauth2, so we never publish an auth
     * surface the site cannot honour.
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>|null
     */
    public static function oauth_protected_resource(array $m): ?array
    {
        $methods = $m['auth']['methods'] ?? [];
        if (!is_array($methods) || !in_array('oauth2', $methods, true)) {
            return null;
        }
        $base = rtrim((string) ($m['site']['url'] ?? home_url('/')), '/');
        $authz = (string) ($m['auth']['oauth2']['authorization_url'] ?? '');
        $issuer = $base;
        if ($authz !== '') {
            $parts = wp_parse_url($authz);
            if (!empty($parts['scheme']) && !empty($parts['host'])) {
                $issuer = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        $doc = [
            'resource' => $base . '/ai2w',
            'authorization_servers' => [$issuer],
            'bearer_methods_supported' => ['header'],
        ];
        $scopes = $m['auth']['oauth2']['scopes'] ?? null;
        if (is_array($scopes) && !empty($scopes)) {
            $doc['scopes_supported'] = array_values($scopes);
        }
        return $doc;
    }

    /**
     * Map usage_policy onto Content Signals tokens. `search` stays yes because AI2Web exists
     * to be discoverable; AI signals are only asserted when the manifest states them, so an
     * unset policy is never reported as a refusal.
     *
     * @param array<string,mixed> $m
     */
    public static function content_signals(array $m): ?string
    {
        $p = $m['usage_policy'] ?? null;
        if (!is_array($p) || empty($p)) {
            return null;
        }
        $signals = ['search=yes'];
        if (isset($p['content_reproduction']) && is_bool($p['content_reproduction'])) {
            $signals[] = 'ai-input=' . ($p['content_reproduction'] ? 'yes' : 'no');
        }
        if (isset($p['model_training']) && is_bool($p['model_training'])) {
            $signals[] = 'ai-train=' . ($p['model_training'] ? 'yes' : 'no');
        }
        return implode(', ', $signals);
    }

    /**
     * A robots.txt FRAGMENT carrying the usage policy plus a pointer to the manifest. This is
     * appended to the site's robots.txt, never a replacement - the file belongs to the owner.
     *
     * @param array<string,mixed> $m
     */
    public static function robots_txt(array $m): string
    {
        $base = rtrim((string) ($m['site']['url'] ?? home_url('/')), '/');
        $signals = self::content_signals($m);
        $lines = ['# AI2Web usage policy, projected from ' . $base . '/ai2w', 'User-agent: *'];
        if ($signals !== null) {
            $lines[] = 'Content-Signal: ' . $signals;
        }
        if (($m['usage_policy']['bulk_extraction'] ?? null) === false) {
            $lines[] = '# bulk_extraction: false - please use the /ai2w endpoints instead of crawling';
        }
        $lines[] = '# AI2Web-Manifest: ' . $base . '/ai2w';
        return implode("\n", $lines) . "\n";
    }

    /**
     * Project the content listing to Markdown, for agents that ask for `text/markdown` via
     * Accept. Complements llms.txt: that is an index, this is the readable content listing.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $m
     */
    public static function content_markdown(array $items, array $m): string
    {
        $lines = ['# ' . (string) ($m['site']['name'] ?? '')];
        if (!empty($m['site']['description'])) {
            $lines[] = '';
            $lines[] = '> ' . (string) $m['site']['description'];
        }
        $lines[] = '';
        $lines[] = '## Content';
        foreach ($items as $it) {
            $lines[] = '';
            $lines[] = '### ' . (string) ($it['title'] ?? '');
            $lines[] = '';
            if (!empty($it['url'])) {
                $lines[] = '- URL: ' . (string) $it['url'];
            }
            if (!empty($it['type'])) {
                $lines[] = '- Type: ' . (string) $it['type'];
            }
            if (!empty($it['published'])) {
                $lines[] = '- Published: ' . (string) $it['published'];
            }
            if (!empty($it['excerpt'])) {
                $lines[] = '';
                $lines[] = (string) $it['excerpt'];
            }
        }
        return implode("\n", $lines) . "\n";
    }

    /** Value for an HTTP Link header advertising the manifest to non-HTML clients. */
    public static function discovery_link_header(array $m): string
    {
        $base = rtrim((string) ($m['site']['url'] ?? home_url('/')), '/');
        return '<' . $base . '/ai2w>; rel="ai2w"';
    }

    /**
     * Generic agent.json style capability document. Best-effort, format-neutral projection.
     *
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    public static function agent_json(array $m): array
    {
        $site = $m['site'] ?? [];
        $consent = $m['consent'] ?? [];
        $actions = [];
        foreach (($m['actions'] ?? []) as $a) {
            $actions[] = [
                'name' => $a['name'] ?? null,
                'intent' => $a['intent'] ?? null,
                'description' => $a['description'] ?? null,
                'risk' => $a['risk'] ?? null,
                'requires_consent' => $a['requires_user_approval'] ?? null,
                'requires_auth' => $a['requires_auth'] ?? null,
                'input_schema' => $a['input_schema'] ?? null,
                'bindings' => $a['bindings'] ?? [['kind' => 'rest', 'ref' => $a['endpoint'] ?? null]],
            ];
        }
        return [
            'schema' => 'agent-capabilities',
            'name' => $site['name'] ?? null,
            'description' => $site['description'] ?? null,
            'url' => $site['url'] ?? null,
            'identity' => $m['identity'] ?? null,
            'capabilities' => self::enabled_capabilities($m),
            'actions' => $actions,
            'knowledge' => $m['knowledge'] ?? null,
            'transports' => $m['transports'] ?? null,
            'policies' => [
                'consent' => $consent['requires_user_approval_for'] ?? null,
                'governance' => $m['governance'] ?? null,
                'usage' => $m['usage_policy'] ?? null,
                'legal' => $m['legal'] ?? null,
            ],
        ];
    }
}
