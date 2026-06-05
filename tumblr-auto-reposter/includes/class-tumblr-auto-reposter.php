<?php

if (!defined('ABSPATH')) {
    exit;
}

class Tumblr_Auto_Reposter
{
    const OPTION_SETTINGS = 'tar_settings';
    const OPTION_TOKENS = 'tar_oauth_tokens';
    const OPTION_REQUEST_TOKEN = 'tar_request_token';
    const OPTION_LOGS = 'tar_logs';
    const OPTION_DAILY_COUNT = 'tar_daily_count';
    const OPTION_IMAGE_USAGE = 'tar_image_usage';
    const CRON_HOOK = 'tar_publish_tumblr_post';
    const CRON_SCHEDULE = 'tar_custom_interval';
    const LOG_LIMIT = 200;

    const REQUEST_TOKEN_URL = 'https://www.tumblr.com/oauth/request_token';
    const AUTHORIZE_URL = 'https://www.tumblr.com/oauth/authorize';
    const ACCESS_TOKEN_URL = 'https://www.tumblr.com/oauth/access_token';
    const API_BASE_URL = 'https://api.tumblr.com/v2';

    public static function init()
    {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'));
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
        add_action('admin_post_tar_oauth_callback', array(__CLASS__, 'handle_oauth_callback'));
    }

    public static function activate()
    {
        if (false === get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
        }

        self::schedule_event();
        self::log('Plugin activated.');
    }

    public static function deactivate()
    {
        self::clear_scheduled_event();
        self::log('Plugin deactivated. Scheduled event cleared.');
    }

    public static function uninstall()
    {
        delete_option(self::OPTION_SETTINGS);
        delete_option(self::OPTION_TOKENS);
        delete_option(self::OPTION_REQUEST_TOKEN);
        delete_option(self::OPTION_LOGS);
        delete_option(self::OPTION_DAILY_COUNT);
        delete_option(self::OPTION_IMAGE_USAGE);
    }

    public static function default_settings()
    {
        return array(
            'enabled' => 0,
            'consumer_key' => '',
            'consumer_secret' => '',
            'blog_hostname' => '',
            'posts_per_day' => 3,
            'cooldown_days' => 14,
            'post_state' => 'queue',
            'post_statuses' => array('publish'),
            'include_featured_image' => 1,
            'include_attached_images' => 1,
            'include_content_images' => 1,
            'dry_run' => 1,
        );
    }

