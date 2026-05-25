<?php
/**
 * Plugin Name: Google Reviews to CPT
 * Plugin URI: https://github.com/thebusinesstoolkitdev/google-reviews-cpt
 * Description: Fetches Google reviews via API and stores them as Custom Post Types. Includes Review Source taxonomy with platform icons (Google, Facebook, Zillow, Yelp, etc). Uses dual-fetch on Legacy API to capture up to 10 reviews.
 * Version: 1.6.1
 * Author: The Business Toolkit
 * Author URI: https://www.thebusinesstoolkit.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: google-reviews-cpt
 * Requires at least: 5.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';

// Wrapped in plugins_loaded to avoid polluting global scope — __FILE__ resolves correctly inside closures.
add_action( 'plugins_loaded', function () {
    $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/thebusinesstoolkitdev/google-reviews-cpt/',
        __FILE__,
        'google-reviews-cpt'
    );
    $checker->getVcsApi()->enableReleaseAssets();
}, 5 );


class Google_Reviews_CPT {

    private $post_type = 'google_review';

    public function __construct() {
        add_action( 'init', array( $this, 'register_cpt' ) );
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        add_action( 'init', array( $this, 'register_shortcode' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_notices', array( $this, 'show_admin_notices' ) );

        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_schedules' ) );

        add_action( 'update_option_google_reviews_sync_frequency', array( $this, 'reschedule_review_fetch' ), 10, 2 );
        add_action( 'update_option_google_reviews_api_key', array( $this, 'maybe_schedule_review_fetch' ) );
        add_action( 'update_option_google_reviews_place_id', array( $this, 'maybe_schedule_review_fetch' ) );

        add_action( 'fetch_google_reviews_event', array( $this, 'fetch_and_store_reviews' ) );
        add_action( 'admin_post_sync_google_reviews', array( $this, 'manual_sync' ) );
        add_action( 'wp_ajax_grcp_lookup_place', array( $this, 'lookup_place_ajax' ) );

        add_filter( 'manage_' . $this->post_type . '_posts_columns', array( $this, 'add_custom_columns' ) );
        add_action( 'manage_' . $this->post_type . '_posts_custom_column', array( $this, 'display_custom_columns' ), 10, 2 );
        add_filter( 'manage_edit-' . $this->post_type . '_sortable_columns', array( $this, 'register_sortable_columns' ) );
        add_action( 'pre_get_posts', array( $this, 'handle_sortable_columns' ) );

        add_action( 'add_meta_boxes', array( $this, 'add_review_meta_box' ) );
        add_action( 'save_post', array( $this, 'save_review_meta_data' ) );

        add_action( 'admin_head', array( $this, 'admin_column_styles' ) );
    }

    // ── CPT ──────────────────────────────────────────────────────────────────

    public function register_cpt() {
        $labels = array(
            'name'               => 'Google Reviews',
            'singular_name'      => 'Google Review',
            'menu_name'          => 'Google Reviews',
            'add_new'            => 'Add New',
            'add_new_item'       => 'Add New Review',
            'edit_item'          => 'Edit Review',
            'new_item'           => 'New Review',
            'view_item'          => 'View Review',
            'search_items'       => 'Search Reviews',
            'not_found'          => 'No reviews found',
            'not_found_in_trash' => 'No reviews found in trash',
        );

        register_post_type( $this->post_type, array(
            'labels'              => $labels,
            'public'              => false,
            'has_archive'         => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'menu_icon'           => 'dashicons-star-filled',
            'supports'            => array( 'title', 'custom-fields' ),
            'rewrite'             => false,
            'capability_type'     => 'post',
            'exclude_from_search' => true,
        ) );
    }

    // ── TAXONOMY ─────────────────────────────────────────────────────────────

    public function register_taxonomy() {
        register_taxonomy( 'review_source', $this->post_type, array(
            'labels' => array(
                'name'          => 'Review Sources',
                'singular_name' => 'Review Source',
                'search_items'  => 'Search Sources',
                'all_items'     => 'All Sources',
                'edit_item'     => 'Edit Source',
                'update_item'   => 'Update Source',
                'add_new_item'  => 'Add New Source',
                'new_item_name' => 'New Source Name',
                'menu_name'     => 'Sources',
            ),
            'hierarchical'      => true,
            'public'            => false,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => false,
        ) );

        $this->maybe_create_default_sources();
    }

    private function maybe_create_default_sources() {
        if ( get_option( 'grcp_default_sources_created' ) ) {
            return;
        }

        $sources = array(
            'google'      => 'Google',
            'facebook'    => 'Facebook',
            'zillow'      => 'Zillow',
            'yelp'        => 'Yelp',
            'homeadvisor' => 'HomeAdvisor',
            'bbb'         => 'BBB',
            'thumbtack'   => 'Thumbtack',
            'tripadvisor' => 'TripAdvisor',
            'realtor'     => 'Realtor.com',
            'angi'        => 'Angi',
        );

        foreach ( $sources as $slug => $name ) {
            if ( ! term_exists( $slug, 'review_source' ) ) {
                $result = wp_insert_term( $name, 'review_source', array( 'slug' => $slug ) );
                if ( ! is_wp_error( $result ) ) {
                    update_term_meta( $result['term_id'], 'source_icon_key', $slug );
                }
            }
        }

        update_option( 'grcp_default_sources_created', true );
    }

    // ── ICONS ─────────────────────────────────────────────────────────────────

    public static function get_source_icon( $slug, $size = 20 ) {
        $icons = self::get_source_icons();
        if ( isset( $icons[ $slug ] ) ) {
            return $icons[ $slug ];
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="#666" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    }

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

    public function get_post_source_icon( $post_id ) {
        $terms = wp_get_post_terms( $post_id, 'review_source' );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }
        $term     = $terms[0];
        $icon_key = get_term_meta( $term->term_id, 'source_icon_key', true );
        if ( ! $icon_key ) {
            $icon_key = $term->slug;
        }
        $icon = self::get_source_icon( $icon_key );
        return '<span class="grcp-source-icon" title="' . esc_attr( $term->name ) . '">' . $icon . '</span>';
    }

    // ── ADMIN STYLES ──────────────────────────────────────────────────────────

    public function admin_column_styles() {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== $this->post_type ) {
            return;
        }
        echo '<style>
            .grcp-source-icon { display: inline-flex; align-items: center; }
            .grcp-source-icon svg { vertical-align: middle; border-radius: 3px; }
            .column-review_source { width: 80px; }
        </style>';
    }

    // ── META BOX ──────────────────────────────────────────────────────────────

    public function add_review_meta_box() {
        add_meta_box(
            'google_review_content_box',
            'Review Content',
            array( $this, 'render_review_content_box' ),
            $this->post_type,
            'normal',
            'high'
        );
    }

    public function render_review_content_box( $post ) {
        $content = get_post_meta( $post->ID, 'review_full_text', true );
        echo '<p class="description" style="margin-bottom:10px;">Edit the review content below. Your changes are safe — automatic syncs will <strong>never overwrite</strong> manually edited reviews.</p>';
        wp_editor( $content, 'review_full_text_editor', array(
            'media_buttons' => false,
            'textarea_name' => 'review_full_text',
            'textarea_rows' => 10,
            'teeny'         => true,
        ) );
        wp_nonce_field( 'save_review_content_nonce', 'review_content_nonce' );
    }

    public function save_review_meta_data( $post_id ) {
        if ( get_post_type( $post_id ) !== $this->post_type ) {
            return;
        }
        if ( ! isset( $_POST['review_content_nonce'] ) ||
             ! wp_verify_nonce( $_POST['review_content_nonce'], 'save_review_content_nonce' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( isset( $_POST['review_full_text'] ) ) {
            update_post_meta( $post_id, 'review_full_text', wp_kses_post( $_POST['review_full_text'] ) );
        }
    }

    // ── CRON ──────────────────────────────────────────────────────────────────

    public function add_custom_cron_schedules( $schedules ) {
        $schedules['weekly'] = array(
            'interval' => 604800,
            'display'  => __( 'Once Weekly', 'google-reviews-cpt' ),
        );
        return $schedules;
    }

    public function reschedule_review_fetch( $old_value, $new_value ) {
        wp_unschedule_hook( 'fetch_google_reviews_event' );
        $api_key  = get_option( 'google_reviews_api_key' );
        $place_id = get_option( 'google_reviews_place_id' );
        if ( $api_key && $place_id ) {
            wp_schedule_event( time(), $new_value, 'fetch_google_reviews_event' );
        }
    }

    public function maybe_schedule_review_fetch() {
        $api_key   = get_option( 'google_reviews_api_key' );
        $place_id  = get_option( 'google_reviews_place_id' );
        $frequency = get_option( 'google_reviews_sync_frequency', 'daily' );
        if ( $api_key && $place_id && ! wp_next_scheduled( 'fetch_google_reviews_event' ) ) {
            wp_schedule_event( time(), $frequency, 'fetch_google_reviews_event' );
        }
    }

    // ── SETTINGS ──────────────────────────────────────────────────────────────

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            'Settings',
            'Settings',
            'manage_options',
            'google-reviews-settings',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'google_reviews_settings', 'google_reviews_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        register_setting( 'google_reviews_settings', 'google_reviews_place_id', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ) );
        register_setting( 'google_reviews_settings', 'google_reviews_sync_frequency', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_sync_frequency' ),
            'default'           => 'daily',
        ) );
        register_setting( 'google_reviews_settings', 'google_reviews_api_version', array(
            'type'              => 'string',
            'sanitize_callback' => array( $this, 'sanitize_api_version' ),
            'default'           => 'legacy',
        ) );
        register_setting( 'google_reviews_settings', 'google_reviews_min_rating', array(
            'type'              => 'integer',
            'sanitize_callback' => array( $this, 'sanitize_min_rating' ),
            'default'           => 4,
        ) );
        register_setting( 'google_reviews_settings', 'google_reviews_summary_label', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'Customer Reviews',
        ) );

        add_settings_section( 'google_reviews_main_section', 'Google API Configuration', array( $this, 'settings_section_callback' ), 'google-reviews-settings' );
        add_settings_field( 'api_version',    'API Version',     array( $this, 'api_version_callback' ),    'google-reviews-settings', 'google_reviews_main_section' );
        add_settings_field( 'api_key',        'Google API Key',  array( $this, 'api_key_callback' ),        'google-reviews-settings', 'google_reviews_main_section' );
        add_settings_field( 'place_id',       'Google Place ID', array( $this, 'place_id_callback' ),       'google-reviews-settings', 'google_reviews_main_section' );
        add_settings_field( 'sync_frequency', 'Sync Frequency',  array( $this, 'sync_frequency_callback' ), 'google-reviews-settings', 'google_reviews_main_section' );
        add_settings_field( 'min_rating',     'Minimum Rating',  array( $this, 'min_rating_callback' ),     'google-reviews-settings', 'google_reviews_main_section' );

        add_settings_section( 'google_reviews_display_section', 'Display Settings', array( $this, 'display_section_callback' ), 'google-reviews-settings' );
        add_settings_field( 'summary_label', 'Review Summary Label', array( $this, 'summary_label_callback' ), 'google-reviews-settings', 'google_reviews_display_section' );
    }

    public function sanitize_sync_frequency( $value ) {
        $allowed = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
        return in_array( $value, $allowed, true ) ? $value : 'daily';
    }

    public function sanitize_api_version( $value ) {
        $allowed = array( 'new', 'legacy' );
        return in_array( $value, $allowed, true ) ? $value : 'legacy';
    }

    public function sanitize_min_rating( $value ) {
        $value = intval( $value );
        return ( $value >= 1 && $value <= 5 ) ? $value : 4;
    }

    public function settings_section_callback() {
        echo '<p>Configure your Google Places API credentials below.</p>';
        echo '<p><strong>Important:</strong> You need to enable the Places API in your Google Cloud Console and ensure billing is set up.</p>';
        echo '<p><a href="https://console.cloud.google.com/apis/library/places-backend.googleapis.com" target="_blank">Enable Places API (New)</a> | ';
        echo '<a href="https://console.cloud.google.com/apis/library/places.googleapis.com" target="_blank">Enable Places API (Legacy)</a></p>';
    }

    public function display_section_callback() {
        echo '<p>Configure how the review summary text is generated.</p>';
    }

    public function api_version_callback() {
        $api_version = get_option( 'google_reviews_api_version', 'legacy' );
        ?>
        <select name="google_reviews_api_version">
            <option value="legacy" <?php selected( $api_version, 'legacy' ); ?>>Places API (Legacy) — Up to 10 reviews (recommended)</option>
            <option value="new"    <?php selected( $api_version, 'new' ); ?>>Places API (New) — Up to 5 reviews</option>
        </select>
        <p class="description">
            <strong>Legacy</strong> fetches twice (by relevance + by newest) and deduplicates, capturing up to 10 unique reviews.<br>
            <strong>New</strong> returns up to 5 reviews with no sort control.
        </p>
        <?php
    }

    public function api_key_callback() {
        $api_key = get_option( 'google_reviews_api_key', '' );
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<input type="password" id="grcp_api_key" name="google_reviews_api_key" value="' . esc_attr( $api_key ) . '" class="regular-text" autocomplete="off" />';
        echo '<button type="button" class="button button-secondary" onclick="var f=document.getElementById(\'grcp_api_key\');f.type=f.type===\'password\'?\'text\':\'password\';this.textContent=f.type===\'password\'?\'Show\':\'Hide\';">Show</button>';
        echo '</div>';
        echo '<p class="description">Your Google Places API key from Google Cloud Console.</p>';
    }

    public function place_id_callback() {
        $place_id = get_option( 'google_reviews_place_id', '' );
        $nonce    = wp_create_nonce( 'grcp_lookup_place' );

        echo '<input type="text" id="grcp_place_id" name="google_reviews_place_id" value="' . esc_attr( $place_id ) . '" class="regular-text" />';
        echo '<p class="description">Paste a known Place ID above, or use the search tool below to find it by business name.</p>';
        ?>
        <div style="margin-top:12px;padding:14px 16px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;max-width:500px;">
            <p style="margin:0 0 4px;font-weight:600;">&#128269; Find Place ID by business name</p>
            <p style="margin:0 0 10px;" class="description">Works for online businesses, service-area businesses, and any business without a precise map pin.</p>
            <div style="display:flex;gap:8px;">
                <input type="text" id="grcp_place_search" placeholder="e.g. The Business Toolkit Melbourne" class="regular-text" style="flex:1;margin:0;" />
                <button type="button" id="grcp_place_search_btn" class="button button-secondary">Search</button>
            </div>
            <div id="grcp_place_results" style="margin-top:10px;"></div>
        </div>
        <script>
        (function () {
            var btn     = document.getElementById('grcp_place_search_btn');
            var input   = document.getElementById('grcp_place_search');
            var results = document.getElementById('grcp_place_results');
            var field   = document.getElementById('grcp_place_id');

            function doSearch() {
                var query = input.value.trim();
                if (!query) { return; }

                btn.disabled    = true;
                btn.textContent = 'Searching…';
                results.innerHTML = '';

                var body = new FormData();
                body.append('action', 'grcp_lookup_place');
                body.append('nonce',  '<?php echo esc_js( $nonce ); ?>');
                body.append('query',  query);

                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        btn.disabled    = false;
                        btn.textContent = 'Search';

                        if (!resp.success) {
                            results.innerHTML = '<p style="color:#d63638;margin:4px 0 0;">⚠ ' + resp.data + '</p>';
                            return;
                        }

                        var items = resp.data.results;
                        if (!items.length) {
                            results.innerHTML = '<p style="color:#72777c;margin:4px 0 0;">No businesses found. Try adding a city or country to your search.</p>';
                            return;
                        }

                        var html = '<p style="margin:0 0 6px;font-size:12px;color:#72777c;">Click a result to use its Place ID:</p><ul style="margin:0;padding:0;list-style:none;">';
                        items.forEach(function (r) {
                            html += '<li style="padding:9px 11px;background:#fff;border:1px solid #dcdcde;border-radius:3px;margin-bottom:4px;cursor:pointer;" data-id="' + r.place_id + '" onmouseover="this.style.background=\'#f0f6fc\'" onmouseout="this.style.background=\'#fff\'">'
                                  + '<strong>' + r.name + '</strong>'
                                  + '<br><span style="font-size:12px;color:#72777c;">' + r.address + '</span>'
                                  + '<br><code style="font-size:11px;color:#50575e;">' + r.place_id + '</code>'
                                  + '</li>';
                        });
                        html += '</ul>';
                        results.innerHTML = html;

                        results.querySelectorAll('li[data-id]').forEach(function (li) {
                            li.addEventListener('click', function () {
                                var id = this.getAttribute('data-id');
                                field.value = id;
                                results.innerHTML = '<p style="color:#00a32a;margin:4px 0 0;">&#10003; Place ID set to <code>' + id + '</code>. Click <strong>Save Changes</strong> to save.</p>';
                            });
                        });
                    })
                    .catch(function () {
                        btn.disabled    = false;
                        btn.textContent = 'Search';
                        results.innerHTML = '<p style="color:#d63638;margin:4px 0 0;">Request failed — please try again.</p>';
                    });
            }

            btn.addEventListener('click', doSearch);
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); doSearch(); }
            });
        })();
        </script>
        <?php
    }

    public function sync_frequency_callback() {
        $frequency = get_option( 'google_reviews_sync_frequency', 'daily' );
        ?>
        <select name="google_reviews_sync_frequency">
            <option value="hourly"     <?php selected( $frequency, 'hourly' ); ?>>Hourly</option>
            <option value="twicedaily" <?php selected( $frequency, 'twicedaily' ); ?>>Twice Daily</option>
            <option value="daily"      <?php selected( $frequency, 'daily' ); ?>>Daily</option>
            <option value="weekly"     <?php selected( $frequency, 'weekly' ); ?>>Weekly</option>
        </select>
        <?php
    }

    public function min_rating_callback() {
        $min = intval( get_option( 'google_reviews_min_rating', 4 ) );
        echo '<select name="google_reviews_min_rating">';
        for ( $i = 1; $i <= 5; $i++ ) {
            echo '<option value="' . $i . '" ' . selected( $min, $i, false ) . '>' . $i . ' star' . ( $i > 1 ? 's' : '' ) . ' &amp; above</option>';
        }
        echo '</select>';
        echo '<p class="description">Reviews below this rating are skipped during sync.</p>';
    }

    public function summary_label_callback() {
        $label        = get_option( 'google_reviews_summary_label', 'Customer Reviews' );
        $google_total = get_option( 'google_reviews_total_count', 0 );
        $local_count  = wp_count_posts( $this->post_type );
        $local_total  = isset( $local_count->publish ) ? intval( $local_count->publish ) : 0;
        $display      = ( $google_total > 0 ) ? intval( $google_total ) : $local_total;

        echo '<input type="text" name="google_reviews_summary_label" value="' . esc_attr( $label ) . '" class="regular-text" />';
        echo '<p class="description">Text that appears after the count, e.g. "87 <strong>Customer Reviews</strong>".</p>';
        echo '<p><strong>Preview:</strong> <code>' . esc_html( $display . ' ' . $label ) . '</code></p>';
        if ( $google_total > 0 ) {
            echo '<p class="description">Using Google total: <strong>' . intval( $google_total ) . '</strong> (local stored: ' . $local_total . ')</p>';
        } else {
            echo '<p class="description">Google total not yet fetched — using local count. Run a sync to update.</p>';
        }
        echo '<p class="description">Access via option: <code>google_reviews_summary_text</code></p>';
    }

    // ── SETTINGS PAGE ─────────────────────────────────────────────────────────

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>Google Reviews Settings</h1>

            <?php
            if ( isset( $_GET['sync_success'] ) ) {
                $count = intval( $_GET['sync_success'] );
                $last  = get_option( 'google_reviews_last_sync', array() );
                $new   = isset( $last['new_count'] )     ? intval( $last['new_count'] )     : 0;
                $upd   = isset( $last['updated_count'] ) ? intval( $last['updated_count'] ) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>Sync complete: <strong>' . $new . ' new</strong>, <strong>' . $upd . ' updated</strong> (' . $count . ' total).</p></div>';
            }
            if ( isset( $_GET['sync_error'] ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>Error: ' . esc_html( urldecode( $_GET['sync_error'] ) ) . '</p></div>';
            }
            ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'google_reviews_settings' );
                do_settings_sections( 'google-reviews-settings' );
                submit_button();
                ?>
            </form>

            <hr>

            <h2>Manual Sync</h2>
            <p>Click the button below to manually fetch reviews now.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="sync_google_reviews">
                <?php wp_nonce_field( 'sync_google_reviews_nonce' ); ?>
                <?php submit_button( 'Sync Reviews Now', 'secondary', 'submit', false ); ?>
            </form>

            <hr>

            <h2 id="grcp-cron-health">Sync Status</h2>
            <?php
            $last_sync = get_option( 'google_reviews_last_sync', false );
            $health    = $this->get_cron_health();
            $next_run  = wp_next_scheduled( 'fetch_google_reviews_event' );

            // ── Health indicator ──────────────────────────────────────────
            $indicator_map = array(
                'healthy'      => array( 'color' => '#00a32a', 'label' => '&#10003; Healthy' ),
                'warning'      => array( 'color' => '#dba617', 'label' => '&#9888; Warning' ),
                'critical'     => array( 'color' => '#d63638', 'label' => '&#10005; Critical' ),
                'unconfigured' => array( 'color' => '#72777c', 'label' => '&#8212; Not configured' ),
            );
            $ind = $indicator_map[ $health['state'] ];
            echo '<p><strong>Cron status:</strong> <span style="color:' . $ind['color'] . ';font-weight:600;">' . $ind['label'] . '</span></p>';

            // ── Last sync ─────────────────────────────────────────────────
            if ( $last_sync ) {
                echo '<p>Last sync: <strong>' . esc_html( $last_sync['time'] ) . '</strong> — ';
                if ( $last_sync['status'] === 'success' ) {
                    $new = isset( $last_sync['new_count'] )     ? intval( $last_sync['new_count'] )     : 0;
                    $upd = isset( $last_sync['updated_count'] ) ? intval( $last_sync['updated_count'] ) : 0;
                    echo '<span style="color:#00a32a;font-weight:600;">Success</span> (' . $new . ' new, ' . $upd . ' updated)</p>';
                } else {
                    echo '<span style="color:#d63638;font-weight:600;">Error</span>: ' . esc_html( $last_sync['message'] ) . '</p>';
                }
            } else {
                echo '<p>No sync has been performed yet.</p>';
            }

            // ── Next scheduled run ────────────────────────────────────────
            if ( $next_run ) {
                $due_in = $next_run - time();
                if ( $due_in > 0 ) {
                    $due_label = ( $due_in < HOUR_IN_SECONDS )
                        ? round( $due_in / 60 ) . ' minutes'
                        : round( $due_in / HOUR_IN_SECONDS, 1 ) . ' hours';
                    echo '<p>Next automatic sync: <strong>' . wp_date( 'F j, Y g:i a', $next_run ) . '</strong> (in ' . $due_label . ')</p>';
                } else {
                    echo '<p>Next automatic sync: <strong>pending</strong> (will fire on next page load if using WP pseudo-cron)</p>';
                }
            } else {
                echo '<p>No sync scheduled. Save settings with API credentials to enable automatic syncing.</p>';
            }

            // ── WP pseudo-cron warning ────────────────────────────────────
            $pseudo_cron_active = ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON;
            if ( $pseudo_cron_active ) {
                echo '<div style="background:#fff8e5;border-left:4px solid #dba617;padding:10px 14px;margin:12px 0;">';
                echo '<p style="margin:0 0 4px;"><strong>&#9888; You are using WP pseudo-cron</strong></p>';
                echo '<p style="margin:0;">Syncs only fire when someone visits the site. On low-traffic sites this can cause long delays or missed syncs entirely. Switch to a real server cron for reliable scheduling.</p>';
                echo '</div>';
            } else {
                echo '<p style="color:#00a32a;">&#10003; <strong>DISABLE_WP_CRON is set</strong> — real server cron is active.</p>';
            }
            ?>

            <h3>Setting Up a Real Server Cron</h3>
            <p>A real server cron runs on a fixed schedule regardless of site traffic. Set it up once and forget it.</p>

            <p><strong>Step 1 &mdash;</strong> Add this to <code>wp-config.php</code> (above the <code>/* That's all */</code> line):</p>
            <pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;overflow-x:auto;">define( 'DISABLE_WP_CRON', true );</pre>

            <p><strong>Step 2 &mdash;</strong> Add a cron job on your server. In cPanel go to <em>Cron Jobs</em>, or via SSH run <code>crontab -e</code> and add one of these:</p>
            <pre style="background:#f6f7f7;padding:10px 14px;border-radius:4px;overflow-x:auto;"># Using wget:
