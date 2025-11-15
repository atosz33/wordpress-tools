<?php
/**
 * Plugin Name: Thumbnail Manager
 * Description: Admin tool to view and (re)generate post featured images using Pexels API.
 * Version: 0.5
 * Author: Attila Kis
 */

if (!defined('ABSPATH')) exit;

class TM_Thumbnail_Manager {
    const OPTION_KEY = 'tm_pexels_api_key';

    public function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_tm_get_posts', array($this, 'ajax_get_posts'));
        add_action('wp_ajax_tm_search_images', array($this, 'ajax_search_images'));
        add_action('wp_ajax_tm_set_thumbnail', array($this, 'ajax_set_thumbnail'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function admin_menu() {
        add_menu_page(
            'Thumbnail Manager',
            'Thumbnail Manager',
            'edit_posts',
            'tm-thumbnail-manager',
            array($this, 'render_main_page'),
            'dashicons-format-image',
            26
        );
        
        add_submenu_page(
            'tm-thumbnail-manager',
            'Settings',
            'Settings',
            'manage_options',
            'tm-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'tm-thumbnail-manager') === false && strpos($hook, 'tm-settings') === false) {
            return;
        }
        
        wp_enqueue_style('tm-admin-css', plugins_url('assets/admin.css', __FILE__), array(), '1.0');
        wp_enqueue_script('tm-admin-js', plugins_url('assets/admin.js', __FILE__), array('jquery'), '1.0', true);
        
        wp_localize_script('tm-admin-js', 'tmData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tm_nonce')
        ));
    }

    public function render_main_page() {
        ?>
        <div class="wrap tm-wrap">
            <h1>Thumbnail Manager</h1>
            <div id="tm-posts-grid" class="tm-grid">
                <p class="tm-loading">Loading posts...</p>
            </div>
            
            <!-- Modal for image generation/regeneration -->
            <div id="tm-modal" class="tm-modal" style="display:none;">
                <div class="tm-modal-overlay"></div>
                <div class="tm-modal-content">
                    <div class="tm-modal-header">
                        <h2 id="tm-modal-title">Generate Thumbnail</h2>
                        <button class="tm-modal-close">&times;</button>
                    </div>
                    <div class="tm-modal-body">
                        <div class="tm-search-section">
                            <input type="text" id="tm-search-query" class="tm-input" placeholder="Enter search query (e.g., nature, technology, business)">
                            <button id="tm-search-btn" class="button button-primary">Search Images</button>
                        </div>
                        <div id="tm-images-grid" class="tm-images-grid"></div>
                        <div id="tm-loading" class="tm-modal-loading" style="display:none;">
                            <p>Searching for images...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Thumbnail Manager Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('tm_settings_group');
                do_settings_sections('tm_settings_group');
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tm_pexels_api_key">Pexels API Key</label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="tm_pexels_api_key" 
                                   name="<?php echo self::OPTION_KEY; ?>" 
                                   value="<?php echo esc_attr(get_option(self::OPTION_KEY, '')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                Get your free API key from <a href="https://www.pexels.com/api/" target="_blank">Pexels API</a>
                            </p>
                        </td>
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
        
        $posts = get_posts(array(
            'post_type' => 'post',
            'numberposts' => -1,
            'post_status' => 'any',
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        $result = array();
        foreach ($posts as $post) {
            $thumbnail_url = get_the_post_thumbnail_url($post->ID, 'medium');
            $result[] = array(
                'id' => $post->ID,
                'title' => get_the_title($post->ID),
                'thumbnail' => $thumbnail_url ? $thumbnail_url : false,
                'edit_link' => get_edit_post_link($post->ID)
            );
        }
        
        wp_send_json_success($result);
    }

    public function ajax_search_images() {
        check_ajax_referer('tm_nonce', 'nonce');
        
        $query = sanitize_text_field($_POST['query']);
        $api_key = get_option(self::OPTION_KEY);
        
        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'Please configure your Pexels API key in settings.'));
        }
        
        $url = 'https://api.pexels.com/v1/search?' . http_build_query(array(
            'query' => $query,
            'per_page' => 15
        ));
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => $api_key
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Failed to connect to Pexels API: ' . $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (empty($data['photos'])) {
            wp_send_json_error(array('message' => 'No images found. Try a different search query.'));
        }
        
        $images = array();
        foreach ($data['photos'] as $photo) {
            $images[] = array(
                'id' => $photo['id'],
                'thumbnail' => $photo['src']['medium'],
                'sizes' => array(
                    'small' => $photo['src']['small'],
                    'medium' => $photo['src']['medium'],
                    'large' => $photo['src']['large'],
                    'large2x' => $photo['src']['large2x'],
                    'original' => $photo['src']['original']
                ),
                'photographer' => $photo['photographer'],
                'photographer_url' => $photo['photographer_url'],
                'width' => $photo['width'],
                'height' => $photo['height']
            );
        }
        
        wp_send_json_success($images);
    }

    public function ajax_set_thumbnail() {
        check_ajax_referer('tm_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $image_url = esc_url_raw($_POST['image_url']);
        $photographer = sanitize_text_field($_POST['photographer']);
        
        if (!$post_id || !$image_url) {
            wp_send_json_error(array('message' => 'Invalid parameters.'));
        }
        
        // Download the image
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            wp_send_json_error(array('message' => 'Failed to download image.'));
        }
        
        $file_array = array(
            'name' => 'pexels-' . basename($image_url) . '.jpg',
            'tmp_name' => $tmp
        );
        
        // Upload the image
        $attachment_id = media_handle_sideload($file_array, $post_id, 'Photo by ' . $photographer);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            wp_send_json_error(array('message' => 'Failed to save image.'));
        }
        
        // Set as featured image
        set_post_thumbnail($post_id, $attachment_id);
        
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'medium');
        
        wp_send_json_success(array(
            'thumbnail' => $thumbnail_url
        ));
    }

    public static function activate() {
        $plugin_dir = plugin_dir_path(__FILE__);
        $assets_dir = $plugin_dir . 'assets';
        
        if (!file_exists($assets_dir)) {
            wp_mkdir_p($assets_dir);
        }
        
        $css_content = file_get_contents(__DIR__ . '/assets/template-admin.css');
        file_put_contents($assets_dir . '/admin.css', $css_content);
        
        $js_content = file_get_contents(__DIR__ . '/assets/template-admin.js');
        file_put_contents($assets_dir . '/admin.js', $js_content);
    }

    public static function uninstall() {
        delete_option(self::OPTION_KEY);
    }
}

new TM_Thumbnail_Manager();

register_activation_hook(__FILE__, array('TM_Thumbnail_Manager', 'activate'));
register_uninstall_hook(__FILE__, array('TM_Thumbnail_Manager', 'uninstall'));