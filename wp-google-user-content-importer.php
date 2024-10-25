<?php
/**
 * Plugin Name: Google User Content Importer (GUCI)
 * Description: Scans posts for Google User Content images, reports metadata, and allows importing.
 * Version: 0.1
 * Author: Matt Anthes-Washburn
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-guci-scanner.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-guci-importer.php');
require_once(plugin_dir_path(__FILE__) . 'includes/class-guci-admin.php');

/**
 * Main plugin class
 */
class GoogleUserContentImporter {
    /**
     * Plugin instance
     *
     * @var GoogleUserContentImporter
     */
    private static $instance = null;

    /**
     * Get plugin instance
     *
     * @return GoogleUserContentImporter
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_dependencies();
        $this->init_hooks();
        $this->register_settings();
    }

    /**
     * Check plugin dependencies
     */
    private function check_dependencies() {
        if (!extension_loaded('gd')) {
            add_action('admin_notices', array($this, 'gd_missing_notice'));
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'init_scanner'));
        add_action('admin_post_import_google_image', array($this, 'import_google_image'));
        add_action('admin_post_update_google_image', array($this, 'update_google_image'));
        add_action('admin_post_import_all_google_images', array($this, 'import_all_google_images'));
        add_action('wp_ajax_generate_image_filename', array($this, 'ajax_generate_image_filename'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    /**
     * Register plugin settings
     */
    private function register_settings() {
        add_action('admin_init', function() {
            register_setting('guci_settings', 'guci_openai_api_key');
            register_setting('guci_settings', 'guci_use_ai_naming');
        });
    }

    /**
     * Display notice if GD library is missing
     */
    public function gd_missing_notice() {
        echo '<div class="error"><p>' . esc_html__('Google User Content Importer requires GD library to be installed and enabled.', 'guci') . '</p></div>';
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            'Google User Content Importer',
            'GUCI',
            'manage_options',
            'google-user-content-importer',
            array($this, 'display_results_page'),
            'dashicons-search',
            100
        );
    }

    /**
     * Initialize scanner
     */
    public function init_scanner() {
        if (isset($_POST['scan_posts']) && check_admin_referer('google_user_content_scan_nonce')) {
            // Save selected post types
            $selected_post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array('post', 'page');
            update_option('guci_selected_post_types', $selected_post_types);

            // Start scan progress tracking
            $scan_progress = array(
                'total_posts' => 0,
                'scanned_posts' => 0,
                'found_images' => 0,
                'current_post' => '',
                'status' => 'starting'
            );
            update_option('guci_scan_progress', $scan_progress);

            // Check if this is the first scan
            $first_scan = get_option('guci_first_scan_completed', false);
            
            if (!$first_scan) {
                $scan_progress['status'] = 'hashing_media';
                update_option('guci_scan_progress', $scan_progress);
                
                // Hash existing media
                $this->hash_existing_media();
                update_option('guci_first_scan_completed', true);
            }

            // Get selected post types
            $selected_post_types = isset($_POST['post_types']) ? array_map('sanitize_text_field', $_POST['post_types']) : array('post', 'page');

            $posts = get_posts(array(
                'post_type' => $selected_post_types,
                'numberposts' => -1,
                'post_status' => array('publish', 'draft')
            ));

            // Update total posts count
            $scan_progress['total_posts'] = count($posts);
            $scan_progress['status'] = 'scanning';
            update_option('guci_scan_progress', $scan_progress);

            $results = array();
            $unique_images = array();

            foreach ($posts as $post) {
                // Update progress
                $scan_progress['scanned_posts']++;
                $scan_progress['current_post'] = get_the_title($post->ID);
                update_option('guci_scan_progress', $scan_progress);

                $images = $this->find_google_images(wp_kses_post($post->post_content));
                if (!empty($images)) {
                    foreach ($images as &$image) {
                        $response = wp_remote_get($image['url']);
                        if (!is_wp_error($response)) {
                            $image_content = wp_remote_retrieve_body($response);
                            $image['phash'] = $this->calculate_perceptual_hash($image_content);
                            
                            // Check if the image already exists in the media library
                            $existing_attachment_id = $this->get_attachment_id_by_hash($image['phash']);
                            $image['existing_attachment_id'] = $existing_attachment_id;

                            // De-duplication: Group images by their perceptual hash
                            if (!isset($unique_images[$image['phash']])) {
                                $unique_images[$image['phash']] = array(
                                    'image' => $image,
                                    'posts' => array()
                                );
                                $scan_progress['found_images']++;
                                update_option('guci_scan_progress', $scan_progress);
                            }
                            $unique_images[$image['phash']]['posts'][] = array(
                                'post_id' => $post->ID,
                                'post_title' => get_the_title($post->ID)
                            );
                        }
                    }
                    $results[intval($post->ID)] = $images;
                }
            }

            // Mark scan as complete
            $scan_progress['status'] = 'complete';
            update_option('guci_scan_progress', $scan_progress);

            // Store both the full results and the de-duplicated results
            update_option('google_user_content_scan_results', $results);
            update_option('google_user_content_unique_images', $unique_images);
            wp_redirect(admin_url('admin.php?page=google-user-content-importer&scanned=1'));
            exit;
        }
    }

    /**
     * Find Google images in content
     *
     * @param string $content Post content
     * @return array Array of Google images
     */
    private function find_google_images($content) {
        if (empty($content)) {
            return array();
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        @$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $images = array();
        $img_tags = $dom->getElementsByTagName('img');

        foreach ($img_tags as $img) {
            $src = $img->getAttribute('src');
            if (strpos($src, '.googleusercontent.com/') !== false) {
                $images[] = array(
                    'url' => $src,
                    'alt_text' => $img->getAttribute('alt') ?? '',
                    'width' => $img->getAttribute('width') ?? '',
                    'height' => $img->getAttribute('height') ?? '',
                    'tag' => $dom->saveHTML($img),
                    'filename' => pathinfo(parse_url($src, PHP_URL_PATH), PATHINFO_FILENAME) ?? 'image',
                    'extension' => pathinfo(parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION) ?? 'jpg'
                );
            }
        }
        return $images;
    }

    /**
     * Get image info
     *
     * @param string $url Image URL
     * @param string $alt_text Alt text
     * @return array Image info
     */
    private function get_image_info($url, $alt_text) {
        $response = wp_remote_get($url);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            error_log("GUCI: Failed to retrieve image data from URL: " . esc_url($url));
            return array(
                'url' => esc_url($url),
                'file_type' => 'Unknown',
                'file_ext' => 'Unknown',
                'size' => 'Unknown',
                'last_modified' => 'Unknown',
                'alt_text' => sanitize_text_field($alt_text),
            );
        }
        $image_data = wp_remote_retrieve_body($response);
        if ($image_data === false) {
            error_log("GUCI: Unable to get image size for URL: " . esc_url($url));
            return array(
                'url' => esc_url($url),
                'file_type' => 'Unknown',
                'file_ext' => 'Unknown',
                'size' => strlen($image_data),
                'last_modified' => 'Unknown',
                'alt_text' => sanitize_text_field($alt_text),
            );
        }

        $image_info = getimagesizefromstring($image_data);
        if ($image_info === false) {
            error_log("GUCI: Unable to get image size for URL: " . esc_url($url));
            return array(
                'url' => esc_url($url),
                'file_type' => 'Unknown',
                'file_ext' => 'Unknown',
                'size' => strlen($image_data),
                'last_modified' => 'Unknown',
                'alt_text' => sanitize_text_field($alt_text),
            );
        }

        $mime_type = sanitize_text_field($image_info['mime']);
        $extensions = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        );
        $file_ext = isset($extensions[$mime_type]) ? sanitize_file_name($extensions[$mime_type]) : 'Unknown';

        $last_modified = wp_remote_retrieve_header($response, 'last-modified') ?: 'Unknown';

        return array(
            'url' => esc_url($url),
            'file_type' => esc_attr($mime_type),
            'file_ext' => esc_attr($file_ext),
            'size' => intval(strlen($image_data)),
            'last_modified' => esc_attr($last_modified),
            'alt_text' => sanitize_text_field($alt_text),
        );
    }

