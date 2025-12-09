# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
