<?php
/**
 * Plugin Name:       AI2Web
 * Plugin URI:        https://ai2web.dev
 * Description:       Make your website AI-native. Exposes an AI2Web (ai2w) capability manifest, REST and MCP endpoints so AI agents can discover, understand and act on your site. Auto-integrates WooCommerce (product search, order tracking, return/refund requests) and popular form plugins.
 * Version:           0.4.0
 * Requires PHP:      8.0
 * Requires at least: 6.0
 * Author:            AI2Web Foundation
 * License:           MIT
 * Text Domain:       ai2web
 *
 * Describe your website once. AI2Web makes it understandable to every AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('AI2WEB_VERSION', '0.4.0');
define('AI2WEB_DIR', plugin_dir_path(__FILE__));

require_once AI2WEB_DIR . 'includes/class-ai2web-settings.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-manifest.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-export.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-woocommerce.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-commerce.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-acp.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-ap2.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-stripe.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-forms.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-actions.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-mcp.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-oauth.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-agent.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-abilities.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-analytics.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-plugin.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-admin.php';

// Publish the configured support email in the manifest (opt-in via settings).
add_filter('ai2web_support_contact', static function ($value) {
    $email = (string) Ai2Web_Settings::get('support_email', '');
    return $email !== '' ? $email : $value;
});

add_action('plugins_loaded', static function (): void {
    (new Ai2Web_Plugin())->boot();
    Ai2Web_Abilities::boot();
    Ai2Web_Stripe::boot();
    if (is_admin()) {
        Ai2Web_Admin::boot();
    }
});

register_activation_hook(__FILE__, static function (): void {
    // Ensure our rewrite tag is present, then flush once.
    Ai2Web_Plugin::add_rewrite_rules();
    flush_rewrite_rules();
    Ai2Web_Analytics::install();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
