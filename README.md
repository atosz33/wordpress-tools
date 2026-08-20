# WordPress Tools

A collection of WordPress plugins and tools.

## Build

Build a plugin zip from the command line:

```bash
./scripts/build-plugin.sh
```

The script lets you select:
- `thumbnail-manager`
- `post-scheduler`
- `tumblr-auto-reposter`
- `reddit-to-wordpress`
- `pod-designer`
- `all`

You can also pass the plugin directly:

```bash
./scripts/build-plugin.sh tumblr-auto-reposter
./scripts/build-plugin.sh reddit-to-wordpress
./scripts/build-plugin.sh all
```

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

### Tumblr Auto Reposter

A WordPress admin tool to repost article images to Tumblr automatically through WP-Cron.

**Features:**
- Connect Tumblr through OAuth using your own Tumblr app consumer key and secret
- Displays the callback URL required by Tumblr app setup
- Randomly selects one WordPress article and one image from that article per run
- Creates Tumblr photo posts with the selected image, linked article title, and article URL
- Configurable daily Tumblr post limit
- Configurable image cooldown so the same image is not reused for X days
- Supports featured images, attached media images, and images embedded in article content
- Supports Tumblr queue, published, draft, and private states
- Dry run mode, manual run button, image cooldown clearing, and admin logs

**Requirements:**
- WordPress 5.8 or higher
- PHP 7.4 or higher
- Tumblr app consumer key and secret

**Installation:**
1. Upload the `tumblr-auto-reposter` folder through WordPress admin or copy it to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Settings -> Tumblr Reposter
4. Copy the displayed callback URL into your Tumblr app settings
5. Save the Tumblr consumer key, consumer secret, and target blog hostname
6. Click Connect Tumblr and authorize the app
7. Configure daily post count, cooldown, post state, image sources, and dry run mode

**Version:** 1.0.0

---

### Reddit To WordPress

A WordPress admin tool to fetch Reddit posts from configured subreddit rules and create WordPress posts through WP-Cron.

**Features:**
- Configure Reddit script-app credentials: client ID, client secret, username, password, and user agent
- Test the Reddit account from the settings page
- Add multiple subreddit rules with content type filters for images, GIFs, videos, links, and text
- Choose Reddit listing methods per rule: hot, new, top, and rising
- Set per-rule fetch intervals, checked by a 15-minute WP-Cron worker
- Process every matching unposted item returned by a rule run, including up to 500 items from the `new` listing
- Limit posts created per rule run to reduce PHP worker pressure during large imports
- Prevent overlapping imports with a runtime lock
- Show last run and next run timestamps for each subreddit rule
- Publish as draft or live posts globally, with per-rule overrides
- Use dry run mode and a dry run preview before creating posts
- Create multiple post templates with placeholders and randomized template selection
- Assign categories and tags from each template, including values like Reddit subreddit and author
- Optionally bind one or more templates to a subreddit rule
- Embed media by URL without downloading it into WordPress
- Avoid duplicate posts by tracking Reddit post IDs

**Requirements:**
- WordPress 5.8 or higher
- PHP 7.4 or higher
- Reddit script app credentials

**Installation:**
1. Upload the `reddit-to-wordpress` folder through WordPress admin or copy it to `/wp-content/plugins/`
2. Activate the plugin
3. Go to Settings -> Reddit To WP
4. Save Reddit credentials and click Test Reddit account
5. Add subreddit rules and templates
6. Keep dry run enabled until the logs and preview look correct, then switch to draft or publish mode

**Version:** 1.0.0

---

### POD Designer

A print-on-demand product designer: the customer places artwork and text on a t-shirt or mug mockup, and the admin gets the exact design plus print-ready files.

**Features:**
- Unlimited product templates, each with its own views (front, back, wrap), printable millimetre sizes and print areas
- Draw the print area straight onto the mockup image in the admin, stored in percent so it survives image swaps
- The bundled t-shirt and mug artwork is a reusable preset for any template: picking a built-in mockup also fills in the printable size, print area, non printable bands and the mug's cylinder wall
- One click template duplication, so a second t-shirt that only differs in its colours does not have to be rebuilt
- Configurable non printable bands at the top and bottom of each print area (a mug's rolled edges, for example), hatched in the designer and trimmed from the print-ready file
- Full page designer: container, wide or full viewport width, overridable per shortcode and per WooCommerce product, with a configurable working area height
- Mug templates carry their real diameter and height, so the 3D preview knows a 185 mm wrap covers only part of an 82 mm mug's circumference and leaves the rest for the handle
- Per-template colours with a hex value that tints the mockup in multiply mode, or a dedicated uploaded mockup photo per colour and view
- Layer editor with drag, corner scaling, rotation, layer ordering, keyboard nudging and deletion, with numeric fields next to the size and rotation sliders and a one click reset to 0 degrees
- Out-of-bounds validation: any layer reaching outside the printable area is flagged with a red outline and a warning triangle, the reason is spelled out on the layer row, and submitting is blocked until it is fixed (0.05 mm tolerance, rotation aware)
- The panel stacks below the product when the designer sits in a narrow column, so the working area stays usable inside a WooCommerce product summary
- Text layers with font, millimetre size, colour and bold
- Rotatable 3D mug preview that wraps the flat design around a cylinder, drawn on canvas without any external 3D library
- Live DPI warning below the configured minimum, repeated on the admin production sheet
- WooCommerce mode: assign a template to a product, the design travels with the cart item and the order item
- Standalone mode: the `[pod_designer]` shortcode renders the designer with a submission form and an admin e-mail notification
- Every submission is stored as a Design entry with preview images, print-ready PNGs, millimetre layer positions and a production status
- Print-ready downloads in three flavours: transparent, flattened onto the ordered product colour, and flattened onto white, rendered on request so older designs get them too
- Ships with built-in SVG t-shirt and mug mockups, so it works right after activation
- Fully translatable and bilingual out of the box: English source strings with a bundled Hungarian translation, covering the admin screens, the designer canvas and the customer facing messages, so the plugin follows the WordPress site language

**Requirements:**
- WordPress 5.8 or higher
- PHP 7.4 or higher
- WooCommerce is optional; without it the plugin runs in standalone shortcode mode

**Installation:**
1. Upload the `pod-designer` folder through WordPress admin or copy it to `/wp-content/plugins/`
2. Activate the plugin
3. Go to POD Designer -> Product templates and adjust the mockups, print areas and colours
4. Go to POD Designer -> Settings for upload limits, DPI values, fonts and the notification e-mail
5. For WooCommerce, edit a product and pick a template under Product data -> General
6. For standalone use, place `[pod_designer template="tshirt"]` on a page, optionally with `layout="full"`

**Usage notes:**
- Print-ready files are rendered in the browser and uploaded with the design, so `upload_max_filesize` and `post_max_size` should be at least 16 MB
- Export resolution is capped to stay inside the canvas pixel budget mobile browsers accept, and the resolution actually used is recorded on the production sheet

**Version:** 1.0.0

---
