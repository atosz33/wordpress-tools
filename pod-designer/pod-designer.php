<?php
/**
 * Plugin Name: POD Designer
 * Description: T-shirt and mug designer with a 2D print area editor, optional 3D mug preview, admin managed colors and mockups, WooCommerce support and an admin view of exactly what the customer designed.
 * Version: 1.0.0
 * Author: Attila Kis
 * Text Domain: pod-designer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('POD_VERSION', '1.0.0');
define('POD_PLUGIN_FILE', __FILE__);
define('POD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('POD_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once POD_PLUGIN_DIR . 'includes/class-pod-designer.php';
require_once POD_PLUGIN_DIR . 'includes/class-pod-designer-settings.php';
require_once POD_PLUGIN_DIR . 'includes/class-pod-designer-designs.php';
require_once POD_PLUGIN_DIR . 'includes/class-pod-designer-woocommerce.php';

register_activation_hook(__FILE__, array('POD_Designer', 'activate'));
register_deactivation_hook(__FILE__, array('POD_Designer', 'deactivate'));
register_uninstall_hook(__FILE__, array('POD_Designer', 'uninstall'));

POD_Designer::init();
