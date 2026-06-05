<?php
/**
 * Plugin Name: Tumblr Auto Reposter
 * Description: Automatically reposts WordPress article images to Tumblr with randomized selection, daily limits, image cooldowns, and OAuth connection.
 * Version: 1.0.0
 * Author: Attila Kis
 * Text Domain: tumblr-auto-reposter
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TAR_VERSION', '1.0.0');
define('TAR_PLUGIN_FILE', __FILE__);
define('TAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TAR_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once TAR_PLUGIN_DIR . 'includes/class-tumblr-auto-reposter.php';

register_activation_hook(__FILE__, array('Tumblr_Auto_Reposter', 'activate'));
register_deactivation_hook(__FILE__, array('Tumblr_Auto_Reposter', 'deactivate'));
register_uninstall_hook(__FILE__, array('Tumblr_Auto_Reposter', 'uninstall'));

Tumblr_Auto_Reposter::init();
