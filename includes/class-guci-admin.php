<?php
/**
 * GUCI Admin class
 */
class GUCI_Admin {
    /**
     * Display the results page in admin
     */
    public function display_results_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'guci'));
        }

        // ... (implementation remains largely unchanged, but with improved organization)
    }

    // ... (other admin-related methods from the original class)
}