    /**
     * Import single image
     *
     * @param array $image_data Image data
     * @param int $post_id Post ID
     * @return array|WP_Error Import result
     */
    private function import_single_image($image_data, $post_id) {
        // Input validation
        if (empty($image_data['url']) || !is_numeric($post_id)) {
            return new WP_Error('invalid_input', __('Invalid input data', 'guci'));
        }

        // Fetch image content
        $response = wp_remote_get($image_data['url']);
        if (is_wp_error($response)) {
            return new WP_Error('download_failed', __('Failed to download image', 'guci'));
        }
        $image_content = wp_remote_retrieve_body($response);

        // Calculate perceptual hash
        $image_hash = $this->calculate_perceptual_hash($image_content);
        if ($image_hash === false) {
            return new WP_Error('hash_failed', __('Failed to calculate image hash', 'guci'));
        }

        // Check if an image with the same hash already exists
        $existing_attachment_id = $this->get_attachment_id_by_hash($image_hash);
        if ($existing_attachment_id) {
            // Image already exists, return the existing attachment data
            $attach_data = wp_get_attachment_metadata($existing_attachment_id);
            return array(
                'attach_id' => $existing_attachment_id,
                'new_url' => wp_get_attachment_url($existing_attachment_id),
                'width' => $attach_data['width'],
                'height' => $attach_data['height'],
                'filename' => basename(get_attached_file($existing_attachment_id))
            );
        }

        // Generate filename
        if (get_option('guci_use_ai_naming') && empty($image_data['custom_filename'])) {
            $ai_filename = $this->generate_ai_image_name($image_data['url']);
            if ($ai_filename) {
                $image_data['custom_filename'] = $ai_filename;
            }
        }

        // Prepare filename
        if (!empty($image_data['custom_filename'])) {
            $filename = sanitize_file_name($image_data['custom_filename']);
        } elseif (!empty($image_data['alt_text'])) {
            $filename = sanitize_file_name($image_data['alt_text']);
        } else {
            $filename = 'imported_image';
        }

        // Ensure the filename is not empty after sanitization
        if (empty($filename)) {
            $filename = 'imported_image';
        }

        // Validate extension
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'gif', 'webp');
        $extension = strtolower($image_data['extension']);
        if (!in_array($extension, $allowed_extensions)) {
            $extension = 'jpg'; // Default to 'jpg' if invalid
        }

