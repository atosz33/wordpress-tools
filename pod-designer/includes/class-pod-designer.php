<?php

if (!defined('ABSPATH')) {
    exit;
}

class POD_Designer
{
    const OPTION_SETTINGS = 'pod_settings';
    const OPTION_TEMPLATES = 'pod_templates';
    const NONCE_ACTION = 'pod_designer_nonce';
    const MENU_SLUG = 'pod-designer';
    const UPLOAD_THROTTLE_LIMIT = 80;

    private static $assets_enqueued = false;
    private static $instance_count = 0;

    public static function init()
    {
        // Priority 0 so the bundled translations are in place before anything
        // else on init calls __(). WordPress 6.7 and later complain when a text
        // domain is loaded any earlier than init.
        add_action('init', array(__CLASS__, 'load_textdomain'), 0);
        add_action('init', array(__CLASS__, 'register_shortcodes'));

        // Registration happens on init, not on wp_enqueue_scripts: block themes
        // render the template (and therefore any shortcode in it) before
        // wp_head runs, and wp_localize_script() silently drops its data when
        // the handle is not registered yet.
        add_action('init', array(__CLASS__, 'register_assets'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'maybe_enqueue_for_shortcode'));

        add_action('wp_ajax_pod_upload_image', array(__CLASS__, 'ajax_upload_image'));
        add_action('wp_ajax_nopriv_pod_upload_image', array(__CLASS__, 'ajax_upload_image'));
        add_action('wp_ajax_pod_save_design', array(__CLASS__, 'ajax_save_design'));
        add_action('wp_ajax_nopriv_pod_save_design', array(__CLASS__, 'ajax_save_design'));

        POD_Designer_Settings::init();
        POD_Designer_Designs::init();

        // WooCommerce may load after this plugin, so the check has to wait until
        // every plugin file has been included.
        add_action('plugins_loaded', array(__CLASS__, 'maybe_init_woocommerce'));
    }

    public static function load_textdomain()
    {
        load_plugin_textdomain('pod-designer', false, dirname(plugin_basename(POD_PLUGIN_FILE)) . '/languages');
    }

    public static function maybe_init_woocommerce()
    {
        if (self::is_woocommerce_active()) {
            POD_Designer_WooCommerce::init();
        }
    }

    public static function activate()
    {
        if (false === get_option(self::OPTION_SETTINGS)) {
            add_option(self::OPTION_SETTINGS, self::default_settings(), '', false);
        }

        if (false === get_option(self::OPTION_TEMPLATES)) {
            add_option(self::OPTION_TEMPLATES, self::default_templates(), '', false);
        }

        POD_Designer_Designs::register_post_type();
        flush_rewrite_rules();
    }

    public static function deactivate()
    {
        flush_rewrite_rules();
    }

    public static function uninstall()
    {
        $settings = get_option(self::OPTION_SETTINGS, array());

        if (!empty($settings['delete_data_on_uninstall'])) {
            $designs = get_posts(array(
                'post_type' => POD_Designer_Designs::POST_TYPE,
                'post_status' => 'any',
                'numberposts' => -1,
                'fields' => 'ids',
            ));

            foreach ($designs as $design_id) {
                wp_delete_post($design_id, true);
            }

            delete_option(self::OPTION_SETTINGS);
            delete_option(self::OPTION_TEMPLATES);
        }
    }

    public static function is_woocommerce_active()
    {
        return class_exists('WooCommerce');
    }

    /* -----------------------------------------------------------------
     * Settings
     * ----------------------------------------------------------------- */

    public static function default_settings()
    {
        return array(
            'notification_email' => get_option('admin_email'),
            'allow_guest_uploads' => 1,
            'max_upload_mb' => 12,
            'min_dpi' => 150,
            'export_dpi' => 300,
            'preview_width' => 1000,
            'enable_text' => 1,
            'enable_3d' => 1,
            'layout' => 'wide',
            'stage_height' => 74,
            'fonts' => "Arial|Arial, Helvetica, sans-serif\nGeorgia|Georgia, 'Times New Roman', serif\nImpact|Impact, Haettenschweiler, sans-serif\nCourier|'Courier New', Courier, monospace\nVerdana|Verdana, Geneva, sans-serif",
            'submit_label' => __('Send order', 'pod-designer'),
            'add_to_cart_label' => __('Add to cart', 'pod-designer'),
            'success_message' => __('Thank you! We have received your design and will get in touch shortly.', 'pod-designer'),
            'require_phone' => 0,
            'delete_data_on_uninstall' => 0,
        );
    }

