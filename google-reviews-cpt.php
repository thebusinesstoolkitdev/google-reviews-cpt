<?php
/**
 * Plugin Name: Google Reviews to CPT
 * Plugin URI: https://github.com/yourusername/google-reviews-cpt
 * Description: Fetches Google reviews via API and stores them as Custom Post Types, compatible with all major page builders (Bricks, Elementor, Greenshift, Oxygen, etc.)
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://www.thebusinesstoolkit.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: google-reviews-cpt
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Google_Reviews_CPT {
    
    private $post_type = 'google_review';
    
    public function __construct() {
        // Register CPT
        add_action('init', array($this, 'register_cpt'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_settings_page'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Schedule cron job
        add_action('wp', array($this, 'schedule_review_fetch'));
        
        // Hook the actual fetch function to our custom cron event
        add_action('fetch_google_reviews_event', array($this, 'fetch_and_store_reviews'));
        
        // Add manual sync button action
        add_action('admin_post_sync_google_reviews', array($this, 'manual_sync'));
        
        // Add custom columns to admin
        add_filter('manage_' . $this->post_type . '_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_' . $this->post_type . '_posts_custom_column', array($this, 'display_custom_columns'), 10, 2);
    }
    
    /**
     * Register the Custom Post Type
     */
    public function register_cpt() {
        $labels = array(
            'name' => 'Google Reviews',
            'singular_name' => 'Google Review',
            'menu_name' => 'Google Reviews',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Review',
            'edit_item' => 'Edit Review',
            'new_item' => 'New Review',
            'view_item' => 'View Review',
            'search_items' => 'Search Reviews',
            'not_found' => 'No reviews found',
            'not_found_in_trash' => 'No reviews found in trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'has_archive' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-star-filled',
            'supports' => array('title', 'editor', 'custom-fields'),
            'rewrite' => array('slug' => 'reviews'),
            'capability_type' => 'post',
        );
        
        register_post_type($this->post_type, $args);
    }
    
    /**
     * Add settings page to WordPress admin
     */
    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            'Settings',
            'Settings',
            'manage_options',
            'google-reviews-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('google_reviews_settings', 'google_reviews_api_key');
        register_setting('google_reviews_settings', 'google_reviews_place_id');
        register_setting('google_reviews_settings', 'google_reviews_sync_frequency');
        
        add_settings_section(
            'google_reviews_main_section',
            'Google API Configuration',
            array($this, 'settings_section_callback'),
            'google-reviews-settings'
        );
        
        add_settings_field(
            'api_key',
            'Google API Key',
            array($this, 'api_key_callback'),
            'google-reviews-settings',
            'google_reviews_main_section'
        );
        
        add_settings_field(
            'place_id',
            'Google Place ID',
            array($this, 'place_id_callback'),
            'google-reviews-settings',
            'google_reviews_main_section'
        );
        
        add_settings_field(
            'sync_frequency',
            'Sync Frequency',
            array($this, 'sync_frequency_callback'),
            'google-reviews-settings',
            'google_reviews_main_section'
        );
    }
    
    public function settings_section_callback() {
        echo '<p>Configure your Google Places API credentials below.</p>';
        echo '<p><strong>Important:</strong> You need to enable the Places API in your Google Cloud Console and ensure billing is set up.</p>';
    }
    
    public function api_key_callback() {
        $api_key = get_option('google_reviews_api_key', '');
        echo '<input type="text" name="google_reviews_api_key" value="' . esc_attr($api_key) . '" class="regular-text" />';
        echo '<p class="description">Your Google Places API key from Google Cloud Console.</p>';
    }
    
    public function place_id_callback() {
        $place_id = get_option('google_reviews_place_id', '');
        echo '<input type="text" name="google_reviews_place_id" value="' . esc_attr($place_id) . '" class="regular-text" />';
        echo '<p class="description">The Place ID of your business. Find it at <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">developers.google.com</a></p>';
    }
    
    public function sync_frequency_callback() {
        $frequency = get_option('google_reviews_sync_frequency', 'daily');
        ?>
        <select name="google_reviews_sync_frequency">
            <option value="hourly" <?php selected($frequency, 'hourly'); ?>>Hourly</option>
            <option value="twicedaily" <?php selected($frequency, 'twicedaily'); ?>>Twice Daily</option>
            <option value="daily" <?php selected($frequency, 'daily'); ?>>Daily</option>
            <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Weekly</option>
        </select>
        <p class="description">How often should the plugin check for new reviews?</p>
        <?php
    }
    
    /**
     * Render the settings page
     */
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Google Reviews Settings</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('google_reviews_settings');
                do_settings_sections('google-reviews-settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2>Manual Sync</h2>
            <p>Click the button below to manually fetch reviews now.</p>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="sync_google_reviews">
                <?php wp_nonce_field('sync_google_reviews_nonce'); ?>
                <?php submit_button('Sync Reviews Now', 'secondary', 'submit', false); ?>
            </form>
            
            <hr>
            
            <h2>Next Scheduled Sync</h2>
            <?php
            $next_run = wp_next_scheduled('fetch_google_reviews_event');
            if ($next_run) {
                echo '<p>Next automatic sync: <strong>' . date('F j, Y g:i a', $next_run) . '</strong></p>';
            } else {
                echo '<p>No sync scheduled. Save your settings to schedule automatic syncing.</p>';
            }
            ?>
            
            <hr>
            
            <h2>How to Use with Page Builders</h2>
            <p>This plugin is compatible with all major page builders. Reviews are stored as Custom Post Types with the following data:</p>
            
            <h3>Available Data Fields</h3>
            <ul>
                <li><strong>Post Title:</strong> Reviewer Name</li>
                <li><strong>Post Content:</strong> Review Text</li>
                <li><strong>Custom Field "review_rating":</strong> Star Rating (1-5)</li>
                <li><strong>Custom Field "review_date":</strong> Review Date (Y-m-d H:i:s)</li>
                <li><strong>Custom Field "review_timestamp":</strong> Unix Timestamp</li>
                <li><strong>Custom Field "reviewer_photo_url":</strong> Reviewer Photo URL</li>
            </ul>
            
            <h3>Quick Start Guides</h3>
            <ul>
                <li><strong>Bricks:</strong> Query Loop → Set post type to "Google Reviews" → Use dynamic data tags</li>
                <li><strong>Elementor Pro:</strong> Loop Grid/Carousel → Create template → Source: "Google Reviews"</li>
                <li><strong>Greenshift:</strong> Query Loop block → Post type: "Google Reviews" → Add dynamic data blocks</li>
                <li><strong>Oxygen:</strong> Repeater → Query: "google_review" post type → Use dynamic data</li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Schedule the cron job
     */
    public function schedule_review_fetch() {
        $frequency = get_option('google_reviews_sync_frequency', 'daily');
        
        // Clear existing schedule
        $timestamp = wp_next_scheduled('fetch_google_reviews_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fetch_google_reviews_event');
        }
        
        // Schedule new event if API key and Place ID are set
        $api_key = get_option('google_reviews_api_key');
        $place_id = get_option('google_reviews_place_id');
        
        if ($api_key && $place_id) {
            if (!wp_next_scheduled('fetch_google_reviews_event')) {
                wp_schedule_event(time(), $frequency, 'fetch_google_reviews_event');
            }
        }
    }
    
    /**
     * Manual sync handler
     */
    public function manual_sync() {
        check_admin_referer('sync_google_reviews_nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->fetch_and_store_reviews();
        
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(array(
                'post_type' => $this->post_type,
                'page' => 'google-reviews-settings',
                'sync_error' => urlencode($result->get_error_message())
            ), admin_url('edit.php')));
        } else {
            wp_redirect(add_query_arg(array(
                'post_type' => $this->post_type,
                'page' => 'google-reviews-settings',
                'sync_success' => $result
            ), admin_url('edit.php')));
        }
        exit;
    }
    
    /**
     * Fetch reviews from Google API and store as CPT
     */
    public function fetch_and_store_reviews() {
        $api_key = get_option('google_reviews_api_key');
        $place_id = get_option('google_reviews_place_id');
        
        if (!$api_key || !$place_id) {
            return new WP_Error('missing_credentials', 'API Key or Place ID not configured.');
        }
        
        // Build API URL
        $url = add_query_arg(array(
            'place_id' => $place_id,
            'fields' => 'reviews',
            'key' => $api_key
        ), 'https://maps.googleapis.com/maps/api/place/details/json');
        
        // Make API request
        $response = wp_remote_get($url, array(
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['result']['reviews'])) {
            return new WP_Error('no_reviews', 'No reviews found in API response.');
        }
        
        $reviews = $data['result']['reviews'];
        $new_count = 0;
        $updated_count = 0;
        
        foreach ($reviews as $review) {
            $review_id = 'google_' . $review['time']; // Use timestamp as unique ID
            
            // Check if review already exists
            $existing = get_posts(array(
                'post_type' => $this->post_type,
                'meta_key' => 'review_id',
                'meta_value' => $review_id,
                'posts_per_page' => 1
            ));
            
            $post_data = array(
                'post_title' => sanitize_text_field($review['author_name']),
                'post_content' => wp_kses_post($review['text']),
                'post_status' => 'publish',
                'post_type' => $this->post_type,
            );
            
            if (!empty($existing)) {
                // Update existing review
                $post_data['ID'] = $existing[0]->ID;
                wp_update_post($post_data);
                $post_id = $existing[0]->ID;
                $updated_count++;
            } else {
                // Create new review
                $post_id = wp_insert_post($post_data);
                $new_count++;
            }
            
            // Save meta data
            update_post_meta($post_id, 'review_id', $review_id);
            update_post_meta($post_id, 'review_rating', intval($review['rating']));
            update_post_meta($post_id, 'review_date', date('Y-m-d H:i:s', $review['time']));
            update_post_meta($post_id, 'reviewer_photo_url', esc_url_raw($review['profile_photo_url']));
            update_post_meta($post_id, 'review_timestamp', intval($review['time']));
        }
        
        return $new_count + $updated_count;
    }
    
    /**
     * Add custom columns to admin list
     */
    public function add_custom_columns($columns) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = 'Reviewer';
        $new_columns['rating'] = 'Rating';
        $new_columns['review_date'] = 'Date';
        $new_columns['date'] = $columns['date'];
        
        return $new_columns;
    }
    
    /**
     * Display custom column content
     */
    public function display_custom_columns($column, $post_id) {
        switch ($column) {
            case 'rating':
                $rating = get_post_meta($post_id, 'review_rating', true);
                if ($rating) {
                    echo str_repeat('⭐', intval($rating));
                }
                break;
            case 'review_date':
                $date = get_post_meta($post_id, 'review_date', true);
                if ($date) {
                    echo date('M j, Y', strtotime($date));
                }
                break;
        }
    }
}

// Initialize the plugin
new Google_Reviews_CPT();

// Activation hook
register_activation_hook(__FILE__, function() {
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('fetch_google_reviews_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fetch_google_reviews_event');
    }
    flush_rewrite_rules();
});