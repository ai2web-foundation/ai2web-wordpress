<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin options store and defaults. A single option array keeps the wp_options table tidy.
 */
final class Ai2Web_Settings
{
    public const OPTION = 'ai2web_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => true,           // master switch for the /ai2w endpoints
            'support_email' => '',       // public support address (opt-in; empty = not published)
            'commerce_actions' => true,  // product search, stock, order tracking (WooCommerce)
            'returns_refunds' => true,   // return/refund request actions (logged for the merchant)
            'mcp_enabled' => true,       // expose the /ai2w/mcp endpoint for AI connectors
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $saved = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $fallback;
    }

    /**
     * Sanitize the settings form on save.
     * @param mixed $input
     * @return array<string,mixed>
     */
    public static function sanitize(mixed $input): array
    {
        $input = is_array($input) ? $input : [];
        return [
            'enabled' => !empty($input['enabled']),
            'support_email' => isset($input['support_email']) ? sanitize_email((string) $input['support_email']) : '',
            'commerce_actions' => !empty($input['commerce_actions']),
            'returns_refunds' => !empty($input['returns_refunds']),
            'mcp_enabled' => !empty($input['mcp_enabled']),
        ];
    }
}
