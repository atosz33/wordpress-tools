<?php

if (!defined('ABSPATH')) {
    exit;
}

class POD_Designer_Designs
{
    const POST_TYPE = 'pod_design';
    const META_DESIGN = '_pod_design';
    const META_STATE = '_pod_state';
    const META_ORDER = '_pod_order_id';

    public static function init()
    {
        add_action('init', array(__CLASS__, 'register_post_type'));
        add_action('add_meta_boxes', array(__CLASS__, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array(__CLASS__, 'save_state'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array(__CLASS__, 'columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array(__CLASS__, 'column_content'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_post_pod_print_file', array(__CLASS__, 'download_print_file'));
    }

    /**
     * The stored print file is transparent, which is what most presses want.
     * The flattened variant is rendered on request instead of being uploaded as
     * a second file: it keeps the submission small and works for designs that
     * were saved before this existed.
     */
    public static function print_file_url($design_id, $view_key, $background)
    {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action' => 'pod_print_file',
                    'design' => (int) $design_id,
                    'view' => rawurlencode($view_key),
                    'bg' => $background,
                ),
                admin_url('admin-post.php')
            ),
            'pod_print_file_' . (int) $design_id
        );
    }

    public static function download_print_file()
    {
        $design_id = isset($_GET['design']) ? (int) $_GET['design'] : 0;

        check_admin_referer('pod_print_file_' . $design_id);

        if (!$design_id || !current_user_can('edit_post', $design_id)) {
            wp_die(esc_html__('You do not have permission to do this.', 'pod-designer'), '', array('response' => 403));
        }

        $design = self::get_design($design_id);
        $view_key = isset($_GET['view']) ? sanitize_key(wp_unslash($_GET['view'])) : '';
        $view = null;

        foreach (($design ? $design['views'] : array()) as $candidate) {
            if ($candidate['key'] === $view_key) {
                $view = $candidate;
                break;
            }
        }

        if (!$view || empty($view['print_id'])) {
            wp_die(esc_html__('No such print-ready file.', 'pod-designer'), '', array('response' => 404));
        }

        $path = get_attached_file((int) $view['print_id']);

        if (!$path || !file_exists($path)) {
            wp_die(esc_html__('The print-ready file could not be found.', 'pod-designer'), '', array('response' => 404));
        }

        $background = (isset($_GET['bg']) && 'product' === $_GET['bg']) ? 'product' : 'white';
        $hex = '#ffffff';

        if ('product' === $background && !empty($design['color']['hex'])) {
            $hex = $design['color']['hex'];
        }

        $flattened = self::flatten_png($path, $hex);

        if (!$flattened) {
            wp_die(
                esc_html__('Burning the background in requires the PHP GD extension. Download the transparent file instead.', 'pod-designer'),
                '',
                array('response' => 500)
            );
        }

        $name = sprintf('design-%d-%s-%s.png', $design_id, $view_key, ltrim($hex, '#'));

        nocache_headers();
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($flattened));

        echo $flattened; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    private static function flatten_png($path, $hex)
    {
        if (!function_exists('imagecreatefrompng')) {
            return '';
        }

        $source = @imagecreatefrompng($path);

        if (!$source) {
            return '';
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);

        list($r, $g, $b) = sscanf($hex, '#%02x%02x%02x');
        imagefilledrectangle($canvas, 0, 0, $width, $height, imagecolorallocate($canvas, (int) $r, (int) $g, (int) $b));

        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        ob_start();
        imagepng($canvas, null, 6);
        $data = ob_get_clean();
        imagedestroy($canvas);

        return $data;
    }

    public static function states()
    {
        return array(
            'new' => __('New', 'pod-designer'),
            'in_progress' => __('In production', 'pod-designer'),
            'done' => __('Done', 'pod-designer'),
            'cancelled' => __('Cancelled', 'pod-designer'),
        );
    }

