<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Detects popular form plugins and exposes them as AI2Web actions.
 * v0.1 declares a generic contact action; per-plugin submission wiring is incremental.
 */
final class Ai2Web_Forms
{
    /** @return array<string,bool> Map of detected form providers. */
    public static function detect(): array
    {
        return [
            'contact_form_7' => defined('WPCF7_VERSION'),
            'gravity_forms'  => class_exists('GFForms'),
            'wpforms'        => function_exists('wpforms'),
            'fluent_forms'   => defined('FLUENTFORM_VERSION'),
            'elementor_forms' => did_action('elementor/loaded') > 0,
        ];
    }

    /** @return array<int,array<string,mixed>> Declared actions for the manifest. */
    public static function detect_actions(): array
    {
        $providers = array_keys(array_filter(self::detect()));
        if (empty($providers)) {
            return [];
        }
        return [[
            'name' => 'submit_contact',
            'description' => 'Submit a contact/enquiry form. Detected providers: ' . implode(', ', $providers) . '.',
            'method' => 'POST',
            'endpoint' => '/ai2w/actions/submit-contact',
            'requires_auth' => false,
            'requires_user_approval' => true,
            'risk' => 'medium',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string'],
                    'message' => ['type' => 'string'],
                ],
                'required' => ['email', 'message'],
            ],
        ]];
    }

    /**
     * Handle an action call. High-risk/approval-required actions return a preview (spec §9).
     * @param callable $json
     * @param callable $error
     * @return array{status:int,headers:array<string,string>,body:mixed}
     */
    public static function handle_action(string $name, mixed $body, callable $json, callable $error): array
    {
        if ($name !== 'submit_contact') {
            return $error(404, 'unsupported_capability', "Unknown action '$name'.");
        }
        // Approval-required: return a structured preview rather than executing.
        return $json(200, [
            'preview' => true,
            'action' => 'submit_contact',
            'risk' => 'medium',
            'message' => 'This action requires explicit user approval before submission.',
            'proposed' => is_array($body) ? $body : [],
        ]);
    }
}
