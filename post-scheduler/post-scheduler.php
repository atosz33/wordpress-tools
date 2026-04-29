<?php
/**
 * Plugin Name: Draft Post Scheduler
 * Description: Publishes draft posts on a configurable WP-Cron schedule with daily limits, dry run mode, and admin logs.
 * Version: 1.0.0
 * Author: Attila Kis
 * Text Domain: draft-post-scheduler
 */

if (!defined('ABSPATH')) {
    exit;
}

define('DPS_VERSION', '1.0.0');
define('DPS_PLUGIN_FILE', __FILE__);
define('DPS_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once DPS_PLUGIN_DIR . 'includes/class-draft-post-scheduler.php';

register_activation_hook(__FILE__, array('Draft_Post_Scheduler', 'activate'));
register_deactivation_hook(__FILE__, array('Draft_Post_Scheduler', 'deactivate'));

Draft_Post_Scheduler::init();
