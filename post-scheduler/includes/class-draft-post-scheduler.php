<?php

if (!defined('ABSPATH')) {
    exit;
}

class Draft_Post_Scheduler
{
    const OPTION_SETTINGS = 'dps_settings';
    const OPTION_LOGS = 'dps_logs';
    const OPTION_DAILY_COUNT = 'dps_daily_count';
    const CRON_HOOK = 'dps_publish_draft_posts';
    const CRON_SCHEDULE = 'dps_custom_interval';
    const LOG_LIMIT = 200;

    public static function init()
    {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'));
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
    }

    public static function activate()
    {
        if (false === get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, self::default_settings());
        }

        self::schedule_event();
        self::log('Plugin activated.');
    }

    public static function deactivate()
    {
        self::clear_scheduled_event();
        self::log('Plugin deactivated. Scheduled event cleared.');
    }

    public static function default_settings()
    {
        return array(
            'enabled' => 0,
            'interval_hours' => 3,
            'min_posts' => 3,
            'max_posts' => 5,
            'daily_limit' => 10,
            'dry_run' => 1,
        );
    }

    public static function get_settings()
    {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), self::default_settings());

        $settings['enabled'] = empty($settings['enabled']) ? 0 : 1;
        $settings['interval_hours'] = max(1, absint($settings['interval_hours']));
        $settings['min_posts'] = max(1, absint($settings['min_posts']));
        $settings['max_posts'] = max($settings['min_posts'], absint($settings['max_posts']));
        $settings['daily_limit'] = max(1, absint($settings['daily_limit']));
        $settings['dry_run'] = empty($settings['dry_run']) ? 0 : 1;

        return $settings;
    }

    public static function add_cron_schedule($schedules)
    {
        $settings = self::get_settings();
        $interval = max(1, absint($settings['interval_hours'])) * HOUR_IN_SECONDS;

        $schedules[self::CRON_SCHEDULE] = array(
            'interval' => $interval,
            'display' => sprintf(
                /* translators: %d: number of hours */
                __('Every %d hour(s)', 'draft-post-scheduler'),
                max(1, absint($settings['interval_hours']))
            ),
        );

        return $schedules;
    }

    public static function schedule_event()
    {
        $settings = self::get_settings();
        self::clear_scheduled_event();

        if (empty($settings['enabled'])) {
            return;
        }

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    public static function clear_scheduled_event()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        while ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
            $timestamp = wp_next_scheduled(self::CRON_HOOK);
        }
    }

    public static function add_admin_menu()
    {
        add_options_page(
            __('Draft Post Scheduler', 'draft-post-scheduler'),
            __('Draft Scheduler', 'draft-post-scheduler'),
            'manage_options',
            'draft-post-scheduler',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function handle_admin_actions()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_POST['dps_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['dps_action']));

        if ('save_settings' === $action) {
            check_admin_referer('dps_save_settings');
            self::save_settings();
            wp_safe_redirect(add_query_arg('dps_message', 'settings_saved', self::admin_url()));
            exit;
        }

        if ('run_now' === $action) {
            check_admin_referer('dps_run_now');
            self::run(true);
            wp_safe_redirect(add_query_arg('dps_message', 'run_complete', self::admin_url()));
            exit;
        }

        if ('clear_logs' === $action) {
            check_admin_referer('dps_clear_logs');
            update_option(self::OPTION_LOGS, array(), false);
            wp_safe_redirect(add_query_arg('dps_message', 'logs_cleared', self::admin_url()));
            exit;
        }
    }

    private static function save_settings()
    {
        $min_posts = isset($_POST['min_posts']) ? absint($_POST['min_posts']) : 1;
        $max_posts = isset($_POST['max_posts']) ? absint($_POST['max_posts']) : $min_posts;

        if ($min_posts < 1) {
            $min_posts = 1;
        }

        if ($max_posts < $min_posts) {
            $max_posts = $min_posts;
        }

        $settings = array(
            'enabled' => empty($_POST['enabled']) ? 0 : 1,
            'interval_hours' => max(1, isset($_POST['interval_hours']) ? absint($_POST['interval_hours']) : 3),
            'min_posts' => $min_posts,
            'max_posts' => $max_posts,
            'daily_limit' => max(1, isset($_POST['daily_limit']) ? absint($_POST['daily_limit']) : 10),
            'dry_run' => empty($_POST['dry_run']) ? 0 : 1,
        );

        update_option(self::OPTION_SETTINGS, $settings, false);
        self::schedule_event();
        self::log('Settings saved. Cron schedule refreshed.');
    }

    public static function run($manual = false)
    {
        $settings = self::get_settings();

        if (!$manual && empty($settings['enabled'])) {
            self::log('Cron skipped because scheduler is disabled.');
            return;
        }

        $daily_state = self::get_daily_state();
        $remaining = max(0, absint($settings['daily_limit']) - absint($daily_state['count']));

        if (0 === $remaining) {
            self::log('Run skipped because the daily publish limit has already been reached.');
            return;
        }

        $target_count = wp_rand(absint($settings['min_posts']), absint($settings['max_posts']));
        $publish_count = min($target_count, $remaining);
        $posts = self::get_draft_posts($publish_count);

        if (empty($posts)) {
            self::log('Run completed. No draft posts found.');
            return;
        }

        $published = 0;
        $dry_run = !empty($settings['dry_run']);

        foreach ($posts as $post) {
            if ($dry_run) {
                self::log(sprintf('Dry run: would publish post #%d "%s".', $post->ID, get_the_title($post)));
                continue;
            }

            $result = wp_update_post(
                array(
                    'ID' => $post->ID,
                    'post_status' => 'publish',
                    'post_date' => current_time('mysql'),
                    'post_date_gmt' => current_time('mysql', true),
                ),
                true
            );

            if (is_wp_error($result)) {
                self::log(sprintf('Failed to publish post #%d: %s', $post->ID, $result->get_error_message()));
                continue;
            }

            $published++;
            self::log(sprintf('Published post #%d "%s".', $post->ID, get_the_title($post)));
        }

        if (!$dry_run && $published > 0) {
            $daily_state['count'] += $published;
            update_option(self::OPTION_DAILY_COUNT, $daily_state, false);
        }

        self::log(sprintf(
            'Run finished. Requested: %d, selected: %d, published: %d, dry run: %s, daily count: %d/%d.',
            $target_count,
            count($posts),
            $published,
            $dry_run ? 'yes' : 'no',
            absint($daily_state['count']),
            absint($settings['daily_limit'])
        ));
    }

    private static function get_draft_posts($limit)
    {
        return get_posts(
            array(
                'post_type' => 'post',
                'post_status' => 'draft',
                'posts_per_page' => max(1, absint($limit)),
                'orderby' => 'date',
                'order' => 'ASC',
                'fields' => 'all',
                'no_found_rows' => true,
                'suppress_filters' => false,
            )
        );
    }

    private static function get_daily_state()
    {
        $today = current_time('Y-m-d');
        $state = get_option(self::OPTION_DAILY_COUNT, array());

        if (!is_array($state) || empty($state['date']) || $today !== $state['date']) {
            $state = array(
                'date' => $today,
                'count' => 0,
            );
            update_option(self::OPTION_DAILY_COUNT, $state, false);
        }

        $state['count'] = absint($state['count']);
        return $state;
    }

    private static function log($message)
    {
        $logs = get_option(self::OPTION_LOGS, array());

        if (!is_array($logs)) {
            $logs = array();
        }

        array_unshift($logs, array(
            'time' => current_time('mysql'),
            'message' => sanitize_text_field($message),
        ));

        $logs = array_slice($logs, 0, self::LOG_LIMIT);
        update_option(self::OPTION_LOGS, $logs, false);
    }

    private static function get_logs()
    {
        $logs = get_option(self::OPTION_LOGS, array());
        return is_array($logs) ? $logs : array();
    }

    private static function admin_url()
    {
        return admin_url('options-general.php?page=draft-post-scheduler');
    }

    public static function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $logs = self::get_logs();
        $daily_state = self::get_daily_state();
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Draft Post Scheduler', 'draft-post-scheduler'); ?></h1>

            <?php self::render_notice(); ?>

            <form method="post" action="">
                <?php wp_nonce_field('dps_save_settings'); ?>
                <input type="hidden" name="dps_action" value="save_settings">

                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enabled', 'draft-post-scheduler'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'], 1); ?>>
                                <?php esc_html_e('Run scheduler through WP-Cron', 'draft-post-scheduler'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="interval_hours"><?php esc_html_e('Run interval', 'draft-post-scheduler'); ?></label></th>
                        <td>
                            <input type="number" min="1" step="1" id="interval_hours" name="interval_hours" value="<?php echo esc_attr($settings['interval_hours']); ?>" class="small-text">
                            <?php esc_html_e('hour(s)', 'draft-post-scheduler'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Posts per run', 'draft-post-scheduler'); ?></th>
                        <td>
                            <label for="min_posts"><?php esc_html_e('Min', 'draft-post-scheduler'); ?></label>
                            <input type="number" min="1" step="1" id="min_posts" name="min_posts" value="<?php echo esc_attr($settings['min_posts']); ?>" class="small-text">
                            <label for="max_posts"><?php esc_html_e('Max', 'draft-post-scheduler'); ?></label>
                            <input type="number" min="1" step="1" id="max_posts" name="max_posts" value="<?php echo esc_attr($settings['max_posts']); ?>" class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="daily_limit"><?php esc_html_e('Daily publish limit', 'draft-post-scheduler'); ?></label></th>
                        <td>
                            <input type="number" min="1" step="1" id="daily_limit" name="daily_limit" value="<?php echo esc_attr($settings['daily_limit']); ?>" class="small-text">
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('Published today by this plugin: %1$d / %2$d', 'draft-post-scheduler'),
                                    absint($daily_state['count']),
                                    absint($settings['daily_limit'])
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dry run', 'draft-post-scheduler'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run'], 1); ?>>
                                <?php esc_html_e('Only log what would be published', 'draft-post-scheduler'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Next cron run', 'draft-post-scheduler'); ?></th>
                        <td>
                            <?php
                            if ($next_run) {
                                echo esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run));
                            } else {
                                esc_html_e('Not scheduled', 'draft-post-scheduler');
                            }
                            ?>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save settings', 'draft-post-scheduler')); ?>
            </form>

            <hr>

            <form method="post" action="" style="display:inline-block;margin-right:8px;">
                <?php wp_nonce_field('dps_run_now'); ?>
                <input type="hidden" name="dps_action" value="run_now">
                <?php submit_button(__('Run now', 'draft-post-scheduler'), 'secondary', 'submit', false); ?>
            </form>

            <form method="post" action="" style="display:inline-block;">
                <?php wp_nonce_field('dps_clear_logs'); ?>
                <input type="hidden" name="dps_action" value="clear_logs">
                <?php submit_button(__('Clear logs', 'draft-post-scheduler'), 'delete', 'submit', false); ?>
            </form>

            <h2><?php esc_html_e('Logs', 'draft-post-scheduler'); ?></h2>
            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No logs yet.', 'draft-post-scheduler'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'draft-post-scheduler'); ?></th>
                        <th><?php esc_html_e('Message', 'draft-post-scheduler'); ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($logs as $log) : ?>
                        <tr>
                            <td><?php echo esc_html(isset($log['time']) ? $log['time'] : ''); ?></td>
                            <td><?php echo esc_html(isset($log['message']) ? $log['message'] : ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_notice()
    {
        if (empty($_GET['dps_message'])) {
            return;
        }

        $message_key = sanitize_key(wp_unslash($_GET['dps_message']));
        $messages = array(
            'settings_saved' => __('Settings saved.', 'draft-post-scheduler'),
            'run_complete' => __('Manual run completed. Check logs for details.', 'draft-post-scheduler'),
            'logs_cleared' => __('Logs cleared.', 'draft-post-scheduler'),
        );

        if (empty($messages[$message_key])) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            esc_html($messages[$message_key])
        );
    }
}
