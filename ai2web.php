<?php
/**
 * Plugin Name:       AI2Web
 * Plugin URI:        https://ai2web.dev
 * Description:       Make your website AI-native. Exposes an AI2Web (ai2w) capability manifest and endpoints so AI agents can discover, understand and act on your site. Auto-integrates WooCommerce and popular form plugins.
 * Version:           0.1.0
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

define('AI2WEB_VERSION', '0.1.0');
define('AI2WEB_DIR', plugin_dir_path(__FILE__));

require_once AI2WEB_DIR . 'includes/class-ai2web-manifest.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-woocommerce.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-forms.php';
require_once AI2WEB_DIR . 'includes/class-ai2web-plugin.php';

add_action('plugins_loaded', static function (): void {
    (new Ai2Web_Plugin())->boot();
});

register_activation_hook(__FILE__, static function (): void {
    // Ensure our rewrite tag is present, then flush once.
    Ai2Web_Plugin::add_rewrite_rules();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
