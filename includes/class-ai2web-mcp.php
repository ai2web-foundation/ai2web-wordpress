<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal MCP (Model Context Protocol) server over Streamable HTTP at /ai2w/mcp.
 *
 * Stateless JSON-RPC: initialize, notifications/initialized, tools/list, tools/call, ping.
 * Every declared AI2Web action becomes an MCP tool, so adding this endpoint to Claude or
 * ChatGPT lets an agent call the store's tools directly. Tool calls route through the same
 * Ai2Web_Actions runner as the REST endpoints, so the same ownership and approval guards apply.
 *
 * Responses use SSE framing (event: message / data: {json}), which the common MCP clients accept.
 */
final class Ai2Web_MCP
{
    public static function enabled(): bool
    {
        return (bool) Ai2Web_Settings::get('mcp_enabled', true);
    }

    /**
     * Handle a request to /ai2w/mcp. Emits the response and exits.
     *
     * @param mixed $body Parsed JSON body (or null).
     */
    public static function handle(string $method, mixed $body): void
    {
        self::cors_headers();

        if ($method === 'OPTIONS') {
            status_header(204);
            exit;
        }
        if ($method === 'GET') {
            // Some clients probe with GET; there is no server-initiated stream here.
            status_header(405);
            header('content-type: application/json; charset=utf-8');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body (wp_json_encode), not HTML; escaping would corrupt it.
            echo wp_json_encode(['error' => ['code' => 'method_not_allowed', 'message' => 'Use POST for MCP.']]);
            exit;
        }
        if ($method !== 'POST') {
            status_header(405);
            exit;
        }

        $req = is_array($body) ? $body : [];
        $id = $req['id'] ?? null;
        $rpc = isset($req['method']) ? (string) $req['method'] : '';

        // Notifications (no id) get a bare 202.
        if ($id === null && strncmp($rpc, 'notifications/', 14) === 0) {
            status_header(202);
            exit;
        }

        switch ($rpc) {
            case 'initialize':
                self::result($id, [
                    'protocolVersion' => '2024-11-05',
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'ai2w:' . get_bloginfo('name'), 'version' => AI2WEB_VERSION],
                ]);
                break;

            case 'ping':
                self::result($id, new stdClass());
                break;

            case 'tools/list':
                self::result($id, ['tools' => self::tools()]);
                break;

            case 'tools/call':
                self::tools_call($id, $req['params'] ?? []);
                break;

            default:
                self::error($id, -32601, "Method not found: $rpc");
        }
        exit;
    }

    /** @return array<int,array<string,mixed>> */
    private static function tools(): array
    {
        $tools = [];
        foreach (Ai2Web_Actions::declared() as $a) {
            $schema = is_array($a['input_schema'] ?? null) ? $a['input_schema'] : ['type' => 'object', 'properties' => new stdClass()];
            // Approval-gated tools accept an explicit confirm flag.
            if (!empty($a['requires_user_approval']) && isset($schema['properties']) && is_array($schema['properties'])) {
                $schema['properties']['confirm'] = ['type' => 'boolean', 'description' => 'Set true to approve and execute; omit for a preview.'];
            }
            $tools[] = [
                'name' => (string) $a['name'],
                'description' => (string) ($a['description'] ?? ''),
                'inputSchema' => $schema,
            ];
        }
        return array_merge($tools, self::acp_tools());
    }

    /**
     * ACP checkout tools (spec mapping: the 5 checkout_session RPCs), so an MCP client can run a
     * full agentic checkout. Present only when ACP checkout is enabled.
     * @return array<int,array<string,mixed>>
     */
    private static function acp_tools(): array
    {
        if (!Ai2Web_ACP::enabled()) {
            return [];
        }
        $sid = ['checkout_session_id' => ['type' => 'string', 'description' => 'The checkout session id.']];
        $items = ['line_items' => ['type' => 'array', 'description' => 'Line items: each { id (product id or SKU), quantity }.', 'items' => ['type' => 'object']]];
        $buyer = ['buyer' => ['type' => 'object', 'description' => 'Buyer { first_name, last_name, email }.']];
        $fd = ['fulfillment_details' => ['type' => 'object', 'description' => 'Shipping details { name, address { line_one, city, state, country, postal_code } }.']];
        $disc = ['discounts' => ['type' => 'object', 'description' => 'Discounts { codes: string[] } (coupon codes).']];
        return [
            [
                'name' => 'create_checkout_session',
                'description' => 'Start an agentic checkout: assemble a cart into a checkout session and get live pricing, shipping options and totals.',
                'inputSchema' => ['type' => 'object', 'properties' => $items + $buyer + $fd + $disc + ['currency' => ['type' => 'string']], 'required' => ['line_items']],
            ],
            [
                'name' => 'get_checkout_session',
                'description' => 'Retrieve the current state of a checkout session.',
                'inputSchema' => ['type' => 'object', 'properties' => $sid, 'required' => ['checkout_session_id']],
            ],
            [
                'name' => 'update_checkout_session',
                'description' => 'Update a checkout session: change items, set the shipping address, choose a delivery option, or apply a coupon.',
                'inputSchema' => ['type' => 'object', 'properties' => $sid + $items + $fd + $disc + ['selected_fulfillment_options' => ['type' => 'array', 'items' => ['type' => 'object']]], 'required' => ['checkout_session_id']],
            ],
            [
                'name' => 'complete_checkout_session',
                'description' => 'Complete a checkout session. With a delegated payment token the order is paid; otherwise a pending order and its secure pay link are returned for the customer to pay in the browser.',
                'inputSchema' => ['type' => 'object', 'properties' => $sid + $buyer + ['payment_data' => ['type' => 'object', 'description' => 'ACP payment_data with the delegated payment token.']], 'required' => ['checkout_session_id', 'buyer', 'payment_data']],
            ],
            [
                'name' => 'cancel_checkout_session',
                'description' => 'Cancel a checkout session.',
                'inputSchema' => ['type' => 'object', 'properties' => $sid, 'required' => ['checkout_session_id']],
            ],
        ];
    }

    /** @param mixed $id @param mixed $params */
    private static function tools_call(mixed $id, mixed $params): void
    {
        $params = is_array($params) ? $params : [];
        $name = isset($params['name']) ? (string) $params['name'] : '';
        $args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

        // ACP checkout tools route through the ACP session dispatcher.
        if (self::is_acp_tool($name)) {
            $r = self::run_acp_tool($name, $args);
            $text = wp_json_encode($r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            self::result($id, [
                'content' => [['type' => 'text', 'text' => $text === false ? '{}' : $text]],
                'isError' => ($r['status'] ?? 200) >= 400,
            ]);
            return;
        }

        if ($name === '' || !in_array($name, Ai2Web_Actions::names(), true)) {
            self::error($id, -32602, "Unknown tool: $name");
            return;
        }

        $r = Ai2Web_Actions::run($name, $args);
        $text = wp_json_encode($r['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        self::result($id, [
            'content' => [['type' => 'text', 'text' => $text === false ? '{}' : $text]],
            'isError' => ($r['status'] ?? 200) >= 400,
        ]);
    }

    private static function is_acp_tool(string $name): bool
    {
        return in_array($name, [
            'create_checkout_session', 'get_checkout_session', 'update_checkout_session',
            'complete_checkout_session', 'cancel_checkout_session',
        ], true);
    }

    /**
     * Map an ACP checkout tool call onto the ACP session dispatcher.
     * @param array<string,mixed> $args
     * @return array{status:int,body:mixed}
     */
    private static function run_acp_tool(string $name, array $args): array
    {
        if (!Ai2Web_ACP::enabled()) {
            return ['status' => 404, 'body' => ['error' => 'ACP checkout is not enabled.']];
        }
        $id = isset($args['checkout_session_id']) ? (string) $args['checkout_session_id'] : null;
        unset($args['checkout_session_id']);
        switch ($name) {
            case 'create_checkout_session':
                return Ai2Web_ACP::dispatch('POST', null, null, $args);
            case 'get_checkout_session':
                return Ai2Web_ACP::dispatch('GET', $id, null, []);
            case 'update_checkout_session':
                return Ai2Web_ACP::dispatch('POST', $id, null, $args);
            case 'complete_checkout_session':
                return Ai2Web_ACP::dispatch('POST', $id, 'complete', $args);
            case 'cancel_checkout_session':
                return Ai2Web_ACP::dispatch('POST', $id, 'cancel', []);
        }
        return ['status' => 404, 'body' => ['error' => "Unknown tool: $name"]];
    }

    private static function cors_headers(): void
    {
        header('access-control-allow-origin: *');
        header('access-control-allow-methods: POST, OPTIONS');
        header('access-control-allow-headers: content-type, authorization, mcp-session-id, mcp-protocol-version');
    }

    /** Emit a JSON-RPC success result as an SSE message. */
    private static function result(mixed $id, mixed $result): void
    {
        self::send(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    private static function error(mixed $id, int $code, string $message): void
    {
        self::send(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /** @param array<string,mixed> $payload */
    private static function send(array $payload): void
    {
        status_header(200);
        header('content-type: text/event-stream; charset=utf-8');
        header('cache-control: no-cache');
        $json = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        echo "event: message\n";
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-RPC payload (wp_json_encode) in an SSE data frame, not HTML.
        echo 'data: ' . ($json === false ? '{}' : $json) . "\n\n";
    }
}