    public static function get_settings()
    {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), self::default_settings());
        $allowed_statuses = array('publish', 'future', 'private');
        $statuses = isset($settings['post_statuses']) && is_array($settings['post_statuses']) ? $settings['post_statuses'] : array('publish');

        $settings['enabled'] = empty($settings['enabled']) ? 0 : 1;
        $settings['consumer_key'] = sanitize_text_field($settings['consumer_key']);
        $settings['consumer_secret'] = sanitize_text_field($settings['consumer_secret']);
        $settings['blog_hostname'] = sanitize_text_field($settings['blog_hostname']);
        $settings['posts_per_day'] = max(1, absint($settings['posts_per_day']));
        $settings['cooldown_days'] = max(0, absint($settings['cooldown_days']));
        $settings['post_state'] = in_array($settings['post_state'], array('queue', 'published', 'draft', 'private'), true) ? $settings['post_state'] : 'queue';
        $settings['post_statuses'] = array_values(array_intersect($allowed_statuses, array_map('sanitize_key', $statuses)));
        $settings['include_featured_image'] = empty($settings['include_featured_image']) ? 0 : 1;
        $settings['include_attached_images'] = empty($settings['include_attached_images']) ? 0 : 1;
        $settings['include_content_images'] = empty($settings['include_content_images']) ? 0 : 1;
        $settings['dry_run'] = empty($settings['dry_run']) ? 0 : 1;

        if (empty($settings['post_statuses'])) {
            $settings['post_statuses'] = array('publish');
        }

        return $settings;
    }

    public static function callback_url()
    {
        return admin_url('admin-post.php?action=tar_oauth_callback');
    }

    public static function admin_url()
    {
        return admin_url('options-general.php?page=tumblr-auto-reposter');
    }

    public static function add_cron_schedule($schedules)
    {
        $settings = self::get_settings();
        $interval = max(15 * MINUTE_IN_SECONDS, (int) floor(DAY_IN_SECONDS / max(1, absint($settings['posts_per_day']))));

        $schedules[self::CRON_SCHEDULE] = array(
            'interval' => $interval,
            'display' => sprintf(
                /* translators: %d: interval in minutes */
                __('Every %d minute(s)', 'tumblr-auto-reposter'),
                (int) round($interval / MINUTE_IN_SECONDS)
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
            __('Tumblr Auto Reposter', 'tumblr-auto-reposter'),
            __('Tumblr Reposter', 'tumblr-auto-reposter'),
            'manage_options',
            'tumblr-auto-reposter',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function enqueue_admin_assets($hook)
    {
        if ('settings_page_tumblr-auto-reposter' !== $hook) {
            return;
        }

        wp_enqueue_style('tar-admin', TAR_PLUGIN_URL . 'assets/admin.css', array(), TAR_VERSION);
    }

    public static function handle_admin_actions()
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $action = '';
        $is_get_fallback = false;

        if (!empty($_POST['tar_action'])) {
            $action = sanitize_key(wp_unslash($_POST['tar_action']));
        } elseif (!empty($_GET['tar_action']) && in_array(sanitize_key(wp_unslash($_GET['tar_action'])), array('connect', 'disconnect'), true)) {
            $action = sanitize_key(wp_unslash($_GET['tar_action']));
            $is_get_fallback = true;
        }

        if (!$action) {
            return;
        }

        if ('save_settings' === $action) {
            if ($is_get_fallback) {
                return;
            }

            check_admin_referer('tar_save_settings');
            self::save_settings();
            wp_safe_redirect(add_query_arg('tar_message', 'settings_saved', self::admin_url()));
            exit;
        }

        if ('connect' === $action) {
            check_admin_referer('tar_connect');
            if ($is_get_fallback) {
                self::log('Connect action received through GET fallback.');
            }
            self::start_oauth();
        }

        if ('disconnect' === $action) {
            check_admin_referer('tar_disconnect');
            self::disconnect();
        }

        if ('run_now' === $action) {
            if ($is_get_fallback) {
                return;
            }

            check_admin_referer('tar_run_now');
            self::run(true);
            wp_safe_redirect(add_query_arg('tar_message', 'run_complete', self::admin_url()));
            exit;
        }

        if ('clear_logs' === $action) {
            if ($is_get_fallback) {
                return;
            }

            check_admin_referer('tar_clear_logs');
            update_option(self::OPTION_LOGS, array(), false);
            wp_safe_redirect(add_query_arg('tar_message', 'logs_cleared', self::admin_url()));
            exit;
        }

        if ('clear_usage' === $action) {
            if ($is_get_fallback) {
                return;
            }

            check_admin_referer('tar_clear_usage');
            update_option(self::OPTION_IMAGE_USAGE, array(), false);
            self::log('Image cooldown history cleared.');
            wp_safe_redirect(add_query_arg('tar_message', 'usage_cleared', self::admin_url()));
            exit;
        }
    }

    private static function save_settings()
    {
        $post_statuses = isset($_POST['post_statuses']) && is_array($_POST['post_statuses']) ? wp_unslash($_POST['post_statuses']) : array('publish');

        $settings = array(
            'enabled' => empty($_POST['enabled']) ? 0 : 1,
            'consumer_key' => isset($_POST['consumer_key']) ? sanitize_text_field(wp_unslash($_POST['consumer_key'])) : '',
            'consumer_secret' => isset($_POST['consumer_secret']) ? sanitize_text_field(wp_unslash($_POST['consumer_secret'])) : '',
            'blog_hostname' => isset($_POST['blog_hostname']) ? sanitize_text_field(wp_unslash($_POST['blog_hostname'])) : '',
            'posts_per_day' => max(1, isset($_POST['posts_per_day']) ? absint($_POST['posts_per_day']) : 3),
            'cooldown_days' => max(0, isset($_POST['cooldown_days']) ? absint($_POST['cooldown_days']) : 14),
            'post_state' => isset($_POST['post_state']) ? sanitize_key(wp_unslash($_POST['post_state'])) : 'queue',
            'post_statuses' => array_map('sanitize_key', $post_statuses),
            'include_featured_image' => empty($_POST['include_featured_image']) ? 0 : 1,
            'include_attached_images' => empty($_POST['include_attached_images']) ? 0 : 1,
            'include_content_images' => empty($_POST['include_content_images']) ? 0 : 1,
            'dry_run' => empty($_POST['dry_run']) ? 0 : 1,
        );

        update_option(self::OPTION_SETTINGS, self::normalize_settings($settings), false);
        self::schedule_event();
        self::log('Settings saved. Cron schedule refreshed.');
    }

    private static function normalize_settings($settings)
    {
        $defaults = self::default_settings();
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
        $allowed_statuses = array('publish', 'future', 'private');

        $settings['enabled'] = empty($settings['enabled']) ? 0 : 1;
        $settings['consumer_key'] = sanitize_text_field($settings['consumer_key']);
        $settings['consumer_secret'] = sanitize_text_field($settings['consumer_secret']);
        $settings['blog_hostname'] = sanitize_text_field($settings['blog_hostname']);
        $settings['posts_per_day'] = max(1, absint($settings['posts_per_day']));
        $settings['cooldown_days'] = max(0, absint($settings['cooldown_days']));
        $settings['post_state'] = in_array($settings['post_state'], array('queue', 'published', 'draft', 'private'), true) ? $settings['post_state'] : 'queue';
        $settings['post_statuses'] = array_values(array_intersect($allowed_statuses, array_map('sanitize_key', (array) $settings['post_statuses'])));
        $settings['include_featured_image'] = empty($settings['include_featured_image']) ? 0 : 1;
        $settings['include_attached_images'] = empty($settings['include_attached_images']) ? 0 : 1;
        $settings['include_content_images'] = empty($settings['include_content_images']) ? 0 : 1;
        $settings['dry_run'] = empty($settings['dry_run']) ? 0 : 1;

        if (empty($settings['post_statuses'])) {
            $settings['post_statuses'] = array('publish');
        }

        return $settings;
    }

    public static function start_oauth()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to connect Tumblr.', 'tumblr-auto-reposter'));
        }

        $settings = self::get_settings();

        if (empty($settings['consumer_key']) || empty($settings['consumer_secret'])) {
            self::log('Tumblr connect failed because consumer key or secret is missing.');
            wp_safe_redirect(add_query_arg('tar_message', 'credentials_missing', self::admin_url()));
            exit;
        }

        $response = self::oauth_request(
            'POST',
            self::REQUEST_TOKEN_URL,
            array('oauth_callback' => self::callback_url()),
            '',
            ''
        );

        if (is_wp_error($response)) {
            self::log('Tumblr request token failed: ' . $response->get_error_message());
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code < 200 || $response_code >= 300) {
            self::log(sprintf(
                'Tumblr request token failed with HTTP %1$d: %2$s',
                absint($response_code),
                self::shorten_for_log($response_body)
            ));
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        parse_str($response_body, $token_data);

        if (empty($token_data['oauth_token']) || empty($token_data['oauth_token_secret'])) {
            self::log('Tumblr request token failed: invalid response.');
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        update_option(
            self::OPTION_REQUEST_TOKEN,
            array(
                'token' => sanitize_text_field($token_data['oauth_token']),
                'secret' => sanitize_text_field($token_data['oauth_token_secret']),
                'created' => time(),
            ),
            false
        );

        self::log('Redirecting to Tumblr authorization.');
        wp_redirect(add_query_arg('oauth_token', $token_data['oauth_token'], self::AUTHORIZE_URL));
        exit;
    }

    public static function handle_oauth_callback()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to connect Tumblr.', 'tumblr-auto-reposter'));
        }

        $oauth_token = isset($_GET['oauth_token']) ? sanitize_text_field(wp_unslash($_GET['oauth_token'])) : '';
        $oauth_verifier = isset($_GET['oauth_verifier']) ? sanitize_text_field(wp_unslash($_GET['oauth_verifier'])) : '';
        $request_token = get_option(self::OPTION_REQUEST_TOKEN, array());

        if (empty($oauth_token) || empty($oauth_verifier) || empty($request_token['token']) || $oauth_token !== $request_token['token']) {
            self::log('Tumblr OAuth callback failed because the verifier or request token was invalid.');
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        $response = self::oauth_request(
            'POST',
            self::ACCESS_TOKEN_URL,
            array('oauth_verifier' => $oauth_verifier),
            $request_token['token'],
            $request_token['secret']
        );

        if (is_wp_error($response)) {
            self::log('Tumblr access token failed: ' . $response->get_error_message());
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code < 200 || $response_code >= 300) {
            self::log(sprintf(
                'Tumblr access token failed with HTTP %1$d: %2$s',
                absint($response_code),
                self::shorten_for_log($response_body)
            ));
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        parse_str($response_body, $token_data);

        if (empty($token_data['oauth_token']) || empty($token_data['oauth_token_secret'])) {
            self::log('Tumblr access token failed: invalid response.');
            wp_safe_redirect(add_query_arg('tar_message', 'connect_failed', self::admin_url()));
            exit;
        }

        update_option(
            self::OPTION_TOKENS,
            array(
                'oauth_token' => sanitize_text_field($token_data['oauth_token']),
                'oauth_token_secret' => sanitize_text_field($token_data['oauth_token_secret']),
                'screen_name' => isset($token_data['screen_name']) ? sanitize_text_field($token_data['screen_name']) : '',
                'connected_at' => current_time('mysql'),
            ),
            false
        );
        delete_option(self::OPTION_REQUEST_TOKEN);
        self::log('Tumblr account connected.');

        wp_safe_redirect(add_query_arg('tar_message', 'connected', self::admin_url()));
        exit;
    }

    public static function disconnect()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to disconnect Tumblr.', 'tumblr-auto-reposter'));
        }

        delete_option(self::OPTION_TOKENS);
        delete_option(self::OPTION_REQUEST_TOKEN);
        self::log('Tumblr account disconnected.');
        wp_safe_redirect(add_query_arg('tar_message', 'disconnected', self::admin_url()));
        exit;
    }

    public static function run($manual = false)
    {
        $settings = self::get_settings();

        if (!$manual && empty($settings['enabled'])) {
            self::log('Cron skipped because automation is disabled.');
            return;
        }

        if (!self::is_connected()) {
            self::log('Run skipped because Tumblr is not connected.');
            return;
        }

        if (empty($settings['blog_hostname'])) {
            self::log('Run skipped because Tumblr blog hostname is missing.');
            return;
        }

        $daily_state = self::get_daily_state();

        if (!$manual && absint($daily_state['count']) >= absint($settings['posts_per_day'])) {
            self::log('Run skipped because the daily Tumblr post limit has already been reached.');
            return;
        }

        $selection = self::select_random_post_image($settings);

        if (is_wp_error($selection)) {
            self::log('Run completed without a Tumblr post: ' . $selection->get_error_message());
            return;
        }

        if (!empty($settings['dry_run'])) {
            self::log(sprintf(
                'Dry run: would create Tumblr %1$s post for article #%2$d "%3$s" using image %4$s.',
                $settings['post_state'],
                absint($selection['post_id']),
                $selection['title'],
                $selection['image_url']
            ));
            return;
        }

        $result = self::create_tumblr_photo_post($selection, $settings);

        if (is_wp_error($result)) {
            self::log('Tumblr post failed: ' . $result->get_error_message());
            return;
        }

        self::mark_image_used($selection['image_key']);
        $daily_state['count'] = absint($daily_state['count']) + 1;
        update_option(self::OPTION_DAILY_COUNT, $daily_state, false);

        $tumblr_id = isset($result['response']['id']) ? $result['response']['id'] : '';
        self::log(sprintf(
            'Created Tumblr %1$s post%2$s for article #%3$d "%4$s" using image %5$s. Daily count: %6$d/%7$d.',
            $settings['post_state'],
            $tumblr_id ? ' #' . $tumblr_id : '',
            absint($selection['post_id']),
            $selection['title'],
            $selection['image_url'],
            absint($daily_state['count']),
            absint($settings['posts_per_day'])
        ));
    }

    private static function select_random_post_image($settings)
    {
        $post_ids = get_posts(
            array(
                'post_type' => 'post',
                'post_status' => $settings['post_statuses'],
                'posts_per_page' => 300,
                'orderby' => 'rand',
                'fields' => 'ids',
                'no_found_rows' => true,
                'suppress_filters' => false,
            )
        );

        if (empty($post_ids)) {
            return new WP_Error('tar_no_posts', __('No matching WordPress articles found.', 'tumblr-auto-reposter'));
        }

        foreach ($post_ids as $post_id) {
            $images = self::get_post_images($post_id, $settings);

            if (empty($images)) {
                continue;
            }

            shuffle($images);

            foreach ($images as $image) {
                if (!self::is_image_blocked($image['key'], $settings['cooldown_days'])) {
                    return array(
                        'post_id' => absint($post_id),
                        'title' => get_the_title($post_id),
                        'permalink' => get_permalink($post_id),
                        'image_url' => $image['url'],
                        'image_key' => $image['key'],
                    );
                }
            }
        }

        return new WP_Error('tar_no_available_images', __('No eligible article images found outside the configured cooldown window.', 'tumblr-auto-reposter'));
    }

    private static function get_post_images($post_id, $settings)
    {
        $images = array();

        if (!empty($settings['include_featured_image'])) {
            $thumbnail_id = get_post_thumbnail_id($post_id);
            $thumbnail_url = $thumbnail_id ? wp_get_attachment_image_url($thumbnail_id, 'full') : '';
            self::add_image_candidate($images, $thumbnail_url, 'attachment:' . $thumbnail_id);
        }

        if (!empty($settings['include_attached_images'])) {
            $attachments = get_children(
                array(
                    'post_parent' => $post_id,
                    'post_type' => 'attachment',
                    'post_mime_type' => 'image',
                    'fields' => 'ids',
                    'orderby' => 'rand',
                )
            );

            foreach ((array) $attachments as $attachment_id) {
                $url = wp_get_attachment_image_url($attachment_id, 'full');
                self::add_image_candidate($images, $url, 'attachment:' . $attachment_id);
            }
        }

        if (!empty($settings['include_content_images'])) {
            $post = get_post($post_id);

            if ($post && !empty($post->post_content)) {
                preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches);

                foreach (!empty($matches[1]) ? $matches[1] : array() as $url) {
                    self::add_image_candidate($images, $url, 'url:' . $url);
                }
            }
        }

        return array_values($images);
    }

    private static function add_image_candidate(&$images, $url, $source_key)
    {
        $url = esc_url_raw($url);

        if (!$url || 0 !== strpos($url, 'http')) {
            return;
        }

        $key = hash('sha256', $source_key . '|' . $url);

        if (isset($images[$key])) {
            return;
        }

        $images[$key] = array(
            'url' => $url,
            'key' => $key,
        );
    }

    private static function create_tumblr_photo_post($selection, $settings)
    {
        $blog_hostname = rawurlencode($settings['blog_hostname']);
        $url = self::API_BASE_URL . '/blog/' . $blog_hostname . '/post';
        $caption = sprintf(
            '<p><a href="%1$s">%2$s</a></p>',
            esc_url($selection['permalink']),
            esc_html($selection['title'])
        );
        $params = array(
            'type' => 'photo',
            'state' => $settings['post_state'],
            'source' => $selection['image_url'],
            'link' => $selection['permalink'],
            'caption' => $caption,
        );

        $response = self::oauth_request('POST', $url, $params, self::get_access_token(), self::get_access_token_secret());

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            $message = isset($body['meta']['msg']) ? $body['meta']['msg'] : wp_remote_retrieve_body($response);
            return new WP_Error('tar_tumblr_api_error', sprintf('Tumblr API returned HTTP %1$d: %2$s', absint($code), sanitize_text_field($message)));
        }

        if (empty($body) || !is_array($body)) {
            return new WP_Error('tar_tumblr_invalid_response', __('Tumblr returned an invalid response.', 'tumblr-auto-reposter'));
        }

        return $body;
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

        $state['count'] = isset($state['count']) ? absint($state['count']) : 0;
        return $state;
    }

    private static function is_image_blocked($image_key, $cooldown_days)
    {
        if (0 === absint($cooldown_days)) {
            return false;
        }

        $usage = self::get_image_usage();

        if (empty($usage[$image_key])) {
            return false;
        }

        return (time() - absint($usage[$image_key])) < (absint($cooldown_days) * DAY_IN_SECONDS);
    }

    private static function mark_image_used($image_key)
    {
        $usage = self::get_image_usage();
        $usage[$image_key] = time();

        $cutoff = time() - (366 * DAY_IN_SECONDS);
        foreach ($usage as $key => $timestamp) {
            if (absint($timestamp) < $cutoff) {
                unset($usage[$key]);
            }
        }

        update_option(self::OPTION_IMAGE_USAGE, $usage, false);
    }

    private static function get_image_usage()
    {
        $usage = get_option(self::OPTION_IMAGE_USAGE, array());
        return is_array($usage) ? $usage : array();
    }

    private static function is_connected()
    {
        return self::get_access_token() && self::get_access_token_secret();
    }

    private static function get_access_token()
    {
        $tokens = get_option(self::OPTION_TOKENS, array());
        return is_array($tokens) && !empty($tokens['oauth_token']) ? $tokens['oauth_token'] : '';
    }

    private static function get_access_token_secret()
    {
        $tokens = get_option(self::OPTION_TOKENS, array());
        return is_array($tokens) && !empty($tokens['oauth_token_secret']) ? $tokens['oauth_token_secret'] : '';
    }

    private static function oauth_request($method, $url, $params = array(), $token = '', $token_secret = '')
    {
        $settings = self::get_settings();

        if (empty($settings['consumer_key']) || empty($settings['consumer_secret'])) {
            return new WP_Error('tar_missing_credentials', __('Tumblr consumer key and secret are required.', 'tumblr-auto-reposter'));
        }

        $method = strtoupper($method);
        $params = is_array($params) ? $params : array();
        $oauth = array(
            'oauth_consumer_key' => $settings['consumer_key'],
            'oauth_nonce' => wp_generate_password(24, false, false),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => time(),
            'oauth_version' => '1.0',
        );

        if ($token) {
            $oauth['oauth_token'] = $token;
        }

        if (!empty($params['oauth_callback'])) {
            $oauth['oauth_callback'] = $params['oauth_callback'];
            unset($params['oauth_callback']);
        }

        if (!empty($params['oauth_verifier'])) {
            $oauth['oauth_verifier'] = $params['oauth_verifier'];
            unset($params['oauth_verifier']);
        }

        $signature_params = array_merge($oauth, $params);
        ksort($signature_params);

        $base_parts = array(
            $method,
            self::oauth_encode(self::normalize_oauth_url($url)),
            self::oauth_encode(self::build_oauth_query($signature_params)),
        );
        $base_string = implode('&', $base_parts);
        $signing_key = self::oauth_encode($settings['consumer_secret']) . '&' . self::oauth_encode($token_secret);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));

        $authorization = 'OAuth ' . implode(', ', array_map(
            function ($key, $value) {
                return self::oauth_encode($key) . '="' . self::oauth_encode($value) . '"';
            },
            array_keys($oauth),
            $oauth
        ));

        $args = array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => $authorization,
            ),
        );

        if ('POST' === $method) {
            $args['body'] = $params;
            return wp_remote_post($url, $args);
        }

        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        return wp_remote_get($url, $args);
    }

    private static function normalize_oauth_url($url)
    {
        $parts = wp_parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? absint($parts['port']) : null;
        $path = isset($parts['path']) ? $parts['path'] : '';
        $normalized = $scheme . '://' . $host;

        if ($port && !(('http' === $scheme && 80 === $port) || ('https' === $scheme && 443 === $port))) {
            $normalized .= ':' . $port;
        }

        return $normalized . $path;
    }

    private static function build_oauth_query($params)
    {
        $pairs = array();

        foreach ($params as $key => $value) {
            if (is_array($value)) {
                sort($value);

                foreach ($value as $item) {
                    $pairs[] = self::oauth_encode($key) . '=' . self::oauth_encode($item);
                }
                continue;
            }

            $pairs[] = self::oauth_encode($key) . '=' . self::oauth_encode($value);
        }

        sort($pairs);
        return implode('&', $pairs);
    }

    private static function oauth_encode($value)
    {
        return str_replace('%7E', '~', rawurlencode((string) $value));
    }

    private static function log($message)
    {
        $logs = get_option(self::OPTION_LOGS, array());

        if (!is_array($logs)) {
            $logs = array();
        }

        array_unshift(
            $logs,
            array(
                'time' => current_time('mysql'),
                'message' => sanitize_text_field($message),
            )
        );

        update_option(self::OPTION_LOGS, array_slice($logs, 0, self::LOG_LIMIT), false);
    }

    private static function shorten_for_log($message)
    {
        $message = trim(wp_strip_all_tags((string) $message));

        if ('' === $message) {
            return 'empty response';
        }

        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 240);
        }

        return substr($message, 0, 240);
    }

    private static function get_logs()
    {
        $logs = get_option(self::OPTION_LOGS, array());
        return is_array($logs) ? $logs : array();
    }

    private static function get_connection()
    {
        $tokens = get_option(self::OPTION_TOKENS, array());
        return is_array($tokens) ? $tokens : array();
    }

    private static function render_notice()
    {
        if (empty($_GET['tar_message'])) {
            return;
        }

        $message_key = sanitize_key(wp_unslash($_GET['tar_message']));
        $messages = array(
            'settings_saved' => __('Settings saved.', 'tumblr-auto-reposter'),
            'run_complete' => __('Manual run completed. Check logs for details.', 'tumblr-auto-reposter'),
            'logs_cleared' => __('Logs cleared.', 'tumblr-auto-reposter'),
            'usage_cleared' => __('Image cooldown history cleared.', 'tumblr-auto-reposter'),
            'connected' => __('Tumblr account connected.', 'tumblr-auto-reposter'),
            'disconnected' => __('Tumblr account disconnected.', 'tumblr-auto-reposter'),
            'connect_failed' => __('Tumblr connection failed. Check logs for details.', 'tumblr-auto-reposter'),
            'credentials_missing' => __('Add your Tumblr consumer key and secret before connecting.', 'tumblr-auto-reposter'),
        );

        if (empty($messages[$message_key])) {
            return;
        }

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            in_array($message_key, array('connect_failed', 'credentials_missing'), true) ? 'error' : 'success',
            esc_html($messages[$message_key])
        );
    }

    public static function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $logs = self::get_logs();
        $connection = self::get_connection();
        $daily_state = self::get_daily_state();
        $image_usage = self::get_image_usage();
        $next_run = wp_next_scheduled(self::CRON_HOOK);
        ?>
        <div class="wrap tar-wrap">
            <h1><?php esc_html_e('Tumblr Auto Reposter', 'tumblr-auto-reposter'); ?></h1>
            <?php self::render_notice(); ?>

            <div class="tar-status">
                <p>
                    <strong><?php esc_html_e('Callback URL:', 'tumblr-auto-reposter'); ?></strong>
                    <code><?php echo esc_html(self::callback_url()); ?></code>
                </p>
                <p>
                    <strong><?php esc_html_e('Tumblr connection:', 'tumblr-auto-reposter'); ?></strong>
                    <?php
                    if (self::is_connected()) {
                        printf(
                            esc_html__('Connected%1$s%2$s', 'tumblr-auto-reposter'),
                            !empty($connection['screen_name']) ? ' as ' : '',
                            !empty($connection['screen_name']) ? esc_html($connection['screen_name']) : ''
                        );
                    } else {
                        esc_html_e('Not connected', 'tumblr-auto-reposter');
                    }
                    ?>
                </p>
            </div>

            <form method="post" action="<?php echo esc_url(self::admin_url()); ?>">
                <?php wp_nonce_field('tar_save_settings'); ?>
                <input type="hidden" name="tar_action" value="save_settings">

                <h2><?php esc_html_e('Tumblr API', 'tumblr-auto-reposter'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><label for="consumer_key"><?php esc_html_e('Consumer key', 'tumblr-auto-reposter'); ?></label></th>
                        <td><input type="text" id="consumer_key" name="consumer_key" value="<?php echo esc_attr($settings['consumer_key']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="consumer_secret"><?php esc_html_e('Consumer secret', 'tumblr-auto-reposter'); ?></label></th>
                        <td><input type="password" id="consumer_secret" name="consumer_secret" value="<?php echo esc_attr($settings['consumer_secret']); ?>" class="regular-text" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="blog_hostname"><?php esc_html_e('Tumblr blog hostname', 'tumblr-auto-reposter'); ?></label></th>
                        <td>
                            <input type="text" id="blog_hostname" name="blog_hostname" value="<?php echo esc_attr($settings['blog_hostname']); ?>" class="regular-text" placeholder="example.tumblr.com">
                            <p class="description"><?php esc_html_e('Use the Tumblr blog hostname that should receive the image posts.', 'tumblr-auto-reposter'); ?></p>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <h2><?php esc_html_e('Automation', 'tumblr-auto-reposter'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Enabled', 'tumblr-auto-reposter'); ?></th>
                        <td>
                            <label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled'], 1); ?>> <?php esc_html_e('Run automation through WP-Cron', 'tumblr-auto-reposter'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="posts_per_day"><?php esc_html_e('Tumblr posts per day', 'tumblr-auto-reposter'); ?></label></th>
                        <td>
                            <input type="number" min="1" step="1" id="posts_per_day" name="posts_per_day" value="<?php echo esc_attr($settings['posts_per_day']); ?>" class="small-text">
                            <p class="description">
                                <?php
                                printf(
                                    esc_html__('Posted today by this plugin: %1$d / %2$d', 'tumblr-auto-reposter'),
                                    absint($daily_state['count']),
                                    absint($settings['posts_per_day'])
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="cooldown_days"><?php esc_html_e('Image cooldown', 'tumblr-auto-reposter'); ?></label></th>
                        <td>
                            <input type="number" min="0" step="1" id="cooldown_days" name="cooldown_days" value="<?php echo esc_attr($settings['cooldown_days']); ?>" class="small-text">
                            <?php esc_html_e('day(s)', 'tumblr-auto-reposter'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="post_state"><?php esc_html_e('Tumblr state', 'tumblr-auto-reposter'); ?></label></th>
                        <td>
                            <select id="post_state" name="post_state">
                                <option value="queue" <?php selected($settings['post_state'], 'queue'); ?>><?php esc_html_e('Queue', 'tumblr-auto-reposter'); ?></option>
                                <option value="published" <?php selected($settings['post_state'], 'published'); ?>><?php esc_html_e('Published', 'tumblr-auto-reposter'); ?></option>
                                <option value="draft" <?php selected($settings['post_state'], 'draft'); ?>><?php esc_html_e('Draft', 'tumblr-auto-reposter'); ?></option>
                                <option value="private" <?php selected($settings['post_state'], 'private'); ?>><?php esc_html_e('Private', 'tumblr-auto-reposter'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Article statuses', 'tumblr-auto-reposter'); ?></th>
                        <td>
                            <?php foreach (array('publish' => 'Published', 'future' => 'Scheduled', 'private' => 'Private') as $status => $label) : ?>
                                <label class="tar-inline"><input type="checkbox" name="post_statuses[]" value="<?php echo esc_attr($status); ?>" <?php checked(in_array($status, $settings['post_statuses'], true)); ?>> <?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Image sources', 'tumblr-auto-reposter'); ?></th>
                        <td>
                            <label class="tar-block"><input type="checkbox" name="include_featured_image" value="1" <?php checked($settings['include_featured_image'], 1); ?>> <?php esc_html_e('Featured image', 'tumblr-auto-reposter'); ?></label>
                            <label class="tar-block"><input type="checkbox" name="include_attached_images" value="1" <?php checked($settings['include_attached_images'], 1); ?>> <?php esc_html_e('Attached media images', 'tumblr-auto-reposter'); ?></label>
                            <label class="tar-block"><input type="checkbox" name="include_content_images" value="1" <?php checked($settings['include_content_images'], 1); ?>> <?php esc_html_e('Images embedded in article content', 'tumblr-auto-reposter'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dry run', 'tumblr-auto-reposter'); ?></th>
                        <td><label><input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run'], 1); ?>> <?php esc_html_e('Only log what would be posted to Tumblr', 'tumblr-auto-reposter'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Next cron run', 'tumblr-auto-reposter'); ?></th>
                        <td>
                            <?php
                            echo $next_run
                                ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $next_run))
                                : esc_html__('Not scheduled', 'tumblr-auto-reposter');
                            ?>
                        </td>
                    </tr>
                    </tbody>
                </table>

                <?php submit_button(__('Save settings', 'tumblr-auto-reposter')); ?>
            </form>

            <div class="tar-actions">
                <form method="post" action="<?php echo esc_url(self::admin_url()); ?>">
                    <?php wp_nonce_field('tar_connect'); ?>
                    <input type="hidden" name="tar_action" value="connect">
                    <?php submit_button(self::is_connected() ? __('Reconnect Tumblr', 'tumblr-auto-reposter') : __('Connect Tumblr', 'tumblr-auto-reposter'), 'primary', 'submit', false); ?>
                </form>

                <?php if (self::is_connected()) : ?>
                    <form method="post" action="<?php echo esc_url(self::admin_url()); ?>">
                        <?php wp_nonce_field('tar_disconnect'); ?>
                        <input type="hidden" name="tar_action" value="disconnect">
                        <?php submit_button(__('Disconnect', 'tumblr-auto-reposter'), 'delete', 'submit', false); ?>
                    </form>
                <?php endif; ?>

                <form method="post" action="<?php echo esc_url(self::admin_url()); ?>">
                    <?php wp_nonce_field('tar_run_now'); ?>
                    <input type="hidden" name="tar_action" value="run_now">
                    <?php submit_button(__('Run now', 'tumblr-auto-reposter'), 'secondary', 'submit', false); ?>
                </form>

                <form method="post" action="<?php echo esc_url(self::admin_url()); ?>">
                    <?php wp_nonce_field('tar_clear_usage'); ?>
                    <input type="hidden" name="tar_action" value="clear_usage">
                    <?php submit_button(__('Clear image cooldowns', 'tumblr-auto-reposter'), 'secondary', 'submit', false); ?>
                </form>

                <form method="post" action="<?php echo esc_url(self::admin_url()); ?>">
                    <?php wp_nonce_field('tar_clear_logs'); ?>
                    <input type="hidden" name="tar_action" value="clear_logs">
                    <?php submit_button(__('Clear logs', 'tumblr-auto-reposter'), 'delete', 'submit', false); ?>
                </form>
            </div>

            <h2><?php esc_html_e('Status', 'tumblr-auto-reposter'); ?></h2>
            <table class="widefat striped tar-small-table">
                <tbody>
                <tr><th><?php esc_html_e('Images in cooldown history', 'tumblr-auto-reposter'); ?></th><td><?php echo esc_html(count($image_usage)); ?></td></tr>
                <tr><th><?php esc_html_e('Cron schedule interval', 'tumblr-auto-reposter'); ?></th><td><?php echo esc_html((int) round(max(15 * MINUTE_IN_SECONDS, floor(DAY_IN_SECONDS / max(1, $settings['posts_per_day']))) / MINUTE_IN_SECONDS)); ?> <?php esc_html_e('minutes', 'tumblr-auto-reposter'); ?></td></tr>
                </tbody>
            </table>

            <h2><?php esc_html_e('Logs', 'tumblr-auto-reposter'); ?></h2>
            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No logs yet.', 'tumblr-auto-reposter'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Time', 'tumblr-auto-reposter'); ?></th>
                        <th><?php esc_html_e('Message', 'tumblr-auto-reposter'); ?></th>
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
}