    public static function get_settings()
    {
        $settings = get_option(self::OPTION_SETTINGS, array());
        $settings = wp_parse_args(is_array($settings) ? $settings : array(), self::default_settings());

        $settings['max_upload_mb'] = max(1, min(64, (int) $settings['max_upload_mb']));
        $settings['min_dpi'] = max(30, min(1200, (int) $settings['min_dpi']));
        $settings['export_dpi'] = max(72, min(600, (int) $settings['export_dpi']));
        $settings['preview_width'] = max(400, min(2400, (int) $settings['preview_width']));
        $settings['stage_height'] = max(30, min(95, (int) $settings['stage_height']));
        $settings['layout'] = in_array($settings['layout'], self::layouts(), true) ? $settings['layout'] : 'wide';

        foreach (array('allow_guest_uploads', 'enable_text', 'enable_3d', 'require_phone', 'delete_data_on_uninstall') as $flag) {
            $settings[$flag] = empty($settings[$flag]) ? 0 : 1;
        }

        return $settings;
    }

    public static function layouts()
    {
        return array('container', 'wide', 'full');
    }

    public static function save_settings($settings)
    {
        update_option(self::OPTION_SETTINGS, $settings, false);
    }

    public static function get_fonts()
    {
        $settings = self::get_settings();
        $fonts = array();

        foreach (preg_split('/\r\n|\r|\n/', (string) $settings['fonts']) as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 2));
            $label = $parts[0];
            $stack = isset($parts[1]) && '' !== $parts[1] ? $parts[1] : $parts[0];

