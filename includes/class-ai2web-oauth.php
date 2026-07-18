<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal OAuth2 authorization-code flow with PKCE (S256) for authenticated agent access.
 *
 * An agent obtains an access token by having a logged-in WordPress user approve it on a consent
 * screen. This is an alternative to the anonymous, ownership-gated public actions, which remain
 * the fallback: a request with a valid Bearer token is authenticated as that user; a request
 * without one still works under the ownership + approval model.
 *
 * Scope and design:
 *  - Public clients only. PKCE (S256, required) replaces a client secret; client_id is not
 *    pre-registered, and the user approves the exact redirect_uri on the consent screen.
 *  - Authorization codes: cryptographically random, single-use, 60s, bound to user + client_id +
 *    redirect_uri + code_challenge.
 *  - Access tokens: random, stored only as a SHA-256 hash, 1 hour expiry.
 *
 * This is security-sensitive code and should be independently reviewed before production use.
 */
final class Ai2Web_OAuth
{
    private const CODE_TTL = 60;      // authorization code lifetime (seconds)
    private const TOKEN_TTL = 3600;   // access token lifetime (seconds)
    private const CODE_PREFIX = 'ai2web_oauth_code_';
    private const TOKEN_PREFIX = 'ai2web_oauth_tok_';
    private const SCOPE = 'ai2w:actions';

    public static function available(): bool
    {
        return (bool) Ai2Web_Settings::get('oauth2', true);
    }

    /** The `oauth2` block for the manifest's `auth`. */
    public static function auth_block(): array
    {
        return [
            'pkce' => true,
            'authorization_endpoint' => home_url('/ai2w/oauth/authorize'),
            'token_endpoint' => home_url('/ai2w/oauth/token'),
            'scopes_supported' => [self::SCOPE],
            'code_challenge_methods_supported' => ['S256'],
        ];
    }

