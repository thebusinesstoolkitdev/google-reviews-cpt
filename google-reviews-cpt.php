<?php
/**
 * Plugin Name: Google Reviews to CPT
 * Plugin URI: https://github.com/thebusinesstoolkitdev/google-reviews-cpt
 * Description: Fetches Google reviews via API and stores them as Custom Post Types. Includes Review Source taxonomy with platform icons (Google, Facebook, Zillow, Yelp, etc). Uses dual-fetch on Legacy API to capture up to 10 reviews.
 * Version: 1.5.0
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
        add_action('init', array($this, 'register_taxonomy'));
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
        
        // Admin styles for source icons
        add_action('admin_head', array($this, 'admin_column_styles'));
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
            'public' => false,
            'has_archive' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-star-filled',
            'supports' => array('title', 'custom-fields'), 
            'rewrite' => false,
            'capability_type' => 'post',
            'exclude_from_search' => true,
        );
        
        register_post_type($this->post_type, $args);
    }

    /**
     * Register the Review Source taxonomy
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => 'Review Sources',
            'singular_name'     => 'Review Source',
            'search_items'      => 'Search Sources',
            'all_items'         => 'All Sources',
            'edit_item'         => 'Edit Source',
            'update_item'       => 'Update Source',
            'add_new_item'      => 'Add New Source',
            'new_item_name'     => 'New Source Name',
            'menu_name'         => 'Sources',
        );
        
        register_taxonomy('review_source', $this->post_type, array(
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => false,
        ));
        
        // Create default sources on first run
        $this->maybe_create_default_sources();
    }
    
    /**
     * Create default review source terms with icons (runs once)
     */
    private function maybe_create_default_sources() {
        if (get_option('grcp_default_sources_created')) {
            return;
        }
        
        $sources = array(
            'google'       => 'Google',
            'facebook'     => 'Facebook',
            'zillow'       => 'Zillow',
            'yelp'         => 'Yelp',
            'homeadvisor'  => 'HomeAdvisor',
            'bbb'          => 'BBB',
            'thumbtack'    => 'Thumbtack',
            'tripadvisor'  => 'TripAdvisor',
            'realtor'      => 'Realtor.com',
            'angi'         => 'Angi',
        );
        
        foreach ($sources as $slug => $name) {
            if (!term_exists($slug, 'review_source')) {
                $result = wp_insert_term($name, 'review_source', array('slug' => $slug));
                if (!is_wp_error($result)) {
                    // Store the slug as icon key in term meta
                    update_term_meta($result['term_id'], 'source_icon_key', $slug);
                }
            }
        }
        
        update_option('grcp_default_sources_created', true);
    }
    
    /**
     * Get SVG icon for a review source
     * Returns inline SVG markup for the given source slug
     */
    public static function get_source_icon($slug, $size = 20) {
        $icons = self::get_source_icons();
        
        if (isset($icons[$slug])) {
            return $icons[$slug];
        }
        
        // Fallback: generic review icon
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    }
    
    /**
     * All bundled SVG icons keyed by source slug
     * Simple, recognizable, brand-colored icons
     */
    public static function get_source_icons() {
        return array(
            'google' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0 1 24 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 0 1-4.087 5.571l.001-.001 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
            </svg>',
            
            'facebook' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <path fill="#1877F2" d="M24 4C12.954 4 4 12.954 4 24s8.954 20 20 20 20-8.954 20-20S35.046 4 24 4z"/>
                <path fill="#fff" d="M26.572 29.036h4.917l.772-5.014h-5.689v-2.849c0-2.075.68-3.915 2.621-3.915h3.108V13.07c-.547-.073-1.704-.236-3.882-.236-4.559 0-7.235 2.405-7.235 7.88v3.308h-4.726v5.014h4.726V43.67c.94.14 1.893.23 2.869.23.882 0 1.745-.072 2.596-.191V29.036z"/>
            </svg>',
            
            'zillow' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#006AFF" width="48" height="48" rx="8"/>
                <path fill="#fff" d="M34 18.5L24 12 14 18.5v2l10-6.5 10 6.5v-2zm0 3L24 15 14 21.5V34h7v-8h6v8h7V21.5z"/>
            </svg>',
            
            'yelp' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#D32323" width="48" height="48" rx="8"/>
                <path fill="#fff" d="M22.3 28.4l-5.6 3.2c-.6.3-1.2-.2-1.1-.9l1-6.4c.1-.4.3-.7.7-.8l5.2-2.2c.7-.3 1.4.3 1.2 1l-1.4 6.1zm2.2-4.8l-2.3-6c-.3-.7.3-1.3 1-1.2l6.4.9c.4.1.7.3.8.7l2.3 5.2c.3.7-.3 1.4-1 1.2l-7.2-1.8zM21 15.8l3.3-5.5c.4-.6 1.2-.4 1.3.3l.7 6.4c0 .4-.1.8-.4 1l-4.3 3.7c-.5.5-1.4.1-1.3-.6l.7-5.3z"/>
            </svg>',
            
            'homeadvisor' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#F68B1E" width="48" height="48" rx="8"/>
                <path fill="#fff" d="M24 12L10 24h4v12h8v-8h4v8h8V24h4L24 12z"/>
            </svg>',
            
            'bbb' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#005A78" width="48" height="48" rx="8"/>
                <text x="24" y="30" font-family="Arial,sans-serif" font-size="16" font-weight="bold" fill="#fff" text-anchor="middle">BBB</text>
            </svg>',
            
            'thumbtack' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#009FD9" width="48" height="48" rx="8"/>
                <path fill="#fff" d="M24 10c-1.1 0-2 .9-2 2v14l-6 8h6v4c0 1.1.9 2 2 2s2-.9 2-2v-4h6l-6-8V12c0-1.1-.9-2-2-2z"/>
            </svg>',
            
            'tripadvisor' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#34E0A1" width="48" height="48" rx="8"/>
                <circle cx="16" cy="26" r="5" fill="none" stroke="#000" stroke-width="2"/>
                <circle cx="32" cy="26" r="5" fill="none" stroke="#000" stroke-width="2"/>
                <circle cx="16" cy="26" r="2" fill="#000"/>
                <circle cx="32" cy="26" r="2" fill="#000"/>
                <path d="M14 18h20M24 14l-3 4h6l-3-4" fill="none" stroke="#000" stroke-width="2"/>
            </svg>',
            
            'realtor' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#D92228" width="48" height="48" rx="8"/>
                <text x="24" y="31" font-family="Arial,sans-serif" font-size="18" font-weight="bold" fill="#fff" text-anchor="middle">R</text>
            </svg>',
            
            'angi' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 48 48">
                <rect fill="#FF6153" width="48" height="48" rx="8"/>
                <text x="24" y="30" font-family="Arial,sans-serif" font-size="14" font-weight="bold" fill="#fff" text-anchor="middle">angi</text>
            </svg>',
        );
    }
    
    /**
     * Get the source icon HTML for a given post
     */
    public function get_post_source_icon($post_id) {
        $terms = wp_get_post_terms($post_id, 'review_source');
        
        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }
        
        $term = $terms[0];
        $icon_key = get_term_meta($term->term_id, 'source_icon_key', true);
        
        if (!$icon_key) {
            $icon_key = $term->slug;
        }
        
        $icon = self::get_source_icon($icon_key);
        return '<span class="grcp-source-icon" title="' . esc_attr($term->name) . '">' . $icon . '</span>';
    }
    
    /**
     * Admin column styles for source icons
     */
    public function admin_column_styles() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== $this->post_type) {
            return;
        }
        echo '<style>
            .grcp-source-icon { display: inline-flex; align-items: center; }
            .grcp-source-icon svg { vertical-align: middle; border-radius: 3px; }
            .column-review_source { width: 80px; }
        </style>';
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
                <li><strong>Taxonomy <code>review_source</code>:</strong> Platform (Google, Facebook, Zillow, etc.)</li>
            </ul>
            
            <h3>Review Sources</h3>
            <p>Each review has a <strong>Source</strong> taxonomy (Google Reviews → Sources). API-synced reviews are auto-tagged as "Google". Manually added reviews can be tagged with any source: Facebook, Zillow, Yelp, HomeAdvisor, BBB, Thumbtack, TripAdvisor, Realtor.com, or Angi.</p>
            <p><strong>Source Icons:</strong> Each platform has a built-in SVG icon. Use the taxonomy in your page builder to display the icon alongside reviews. You can also add custom sources via the Sources taxonomy screen.</p>
            <p><strong>PHP Helper:</strong> <code>Google_Reviews_CPT::get_source_icon('google')</code> returns the SVG icon markup for any source slug.</p>
            
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
            
            // Skip reviews below minimum rating (4 stars)
            if (intval($rating) < 4) {
                continue;
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
                
                // Auto-assign "Google" source taxonomy
                wp_set_object_terms($post_id, 'google', 'review_source');
                
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
        $new_columns['review_source'] = 'Source';
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
            case 'review_source':
                echo $this->get_post_source_icon($post_id);
                break;
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
    // Ensure CPT and taxonomy are registered before flushing
    $plugin = new Google_Reviews_CPT();
    $plugin->register_cpt();
    $plugin->register_taxonomy();
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
