=== Draft Post Scheduler ===
Contributors: attilakis
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Publishes draft posts through WP-Cron with configurable intervals, randomized per-run counts, a daily limit, dry run mode, and visible admin logs.

== Description ==

Draft Post Scheduler publishes unpublished WordPress posts from draft status. It only handles the built-in `post` post type.

Settings are available under Settings > Draft Scheduler:

* Enable or disable the scheduler.
* Run every N hours.
* Publish a random number of posts between the configured minimum and maximum on each run.
* Enforce a maximum number of posts per day.
* Dry run mode to log selected posts without publishing them.
* Manual run and visible logs.

== Notes ==

This plugin uses WP-Cron, so scheduled runs depend on site traffic unless real server cron is configured to call `wp-cron.php`.
