# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.6.0] - 2026-05-25

### Fixed
- Place ID "no longer valid" error now detected specifically — triggers auto-refresh via Places Details API and retries the sync automatically with the new canonical ID
- Double instantiation on activation: activation hook now reuses the global instance instead of creating a second one (which was registering all hooks twice)
- `publicly_queryable` changed to `false` — reviews were accessible via direct front-end URL queries
- `user_ratings_total` / `userRatingCount` were not being fetched or stored in either API version; both restored
- All `date()` calls replaced with `wp_date()` to respect the WordPress timezone setting
- Unescaped `admin_url()` in form action — `esc_url()` added
- `save_review_meta_data` was running on every post save across the admin; post type guard added
- Deactivation hook now uses `wp_unschedule_hook()` instead of `wp_unschedule_event()` to clear all scheduled instances
- Rating column in admin clamped to 1–5 stars to guard against malformed API data
- Update checker variable scoped inside `plugins_loaded` closure to avoid global namespace pollution

### Added
- **Place ID auto-refresh**: on `NOT_FOUND` error the plugin calls the Details API with `fields=place_id` to resolve the canonical ID, updates the stored option, and retries the sync in the same run
- **Admin notices**: persistent warning when Place ID is invalid with step-by-step fix instructions; one-time info notice when Place ID is auto-refreshed
- **Cron health check**: `get_cron_health()` surfaces healthy / warning / critical states based on last successful sync vs. expected interval
- Cron health indicator on the Settings page showing coloured status, overdue time, next scheduled run countdown, and whether WP pseudo-cron or a real server cron is active
- Cron health warning in admin notices when syncs are overdue or not scheduled
- Pre-filled server cron setup instructions (wget + curl) with the site URL embedded
- Masked API key input (`type="password"`) with Show/Hide toggle
- **Minimum rating setting**: configurable 1–5 star dropdown (was hardcoded to 4)
- **New vs. updated count**: sync log and success notices now show "X new, Y updated" breakdown
- **Sync failure email**: admin is emailed when an automated sync fails, throttled to once per 24 hours
- **Sortable admin columns**: Rating and Review Date columns are now clickable to sort
- `[google_reviews]` shortcode with `count`, `min_rating`, `source`, `orderby`, `order` attributes
- `update_summary_text()` and `google_reviews_summary_label` setting restored
- Display Settings section restored with review summary label preview

### Changed
- `wp_insert_post` now uses `meta_input` for new reviews — 6 separate `update_post_meta` calls collapsed into one DB operation
- `get_posts()` inside the sync loop now includes `no_found_rows`, `update_post_term_cache`, `update_post_meta_cache` hints for better performance
- `process_reviews()` returns `['total', 'new', 'updated']` array instead of a plain integer

## [1.0.1] - 2024-12-09

### Added
- Automatic update checking from GitHub
- Plugin Update Checker library integration

## [1.0.0] - 2024-12-09

### Added
- Initial release
- Custom Post Type for Google reviews
- Google Places API integration
- Automated sync with WordPress Cron
- Configurable sync frequency (Hourly, Twice Daily, Daily, Weekly)
- Admin settings page with comprehensive instructions
- Manual sync functionality
- Support for all major page builders (Bricks, Elementor, Greenshift, Oxygen)
- Custom admin columns showing star ratings and dates
- Duplicate review prevention
- Reviewer profile photo storage
- Detailed error handling and user feedback

### Meta Fields Included
- `review_rating` - Star rating (1-5)
- `review_date` - Formatted date
- `review_timestamp` - Unix timestamp
- `reviewer_photo_url` - Profile photo URL
- `review_id` - Unique identifier

## [Unreleased]

### Planned Features
- Review reply support
- Multi-location support
- Review filtering options
- Export to CSV functionality
- Email notifications for new reviews
