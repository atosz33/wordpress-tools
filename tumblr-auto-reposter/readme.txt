=== Tumblr Auto Reposter ===
Contributors: attilakis
Tags: tumblr, automation, repost, images, wp-cron
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Automatically reposts WordPress article images to Tumblr with randomized article/image selection, daily limits, image cooldowns, and OAuth connection.

== Description ==

Tumblr Auto Reposter creates Tumblr photo posts from existing WordPress articles. Each run randomly selects one eligible article and one eligible image from that article, then creates a Tumblr photo post with the image, article link, and linked article title.

Features:

* Connect Tumblr through OAuth 1.0a using your own Tumblr app consumer key and secret.
* Displays the callback URL to copy into the Tumblr app settings.
* Configurable Tumblr posts per day.
* Configurable image cooldown in days so the same image is not reused too soon.
* Randomized article and image selection.
* Supports featured images, attached media images, and images embedded in post content.
* Allows the same article to be selected again when a different image is available.
* Supports Tumblr queue, published, draft, or private post states.
* Dry run mode and admin logs.

== Installation ==

1. Upload the `tumblr-auto-reposter` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress admin.
3. Go to Settings -> Tumblr Reposter.
4. Create a Tumblr app, add the callback URL shown by the plugin, then save the consumer key and secret.
5. Click Connect Tumblr and authorize the app.
6. Set the Tumblr blog hostname, daily post count, cooldown, and post state.
7. Disable dry run when ready to create real Tumblr posts.

== Notes ==

WP-Cron depends on site traffic unless a real server cron triggers `wp-cron.php`.