    public static function register_post_type()
    {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Designs', 'pod-designer'),
                'singular_name' => __('Design', 'pod-designer'),
                'menu_name' => __('Designs', 'pod-designer'),
                'search_items' => __('Search designs', 'pod-designer'),
                'not_found' => __('No designs found.', 'pod-designer'),
                'not_found_in_trash' => __('No designs found in the trash.', 'pod-designer'),
                'edit_item' => __('View design', 'pod-designer'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => POD_Designer::MENU_SLUG,
            'capability_type' => 'post',
            'capabilities' => array(
                'create_posts' => 'do_not_allow',
            ),
            'map_meta_cap' => true,
            'supports' => array('title'),
            'has_archive' => false,
            'rewrite' => false,
            'query_var' => false,
            'menu_icon' => 'dashicons-art',
        ));
    }

    public static function enqueue_admin_assets($hook)
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && self::POST_TYPE === $screen->post_type) {
            wp_enqueue_style('pod-designer-admin', POD_PLUGIN_URL . 'assets/admin.css', array(), POD_VERSION);
        }
    }

    /* -----------------------------------------------------------------
     * Storage
     * ----------------------------------------------------------------- */

    public static function create_design($design, $template)
    {
        $customer_label = '' !== $design['customer']['name'] ? $design['customer']['name'] : __('Guest', 'pod-designer');
        $color_label = $design['color'] ? $design['color']['name'] : '—';

        $post_id = wp_insert_post(array(
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => sprintf('%s – %s – %s', $template['name'], $color_label, $customer_label),
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => sprintf('#%d – %s – %s – %s', $post_id, $template['name'], $color_label, $customer_label),
        ));

        update_post_meta($post_id, self::META_DESIGN, $design);
        update_post_meta($post_id, self::META_STATE, 'new');
        update_post_meta($post_id, '_pod_template_id', $design['template_id']);
        update_post_meta($post_id, '_pod_customer_email', $design['customer']['email']);

        foreach ($design['views'] as $view) {
            foreach (array('preview_id', 'print_id') as $key) {
                if (!empty($view[$key])) {
                    wp_update_post(array(
                        'ID' => (int) $view[$key],
                        'post_parent' => $post_id,
                    ));
                }
            }
        }

        return $post_id;
    }

    public static function get_design($post_id)
    {
        $design = get_post_meta($post_id, self::META_DESIGN, true);

        return is_array($design) ? $design : null;
    }

    public static function attach_to_order($design_id, $order_id, $order_number = '')
    {
        update_post_meta($design_id, self::META_ORDER, (int) $order_id);

        if ('' !== $order_number) {
            update_post_meta($design_id, '_pod_order_number', sanitize_text_field($order_number));
        }
    }

    public static function is_attached_to_order($design_id)
    {
        return (int) get_post_meta($design_id, self::META_ORDER, true) > 0;
    }

    public static function get_preview_attachment_id($design_id)
    {
        $design = self::get_design($design_id);

        if (!$design) {
            return 0;
        }

        foreach ($design['views'] as $view) {
            if (!empty($view['preview_id'])) {
                return (int) $view['preview_id'];
            }
        }

        return 0;
    }

    public static function get_preview_url($design_id, $size = 'medium')
    {
        $attachment_id = self::get_preview_attachment_id($design_id);

        if (!$attachment_id) {
            return '';
        }

        $url = wp_get_attachment_image_url($attachment_id, $size);

        return $url ? $url : '';
    }

    public static function get_summary($design_id)
    {
        $design = self::get_design($design_id);

        if (!$design) {
            return array();
        }

        $summary = array();
        $summary[__('Product', 'pod-designer')] = $design['template_name'];

        if ($design['color']) {
            $summary[__('Color', 'pod-designer')] = $design['color']['name'];
        }

        $parts = array();

        foreach ($design['views'] as $view) {
            if (!empty($view['layers'])) {
                $parts[] = sprintf(
                    /* translators: 1: name of the printed side, 2: number of layers placed on it. */
                    _n('%1$s (%2$d item)', '%1$s (%2$d items)', count($view['layers']), 'pod-designer'),
                    $view['label'],
                    count($view['layers'])
                );
            }
        }

        if ($parts) {
            $summary[__('Print', 'pod-designer')] = implode(', ', $parts);
        }

        return $summary;
    }

    /* -----------------------------------------------------------------
     * Admin list
     * ----------------------------------------------------------------- */

    public static function columns($columns)
    {
        $new = array();
        $new['cb'] = isset($columns['cb']) ? $columns['cb'] : '';
        $new['pod_preview'] = __('Preview', 'pod-designer');
        $new['title'] = __('Design', 'pod-designer');
        $new['pod_product'] = __('Product', 'pod-designer');
        $new['pod_customer'] = __('Customer', 'pod-designer');
        $new['pod_order'] = __('Order', 'pod-designer');
        $new['pod_state'] = __('Status', 'pod-designer');
        $new['date'] = isset($columns['date']) ? $columns['date'] : __('Date', 'pod-designer');

        return $new;
    }

    public static function column_content($column, $post_id)
    {
        $design = self::get_design($post_id);

        switch ($column) {
            case 'pod_preview':
                $url = self::get_preview_url($post_id, 'thumbnail');

                if ($url) {
                    printf('<a href="%s"><img src="%s" alt="" class="pod-admin-thumb" /></a>', esc_url(get_edit_post_link($post_id)), esc_url($url));
                } else {
                    echo '—';
                }
                break;

            case 'pod_product':
                if ($design) {
                    echo esc_html($design['template_name']);

                    if ($design['color']) {
                        printf(
                            ' <span class="pod-color-dot" style="background:%s"></span> %s',
                            esc_attr($design['color']['hex']),
                            esc_html($design['color']['name'])
                        );
                    }
                } else {
                    echo '—';
                }
                break;

            case 'pod_customer':
                if ($design) {
                    $name = $design['customer']['name'];
                    $email = $design['customer']['email'];
                    echo esc_html($name ? $name : '—');

                    if ($email) {
                        printf('<br /><a href="mailto:%1$s">%1$s</a>', esc_html($email));
                    }
                } else {
                    echo '—';
                }
                break;

            case 'pod_order':
                $order_id = (int) get_post_meta($post_id, self::META_ORDER, true);

                if ($order_id && function_exists('wc_get_order')) {
                    $order = wc_get_order($order_id);

                    if ($order) {
                        printf(
                            '<a href="%s">#%s</a>',
                            esc_url($order->get_edit_order_url()),
                            esc_html($order->get_order_number())
                        );
                        break;
                    }
                }

                echo $order_id ? esc_html('#' . $order_id) : '—';
                break;

            case 'pod_state':
                $states = self::states();
                $state = get_post_meta($post_id, self::META_STATE, true);
                $state = isset($states[$state]) ? $state : 'new';
                printf('<span class="pod-state pod-state--%s">%s</span>', esc_attr($state), esc_html($states[$state]));
                break;
        }
    }

    /* -----------------------------------------------------------------
     * Detail screen
     * ----------------------------------------------------------------- */

    public static function add_meta_boxes()
    {
        add_meta_box(
            'pod-design-preview',
            __('What the customer saw', 'pod-designer'),
            array(__CLASS__, 'render_preview_box'),
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'pod-design-details',
            __('Production sheet', 'pod-designer'),
            array(__CLASS__, 'render_details_box'),
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'pod-design-meta',
            __('Customer and status', 'pod-designer'),
            array(__CLASS__, 'render_meta_box'),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public static function render_preview_box($post)
    {
        $design = self::get_design($post->ID);

        if (!$design) {
            echo '<p>' . esc_html__('No saved design data.', 'pod-designer') . '</p>';
            return;
        }

        echo '<div class="pod-preview-grid">';

        foreach ($design['views'] as $view) {
            echo '<div class="pod-preview-card">';
            printf('<h3>%s</h3>', esc_html($view['label']));

            $preview_url = !empty($view['preview_id']) ? wp_get_attachment_url((int) $view['preview_id']) : '';

            if ($preview_url) {
                printf('<a href="%1$s" target="_blank" rel="noopener"><img src="%1$s" alt="" /></a>', esc_url($preview_url));
            } else {
                echo '<p class="pod-muted">' . esc_html__('No preview image.', 'pod-designer') . '</p>';
            }

            echo '<p class="pod-file-links">';

            if ($preview_url) {
                printf('<a class="button button-small" href="%s" download>%s</a> ', esc_url($preview_url), esc_html__('Download preview', 'pod-designer'));
            }

            $print_url = !empty($view['print_id']) ? wp_get_attachment_url((int) $view['print_id']) : '';

            if ($print_url) {
                printf(
                    '<a class="button button-primary button-small" href="%s" download>%s</a> ',
                    esc_url($print_url),
                    esc_html__('Print-ready – transparent', 'pod-designer')
                );

                printf(
                    '<a class="button button-small" href="%s">%s</a> ',
                    esc_url(self::print_file_url($post->ID, $view['key'], 'product')),
                    esc_html__('Print-ready – on product color', 'pod-designer')
                );

                printf(
                    '<a class="button button-small" href="%s">%s</a>',
                    esc_url(self::print_file_url($post->ID, $view['key'], 'white')),
                    esc_html__('Print-ready – on white', 'pod-designer')
                );
            }

            echo '</p>';
            echo '</div>';
        }

        echo '</div>';
    }

    public static function render_details_box($post)
    {
        $design = self::get_design($post->ID);

        if (!$design) {
            return;
        }

        $settings = POD_Designer::get_settings();

        printf(
            '<p><strong>%s:</strong> %s &nbsp; <strong>%s:</strong> <span class="pod-color-dot" style="background:%s"></span> %s (%s)</p>',
            esc_html__('Product', 'pod-designer'),
            esc_html($design['template_name']),
            esc_html__('Color', 'pod-designer'),
            esc_attr($design['color'] ? $design['color']['hex'] : '#ffffff'),
            esc_html($design['color'] ? $design['color']['name'] : '—'),
            esc_html($design['color'] ? $design['color']['hex'] : '—')
        );

        foreach ($design['views'] as $view) {
            printf(
                '<h3>%s <span class="pod-muted">%s</span></h3>',
                esc_html($view['label']),
                esc_html(sprintf(
                    /* translators: 1: print area width, 2: print area height, 3: optional DPI note. */
                    __('(%1$s × %2$s mm print area%3$s)', 'pod-designer'),
                    self::format_mm($view['width_mm']),
                    self::format_mm($view['height_mm']),
                    empty($view['print_dpi']) ? '' : sprintf(
                        /* translators: %d: dots per inch of the print-ready file. */
                        __(', print-ready file: %d DPI', 'pod-designer'),
                        (int) $view['print_dpi']
                    )
                ))
            );

            if (empty($view['layers'])) {
                echo '<p class="pod-muted">' . esc_html__('Nothing is printed on this side.', 'pod-designer') . '</p>';
                continue;
            }

            echo '<table class="widefat striped pod-layer-table"><thead><tr>';
            printf('<th>%s</th>', esc_html__('#', 'pod-designer'));
            printf('<th>%s</th>', esc_html__('Item', 'pod-designer'));
            printf('<th>%s</th>', esc_html__('Position (center, mm)', 'pod-designer'));
            printf('<th>%s</th>', esc_html__('Size (mm)', 'pod-designer'));
            printf('<th>%s</th>', esc_html__('Rotation', 'pod-designer'));
            printf('<th>%s</th>', esc_html__('Resolution', 'pod-designer'));
            echo '</tr></thead><tbody>';

            foreach ($view['layers'] as $index => $layer) {
                echo '<tr>';
                printf('<td>%d</td>', (int) ($index + 1));

                if ('text' === $layer['type']) {
                    printf(
                        '<td><strong>%s</strong><br /><span class="pod-layer-text">%s</span><br /><span class="pod-muted">%s, %s mm, %s, %s</span></td>',
                        esc_html__('Text', 'pod-designer'),
                        nl2br(esc_html($layer['text'])),
                        esc_html($layer['font']),
                        esc_html(self::format_mm($layer['font_size'])),
                        esc_html($layer['color']),
                        esc_html('bold' === $layer['weight'] ? __('bold', 'pod-designer') : __('regular', 'pod-designer'))
                    );
                } else {
                    $thumb = wp_get_attachment_image((int) $layer['attachment_id'], 'thumbnail', false, array('class' => 'pod-layer-thumb'));
                    printf(
                        '<td>%s<br /><a href="%s" target="_blank" rel="noopener">%s</a><br /><span class="pod-muted">%d × %d px</span></td>',
                        $thumb ? wp_kses_post($thumb) : '',
                        esc_url($layer['url']),
                        esc_html__('Open original image', 'pod-designer'),
                        (int) $layer['natural_w'],
                        (int) $layer['natural_h']
                    );
                }

                printf('<td>%s × %s</td>', esc_html(self::format_mm($layer['x'])), esc_html(self::format_mm($layer['y'])));
                printf('<td>%s × %s</td>', esc_html(self::format_mm($layer['w'])), esc_html(self::format_mm($layer['h'])));
                printf('<td>%s°</td>', esc_html(self::format_mm($layer['rotation'])));

                if ('image' === $layer['type']) {
                    $dpi = (int) $layer['dpi'];
                    $class = $dpi > 0 && $dpi < $settings['min_dpi'] ? 'pod-dpi pod-dpi--low' : 'pod-dpi';
                    printf('<td><span class="%s">%s DPI</span></td>', esc_attr($class), esc_html($dpi));
                } else {
                    echo '<td>—</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    }

    public static function render_meta_box($post)
    {
        $design = self::get_design($post->ID);
        $states = self::states();
        $state = get_post_meta($post->ID, self::META_STATE, true);
        $state = isset($states[$state]) ? $state : 'new';

        wp_nonce_field('pod_save_design_state', 'pod_design_state_nonce');

        echo '<p><label for="pod-state"><strong>' . esc_html__('Status', 'pod-designer') . '</strong></label><br />';
        echo '<select id="pod-state" name="pod_state" class="widefat">';

        foreach ($states as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($state, $value, false), esc_html($label));
        }

        echo '</select></p>';

        if (!$design) {
            return;
        }

        $customer = $design['customer'];

        echo '<hr />';
        echo '<p><strong>' . esc_html__('Customer', 'pod-designer') . '</strong><br />';
        echo esc_html($customer['name'] ? $customer['name'] : '—') . '<br />';

        if ($customer['email']) {
            printf('<a href="mailto:%1$s">%1$s</a><br />', esc_html($customer['email']));
        }

        if ($customer['phone']) {
            echo esc_html($customer['phone']) . '<br />';
        }

        printf('%s: %d</p>', esc_html__('Quantity', 'pod-designer'), (int) $customer['quantity']);

        if ($customer['note']) {
            echo '<p><strong>' . esc_html__('Note', 'pod-designer') . '</strong><br />' . nl2br(esc_html($customer['note'])) . '</p>';
        }

        $order_id = (int) get_post_meta($post->ID, self::META_ORDER, true);

        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);

            if ($order) {
                printf(
                    '<p><strong>%s</strong><br /><a href="%s">#%s</a></p>',
                    esc_html__('WooCommerce order', 'pod-designer'),
                    esc_url($order->get_edit_order_url()),
                    esc_html($order->get_order_number())
                );
            }
        }
    }

    public static function save_state($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!isset($_POST['pod_design_state_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pod_design_state_nonce'])), 'pod_save_design_state')) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $states = self::states();
        $state = isset($_POST['pod_state']) ? sanitize_key(wp_unslash($_POST['pod_state'])) : 'new';

        update_post_meta($post_id, self::META_STATE, isset($states[$state]) ? $state : 'new');
    }

    /* -----------------------------------------------------------------
     * Notifications
     * ----------------------------------------------------------------- */

    public static function notify_admin($design_id)
    {
        $settings = POD_Designer::get_settings();
        $email = $settings['notification_email'];

        if (!is_email($email)) {
            return;
        }

        $design = self::get_design($design_id);

        if (!$design) {
            return;
        }

        $lines = array();
        $lines[] = sprintf(__('New design received: %s', 'pod-designer'), $design['template_name']);
        $lines[] = '';

        foreach (self::get_summary($design_id) as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = __('Customer', 'pod-designer') . ': ' . $design['customer']['name'];
        $lines[] = __('Email', 'pod-designer') . ': ' . $design['customer']['email'];

        if ($design['customer']['phone']) {
            $lines[] = __('Phone', 'pod-designer') . ': ' . $design['customer']['phone'];
        }

        $lines[] = __('Quantity', 'pod-designer') . ': ' . $design['customer']['quantity'];

        if ($design['customer']['note']) {
            $lines[] = __('Note', 'pod-designer') . ': ' . $design['customer']['note'];
        }

        $lines[] = '';
        $lines[] = __('Open it in the admin:', 'pod-designer') . ' ' . get_edit_post_link($design_id, 'raw');

        wp_mail(
            $email,
            sprintf(__('[%s] New design: %s', 'pod-designer'), get_bloginfo('name'), $design['template_name']),
            implode("\n", $lines)
        );
    }

    public static function format_mm($value)
    {
        $value = (float) $value;

        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
