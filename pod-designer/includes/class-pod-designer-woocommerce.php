<?php

if (!defined('ABSPATH')) {
    exit;
}

class POD_Designer_WooCommerce
{
    const PRODUCT_META = '_pod_template_id';
    const LAYOUT_META = '_pod_layout';

    public static function init()
    {
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'product_field'));
        add_action('woocommerce_process_product_meta', array(__CLASS__, 'save_product_field'));

        add_action('woocommerce_before_add_to_cart_button', array(__CLASS__, 'render_designer'), 20);
        add_filter('woocommerce_loop_add_to_cart_link', array(__CLASS__, 'loop_add_to_cart_link'), 10, 2);

        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate_add_to_cart'), 10, 3);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'add_cart_item_data'), 10, 3);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'cart_item_data'), 10, 2);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'cart_item_thumbnail'), 10, 3);
        add_filter('woocommerce_store_api_cart_item_images', array(__CLASS__, 'store_api_cart_item_images'), 10, 2);

        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_line_item'), 10, 4);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'sync_order_designs'), 10, 1);
        add_action('woocommerce_store_api_checkout_order_processed', array(__CLASS__, 'sync_order_designs'), 10, 1);

        add_action('woocommerce_after_order_itemmeta', array(__CLASS__, 'admin_order_item'), 10, 2);
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_admin_assets'));
        add_action('admin_notices', array(__CLASS__, 'product_notice'));
    }

    /**
     * The designer is rendered inside the add to cart form, and WooCommerce
     * skips that form entirely for products it considers unbuyable. Without a
     * warning the template simply looks like it was never applied.
     */
    public static function product_notice()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen || 'product' !== $screen->id || 'post' !== $screen->base) {
            return;
        }

        $post = get_post();

        if (!$post || !self::get_product_template($post->ID)) {
            return;
        }

        $product = wc_get_product($post->ID);

        if (!$product) {
            return;
        }

        $reason = '';

        if (!$product->is_purchasable()) {
            $reason = __('the product has no price', 'pod-designer');
        } elseif (!$product->is_in_stock()) {
            $reason = __('the product is out of stock', 'pod-designer');
        }

        if ('' === $reason) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
            esc_html__('POD Designer:', 'pod-designer'),
            esc_html(sprintf(
                /* translators: %s: reason the product is not purchasable. */
                __('the designer will not show up on the product page because %s. WooCommerce does not render the add-to-cart form in that case, and the designer lives inside that form. Set a real price and save the product again.', 'pod-designer'),
                $reason
            ))
        );
    }

    public static function enqueue_admin_assets()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if (!$screen) {
            return;
        }

        $order_screens = array('shop_order', 'woocommerce_page_wc-orders');

        if (in_array($screen->id, $order_screens, true) || in_array($screen->post_type, $order_screens, true)) {
            wp_enqueue_style('pod-designer-admin', POD_PLUGIN_URL . 'assets/admin.css', array(), POD_VERSION);
        }
    }

    /* -----------------------------------------------------------------
     * Product settings
     * ----------------------------------------------------------------- */

    public static function product_field()
    {
        $options = array('' => __('— No designer —', 'pod-designer'));

        foreach (POD_Designer::get_templates() as $template) {
            if (empty($template['enabled'])) {
                continue;
            }

            $options[$template['id']] = $template['name'];
        }

        woocommerce_wp_select(array(
            'id' => self::PRODUCT_META,
            'label' => __('POD Designer template', 'pod-designer'),
            'description' => __('When set, the customer can design the product on the product page and the design is attached to the order.', 'pod-designer'),
            'desc_tip' => true,
            'options' => $options,
        ));

        woocommerce_wp_select(array(
            'id' => self::LAYOUT_META,
            'label' => __('Designer width', 'pod-designer'),
            'description' => __('On a product page, wide and full screen overlap the product gallery in most themes, so this stays inside the product column by default.', 'pod-designer'),
            'desc_tip' => true,
            'options' => array(
                '' => __('Inside the product column (default)', 'pod-designer'),
                'container' => __('Inside the product column', 'pod-designer'),
                'wide' => __('Wide', 'pod-designer'),
                'full' => __('Full screen', 'pod-designer'),
            ),
        ));
    }

    public static function save_product_field($product_id)
    {
        $layout = isset($_POST[self::LAYOUT_META]) ? sanitize_key(wp_unslash($_POST[self::LAYOUT_META])) : '';

        if (in_array($layout, POD_Designer::layouts(), true)) {
            update_post_meta($product_id, self::LAYOUT_META, $layout);
        } else {
            delete_post_meta($product_id, self::LAYOUT_META);
        }

        $value = isset($_POST[self::PRODUCT_META]) ? sanitize_text_field(wp_unslash($_POST[self::PRODUCT_META])) : '';

        if ('' === $value || !POD_Designer::get_template($value)) {
            delete_post_meta($product_id, self::PRODUCT_META);
            return;
        }

        update_post_meta($product_id, self::PRODUCT_META, $value);
    }

    public static function get_product_layout($product_id)
    {
        $layout = (string) get_post_meta($product_id, self::LAYOUT_META, true);

        return in_array($layout, POD_Designer::layouts(), true) ? $layout : 'container';
    }

    public static function get_product_template($product_id)
    {
        $template_id = get_post_meta($product_id, self::PRODUCT_META, true);

        if (!$template_id) {
            return null;
        }

        $template = POD_Designer::get_template($template_id);

        return ($template && !empty($template['enabled'])) ? $template : null;
    }

    /* -----------------------------------------------------------------
     * Product page
     * ----------------------------------------------------------------- */

    public static function render_designer()
    {
        global $product;

        if (!$product instanceof WC_Product) {
            return;
        }

        $template = self::get_product_template($product->get_id());

        if (!$template) {
            return;
        }

        echo '<input type="hidden" name="pod_design_id" value="" class="pod-design-id-field" />';

        // Escaping happens inside render_designer(); the markup carries a JSON config attribute
        // that wp_kses_post() would corrupt.
        echo POD_Designer::render_designer($template, array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            'mode' => 'woo',
            'product_id' => $product->get_id(),
            // Product pages default to the summary column: a break-out would sit
            // on top of the product gallery in the usual two column layout.
            'layout' => self::get_product_layout($product->get_id()),
        ));
    }

    public static function loop_add_to_cart_link($html, $product)
    {
        if (!$product instanceof WC_Product || !self::get_product_template($product->get_id())) {
            return $html;
        }

        return sprintf(
            '<a href="%s" class="button product_type_simple">%s</a>',
            esc_url($product->get_permalink()),
            esc_html__('Design it', 'pod-designer')
        );
    }

    /* -----------------------------------------------------------------
     * Cart
     * ----------------------------------------------------------------- */

    private static function design_belongs_to_product($design_id, $product_id)
    {
        if (POD_Designer_Designs::POST_TYPE !== get_post_type($design_id)) {
            return false;
        }

        $design = POD_Designer_Designs::get_design($design_id);

        if (!$design) {
            return false;
        }

        return (int) $design['product_id'] === (int) $product_id;
    }

    public static function validate_add_to_cart($passed, $product_id, $quantity)
    {
        if (!self::get_product_template($product_id)) {
            return $passed;
        }

        $design_id = isset($_POST['pod_design_id']) ? (int) $_POST['pod_design_id'] : 0;

        if (!$design_id || !self::design_belongs_to_product($design_id, $product_id)) {
            wc_add_notice(__('Design the product before you add it to the cart.', 'pod-designer'), 'error');
            return false;
        }

        if (POD_Designer_Designs::is_attached_to_order($design_id)) {
            wc_add_notice(__('This design already belongs to a placed order. Please design it again.', 'pod-designer'), 'error');
            return false;
        }

        return $passed;
    }

    public static function add_cart_item_data($cart_item_data, $product_id, $variation_id)
    {
        if (!self::get_product_template($product_id)) {
            return $cart_item_data;
        }

        $design_id = isset($_POST['pod_design_id']) ? (int) $_POST['pod_design_id'] : 0;

        if ($design_id && self::design_belongs_to_product($design_id, $product_id)) {
            $cart_item_data['pod_design_id'] = $design_id;
            $cart_item_data['pod_unique_key'] = md5($design_id . '-' . microtime());
        }

        return $cart_item_data;
    }

    public static function cart_item_data($item_data, $cart_item)
    {
        if (empty($cart_item['pod_design_id'])) {
            return $item_data;
        }

        foreach (POD_Designer_Designs::get_summary((int) $cart_item['pod_design_id']) as $label => $value) {
            $item_data[] = array(
                'key' => $label,
                'value' => $value,
            );
        }

        return $item_data;
    }

    public static function cart_item_thumbnail($thumbnail, $cart_item, $cart_item_key)
    {
        if (empty($cart_item['pod_design_id'])) {
            return $thumbnail;
        }

        $url = POD_Designer_Designs::get_preview_url((int) $cart_item['pod_design_id'], 'woocommerce_thumbnail');

        if (!$url) {
            return $thumbnail;
        }

        return sprintf('<img src="%s" alt="" class="pod-cart-preview" />', esc_url($url));
    }

    /**
     * The block based cart and checkout read their images from the Store API
     * instead of the classic cart template, so the preview has to be swapped in
     * there as well. The filter exists from WooCommerce 9.6 onwards.
     */
    public static function store_api_cart_item_images($images, $cart_item)
    {
        if (empty($cart_item['pod_design_id'])) {
            return $images;
        }

        $attachment_id = POD_Designer_Designs::get_preview_attachment_id((int) $cart_item['pod_design_id']);

        if (!$attachment_id) {
            return $images;
        }

        $thumbnail = wp_get_attachment_image_url($attachment_id, 'woocommerce_thumbnail');
        $full = wp_get_attachment_image_url($attachment_id, 'full');

        if (!$thumbnail || !$full) {
            return $images;
        }

        $image = new stdClass();
        $image->id = $attachment_id;
        $image->src = $full;
        $image->thumbnail = $thumbnail;
        $image->srcset = (string) wp_get_attachment_image_srcset($attachment_id, 'woocommerce_thumbnail');
        $image->sizes = (string) wp_get_attachment_image_sizes($attachment_id, 'woocommerce_thumbnail');
        $image->name = get_the_title($attachment_id);
        $image->alt = __('The customer\'s design', 'pod-designer');

        return array($image);
    }

    /* -----------------------------------------------------------------
     * Orders
     * ----------------------------------------------------------------- */

    public static function order_line_item($item, $cart_item_key, $values, $order)
    {
        if (empty($values['pod_design_id'])) {
            return;
        }

        $design_id = (int) $values['pod_design_id'];

        $item->add_meta_data('_pod_design_id', $design_id, true);

        foreach (POD_Designer_Designs::get_summary($design_id) as $label => $value) {
            $item->add_meta_data($label, $value, true);
        }
    }

    public static function sync_order_designs($order)
    {
        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }

        if (!$order instanceof WC_Order) {
            return;
        }

        foreach ($order->get_items() as $item) {
            $design_id = (int) $item->get_meta('_pod_design_id');

            if ($design_id) {
                POD_Designer_Designs::attach_to_order($design_id, $order->get_id(), $order->get_order_number());
            }
        }
    }

    public static function admin_order_item($item_id, $item)
    {
        if (!is_admin() || !method_exists($item, 'get_meta')) {
            return;
        }

        $design_id = (int) $item->get_meta('_pod_design_id');

        if (!$design_id) {
            return;
        }

        $design = POD_Designer_Designs::get_design($design_id);

        if (!$design) {
            return;
        }

        echo '<div class="pod-order-design">';

        foreach ($design['views'] as $view) {
            $preview = !empty($view['preview_id']) ? wp_get_attachment_image_url((int) $view['preview_id'], 'medium') : '';
            $print = !empty($view['print_id']) ? wp_get_attachment_url((int) $view['print_id']) : '';

            echo '<div class="pod-order-design__view">';
            printf('<strong>%s</strong><br />', esc_html($view['label']));

            if ($preview) {
                printf('<img src="%s" alt="" />', esc_url($preview));
            }

            if ($print) {
                printf(
                    '<br /><a class="button button-small" href="%s" download>%s</a> ',
                    esc_url($print),
                    esc_html__('Print-ready – transparent', 'pod-designer')
                );

                printf(
                    '<a class="button button-small" href="%s">%s</a>',
                    esc_url(POD_Designer_Designs::print_file_url($design_id, $view['key'], 'product')),
                    esc_html__('on product color', 'pod-designer')
                );
            }

            echo '</div>';
        }

        printf(
            '<p><a class="button button-small" href="%s">%s</a></p>',
            esc_url(get_edit_post_link($design_id)),
            esc_html__('Full production sheet', 'pod-designer')
        );

        echo '</div>';
    }
}
