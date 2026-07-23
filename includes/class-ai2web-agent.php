<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agent service (/ai2w/agent): a natural-language endpoint answered by WordPress 7.0's built-in
 * AI Client, using whatever provider the site owner connected in Settings -> Connectors. This
 * plugin never handles an AI key. It answers questions grounded in the site's AI2Web capabilities;
 * for concrete actions (stock, orders, checkout, refunds) it points the caller at the structured
 * actions, which keep their ownership + approval guarantees.
 */
final class Ai2Web_Agent
{
    private const RATE_KEY = 'ai2web_agent_rl_';
    private const RATE_MAX = 10;      // queries per window, per IP
    private const RATE_WINDOW = 600;  // 10 minutes

    public static function available(): bool
    {
        return function_exists('wp_ai_client_prompt') && Ai2Web_Settings::get('agent_service', true);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{status:int,body:mixed}
     */
    public static function handle(array $input): array
    {
        if (!self::available()) {
            return self::err(404, 'unsupported_capability', 'The agent service is not enabled.');
        }
        $query = '';
        foreach (['query', 'message', 'q'] as $k) {
            if (isset($input[$k]) && is_string($input[$k]) && trim($input[$k]) !== '') {
                $query = sanitize_textarea_field($input[$k]);
                break;
            }
        }
        if ($query === '') {
            return self::err(400, 'invalid_request', 'A query is required.');
        }
        if (mb_strlen($query) > 2000) {
            return self::err(400, 'invalid_request', 'Query is too long.');
        }
        if (!self::rate_ok()) {
            return self::err(429, 'rate_limited', 'Too many requests. Try again later.');
        }
        // The AI Client (wp_ai_client_prompt) ships with WordPress 7.0+; on older cores the agent
        // service is simply unavailable. Resolved through a guarded dynamic reference so it is only
        // ever invoked where it is defined (and never assumed to exist on the declared minimum WP).
        $ai_prompt = 'wp_ai_client_prompt';
        if (!function_exists($ai_prompt)) {
            return self::err(503, 'agent_unavailable', 'The site agent is not available right now.');
        }

        $system = self::system_instruction();

        try {
            $builder = $ai_prompt($query);
            if (is_object($builder) && method_exists($builder, 'usingSystemInstruction')) {
                $result = $builder->usingSystemInstruction($system)->generateText();
            } else {
                $result = $ai_prompt($system . "\n\nVisitor question: " . $query)->generateText();
            }
        } catch (\Throwable $e) {
            self::debug('exception: ' . $e->getMessage());
            return self::err(503, 'agent_unavailable', 'The site agent is not available right now.');
        }

        if (is_wp_error($result)) {
            self::debug('wp_error(' . $result->get_error_code() . '): ' . $result->get_error_message());
            return self::err(503, 'agent_unavailable', 'The site agent is not available right now.');
        }

        return ['status' => 200, 'body' => [
            'answer' => is_string($result) ? $result : (is_scalar($result) ? (string) $result : wp_json_encode($result)),
            'source' => get_bloginfo('name'),
        ]];
    }

    private static function system_instruction(): string
    {
        $manifest = Ai2Web_Manifest::build();
        $site = is_array($manifest['site'] ?? null) ? $manifest['site'] : [];
        $caps = array_keys(array_filter(
            is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [],
            static fn($v) => $v === true || (is_array($v) && !empty($v['enabled']))
        ));
        $actions = array_map(static fn($a) => (string) ($a['name'] ?? ''), is_array($manifest['actions'] ?? null) ? $manifest['actions'] : []);

        return sprintf(
            'You are the assistant for %s%s. It offers: %s. Structured agent actions available: %s. '
            . "Answer the visitor's question helpfully and concisely, only about this site. Do not invent products, "
            . 'prices, stock, or order details you were not given; for those, tell the visitor the site exposes '
            . 'structured AI2Web actions (for example check_stock, track_order, start_checkout) that return verified '
            . 'data. Never claim to have completed a purchase, refund, or order change.',
            (string) ($site['name'] ?? 'this site'),
            !empty($site['description']) ? ' (' . $site['description'] . ')' : '',
            $caps ? implode(', ', $caps) : 'content',
            $actions ? implode(', ', $actions) : 'none'
        );
    }

    private static function rate_ok(): bool
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = self::RATE_KEY . md5($ip);
        $count = (int) get_transient($key);
        if ($count >= self::RATE_MAX) {
            return false;
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    private static function debug(string $msg): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic, gated behind WP_DEBUG only.
            error_log('AI2Web agent service: ' . $msg);
        }
    }

    /** @return array{status:int,body:mixed} */
    private static function err(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => ['code' => $code, 'message' => $message, 'retryable' => $status === 429 || $status === 503]]];
    }
}
