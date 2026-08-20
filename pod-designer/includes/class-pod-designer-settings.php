<?php

if (!defined('ABSPATH')) {
    exit;
}

class POD_Designer_Settings
{
    const TEMPLATES_PAGE = 'pod-designer';
    const SETTINGS_PAGE = 'pod-designer-settings';

    public static function init()
    {
        add_action('admin_menu', array(__CLASS__, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_post_pod_save_templates', array(__CLASS__, 'handle_save_templates'));
        add_action('admin_post_pod_save_settings', array(__CLASS__, 'handle_save_settings'));
    }

    public static function add_admin_menu()
    {
        add_menu_page(
            __('POD Designer', 'pod-designer'),
            __('POD Designer', 'pod-designer'),
            'manage_options',
            self::TEMPLATES_PAGE,
            array(__CLASS__, 'render_templates_page'),
            'dashicons-art',
            56
        );

        add_submenu_page(
            self::TEMPLATES_PAGE,
            __('Product templates', 'pod-designer'),
            __('Product templates', 'pod-designer'),
            'manage_options',
            self::TEMPLATES_PAGE,
            array(__CLASS__, 'render_templates_page')
        );

        add_submenu_page(
            self::TEMPLATES_PAGE,
            __('Settings', 'pod-designer'),
            __('Settings', 'pod-designer'),
            'manage_options',
            self::SETTINGS_PAGE,
            array(__CLASS__, 'render_settings_page')
        );
    }

    public static function enqueue_admin_assets($hook)
    {
        if (!in_array($hook, array('toplevel_page_' . self::TEMPLATES_PAGE, 'pod-designer_page_' . self::SETTINGS_PAGE), true)) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style('pod-designer-admin', POD_PLUGIN_URL . 'assets/admin.css', array(), POD_VERSION);
        wp_enqueue_script('pod-designer-admin', POD_PLUGIN_URL . 'assets/admin.js', array('jquery'), POD_VERSION, true);
        $builtins = array();

        foreach (POD_Designer::builtin_mockups() as $file => $mockup) {
            $mockup['url'] = POD_Designer::builtin_url($file);
            $builtins[$file] = $mockup;
        }

        wp_localize_script('pod-designer-admin', 'podAdmin', array(
            'chooseImage' => __('Select mockup image', 'pod-designer'),
            'useImage' => __('Use image', 'pod-designer'),
            'confirmDeleteTemplate' => __('Delete this template? This cannot be undone once you save.', 'pod-designer'),
            'confirmDeleteRow' => __('Delete this row?', 'pod-designer'),
            'noImage' => __('Pick a built-in mockup for this view first, or upload your own.', 'pod-designer'),
            'copySuffix' => __('copy', 'pod-designer'),
            'builtins' => $builtins,
        ));
    }

    /* -----------------------------------------------------------------
     * Templates page
     * ----------------------------------------------------------------- */

    public static function render_templates_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $templates = POD_Designer::get_templates();
        ?>
        <div class="wrap pod-admin">
            <h1><?php esc_html_e('Product templates', 'pod-designer'); ?></h1>

            <?php if (isset($_GET['pod_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Templates saved.', 'pod-designer'); ?></p></div>
            <?php endif; ?>

            <p class="pod-admin__intro">
                <?php esc_html_e('Each template is one product (a t-shirt, a mug and so on). Views are the printable sides, and the print area marks the spot on the mockup image where the customer\'s artwork goes.', 'pod-designer'); ?>
                <br />
                <?php esc_html_e('Embed in a page:', 'pod-designer'); ?>
                <code>[pod_designer template="tshirt"]</code>
            </p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="pod-templates-form">
                <input type="hidden" name="action" value="pod_save_templates" />
                <?php wp_nonce_field('pod_save_templates'); ?>

                <div id="pod-template-list">
                    <?php foreach ($templates as $index => $template) : ?>
                        <?php self::render_template_card($index, $template); ?>
                    <?php endforeach; ?>
                </div>

                <p>
                    <button type="button" class="button" id="pod-add-template"><?php esc_html_e('+ New template', 'pod-designer'); ?></button>
                    <button type="submit" class="button button-primary"><?php esc_html_e('Save templates', 'pod-designer'); ?></button>
                </p>
            </form>

            <?php // <template> is used instead of a script tag because these protos nest. ?>
            <template id="pod-template-proto">
                <?php self::render_template_card('__TINDEX__', self::blank_template()); ?>
            </template>
        </div>
        <?php
    }

    private static function blank_template()
    {
        return POD_Designer::normalize_template(array(
            'id' => '',
            'name' => __('New product', 'pod-designer'),
            'slug' => '',
            'type' => 'flat',
            'enabled' => 1,
            'views' => array(
                array(
                    'key' => 'front',
                    'label' => __('Front', 'pod-designer'),
                    'builtin' => 'tshirt-front.svg',
                    'width_mm' => 297,
                    'height_mm' => 420,
                    'area' => array('x' => 32.75, 'y' => 23.33, 'w' => 34.5, 'h' => 43.33),
                ),
            ),
            'colors' => array(
                array('id' => '', 'name' => __('White', 'pod-designer'), 'hex' => '#ffffff', 'images' => array()),
            ),
        ));
    }

    private static function render_template_card($t, $template)
    {
        $base = 'pod_templates[' . $t . ']';
        ?>
        <div class="pod-card" data-tindex="<?php echo esc_attr($t); ?>">
            <div class="pod-card__head">
                <h2 class="pod-card__title"><?php echo esc_html($template['name']); ?></h2>
                <div class="pod-card__actions">
                    <button type="button" class="button-link pod-toggle-card"><?php esc_html_e('Expand/collapse', 'pod-designer'); ?></button>
                    <button type="button" class="button-link pod-duplicate-template"><?php esc_html_e('Duplicate', 'pod-designer'); ?></button>
                    <button type="button" class="button-link pod-delete-template"><?php esc_html_e('Delete template', 'pod-designer'); ?></button>
                </div>
            </div>

            <div class="pod-card__body">
                <input type="hidden" name="<?php echo esc_attr($base); ?>[id]" value="<?php echo esc_attr($template['id']); ?>" />

                <table class="form-table">
                    <tr>
                        <th><label><?php esc_html_e('Name', 'pod-designer'); ?></label></th>
                        <td><input type="text" class="regular-text pod-template-name" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($template['name']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Slug (shortcode)', 'pod-designer'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo esc_attr($base); ?>[slug]" value="<?php echo esc_attr($template['slug']); ?>" />
                            <p class="description"><?php esc_html_e('Generated from the name when left empty.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label><?php esc_html_e('Type', 'pod-designer'); ?></label></th>
                        <td>
                            <select name="<?php echo esc_attr($base); ?>[type]" class="pod-template-type">
                                <option value="flat" <?php selected($template['type'], 'flat'); ?>><?php esc_html_e('Flat product (t-shirt, tote bag, poster)', 'pod-designer'); ?></option>
                                <option value="mug" <?php selected($template['type'], 'mug'); ?>><?php esc_html_e('Mug (unwrapped cylinder)', 'pod-designer'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Status', 'pod-designer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr($base); ?>[enabled]" value="1" <?php checked($template['enabled'], 1); ?> /> <?php esc_html_e('Enabled', 'pod-designer'); ?></label>
                        </td>
                    </tr>
                    <tr class="pod-mug-only" <?php echo 'mug' === $template['type'] ? '' : 'style="display:none"'; ?>>
                        <th><?php esc_html_e('3D preview', 'pod-designer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="<?php echo esc_attr($base); ?>[allow_3d]" value="1" <?php checked($template['allow_3d'], 1); ?> /> <?php esc_html_e('Enable the rotatable 3D mug preview', 'pod-designer'); ?></label>
                        </td>
                    </tr>
                    <tr class="pod-mug-only" <?php echo 'mug' === $template['type'] ? '' : 'style="display:none"'; ?>>
                        <th><?php esc_html_e('Mug dimensions', 'pod-designer'); ?></th>
                        <td>
                            <label>
                                <?php esc_html_e('Diameter (mm)', 'pod-designer'); ?>
                                <input type="number" step="0.5" min="20" max="300" class="small-text" name="<?php echo esc_attr($base); ?>[mug_diameter_mm]" value="<?php echo esc_attr($template['mug_diameter_mm']); ?>" />
                            </label>
                            <label>
                                <?php esc_html_e('Height (mm)', 'pod-designer'); ?>
                                <input type="number" step="0.5" min="20" max="400" class="small-text" name="<?php echo esc_attr($base); ?>[mug_height_mm]" value="<?php echo esc_attr($template['mug_height_mm']); ?>" />
                            </label>
                            <p class="description">
                                <?php esc_html_e('The 3D preview is calculated from these. A 330 ml mug is typically 82 mm across and 95 mm tall, so its circumference is 258 mm, of which the 185 mm print covers 72%; the rest is where the handle sits.', 'pod-designer'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr class="pod-mug-only" <?php echo 'mug' === $template['type'] ? '' : 'style="display:none"'; ?>>
                        <th><?php esc_html_e('Cylinder surface on the mockup (%)', 'pod-designer'); ?></th>
                        <td>
                            <?php self::render_area_inputs($base . '[body]', $template['body']); ?>
                            <p class="description"><?php esc_html_e('The part of the mockup image that is the body of the mug (without the handle). This is what the 3D preview wraps around.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                </table>

                <h3><?php esc_html_e('Views', 'pod-designer'); ?></h3>
                <div class="pod-rows pod-view-rows">
                    <?php foreach ($template['views'] as $v => $view) : ?>
                        <?php self::render_view_row($t, $v, $view); ?>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button pod-add-view"><?php esc_html_e('+ Add view', 'pod-designer'); ?></button></p>
                <template class="pod-view-proto">
                    <?php
                    $blank_view = POD_Designer::normalize_template(array(
                        'views' => array(array(
                            'key' => '',
                            'label' => __('New view', 'pod-designer'),
                            'builtin' => 'tshirt-front.svg',
                            'width_mm' => 297,
                            'height_mm' => 420,
                            'area' => array('x' => 32.75, 'y' => 23.33, 'w' => 34.5, 'h' => 43.33),
                        )),
                    ));
                    self::render_view_row($t, '__VINDEX__', $blank_view['views'][0]);
                    ?>
                </template>

                <h3><?php esc_html_e('Colors', 'pod-designer'); ?></h3>
                <p class="description"><?php esc_html_e('The hex color tints the mockup in multiply mode. If you need an exact photo for a given color, upload your own mockup image per view – that overrides the tinting.', 'pod-designer'); ?></p>
                <div class="pod-rows pod-color-rows">
                    <?php foreach ($template['colors'] as $c => $color) : ?>
                        <?php self::render_color_row($t, $c, $color, $template['views']); ?>
                    <?php endforeach; ?>
                </div>
                <p><button type="button" class="button pod-add-color"><?php esc_html_e('+ Add color', 'pod-designer'); ?></button></p>
                <template class="pod-color-proto">
                    <?php
                    self::render_color_row($t, '__CINDEX__', array(
                        'id' => '',
                        'name' => __('New color', 'pod-designer'),
                        'hex' => '#ffffff',
                        'images' => array(),
                    ), $template['views']);
                    ?>
                </template>
            </div>
        </div>
        <?php
    }

    private static function render_area_inputs($base, $area)
    {
        $labels = array(
            'x' => __('Left', 'pod-designer'),
            'y' => __('Top', 'pod-designer'),
            'w' => __('Width', 'pod-designer'),
            'h' => __('Height', 'pod-designer'),
        );
        ?>
        <span class="pod-area-inputs">
            <?php foreach ($labels as $key => $label) : ?>
                <label>
                    <?php echo esc_html($label); ?>
                    <input type="number" step="0.01" min="0" max="100" class="small-text pod-area-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($base); ?>[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($area[$key]); ?>" />%
                </label>
            <?php endforeach; ?>
        </span>
        <?php
    }

    private static function render_view_row($t, $v, $view)
    {
        $base = 'pod_templates[' . $t . '][views][' . $v . ']';
        $image_url = POD_Designer::get_view_image_url($view);
        ?>
        <div class="pod-row pod-view-row" data-vindex="<?php echo esc_attr($v); ?>">
            <div class="pod-row__media">
                <div class="pod-media-preview" data-image="<?php echo esc_url($image_url); ?>">
                    <?php if ($image_url) : ?>
                        <img src="<?php echo esc_url($image_url); ?>" alt="" />
                        <span class="pod-area-marker"
                              style="left:<?php echo esc_attr($view['area']['x']); ?>%;top:<?php echo esc_attr($view['area']['y']); ?>%;width:<?php echo esc_attr($view['area']['w']); ?>%;height:<?php echo esc_attr($view['area']['h']); ?>%"></span>
                    <?php endif; ?>
                </div>
                <input type="hidden" class="pod-image-id" name="<?php echo esc_attr($base); ?>[image_id]" value="<?php echo esc_attr($view['image_id']); ?>" />
                <p>
                    <label class="pod-builtin-label">
                        <?php esc_html_e('Built-in mockup', 'pod-designer'); ?><br />
                        <select class="pod-builtin-select" name="<?php echo esc_attr($base); ?>[builtin]">
                            <option value=""><?php esc_html_e('— None / own image —', 'pod-designer'); ?></option>
                            <?php foreach (POD_Designer::builtin_mockups() as $file => $mockup) : ?>
                                <option value="<?php echo esc_attr($file); ?>" <?php selected($view['builtin'], $file); ?>>
                                    <?php echo esc_html($mockup['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <button type="button" class="button button-small pod-pick-image"><?php esc_html_e('Own image', 'pod-designer'); ?></button>
                    <button type="button" class="button button-small pod-clear-image"><?php esc_html_e('Delete', 'pod-designer'); ?></button>
                </p>
            </div>

            <div class="pod-row__fields">
                <p>
                    <label><?php esc_html_e('Label', 'pod-designer'); ?><br />
                        <input type="text" name="<?php echo esc_attr($base); ?>[label]" value="<?php echo esc_attr($view['label']); ?>" />
                    </label>
                    <label><?php esc_html_e('Key', 'pod-designer'); ?><br />
                        <input type="text" class="pod-view-key" name="<?php echo esc_attr($base); ?>[key]" value="<?php echo esc_attr($view['key']); ?>" />
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e('Printable width (mm)', 'pod-designer'); ?><br />
                        <input type="number" step="0.1" min="1" name="<?php echo esc_attr($base); ?>[width_mm]" value="<?php echo esc_attr($view['width_mm']); ?>" />
                    </label>
                    <label><?php esc_html_e('Printable height (mm)', 'pod-designer'); ?><br />
                        <input type="number" step="0.1" min="1" name="<?php echo esc_attr($base); ?>[height_mm]" value="<?php echo esc_attr($view['height_mm']); ?>" />
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e('Non-printable band at the top (%)', 'pod-designer'); ?><br />
                        <input type="number" step="0.5" min="0" max="40" name="<?php echo esc_attr($base); ?>[safe_top]" value="<?php echo esc_attr($view['safe_top']); ?>" />
                    </label>
                    <label><?php esc_html_e('Non-printable band at the bottom (%)', 'pod-designer'); ?><br />
                        <input type="number" step="0.5" min="0" max="40" name="<?php echo esc_attr($base); ?>[safe_bottom]" value="<?php echo esc_attr($view['safe_bottom']); ?>" />
                    </label>
                    <span class="description">
                        <?php esc_html_e('Bands at the top and the bottom of the print area where the print does not come out well (typically 5-5% on a mug). The designer marks them with hatching and cuts them out of the print-ready file.', 'pod-designer'); ?>
                    </span>
                </p>
                <p class="pod-area-line">
                    <strong><?php esc_html_e('Print area on the mockup', 'pod-designer'); ?></strong><br />
                    <?php self::render_area_inputs($base . '[area]', $view['area']); ?>
                    <button type="button" class="button button-small pod-draw-area"><?php esc_html_e('Select on the image', 'pod-designer'); ?></button>
                </p>
                <p><button type="button" class="button-link pod-delete-row"><?php esc_html_e('Delete view', 'pod-designer'); ?></button></p>
            </div>
        </div>
        <?php
    }

    private static function render_color_row($t, $c, $color, $views)
    {
        $base = 'pod_templates[' . $t . '][colors][' . $c . ']';
        ?>
        <div class="pod-row pod-color-row">
            <input type="hidden" name="<?php echo esc_attr($base); ?>[id]" value="<?php echo esc_attr($color['id']); ?>" />
            <p class="pod-color-main">
                <label><?php esc_html_e('Name', 'pod-designer'); ?><br />
                    <input type="text" name="<?php echo esc_attr($base); ?>[name]" value="<?php echo esc_attr($color['name']); ?>" />
                </label>
                <label><?php esc_html_e('Color', 'pod-designer'); ?><br />
                    <input type="color" name="<?php echo esc_attr($base); ?>[hex]" value="<?php echo esc_attr($color['hex']); ?>" />
                </label>
                <button type="button" class="button-link pod-delete-row"><?php esc_html_e('Delete color', 'pod-designer'); ?></button>
            </p>

            <?php if (!empty($views)) : ?>
                <div class="pod-color-images">
                    <?php foreach ($views as $view) : ?>
                        <?php
                        $attachment_id = isset($color['images'][$view['key']]) ? (int) $color['images'][$view['key']] : 0;
                        $url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'medium') : '';
                        ?>
                        <div class="pod-color-image">
                            <span class="pod-color-image__label"><?php echo esc_html($view['label']); ?></span>
                            <div class="pod-media-preview pod-media-preview--small">
                                <?php if ($url) : ?>
                                    <img src="<?php echo esc_url($url); ?>" alt="" />
                                <?php endif; ?>
                            </div>
                            <input type="hidden" class="pod-image-id" name="<?php echo esc_attr($base); ?>[images][<?php echo esc_attr($view['key']); ?>]" value="<?php echo esc_attr($attachment_id); ?>" />
                            <button type="button" class="button button-small pod-pick-image"><?php esc_html_e('Image', 'pod-designer'); ?></button>
                            <button type="button" class="button button-small pod-clear-image"><?php esc_html_e('X', 'pod-designer'); ?></button>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save_templates()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'pod-designer'));
        }

        check_admin_referer('pod_save_templates');

        $raw = isset($_POST['pod_templates']) ? wp_unslash($_POST['pod_templates']) : array();
        $templates = array();

        foreach ((array) $raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (empty($item['id'])) {
                $item['id'] = wp_generate_uuid4();
            }

            $item['name'] = sanitize_text_field(isset($item['name']) ? $item['name'] : '');
            $item['enabled'] = empty($item['enabled']) ? 0 : 1;
            $item['allow_3d'] = empty($item['allow_3d']) ? 0 : 1;

            if (isset($item['views']) && is_array($item['views'])) {
                $item['views'] = array_values($item['views']);

                foreach ($item['views'] as $index => $view) {
                    $item['views'][$index]['label'] = sanitize_text_field(isset($view['label']) ? $view['label'] : '');
                }
            }

            if (isset($item['colors']) && is_array($item['colors'])) {
                $item['colors'] = array_values($item['colors']);

                foreach ($item['colors'] as $index => $color) {
                    if (empty($color['id'])) {
                        $item['colors'][$index]['id'] = wp_generate_uuid4();
                    }

                    $item['colors'][$index]['name'] = sanitize_text_field(isset($color['name']) ? $color['name'] : '');
                }
            }

            $templates[] = POD_Designer::normalize_template($item);
        }

        POD_Designer::save_templates($templates);

        wp_safe_redirect(add_query_arg(array('page' => self::TEMPLATES_PAGE, 'pod_saved' => 1), admin_url('admin.php')));
        exit;
    }

    /* -----------------------------------------------------------------
     * Settings page
     * ----------------------------------------------------------------- */

    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = POD_Designer::get_settings();
        ?>
        <div class="wrap pod-admin">
            <h1><?php esc_html_e('POD Designer settings', 'pod-designer'); ?></h1>

            <?php if (isset($_GET['pod_saved'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'pod-designer'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pod_save_settings" />
                <?php wp_nonce_field('pod_save_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="pod-email"><?php esc_html_e('Notification email', 'pod-designer'); ?></label></th>
                        <td>
                            <input type="email" id="pod-email" class="regular-text" name="notification_email" value="<?php echo esc_attr($settings['notification_email']); ?>" />
                            <p class="description"><?php esc_html_e('Notifications about shortcode (non-WooCommerce) submissions are sent here.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Guest uploads', 'pod-designer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="allow_guest_uploads" value="1" <?php checked($settings['allow_guest_uploads'], 1); ?> /> <?php esc_html_e('Allow uploading images and submitting designs without logging in', 'pod-designer'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pod-max-upload"><?php esc_html_e('Max upload (MB)', 'pod-designer'); ?></label></th>
                        <td><input type="number" id="pod-max-upload" min="1" max="64" name="max_upload_mb" value="<?php echo esc_attr($settings['max_upload_mb']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="pod-min-dpi"><?php esc_html_e('Minimum DPI warning', 'pod-designer'); ?></label></th>
                        <td>
                            <input type="number" id="pod-min-dpi" min="30" max="1200" name="min_dpi" value="<?php echo esc_attr($settings['min_dpi']); ?>" />
                            <p class="description"><?php esc_html_e('Below this the designer warns the customer that the image may look blurry.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pod-export-dpi"><?php esc_html_e('Print-ready file DPI', 'pod-designer'); ?></label></th>
                        <td>
                            <input type="number" id="pod-export-dpi" min="72" max="600" name="export_dpi" value="<?php echo esc_attr($settings['export_dpi']); ?>" />
                            <p class="description"><?php esc_html_e('300 DPI is the usual print value. A higher value means a bigger file and slower saving.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pod-preview-width"><?php esc_html_e('Preview image width (px)', 'pod-designer'); ?></label></th>
                        <td><input type="number" id="pod-preview-width" min="400" max="2400" name="preview_width" value="<?php echo esc_attr($settings['preview_width']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="pod-layout"><?php esc_html_e('Designer width', 'pod-designer'); ?></label></th>
                        <td>
                            <select id="pod-layout" name="layout">
                                <option value="container" <?php selected($settings['layout'], 'container'); ?>><?php esc_html_e('The theme\'s content width', 'pod-designer'); ?></option>
                                <option value="wide" <?php selected($settings['layout'], 'wide'); ?>><?php esc_html_e('Wide (max. 1500 px, extends past the content)', 'pod-designer'); ?></option>
                                <option value="full" <?php selected($settings['layout'], 'full'); ?>><?php esc_html_e('Full screen width', 'pod-designer'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Can be overridden per shortcode: [pod_designer template="mug" layout="full"]', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pod-stage-height"><?php esc_html_e('Workspace height (vh)', 'pod-designer'); ?></label></th>
                        <td>
                            <input type="number" id="pod-stage-height" min="30" max="95" name="stage_height" value="<?php echo esc_attr($settings['stage_height']); ?>" />
                            <p class="description"><?php esc_html_e('The product image takes up at most this much of the browser window height. 74 = 74% of the window.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Features', 'pod-designer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="enable_text" value="1" <?php checked($settings['enable_text'], 1); ?> /> <?php esc_html_e('Enable text layers', 'pod-designer'); ?></label><br />
                            <label><input type="checkbox" name="enable_3d" value="1" <?php checked($settings['enable_3d'], 1); ?> /> <?php esc_html_e('Enable the 3D mug preview globally', 'pod-designer'); ?></label><br />
                            <label><input type="checkbox" name="require_phone" value="1" <?php checked($settings['require_phone'], 1); ?> /> <?php esc_html_e('Require a phone number on the submission form', 'pod-designer'); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pod-fonts"><?php esc_html_e('Fonts', 'pod-designer'); ?></label></th>
                        <td>
                            <textarea id="pod-fonts" name="fonts" rows="6" class="large-text code"><?php echo esc_textarea($settings['fonts']); ?></textarea>
                            <p class="description"><?php esc_html_e('One per line: Display name|CSS font-family. Only list fonts that are available on the visitor\'s machine or in the theme.', 'pod-designer'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="pod-submit-label"><?php esc_html_e('Submit button label', 'pod-designer'); ?></label></th>
                        <td><input type="text" id="pod-submit-label" class="regular-text" name="submit_label" value="<?php echo esc_attr($settings['submit_label']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="pod-cart-label"><?php esc_html_e('Cart button label', 'pod-designer'); ?></label></th>
                        <td><input type="text" id="pod-cart-label" class="regular-text" name="add_to_cart_label" value="<?php echo esc_attr($settings['add_to_cart_label']); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="pod-success"><?php esc_html_e('Successful submission message', 'pod-designer'); ?></label></th>
                        <td><textarea id="pod-success" name="success_message" rows="3" class="large-text"><?php echo esc_textarea($settings['success_message']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Uninstall', 'pod-designer'); ?></th>
                        <td>
                            <label><input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked($settings['delete_data_on_uninstall'], 1); ?> /> <?php esc_html_e('Delete designs and settings when the plugin is deleted', 'pod-designer'); ?></label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Save settings', 'pod-designer')); ?>
            </form>

            <h2><?php esc_html_e('WooCommerce', 'pod-designer'); ?></h2>
            <?php if (POD_Designer::is_woocommerce_active()) : ?>
                <p><?php esc_html_e('WooCommerce is active. On a product you can pick the designer template under Product data → General.', 'pod-designer'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('WooCommerce is not active, so the designer runs in standalone (shortcode) mode: submitted designs show up under the Designs menu.', 'pod-designer'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_save_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'pod-designer'));
        }

        check_admin_referer('pod_save_settings');

        $settings = POD_Designer::get_settings();

        $settings['notification_email'] = sanitize_email(isset($_POST['notification_email']) ? wp_unslash($_POST['notification_email']) : '');
        $settings['max_upload_mb'] = isset($_POST['max_upload_mb']) ? (int) $_POST['max_upload_mb'] : 12;
        $settings['min_dpi'] = isset($_POST['min_dpi']) ? (int) $_POST['min_dpi'] : 150;
        $settings['export_dpi'] = isset($_POST['export_dpi']) ? (int) $_POST['export_dpi'] : 300;
        $settings['preview_width'] = isset($_POST['preview_width']) ? (int) $_POST['preview_width'] : 1000;
        $settings['stage_height'] = isset($_POST['stage_height']) ? (int) $_POST['stage_height'] : 74;
        $settings['layout'] = isset($_POST['layout']) ? sanitize_key(wp_unslash($_POST['layout'])) : 'wide';
        $settings['fonts'] = sanitize_textarea_field(isset($_POST['fonts']) ? wp_unslash($_POST['fonts']) : '');
        $settings['submit_label'] = sanitize_text_field(isset($_POST['submit_label']) ? wp_unslash($_POST['submit_label']) : '');
        $settings['add_to_cart_label'] = sanitize_text_field(isset($_POST['add_to_cart_label']) ? wp_unslash($_POST['add_to_cart_label']) : '');
        $settings['success_message'] = sanitize_textarea_field(isset($_POST['success_message']) ? wp_unslash($_POST['success_message']) : '');

        foreach (array('allow_guest_uploads', 'enable_text', 'enable_3d', 'require_phone', 'delete_data_on_uninstall') as $flag) {
            $settings[$flag] = empty($_POST[$flag]) ? 0 : 1;
        }

        POD_Designer::save_settings($settings);

        wp_safe_redirect(add_query_arg(array('page' => self::SETTINGS_PAGE, 'pod_saved' => 1), admin_url('admin.php')));
        exit;
    }
}
