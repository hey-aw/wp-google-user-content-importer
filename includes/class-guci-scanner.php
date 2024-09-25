<?php
/**
 * GUCI Scanner class
 */
class GUCI_Scanner {
    /**
     * Scan posts for Google User Content images
     *
     * @param int $batch_size Number of posts to scan in each batch
     * @param int $offset Offset for pagination
     * @return array Scan results
     */
    public function scan_posts($batch_size = 20, $offset = 0) {
        $posts = get_posts(array(
            'numberposts' => $batch_size,
            'offset' => $offset,
            'post_status' => array('publish', 'draft')
        ));

        $results = array();

        foreach ($posts as $post) {
            $images = $this->find_google_images(wp_kses_post($post->post_content));
            if (!empty($images)) {
                foreach ($images as &$image) {
                    $image = $this->process_image($image);
                }
                $results[intval($post->ID)] = $images;
            }
        }

        return $results;
    }

    // ... (other methods from the original class, with improved documentation)
}