            $fonts[] = array(
                'label' => $label,
                'stack' => $stack,
            );
        }

        if (empty($fonts)) {
            $fonts[] = array('label' => 'Arial', 'stack' => 'Arial, Helvetica, sans-serif');
        }

        return $fonts;
    }

    /* -----------------------------------------------------------------
     * Templates
     * ----------------------------------------------------------------- */

    /**
     * Mockups shipped with the plugin. They are presets rather than plain
     * images: each one knows the printable size, the print area on the artwork
     * and the bands the press cannot reach, so a new template can be built on
     * one of them without measuring anything.
     */
    public static function builtin_mockups()
    {
        return array(
            'tshirt-front.svg' => array(
                'label' => __('T-shirt – front (297 × 420 mm)', 'pod-designer'),
                'width_mm' => 297,
                'height_mm' => 420,
                'area' => array('x' => 32.75, 'y' => 23.33, 'w' => 34.5, 'h' => 43.33),
                'safe_top' => 0,
                'safe_bottom' => 0,
            ),
            'tshirt-back.svg' => array(
                'label' => __('T-shirt – back (297 × 420 mm)', 'pod-designer'),
                'width_mm' => 297,
                'height_mm' => 420,
                'area' => array('x' => 32.75, 'y' => 23.33, 'w' => 34.5, 'h' => 43.33),
                'safe_top' => 0,
                'safe_bottom' => 0,
            ),
            'mug-flat.svg' => array(
                'label' => __('Mug – unwrapped (185 × 90 mm)', 'pod-designer'),
                'width_mm' => 185,
                'height_mm' => 90,
                'area' => array('x' => 18.75, 'y' => 13.21, 'w' => 62.5, 'h' => 73.58),
                'safe_top' => 5,
                'safe_bottom' => 5,
                // The cylinder wall on this artwork, used by the 3D preview.
                'body' => array('x' => 18.75, 'y' => 13.21, 'w' => 62.5, 'h' => 73.58),
            ),
        );
    }

    public static function builtin_url($file)
    {
        $mockups = self::builtin_mockups();

        return isset($mockups[$file]) ? POD_PLUGIN_URL . 'assets/mockups/' . $file : '';
    }

    public static function default_templates()
    {
        return array(
            array(
                'id' => wp_generate_uuid4(),
                'name' => __('T-shirt', 'pod-designer'),
                'slug' => 'tshirt',
                'type' => 'flat',
                'enabled' => 1,
                'allow_3d' => 0,
                'body' => array('x' => 0, 'y' => 0, 'w' => 100, 'h' => 100),
                'views' => array(
                    array(
                        'key' => 'front',
                        'label' => __('Front', 'pod-designer'),
                        'image_id' => 0,
                        'builtin' => 'tshirt-front.svg',
                        'width_mm' => 297,
                        'height_mm' => 420,
                        'area' => array('x' => 32.75, 'y' => 23.33, 'w' => 34.5, 'h' => 43.33),
                    ),
                    array(
                        'key' => 'back',
                        'label' => __('Back', 'pod-designer'),
                        'image_id' => 0,
                        'builtin' => 'tshirt-back.svg',
                        'width_mm' => 297,
                        'height_mm' => 420,
                        'area' => array('x' => 32.75, 'y' => 23.33, 'w' => 34.5, 'h' => 43.33),
                    ),
                ),
                'colors' => array(
                    array('id' => wp_generate_uuid4(), 'name' => __('White', 'pod-designer'), 'hex' => '#ffffff', 'images' => array()),
                    array('id' => wp_generate_uuid4(), 'name' => __('Black', 'pod-designer'), 'hex' => '#1b1b1f', 'images' => array()),
                    array('id' => wp_generate_uuid4(), 'name' => __('Navy', 'pod-designer'), 'hex' => '#22314f', 'images' => array()),
                    array('id' => wp_generate_uuid4(), 'name' => __('Red', 'pod-designer'), 'hex' => '#b93030', 'images' => array()),
                    array('id' => wp_generate_uuid4(), 'name' => __('Grey', 'pod-designer'), 'hex' => '#9c9ca4', 'images' => array()),
                ),
            ),
            array(
                'id' => wp_generate_uuid4(),
                'name' => __('Mug', 'pod-designer'),
                'slug' => 'mug',
                'type' => 'mug',
                'enabled' => 1,
                'allow_3d' => 1,
                'body' => array('x' => 18.75, 'y' => 13.21, 'w' => 62.5, 'h' => 73.58),
                'mug_diameter_mm' => 82,
                'mug_height_mm' => 95,
                'views' => array(
                    array(
                        'key' => 'wrap',
                        'label' => __('Wrap print', 'pod-designer'),
                        'image_id' => 0,
                        'builtin' => 'mug-flat.svg',
                        'width_mm' => 185,
                        'height_mm' => 90,
                        'area' => array('x' => 18.75, 'y' => 13.21, 'w' => 62.5, 'h' => 73.58),
                        'safe_top' => 5,
                        'safe_bottom' => 5,
                    ),
                ),
                'colors' => array(
                    array('id' => wp_generate_uuid4(), 'name' => __('White', 'pod-designer'), 'hex' => '#ffffff', 'images' => array()),
                    array('id' => wp_generate_uuid4(), 'name' => __('Black', 'pod-designer'), 'hex' => '#26262b', 'images' => array()),
                ),
            ),
        );
    }

    public static function get_templates()
    {
        $templates = get_option(self::OPTION_TEMPLATES, array());

        if (!is_array($templates)) {
            return array();
        }

        $normalized = array();

        foreach ($templates as $template) {
            $normalized[] = self::normalize_template($template);
        }

        return $normalized;
    }

    public static function save_templates($templates)
    {
        update_option(self::OPTION_TEMPLATES, array_values($templates), false);
    }

    public static function normalize_template($template)
    {
        $template = is_array($template) ? $template : array();

        $template = wp_parse_args($template, array(
            'id' => wp_generate_uuid4(),
            'name' => __('Product', 'pod-designer'),
            'slug' => '',
            'type' => 'flat',
            'enabled' => 1,
            'allow_3d' => 0,
            'body' => array('x' => 0, 'y' => 0, 'w' => 100, 'h' => 100),
            'mug_diameter_mm' => 82,
            'mug_height_mm' => 95,
            'views' => array(),
            'colors' => array(),
        ));

        $template['type'] = 'mug' === $template['type'] ? 'mug' : 'flat';
        $template['enabled'] = empty($template['enabled']) ? 0 : 1;
        $template['allow_3d'] = ('mug' === $template['type'] && !empty($template['allow_3d'])) ? 1 : 0;
        $template['slug'] = $template['slug'] ? sanitize_title($template['slug']) : sanitize_title($template['name']);
        $template['body'] = self::normalize_area($template['body']);

        // Physical dimensions of the mug body. The printable wrap is narrower
        // than the full circumference, because the handle needs its own space.
        $template['mug_diameter_mm'] = max(20, min(300, (float) $template['mug_diameter_mm']));
        $template['mug_height_mm'] = max(20, min(400, (float) $template['mug_height_mm']));

        $views = array();
        $builtins = self::builtin_mockups();

        foreach ((array) $template['views'] as $index => $view) {
            $view = wp_parse_args(is_array($view) ? $view : array(), array(
                'key' => 'view-' . ($index + 1),
                'label' => sprintf(__('View %d', 'pod-designer'), $index + 1),
                'image_id' => 0,
                'builtin' => '',
                'width_mm' => 200,
                'height_mm' => 200,
                'area' => array('x' => 25, 'y' => 25, 'w' => 50, 'h' => 50),
                'safe_top' => 0,
                'safe_bottom' => 0,
            ));

            $view['key'] = sanitize_key($view['key']);

            if ('' === $view['key']) {
                $view['key'] = 'view-' . ($index + 1);
            }

            $view['image_id'] = (int) $view['image_id'];
            $view['builtin'] = isset($builtins[$view['builtin']]) ? $view['builtin'] : '';
            $view['width_mm'] = max(1, (float) $view['width_mm']);
            $view['height_mm'] = max(1, (float) $view['height_mm']);
            $view['area'] = self::normalize_area($view['area']);

            // Bands at the top and bottom of the print area that the press
            // cannot reproduce well, for example the rolled edges of a mug.
            $view['safe_top'] = max(0, min(40, round((float) $view['safe_top'], 2)));
            $view['safe_bottom'] = max(0, min(40, round((float) $view['safe_bottom'], 2)));

            if ($view['safe_top'] + $view['safe_bottom'] > 80) {
                $view['safe_bottom'] = 80 - $view['safe_top'];
            }

            $views[] = $view;
        }

        $template['views'] = $views;

        $colors = array();

        foreach ((array) $template['colors'] as $color) {
            $color = wp_parse_args(is_array($color) ? $color : array(), array(
                'id' => wp_generate_uuid4(),
                'name' => __('Color', 'pod-designer'),
                'hex' => '#ffffff',
                'images' => array(),
            ));

            $hex = sanitize_hex_color($color['hex']);
            $color['hex'] = $hex ? $hex : '#ffffff';
            $color['images'] = is_array($color['images']) ? array_map('intval', $color['images']) : array();
            $colors[] = $color;
        }

        $template['colors'] = $colors;

        return $template;
    }

    private static function normalize_area($area)
    {
        $area = wp_parse_args(is_array($area) ? $area : array(), array(
            'x' => 0,
            'y' => 0,
            'w' => 100,
            'h' => 100,
        ));

        foreach (array('x', 'y', 'w', 'h') as $key) {
            $area[$key] = max(0, min(100, round((float) $area[$key], 3)));
        }

        return $area;
    }

    public static function get_template($identifier)
    {
        if ('' === $identifier || null === $identifier) {
            return null;
        }

        foreach (self::get_templates() as $template) {
            if ($template['id'] === $identifier || $template['slug'] === $identifier) {
                return $template;
            }
        }

        return null;
    }

    public static function get_view_image_url($view, $color = null)
    {
        if ($color && !empty($color['images'][$view['key']])) {
            $url = wp_get_attachment_url((int) $color['images'][$view['key']]);

            if ($url) {
                return $url;
            }
        }

        if (!empty($view['image_id'])) {
            $url = wp_get_attachment_url((int) $view['image_id']);

            if ($url) {
                return $url;
            }
        }

        if (!empty($view['builtin'])) {
            return self::builtin_url($view['builtin']);
        }

        return '';
    }

    /* -----------------------------------------------------------------
     * Frontend
     * ----------------------------------------------------------------- */

    public static function register_shortcodes()
    {
        add_shortcode('pod_designer', array(__CLASS__, 'shortcode'));
    }

    public static function register_assets()
    {
        wp_register_style('pod-designer', POD_PLUGIN_URL . 'assets/designer.css', array(), POD_VERSION);
        wp_register_script('pod-designer', POD_PLUGIN_URL . 'assets/designer.js', array(), POD_VERSION, true);
    }

    /**
     * Loads the stylesheet in the head when the shortcode is already visible in
     * the post content, instead of letting it fall through to the footer.
     */
    public static function maybe_enqueue_for_shortcode()
    {
        if (!is_singular()) {
            return;
        }

        $post = get_post();

        if ($post && has_shortcode($post->post_content, 'pod_designer')) {
            self::enqueue_designer_assets();
        }
    }

    public static function enqueue_designer_assets()
    {
        if (self::$assets_enqueued) {
            return;
        }

        self::$assets_enqueued = true;

        wp_enqueue_style('pod-designer');
        wp_enqueue_script('pod-designer');

        wp_localize_script('pod-designer', 'podDesignerGlobals', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => self::script_strings(),
        ));
    }

    /**
     * Labels the designer script renders. They live here rather than in the
     * JavaScript so that they go through the plugin's text domain; designer.js
     * keeps an English copy only as a fallback.
     */
    public static function script_strings()
    {
        return array(
            'upload' => __('Upload image', 'pod-designer'),
            'addText' => __('Add text', 'pod-designer'),
            'colors' => __('Color', 'pod-designer'),
            'views' => __('Print side', 'pod-designer'),
            'layers' => __('Layers', 'pod-designer'),
            'empty' => __('There is nothing on this side yet.', 'pod-designer'),
            'deleteLayer' => __('Delete', 'pod-designer'),
            'forward' => __('Forward', 'pod-designer'),
            'backward' => __('Backward', 'pod-designer'),
            'center' => __('Center', 'pod-designer'),
            'fit' => __('Fit', 'pod-designer'),
            'size' => __('Size', 'pod-designer'),
            'rotation' => __('Rotation', 'pod-designer'),
            'text' => __('Text', 'pod-designer'),
            'font' => __('Font', 'pod-designer'),
            'fontSize' => __('Font size', 'pod-designer'),
            'textColor' => __('Text color', 'pod-designer'),
            'bold' => __('Bold', 'pod-designer'),
            'noPrint' => __('Not printable', 'pod-designer'),
            'view2d' => __('2D view', 'pod-designer'),
            'view3d' => __('3D view', 'pod-designer'),
            'spin' => __('Auto rotate', 'pod-designer'),
            'name' => __('Name', 'pod-designer'),
            'email' => __('Email', 'pod-designer'),
            'phone' => __('Phone number', 'pod-designer'),
            'quantity' => __('Quantity', 'pod-designer'),
            'note' => __('Note', 'pod-designer'),
            'saving' => __('Saving…', 'pod-designer'),
            'lowDpi' => __('Low resolution – the print may look blurry.', 'pod-designer'),
            'dropHint' => __('Drop an image here, or click to browse.', 'pod-designer'),
            'imageLoadFailed' => __('The image could not be loaded: ', 'pod-designer'),
            'imageLayer' => __('Image', 'pod-designer'),
            'textDefault' => __('Text', 'pod-designer'),
            'sideLeft' => __('left', 'pod-designer'),
            'sideRight' => __('right', 'pod-designer'),
            'sideTop' => __('top', 'pod-designer'),
            'sideBottom' => __('bottom', 'pod-designer'),
            'outsideArea' => __('Outside the printable area (%s).', 'pod-designer'),
            'issueOne' => __('One item is outside the printable area. Move it back before you submit.', 'pod-designer'),
            'issueMany' => __('%s items are outside the printable area. Move them back before you submit.', 'pod-designer'),
            'wooHint' => __('The design is saved together with the cart item.', 'pod-designer'),
            'selectHint' => __('Select an item to edit it.', 'pod-designer'),
            'fileTooLarge' => __('The file is too large (max. %s MB).', 'pod-designer'),
            'uploading' => __('Uploading image…', 'pod-designer'),
            'uploadFailed' => __('Upload failed.', 'pod-designer'),
            'needContent' => __('Upload at least one image or add some text.', 'pod-designer'),
            'saveFailed' => __('Saving failed.', 'pod-designer'),
            'enterName' => __('Enter your name.', 'pod-designer'),
            'enterEmail' => __('Enter a valid email address.', 'pod-designer'),
            'enterPhone' => __('Enter your phone number.', 'pod-designer'),
            'resetRotation' => __('Back to 0°', 'pod-designer'),
        );
    }

    public static function shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'template' => '',
            'mode' => 'standalone',
            'layout' => '',
        ), $atts, 'pod_designer');

        $template = self::get_template($atts['template']);

        if (!$template) {
            $templates = self::get_templates();

            foreach ($templates as $candidate) {
                if (!empty($candidate['enabled'])) {
                    $template = $candidate;
                    break;
                }
            }
        }

        if (!$template || empty($template['enabled'])) {
            return '<p class="pod-notice">' . esc_html__('No designer template is available.', 'pod-designer') . '</p>';
        }

        return self::render_designer($template, array(
            'mode' => 'woo' === $atts['mode'] ? 'woo' : 'standalone',
            'layout' => $atts['layout'],
        ));
    }

    public static function build_config($template, $args = array())
    {
        $settings = self::get_settings();

        $args = wp_parse_args($args, array(
            'mode' => 'standalone',
            'product_id' => 0,
        ));

        $views = array();

        foreach ($template['views'] as $view) {
            $color_images = array();

            foreach ($template['colors'] as $color) {
                if (!empty($color['images'][$view['key']])) {
                    $url = wp_get_attachment_url((int) $color['images'][$view['key']]);

                    if ($url) {
                        $color_images[$color['id']] = $url;
                    }
                }
            }

            $views[] = array(
                'key' => $view['key'],
                'label' => $view['label'],
                'image' => self::get_view_image_url($view),
                'colorImages' => $color_images,
                'widthMm' => (float) $view['width_mm'],
                'heightMm' => (float) $view['height_mm'],
                'area' => $view['area'],
                'safeTop' => (float) $view['safe_top'],
                'safeBottom' => (float) $view['safe_bottom'],
            );
        }

        $colors = array();

        foreach ($template['colors'] as $color) {
            $colors[] = array(
                'id' => $color['id'],
                'name' => $color['name'],
                'hex' => $color['hex'],
            );
        }

        return array(
            'mode' => $args['mode'],
            'productId' => (int) $args['product_id'],
            'template' => array(
                'id' => $template['id'],
                'name' => $template['name'],
                'slug' => $template['slug'],
                'type' => $template['type'],
                'allow3d' => (int) $template['allow_3d'] && $settings['enable_3d'] ? 1 : 0,
                'body' => $template['body'],
                'mugDiameterMm' => (float) $template['mug_diameter_mm'],
                'mugHeightMm' => (float) $template['mug_height_mm'],
                'views' => $views,
                'colors' => $colors,
            ),
            'settings' => array(
                'maxUploadBytes' => $settings['max_upload_mb'] * 1024 * 1024,
                'minDpi' => $settings['min_dpi'],
                'exportDpi' => $settings['export_dpi'],
                'previewWidth' => $settings['preview_width'],
                'stageHeight' => $settings['stage_height'],
                'enableText' => $settings['enable_text'],
                'requirePhone' => $settings['require_phone'],
                'submitLabel' => $settings['submit_label'],
                'addToCartLabel' => $settings['add_to_cart_label'],
                'successMessage' => $settings['success_message'],
                'fonts' => self::get_fonts(),
            ),
        );
    }

    public static function render_designer($template, $args = array())
    {
        self::enqueue_designer_assets();
        self::$instance_count++;

        $settings = self::get_settings();
        $config = self::build_config($template, $args);
        $id = 'pod-designer-' . self::$instance_count;

        $layout = isset($args['layout']) ? $args['layout'] : '';
        $layout = in_array($layout, self::layouts(), true) ? $layout : $settings['layout'];

        $classes = 'pod-designer pod-designer--' . $layout;
        $style = '--pod-stage-height:' . (int) $settings['stage_height'] . 'vh';

        ob_start();
        ?>
        <div class="<?php echo esc_attr($classes); ?>" id="<?php echo esc_attr($id); ?>" style="<?php echo esc_attr($style); ?>" data-pod-config="<?php echo esc_attr(wp_json_encode($config)); ?>">
            <div class="pod-designer__loading"><?php esc_html_e('Loading the designer…', 'pod-designer'); ?></div>
            <noscript>
                <p><?php esc_html_e('You need to enable JavaScript to use the designer.', 'pod-designer'); ?></p>
            </noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    /* -----------------------------------------------------------------
     * AJAX
     * ----------------------------------------------------------------- */

    private static function verify_request()
    {
        if (!check_ajax_referer(self::NONCE_ACTION, 'nonce', false)) {
            wp_send_json_error(array('message' => __('Your session expired, please reload the page.', 'pod-designer')), 403);
        }

        $settings = self::get_settings();

        if (!is_user_logged_in() && empty($settings['allow_guest_uploads'])) {
            wp_send_json_error(array('message' => __('You need to log in to use the designer.', 'pod-designer')), 403);
        }

        return $settings;
    }

    private static function throttle_check()
    {
        if (current_user_can('upload_files')) {
            return;
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        $key = 'pod_throttle_' . md5($ip);
        $count = (int) get_transient($key);

        if ($count >= self::UPLOAD_THROTTLE_LIMIT) {
            wp_send_json_error(array('message' => __('Too many uploads in a short time. Please try again later.', 'pod-designer')), 429);
        }

        set_transient($key, $count + 1, HOUR_IN_SECONDS);
    }

    private static function allowed_mimes()
    {
        return array(
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        );
    }

    private static function sideload_file($file_key, $title, $max_bytes = 0)
    {
        if (empty($_FILES[$file_key]) || !isset($_FILES[$file_key]['tmp_name'])) {
            return new WP_Error('pod_missing_file', __('Missing file.', 'pod-designer'));
        }

        $file = $_FILES[$file_key];

        if (!empty($file['error'])) {
            return new WP_Error('pod_upload_error', __('The file could not be uploaded.', 'pod-designer'));
        }

        if ($max_bytes > 0 && (int) $file['size'] > $max_bytes) {
            return new WP_Error('pod_too_large', __('The file is too large.', 'pod-designer'));
        }

        if (!function_exists('getimagesize') || false === @getimagesize($file['tmp_name'])) {
            return new WP_Error('pod_not_image', __('You can only upload image files.', 'pod-designer'));
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $overrides = array(
            'test_form' => false,
            'mimes' => self::allowed_mimes(),
        );

        $uploaded = wp_handle_upload($file, $overrides);

        if (isset($uploaded['error'])) {
            return new WP_Error('pod_upload_error', $uploaded['error']);
        }

        $attachment_id = wp_insert_attachment(array(
            'post_mime_type' => $uploaded['type'],
            'post_title' => sanitize_text_field($title),
            'post_content' => '',
            'post_status' => 'inherit',
        ), $uploaded['file']);

        if (is_wp_error($attachment_id) || !$attachment_id) {
            return new WP_Error('pod_attachment_error', __('The file could not be saved.', 'pod-designer'));
        }

        wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $uploaded['file']));
        update_post_meta($attachment_id, '_pod_designer_file', 1);

        return $attachment_id;
    }

    public static function ajax_upload_image()
    {
        $settings = self::verify_request();
        self::throttle_check();

        $max_bytes = $settings['max_upload_mb'] * 1024 * 1024;
        $attachment_id = self::sideload_file('file', 'POD upload', $max_bytes);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => $attachment_id->get_error_message()), 400);
        }

        $meta = wp_get_attachment_metadata($attachment_id);

        wp_send_json_success(array(
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'width' => isset($meta['width']) ? (int) $meta['width'] : 0,
            'height' => isset($meta['height']) ? (int) $meta['height'] : 0,
        ));
    }

    public static function ajax_save_design()
    {
        $settings = self::verify_request();
        self::throttle_check();

        $raw = isset($_POST['design']) ? wp_unslash($_POST['design']) : '';
        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            wp_send_json_error(array('message' => __('Invalid data.', 'pod-designer')), 400);
        }

        $template = self::get_template(isset($payload['template_id']) ? sanitize_text_field($payload['template_id']) : '');

        if (!$template) {
            wp_send_json_error(array('message' => __('Unknown product template.', 'pod-designer')), 400);
        }

        $design = self::sanitize_design($payload, $template);

        if (empty($design['views'])) {
            wp_send_json_error(array('message' => __('The design is empty.', 'pod-designer')), 400);
        }

        $has_layers = false;

        foreach ($design['views'] as $view) {
            if (!empty($view['layers'])) {
                $has_layers = true;
                break;
            }
        }

        if (!$has_layers) {
            wp_send_json_error(array('message' => __('Upload at least one image or add some text.', 'pod-designer')), 400);
        }

        if ('standalone' === $design['mode']) {
            if (!is_email($design['customer']['email'])) {
                wp_send_json_error(array('message' => __('Enter a valid email address.', 'pod-designer')), 400);
            }

            if ('' === $design['customer']['name']) {
                wp_send_json_error(array('message' => __('Enter your name.', 'pod-designer')), 400);
            }

            if (!empty($settings['require_phone']) && '' === $design['customer']['phone']) {
                wp_send_json_error(array('message' => __('Enter your phone number.', 'pod-designer')), 400);
            }
        }

        $max_bytes = max($settings['max_upload_mb'], 24) * 1024 * 1024;

        foreach ($design['views'] as $index => $view) {
            $preview_id = self::sideload_file('preview_' . $view['key'], sprintf(__('%1$s – preview (%2$s)', 'pod-designer'), $template['name'], $view['label']), $max_bytes);
            $print_id = self::sideload_file('print_' . $view['key'], sprintf(__('%1$s – print-ready (%2$s)', 'pod-designer'), $template['name'], $view['label']), $max_bytes);

            $design['views'][$index]['preview_id'] = is_wp_error($preview_id) ? 0 : $preview_id;
            $design['views'][$index]['print_id'] = is_wp_error($print_id) ? 0 : $print_id;
        }

        $design_id = POD_Designer_Designs::create_design($design, $template);

        if (is_wp_error($design_id)) {
            wp_send_json_error(array('message' => $design_id->get_error_message()), 500);
        }

        if ('woo' !== $design['mode']) {
            POD_Designer_Designs::notify_admin($design_id);
        }

        wp_send_json_success(array(
            'design_id' => $design_id,
            'message' => $settings['success_message'],
        ));
    }

    private static function sanitize_design($payload, $template)
    {
        $color = null;
        $color_id = isset($payload['color_id']) ? sanitize_text_field($payload['color_id']) : '';

        foreach ($template['colors'] as $candidate) {
            if ($candidate['id'] === $color_id) {
                $color = $candidate;
                break;
            }
        }

        if (!$color && !empty($template['colors'])) {
            $color = $template['colors'][0];
        }

        $views_by_key = array();

        foreach ($template['views'] as $view) {
            $views_by_key[$view['key']] = $view;
        }

        $views = array();

        foreach ((array) (isset($payload['views']) ? $payload['views'] : array()) as $view) {
            $key = isset($view['key']) ? sanitize_key($view['key']) : '';

            if (!isset($views_by_key[$key])) {
                continue;
            }

            $template_view = $views_by_key[$key];
            $layers = array();

            foreach ((array) (isset($view['layers']) ? $view['layers'] : array()) as $layer) {
                $sanitized = self::sanitize_layer($layer, $template_view);

                if ($sanitized) {
                    $layers[] = $sanitized;
                }
            }

            $views[] = array(
                'key' => $key,
                'label' => $template_view['label'],
                'width_mm' => (float) $template_view['width_mm'],
                'height_mm' => (float) $template_view['height_mm'],
                'print_dpi' => isset($view['print_dpi']) ? max(0, (int) $view['print_dpi']) : 0,
                'layers' => $layers,
                'preview_id' => 0,
                'print_id' => 0,
            );
        }

        $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : array();

        return array(
            'mode' => (isset($payload['mode']) && 'woo' === $payload['mode']) ? 'woo' : 'standalone',
            'product_id' => isset($payload['product_id']) ? (int) $payload['product_id'] : 0,
            'template_id' => $template['id'],
            'template_name' => $template['name'],
            'template_type' => $template['type'],
            'color' => $color ? array('id' => $color['id'], 'name' => $color['name'], 'hex' => $color['hex']) : null,
            'views' => $views,
            'customer' => array(
                'name' => isset($customer['name']) ? sanitize_text_field($customer['name']) : '',
                'email' => isset($customer['email']) ? sanitize_email($customer['email']) : '',
                'phone' => isset($customer['phone']) ? sanitize_text_field($customer['phone']) : '',
                'quantity' => isset($customer['quantity']) ? max(1, (int) $customer['quantity']) : 1,
                'note' => isset($customer['note']) ? sanitize_textarea_field($customer['note']) : '',
            ),
        );
    }

    private static function sanitize_layer($layer, $view)
    {
        if (!is_array($layer)) {
            return null;
        }

        $type = isset($layer['type']) && 'text' === $layer['type'] ? 'text' : 'image';

        $common = array(
            'type' => $type,
            'x' => round((float) (isset($layer['x']) ? $layer['x'] : 0), 2),
            'y' => round((float) (isset($layer['y']) ? $layer['y'] : 0), 2),
            'w' => round(max(1, (float) (isset($layer['w']) ? $layer['w'] : 10)), 2),
            'h' => round(max(1, (float) (isset($layer['h']) ? $layer['h'] : 10)), 2),
            'rotation' => round((float) (isset($layer['rotation']) ? $layer['rotation'] : 0), 2),
        );

        if ('text' === $type) {
            $text = isset($layer['text']) ? sanitize_textarea_field($layer['text']) : '';

            if ('' === trim($text)) {
                return null;
            }

            $hex = sanitize_hex_color(isset($layer['color']) ? $layer['color'] : '#000000');

            return array_merge($common, array(
                'text' => $text,
                'font' => isset($layer['font']) ? sanitize_text_field($layer['font']) : 'Arial',
                'font_size' => round(max(1, (float) (isset($layer['font_size']) ? $layer['font_size'] : 10)), 2),
                'color' => $hex ? $hex : '#000000',
                'weight' => (isset($layer['weight']) && 'bold' === $layer['weight']) ? 'bold' : 'normal',
                'align' => in_array(isset($layer['align']) ? $layer['align'] : '', array('left', 'center', 'right'), true) ? $layer['align'] : 'center',
            ));
        }

        $attachment_id = isset($layer['attachment_id']) ? (int) $layer['attachment_id'] : 0;

        if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
            return null;
        }

        $meta = wp_get_attachment_metadata($attachment_id);

        return array_merge($common, array(
            'attachment_id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'natural_w' => isset($meta['width']) ? (int) $meta['width'] : 0,
            'natural_h' => isset($meta['height']) ? (int) $meta['height'] : 0,
            'dpi' => isset($meta['width']) && $common['w'] > 0 ? round(((int) $meta['width']) / ($common['w'] / 25.4)) : 0,
            'view_width_mm' => (float) $view['width_mm'],
        ));
    }
}