*/15 * * * * wget -q -O - <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1

# Using curl:
*/15 * * * * curl -s <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?> &gt;/dev/null 2&gt;&amp;1</pre>
            <p><em>Every 15 minutes is the recommended interval — it handles all WP cron events, not just this plugin.</em></p>

            <hr>

            <h2>How to Use with Page Builders</h2>

            <h3>Available Data Fields</h3>
            <ul>
                <li><strong>Post Title:</strong> Reviewer Name</li>
                <li><strong>Custom Field <code>review_full_text</code>:</strong> Review Content</li>
                <li><strong>Custom Field <code>review_rating</code>:</strong> Star Rating (1–5)</li>
                <li><strong>Custom Field <code>review_date</code>:</strong> Review Date (Y-m-d H:i:s)</li>
                <li><strong>Custom Field <code>review_timestamp</code>:</strong> Unix Timestamp</li>
                <li><strong>Custom Field <code>reviewer_photo_url</code>:</strong> Reviewer Photo URL</li>
                <li><strong>Taxonomy <code>review_source</code>:</strong> Platform (Google, Facebook, Zillow, etc.)</li>
                <li><strong>Option <code>google_reviews_summary_text</code>:</strong> e.g. "87 Customer Reviews"</li>
            </ul>

            <h3>Shortcode</h3>
            <p>Use <code>[google_reviews]</code> to display reviews in any page or post.</p>
            <table class="widefat striped" style="max-width:600px;margin-top:8px;">
                <thead><tr><th>Attribute</th><th>Default</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>count</code></td><td>5</td><td>Number of reviews to show</td></tr>
                    <tr><td><code>min_rating</code></td><td>your setting</td><td>Minimum star rating to include</td></tr>
                    <tr><td><code>source</code></td><td>google</td><td>Source slug (google, yelp, etc.) — leave empty for all</td></tr>
                    <tr><td><code>orderby</code></td><td>date</td><td><code>date</code> or <code>rating</code></td></tr>
                    <tr><td><code>order</code></td><td>DESC</td><td><code>ASC</code> or <code>DESC</code></td></tr>
                </tbody>
            </table>
            <p style="margin-top:8px;">Example: <code>[google_reviews count="3" orderby="rating" order="DESC"]</code></p>

            <h3>Review Sources</h3>
            <p>API-synced reviews are auto-tagged as "Google". Manually added reviews can be tagged with any source. Each platform has a built-in SVG icon accessible via <code>Google_Reviews_CPT::get_source_icon('google')</code>.</p>

            <h3>Quick Start</h3>
            <ul>
                <li><strong>Greenshift:</strong> Dynamic Field Block → Source: "Custom Field" → Key: <code>review_full_text</code></li>
                <li><strong>Bricks:</strong> Basic Text → Dynamic Data → <code>{cf_review_full_text}</code></li>
                <li><strong>Elementor Pro:</strong> Text Editor → Dynamic Tag → Post Custom Field → Key: <code>review_full_text</code></li>
            </ul>

            <hr>

            <h2>About the 5-Review Limit</h2>
            <p>The Google Places API is limited to <strong>5 reviews per request</strong> — this is a Google-imposed restriction, not a plugin limitation.</p>
            <p><strong>What this plugin does:</strong> When using the <em>Legacy API</em>, the plugin makes two requests — one sorted by "most relevant" and one by "newest" — then deduplicates them. This can capture <strong>up to 10 unique reviews</strong>. Over time, as Google rotates which reviews it returns, your collection will grow.</p>
            <p><strong>Need all reviews?</strong> The <a href="https://developers.google.com/my-business/content/review-data" target="_blank">Google Business Profile API</a> can fetch all reviews with pagination, but requires OAuth 2.0 authentication.</p>
        </div>
        <?php
    }

    // ── ADMIN NOTICES ─────────────────────────────────────────────────────────

    public function show_admin_notices() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }
        $is_plugin_screen = ( $screen->post_type === $this->post_type ||
                               $screen->id === 'google_review_page_google-reviews-settings' );
        if ( ! $is_plugin_screen ) {
            return;
        }

        // Show one-time notice when Place ID was auto-refreshed
        $refreshed_id = get_option( 'grcp_place_id_refreshed' );
        if ( $refreshed_id ) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Google Reviews:</strong> Your Place ID was automatically refreshed. New ID: <code>' . esc_html( $refreshed_id ) . '</code>.</p></div>';
            delete_option( 'grcp_place_id_refreshed' );
        }

        // Cron health warning
        $health = $this->get_cron_health();
        if ( in_array( $health['state'], array( 'warning', 'critical' ), true ) ) {
            $settings_url = add_query_arg( array(
                'post_type' => $this->post_type,
                'page'      => 'google-reviews-settings',
            ), admin_url( 'edit.php' ) );
            $class = ( $health['state'] === 'critical' ) ? 'notice-error' : 'notice-warning';

            echo '<div class="notice ' . $class . '">';
            echo '<p><strong>Google Reviews: Sync may not be running.</strong> ';

            if ( $health['reason'] === 'not_scheduled' ) {
                echo 'No sync is currently scheduled. ';
            } elseif ( $health['reason'] === 'overdue' ) {
                $hours = round( $health['overdue_seconds'] / HOUR_IN_SECONDS, 1 );
                echo 'The last successful sync was <strong>' . $hours . ' hours overdue</strong>. ';
            }

            echo 'Check <a href="' . esc_url( $settings_url ) . '#grcp-cron-health">Sync Status</a> for setup instructions.</p>';
            echo '</div>';
        }

        // Show persistent guidance when Place ID is invalid and auto-refresh failed
        $last_sync = get_option( 'google_reviews_last_sync', false );
        if ( $last_sync &&
             $last_sync['status'] === 'error' &&
             isset( $last_sync['error_code'] ) &&
             $last_sync['error_code'] === 'invalid_place_id' ) {
            $settings_url = add_query_arg( array(
                'post_type' => $this->post_type,
                'page'      => 'google-reviews-settings',
            ), admin_url( 'edit.php' ) );
            ?>
            <div class="notice notice-warning">
                <p><strong>Google Reviews: Place ID is no longer valid.</strong></p>
                <p><?php echo esc_html( $last_sync['message'] ); ?></p>
                <p>To resolve this:</p>
                <ol>
                    <li>Visit the <a href="https://developers.google.com/maps/documentation/javascript/examples/places-placeid-finder" target="_blank">Place ID Finder</a></li>
                    <li>Search for your business name and location</li>
                    <li>Copy the new Place ID shown</li>
                    <li>Paste it into <a href="<?php echo esc_url( $settings_url ); ?>">Google Reviews Settings → Google Place ID</a> and save</li>
                </ol>
            </div>
            <?php
        }
    }

    // ── SYNC ──────────────────────────────────────────────────────────────────

    public function manual_sync() {
        check_admin_referer( 'sync_google_reviews_nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        $result = $this->fetch_and_store_reviews();

        if ( is_wp_error( $result ) ) {
            wp_redirect( add_query_arg( array(
                'post_type'  => $this->post_type,
                'page'       => 'google-reviews-settings',
                'sync_error' => urlencode( $result->get_error_message() ),
            ), admin_url( 'edit.php' ) ) );
        } else {
            wp_redirect( add_query_arg( array(
                'post_type'    => $this->post_type,
                'page'         => 'google-reviews-settings',
                'sync_success' => $result['total'],
            ), admin_url( 'edit.php' ) ) );
        }
        exit;
    }

    /**
     * AJAX handler: search for a Place ID by business name using findplacefromtext.
     * Works for online businesses, service-area businesses, and any business without a map pin.
     */
    public function lookup_place_ajax() {
        check_ajax_referer( 'grcp_lookup_place', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $query   = isset( $_POST['query'] ) ? sanitize_text_field( $_POST['query'] ) : '';
        $api_key = get_option( 'google_reviews_api_key' );

        if ( ! $query ) {
            wp_send_json_error( 'Please enter a business name to search.' );
        }
        if ( ! $api_key ) {
            wp_send_json_error( 'No API key saved yet — add your Google API key above and save first.' );
        }

        $url = add_query_arg( array(
            'input'     => $query,
            'inputtype' => 'textquery',
            'fields'    => 'place_id,name,formatted_address',
            'key'       => $api_key,
        ), 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json' );

        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['status'] ) && ! in_array( $data['status'], array( 'OK', 'ZERO_RESULTS' ), true ) ) {
            $msg = isset( $data['error_message'] ) ? $data['error_message'] : 'API error: ' . $data['status'];
            wp_send_json_error( $msg );
        }

        if ( empty( $data['candidates'] ) ) {
            wp_send_json_success( array(
                'results' => array(),
                'message' => 'No businesses found. Try adding a city, state, or country to your search.',
            ) );
        }

        $results = array();
        foreach ( $data['candidates'] as $candidate ) {
            $results[] = array(
                'place_id' => sanitize_text_field( $candidate['place_id'] ),
                'name'     => isset( $candidate['name'] ) ? sanitize_text_field( $candidate['name'] ) : 'Unknown',
                'address'  => isset( $candidate['formatted_address'] ) ? sanitize_text_field( $candidate['formatted_address'] ) : 'No address listed',
            );
        }

        wp_send_json_success( array( 'results' => $results ) );
    }

    public function fetch_and_store_reviews() {
        $api_key     = get_option( 'google_reviews_api_key' );
        $place_id    = get_option( 'google_reviews_place_id' );
        $api_version = get_option( 'google_reviews_api_version', 'legacy' );

        if ( ! $api_key || ! $place_id ) {
            $this->log_sync_status( 'error', 0, 'API Key or Place ID not configured.' );
            return new WP_Error( 'missing_credentials', 'API Key or Place ID not configured.' );
        }

        if ( $api_version === 'new' ) {
            $result = $this->fetch_reviews_new_api( $api_key, $place_id );
        } else {
            $result = $this->fetch_reviews_legacy_api( $api_key, $place_id );
        }

        // Attempt automatic Place ID refresh when Google returns NOT_FOUND
        if ( is_wp_error( $result ) && $result->get_error_code() === 'invalid_place_id' ) {
            $refreshed_id = $this->attempt_place_id_refresh( $api_key, $place_id );
            if ( $refreshed_id ) {
                update_option( 'grcp_place_id_refreshed', $refreshed_id );
                if ( $api_version === 'new' ) {
                    $result = $this->fetch_reviews_new_api( $api_key, $refreshed_id );
                } else {
                    $result = $this->fetch_reviews_legacy_api( $api_key, $refreshed_id );
                }
            }
        }

        if ( is_wp_error( $result ) ) {
            $this->log_sync_status( 'error', 0, $result->get_error_message(), 0, 0, $result->get_error_code() );
        } else {
            $this->log_sync_status( 'success', $result['total'], '', $result['new'], $result['updated'] );
            $this->update_summary_text();
        }

        return $result;
    }

    /**
     * Attempt to resolve a stale Place ID using the Places Details endpoint.
     * Google recommends requesting only fields=place_id to obtain the canonical ID.
     * Returns the new ID string on success, false on failure.
     */
    private function attempt_place_id_refresh( $api_key, $old_place_id ) {
        $url = add_query_arg( array(
            'place_id' => $old_place_id,
            'fields'   => 'place_id',
            'key'      => $api_key,
        ), 'https://maps.googleapis.com/maps/api/place/details/json' );

        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['result']['place_id'] ) ) {
            $new_id = sanitize_text_field( $data['result']['place_id'] );
            update_option( 'google_reviews_place_id', $new_id );
            return $new_id;
        }

        return false;
    }

    private function log_sync_status( $status, $count = 0, $message = '', $new_count = 0, $updated_count = 0, $error_code = '' ) {
        update_option( 'google_reviews_last_sync', array(
            'status'        => $status,
            'count'         => $count,
            'new_count'     => $new_count,
            'updated_count' => $updated_count,
            'message'       => $message,
            'error_code'    => $error_code,
            'time'          => current_time( 'F j, Y g:i a' ),
            'timestamp'     => time(),
        ), false );

        if ( $status === 'error' ) {
            error_log( '[Google Reviews CPT] Sync error: ' . $message );
            // Email admin on failure — throttled to once per day to avoid inbox spam
            $last_email = get_option( 'grcp_last_error_email', 0 );
            if ( ( time() - $last_email ) > DAY_IN_SECONDS ) {
                $site_name   = get_bloginfo( 'name' );
                $settings_url = admin_url( 'edit.php?post_type=google_review&page=google-reviews-settings' );
                wp_mail(
                    get_option( 'admin_email' ),
                    '[' . $site_name . '] Google Reviews sync failed',
                    "The Google Reviews auto-sync failed with the following error:\n\n" . $message . "\n\nResolve it here: " . $settings_url
                );
                update_option( 'grcp_last_error_email', time() );
            }
        }
    }

    /**
     * Returns cron health status for display and notices.
     *
     * States: 'healthy' | 'warning' | 'critical' | 'unconfigured'
     * 'overdue_seconds' is how many seconds past the expected interval the last success was (0 if on time).
     */
    private function get_cron_health() {
        $api_key  = get_option( 'google_reviews_api_key' );
        $place_id = get_option( 'google_reviews_place_id' );

        if ( ! $api_key || ! $place_id ) {
            return array( 'state' => 'unconfigured' );
        }

        $frequency  = get_option( 'google_reviews_sync_frequency', 'daily' );
        $intervals  = array(
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'daily'      => DAY_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
        );
        $interval   = isset( $intervals[ $frequency ] ) ? $intervals[ $frequency ] : DAY_IN_SECONDS;
        $next_run   = wp_next_scheduled( 'fetch_google_reviews_event' );
        $last_sync  = get_option( 'google_reviews_last_sync', false );
        $last_ts    = ( $last_sync && isset( $last_sync['timestamp'] ) && $last_sync['status'] === 'success' )
                        ? intval( $last_sync['timestamp'] )
                        : 0;
        $elapsed    = $last_ts ? ( time() - $last_ts ) : 0;
        $overdue    = max( 0, $elapsed - $interval );

        if ( ! $next_run ) {
            return array(
                'state'          => 'warning',
                'reason'         => 'not_scheduled',
                'overdue_seconds' => $overdue,
                'interval'       => $interval,
                'last_ts'        => $last_ts,
            );
        }

        // Last success was more than 3× the interval ago — something is wrong
        if ( $last_ts && $elapsed > ( $interval * 3 ) ) {
            return array(
                'state'          => 'critical',
                'reason'         => 'overdue',
                'overdue_seconds' => $overdue,
                'interval'       => $interval,
                'last_ts'        => $last_ts,
                'next_run'       => $next_run,
            );
        }

        // Last success was between 1.5× and 3× the interval — mild warning
        if ( $last_ts && $elapsed > ( $interval * 1.5 ) ) {
            return array(
                'state'          => 'warning',
                'reason'         => 'overdue',
                'overdue_seconds' => $overdue,
                'interval'       => $interval,
                'last_ts'        => $last_ts,
                'next_run'       => $next_run,
            );
        }

        return array(
            'state'    => 'healthy',
            'interval' => $interval,
            'last_ts'  => $last_ts,
            'next_run' => $next_run,
        );
    }

    private function update_summary_text() {
        $google_total = get_option( 'google_reviews_total_count', 0 );
        if ( $google_total > 0 ) {
            $total = intval( $google_total );
        } else {
            $count = wp_count_posts( $this->post_type );
            $total = isset( $count->publish ) ? intval( $count->publish ) : 0;
        }
        $label = get_option( 'google_reviews_summary_label', 'Customer Reviews' );
        update_option( 'google_reviews_summary_text', $total . ' ' . $label, true );
    }

    // ── API FETCH ─────────────────────────────────────────────────────────────

    private function fetch_reviews_new_api( $api_key, $place_id ) {
        $url = 'https://places.googleapis.com/v1/places/' . rawurlencode( $place_id );

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'     => 'application/json',
                'X-Goog-Api-Key'   => $api_key,
                'X-Goog-FieldMask' => 'reviews,userRatingCount',
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            $status = isset( $data['error']['status'] ) ? $data['error']['status'] : '';
            $code   = ( $status === 'NOT_FOUND' ) ? 'invalid_place_id' : 'api_error';
            return new WP_Error( $code, $data['error']['message'] );
        }

        if ( isset( $data['userRatingCount'] ) ) {
            update_option( 'google_reviews_total_count', intval( $data['userRatingCount'] ), true );
        }

        if ( empty( $data['reviews'] ) ) {
            return new WP_Error( 'no_reviews', 'No reviews found. Make sure your business has public reviews.' );
        }

        return $this->process_reviews( $data['reviews'], 'new' );
    }

    private function fetch_reviews_legacy_api( $api_key, $place_id ) {
        $all_reviews = array();
        $seen_keys   = array();

        // First fetch: most_relevant — also retrieves total review count
        $reviews_relevant = $this->fetch_legacy_single( $api_key, $place_id, 'most_relevant', true );
        if ( is_wp_error( $reviews_relevant ) ) {
            return $reviews_relevant;
        }

        foreach ( $reviews_relevant as $review ) {
            $key = $this->get_review_dedup_key( $review, 'legacy' );
            if ( ! isset( $seen_keys[ $key ] ) ) {
                $seen_keys[ $key ] = true;
                $all_reviews[]     = $review;
            }
        }

        // Second fetch: newest — deduplicated against first batch
        $reviews_newest = $this->fetch_legacy_single( $api_key, $place_id, 'newest' );
        if ( ! is_wp_error( $reviews_newest ) ) {
            foreach ( $reviews_newest as $review ) {
                $key = $this->get_review_dedup_key( $review, 'legacy' );
                if ( ! isset( $seen_keys[ $key ] ) ) {
                    $seen_keys[ $key ] = true;
                    $all_reviews[]     = $review;
                }
            }
        }

        if ( empty( $all_reviews ) ) {
            return new WP_Error( 'no_reviews', 'No reviews found. Try enabling "Places API (New)" in settings.' );
        }

        return $this->process_reviews( $all_reviews, 'legacy' );
    }

    private function fetch_legacy_single( $api_key, $place_id, $sort = 'most_relevant', $fetch_total = false ) {
        $fields = $fetch_total ? 'reviews,user_ratings_total' : 'reviews';

        $url = add_query_arg( array(
            'place_id'     => $place_id,
            'fields'       => $fields,
            'reviews_sort' => $sort,
            'key'          => $api_key,
        ), 'https://maps.googleapis.com/maps/api/place/details/json' );

        $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['status'] ) && $data['status'] !== 'OK' ) {
            $msg  = isset( $data['error_message'] ) ? $data['error_message'] : 'API error: ' . $data['status'];
            $code = ( $data['status'] === 'NOT_FOUND' ) ? 'invalid_place_id' : 'api_error';
            return new WP_Error( $code, $msg );
        }

        if ( $fetch_total && isset( $data['result']['user_ratings_total'] ) ) {
            update_option( 'google_reviews_total_count', intval( $data['result']['user_ratings_total'] ), true );
        }

        if ( ! isset( $data['result']['reviews'] ) ) {
            return new WP_Error( 'no_reviews', 'No reviews found for sort: ' . $sort );
        }

        return $data['result']['reviews'];
    }

    private function get_review_dedup_key( $review, $api_type ) {
        if ( $api_type === 'new' ) {
            $name = isset( $review['authorAttribution']['displayName'] ) ? $review['authorAttribution']['displayName'] : '';
            $time = isset( $review['publishTime'] ) ? $review['publishTime'] : '';
        } else {
            $name = isset( $review['author_name'] ) ? $review['author_name'] : '';
            $time = isset( $review['time'] ) ? $review['time'] : '';
        }
        return md5( $name . '_' . $time );
    }

    /**
     * Process and store reviews.
     *
     * review_full_text is only written on first import — existing reviews are
     * never overwritten, so manual edits (formatting, <span> tags, etc.) are safe.
     *
     * Returns array: [ 'total' => int, 'new' => int, 'updated' => int ]
     */
    private function process_reviews( $reviews, $api_type ) {
        $new_count     = 0;
        $updated_count = 0;
        $min_rating    = intval( get_option( 'google_reviews_min_rating', 4 ) );

        foreach ( $reviews as $review ) {
            if ( $api_type === 'new' ) {
                $author_name = isset( $review['authorAttribution']['displayName'] ) ? $review['authorAttribution']['displayName'] : 'Anonymous';
                $text        = isset( $review['text']['text'] ) ? $review['text']['text'] : '';
                $rating      = isset( $review['rating'] ) ? $review['rating'] : 0;
                $time        = isset( $review['publishTime'] ) ? strtotime( $review['publishTime'] ) : time();
                $photo_url   = isset( $review['authorAttribution']['photoUri'] ) ? $review['authorAttribution']['photoUri'] : '';
                $unique_key  = isset( $review['name'] ) ? $review['name'] : '';
            } else {
                $author_name = isset( $review['author_name'] ) ? $review['author_name'] : 'Anonymous';
                $text        = isset( $review['text'] ) ? $review['text'] : '';
                $rating      = isset( $review['rating'] ) ? $review['rating'] : 0;
                $time        = isset( $review['time'] ) ? $review['time'] : time();
                $photo_url   = isset( $review['profile_photo_url'] ) ? $review['profile_photo_url'] : '';
                $unique_key  = '';
            }

            if ( ! empty( $unique_key ) ) {
                $review_id = 'google_' . md5( $unique_key );
            } else {
                $review_id = 'google_' . md5( $author_name . '_' . $time . '_' . substr( $text, 0, 100 ) );
            }

            if ( intval( $rating ) < $min_rating ) {
                continue;
            }

            $existing = get_posts( array(
                'post_type'              => $this->post_type,
                'meta_key'               => 'review_id',
                'meta_value'             => $review_id,
                'posts_per_page'         => 1,
                'post_status'            => 'any',
                'no_found_rows'          => true,
                'update_post_term_cache' => false,
                'update_post_meta_cache' => false,
            ) );

            if ( ! empty( $existing ) ) {
                $post_id = $existing[0]->ID;
                wp_update_post( array(
                    'ID'         => $post_id,
                    'post_title' => sanitize_text_field( $author_name ),
                ) );
                update_post_meta( $post_id, 'review_rating', intval( $rating ) );
                update_post_meta( $post_id, 'review_date', wp_date( 'Y-m-d H:i:s', $time ) );
                update_post_meta( $post_id, 'reviewer_photo_url', esc_url_raw( $photo_url ) );
                update_post_meta( $post_id, 'review_timestamp', intval( $time ) );
                $updated_count++;
            } else {
                $post_id = wp_insert_post( array(
                    'post_title'  => sanitize_text_field( $author_name ),
                    'post_status' => 'publish',
                    'post_type'   => $this->post_type,
                    'meta_input'  => array(
                        'review_full_text'   => wp_kses_post( $text ),
                        'review_id'          => $review_id,
                        'review_rating'      => intval( $rating ),
                        'review_date'        => wp_date( 'Y-m-d H:i:s', $time ),
                        'reviewer_photo_url' => esc_url_raw( $photo_url ),
                        'review_timestamp'   => intval( $time ),
                    ),
                ) );

                if ( is_wp_error( $post_id ) ) {
                    error_log( '[Google Reviews CPT] Failed to insert review for: ' . $author_name );
                    continue;
                }

                wp_set_object_terms( $post_id, 'google', 'review_source' );
                $new_count++;
            }
        }

        return array(
            'total'   => $new_count + $updated_count,
            'new'     => $new_count,
            'updated' => $updated_count,
        );
    }

    // ── SHORTCODE ─────────────────────────────────────────────────────────────

    public function register_shortcode() {
        add_shortcode( 'google_reviews', array( $this, 'render_shortcode' ) );
    }

    public function render_shortcode( $atts ) {
        $min_setting = intval( get_option( 'google_reviews_min_rating', 4 ) );
        $atts = shortcode_atts( array(
            'count'      => 5,
            'min_rating' => $min_setting,
            'source'     => 'google',
            'orderby'    => 'date',
            'order'      => 'DESC',
        ), $atts, 'google_reviews' );

        $meta_key = ( sanitize_key( $atts['orderby'] ) === 'rating' ) ? 'review_rating' : 'review_timestamp';
        $order    = in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( $atts['order'] ) : 'DESC';

        $args = array(
            'post_type'              => $this->post_type,
            'posts_per_page'         => intval( $atts['count'] ),
            'post_status'            => 'publish',
            'orderby'                => 'meta_value_num',
            'order'                  => $order,
            'meta_key'               => $meta_key,
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => 'review_rating',
                    'value'   => intval( $atts['min_rating'] ),
                    'compare' => '>=',
                    'type'    => 'NUMERIC',
                ),
            ),
        );

        if ( ! empty( $atts['source'] ) ) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'review_source',
                    'field'    => 'slug',
                    'terms'    => sanitize_text_field( $atts['source'] ),
                ),
            );
        }

        $reviews = get_posts( $args );
        if ( empty( $reviews ) ) {
            return '<p class="grcp-no-reviews">No reviews found.</p>';
        }

        ob_start();
        echo '<div class="grcp-reviews">';
        foreach ( $reviews as $review ) {
            $rating    = min( 5, max( 0, intval( get_post_meta( $review->ID, 'review_rating', true ) ) ) );
            $text      = get_post_meta( $review->ID, 'review_full_text', true );
            $date      = get_post_meta( $review->ID, 'review_date', true );
            $photo_url = get_post_meta( $review->ID, 'reviewer_photo_url', true );
            echo '<div class="grcp-review">';
            if ( $photo_url ) {
                echo '<img class="grcp-reviewer-photo" src="' . esc_url( $photo_url ) . '" alt="' . esc_attr( get_the_title( $review->ID ) ) . '" width="40" height="40" loading="lazy">';
            }
            echo '<div class="grcp-review-author">' . esc_html( get_the_title( $review->ID ) ) . '</div>';
            echo '<div class="grcp-review-rating">' . str_repeat( '★', $rating ) . '</div>';
            if ( $text ) {
                echo '<div class="grcp-review-text">' . wp_kses_post( $text ) . '</div>';
            }
            if ( $date ) {
                echo '<div class="grcp-review-date">' . esc_html( wp_date( get_option( 'date_format' ), strtotime( $date ) ) ) . '</div>';
            }
            echo $this->get_post_source_icon( $review->ID );
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    // ── ADMIN COLUMNS ─────────────────────────────────────────────────────────

    public function add_custom_columns( $columns ) {
        $new_columns = array();
        $new_columns['cb']            = $columns['cb'];
        $new_columns['title']         = 'Reviewer';
        $new_columns['review_source'] = 'Source';
        $new_columns['rating']        = 'Rating';
        $new_columns['review_text']   = 'Review';
        $new_columns['review_date']   = 'Review Date';
        $new_columns['date']          = $columns['date'];
        return $new_columns;
    }

    public function register_sortable_columns( $columns ) {
        $columns['rating']      = 'rating';
        $columns['review_date'] = 'review_date';
        return $columns;
    }

    public function handle_sortable_columns( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || $query->get( 'post_type' ) !== $this->post_type ) {
            return;
        }
        $orderby = $query->get( 'orderby' );
        if ( $orderby === 'rating' ) {
            $query->set( 'meta_key', 'review_rating' );
            $query->set( 'orderby', 'meta_value_num' );
        } elseif ( $orderby === 'review_date' ) {
            $query->set( 'meta_key', 'review_timestamp' );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }

    public function display_custom_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'review_source':
                echo $this->get_post_source_icon( $post_id );
                break;
            case 'rating':
                $rating = get_post_meta( $post_id, 'review_rating', true );
                if ( $rating ) {
                    echo str_repeat( '⭐', min( 5, max( 1, intval( $rating ) ) ) );
                }
                break;
            case 'review_text':
                $text = get_post_meta( $post_id, 'review_full_text', true );
                if ( $text ) {
                    echo esc_html( wp_trim_words( wp_strip_all_tags( $text ), 15, '…' ) );
                } else {
                    echo '<em>No text</em>';
                }
                break;
            case 'review_date':
                $date = get_post_meta( $post_id, 'review_date', true );
                if ( $date ) {
                    echo wp_date( 'M j, Y', strtotime( $date ) );
                }
                break;
        }
    }
}

// Store in global so the activation hook can reuse the same instance
// without re-registering all add_action / add_filter calls.
$GLOBALS['google_reviews_cpt'] = new Google_Reviews_CPT();

// Activation — reuse the global instance to avoid double hook registration
register_activation_hook( __FILE__, function () {
    $GLOBALS['google_reviews_cpt']->register_cpt();
    $GLOBALS['google_reviews_cpt']->register_taxonomy();
    flush_rewrite_rules();

    $api_key   = get_option( 'google_reviews_api_key' );
    $place_id  = get_option( 'google_reviews_place_id' );
    $frequency = get_option( 'google_reviews_sync_frequency', 'daily' );
    if ( $api_key && $place_id && ! wp_next_scheduled( 'fetch_google_reviews_event' ) ) {
        wp_schedule_event( time(), $frequency, 'fetch_google_reviews_event' );
    }
} );

// Deactivation — remove all scheduled instances and flush rewrites
register_deactivation_hook( __FILE__, function () {
    wp_unschedule_hook( 'fetch_google_reviews_event' );
    flush_rewrite_rules();
} );
