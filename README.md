# WordPress Tools

A collection of WordPress plugins and tools.

## Plugins

### Thumbnail Manager

A WordPress admin tool to view and generate post featured images using the Pexels API.

**Features:**
- View all posts with their current featured images in a grid layout
- Search and select images from Pexels directly from the WordPress admin
- Generate or regenerate featured images for any post
- Choose from multiple image sizes (small, medium, large, large2x, original)
- Automatic image download and attachment to WordPress media library
- Photo credit attribution included in image metadata

**Requirements:**
- WordPress 5.0 or higher
- PHP 7.0 or higher
- Free Pexels API key ([Get one here](https://www.pexels.com/api/))

**Installation:**
1. Download the latest release from the [Releases](https://github.com/atosz33/wordpress-tools/releases) page
2. Upload the plugin zip file through WordPress admin (Plugins → Add New → Upload Plugin)
3. Activate the plugin
4. Go to Thumbnail Manager → Settings and add your Pexels API key

**Usage:**
1. Navigate to Thumbnail Manager in the WordPress admin menu
2. Click "Generate" on any post without a featured image, or "Regenerate" to replace an existing one
3. Enter a search query (e.g., "nature", "technology", "business")
4. Browse the search results and click on an image
5. Select your preferred image size
6. The image will be automatically downloaded, added to your media library, and set as the featured image

**Screenshots:**

![Main Grid View](https://i.imgur.com/bW2jc8G.jpeg)
![Search Modal](https://i.imgur.com/3TyXbIw.png)
![Image Selection](https://i.imgur.com/RZIhECY.jpeg)
![Size Selection Modal](https://i.imgur.com/CEBqEGk.png)

**Version:** 1.1

---

### Draft Post Scheduler

A WordPress admin tool to publish draft posts automatically through WP-Cron with configurable limits and visible run logs.

**Features:**
- Enable or disable scheduled publishing from the WordPress admin
- Run the scheduler at a configurable hourly interval
- Publish a randomized number of draft posts per run using minimum and maximum limits
- Enforce a daily publish limit to prevent too many posts going live in one day
- Use dry run mode to log what would be published without changing post statuses
- Trigger manual runs and review scheduler logs from the settings page

**Requirements:**
- WordPress 5.8 or higher
- PHP 7.4 or higher

**Installation:**
1. Download the latest release from the [Releases](https://github.com/atosz33/wordpress-tools/releases) page
2. Upload the plugin zip file through WordPress admin (Plugins → Add New → Upload Plugin)
3. Activate the plugin
4. Go to Settings → Draft Scheduler and configure the scheduler

**Usage:**
1. Navigate to Settings → Draft Scheduler in the WordPress admin menu
2. Enable the scheduler and set the run interval in hours
3. Configure the minimum and maximum number of posts to publish per run
4. Set a daily publish limit
5. Use dry run mode for testing, or click "Run now" to trigger a manual scheduler run
6. Review the log table to see published posts, skipped runs, and daily limit activity

**Screenshots:**

![Draft Post Scheduler Settings](docs/images/draft-post-scheduler.png)

**Version:** 1.0.0

---
