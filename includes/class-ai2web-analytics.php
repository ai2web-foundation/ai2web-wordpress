<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * RFC-0016 analytics: personal-data-free, server-side interaction events. Parity with
 * @ai2web/server. Local-first: events are stored in a plugin table AND fired as the
 * `ai2web_event` action so operators can forward them anywhere. A query with an empty
 * result is recorded as a `miss` - the demand signal a read-only crawl cannot produce.
 */
final class Ai2Web_Analytics
{
    private const TABLE = 'ai2web_events';
    private const RETAIN_DAYS = 90;

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Create the events table (called on activation). */
    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $t = self::table();
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ts DATETIME NOT NULL,
            type VARCHAR(16) NOT NULL,
            capability VARCHAR(48) DEFAULT NULL,
            name VARCHAR(64) DEFAULT NULL,
            result VARCHAR(16) NOT NULL,
            filters TEXT DEFAULT NULL,
            agent VARCHAR(64) DEFAULT NULL,
            audit_ref VARCHAR(64) DEFAULT NULL,
            latency INT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_type_ts (type, ts),
            KEY idx_capability (capability)
        ) {$charset};");
    }

    /** Classify a served route into an event and record it. */
    public static function record_for(string $path, string $method, array $body, array $res): void
    {
        if (($res['status'] ?? 200) >= 500) {
            return;
        }
        $status = (int) ($res['status'] ?? 200);
        $ev = null;
        if ($path === '/ai2w' || $path === '/.well-known/ai2w') {
            $ev = ['type' => 'discovery', 'result' => 'hit'];
        } elseif (in_array($path, ['/ai2w/content', '/ai2w/search', '/ai2w/products', '/ai2w/events'], true)) {
            $ev = [
                'type' => 'query',
                'capability' => substr($path, strlen('/ai2w/')),
                'result' => self::is_empty($res['body'] ?? null) ? 'miss' : 'count',
                'filters' => self::sanitize_filters($body),
            ];
        } elseif (preg_match('#^/ai2w/actions/([a-z0-9_-]+)$#i', $path, $m)) {
            $ev = ['type' => 'action', 'name' => str_replace('-', '_', strtolower($m[1])), 'result' => $status < 400 ? 'success' : 'error'];
        } elseif ($path === '/ai2w/agent') {
            $ev = ['type' => 'action', 'name' => 'agent', 'result' => $status < 400 ? 'success' : 'error'];
        }
        if ($ev !== null) {
            self::record($ev);
        }
    }

    /** Store + broadcast one event. Filterable off via `ai2web_analytics_enabled`. */
    public static function record(array $event): void
    {
        $event = array_merge([
            'ts' => gmdate('Y-m-d H:i:s'),
            'agent' => self::agent(),
        ], $event);

        /** Sink hook: subscribe with add_action('ai2web_event', fn($event) => ...). */
        do_action('ai2web_event', $event);

        if (!apply_filters('ai2web_analytics_enabled', true)) {
            return;
        }
        global $wpdb;
        $t = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->insert($t, [
            'ts' => $event['ts'],
            'type' => (string) $event['type'],
            'capability' => isset($event['capability']) ? (string) $event['capability'] : null,
            'name' => isset($event['name']) ? (string) $event['name'] : null,
            'result' => (string) $event['result'],
            'filters' => isset($event['filters']) && $event['filters'] ? wp_json_encode($event['filters']) : null,
            'agent' => $event['agent'] ?: null,
            'audit_ref' => isset($event['audit_ref']) ? (string) $event['audit_ref'] : null,
            'latency' => isset($event['latency']) ? (int) $event['latency'] : null,
        ]);
        // Opportunistic retention (RFC-0009): prune old rows ~1% of writes.
        if (wp_rand(1, 100) === 1) {
            $cutoff = gmdate('Y-m-d H:i:s', time() - self::RETAIN_DAYS * DAY_IN_SECONDS);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $t is $wpdb->prefix . a constant table name (not user input); the value is prepared.
            $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE ts < %s", $cutoff));
        }
    }

    /** Coarse agent identity from the User-Agent (RFC-0013); never an end-user identifier. */
    private static function agent(): string
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        return substr($ua, 0, 64);
    }

    /** Keep only non-identifying scalar params; drop emails, long digit runs, long keys/values. */
    private static function sanitize_filters(array $body): ?array
    {
        $out = [];
        foreach ($body as $k => $v) {
            if (!is_string($k) || strlen($k) > 24 || count($out) >= 8) {
                break;
            }
            if (is_int($v) || is_float($v) || is_bool($v)) {
                $out[$k] = $v;
            } elseif (is_string($v) && strlen($v) <= 40 && !preg_match('/@|\d{6,}/', $v)) {
                $out[$k] = $v;
            }
        }
        return $out ?: null;
    }

    private static function is_empty(mixed $body): bool
    {
        if (is_array($body)) {
            if (isset($body['results']) && is_array($body['results'])) {
                return count($body['results']) === 0;
            }
            if (isset($body['items']) && is_array($body['items'])) {
                return count($body['items']) === 0;
            }
            if (isset($body['count']) && is_numeric($body['count'])) {
                return (int) $body['count'] === 0;
            }
            // array_is_list() is PHP 8.1+/WP 6.5+; use a version-safe equivalent for wider support.
            if ($body === array_values($body)) {
                return count($body) === 0;
            }
        }
        return false;
    }
}
