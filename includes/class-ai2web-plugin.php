<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Bootstraps AI2Web: intercepts /ai2w, /.well-known/ai2w and /ai2w/* and serves JSON.
 * Backend-first, API-driven (no frontend/DOM tools) - per the AI2Web transport taxonomy.
 */
final class Ai2Web_Plugin
{
    public function boot(): void
    {
        add_action('init', [self::class, 'add_rewrite_rules']);
        add_action('parse_request', [$this, 'maybe_handle']);
        add_action('wp_head', [self::class, 'discovery_link']);
    }

    /**
     * Emit a <link rel="ai2w"> discovery hint in the page head. On multisite this resolves
     * per subsite via home_url(), which is the primary discovery path for subdirectory subsites
     * (they cannot own the domain-root /.well-known anchor).
     */
    public static function discovery_link(): void
    {
        if (!Ai2Web_Settings::get('enabled', true)) {
            return;
        }
        echo '<link rel="ai2w" href="' . esc_url(home_url('/ai2w')) . '">' . "\n";
    }

    public static function add_rewrite_rules(): void
    {
        add_rewrite_rule('^ai2w/?$', 'index.php?ai2web_route=manifest', 'top');
        add_rewrite_rule('^ai2w/(.+)/?$', 'index.php?ai2web_route=$matches[1]', 'top');
        add_rewrite_tag('%ai2web_route%', '([^&]+)');
    }

    /**
     * We match on the raw path so /.well-known/ai2w works without a rewrite rule.
     */
    private const MAX_BODY = 262144; // 256 KB request-body cap (DoS guard).

    public function maybe_handle(WP $wp): void
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
        $path = '/' . trim((string) wp_parse_url($uri, PHP_URL_PATH), '/');

        // Normalise the path relative to this site's home base, so it also works when WordPress
        // lives in a subdirectory and for subdirectory multisite subsites (e.g. /shop/ai2w).
        // Domain-root paths like /.well-known/ai2w do not start with the base and pass through.
        $base = '/' . trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '/' && strpos($path, $base) === 0) {
            $path = '/' . ltrim(substr($path, strlen($base)), '/');
        }

        $known = $path === '/ai2w'
            || $path === '/.well-known/ai2w'
            || $path === '/.well-known/agent.json'
            || $path === '/agent.json'
            || $path === '/llms.txt'
            || strncmp($path, '/ai2w/', 6) === 0;
        if (!$known) {
            return;
        }
        if (!Ai2Web_Settings::get('enabled', true)) {
            return; // endpoints disabled in settings; let WordPress serve normally
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';

        $body = null;
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $raw = file_get_contents('php://input');
            if ($raw !== false && strlen($raw) > self::MAX_BODY) {
                status_header(413);
                wp_send_json(['error' => ['code' => 'payload_too_large', 'retryable' => false]], 413);
            }
            $body = $raw ? json_decode($raw, true) : null;
        }

        // MCP uses its own transport (SSE); it emits the response and exits.
        if ($path === '/ai2w/mcp') {
            if (Ai2Web_MCP::enabled()) {
                Ai2Web_MCP::handle($method, $body);
            }
            status_header(404);
            wp_send_json(['error' => ['code' => 'not_found', 'message' => 'MCP endpoint is disabled.']], 404);
        }

        $manifest = Ai2Web_Manifest::build();

        // llms.txt is a plain-text projection (not JSON), so it is emitted directly.
        if ($path === '/llms.txt') {
            if ($method !== 'GET') {
                status_header(405);
                wp_send_json(['error' => ['code' => 'invalid_request', 'message' => 'Use GET for llms.txt.']], 405);
            }
            status_header(200);
            header('content-type: text/plain; charset=utf-8');
            header('access-control-allow-origin: *');
            echo Ai2Web_Export::llms_txt($manifest); // phpcs:ignore WordPress.Security.EscapeOutput
            exit;
        }

        $res = $this->route($manifest, $method, $path, $body);

        // Emit CORS/custom headers; wp_send_json sets status + JSON content-type and exits.
        foreach ($res['headers'] as $k => $v) {
            if (stripos($k, 'content-type') === 0) {
                continue;
            }
            header($k . ': ' . $v);
        }
        if ($res['body'] === null) {
            status_header($res['status']);
            exit;
        }
        wp_send_json($res['body'], $res['status']);
    }

    /**
     * Minimal router mirroring Ai2Web\Server (kept self-contained so the plugin needs no Composer).
     *
     * @param array<string,mixed> $manifest
     * @return array{status:int,headers:array<string,string>,body:mixed}
     */
    private function route(array $manifest, string $method, string $path, mixed $body): array
    {
        $cors = [
            'access-control-allow-origin' => '*',
            'access-control-allow-methods' => 'GET, POST, OPTIONS',
            'access-control-allow-headers' => 'content-type, authorization',
        ];
        $json = static fn(int $s, mixed $b): array => ['status' => $s, 'headers' => array_merge(['content-type' => 'application/json; charset=utf-8'], $cors), 'body' => $b];
        $error = static fn(int $s, string $c, string $m): array => $json($s, ['error' => ['code' => $c, 'message' => $m, 'retryable' => false]]);

        if ($method === 'OPTIONS') {
            return ['status' => 204, 'headers' => $cors, 'body' => null];
        }
        if ($path === '/.well-known/ai2w') {
            // Derive from the site's configured URL, never the request Host header (host-spoofing guard).
            return $json(200, ['ai2w' => home_url('/ai2w')]);
        }
        if ($path === '/.well-known/agent.json' || $path === '/agent.json') {
            if ($method !== 'GET') {
                return $error(405, 'invalid_request', 'Use GET for agent.json.');
            }
            return $json(200, Ai2Web_Export::agent_json($manifest));
        }
        if ($path === '/ai2w') {
            if ($method !== 'GET') {
                return $error(405, 'invalid_request', 'Use GET for the manifest.');
            }
            return $json(200, $manifest);
        }
        if ($path === '/ai2w/negotiate') {
            $supports = is_array($body) ? ($body['agent']['supports'] ?? $body['supports'] ?? $body) : [];
            return $json(200, Ai2Web_Manifest::negotiate($manifest, is_array($supports) ? $supports : []));
        }
        if ($path === '/ai2w/content') {
            return $json(200, Ai2Web_Manifest::content());
        }
        if ($path === '/ai2w/search') {
            $q = is_array($body) && isset($body['query'])
                ? (string) $body['query']
                : (isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '');
            return $json(200, Ai2Web_Manifest::search($q));
        }
        if ($path === '/ai2w/products') {
            return $json(200, Ai2Web_WooCommerce::products());
        }
        if ($path === '/ai2w/events') {
            return $json(200, ['types' => Ai2Web_WooCommerce::event_types()]);
        }
        if (preg_match('#^/ai2w/actions/([a-z0-9_-]+)$#i', $path, $mm)) {
            $name = str_replace('-', '_', strtolower($mm[1]));
            $input = is_array($body) ? $body : [];
            $r = Ai2Web_Actions::run($name, $input);
            return $json($r['status'], $r['body']);
        }
        return $error(404, 'invalid_request', "No AI2Web route for $path.");
    }
}