    /** RFC 8414 authorization server metadata. */
    public static function metadata(): array
    {
        return [
            'issuer' => home_url('/'),
            'authorization_endpoint' => home_url('/ai2w/oauth/authorize'),
            'token_endpoint' => home_url('/ai2w/oauth/token'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
        ];
    }

    /**
     * Handle /ai2w/oauth/authorize. GET renders the consent screen; POST processes consent.
     * Emits its own output (HTML or a redirect) and exits.
     */
    public static function authorize(string $method): void
    {
        if (!self::secure_context()) {
            self::html_error(__('OAuth requires a secure (HTTPS) connection.', 'ai2web'));
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth params, validated below; consent POST is nonce-checked.
        $src = $method === 'POST' ? $_POST : $_GET;
        $get = static fn(string $k): string => isset($src[$k]) ? sanitize_text_field(wp_unslash($src[$k])) : '';
        $client_id     = $get('client_id');
        $redirect_uri  = isset($src['redirect_uri']) ? esc_url_raw(wp_unslash($src['redirect_uri'])) : '';
        $response_type = $get('response_type');
        $scope         = $get('scope') ?: self::SCOPE;
        $state         = $get('state');
        $challenge     = $get('code_challenge');
        $challenge_m   = $get('code_challenge_method');
        // phpcs:enable

        // redirect_uri must be valid before we can safely send errors back to it.
        if ($redirect_uri === '' || !self::valid_redirect($redirect_uri)) {
            self::html_error(__('Invalid or missing redirect_uri.', 'ai2web'));
        }
        if ($client_id === '') {
            self::redirect_error($redirect_uri, 'invalid_request', 'missing client_id', $state);
        }
        // Optional hardening: a site owner can restrict which client_ids may request access.
        // Empty (default) allows any client (dynamic public clients); a non-empty list is enforced.
        $allowed = apply_filters('ai2web_oauth_allowed_clients', []);
        if (is_array($allowed) && !empty($allowed) && !in_array($client_id, $allowed, true)) {
            self::redirect_error($redirect_uri, 'unauthorized_client', 'client is not allowed', $state);
        }
        if ($response_type !== 'code') {
            self::redirect_error($redirect_uri, 'unsupported_response_type', 'only code is supported', $state);
        }
        // S256 code_challenge is BASE64URL(SHA-256(verifier)): exactly 43 unpadded base64url chars.
        if ($challenge_m !== 'S256' || !preg_match('/^[A-Za-z0-9\-_]{43}$/', $challenge)) {
            self::redirect_error($redirect_uri, 'invalid_request', 'a valid S256 PKCE code_challenge is required', $state);
        }
        // Only scopes this server advertises may be requested (space-delimited per RFC 6749).
        foreach (preg_split('/\s+/', trim($scope)) ?: [] as $requested) {
            if ($requested !== '' && $requested !== self::SCOPE) {
                self::redirect_error($redirect_uri, 'invalid_scope', 'requested scope is not supported', $state);
            }
        }

        // A logged-in WordPress user must grant consent.
        if (!is_user_logged_in()) {
            $return = home_url('/ai2w/oauth/authorize') . '?' . http_build_query([
                'client_id' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => 'code',
                'scope' => $scope, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
            ]);
            wp_safe_redirect(wp_login_url($return));
            exit;
        }

        if ($method === 'POST') {
            $nonce = isset($_POST['_ai2web_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ai2web_nonce'])) : '';
            if (!wp_verify_nonce($nonce, 'ai2web_oauth_consent')) {
                self::redirect_error($redirect_uri, 'access_denied', 'invalid consent', $state);
            }
            $decision = isset($_POST['ai2web_consent']) ? sanitize_text_field(wp_unslash($_POST['ai2web_consent'])) : 'deny';
            if ($decision !== 'approve') {
                self::redirect_error($redirect_uri, 'access_denied', 'user denied the request', $state);
            }

            $code = bin2hex(random_bytes(32));
            set_transient(self::CODE_PREFIX . hash('sha256', $code), [
                'user_id' => get_current_user_id(),
                'client_id' => $client_id,
                'redirect_uri' => $redirect_uri,
                'challenge' => $challenge,
                'scope' => $scope,
            ], self::CODE_TTL);

            $sep = strpos($redirect_uri, '?') === false ? '?' : '&';
            $params = ['code' => $code];
            if ($state !== '') {
                $params['state'] = $state;
            }
            wp_redirect($redirect_uri . $sep . http_build_query($params)); // cross-origin callback: user-approved
            exit;
        }

        self::render_consent($client_id, $redirect_uri, $scope, $state, $challenge);
    }

    /**
     * Handle POST /ai2w/oauth/token (authorization_code grant + PKCE).
     * @param array<string,mixed> $body
     * @return array{status:int,body:mixed}
     */
    public static function token(array $body): array
    {
        if (!self::secure_context()) {
            return ['status' => 400, 'body' => ['error' => 'invalid_request', 'error_description' => 'a secure (HTTPS) connection is required']];
        }
        if (!self::rate_ok()) {
            return ['status' => 429, 'body' => ['error' => 'temporarily_unavailable', 'error_description' => 'too many token requests']];
        }
        if ((string) ($body['grant_type'] ?? '') !== 'authorization_code') {
            return self::err('unsupported_grant_type', 'only authorization_code is supported');
        }
        $code = (string) ($body['code'] ?? '');
        $redirect_uri = (string) ($body['redirect_uri'] ?? '');
        $verifier = (string) ($body['code_verifier'] ?? '');
        $client_id = (string) ($body['client_id'] ?? '');
        if ($code === '' || $verifier === '') {
            return self::err('invalid_request', 'code and code_verifier are required');
        }

        $key = self::CODE_PREFIX . hash('sha256', $code);
        $data = get_transient($key);
        if (!is_array($data)) {
            return self::err('invalid_grant', 'code is invalid or expired');
        }
        // Single-use, race-safe: only the caller whose delete actually removes the code proceeds,
        // so two concurrent requests cannot both redeem the same authorization code.
        if (!delete_transient($key)) {
            return self::err('invalid_grant', 'code has already been used');
        }

        if (esc_url_raw($redirect_uri) !== (string) $data['redirect_uri'] || $client_id !== (string) $data['client_id']) {
            return self::err('invalid_grant', 'redirect_uri or client_id mismatch');
        }
        if (!self::pkce_ok($verifier, (string) $data['challenge'])) {
            return self::err('invalid_grant', 'PKCE verification failed');
        }

        $token = bin2hex(random_bytes(32));
        set_transient(self::TOKEN_PREFIX . hash('sha256', $token), [
            'user_id' => (int) $data['user_id'],
            'scope' => (string) $data['scope'],
        ], self::TOKEN_TTL);

        return ['status' => 200, 'body' => [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
            'scope' => (string) $data['scope'],
        ]];
    }

    /** Resolve a Bearer token to a WordPress user id, or 0 if invalid/expired. */
    public static function user_for_token(string $token): int
    {
        if ($token === '') {
            return 0;
        }
        $data = get_transient(self::TOKEN_PREFIX . hash('sha256', $token));
        return is_array($data) ? (int) ($data['user_id'] ?? 0) : 0;
    }

    /**
     * OAuth must run over TLS. Allow plain HTTP only for local/development hosts, so real
     * production sites cannot issue codes or tokens in cleartext. Filterable for reverse-proxy
     * setups where is_ssl() is unreliable.
     */
    private static function secure_context(): bool
    {
        if (is_ssl()) {
            return true;
        }
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if (in_array($env, ['local', 'development'], true)) {
            return true;
        }
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $is_local_host = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || (bool) preg_match('/\.(local|test|localhost)$/', $host);
        return (bool) apply_filters('ai2web_oauth_allow_insecure', $is_local_host);
    }

    /** Per-IP throttle on the token endpoint (defence in depth; codes are already unguessable). */
    private static function rate_ok(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'ai2web_oauth_rl_' . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= 30) {
            return false;
        }
        set_transient($key, $count + 1, 600); // 30 token requests / 10 min / IP
        return true;
    }

