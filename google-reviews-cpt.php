<?php
/**
 * Plugin Name: Google Reviews to CPT
 * Plugin URI: https://github.com/thebusinesstoolkitdev/google-reviews-cpt
 * Description: Fetches Google reviews via API and stores them as Custom Post Types. Stores review text in a WYSIWYG custom field for better page builder compatibility. Uses dual-fetch on Legacy API to capture up to 10 reviews.
 * Version: 1.4.0
 * Author: The Business Toolkit
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

/**
 * ─────────────────────────────────────────────
 * AUTO-UPDATES FROM GITHUB via Plugin Update Checker
 * https://github.com/YahniSElsts/plugin-update-checker
 * ─────────────────────────────────────────────
 */
require plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$grcp_updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/thebusinesstoolkitdev/google-reviews-cpt/',
    __FILE__,
    'google-reviews-cpt'
);

// Use releases/tags for stable versions (recommended)
$grcp_updateChecker->getVcsApi()->enableReleaseAssets();

// If your repo is private, uncomment and add your token:
// $grcp_updateChecker->setAuthentication('your-github-personal-access-token');


class Google_Reviews_CPT {
    
    private $post_type = 'google_review';
    
    public function __construct() {
        add_action('init', array($this, 'register_cpt'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Custom cron schedules
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        // Schedule cron only when settings change or on activation
        add_action('update_option_google_reviews_sync_frequency', array($this, 'reschedule_review_fetch'), 10, 2);
        add_action('update_option_google_reviews_api_key', array($this, 'maybe_schedule_review_fetch'));
        add_action('update_option_google_reviews_place_id', array($this, 'maybe_schedule_review_fetch'));
        
        // Cron event handler
        add_action('fetch_google_reviews_event', array($this, 'fetch_and_store_reviews'));
        
        // Manual sync
        add_action('admin_post_sync_google_reviews', array($this, 'manual_sync'));
        
        // Admin columns
        add_filter('manage_' . $this->post_type . '_posts_columns', array($this, 'add_custom_columns'));
        add_action('manage_' . $this->post_type . '_posts_custom_column', array($this, 'display_custom_columns'), 10, 2);

        // WYSIWYG Meta Box
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
            'supports' => array('title', 'custom-fields'), 
            'rewrite' => array('slug' => 'reviews'),
            'capability_type' => 'post',
        );
        
        register_post_type($this->post_type, $args);
    }

    /**
     * Register the WYSIWYG Meta Box
     */
    public function add_review_meta_box() {
        add_meta_box(
            'google_review_content_box',
            'Review Content',
            array($this, 'render_review_content_box'),
            $this->post_type,
            'normal',
            'high'
        );
    }

    /**
     * Render the WYSIWYG Editor
     */
    public function render_review_content_box($post) {
        $content = get_post_meta($post->ID, 'review_full_text', true);
        
        echo '<p class="description" style="margin-bottom: 10px;">';
        echo 'Edit the review content below. Your changes are safe — automatic syncs will <strong>never overwrite</strong> manually edited reviews.';
        echo '</p>';
        
        wp_editor($content, 'review_full_text_editor', array(
            'media_buttons' => false,
            'textarea_name' => 'review_full_text',
            'textarea_rows' => 10,
            'teeny'         => true
        ));
        
        wp_nonce_field('save_review_content_nonce', 'review_content_nonce');
    }

    /**
     * Save the WYSIWYG Content (Manual Edits)
     */
    public function save_review_meta_data($post_id) {
        if (!isset($_POST['review_content_nonce']) || 
            !wp_verify_nonce($_POST['review_content_nonce'], 'save_review_content_nonce')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        
        if (isset($_POST['review_full_text'])) {
            update_post_meta($post_id, 'review_full_text', wp_kses_post($_POST['review_full_text']));
        }
    }
    
    /**
     * Register the 'weekly' cron schedule (not a WP default)
     */
    public function add_custom_cron_schedules($schedules) {
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display'  => __('Once Weekly', 'google-reviews-cpt')
        );
        return $schedules;
    }
    
    /**
     * Add settings page
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
     * Register plugin settings with sanitization
     */
    public function register_settings() {
        register_setting('google_reviews_settings', 'google_reviews_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        register_setting('google_reviews_settings', 'google_reviews_place_id', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        ));
        register_setting('google_reviews_settings', 'google_reviews_sync_frequency', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_sync_frequency'),
            'default' => 'daily',
        ));
        register_setting('google_reviews_settings', 'google_reviews_api_version', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_api_version'),
            'default' => 'legacy',
        ));
        
        add_settings_section(
            'google_reviews_main_section',
            'Google API Configuration',
            array($this, 'settings_section_callback'),
            'google-reviews-settings'
        );
        
        add_settings_field('api_version', 'API Version', array($this, 'api_version_callback'), 'google-reviews-settings', 'google_reviews_main_section');
        add_settings_field('api_key', 'Google API Key', array($this, 'api_key_callback'), 'google-reviews-settings', 'google_reviews_main_section');
        add_settings_field('place_id', 'Google Place ID', array($this, 'place_id_callback'), 'google-reviews-settings', 'google_reviews_main_section');
        add_settings_field('sync_frequency', 'Sync Frequency', array($this, 'sync_frequency_callback'), 'google-reviews-settings', 'google_reviews_main_section');
    }
    
