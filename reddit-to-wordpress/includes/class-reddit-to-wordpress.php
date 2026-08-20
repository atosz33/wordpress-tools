<?php

if (!defined('ABSPATH')) {
    exit;
}

class Reddit_To_WordPress
{
    const OPTION_SETTINGS = 'rtw_settings';
    const OPTION_RULES = 'rtw_rules';
    const OPTION_TEMPLATES = 'rtw_templates';
    const OPTION_POSTED = 'rtw_posted_ids';
    const OPTION_LOGS = 'rtw_logs';
    const CRON_HOOK = 'rtw_fetch_reddit_posts';
    const CRON_SCHEDULE = 'rtw_every_fifteen_minutes';
    const TOKEN_TRANSIENT = 'rtw_access_token';
    const RUN_LOCK_TRANSIENT = 'rtw_run_lock';
    const LOG_LIMIT = 200;
    const FETCH_LIMIT = 100;
    const NEW_MAX_PAGES = 5;
    const DEFAULT_MAX_POSTS_PER_RUN = 25;

    const TOKEN_URL = 'https://www.reddit.com/api/v1/access_token';
    const API_BASE_URL = 'https://oauth.reddit.com';

    public static function init()
    {
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_schedule'));
        add_action(self::CRON_HOOK, array(__CLASS__, 'run'));
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
    }

    public static function activate()
    {
        if (false === get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
        }

        if (false === get_option(self::OPTION_RULES)) {
            add_option(self::OPTION_RULES, array(self::blank_rule()), '', false);
        }

        if (false === get_option(self::OPTION_TEMPLATES)) {
            add_option(self::OPTION_TEMPLATES, array(self::default_template()), '', false);
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
        delete_option(self::OPTION_RULES);
        delete_option(self::OPTION_TEMPLATES);
        delete_option(self::OPTION_POSTED);
        delete_option(self::OPTION_LOGS);
        delete_transient(self::TOKEN_TRANSIENT);
        delete_transient(self::RUN_LOCK_TRANSIENT);
    }

    public static function default_settings()
    {
        return array(
            'enabled' => 0,
            'client_id' => '',
            'client_secret' => '',
            'username' => '',
            'password' => '',
            'user_agent' => '',
            'default_post_status' => 'draft',
            'dry_run' => 1,
        );
    }

    private static function blank_rule()
    {
        return array(
            'id' => wp_generate_uuid4(),
            'enabled' => 0,
            'subreddit' => '',
            'method' => 'hot',
            'top_time_filter' => 'day',
            'content_types' => array('image', 'gif'),
            'interval_minutes' => 60,
            'max_posts_per_run' => self::DEFAULT_MAX_POSTS_PER_RUN,
            'post_status' => '',
            'template_ids' => array(),
            'last_run' => 0,
        );
    }

    private static function default_template()
    {
        return array(
            'id' => wp_generate_uuid4(),
            'name' => 'Default media embed',
            'title_template' => '{{title}}',
            'content_template' => "{{embed}}\n\n<p>Source: <a href=\"{{reddit_url}}\">Reddit /r/{{subreddit}}</a></p>",
            'categories_template' => '{{subreddit}}',
            'tags_template' => 'reddit, {{subreddit}}, {{author}}',
        );
    }

    public static function get_settings()
    {
        return self::normalize_settings(get_option(self::OPTION_SETTINGS, array()));
    }

    private static function normalize_settings($settings)
    {
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), self::default_settings());
        $settings['enabled'] = empty($settings['enabled']) ? 0 : 1;
        $settings['client_id'] = sanitize_text_field($settings['client_id']);
        $settings['client_secret'] = sanitize_text_field($settings['client_secret']);
        $settings['username'] = sanitize_text_field($settings['username']);
        $settings['password'] = (string) $settings['password'];
        $settings['user_agent'] = sanitize_text_field($settings['user_agent']);
        $settings['default_post_status'] = in_array($settings['default_post_status'], array('draft', 'publish'), true) ? $settings['default_post_status'] : 'draft';
        $settings['dry_run'] = empty($settings['dry_run']) ? 0 : 1;