        $filename .= '.' . $extension;

        // Use media_sideload_image
        $tmp = wp_tempnam($filename);
        file_put_contents($tmp, $image_content);

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp
        );
        $attach_id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($attach_id)) {
            @unlink($tmp);
            return $attach_id;
        } else {
            // Successfully sideloaded
            if (!empty($image_data['alt_text'])) {
                update_post_meta($attach_id, '_wp_attachment_image_alt', sanitize_text_field($image_data['alt_text']));
            }

            // Store the perceptual hash
            update_post_meta($attach_id, '_guci_image_phash', $image_hash);

            $new_url = wp_get_attachment_url($attach_id);

            return array(
                'attach_id' => $attach_id,
                'new_url' => $new_url,
                'width' => intval($image_data['width']),
                'height' => intval($image_data['height']),
                'filename' => $filename
            );
        }
    }

    /**
     * Import post Google images
     */
    public function import_post_google_images() {
        if (!current_user_can('upload_files')) {
            wp_die(__('You do not have permission to upload files.', 'guci'));
        }

        check_admin_referer('import_post_google_images');

        $post_id = intval($_POST['post_id']);
        $results = get_option('google_user_content_scan_results', array());
        $filenames = isset($_POST['filenames']) ? array_map('sanitize_file_name', $_POST['filenames']) : array();

        $import_results = array(
            'imported' => array(),
            'updated' => array(),
            'errors' => array()
        );

        if (isset($results[$post_id])) {
            $post = get_post($post_id);
            $content = wp_kses_post($post->post_content);

            foreach ($results[$post_id] as $index => $image) {
                $custom_filename = isset($filenames[$index]) ? sanitize_file_name($filenames[$index]) : '';
                $image['custom_filename'] = $custom_filename;
                
                if (isset($image['existing_attachment_id']) && $image['existing_attachment_id']) {
                    // Image already exists, update the URL in the post content
                    $existing_url = wp_get_attachment_url($image['existing_attachment_id']);
                    $new_img_tag = str_replace($image['url'], $existing_url, $image['tag']);
                    $content = str_replace($image['tag'], $new_img_tag, $content);
                    $import_results['updated'][] = array(
                        'old_url' => $image['url'],
                        'new_url' => $existing_url
                    );
                } else {
                    // Import new image
                    $import_result = $this->import_single_image($image, $post_id);
                    if (!is_wp_error($import_result)) {
                        $new_img_tag = str_replace($image['url'], $import_result['new_url'], $image['tag']);
                        $new_img_tag = str_replace(
                            'src="' . esc_url($image['url']) . '"',
                            'src="' . esc_url($import_result['new_url']) . '" width="' . esc_attr($import_result['width']) . '" height="' . esc_attr($import_result['height']) . '"',
                            $new_img_tag
                        );
                        $content = str_replace($image['tag'], $new_img_tag, $content);
                        $import_results['imported'][] = array(
                            'old_url' => $image['url'],
                            'new_url' => $import_result['new_url'],
                            'filename' => $import_result['filename']
                        );
                    } else {
                        $import_results['errors'][] = array(
                            'url' => $image['url'],
                            'error' => $import_result->get_error_message()
                        );
                    }
                }
            }

            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $content
            ));
        }

        // Store the import results in a transient
        set_transient('guci_import_results_' . $post_id, $import_results, 60 * 5); // Store for 5 minutes

        // Redirect back to the main page with a query parameter
        wp_redirect(add_query_arg(array('page' => 'google-user-content-importer', 'imported' => 'post_' . $post_id), admin_url('admin.php')));
        exit;
    }

    /**
     * Import all Google images
     */
    public function import_all_google_images() {
        if (!current_user_can('upload_files')) {
            wp_die(__('You do not have permission to upload files.', 'guci'));
        }

        check_admin_referer('import_all_google_images');

        $unique_images = get_option('google_user_content_unique_images', array());
        $import_results = array(
            'imported' => array(),
            'updated' => array(),
            'errors' => array()
        );

        foreach ($unique_images as $phash => $data) {
            $image = $data['image'];
            if (isset($image['existing_attachment_id']) && $image['existing_attachment_id']) {
                // Update existing image links
                foreach ($data['posts'] as $post) {
                    $post_content = get_post_field('post_content', $post['post_id']);
                    $existing_url = wp_get_attachment_url($image['existing_attachment_id']);
                    
                    // Replace old URL with new URL
                    $new_img_tag = str_replace($image['url'], $existing_url, $image['tag']);
                    $post_content = str_replace($image['tag'], $new_img_tag, $post_content);
                    
                    // Update post
                    wp_update_post(array(
                        'ID' => $post['post_id'],
                        'post_content' => $post_content
                    ));
                }
                
                $import_results['updated'][] = array(
                    'old_url' => $image['url'],
                    'new_url' => $existing_url
                );
            } else {
                // Import new image
                $import_result = $this->import_single_image($image, $data['posts'][0]['post_id']);
                if (!is_wp_error($import_result)) {
                    $import_results['imported'][] = $import_result;
                    
                    // Update image in all posts where it appears
                    foreach ($data['posts'] as $post) {
                        $post_content = get_post_field('post_content', $post['post_id']);
                        $new_img_tag = str_replace($image['url'], $import_result['new_url'], $image['tag']);
                        $post_content = str_replace($image['tag'], $new_img_tag, $post_content);
                        wp_update_post(array(
                            'ID' => $post['post_id'],
                            'post_content' => $post_content
                        ));
                    }
                } else {
                    $import_results['errors'][] = array(
                        'url' => $image['url'],
                        'error' => $import_result->get_error_message()
                    );
                }
            }
        }

        // Store the import results in a transient
        set_transient('guci_import_results_all', $import_results, 60 * 5); // Store for 5 minutes

        // Redirect back to the main page with a query parameter
        wp_redirect(add_query_arg(array('page' => 'google-user-content-importer', 'imported' => 'all'), admin_url('admin.php')));
        exit;
    }

    /**
     * Display results page
     */
    public function display_results_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'guci'));
        }

        $results = isset($_GET['scanned']) ? get_option('google_user_content_scan_results', array()) : array();
        $imported = isset($_GET['imported']) ? $_GET['imported'] : null;

        // Check if we have import results to display
        $import_results = null;
        $post_id = null;
        if ($imported && strpos($imported, 'post_') === 0) {
            $post_id = intval(substr($imported, 5));
            $import_results = get_transient('guci_import_results_' . $post_id);
            delete_transient('guci_import_results_' . $post_id);
        } elseif ($imported && strpos($imported, 'single_') === 0) {
            $post_id = intval(substr($imported, 7));
            $import_results = get_transient('guci_import_results_single_' . $post_id);
            delete_transient('guci_import_results_single_' . $post_id);
        }

        $update_results = null;
        if (isset($_GET['updated']) && strpos($_GET['updated'], 'single_') === 0) {
            $post_id = intval(substr($_GET['updated'], 7));
            $update_results = get_transient('guci_update_results_single_' . $post_id);
            delete_transient('guci_update_results_single_' . $post_id);
        }

        // Add check for 'all' import results
        if ($imported === 'all') {
            $import_results = get_transient('guci_import_results_all');
            delete_transient('guci_import_results_all');
        }

        // Add this before the scan form
        $scan_progress = get_option('guci_scan_progress', array());
        if (!empty($scan_progress) && $scan_progress['status'] !== 'complete') {
            ?>
            <div class="scan-progress-wrapper">
                <h2><?php esc_html_e('Scan Progress', 'guci'); ?></h2>
                <div class="scan-progress">
                    <?php if ($scan_progress['status'] === 'hashing_media'): ?>
                        <p><?php esc_html_e('Hashing existing media library...', 'guci'); ?></p>
                    <?php else: ?>
                        <p>
                            <?php 
                            printf(
                                esc_html__('Scanning posts: %1$d of %2$d (%3$d%%) - Found %4$d images', 'guci'),
                                $scan_progress['scanned_posts'],
                                $scan_progress['total_posts'],
                                $scan_progress['total_posts'] ? ($scan_progress['scanned_posts'] / $scan_progress['total_posts'] * 100) : 0,
                                $scan_progress['found_images']
                            );
                            ?>
                        </p>
                        <?php if (!empty($scan_progress['current_post'])): ?>
                            <p><?php printf(esc_html__('Currently scanning: %s', 'guci'), esc_html($scan_progress['current_post'])); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="progress-bar">
                        <div class="progress" style="width: <?php echo esc_attr($scan_progress['total_posts'] ? ($scan_progress['scanned_posts'] / $scan_progress['total_posts'] * 100) : 0); ?>%"></div>
                    </div>
                </div>
            </div>
            <style>
                .progress-bar {
                    width: 100%;
                    height: 20px;
                    background: #f0f0f0;
                    border-radius: 10px;
                    overflow: hidden;
                    margin: 10px 0;
                }
                .progress-bar .progress {
                    height: 100%;
                    background: #2271b1;
                    transition: width 0.3s ease;
                }
                .scan-progress-wrapper {
                    background: #fff;
                    padding: 15px;
                    margin: 20px 0;
                    border: 1px solid #ccd0d4;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
            </style>
            <?php
        }

        // Add this to the display_results_page() method, before the HTML output
        ?>
        <style>
            .ai-filename-cell code {
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 3px;
            }
            .ai-filename-cell .error {
                color: #dc3232;
            }
            .ai-filename-cell .generating {
                color: #666;
            }
        </style>
        <?php

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Google User Content Importer (GUCI)', 'guci'); ?></h1>

            <!-- Settings Section -->
            <div class="card">
                <h2><?php esc_html_e('Settings', 'guci'); ?></h2>
                <form method="post" action="options.php">
                    <?php settings_fields('guci_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('OpenAI API Key', 'guci'); ?></th>
                            <td>
                                <input type="password" 
                                       name="guci_openai_api_key" 
                                       value="<?php echo esc_attr(get_option('guci_openai_api_key')); ?>" 
                                       class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Use AI for Image Naming', 'guci'); ?></th>
                            <td>
                                <input type="checkbox" 
                                       name="guci_use_ai_naming" 
                                       value="1" 
                                       <?php checked(get_option('guci_use_ai_naming'), '1'); ?>>
                                <span class="description">
                                    <?php esc_html_e('Use OpenAI to generate descriptive image names', 'guci'); ?>
                                </span>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save Settings', 'guci')); ?>
                </form>
            </div>

            <hr class="wp-header-end">
            
            <?php if ($import_results): ?>
                <div class="notice notice-success is-dismissible">
                    <?php if ($imported === 'all'): ?>
                        <p>
                            <?php 
                            $imported_count = count($import_results['imported']);
                            $updated_count = count($import_results['updated']);
                            $error_count = count($import_results['errors']);
                            
                            if ($imported_count > 0 || $updated_count > 0) {
                                printf(
                                    esc_html__('Successfully processed images: %1$d imported, %2$d updated.', 'guci'),
                                    $imported_count,
                                    $updated_count
                                );
                                if ($error_count > 0) {
                                    echo ' ';
                                    printf(
                                        esc_html__('%d errors occurred.', 'guci'),
                                        $error_count
                                    );
                                }
                            } else {
                                esc_html_e('No images were processed.', 'guci');
                            }
                            ?>
                        </p>
                    <?php else: ?>
                        <p><?php esc_html_e('Import process completed.', 'guci'); ?></p>
                    <?php endif; ?>
                </div>
                <?php $this->display_import_results($import_results, $post_id); ?>
            <?php endif; ?>

            <?php if ($update_results): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Update process completed successfully.', 'guci'); ?></p>
                </div>
                <?php $this->display_update_results($update_results, $post_id); ?>
            <?php endif; ?>

            <!-- Scan Form -->
            <div class="card">
                <form method="post" action="">
                    <?php wp_nonce_field('google_user_content_scan_nonce'); ?>
                    <h2><?php esc_html_e('Select Post Types to Scan', 'guci'); ?></h2>
                    <?php
                    $post_types = get_post_types(array('public' => true), 'objects');
                    $selected_post_types = get_option('guci_selected_post_types', array('post', 'page'));
                    foreach ($post_types as $post_type) {
                        ?>
                        <label>
                            <input type="checkbox" name="post_types[]" value="<?php echo esc_attr($post_type->name); ?>" 
                                <?php checked(in_array($post_type->name, $selected_post_types)); ?>>
                            <?php echo esc_html($post_type->label); ?>
                        </label><br>
                        <?php
                    }
                    ?>
                    <br>
                    <input type="submit" name="scan_posts" class="button button-primary" value="<?php esc_attr_e('Scan Posts', 'guci'); ?>">
                </form>
            </div>

            <?php if (isset($_GET['scanned'])): ?>
                <?php 
                $unique_images = get_option('google_user_content_unique_images', array());
                if (!empty($unique_images)): 
                ?>
                    <h2><?php esc_html_e('Scan Results (De-duplicated)', 'guci'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="import_all_google_images">
                        <?php wp_nonce_field('import_all_google_images'); ?>
                        <input type="submit" class="button button-primary" value="<?php esc_attr_e('Import and Update All Images', 'guci'); ?>">
                    </form>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Image Preview', 'guci'); ?></th>
                                <th><?php esc_html_e('Image URL', 'guci'); ?></th>
                                <th><?php esc_html_e('Alt Text', 'guci'); ?></th>
                                <th><?php esc_html_e('AI Suggested Name', 'guci'); ?></th>
                                <th><?php esc_html_e('Posts', 'guci'); ?></th>
                                <th><?php esc_html_e('Status', 'guci'); ?></th>
                                <th><?php esc_html_e('Action', 'guci'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($unique_images as $phash => $data): ?>
                                <?php 
                                $image = $data['image']; 
                                $suggested_filename = $this->get_ai_filename_suggestion($image['url']);
                                ?>
                                <tr>
                                    <td><img src="<?php echo esc_url($image['url']); ?>" style="max-width: 100px; max-height: 100px;" alt="<?php echo esc_attr($image['alt_text']); ?>"></td>
                                    <td title="<?php echo esc_attr($image['url']); ?>"><?php echo esc_html($this->truncate_url($image['url'])); ?></td>
                                    <td><?php echo esc_html($image['alt_text']); ?></td>
                                    <td class="ai-filename-cell" data-image-url="<?php echo esc_attr($image['url']); ?>">
                                        <?php 
                                        if (!empty($suggested_filename)) {
                                            echo '<code>' . esc_html($suggested_filename) . '</code>';
                                        } else {
                                            if (get_option('guci_use_ai_naming')) {
                                                echo '<em class="generating">' . esc_html__('Generating...', 'guci') . '</em>';
                                            } else {
                                                echo '<em>' . esc_html__('AI naming disabled', 'guci') . '</em>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php foreach ($data['posts'] as $post): ?>
                                            <a href="<?php echo get_edit_post_link($post['post_id']); ?>"><?php echo esc_html($post['post_title']); ?></a><br>
                                        <?php endforeach; ?>
                                    </td>
                                    <td>
                                        <?php
                                        if (isset($image['existing_attachment_id']) && $image['existing_attachment_id']) {
                                            echo esc_html__('Already in Media Library', 'guci');
                                        } else {
                                            echo esc_html__('New Image', 'guci');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <?php if (!isset($image['existing_attachment_id']) || !$image['existing_attachment_id']): ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <input type="hidden" name="action" value="import_google_image">
                                                <input type="hidden" name="image_url" value="<?php echo esc_url($image['url']); ?>">
                                                <input type="hidden" name="post_id" value="<?php echo esc_attr($data['posts'][0]['post_id']); ?>">
                                                
                                                <?php
                                                    $default_filename = !empty($suggested_filename) ? 
                                                        $suggested_filename : 
                                                        (!empty($image['alt_text']) ? sanitize_file_name($image['alt_text']) : 'imported_image');
                                                ?>
                                                <input type="text" 
                                                       name="custom_filename" 
                                                       value="<?php echo esc_attr($default_filename); ?>" 
                                                       placeholder="<?php esc_attr_e('Enter filename', 'guci'); ?>"
                                                       class="regular-text">
                                                
                                                <?php wp_nonce_field('import_google_image'); ?>
                                                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Import Image', 'guci'); ?>">
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                <input type="hidden" name="action" value="update_google_image">
                                                <input type="hidden" name="image_url" value="<?php echo esc_url($image['url']); ?>">
                                                <input type="hidden" name="post_id" value="<?php echo esc_attr($data['posts'][0]['post_id']); ?>">
                                                <input type="hidden" name="attachment_id" value="<?php echo esc_attr($image['existing_attachment_id']); ?>">
                                                <?php wp_nonce_field('update_google_image'); ?>
                                                <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Update Link', 'guci'); ?>">
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?php esc_html_e('No Google User Content images found in the selected post types.', 'guci'); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Truncate URL
     *
     * @param string $url URL to truncate
     * @param int $max_length Maximum length
     * @return string Truncated URL
     */
    private function truncate_url($url, $max_length = 50) {
        $parts = parse_url($url);
        $path_parts = explode('/', trim($parts['path'], '/'));
        
        // Keep only the first part of the host (e.g., 'lh7-rt' from 'lh7-rt.googleusercontent.com')
        $host_parts = explode('.', $parts['host']);
        $short_host = $host_parts[0];

        // Keep the last part of the path (usually the filename or identifier)
        $last_part = end($path_parts);

        // Construct the shortened URL
        $short_url = $short_host . '/.../' . $last_part;

        // If it's still too long, truncate the last part
        if (strlen($short_url) > $max_length) {
            $last_part_max = $max_length - strlen($short_host) - 6; // 6 for '/.../' and ellipsis
            $last_part = substr($last_part, 0, $last_part_max / 2) . '...' . substr($last_part, -$last_part_max / 2);
            $short_url = $short_host . '/.../' . $last_part;
        }

        return $short_url;
    }

    /**
     * Calculate perceptual hash
     *
     * @param string $image_data Image data
     * @return string|false Perceptual hash or false on failure
     */
    private function calculate_perceptual_hash($image_data) {
        $image = imagecreatefromstring($image_data);
        if (!$image) {
            return false;
        }

        // Resize the image to 8x8
        $resized = imagecreatetruecolor(8, 8);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));

        // Convert to grayscale
        imagefilter($resized, IMG_FILTER_GRAYSCALE);

        // Calculate average value
        $total = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $total += (imagecolorat($resized, $x, $y) & 0xFF);
            }
        }
        $average = $total / 64;

        // Calculate hash
        $hash = 0;
        $index = 0;
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $hash |= ((imagecolorat($resized, $x, $y) & 0xFF) > $average) << $index;
                $index++;
            }
        }

        // Free memory
        imagedestroy($image);
        imagedestroy($resized);

        return sprintf('%016x', $hash);
    }

    /**
     * Get attachment ID by hash
     *
     * @param string $hash Perceptual hash
     * @return int|null Attachment ID or null if not found
     */
    private function get_attachment_id_by_hash($hash) {
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_guci_image_phash' AND meta_value = %s LIMIT 1",
            $hash
        ));
        return $attachment_id ? intval($attachment_id) : null;
    }

    /**
     * Hash existing media
     */
    private function hash_existing_media() {
        $args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
        );
        $query = new WP_Query($args);
        
        foreach ($query->posts as $attachment) {
            $file = get_attached_file($attachment->ID);
            if ($file && file_exists($file)) {
                $image_data = file_get_contents($file);
                $phash = $this->calculate_perceptual_hash($image_data);
                if ($phash !== false) {
                    update_post_meta($attachment->ID, '_guci_image_phash', $phash);
                }
            }
        }
    }

    /**
     * Display import results
     *
     * @param array $results Import results
     * @param int|null $post_id Post ID
     */
    private function display_import_results($results, $post_id = null) {
        ?>
        <div class="import-results">
            <h2><?php echo $post_id ? sprintf(esc_html__('Import Results for Post ID: %d', 'guci'), $post_id) : esc_html__('Import Results', 'guci'); ?></h2>
            
            <h3><?php esc_html_e('Imported Images', 'guci'); ?> (<?php echo count($results['imported']); ?>)</h3>
            <?php if (!empty($results['imported'])): ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Preview', 'guci'); ?></th>
                            <th><?php esc_html_e('Old URL', 'guci'); ?></th>
                            <th><?php esc_html_e('New URL', 'guci'); ?></th>
                            <th><?php esc_html_e('Filename', 'guci'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['imported'] as $import): ?>
                            <tr>
                                <td><img src="<?php echo esc_url($import['new_url']); ?>" style="max-width: 100px; max-height: 100px;" alt="<?php echo esc_attr($import['filename']); ?>"></td>
                                <td><a href="<?php echo esc_url($import['old_url']); ?>" target="_blank"><?php echo esc_html($this->truncate_url($import['old_url'])); ?></a></td>
                                <td><a href="<?php echo esc_url($import['new_url']); ?>" target="_blank"><?php echo esc_html($this->truncate_url($import['new_url'])); ?></a></td>
                                <td><?php echo esc_html($import['filename']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('No images were imported.', 'guci'); ?></p>
            <?php endif; ?>

            <h3><?php esc_html_e('Updated Images', 'guci'); ?> (<?php echo count($results['updated']); ?>)</h3>
            <?php if (!empty($results['updated'])): ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Preview', 'guci'); ?></th>
                            <th><?php esc_html_e('Old URL', 'guci'); ?></th>
                            <th><?php esc_html_e('New URL', 'guci'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['updated'] as $update): ?>
                            <tr>
                                <td><img src="<?php echo esc_url($update['new_url']); ?>" style="max-width: 100px; max-height: 100px;" alt="<?php esc_attr_e('Updated image', 'guci'); ?>"></td>
                                <td><a href="<?php echo esc_url($update['old_url']); ?>" target="_blank"><?php echo esc_html($this->truncate_url($update['old_url'])); ?></a></td>
                                <td><a href="<?php echo esc_url($update['new_url']); ?>" target="_blank"><?php echo esc_html($this->truncate_url($update['new_url'])); ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('No images were updated.', 'guci'); ?></p>
            <?php endif; ?>

            <h3><?php esc_html_e('Errors', 'guci'); ?> (<?php echo count($results['errors']); ?>)</h3>
            <?php if (!empty($results['errors'])): ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('URL', 'guci'); ?></th>
                            <th><?php esc_html_e('Error', 'guci'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['errors'] as $error): ?>
                            <tr>
                                <td><a href="<?php echo esc_url($error['url']); ?>" target="_blank"><?php echo esc_html($this->truncate_url($error['url'])); ?></a></td>
                                <td><?php echo esc_html($error['error']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('No errors occurred during the import/update process.', 'guci'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Import Google image
     */
    public function import_google_image() {
        // ... (import logic)

        // Store the import results in a transient
        set_transient('guci_import_results_single_' . $post_id, $import_results, 60 * 5);

        // Redirect back to the main page
        wp_redirect(add_query_arg(array('page' => 'google-user-content-importer', 'imported' => 'single_' . $post_id), admin_url('admin.php')));
        exit;
    }

    /**
     * Update Google image
     */
    public function update_google_image() {
        // ... (update logic)

        // Store the update results in a transient
        set_transient('guci_update_results_single_' . $post_id, $update_results, 60 * 5);

        // Redirect back to the main page
        wp_redirect(add_query_arg(array('page' => 'google-user-content-importer', 'updated' => 'single_' . $post_id), admin_url('admin.php')));
        exit;
    }

    /**
     * Generate image name using OpenAI
     */
    private function generate_ai_image_name($image_url) {
        $api_key = get_option('guci_openai_api_key');
        if (empty($api_key)) {
            error_log('GUCI: OpenAI API key is not set');
            return false;
        }

        // Validate image URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            error_log('GUCI: Invalid image URL format: ' . $image_url);
            return false;
        }

        $request_body = array(
            'model' => 'gpt-4o-mini',  // Changed from gpt-4-vision-preview to gpt-4o-mini
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => array(
                        array(
                            'type' => 'text',
                            'text' => 'Generate a short, SEO-friendly filename (without extension) for this image. Use only lowercase letters, numbers, and hyphens. Keep it under 50 characters. Respond with only the filename.'
                        ),
                        array(
                            'type' => 'image_url',
                            'image_url' => array(
                                'url' => $image_url
                            )
                        )
                    )
                )
            ),
            'max_tokens' => 50
        );

        error_log('GUCI: Sending request to OpenAI API for URL: ' . $image_url);

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($request_body),
            'timeout' => 30,
            'data_format' => 'body'
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('GUCI OpenAI Error: ' . $error_message);
            throw new Exception('API request failed: ' . $error_message);
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        error_log('GUCI OpenAI Response Code: ' . $response_code);
        error_log('GUCI OpenAI Response Body: ' . $response_body);

        if ($response_code !== 200) {
            throw new Exception('API returned non-200 status code: ' . $response_code . ' - ' . $response_body);
        }

        $body = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Failed to parse JSON response: ' . json_last_error_msg());
        }

        if (empty($body['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response structure from API');
        }

        $filename = strtolower(trim($body['choices'][0]['message']['content']));
        $filename = preg_replace('/[^a-z0-9-]/', '-', $filename);
        $filename = preg_replace('/-+/', '-', $filename);
        $filename = trim($filename, '-');

        if (empty($filename)) {
            throw new Exception('Generated filename is empty after cleanup');
        }

        return $filename;
    }

    /**
     * Handle AJAX request for filename generation
     */
    public function ajax_generate_image_filename() {
        try {
            if (!current_user_can('upload_files')) {
                throw new Exception('Permission denied');
            }

            $image_url = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
            if (empty($image_url)) {
                throw new Exception('Invalid image URL');
            }

            if (empty(get_option('guci_openai_api_key'))) {
                throw new Exception('OpenAI API key is not configured');
            }

            $suggested_filename = $this->generate_ai_image_name($image_url);
            if ($suggested_filename) {
                wp_send_json_success(array('filename' => $suggested_filename));
            } else {
                throw new Exception('Failed to generate filename');
            }
        } catch (Exception $e) {
            error_log('GUCI Error in ajax_generate_image_filename: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Generation failed',
                'details' => $e->getMessage(),
                'code' => 'generation_failed'
            ));
        }
    }

    /**
     * Get cached AI filename suggestion
     * 
     * @param string $image_url The image URL
     * @return string|null Cached filename or null if not cached
     */
    private function get_cached_ai_filename($image_url) {
        $cached_filenames = get_option('guci_ai_filename_cache', array());
        return isset($cached_filenames[$image_url]) ? $cached_filenames[$image_url] : null;
    }

    /**
     * Cache AI filename suggestion
     * 
     * @param string $image_url The image URL
     * @param string $filename The suggested filename
     */
    private function cache_ai_filename($image_url, $filename) {
        $cached_filenames = get_option('guci_ai_filename_cache', array());
        $cached_filenames[$image_url] = $filename;
        update_option('guci_ai_filename_cache', $cached_filenames);
    }

    /**
     * Get AI filename suggestion with caching
     * 
     * @param string $image_url The image URL
     * @return string The suggested filename
     */
    private function get_ai_filename_suggestion($image_url) {
        // Check cache first
        $cached_filename = $this->get_cached_ai_filename($image_url);
        if ($cached_filename !== null) {
            return $cached_filename;
        }

        // Generate new suggestion if AI naming is enabled
        if (get_option('guci_use_ai_naming')) {
            error_log('GUCI: AI naming is enabled, generating filename for ' . $image_url);
            $ai_filename = $this->generate_ai_image_name($image_url);
            if ($ai_filename) {
                $this->cache_ai_filename($image_url, $ai_filename);
                return $ai_filename;
            }
            error_log('GUCI: Failed to generate AI filename');
        } else {
            error_log('GUCI: AI naming is disabled');
        }

        return '';
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook The current admin page
     */
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_google-user-content-importer' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'guci-admin',
            plugins_url('js/admin.js', __FILE__),
            array('jquery'),
            '1.0.0',
            true
        );
    }
}

// Initialize the plugin
GoogleUserContentImporter::get_instance();

