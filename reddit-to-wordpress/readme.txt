=== Reddit To WordPress ===
Contributors: attilakis
Tags: reddit, automation, wp-cron, embed, posts
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Fetch Reddit posts from configured subreddit rules and publish WordPress posts using randomized templates.

== Description ==

Reddit To WordPress lets you configure Reddit script-app credentials, add subreddit automation rules, and create WordPress posts from matching Reddit content. Media is embedded from its original URL and is not downloaded into the WordPress media library.

Features:

* Reddit credentials: client ID, client secret, username, password, and optional user agent.
* Reddit account test button.
* Multiple subreddit rules in a table.
* Per-rule content type filters: image, GIF, video, link, and text.
* Per-rule listing method: hot, new, top, and rising.
* Per-rule fetch interval in minutes, checked by WP-Cron every 15 minutes.
* Processes every matching unposted item returned by a rule run, not just the first item.
* The new listing is paged up to 500 Reddit items per run, with duplicate checks before posting.
* Per-rule max posts per run limit to avoid long imports consuming too many PHP workers.
* Runtime lock to prevent overlapping cron/manual imports.
* Per-rule last run and next run display.
* Draft or publish status globally or per subreddit rule.
* Dry run mode that logs intended posts without creating WordPress posts.
* Dry run preview button.
* Multiple templates with randomized selection.
* Template-level category and tag assignment with Reddit placeholders.
* Optional template binding per subreddit rule.
* Duplicate prevention using Reddit post IDs.
* Admin logs and posted-history clearing.

== Installation ==

1. Upload the `reddit-to-wordpress` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to Settings -> Reddit To WP.
4. Add Reddit script-app credentials.
5. Click Test Reddit account.
6. Add subreddit rules and templates.
7. Keep Dry run enabled until the preview and logs look correct.
8. Enable automation and choose Draft or Publish mode.

== Template Placeholders ==

Available placeholders:

* `{{title}}`
* `{{embed}}`
* `{{subreddit}}`
* `{{author}}`
* `{{reddit_url}}`
* `{{source_url}}`
* `{{media_url}}`
* `{{thumbnail}}`
* `{{score}}`
* `{{comments}}`
* `{{created}}`
* `{{excerpt}}`

Categories and tags accept the same placeholders. Use comma-separated or line-separated values, for example:

Categories: `{{subreddit}}`

Tags: `reddit, {{subreddit}}, {{author}}`

== Changelog ==

= 1.0.0 =
* Initial release.
