<?php
/**
 * Plugin Name: Google Reviews to CPT
 * Plugin URI: https://github.com/thebusinesstoolkitdev/google-reviews-cpt
 * Description: Fetches Google reviews via API and stores them as Custom Post Types. Stores review text in a WYSIWYG custom field for better page builder compatibility.
 * Version: 1.2.0
 * Author: The Business Toolkit
 * Author URI: https://www.thebusinesstoolkit.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: google-reviews-cpt
 * GitHub Plugin URI: thebusinesstoolkitdev/google-reviews-cpt
 * GitHub Branch: main
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

        // NEW: Add Meta Box hooks for WYSIWYG Editor
        add_action('add_meta_boxes', array($this, 'add_review_meta_box'));
        add_action('save_post', array($this, 'save_review_meta_data'));
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
            // UPDATED: Removed 'editor' support to force usage of custom field
            'supports' => array('title', 'custom-fields'), 
            'rewrite' => array('slug' => 'reviews'),
            'capability_type' => 'post',
        );
        
        register_post_type($this->post_type, $args);
    }

    /**
     * NEW: Register the WYSIWYG Meta Box
     */
    public function add_review_meta_box() {
        add_meta_box(
            'google_review_content_box', // ID
            'Review Content (WYSIWYG)',  // Title
            array($this, 'render_review_content_box'), // Callback
            $this->post_type,            // Screen
            'normal',                    // Context
            'high'                       // Priority
        );
    }

    /**
     * NEW: Render the WYSIWYG Editor
     */
    public function render_review_content_box($post) {
        // Retrieve the existing value
        $content = get_post_meta($post->ID, 'review_full_text', true);
        
        // Use wp_editor to create a real WYSIWYG field
        wp_editor($content, 'review_full_text_editor', array(
            'media_buttons' => false,
            'textarea_name' => 'review_full_text', // This name is what we check in $_POST
            'textarea_rows' => 10,
            'teeny'         => true // Minimal toolbar
        ));
        
        // Add a nonce for security
        wp_nonce_field('save_review_content_nonce', 'review_content_nonce');
    }

    /**
     * NEW: Save the WYSIWYG Content (Manual Edits)
     */
    public function save_review_meta_data($post_id) {
        // Security checks
        if (!isset($_POST['review_content_nonce']) || 
            !wp_verify_nonce($_POST['review_content_nonce'], 'save_review_content_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        
        // Save the data
        if (isset($_POST['review_full_text'])) {
            update_post_meta($post_id, 'review_full_text', wp_kses_post($_POST['review_full_text']));
        }
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
        register_setting('google_reviews_settings', 'google_reviews_api_version');
        
        add_settings_section(
            'google_reviews_main_section',
            'Google API Configuration',
            array($this, 'settings_section_callback'),
            'google-reviews-settings'
        );
        
        add_settings_field(
            'api_version',
            'API Version',
            array($this, 'api_version_callback'),
            'google-reviews-settings',
            'google_reviews_main_section'
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
        echo '<p><strong>Important:</strong> You need to enable the Places API (New) in your Google Cloud Console and ensure billing is set up.</p>';
        echo '<p><a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank">Enable Places API (New)</a></p>';
    }
    
    public function api_version_callback() {
        $api_version = get_option('google_reviews_api_version', 'new');
        ?>
        <select name="google_reviews_api_version">
            <option value="new" <?php selected($api_version, 'new'); ?>>Places API (New) - Recommended</option>
            <option value="legacy" <?php selected($api_version, 'legacy'); ?>>Places API (Legacy)</option>
        </select>
        <p class="description">Use "Places API (New)" if you have it enabled. Fallback to "Legacy" if needed.</p>
        <?php
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
            
            <?php
            // Display sync messages
            if (isset($_GET['sync_success'])) {
                echo '<div class="notice notice-success"><p>Successfully synced ' . intval($_GET['sync_success']) . ' review(s)!</p></div>';
            }
            if (isset($_GET['sync_error'])) {
                echo '<div class="notice notice-error"><p>Error: ' . esc_html(urldecode($_GET['sync_error'])) . '</p></div>';
            }
            ?>
            
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
            <p><strong>UPDATED v1.2:</strong> Reviews are now stored in a Custom Field for better formatting.</p>
            
            <h3>Available Data Fields</h3>
            <ul>
                <li><strong>Post Title:</strong> Reviewer Name</li>
                <li><strong>Custom Field "review_full_text":</strong> The Review Content (Use this instead of Post Content)</li>
                <li><strong>Custom Field "review_rating":</strong> Star Rating (1-5)</li>
                <li><strong>Custom Field "review_date":</strong> Review Date (Y-m-d H:i:s)</li>
                <li><strong>Custom Field "review_timestamp":</strong> Unix Timestamp</li>
                <li><strong>Custom Field "reviewer_photo_url":</strong> Reviewer Photo URL</li>
            </ul>
            
            <h3>Quick Start Guides</h3>
            <ul>
                <li><strong>Greenshift:</strong> Dynamic Field Block → Source: "Custom Field" → Key: <code>review_full_text</code></li>
                <li><strong>Bricks:</strong> Basic Text → Dynamic Data → <code>{cf_review_full_text}</code></li>
                <li><strong>Elementor Pro:</strong> Text Editor → Dynamic Tag → Post Custom Field → Key: <code>review_full_text</code></li>
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
        $api_version = get_option('google_reviews_api_version', 'new');
        
        if (!$api_key || !$place_id) {
            return new WP_Error('missing_credentials', 'API Key or Place ID not configured.');
        }
        
        if ($api_version === 'new') {
            return $this->fetch_reviews_new_api($api_key, $place_id);
        } else {
            return $this->fetch_reviews_legacy_api($api_key, $place_id);
        }
    }
    
    /**
     * Fetch reviews using NEW Places API
     */
    private function fetch_reviews_new_api($api_key, $place_id) {
        // New API endpoint
        $url = 'https://places.googleapis.com/v1/places/' . $place_id;
        
        // Make API request with new format
        $response = wp_remote_get($url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $api_key,
                'X-Goog-FieldMask' => 'reviews'
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Check for API errors
        if (isset($data['error'])) {
            return new WP_Error('api_error', $data['error']['message']);
        }
        
        if (!isset($data['reviews']) || empty($data['reviews'])) {
            return new WP_Error('no_reviews', 'No reviews found in API response. Make sure your business has public reviews.');
        }
        
        return $this->process_reviews($data['reviews'], 'new');
    }
    
    /**
     * Fetch reviews using LEGACY Places API
     */
    private function fetch_reviews_legacy_api($api_key, $place_id) {
        // Legacy API endpoint
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
        
        // Check for API errors
        if (isset($data['error_message'])) {
            return new WP_Error('api_error', $data['error_message']);
        }
        
        if (!isset($data['result']['reviews'])) {
            return new WP_Error('no_reviews', 'No reviews found in API response. Try enabling "Places API (New)" in settings.');
        }
        
        return $this->process_reviews($data['result']['reviews'], 'legacy');
    }
    
    /**
     * Process and store reviews
     */
    private function process_reviews($reviews, $api_type) {
        $new_count = 0;
        $updated_count = 0;
        
        foreach ($reviews as $review) {
            // Handle different field names between APIs
            if ($api_type === 'new') {
                $author_name = isset($review['authorAttribution']['displayName']) ? $review['authorAttribution']['displayName'] : 'Anonymous';
                $text = isset($review['text']['text']) ? $review['text']['text'] : '';
                $rating = isset($review['rating']) ? $review['rating'] : 0;
                $time = isset($review['publishTime']) ? strtotime($review['publishTime']) : time();
                $photo_url = isset($review['authorAttribution']['photoUri']) ? $review['authorAttribution']['photoUri'] : '';
            } else {
                $author_name = isset($review['author_name']) ? $review['author_name'] : 'Anonymous';
                $text = isset($review['text']) ? $review['text'] : '';
                $rating = isset($review['rating']) ? $review['rating'] : 0;
                $time = isset($review['time']) ? $review['time'] : time();
                $photo_url = isset($review['profile_photo_url']) ? $review['profile_photo_url'] : '';
            }
            
            $review_id = 'google_' . $time . '_' . md5($author_name);
            
            // Check if review already exists
            $existing = get_posts(array(
                'post_type' => $this->post_type,
                'meta_key' => 'review_id',
                'meta_value' => $review_id,
                'posts_per_page' => 1
            ));
            
            $post_data = array(
                'post_title'   => sanitize_text_field($author_name),
                'post_content' => '', // UPDATED: Content is now stored in custom field
                'post_status'  => 'publish',
                'post_type'    => $this->post_type,
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
            
            // UPDATED: Save the text to the new WYSIWYG field
            update_post_meta($post_id, 'review_full_text', wp_kses_post($text));

            // Save meta data
            update_post_meta($post_id, 'review_id', $review_id);
            update_post_meta($post_id, 'review_rating', intval($rating));
            update_post_meta($post_id, 'review_date', date('Y-m-d H:i:s', $time));
            update_post_meta($post_id, 'reviewer_photo_url', esc_url_raw($photo_url));
            update_post_meta($post_id, 'review_timestamp', intval($time));
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