    public function sanitize_sync_frequency($value) {
        $allowed = array('hourly', 'twicedaily', 'daily', 'weekly');
        return in_array($value, $allowed, true) ? $value : 'daily';
    }
    
    public function sanitize_api_version($value) {
        $allowed = array('new', 'legacy');
        return in_array($value, $allowed, true) ? $value : 'legacy';
    }
    
    public function settings_section_callback() {
        echo '<p>Configure your Google Places API credentials below.</p>';
        echo '<p><strong>Important:</strong> You need to enable the Places API in your Google Cloud Console and ensure billing is set up.</p>';
        echo '<p><a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank">Enable Places API (New)</a> | ';
        echo '<a href="https://console.cloud.google.com/apis/library/places.googleapis.com" target="_blank">Enable Places API (Legacy)</a></p>';
    }
    
    public function api_version_callback() {
        $api_version = get_option('google_reviews_api_version', 'legacy');
        ?>
        <select name="google_reviews_api_version">
            <option value="legacy" <?php selected($api_version, 'legacy'); ?>>Places API (Legacy) — Up to 10 reviews (recommended)</option>
            <option value="new" <?php selected($api_version, 'new'); ?>>Places API (New) — Up to 5 reviews</option>
        </select>
        <p class="description">
            <strong>Legacy</strong> fetches twice (by relevance + by newest) and deduplicates, capturing up to 10 unique reviews.<br>
            <strong>New</strong> returns up to 5 reviews with no sort control.
        </p>
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
        echo '<p class="description">The Place ID of your business. <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">Find your Place ID</a></p>';
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
            if (isset($_GET['sync_success'])) {
                $count = intval($_GET['sync_success']);
                echo '<div class="notice notice-success"><p>Successfully synced ' . $count . ' review(s)!</p></div>';
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
            
            <h2>Sync Status</h2>
            <?php
            $last_sync = get_option('google_reviews_last_sync', false);
            if ($last_sync) {
                echo '<p>Last sync: <strong>' . esc_html($last_sync['time']) . '</strong> — ';
                if ($last_sync['status'] === 'success') {
                    echo '<span style="color:green;">Success</span> (' . intval($last_sync['count']) . ' reviews processed)</p>';
                } else {
                    echo '<span style="color:red;">Error</span>: ' . esc_html($last_sync['message']) . '</p>';
                }
            } else {
                echo '<p>No sync has been performed yet.</p>';
            }
            
            $next_run = wp_next_scheduled('fetch_google_reviews_event');
            if ($next_run) {
                echo '<p>Next automatic sync: <strong>' . date('F j, Y g:i a', $next_run) . '</strong></p>';
            } else {
                echo '<p>No sync scheduled. Save settings to enable automatic syncing.</p>';
            }
            ?>
            
            <hr>
            
            <h2>How to Use with Page Builders</h2>
            
            <h3>Available Data Fields</h3>
            <ul>
                <li><strong>Post Title:</strong> Reviewer Name</li>
                <li><strong>Custom Field <code>review_full_text</code>:</strong> Review Content</li>
                <li><strong>Custom Field <code>review_rating</code>:</strong> Star Rating (1-5)</li>
                <li><strong>Custom Field <code>review_date</code>:</strong> Review Date (Y-m-d H:i:s)</li>
                <li><strong>Custom Field <code>review_timestamp</code>:</strong> Unix Timestamp</li>
                <li><strong>Custom Field <code>reviewer_photo_url</code>:</strong> Reviewer Photo URL</li>
            </ul>
            
            <h3>Quick Start</h3>
            <ul>
                <li><strong>Greenshift:</strong> Dynamic Field Block → Source: "Custom Field" → Key: <code>review_full_text</code></li>
                <li><strong>Bricks:</strong> Basic Text → Dynamic Data → <code>{cf_review_full_text}</code></li>
                <li><strong>Elementor Pro:</strong> Text Editor → Dynamic Tag → Post Custom Field → Key: <code>review_full_text</code></li>
            </ul>
            
            <hr>
            
            <h2>About the 5-Review Limit</h2>
            <p>The Google Places API is limited to <strong>5 reviews per request</strong> — this is a Google-imposed restriction, not a plugin limitation.</p>
            <p><strong>What this plugin does:</strong> When using the <em>Legacy API</em>, the plugin makes two requests — one sorted by "most relevant" and one by "newest" — then deduplicates them. This can capture <strong>up to 10 unique reviews</strong>. Over time, as Google rotates which reviews it returns, your collection will grow beyond 10.</p>
            <p><strong>Need all reviews?</strong> The <a href="https://developers.google.com/my-business/content/review-data" target="_blank">Google Business Profile API</a> can fetch <em>all</em> reviews with pagination (50 per page), but requires OAuth 2.0 authentication.</p>
        </div>
        <?php
    }
    
    /**
     * Reschedule cron when frequency changes
     */
    public function reschedule_review_fetch($old_value, $new_value) {
        $timestamp = wp_next_scheduled('fetch_google_reviews_event');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'fetch_google_reviews_event');
        }
        
