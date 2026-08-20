<?php
/**
 * Plugin Name: Reddit To WordPress
 * Description: Fetches Reddit posts from configured subreddits and creates WordPress posts using randomized templates.
 * Version: 1.0.0
 * Author: Attila Kis
 * Text Domain: reddit-to-wordpress
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RTW_VERSION', '1.0.0');
define('RTW_PLUGIN_FILE', __FILE__);
define('RTW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RTW_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RTW_PLUGIN_DIR . 'includes/class-reddit-to-wordpress.php';

register_activation_hook(__FILE__, array('Reddit_To_WordPress', 'activate'));
register_deactivation_hook(__FILE__, array('Reddit_To_WordPress', 'deactivate'));
register_uninstall_hook(__FILE__, array('Reddit_To_WordPress', 'uninstall'));

Reddit_To_WordPress::init();
