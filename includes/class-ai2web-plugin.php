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

    /** WordPress user id authenticated for this request via an OAuth2 bearer token (0 = anonymous). */
    private static int $token_user_id = 0;

    /** The user authenticated via OAuth2 bearer token for this request, or 0 if anonymous. */
    public static function token_user_id(): int
    {
        return self::$token_user_id;
    }

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
            || $path === '/.well-known/oauth-authorization-server'
            || strncmp($path, '/ai2w/', 6) === 0;
        if (!$known) {
            return;
        }
        if (!Ai2Web_Settings::get('enabled', true)) {
            return; // endpoints disabled in settings; let WordPress serve normally
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))) : 'GET';

        // Authenticate via an AI2Web OAuth2 bearer token if one is presented. This is additive:
        // requests without a token still work under the anonymous ownership + approval model.
        // We record the authenticated user id but deliberately do NOT call wp_set_current_user():
        // that would broaden the whole request to the token owner's capabilities. User-scoped
        // actions should read self::token_user_id() and enforce their own per-resource checks.
        $bearer = self::bearer_token();
        if ($bearer !== '') {
            self::$token_user_id = Ai2Web_OAuth::user_for_token($bearer);
        }

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

        // OAuth2 authorize endpoint: renders a consent screen or redirects, then exits.
        if ($path === '/ai2w/oauth/authorize') {
            if (Ai2Web_OAuth::available()) {
                Ai2Web_OAuth::authorize($method);
            }
            status_header(404);
            wp_send_json(['error' => ['code' => 'not_found', 'message' => 'OAuth is disabled.']], 404);
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

        // Analytics (RFC-0016): personal-data-free, server-side event per interaction. Local-first.
        Ai2Web_Analytics::record_for($path, $method, is_array($body) ? $body : [], $res);

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

    /** Extract a Bearer token from the Authorization header (handles Apache's redirect variant). */
    private static function bearer_token(): string
    {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']);
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        } elseif (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') {
                    $header = (string) $v;
                    break;
                }
            }
        }
        return stripos($header, 'Bearer ') === 0 ? trim(substr($header, 7)) : '';
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
        // Emit a {status,body} result (from a sub-handler) as a router response.
        $emit = static fn(array $r): array => $json((int) $r['status'], $r['body']);

        if ($method === 'OPTIONS') {
            return ['status' => 204, 'headers' => $cors, 'body' => null];
        }
        if ($path === '/.well-known/ai2w') {
            // Derive from the site's configured URL, never the request Host header (host-spoofing guard).
            return $json(200, ['ai2w' => home_url('/ai2w')]);
        }
        if ($path === '/.well-known/oauth-authorization-server') {
            return Ai2Web_OAuth::available()
                ? $json(200, Ai2Web_OAuth::metadata())
                : $error(404, 'not_found', 'OAuth is not enabled.');
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
        if ($path === '/ai2w/agent') {
            if ($method !== 'POST') {
                return $error(405, 'invalid_request', 'Use POST with a query for the agent service.');
            }
            $r = Ai2Web_Agent::handle(is_array($body) ? $body : []);
            return $json($r['status'], $r['body']);
        }
        if ($path === '/ai2w/oauth/token') {
            if ($method !== 'POST') {
                return $error(405, 'invalid_request', 'Use POST for the token endpoint.');
            }
            if (!Ai2Web_OAuth::available()) {
                return $error(404, 'not_found', 'OAuth is not enabled.');
            }
            // Token endpoints are usually form-encoded; accept that or a JSON body.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- OAuth token exchange is authenticated by the code + PKCE verifier, not a nonce.
            $src = (is_array($body) && !empty($body)) ? $body : $_POST;
            $input = [
                'grant_type' => isset($src['grant_type']) ? sanitize_text_field(wp_unslash($src['grant_type'])) : '',
                'code' => isset($src['code']) ? sanitize_text_field(wp_unslash($src['code'])) : '',
                'redirect_uri' => isset($src['redirect_uri']) ? esc_url_raw(wp_unslash($src['redirect_uri'])) : '',
                'code_verifier' => isset($src['code_verifier']) ? sanitize_text_field(wp_unslash($src['code_verifier'])) : '',
                'client_id' => isset($src['client_id']) ? sanitize_text_field(wp_unslash($src['client_id'])) : '',
            ];
            header('Cache-Control: no-store'); // OAuth2: token responses must not be cached
            header('Pragma: no-cache');
            $r = Ai2Web_OAuth::token($input);
            return $json($r['status'], $r['body']);
        }
        if (preg_match('#^/ai2w/actions/([a-z0-9_-]+)$#i', $path, $mm)) {
            $name = str_replace('-', '_', strtolower($mm[1]));
            $input = is_array($body) ? $body : [];
            $r = Ai2Web_Actions::run($name, $input);
            return $json($r['status'], $r['body']);
        }
        // ACP (Agentic Commerce Protocol) product feed.
        if ($path === '/ai2w/acp/feed') {
            if ($method !== 'GET') {
                return $error(405, 'invalid_request', 'Use GET for the ACP feed.');
            }
            if (!Ai2Web_ACP::enabled()) {
                return $error(404, 'not_found', 'ACP checkout is not enabled.');
            }
            return $json(200, ['version' => Ai2Web_ACP::SPEC_VERSION, 'products' => Ai2Web_ACP::feed()]);
        }
        // ACP checkout sessions: /ai2w/acp/checkout_sessions[/{id}[/complete|/cancel]].
        if (preg_match('#^/ai2w/acp/checkout_sessions(?:/([A-Za-z0-9_]+))?(?:/(complete|cancel))?$#', $path, $cm)) {
            $id = ($cm[1] ?? '') !== '' ? $cm[1] : null;
            $action = ($cm[2] ?? '') !== '' ? $cm[2] : null;
            $r = Ai2Web_ACP::dispatch($method, $id, $action, is_array($body) ? $body : []);
            return ['status' => $r['status'], 'headers' => array_merge(['content-type' => 'application/json; charset=utf-8'], $cors, $r['headers'] ?? []), 'body' => $r['body']];
        }
        // AP2 (Agent Payments Protocol) merchant surface.
        if (strncmp($path, '/ai2w/ap2', 9) === 0) {
            if (!Ai2Web_AP2::enabled()) {
                return $error(404, 'not_found', 'AP2 is not enabled.');
            }
            if ($path === '/ai2w/ap2/agent-card') {
                return $method === 'GET' ? $json(200, Ai2Web_AP2::agent_card()) : $error(405, 'invalid_request', 'Use GET for the agent card.');
            }
            if ($path === '/ai2w/ap2/jwks') {
                return $method === 'GET' ? $json(200, Ai2Web_AP2::jwks()) : $error(405, 'invalid_request', 'Use GET for the JWKS.');
            }
            if ($path === '/ai2w/ap2/cart') {
                return $method === 'POST' ? $emit(Ai2Web_AP2::dispatch_cart(is_array($body) ? $body : [])) : $error(405, 'invalid_request', 'Use POST with an IntentMandate.');
            }
            if ($path === '/ai2w/ap2/payment') {
                return $method === 'POST' ? $emit(Ai2Web_AP2::dispatch_payment(is_array($body) ? $body : [])) : $error(405, 'invalid_request', 'Use POST with a PaymentMandate.');
            }
            if ($path === '/ai2w/ap2') {
                return $method === 'POST' ? $emit(Ai2Web_AP2::dispatch_a2a(is_array($body) ? $body : [])) : $error(405, 'invalid_request', 'Use POST (A2A JSON-RPC message/send).');
            }
            return $error(404, 'invalid_request', "No AP2 route for $path.");
        }
        return $error(404, 'invalid_request', "No AI2Web route for $path.");
    }
}