        $api_key = get_option('google_reviews_api_key');
        $place_id = get_option('google_reviews_place_id');
        
        if ($api_key && $place_id) {
            wp_schedule_event(time(), $new_value, 'fetch_google_reviews_event');
        }
    }
    
    /**
     * Schedule cron when API key or Place ID is saved
     */
    public function maybe_schedule_review_fetch() {
        $api_key = get_option('google_reviews_api_key');
        $place_id = get_option('google_reviews_place_id');
        $frequency = get_option('google_reviews_sync_frequency', 'daily');
        
        if ($api_key && $place_id && !wp_next_scheduled('fetch_google_reviews_event')) {
            wp_schedule_event(time(), $frequency, 'fetch_google_reviews_event');
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
        $api_version = get_option('google_reviews_api_version', 'legacy');
        
        if (!$api_key || !$place_id) {
            $this->log_sync_status('error', 0, 'API Key or Place ID not configured.');
            return new WP_Error('missing_credentials', 'API Key or Place ID not configured.');
        }
        
        if ($api_version === 'new') {
            $result = $this->fetch_reviews_new_api($api_key, $place_id);
        } else {
            $result = $this->fetch_reviews_legacy_api($api_key, $place_id);
        }
        
        if (is_wp_error($result)) {
            $this->log_sync_status('error', 0, $result->get_error_message());
        } else {
            $this->log_sync_status('success', $result);
        }
        
        return $result;
    }
    
    /**
     * Log sync status for admin display
     */
    private function log_sync_status($status, $count = 0, $message = '') {
        update_option('google_reviews_last_sync', array(
            'status'  => $status,
            'count'   => $count,
            'message' => $message,
            'time'    => current_time('F j, Y g:i a'),
        ), false);
        
        if ($status === 'error') {
            error_log('[Google Reviews CPT] Sync error: ' . $message);
        }
    }
    
    /**
     * Fetch reviews using NEW Places API (up to 5 reviews)
     */
    private function fetch_reviews_new_api($api_key, $place_id) {
        $url = 'https://places.googleapis.com/v1/places/' . $place_id;
        
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
        
        if (isset($data['error'])) {
            return new WP_Error('api_error', $data['error']['message']);
        }
        
        if (!isset($data['reviews']) || empty($data['reviews'])) {
            return new WP_Error('no_reviews', 'No reviews found. Make sure your business has public reviews.');
        }
        
        return $this->process_reviews($data['reviews'], 'new');
    }
    
    /**
     * Fetch reviews using LEGACY Places API
     * Makes TWO requests (most_relevant + newest) and deduplicates for up to 10 reviews
     */
    private function fetch_reviews_legacy_api($api_key, $place_id) {
        $all_reviews = array();
        $seen_keys = array();
        
        // Fetch #1: Most Relevant (default)
        $reviews_relevant = $this->fetch_legacy_single($api_key, $place_id, 'most_relevant');
        if (is_wp_error($reviews_relevant)) {
            return $reviews_relevant;
        }
        
        foreach ($reviews_relevant as $review) {
            $key = $this->get_review_dedup_key($review, 'legacy');
            if (!isset($seen_keys[$key])) {
                $seen_keys[$key] = true;
                $all_reviews[] = $review;
            }
        }
        
        // Fetch #2: Newest
        $reviews_newest = $this->fetch_legacy_single($api_key, $place_id, 'newest');
        if (!is_wp_error($reviews_newest)) {
            foreach ($reviews_newest as $review) {
                $key = $this->get_review_dedup_key($review, 'legacy');
                if (!isset($seen_keys[$key])) {
                    $seen_keys[$key] = true;
                    $all_reviews[] = $review;
                }
            }
        }
        
        if (empty($all_reviews)) {
            return new WP_Error('no_reviews', 'No reviews found. Try enabling "Places API (New)" in settings.');
        }
        
        return $this->process_reviews($all_reviews, 'legacy');
    }
    
    /**
     * Single legacy API fetch with a specific sort order
     */
    private function fetch_legacy_single($api_key, $place_id, $sort = 'most_relevant') {
        $url = add_query_arg(array(
            'place_id' => $place_id,
            'fields' => 'reviews',
            'reviews_sort' => $sort,
            'key' => $api_key
        ), 'https://maps.googleapis.com/maps/api/place/details/json');
        
        $response = wp_remote_get($url, array(
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error_message'])) {
            return new WP_Error('api_error', $data['error_message']);
        }
        
        if (!isset($data['result']['reviews'])) {
            return new WP_Error('no_reviews', 'No reviews found for sort: ' . $sort);
        }
        
        return $data['result']['reviews'];
    }
    
    /**
     * Generate a deduplication key for a review
     */
    private function get_review_dedup_key($review, $api_type) {
        if ($api_type === 'new') {
            $name = isset($review['authorAttribution']['displayName']) ? $review['authorAttribution']['displayName'] : '';
            $time = isset($review['publishTime']) ? $review['publishTime'] : '';
        } else {
            $name = isset($review['author_name']) ? $review['author_name'] : '';
            $time = isset($review['time']) ? $review['time'] : '';
        }
        return md5($name . '_' . $time);
    }
    
    /**
     * Process and store reviews
     * 
     * KEY BEHAVIOR: review_full_text is ONLY set on first import.
     * Existing reviews never have their text overwritten, so your team
     * can safely add <span> tags, formatting, or any custom edits.
     */
    private function process_reviews($reviews, $api_type) {
        $new_count = 0;
        $updated_count = 0;
        
        foreach ($reviews as $review) {
            // Normalize fields across API versions
            if ($api_type === 'new') {
                $author_name = isset($review['authorAttribution']['displayName']) ? $review['authorAttribution']['displayName'] : 'Anonymous';
                $text = isset($review['text']['text']) ? $review['text']['text'] : '';
                $rating = isset($review['rating']) ? $review['rating'] : 0;
                $time = isset($review['publishTime']) ? strtotime($review['publishTime']) : time();
                $photo_url = isset($review['authorAttribution']['photoUri']) ? $review['authorAttribution']['photoUri'] : '';
                $unique_key = isset($review['name']) ? $review['name'] : '';
            } else {
                $author_name = isset($review['author_name']) ? $review['author_name'] : 'Anonymous';
                $text = isset($review['text']) ? $review['text'] : '';
                $rating = isset($review['rating']) ? $review['rating'] : 0;
                $time = isset($review['time']) ? $review['time'] : time();
                $photo_url = isset($review['profile_photo_url']) ? $review['profile_photo_url'] : '';
                $unique_key = '';
            }
            
            // Generate a robust review ID
            if (!empty($unique_key)) {
                $review_id = 'google_' . md5($unique_key);
            } else {
                $review_id = 'google_' . md5($author_name . '_' . $time . '_' . substr($text, 0, 100));
            }
            
            // Check if review already exists (any status to prevent duplicates)
            $existing = get_posts(array(
                'post_type' => $this->post_type,
                'meta_key' => 'review_id',
                'meta_value' => $review_id,
                'posts_per_page' => 1,
                'post_status' => 'any',
            ));
            
            if (!empty($existing)) {
                // ──────────────────────────────────────────────
                // EXISTING REVIEW: update meta, SKIP text
                // ──────────────────────────────────────────────
                $post_id = $existing[0]->ID;
                
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_title' => sanitize_text_field($author_name),
                ));
                
                update_post_meta($post_id, 'review_rating', intval($rating));
                update_post_meta($post_id, 'review_date', date('Y-m-d H:i:s', $time));
                update_post_meta($post_id, 'reviewer_photo_url', esc_url_raw($photo_url));
                update_post_meta($post_id, 'review_timestamp', intval($time));
                
                $updated_count++;
            } else {
                // ──────────────────────────────────────────────
                // NEW REVIEW: create post and set ALL fields
                // ──────────────────────────────────────────────
                $post_id = wp_insert_post(array(
                    'post_title'   => sanitize_text_field($author_name),
                    'post_content' => '',
                    'post_status'  => 'publish',
                    'post_type'    => $this->post_type,
                ));
                
                if (is_wp_error($post_id)) {
                    error_log('[Google Reviews CPT] Failed to insert review for: ' . $author_name);
                    continue;
                }
                
                update_post_meta($post_id, 'review_full_text', wp_kses_post($text));
                update_post_meta($post_id, 'review_id', $review_id);
                update_post_meta($post_id, 'review_rating', intval($rating));
                update_post_meta($post_id, 'review_date', date('Y-m-d H:i:s', $time));
                update_post_meta($post_id, 'reviewer_photo_url', esc_url_raw($photo_url));
                update_post_meta($post_id, 'review_timestamp', intval($time));
                
                $new_count++;
            }
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
        $new_columns['review_text'] = 'Review';
        $new_columns['review_date'] = 'Review Date';
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
            case 'review_text':
                $text = get_post_meta($post_id, 'review_full_text', true);
                if ($text) {
                    echo esc_html(wp_trim_words(wp_strip_all_tags($text), 15, '…'));
                } else {
                    echo '<em>No text</em>';
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

// Initialize
new Google_Reviews_CPT();

// Activation — schedule cron if credentials exist
register_activation_hook(__FILE__, function() {
    flush_rewrite_rules();
    
    $api_key = get_option('google_reviews_api_key');
    $place_id = get_option('google_reviews_place_id');
    $frequency = get_option('google_reviews_sync_frequency', 'daily');
    
    if ($api_key && $place_id && !wp_next_scheduled('fetch_google_reviews_event')) {
        wp_schedule_event(time(), $frequency, 'fetch_google_reviews_event');
    }
});

// Deactivation — clean up
register_deactivation_hook(__FILE__, function() {
    $timestamp = wp_next_scheduled('fetch_google_reviews_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fetch_google_reviews_event');
    }
    flush_rewrite_rules();
});
