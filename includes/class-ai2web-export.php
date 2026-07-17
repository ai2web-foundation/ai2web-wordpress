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
