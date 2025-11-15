<?php
/**
 * Plugin Name: Thumbnail Manager
 * Description: Admin tool to view and (re)generate post featured images using Pexels API.
 * Version: 0.3
 * Author: Attila Kis
 */

if (!defined('ABSPATH')) exit;

class TM_Thumbnail_Manager {
    const OPTION_KEY = 'tm_pexels_api_key';

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue'));
        add_action('wp_ajax_tm_get_posts', array($this, 'ajax_get_posts'));
        add_action('wp_ajax_tm_generate_image', array($this, 'ajax_generate_image'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function admin_menu() {
        $cap = 'edit_posts';
        add_menu_page('Thumbnail Manager', 'Thumbnail Manager', $cap, 'tm-thumbnail-manager', array($this, 'page_main'), 'dashicons-format-image', 6);
        add_submenu_page('tm-thumbnail-manager', 'Settings', 'Settings', $cap, 'tm-thumbnail-manager-settings', array($this, 'page_settings'));
    }

    public function enqueue($hook) {
        if (strpos($hook, 'tm-thumbnail-manager') === false) return;
        wp_enqueue_style('tm-admin', plugin_dir_url(__FILE__).'assets/admin.css');
        wp_enqueue_script('tm-admin-js', plugin_dir_url(__FILE__).'assets/admin.js', array('jquery'), false, true);
        wp_localize_script('tm-admin-js', 'tmData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tm_nonce')
        ));
    }

    public function page_main() {
        ?>
        <div class="wrap">
            <h1>Thumbnail Manager</h1>
            <div id="tm-grid"></div>
            <div id="tm-modal" class="tm-hidden" role="dialog" aria-hidden="true">
                <div class="tm-modal-inner">
                    <h2 id="tm-modal-title">Generate image</h2>
                    <input type="text" id="tm-query" placeholder="Enter search query" style="width:100%" />
                    <div style="margin-top:12px; text-align:right">
                        <button class="button" id="tm-cancel">Cancel</button>
                        <button class="button button-primary" id="tm-do-generate">Generate</button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function page_settings() {
        ?>
        <div class="wrap">
            <h1>Thumbnail Manager Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('tm_settings_group'); ?>
                <table class="form-table">
                    <tr>
                        <th>Pexels API Key</th>
                        <td><input name="<?php echo self::OPTION_KEY; ?>" value="<?php echo esc_attr(get_option(self::OPTION_KEY)); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('tm_settings_group', self::OPTION_KEY);
    }

    public function ajax_get_posts() {
        check_ajax_referer('tm_nonce', 'nonce');
        $posts = get_posts(array('post_type'=>'post','numberposts'=>100));
        $out = array();
        foreach ($posts as $p) {
            $out[] = array(
                'ID' => $p->ID,
                'title' => get_the_title($p->ID),
                'thumbnail' => get_the_post_thumbnail_url($p->ID, 'medium')
            );
        }
        wp_send_json_success($out);
    }

    public function ajax_generate_image() {
        check_ajax_referer('tm_nonce', 'nonce');

        $post_id = intval($_POST['post_id']);
        $query = sanitize_text_field($_POST['query']);
        $api_key = get_option(self::OPTION_KEY);

        $url = "https://api.pexels.com/v1/search?query=" . rawurlencode($query) . "&per_page=10";
        $resp = wp_remote_get($url, array('headers' => array('Authorization' => $api_key)));

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (empty($data['photos'])) wp_send_json_error('no-photos');

        $img_url = $data['photos'][0]['src']['large'];

        require_once(ABSPATH.'wp-admin/includes/file.php');
        require_once(ABSPATH.'wp-admin/includes/media.php');
        require_once(ABSPATH.'wp-admin/includes/image.php');

        $tmp = download_url($img_url);
        $file = array(
            'name' => basename($img_url),
            'tmp_name' => $tmp,
            'type' => 'image/jpeg',
            'size' => filesize($tmp),
            'error' => 0
        );

        $sideload = wp_handle_sideload($file, array('test_form'=>false));
        $attach_id = wp_insert_attachment(array('post_mime_type'=>'image/jpeg','post_title'=>'pexels'), $sideload['file']);
        $meta = wp_generate_attachment_metadata($attach_id, $sideload['file']);
        wp_update_attachment_metadata($attach_id, $meta);
        set_post_thumbnail($post_id, $attach_id);

        wp_send_json_success(array('thumb'=>wp_get_attachment_image_url($attach_id,'medium')));
    }

    public static function activate() {
    }

    public static function uninstall() {
        delete_option(self::OPTION_KEY);
    }
}

new TM_Thumbnail_Manager();

register_activation_hook(__FILE__, array('TM_Thumbnail_Manager', 'activate'));
register_uninstall_hook(__FILE__, array('TM_Thumbnail_Manager', 'uninstall'));