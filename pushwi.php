<?php

/**
 * Plugin Name:       Pushwi
 * Plugin URI:        https://pushwi.com
 * Description:       Pushwi web push: installs the widget and sends posts as push notifications from the editor.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Butiá Labs
 * Author URI:        https://pushwi.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pushwi
 * Domain Path:       /languages
 */

if (! defined('ABSPATH')) {
    exit;
}

define('PUSHWI_PLUGIN_VERSION', '1.0.0');
define('PUSHWI_PLUGIN_FILE', __FILE__);
define('PUSHWI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PUSHWI_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once PUSHWI_PLUGIN_DIR.'includes/class-settings.php';
require_once PUSHWI_PLUGIN_DIR.'includes/class-snippet.php';
require_once PUSHWI_PLUGIN_DIR.'includes/class-service-worker.php';
require_once PUSHWI_PLUGIN_DIR.'includes/class-api.php';
require_once PUSHWI_PLUGIN_DIR.'includes/class-segments.php';
require_once PUSHWI_PLUGIN_DIR.'includes/class-campaign.php';
require_once PUSHWI_PLUGIN_DIR.'includes/class-metabox.php';

// Rewrite rules only apply after a flush.
function pushwi_activate(): void
{
    Pushwi_Service_Worker::register_rewrite_rule();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'pushwi_activate');

// `init` already re-added the rule in this request; remove it before flushing.
function pushwi_deactivate(): void
{
    Pushwi_Service_Worker::unregister_rewrite_rule();
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'pushwi_deactivate');

function pushwi_load_textdomain(): void
{
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- bundled translations.
    load_plugin_textdomain('pushwi', false, dirname(plugin_basename(__FILE__)).'/languages');
}
add_action('init', 'pushwi_load_textdomain');

add_action('init', ['Pushwi_Service_Worker', 'register_rewrite_rule']);
add_action('parse_request', ['Pushwi_Service_Worker', 'maybe_serve']);

add_action('wp_enqueue_scripts', ['Pushwi_Snippet', 'enqueue']);
add_filter('wp_script_attributes', ['Pushwi_Snippet', 'filter_script_attributes']);

add_action('admin_menu', ['Pushwi_Settings', 'register_menu']);
add_action('admin_init', ['Pushwi_Settings', 'register_settings']);
add_action('admin_post_pushwi_test_connection', ['Pushwi_Settings', 'handle_test_connection']);
add_filter('plugin_action_links_'.plugin_basename(__FILE__), ['Pushwi_Settings', 'action_links']);

Pushwi_Metabox::register_hooks();
