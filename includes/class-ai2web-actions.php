<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central action registry + dispatcher. Merges commerce (WooCommerce) and form actions
 * so the REST router, the manifest and the MCP endpoint all see one list and one runner.
 */
final class Ai2Web_Actions
{
    /** @return array<int,array<string,mixed>> Declared action definitions for the manifest / MCP tools. */
    public static function declared(): array
    {
        return array_merge(Ai2Web_Commerce::declared_actions(), Ai2Web_Forms::declared_actions());
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_map(static fn(array $a): string => (string) $a['name'], self::declared());
    }

    public static function any(): bool
    {
        return self::declared() !== [];
    }

    /**
     * Run an action by name. Returns a transport-agnostic ['status'=>int,'body'=>mixed].
     *
     * @param array<string,mixed> $input
     * @return array{status:int,body:mixed}
     */
    public static function run(string $name, array $input): array
    {
        $name = strtolower($name);
        if (in_array($name, Ai2Web_Commerce::action_names(), true)) {
            return Ai2Web_Commerce::run($name, $input);
        }
        if (in_array($name, Ai2Web_Forms::action_names(), true)) {
            return Ai2Web_Forms::run($name, $input);
        }
        return ['status' => 404, 'body' => ['error' => ['code' => 'unsupported_capability', 'message' => "Unknown action '$name'.", 'retryable' => false]]];
    }
}
