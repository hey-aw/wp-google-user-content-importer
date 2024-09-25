<?php
/**
 * Plugin Name: Google User Content Importer (GUCI)
 * Description: Scans posts for Google User Content images, reports metadata, and allows importing.
 * Version: 2.9
 * Author: Your Name
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
        add_action('admin_post_import_post_google_images', array($this, 'import_post_google_images'));
        add_action('admin_post_import_all_google_images', array($this, 'import_all_google_images'));
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
            // Check if this is the first scan
            $first_scan = get_option('guci_first_scan_completed', false);
            
            if (!$first_scan) {
                // Hash existing media
                $this->hash_existing_media();
                update_option('guci_first_scan_completed', true);
            }

            $posts = get_posts(array(
                'numberposts' => -1,
                'post_status' => array('publish', 'draft') // Include both published and draft posts
            ));
            $results = array();

            foreach ($posts as $post) {
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
                        }
                    }
                    $results[intval($post->ID)] = $images;
                }
            }

            update_option('google_user_content_scan_results', $results);
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
        $dom = new DOMDocument();
        @$dom->loadHTML($content);
        $images = array();
        foreach ($dom->getElementsByTagName('img') as $img) {
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

        $results = get_option('google_user_content_scan_results', array());
        $all_filenames = isset($_POST['all_filenames']) ? $_POST['all_filenames'] : array();

        $total_imported = 0;
        $total_errors = 0;

        // Start output buffering
        ob_start();
        echo '<div class="wrap"><h2>' . esc_html__('Importing all images', 'guci') . '</h2>';
        ob_flush();
        flush();

        $results = array(
            'imported' => array(),
            'updated' => array(),
            'errors' => array()
        );

        foreach ($results as $post_id => $images) {
            $post = get_post($post_id);
            $content = wp_kses_post($post->post_content);

            echo '<h3>' . sprintf(__('Processing Post ID: %d', 'guci'), esc_html($post_id)) . '</h3>';
            ob_flush();
            flush();

            foreach ($images as $index => $image) {
                $custom_filename = isset($all_filenames[$post_id][$index]) ? sanitize_file_name($all_filenames[$post_id][$index]) : 'imported_image';
                $image['custom_filename'] = $custom_filename;
                
                if (isset($image['existing_attachment_id']) && $image['existing_attachment_id']) {
                    // Update existing image
                    $existing_url = wp_get_attachment_url($image['existing_attachment_id']);
                    $new_img_tag = str_replace($image['url'], $existing_url, $image['tag']);
                    $content = str_replace($image['tag'], $new_img_tag, $content);
                    $results['updated'][] = array(
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
                        $results['imported'][] = array(
                            'old_url' => $image['url'],
                            'new_url' => $import_result['new_url'],
                            'filename' => $import_result['filename']
                        );
                        $total_imported++;
                        echo '<p>' . sprintf(__('Imported: %s as %s', 'guci'), esc_html($custom_filename ?: $image['filename']), esc_html($import_result['filename'])) . '</p>';
                    } else {
                        $total_errors++;
                        echo '<p>' . sprintf(__('Error importing: %s', 'guci'), esc_html($custom_filename ?: $image['filename'])) . '</p>';
                        $results['errors'][] = array(
                            'url' => $image['url'],
                            'error' => $import_result->get_error_message()
                        );
                    }
                }
                ob_flush();
                flush();
            }

            wp_update_post(array(
                'ID' => $post_id,
                'post_content' => $content
            ));
        }

        echo '<p>' . sprintf(__('Import completed. Successfully imported: %d images. Errors: %d', 'guci'), $total_imported, $total_errors) . '</p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=google-user-content-importer')) . '" class="button">' . esc_html__('Back to Importer', 'guci') . '</a>';
        echo '</div>';
        ob_end_flush();
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
            delete_transient('guci_import_results_' . $post_id); // Clean up after displaying
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

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Google User Content Importer (GUCI)', 'guci'); ?></h1>
            
            <?php if ($import_results): ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e('Import process completed.', 'guci'); ?></p>
                </div>
                <?php $this->display_import_results($import_results, $post_id); ?>
            <?php endif; ?>

            <?php if ($update_results): ?>
                <div class="notice notice-success">
                    <p><?php esc_html_e('Update process completed.', 'guci'); ?></p>
                </div>
                <?php $this->display_update_results($update_results, $post_id); ?>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('google_user_content_scan_nonce'); ?>
                <input type="submit" name="scan_posts" class="button button-primary" value="<?php esc_attr_e('Scan Posts', 'guci'); ?>">
            </form>

            <?php if (isset($_GET['scanned'])): ?>
                <?php if (!empty($results)): ?>
                    <h2><?php esc_html_e('Scan Results', 'guci'); ?></h2>
                    <?php foreach ($results as $post_id => $images): ?>
                        <h3><?php echo esc_html(sprintf(__('Post ID: %d - %s', 'guci'), $post_id, get_the_title($post_id))); ?></h3>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="import_post_google_images">
                            <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
                            <?php wp_nonce_field('import_post_google_images'); ?>
                            <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Import/Update All Images for this Post', 'guci'); ?>">
                        </form>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Image Preview', 'guci'); ?></th>
                                    <th><?php esc_html_e('Image URL', 'guci'); ?></th>
                                    <th><?php esc_html_e('Alt Text', 'guci'); ?></th>
                                    <th><?php esc_html_e('Perceptual Hash', 'guci'); ?></th>
                                    <th><?php esc_html_e('Status', 'guci'); ?></th>
                                    <th><?php esc_html_e('Action', 'guci'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($images as $index => $image): ?>
                                    <tr>
                                        <td><img src="<?php echo esc_url($image['url']); ?>" style="max-width: 100px; max-height: 100px;" alt="<?php echo esc_attr($image['alt_text']); ?>"></td>
                                        <td title="<?php echo esc_attr($image['url']); ?>"><?php echo esc_html($this->truncate_url($image['url'])); ?></td>
                                        <td><?php echo esc_html($image['alt_text']); ?></td>
                                        <td><?php echo esc_html($image['phash'] ?? 'N/A'); ?></td>
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
                                                    <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
                                                    
                                                    <?php
                                                        $default_filename = !empty($image['alt_text']) ? sanitize_file_name($image['alt_text']) : 'imported_image';
                                                    ?>
                                                    <input type="text" name="custom_filename" value="<?php echo esc_attr($default_filename); ?>" placeholder="<?php esc_attr_e('Enter filename', 'guci'); ?>">
                                                    
                                                    <?php wp_nonce_field('import_google_image'); ?>
                                                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e('Import Image', 'guci'); ?>">
                                                </form>
                                            <?php else: ?>
                                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                                    <input type="hidden" name="action" value="update_google_image">
                                                    <input type="hidden" name="image_url" value="<?php echo esc_url($image['url']); ?>">
                                                    <input type="hidden" name="post_id" value="<?php echo esc_attr($post_id); ?>">
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
                    <?php endforeach; ?>
                <?php else: ?>
                    <p><?php esc_html_e('No Google User Content images found in any posts.', 'guci'); ?></p>
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
}

// Initialize the plugin
GoogleUserContentImporter::get_instance();