    private static function valid_redirect(string $uri): bool
    {
        $p = wp_parse_url($uri);
        // Reject anything without a clear scheme+host, with a fragment, or with embedded
        // credentials (https://legit.com@evil.com would otherwise resolve to evil.com).
        if (!$p || empty($p['scheme']) || empty($p['host']) || !empty($p['fragment'])
            || !empty($p['user']) || !empty($p['pass'])) {
            return false;
        }
        $scheme = strtolower((string) $p['scheme']);
        $host = strtolower((string) $p['host']);
        if ($scheme === 'https') {
            return true;
        }
        // Allow loopback over http for native / CLI agents (RFC 8252).
        return $scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function pkce_ok(string $verifier, string $challenge): bool
    {
        $len = strlen($verifier);
        if ($len < 43 || $len > 128) {
            return false;
        }
        $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        return hash_equals($challenge, $computed);
    }

    private static function redirect_error(string $redirect_uri, string $error, string $desc, string $state): void
    {
        $sep = strpos($redirect_uri, '?') === false ? '?' : '&';
        $params = ['error' => $error, 'error_description' => $desc];
        if ($state !== '') {
            $params['state'] = $state;
        }
        wp_redirect($redirect_uri . $sep . http_build_query($params));
        exit;
    }

    private static function html_error(string $msg): void
    {
        status_header(400);
        header('content-type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__('Authorization error', 'ai2web') . '</title>';
        echo '<body style="font-family:system-ui;max-width:34rem;margin:4rem auto;padding:0 1rem">';
        echo '<h1>' . esc_html__('Authorization error', 'ai2web') . '</h1><p>' . esc_html($msg) . '</p></body>';
        exit;
    }

    private static function render_consent(string $client_id, string $redirect_uri, string $scope, string $state, string $challenge): void
    {
        $user = wp_get_current_user();
        status_header(200);
        header('content-type: text/html; charset=utf-8');
        // Prevent clickjacking of the Approve button, and stop the code_challenge/params leaking via Referer.
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');
        nocache_headers();
        echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__('Authorize access', 'ai2web') . '</title>';
        echo '<body style="font-family:system-ui;max-width:34rem;margin:4rem auto;padding:0 1rem;line-height:1.6">';
        echo '<h1>' . esc_html(sprintf(/* translators: %s: site name */ __('Authorize access to %s', 'ai2web'), get_bloginfo('name'))) . '</h1>';
        echo '<p>' . esc_html(sprintf(
            /* translators: 1: client id, 2: scope, 3: user display name */
            __('The application "%1$s" wants permission to act on your behalf (%2$s) as %3$s.', 'ai2web'),
            $client_id,
            $scope,
            $user->display_name
        )) . '</p>';
        echo '<p style="color:#666;font-size:.9em">' . esc_html__('It will be redirected to:', 'ai2web') . ' <code>' . esc_html($redirect_uri) . '</code></p>';
        echo '<form method="post" action="' . esc_url(home_url('/ai2w/oauth/authorize')) . '">';
        wp_nonce_field('ai2web_oauth_consent', '_ai2web_nonce');
        $fields = [
            'client_id' => $client_id, 'redirect_uri' => $redirect_uri, 'response_type' => 'code',
            'scope' => $scope, 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
        ];
        foreach ($fields as $k => $v) {
            echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        echo '<button type="submit" name="ai2web_consent" value="approve" style="padding:.6rem 1.2rem;margin-right:.5rem;cursor:pointer">' . esc_html__('Approve', 'ai2web') . '</button>';
        echo '<button type="submit" name="ai2web_consent" value="deny" style="padding:.6rem 1.2rem;cursor:pointer">' . esc_html__('Deny', 'ai2web') . '</button>';
        echo '</form></body>';
        exit;
    }

    /** @return array{status:int,body:mixed} OAuth2 error response. */
    private static function err(string $error, string $desc): array
    {
        return ['status' => 400, 'body' => ['error' => $error, 'error_description' => $desc]];
    }
}