        return $settings;
    }

    private static function get_rules()
    {
        $rules = get_option(self::OPTION_RULES, array());
        $rules = is_array($rules) ? $rules : array();
        $normalized = array();

        foreach ($rules as $rule) {
            $rule = self::normalize_rule($rule);
            if ($rule['subreddit']) {
                $normalized[] = $rule;
            }
        }

        return $normalized;
    }

    private static function normalize_rule($rule)
    {
        $defaults = self::blank_rule();
        $rule = wp_parse_args(is_array($rule) ? $rule : array(), $defaults);
        $methods = array('new', 'hot', 'top', 'rising');
        $time_filters = array('hour', 'day', 'week', 'month', 'year', 'all');
        $content_types = array_intersect(array('image', 'gif', 'video', 'link', 'text'), array_map('sanitize_key', (array) $rule['content_types']));

        $rule['id'] = sanitize_text_field($rule['id']) ? sanitize_text_field($rule['id']) : wp_generate_uuid4();
        $rule['enabled'] = empty($rule['enabled']) ? 0 : 1;
        $rule['subreddit'] = trim(sanitize_text_field($rule['subreddit']));
        $rule['subreddit'] = preg_replace('/^r\//i', '', $rule['subreddit']);
        $rule['method'] = in_array($rule['method'], $methods, true) ? $rule['method'] : 'hot';
        $rule['top_time_filter'] = in_array($rule['top_time_filter'], $time_filters, true) ? $rule['top_time_filter'] : 'day';
        $rule['content_types'] = empty($content_types) ? array('image', 'gif') : array_values($content_types);
        $rule['interval_minutes'] = max(15, absint($rule['interval_minutes']));
        $rule['max_posts_per_run'] = min(100, absint($rule['max_posts_per_run']));
        $rule['post_status'] = in_array($rule['post_status'], array('draft', 'publish'), true) ? $rule['post_status'] : '';
        $rule['template_ids'] = array_values(array_filter(array_map('sanitize_text_field', (array) $rule['template_ids'])));
        $rule['last_run'] = absint($rule['last_run']);

        return $rule;
    }

    private static function get_templates()
    {
        $templates = get_option(self::OPTION_TEMPLATES, array());
        $templates = is_array($templates) ? $templates : array();
        $normalized = array();

        foreach ($templates as $template) {
            $template = self::normalize_template($template);
            if ($template['name'] || $template['title_template'] || $template['content_template']) {
                $normalized[] = $template;
            }
        }

        if (empty($normalized)) {
            $normalized[] = self::default_template();
        }

        return $normalized;
    }

    private static function normalize_template($template)
    {
        $template = wp_parse_args(is_array($template) ? $template : array(), self::default_template());
        $template['id'] = sanitize_text_field($template['id']) ? sanitize_text_field($template['id']) : wp_generate_uuid4();
        $template['name'] = sanitize_text_field($template['name']);
        $template['title_template'] = sanitize_text_field($template['title_template']);
        $template['content_template'] = wp_kses($template['content_template'], self::allowed_template_html());
        $template['categories_template'] = sanitize_text_field($template['categories_template']);
        $template['tags_template'] = sanitize_text_field($template['tags_template']);

        return $template;
    }

    private static function allowed_template_html()
    {
        $allowed = wp_kses_allowed_html('post');
        $allowed['center'] = array(
            'class' => true,
            'id' => true,
            'style' => true,
        );

        return $allowed;
    }

    public static function add_cron_schedule($schedules)
    {
        $schedules[self::CRON_SCHEDULE] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 minutes', 'reddit-to-wordpress'),
        );

        return $schedules;
    }

    public static function schedule_event()
    {
        self::clear_scheduled_event();

        if (empty(self::get_settings()['enabled'])) {
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
            __('Reddit To WordPress', 'reddit-to-wordpress'),
            __('Reddit To WP', 'reddit-to-wordpress'),
            'manage_options',
            'reddit-to-wordpress',
            array(__CLASS__, 'render_admin_page')
        );
    }

    public static function admin_url()
    {
        return admin_url('options-general.php?page=reddit-to-wordpress');
    }

    public static function enqueue_admin_assets($hook)
    {
        if ('settings_page_reddit-to-wordpress' !== $hook) {
            return;
        }

        wp_enqueue_style('rtw-admin', RTW_PLUGIN_URL . 'assets/admin.css', array(), RTW_VERSION);
        wp_enqueue_script('rtw-admin', RTW_PLUGIN_URL . 'assets/admin.js', array(), RTW_VERSION, true);
    }

    public static function handle_admin_actions()
    {
        if (!is_admin() || !current_user_can('manage_options') || empty($_POST['rtw_action'])) {
            return;
        }

        $action = sanitize_key(wp_unslash($_POST['rtw_action']));

        if ('save_settings' === $action) {
            check_admin_referer('rtw_save_settings');
            self::save_settings();
            wp_safe_redirect(add_query_arg('rtw_message', 'settings_saved', self::admin_url()));
            exit;
        }

        if ('test_credentials' === $action) {
            check_admin_referer('rtw_test_credentials');
            delete_transient(self::TOKEN_TRANSIENT);
            $token = self::get_access_token();
            $message = is_wp_error($token) ? 'credentials_failed' : 'credentials_ok';
            self::log(is_wp_error($token) ? 'Reddit credential test failed: ' . $token->get_error_message() : 'Reddit credential test succeeded.');
            wp_safe_redirect(add_query_arg('rtw_message', $message, self::admin_url()));
            exit;
        }

        if ('run_now' === $action) {
            check_admin_referer('rtw_run_now');
            self::run(true);
            wp_safe_redirect(add_query_arg('rtw_message', 'run_complete', self::admin_url()));
            exit;
        }

        if ('dry_run_preview' === $action) {
            check_admin_referer('rtw_dry_run_preview');
            $preview = self::build_dry_run_preview();
            set_transient('rtw_preview_' . get_current_user_id(), $preview, 10 * MINUTE_IN_SECONDS);
            wp_safe_redirect(add_query_arg('rtw_message', is_wp_error($preview) ? 'preview_failed' : 'preview_ready', self::admin_url()));
            exit;
        }

        if ('clear_logs' === $action) {
            check_admin_referer('rtw_clear_logs');
            update_option(self::OPTION_LOGS, array(), false);
            wp_safe_redirect(add_query_arg('rtw_message', 'logs_cleared', self::admin_url()));
            exit;
        }

        if ('clear_history' === $action) {
            check_admin_referer('rtw_clear_history');
            update_option(self::OPTION_POSTED, array(), false);
            self::log('Reddit posted history cleared.');
            wp_safe_redirect(add_query_arg('rtw_message', 'history_cleared', self::admin_url()));
            exit;
        }
    }

    private static function save_settings()
    {
        $settings = array(
            'enabled' => empty($_POST['enabled']) ? 0 : 1,
            'client_id' => isset($_POST['client_id']) ? sanitize_text_field(wp_unslash($_POST['client_id'])) : '',
            'client_secret' => isset($_POST['client_secret']) ? sanitize_text_field(wp_unslash($_POST['client_secret'])) : '',
            'username' => isset($_POST['username']) ? sanitize_text_field(wp_unslash($_POST['username'])) : '',
            'password' => isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '',
            'user_agent' => isset($_POST['user_agent']) ? sanitize_text_field(wp_unslash($_POST['user_agent'])) : '',
            'default_post_status' => isset($_POST['default_post_status']) ? sanitize_key(wp_unslash($_POST['default_post_status'])) : 'draft',
            'dry_run' => empty($_POST['dry_run']) ? 0 : 1,
        );

        update_option(self::OPTION_SETTINGS, self::normalize_settings($settings), false);
        update_option(self::OPTION_RULES, self::read_posted_rules(), false);
        update_option(self::OPTION_TEMPLATES, self::read_posted_templates(), false);
        delete_transient(self::TOKEN_TRANSIENT);
        self::schedule_event();
        self::log('Settings saved. Cron schedule refreshed.');
    }

    private static function read_posted_rules()
    {
        $raw_rules = isset($_POST['rules']) && is_array($_POST['rules']) ? wp_unslash($_POST['rules']) : array();
        $existing = array();

        foreach (self::get_rules() as $rule) {
            $existing[$rule['id']] = $rule;
        }

        $rules = array();
        foreach ($raw_rules as $raw_rule) {
            if (empty($raw_rule['subreddit'])) {
                continue;
            }

            $id = !empty($raw_rule['id']) ? sanitize_text_field($raw_rule['id']) : wp_generate_uuid4();
            $raw_rule['id'] = $id;
            $raw_rule['last_run'] = isset($existing[$id]) ? $existing[$id]['last_run'] : 0;
            $rules[] = self::normalize_rule($raw_rule);
        }

        return $rules;
    }

    private static function read_posted_templates()
    {
        $raw_templates = isset($_POST['templates']) && is_array($_POST['templates']) ? wp_unslash($_POST['templates']) : array();
        $templates = array();

        foreach ($raw_templates as $raw_template) {
            if (empty($raw_template['name']) && empty($raw_template['title_template']) && empty($raw_template['content_template'])) {
                continue;
            }

            $templates[] = self::normalize_template($raw_template);
        }

        return empty($templates) ? array(self::default_template()) : $templates;
    }

    public static function run($manual = false)
    {
        if (get_transient(self::RUN_LOCK_TRANSIENT)) {
            self::log('Run skipped because another Reddit import is already running.');
            return;
        }

        set_transient(self::RUN_LOCK_TRANSIENT, 1, 10 * MINUTE_IN_SECONDS);

        try {
            self::run_import($manual);
        } finally {
            delete_transient(self::RUN_LOCK_TRANSIENT);
        }
    }

    private static function run_import($manual = false)
    {
        $settings = self::get_settings();

        if (!$manual && empty($settings['enabled'])) {
            self::log('Cron skipped because automation is disabled.');
            return;
        }

        $rules = self::get_rules();
        if (empty($rules)) {
            self::log('Run skipped because no subreddit rules are configured.');
            return;
        }

        $ran = 0;
        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }

            if (!$manual && !self::is_rule_due($rule)) {
                continue;
            }

            self::process_rule($rule, $settings);
            self::mark_rule_run($rule['id']);
            $ran++;
        }

        if (0 === $ran) {
            self::log('Run completed. No enabled subreddit rules were due.');
        }
    }

    private static function is_rule_due($rule)
    {
        return (time() - absint($rule['last_run'])) >= (absint($rule['interval_minutes']) * MINUTE_IN_SECONDS);
    }

    private static function format_rule_next_run($rule)
    {
        if (empty($rule['enabled'])) {
            return __('Disabled', 'reddit-to-wordpress');
        }

        if (empty($rule['last_run'])) {
            return __('Due now', 'reddit-to-wordpress');
        }

        $next_run = absint($rule['last_run']) + (absint($rule['interval_minutes']) * MINUTE_IN_SECONDS);

        if ($next_run <= time()) {
            return __('Due now', 'reddit-to-wordpress');
        }

        return wp_date('Y-m-d H:i:s', $next_run);
    }

    private static function mark_rule_run($rule_id)
    {
        $rules = self::get_rules();
        foreach ($rules as &$rule) {
            if ($rule['id'] === $rule_id) {
                $rule['last_run'] = time();
                break;
            }
        }

        update_option(self::OPTION_RULES, $rules, false);
    }

    private static function process_rule($rule, $settings)
    {
        $candidates = self::find_candidates($rule);

        if (is_wp_error($candidates)) {
            self::log(sprintf('Rule /r/%1$s skipped: %2$s', $rule['subreddit'], $candidates->get_error_message()));
            return $candidates;
        }

        if (empty($candidates)) {
            self::log(sprintf('Rule /r/%s completed. No matching unposted Reddit posts found.', $rule['subreddit']));
            return 0;
        }

        $found_count = count($candidates);
        if (!empty($rule['max_posts_per_run'])) {
            $candidates = array_slice($candidates, 0, absint($rule['max_posts_per_run']));
        }

        $status = $rule['post_status'] ? $rule['post_status'] : $settings['default_post_status'];

        if (!empty($settings['dry_run'])) {
            foreach ($candidates as $candidate) {
                $template = self::select_template($rule);
                self::log(sprintf(
                    'Dry run: would create %1$s post from /r/%2$s Reddit post %3$s using template "%4$s".',
                    $status,
                    $rule['subreddit'],
                    $candidate['id'],
                    $template['name']
                ));
            }

            self::log(sprintf('Dry run: /r/%1$s has %2$d matching unposted Reddit post(s); %3$d would be processed this run.', $rule['subreddit'], $found_count, count($candidates)));
            return count($candidates);
        }

        $created = 0;
        $failed = 0;

        foreach ($candidates as $candidate) {
            $template = self::select_template($rule);
            $post_data = self::render_post_data($candidate, $template);
            $terms = self::render_post_terms($candidate, $template);

            $post_id = wp_insert_post(
                array(
                    'post_title' => $post_data['title'],
                    'post_content' => $post_data['content'],
                    'post_status' => $status,
                    'post_type' => 'post',
                    'meta_input' => array(
                        '_rtw_reddit_id' => $candidate['id'],
                        '_rtw_reddit_url' => $candidate['reddit_url'],
                        '_rtw_subreddit' => $candidate['subreddit'],
                    ),
                ),
                true
            );

            if (is_wp_error($post_id)) {
                $failed++;
                self::log(sprintf('WordPress post creation failed for Reddit post %1$s: %2$s', $candidate['id'], $post_id->get_error_message()));
                continue;
            }

            self::assign_post_terms($post_id, $terms);
            self::mark_posted($candidate['id']);
            $created++;
            self::log(sprintf('Created WordPress %1$s post #%2$d from /r/%3$s Reddit post %4$s.', $status, absint($post_id), $candidate['subreddit'], $candidate['id']));
        }

        self::log(sprintf('Rule /r/%1$s completed. Found %2$d matching unposted post(s), processed %3$d, created %4$d, failed %5$d.', $rule['subreddit'], $found_count, count($candidates), $created, $failed));

        return $created;
    }

    private static function find_candidates($rule)
    {
        $posts = self::fetch_reddit_posts($rule);
        if (is_wp_error($posts)) {
            return $posts;
        }

        $candidates = array();
        $seen = array();

        foreach ($posts as $post) {
            $candidate = self::normalize_reddit_post($post);

            if (!$candidate || self::was_posted($candidate['id'])) {
                continue;
            }

            if (isset($seen[$candidate['id']])) {
                continue;
            }

            if (array_intersect($rule['content_types'], $candidate['content_types'])) {
                $seen[$candidate['id']] = true;
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private static function fetch_reddit_posts($rule)
    {
        $token = self::get_access_token();
        if (is_wp_error($token)) {
            return $token;
        }

        $all_posts = array();
        $after = '';
        $max_pages = 'new' === $rule['method'] ? self::NEW_MAX_PAGES : 1;

        for ($page = 0; $page < $max_pages; $page++) {
            $result = self::fetch_reddit_posts_page($rule, $token, $after);

            if (is_wp_error($result)) {
                return $result;
            }

            $all_posts = array_merge($all_posts, $result['posts']);
            $after = $result['after'];

            if (!$after) {
                break;
            }
        }

        if (empty($all_posts)) {
            return new WP_Error('rtw_reddit_invalid_response', __('Reddit returned no posts.', 'reddit-to-wordpress'));
        }

        return $all_posts;
    }

    private static function fetch_reddit_posts_page($rule, $token, $after = '')
    {
        $path = '/r/' . rawurlencode($rule['subreddit']) . '/' . rawurlencode($rule['method']) . '.json';
        $query_args = array(
            'limit' => self::FETCH_LIMIT,
            'raw_json' => 1,
        );

        if ($after) {
            $query_args['after'] = $after;
        }

        if ('top' === $rule['method']) {
            $query_args['t'] = $rule['top_time_filter'];
        }

        $url = add_query_arg($query_args, self::API_BASE_URL . $path);

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'User-Agent' => self::user_agent(),
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300) {
            return new WP_Error('rtw_reddit_api_error', sprintf('Reddit API returned HTTP %1$d: %2$s', absint($code), self::shorten_for_log(wp_remote_retrieve_body($response))));
        }

        if (empty($body['data']['children']) || !is_array($body['data']['children'])) {
            return new WP_Error('rtw_reddit_invalid_response', __('Reddit returned no posts.', 'reddit-to-wordpress'));
        }

        return array(
            'posts' => $body['data']['children'],
            'after' => !empty($body['data']['after']) ? sanitize_text_field($body['data']['after']) : '',
        );
    }

    private static function get_access_token()
    {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if ($cached) {
            return $cached;
        }

        $settings = self::get_settings();
        if (!$settings['client_id'] || !$settings['client_secret'] || !$settings['username'] || !$settings['password']) {
            return new WP_Error('rtw_missing_credentials', __('Reddit credentials are incomplete.', 'reddit-to-wordpress'));
        }

        $response = wp_remote_post(
            self::TOKEN_URL,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($settings['client_id'] . ':' . $settings['client_secret']),
                    'User-Agent' => self::user_agent(),
                ),
                'body' => array(
                    'grant_type' => 'password',
                    'username' => $settings['username'],
                    'password' => $settings['password'],
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || empty($body['access_token'])) {
            return new WP_Error('rtw_token_failed', sprintf('Reddit token request failed with HTTP %1$d: %2$s', absint($code), self::shorten_for_log(wp_remote_retrieve_body($response))));
        }

        $ttl = !empty($body['expires_in']) ? max(60, absint($body['expires_in']) - 60) : HOUR_IN_SECONDS;
        set_transient(self::TOKEN_TRANSIENT, sanitize_text_field($body['access_token']), $ttl);

        return sanitize_text_field($body['access_token']);
    }

    private static function user_agent()
    {
        $settings = self::get_settings();
        if ($settings['user_agent']) {
            return $settings['user_agent'];
        }

        $username = $settings['username'] ? $settings['username'] : 'wordpress';
        return 'WordPress:reddit-to-wordpress:' . RTW_VERSION . ' (by /u/' . $username . ')';
    }

    private static function normalize_reddit_post($post)
    {
        if (empty($post['data']) || !is_array($post['data'])) {
            return null;
        }

        $data = $post['data'];
        $id = !empty($data['name']) ? sanitize_text_field($data['name']) : 't3_' . sanitize_text_field($data['id']);
        $permalink = !empty($data['permalink']) ? 'https://www.reddit.com' . $data['permalink'] : '';
        $media_url = self::detect_media_url($data);
        $content_types = self::detect_content_types($data, $media_url);

        return array(
            'id' => $id,
            'title' => sanitize_text_field($data['title'] ?? ''),
            'subreddit' => sanitize_text_field($data['subreddit'] ?? ''),
            'author' => sanitize_text_field($data['author'] ?? ''),
            'reddit_url' => esc_url_raw($permalink),
            'source_url' => esc_url_raw($data['url'] ?? ''),
            'media_url' => esc_url_raw($media_url),
            'thumbnail' => esc_url_raw(($data['thumbnail'] ?? '') && 0 === strpos($data['thumbnail'], 'http') ? $data['thumbnail'] : ''),
            'selftext' => wp_kses_post($data['selftext'] ?? ''),
            'score' => absint($data['score'] ?? 0),
            'comments' => absint($data['num_comments'] ?? 0),
            'created' => !empty($data['created_utc']) ? gmdate('Y-m-d H:i:s', absint($data['created_utc'])) : '',
            'content_types' => $content_types,
            'embed' => self::build_embed($data, $media_url, $permalink),
        );
    }

    private static function detect_media_url($data)
    {
        if (!empty($data['media']['reddit_video']['fallback_url'])) {
            return $data['media']['reddit_video']['fallback_url'];
        }

        if (!empty($data['preview']['reddit_video_preview']['fallback_url'])) {
            return $data['preview']['reddit_video_preview']['fallback_url'];
        }

        if (!empty($data['url_overridden_by_dest'])) {
            return $data['url_overridden_by_dest'];
        }

        return !empty($data['url']) ? $data['url'] : '';
    }

    private static function detect_content_types($data, $media_url)
    {
        $types = array();
        $url_path = strtolower((string) wp_parse_url($media_url, PHP_URL_PATH));

        if (!empty($data['is_self'])) {
            $types[] = 'text';
        }

        if (!empty($data['is_video']) || !empty($data['media']['reddit_video']['fallback_url'])) {
            $types[] = 'video';
        }

        if (preg_match('/\.(gif|gifv)$/', $url_path) || !empty($data['preview']['reddit_video_preview']['is_gif'])) {
            $types[] = 'gif';
        }

        if ('image' === ($data['post_hint'] ?? '') || preg_match('/\.(jpe?g|png|webp)$/', $url_path)) {
            $types[] = 'image';
        }

        if (empty($data['is_self']) && $media_url) {
            $types[] = 'link';
        }

        return array_values(array_unique($types));
    }

    private static function build_embed($data, $media_url, $permalink)
    {
        $media_url = esc_url_raw($media_url);
        $permalink = esc_url_raw($permalink);
        $path = strtolower((string) wp_parse_url($media_url, PHP_URL_PATH));

        if ($media_url && preg_match('/\.(jpe?g|png|webp|gif)$/', $path)) {
            return sprintf('<figure><img src="%1$s" alt="%2$s" loading="lazy"></figure>', esc_url($media_url), esc_attr($data['title'] ?? ''));
        }

        if ($media_url && (!empty($data['is_video']) || preg_match('/\.(mp4|webm)$/', $path))) {
            return sprintf('<video controls preload="metadata" src="%1$s"></video>', esc_url($media_url));
        }

        if ($media_url) {
            return '[embed]' . esc_url($media_url) . '[/embed]';
        }

        return $permalink ? '[embed]' . esc_url($permalink) . '[/embed]' : '';
    }

    private static function select_template($rule)
    {
        $templates = self::get_templates();
        $allowed = array();

        if (!empty($rule['template_ids'])) {
            foreach ($templates as $template) {
                if (in_array($template['id'], $rule['template_ids'], true)) {
                    $allowed[] = $template;
                }
            }
        }

        if (empty($allowed)) {
            $allowed = $templates;
        }

        return $allowed[array_rand($allowed)];
    }

    private static function render_post_data($candidate, $template)
    {
        $replacements = self::placeholder_values($candidate);

        return array(
            'title' => wp_strip_all_tags(strtr($template['title_template'], $replacements)),
            'content' => strtr($template['content_template'], $replacements),
        );
    }

    private static function render_post_terms($candidate, $template)
    {
        $replacements = self::placeholder_values($candidate);

        return array(
            'categories' => self::parse_term_template(strtr($template['categories_template'], $replacements)),
            'tags' => self::parse_term_template(strtr($template['tags_template'], $replacements)),
        );
    }

    private static function parse_term_template($value)
    {
        $value = wp_strip_all_tags((string) $value);
        $parts = preg_split('/[\r\n,]+/', $value);
        $terms = array();

        foreach ((array) $parts as $part) {
            $term = trim($part);
            if ('' === $term) {
                continue;
            }

            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private static function assign_post_terms($post_id, $terms)
    {
        if (!empty($terms['categories'])) {
            $category_ids = array();

            foreach ($terms['categories'] as $category_name) {
                $term = term_exists($category_name, 'category');

                if (!$term) {
                    $term = wp_insert_term($category_name, 'category');
                }

                if (!is_wp_error($term)) {
                    $term_id = is_array($term) && !empty($term['term_id']) ? $term['term_id'] : $term;

                    if ($term_id) {
                        $category_ids[] = absint($term_id);
                    }
                }
            }

            if (!empty($category_ids)) {
                wp_set_post_categories($post_id, $category_ids, false);
            }
        }

        if (!empty($terms['tags'])) {
            wp_set_post_tags($post_id, $terms['tags'], false);
        }
    }

    private static function placeholder_values($candidate)
    {
        return array(
            '{{title}}' => $candidate['title'],
            '{{subreddit}}' => $candidate['subreddit'],
            '{{author}}' => $candidate['author'],
            '{{reddit_url}}' => $candidate['reddit_url'],
            '{{source_url}}' => $candidate['source_url'],
            '{{media_url}}' => $candidate['media_url'],
            '{{thumbnail}}' => $candidate['thumbnail'],
            '{{score}}' => (string) $candidate['score'],
            '{{comments}}' => (string) $candidate['comments'],
            '{{created}}' => $candidate['created'],
            '{{excerpt}}' => wp_trim_words(wp_strip_all_tags($candidate['selftext']), 40),
            '{{embed}}' => $candidate['embed'],
        );
    }

    private static function build_dry_run_preview()
    {
        $settings = self::get_settings();
        $rules = self::get_rules();

        foreach ($rules as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }

            $candidates = self::find_candidates($rule);
            if (is_wp_error($candidates) || empty($candidates)) {
                continue;
            }

            $matching_count = count($candidates);
            if (!empty($rule['max_posts_per_run'])) {
                $candidates = array_slice($candidates, 0, absint($rule['max_posts_per_run']));
            }

            $candidate = $candidates[0];
            $template = self::select_template($rule);
            $post_data = self::render_post_data($candidate, $template);
            $terms = self::render_post_terms($candidate, $template);

            return array(
                'rule' => $rule,
                'template' => $template,
                'candidate' => $candidate,
                'post_status' => $rule['post_status'] ? $rule['post_status'] : $settings['default_post_status'],
                'title' => $post_data['title'],
                'content' => $post_data['content'],
                'categories' => $terms['categories'],
                'tags' => $terms['tags'],
                'matching_count' => $matching_count,
                'process_count' => count($candidates),
            );
        }

        return new WP_Error('rtw_preview_empty', __('No enabled rule returned a matching unposted Reddit post.', 'reddit-to-wordpress'));
    }

    private static function was_posted($reddit_id)
    {
        $posted = get_option(self::OPTION_POSTED, array());
        if (is_array($posted) && isset($posted[$reddit_id])) {
            return true;
        }

        $existing = get_posts(
            array(
                'post_type' => 'post',
                'post_status' => 'any',
                'posts_per_page' => 1,
                'fields' => 'ids',
                'no_found_rows' => true,
                'meta_key' => '_rtw_reddit_id',
                'meta_value' => $reddit_id,
            )
        );

        return !empty($existing);
    }

    private static function mark_posted($reddit_id)
    {
        $posted = get_option(self::OPTION_POSTED, array());
        $posted = is_array($posted) ? $posted : array();
        $posted[$reddit_id] = time();

        $cutoff = time() - (366 * DAY_IN_SECONDS);
        foreach ($posted as $id => $timestamp) {
            if (absint($timestamp) < $cutoff) {
                unset($posted[$id]);
            }
        }

        update_option(self::OPTION_POSTED, $posted, false);
    }

    public static function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::get_settings();
        $rules = self::get_rules();
        $templates = self::get_templates();
        $logs = self::get_logs();
        $preview = get_transient('rtw_preview_' . get_current_user_id());

        if (empty($rules)) {
            $rules[] = self::blank_rule();
        }

        ?>
        <div class="wrap rtw-wrap">
            <h1><?php esc_html_e('Reddit To WordPress', 'reddit-to-wordpress'); ?></h1>
            <?php self::render_message(); ?>

            <div class="rtw-status">
                <p><strong><?php esc_html_e('Cron:', 'reddit-to-wordpress'); ?></strong>
                    <?php echo $settings['enabled'] ? esc_html__('enabled', 'reddit-to-wordpress') : esc_html__('disabled', 'reddit-to-wordpress'); ?>
                    <?php $next = wp_next_scheduled(self::CRON_HOOK); ?>
                    <?php if ($next) : ?>
                        <span class="rtw-muted"><?php printf(esc_html__('Next run: %s', 'reddit-to-wordpress'), esc_html(wp_date('Y-m-d H:i:s', $next))); ?></span>
                    <?php endif; ?>
                </p>
                <p><strong><?php esc_html_e('Placeholders:', 'reddit-to-wordpress'); ?></strong>
                    <code>{{title}}</code> <code>{{embed}}</code> <code>{{subreddit}}</code> <code>{{author}}</code> <code>{{reddit_url}}</code> <code>{{source_url}}</code> <code>{{media_url}}</code> <code>{{thumbnail}}</code> <code>{{score}}</code> <code>{{comments}}</code> <code>{{created}}</code> <code>{{excerpt}}</code>
                </p>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('rtw_save_settings'); ?>
                <input type="hidden" name="rtw_action" value="save_settings">

                <h2><?php esc_html_e('Reddit Credentials', 'reddit-to-wordpress'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="client_id"><?php esc_html_e('Client ID', 'reddit-to-wordpress'); ?></label></th>
                        <td><input class="regular-text" type="text" id="client_id" name="client_id" value="<?php echo esc_attr($settings['client_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="client_secret"><?php esc_html_e('Client Secret', 'reddit-to-wordpress'); ?></label></th>
                        <td><input class="regular-text" type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr($settings['client_secret']); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="username"><?php esc_html_e('Username', 'reddit-to-wordpress'); ?></label></th>
                        <td><input class="regular-text" type="text" id="username" name="username" value="<?php echo esc_attr($settings['username']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="password"><?php esc_html_e('Password', 'reddit-to-wordpress'); ?></label></th>
                        <td><input class="regular-text" type="password" id="password" name="password" value="<?php echo esc_attr($settings['password']); ?>" autocomplete="off"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="user_agent"><?php esc_html_e('User Agent', 'reddit-to-wordpress'); ?></label></th>
                        <td>
                            <input class="regular-text" type="text" id="user_agent" name="user_agent" value="<?php echo esc_attr($settings['user_agent']); ?>">
                            <p class="description"><?php esc_html_e('Optional. Reddit requires a descriptive user agent.', 'reddit-to-wordpress'); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Automation', 'reddit-to-wordpress'); ?></h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enabled', 'reddit-to-wordpress'); ?></th>
                        <td><label><input type="checkbox" name="enabled" value="1" <?php checked($settings['enabled']); ?>> <?php esc_html_e('Run subreddit rules through WP-Cron', 'reddit-to-wordpress'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Default post status', 'reddit-to-wordpress'); ?></th>
                        <td>
                            <select name="default_post_status">
                                <option value="draft" <?php selected($settings['default_post_status'], 'draft'); ?>><?php esc_html_e('Draft', 'reddit-to-wordpress'); ?></option>
                                <option value="publish" <?php selected($settings['default_post_status'], 'publish'); ?>><?php esc_html_e('Publish', 'reddit-to-wordpress'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dry run', 'reddit-to-wordpress'); ?></th>
                        <td><label><input type="checkbox" name="dry_run" value="1" <?php checked($settings['dry_run']); ?>> <?php esc_html_e('Log what would be posted without creating WordPress posts', 'reddit-to-wordpress'); ?></label></td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Subreddit Rules', 'reddit-to-wordpress'); ?></h2>
                <table class="widefat striped rtw-table rtw-rules-table">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('On', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Subreddit', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Types', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Method', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Every minutes', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Max posts/run', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Next run', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Status', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Templates', 'reddit-to-wordpress'); ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rules as $index => $rule) : ?>
                        <?php self::render_rule_row($index, $rule, $templates); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button class="button" data-rtw-add-rule><?php esc_html_e('Add rule', 'reddit-to-wordpress'); ?></button></p>

                <h2><?php esc_html_e('Templates', 'reddit-to-wordpress'); ?></h2>
                <table class="widefat striped rtw-table rtw-template-table">
                    <thead>
                    <tr>
                        <th><?php esc_html_e('Name', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Title template', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Content template', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Categories', 'reddit-to-wordpress'); ?></th>
                        <th><?php esc_html_e('Tags', 'reddit-to-wordpress'); ?></th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($templates as $index => $template) : ?>
                        <?php self::render_template_row($index, $template); ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p><button class="button" data-rtw-add-template><?php esc_html_e('Add template', 'reddit-to-wordpress'); ?></button></p>

                <?php submit_button(__('Save settings', 'reddit-to-wordpress')); ?>
            </form>

            <div class="rtw-actions">
                <form method="post">
                    <?php wp_nonce_field('rtw_test_credentials'); ?>
                    <input type="hidden" name="rtw_action" value="test_credentials">
                    <?php submit_button(__('Test Reddit account', 'reddit-to-wordpress'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post">
                    <?php wp_nonce_field('rtw_run_now'); ?>
                    <input type="hidden" name="rtw_action" value="run_now">
                    <?php submit_button(__('Run now', 'reddit-to-wordpress'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post">
                    <?php wp_nonce_field('rtw_dry_run_preview'); ?>
                    <input type="hidden" name="rtw_action" value="dry_run_preview">
                    <?php submit_button(__('Dry run preview', 'reddit-to-wordpress'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post">
                    <?php wp_nonce_field('rtw_clear_logs'); ?>
                    <input type="hidden" name="rtw_action" value="clear_logs">
                    <?php submit_button(__('Clear logs', 'reddit-to-wordpress'), 'secondary', 'submit', false); ?>
                </form>
                <form method="post">
                    <?php wp_nonce_field('rtw_clear_history'); ?>
                    <input type="hidden" name="rtw_action" value="clear_history">
                    <?php submit_button(__('Clear posted history', 'reddit-to-wordpress'), 'secondary', 'submit', false); ?>
                </form>
            </div>

            <?php self::render_preview($preview); ?>
            <?php self::render_logs($logs); ?>
        </div>
        <?php
    }

    private static function render_rule_row($index, $rule, $templates)
    {
        $types = array('image' => 'Image', 'gif' => 'GIF', 'video' => 'Video', 'link' => 'Link', 'text' => 'Text');
        $methods = array('hot' => 'Hot', 'new' => 'New', 'top' => 'Top', 'rising' => 'Rising');
        ?>
        <tr>
            <td>
                <input type="hidden" name="rules[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($rule['id']); ?>">
                <input type="checkbox" name="rules[<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked($rule['enabled']); ?>>
            </td>
            <td><input type="text" name="rules[<?php echo esc_attr($index); ?>][subreddit]" value="<?php echo esc_attr($rule['subreddit']); ?>" placeholder="pics"></td>
            <td class="rtw-checks">
                <?php foreach ($types as $type => $label) : ?>
                    <label><input type="checkbox" name="rules[<?php echo esc_attr($index); ?>][content_types][]" value="<?php echo esc_attr($type); ?>" <?php checked(in_array($type, $rule['content_types'], true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </td>
            <td>
                <select name="rules[<?php echo esc_attr($index); ?>][method]">
                    <?php foreach ($methods as $method => $label) : ?>
                        <option value="<?php echo esc_attr($method); ?>" <?php selected($rule['method'], $method); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="rules[<?php echo esc_attr($index); ?>][top_time_filter]">
                    <?php foreach (array('hour', 'day', 'week', 'month', 'year', 'all') as $filter) : ?>
                        <option value="<?php echo esc_attr($filter); ?>" <?php selected($rule['top_time_filter'], $filter); ?>><?php echo esc_html($filter); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="number" min="15" step="15" name="rules[<?php echo esc_attr($index); ?>][interval_minutes]" value="<?php echo esc_attr($rule['interval_minutes']); ?>"></td>
            <td>
                <input type="number" min="0" max="100" step="1" name="rules[<?php echo esc_attr($index); ?>][max_posts_per_run]" value="<?php echo esc_attr($rule['max_posts_per_run']); ?>">
                <p class="description"><?php esc_html_e('0 means unlimited.', 'reddit-to-wordpress'); ?></p>
            </td>
            <td>
                <?php echo esc_html(self::format_rule_next_run($rule)); ?>
                <?php if (!empty($rule['last_run'])) : ?>
                    <p class="description"><?php printf(esc_html__('Last: %s', 'reddit-to-wordpress'), esc_html(wp_date('Y-m-d H:i:s', absint($rule['last_run'])))); ?></p>
                <?php endif; ?>
            </td>
            <td>
                <select name="rules[<?php echo esc_attr($index); ?>][post_status]">
                    <option value="" <?php selected($rule['post_status'], ''); ?>><?php esc_html_e('Default', 'reddit-to-wordpress'); ?></option>
                    <option value="draft" <?php selected($rule['post_status'], 'draft'); ?>><?php esc_html_e('Draft', 'reddit-to-wordpress'); ?></option>
                    <option value="publish" <?php selected($rule['post_status'], 'publish'); ?>><?php esc_html_e('Publish', 'reddit-to-wordpress'); ?></option>
                </select>
            </td>
            <td class="rtw-checks">
                <?php foreach ($templates as $template) : ?>
                    <label><input type="checkbox" name="rules[<?php echo esc_attr($index); ?>][template_ids][]" value="<?php echo esc_attr($template['id']); ?>" <?php checked(in_array($template['id'], $rule['template_ids'], true)); ?>> <?php echo esc_html($template['name']); ?></label>
                <?php endforeach; ?>
                <p class="description"><?php esc_html_e('No selection means any template can be used.', 'reddit-to-wordpress'); ?></p>
            </td>
            <td><button class="button" data-rtw-remove-row><?php esc_html_e('Remove', 'reddit-to-wordpress'); ?></button></td>
        </tr>
        <?php
    }

    private static function render_template_row($index, $template)
    {
        ?>
        <tr>
            <td>
                <input type="hidden" name="templates[<?php echo esc_attr($index); ?>][id]" value="<?php echo esc_attr($template['id']); ?>">
                <input type="text" name="templates[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($template['name']); ?>">
            </td>
            <td><input type="text" name="templates[<?php echo esc_attr($index); ?>][title_template]" value="<?php echo esc_attr($template['title_template']); ?>"></td>
            <td><textarea name="templates[<?php echo esc_attr($index); ?>][content_template]"><?php echo esc_textarea($template['content_template']); ?></textarea></td>
            <td>
                <textarea name="templates[<?php echo esc_attr($index); ?>][categories_template]"><?php echo esc_textarea($template['categories_template']); ?></textarea>
                <p class="description"><?php esc_html_e('Comma or line separated.', 'reddit-to-wordpress'); ?></p>
            </td>
            <td>
                <textarea name="templates[<?php echo esc_attr($index); ?>][tags_template]"><?php echo esc_textarea($template['tags_template']); ?></textarea>
                <p class="description"><?php esc_html_e('Comma or line separated.', 'reddit-to-wordpress'); ?></p>
            </td>
            <td><button class="button" data-rtw-remove-row><?php esc_html_e('Remove', 'reddit-to-wordpress'); ?></button></td>
        </tr>
        <?php
    }

    private static function render_preview($preview)
    {
        if (!$preview) {
            return;
        }

        if (is_wp_error($preview)) {
            echo '<div class="notice notice-error"><p>' . esc_html($preview->get_error_message()) . '</p></div>';
            return;
        }

        $matching_count = isset($preview['matching_count']) ? absint($preview['matching_count']) : 1;
        $process_count = isset($preview['process_count']) ? absint($preview['process_count']) : $matching_count;
        $categories = !empty($preview['categories']) && is_array($preview['categories']) ? $preview['categories'] : array();
        $tags = !empty($preview['tags']) && is_array($preview['tags']) ? $preview['tags'] : array();

        ?>
        <h2><?php esc_html_e('Dry Run Preview', 'reddit-to-wordpress'); ?></h2>
        <div class="rtw-preview">
            <p><strong><?php echo esc_html($preview['title']); ?></strong></p>
            <p class="rtw-muted">
                <?php printf(esc_html__('/r/%1$s via template "%2$s" as %3$s', 'reddit-to-wordpress'), esc_html($preview['rule']['subreddit']), esc_html($preview['template']['name']), esc_html($preview['post_status'])); ?>
            </p>
            <p class="rtw-muted">
                <?php printf(esc_html__('Matching unposted posts: %1$d | Processed this run: %2$d', 'reddit-to-wordpress'), $matching_count, $process_count); ?>
            </p>
            <p class="rtw-muted">
                <?php printf(esc_html__('Categories: %1$s | Tags: %2$s', 'reddit-to-wordpress'), esc_html(implode(', ', $categories)), esc_html(implode(', ', $tags))); ?>
            </p>
            <div class="rtw-preview-frame"><?php echo wp_kses(do_shortcode($preview['content']), self::allowed_template_html()); ?></div>
        </div>
        <?php
    }

    private static function render_logs($logs)
    {
        ?>
        <h2><?php esc_html_e('Logs', 'reddit-to-wordpress'); ?></h2>
        <table class="widefat striped rtw-table">
            <thead><tr><th><?php esc_html_e('Time', 'reddit-to-wordpress'); ?></th><th><?php esc_html_e('Message', 'reddit-to-wordpress'); ?></th></tr></thead>
            <tbody>
            <?php if (empty($logs)) : ?>
                <tr><td colspan="2"><?php esc_html_e('No logs yet.', 'reddit-to-wordpress'); ?></td></tr>
            <?php else : ?>
                <?php foreach ($logs as $log) : ?>
                    <tr><td><?php echo esc_html($log['time']); ?></td><td><?php echo esc_html($log['message']); ?></td></tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private static function render_message()
    {
        if (empty($_GET['rtw_message'])) {
            return;
        }

        $messages = array(
            'settings_saved' => __('Settings saved.', 'reddit-to-wordpress'),
            'credentials_ok' => __('Reddit account test succeeded.', 'reddit-to-wordpress'),
            'credentials_failed' => __('Reddit account test failed. Check the logs.', 'reddit-to-wordpress'),
            'run_complete' => __('Manual run completed. Check the logs.', 'reddit-to-wordpress'),
            'preview_ready' => __('Dry run preview is ready.', 'reddit-to-wordpress'),
            'preview_failed' => __('Dry run preview failed. Check the logs or preview error.', 'reddit-to-wordpress'),
            'logs_cleared' => __('Logs cleared.', 'reddit-to-wordpress'),
            'history_cleared' => __('Posted history cleared.', 'reddit-to-wordpress'),
        );

        $key = sanitize_key(wp_unslash($_GET['rtw_message']));
        if (!isset($messages[$key])) {
            return;
        }

        printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$key]));
    }

    private static function get_logs()
    {
        $logs = get_option(self::OPTION_LOGS, array());
        return is_array($logs) ? $logs : array();
    }

    private static function log($message)
    {
        $logs = self::get_logs();
        array_unshift(
            $logs,
            array(
                'time' => current_time('mysql'),
                'message' => sanitize_text_field($message),
            )
        );

        $logs = array_slice($logs, 0, self::LOG_LIMIT);
        update_option(self::OPTION_LOGS, $logs, false);
    }

    private static function shorten_for_log($value)
    {
        $value = wp_strip_all_tags((string) $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, 300) : substr($value, 0, 300);
    }
}
