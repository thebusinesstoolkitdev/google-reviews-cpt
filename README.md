# Google Reviews to CPT

WordPress plugin that fetches Google reviews via API and stores them as Custom Post Types, compatible with any page builder.

![WordPress Plugin](https://img.shields.io/badge/WordPress-Plugin-blue)
![Version](https://img.shields.io/badge/version-1.0.0-green)
![License](https://img.shields.io/badge/license-GPL%20v2-blue)

## 📋 Description

This plugin automatically syncs Google reviews and stores each review as a WordPress Custom Post Type, giving you complete design freedom with any page builder including **Bricks**, **Elementor**, **Greenshift**, **Oxygen**, and more.

## ✨ Features

- ✅ Fetch reviews from Google Places API
- ✅ Store each review as a separate Custom Post Type
- ✅ Automated syncing with configurable frequency
- ✅ Compatible with all major page builders
- ✅ Custom meta fields for ratings, dates, and photos
- ✅ Manual sync option
- ✅ Beautiful admin interface with star ratings
- ✅ Duplicate review prevention

## 📦 Installation

### Automatic Installation
1. Download the [latest release](https://github.com/thebusinesstoolkitdev/google-reviews-cpt/releases)
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Choose the downloaded zip file
4. Click "Install Now" and then "Activate"

### Manual Installation
1. Download the latest release
2. Extract and upload to `/wp-content/plugins/google-reviews-cpt/`
3. Activate through WordPress admin

## ⚙️ Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- Google Places API key with billing enabled

## 🔧 Configuration

### Getting Your Google API Key

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable "Places API"
4. Create credentials (API Key)
5. Set up billing (required even for free tier)

### Finding Your Place ID

Visit [Place ID Finder](https://developers.google.com/maps/documentation/places/web-service/place-id) to find your business's Place ID.

### Plugin Setup

1. Go to **Google Reviews → Settings**
2. Enter your Google API Key
3. Enter your Google Place ID
4. Choose sync frequency (Hourly, Twice Daily, Daily, Weekly)
5. Click "Sync Reviews Now"

## 🎨 Usage with Page Builders

The plugin stores reviews with the following data structure:

### Post Data
- **Post Title**: Reviewer Name
- **Post Content**: Review Text
- **Post Type**: `google_review`

### Meta Fields
- `review_rating` - Star rating (1-5, integer)
- `review_date` - Review date (Y-m-d H:i:s format)
- `review_timestamp` - Unix timestamp
- `reviewer_photo_url` - Reviewer profile photo URL
- `review_id` - Unique review identifier

### Integration Examples

#### Bricks Builder
1. Add a Query Loop element
2. Set Query Type to "Posts"
3. Choose "Google Reviews" as post type
4. Use dynamic data tags

#### Elementor Pro
1. Add a Loop Grid or Loop Carousel
2. Create a new template
3. Set source to "Google Reviews"
4. Use dynamic tags

#### Greenshift
1. Add a Query Loop block
2. Set post type to "Google Reviews"
3. Use dynamic data blocks

#### Oxygen Builder
1. Add a Repeater
2. Set query to "google_review" post type
3. Use dynamic data

## 📸 Screenshots

Coming soon...

## ❓ FAQ

**Does this work with the free version of page builders?**
It depends on the builder. You need a page builder that supports Custom Post Type queries and custom field display. Most pro versions support this.

**How often are reviews synced?**
You can choose between Hourly, Twice Daily, Daily, or Weekly automatic syncing. You can also manually sync anytime.

**Does this cost money?**
The plugin is free. However, Google requires a billing account for the Places API. Most small businesses stay within the free tier ($200/month credit).

**Will old reviews be updated?**
Yes! The plugin checks for existing reviews and updates them if the content changes.

**Can I customize the review display?**
Absolutely! Since reviews are stored as CPTs, you have 100% design control using your preferred page builder.

## 🐛 Support

For issues and feature requests, please use the [GitHub Issues](https://github.com/thebusinesstoolkitdevgoogle-reviews-cpt/issues) page.

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for all changes.

## 📄 License

GPL v2 or later - see [LICENSE](LICENSE) file for details.

## 👨‍💻 Author

Created by The Business Toolkit(https://www.thebusinesstoolkit.com/)

## ⭐ Show Your Support

If this plugin helped you, please consider giving it a star on GitHub!

---

**Made with ❤️ for the WordPress community**
