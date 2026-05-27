<?php
/**
 * Plugin Name: Easy thumbnail manager
 * Description: Admin tool to view and (re)generate post featured images using Pexels API.
 * Version: 1.1
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
        add_action('wp_ajax_tm_remove_thumbnail', array($this, 'ajax_remove_thumbnail'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
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
        $is_plugin_page = strpos($hook, 'tm-thumbnail-manager') !== false || strpos($hook, 'tm-settings') !== false;
        $is_post_edit = in_array($hook, array('post.php', 'post-new.php'));
        
        if (!$is_plugin_page && !$is_post_edit) {
            return;
        }
        
        wp_enqueue_style('tm-admin-css', plugins_url('assets/admin.css', __FILE__), array(), '1.1');
        wp_enqueue_script('tm-admin-js', plugins_url('assets/admin.js', __FILE__), array('jquery'), '1.1', true);
        
        $post_id = 0;
        if ($is_post_edit && isset($_GET['post'])) {
            $post_id = intval($_GET['post']);
        }
        
        wp_localize_script('tm-admin-js', 'tmData', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tm_nonce'),
            'postId' => $post_id
        ));
    }

    public function render_main_page() {
        ?>
        <div class="wrap tm-wrap">
            <h1>Thumbnail Manager</h1>
            
            <div class="tm-filters">
                <input type="text" id="tm-post-search" class="tm-filter-input" placeholder="Search by title...">
                <select id="tm-filter-status" class="tm-filter-select">
                    <option value="all">All Posts</option>
                    <option value="with-image">With Thumbnail</option>
                    <option value="without-image">Without Thumbnail</option>
                </select>
                <button id="tm-apply-filter" class="button button-primary">Filter</button>
                <button id="tm-reset-filter" class="button">Reset</button>
            </div>
            
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

    public function add_meta_box() {
        add_meta_box(
            'tm-thumbnail-box',
            'Thumbnail Manager',
            array($this, 'render_meta_box'),
            'post',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        $thumbnail_id = get_post_thumbnail_id($post->ID);
        $has_thumbnail = !empty($thumbnail_id);
        ?>
        <div class="tm-meta-box">
            <?php if ($has_thumbnail): ?>
                <div class="tm-meta-preview">
                    <?php echo get_the_post_thumbnail($post->ID, 'medium'); ?>
                </div>
                <p>
                    <button type="button" class="button button-large tm-meta-remove" data-post-id="<?php echo $post->ID; ?>">
                        Remove Thumbnail
                    </button>
                </p>
            <?php endif; ?>
            <p>
                <button type="button" class="button button-primary button-large tm-meta-generate" data-post-id="<?php echo $post->ID; ?>" data-post-title="<?php echo esc_attr(get_the_title($post->ID)); ?>">
                    <?php echo $has_thumbnail ? 'Regenerate Thumbnail' : 'Generate Thumbnail'; ?>
                </button>
            </p>
            <p class="description">Generate featured images from Pexels API</p>
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
        <?php
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
        
        $attachment_id = media_handle_sideload($file_array, $post_id, 'Photo by ' . $photographer);
        
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            wp_send_json_error(array('message' => 'Failed to save image.'));
        }
        
        set_post_thumbnail($post_id, $attachment_id);
        
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'medium');
        
        wp_send_json_success(array(
            'thumbnail' => $thumbnail_url
        ));
    }

    public function ajax_remove_thumbnail() {
        check_ajax_referer('tm_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Invalid post ID.'));
        }
        
        delete_post_thumbnail($post_id);
        
        wp_send_json_success(array('message' => 'Thumbnail removed successfully.'));
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
