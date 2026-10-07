=== Pushwi ===
Contributors: butialabs
Tags: web push, push notifications, notifications, pwa, engagement
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pushwi web push: install the widget and send posts as push notifications from the editor.

== Description ==

Requires a [Pushwi](https://pushwi.com) account.

* Loads the Pushwi widget and serves the service worker at `/pushwi-sw.js`. No theme edits or files.
* Post editor box: send a notification or create a Pushwi draft on publish, or on demand for published posts.
* Target all subscribers or only those interested in the post's categories and tags.

Filters: `pushwi_campaign_payload`, `pushwi_campaign_category`, `pushwi_segment_definition`, `pushwi_subscriber_tags`, `pushwi_tag_taxonomies`, `pushwi_sdk_url`, `pushwi_api_url`, `pushwi_dashboard_url`. Action: `pushwi_campaign_created`.

Source: [github.com/butialabs/pushwi-wp](https://github.com/butialabs/pushwi-wp)

== External services ==

This plugin uses Pushwi, a web push service by Butiá Labs.

* **sdk.pushwi.com**: after you save the site public ID, every page loads `pushwi.js` and the service worker loads `pushwi-sw-core.js`. When a visitor subscribes, their push subscription, browser language, post interest tags and consent text are sent to `api.pushwi.com`; delivery and clicks are reported afterwards.
* **api.pushwi.com**: only with a secret API key, and only when an editor sends a post or tests the connection. Sends the notification title, message, post URL, image and icon URLs, and category/tag slugs.

[Terms of Service](https://pushwi.com/terms) · [Privacy Policy](https://pushwi.com/privacy)

== Installation ==

1. Go to **Settings → Pushwi** and paste the site public ID (`pub_...`, Pushwi dashboard → Widget → Snippet).
2. To send from the editor, add a secret API key (`sk_live_...`, Developer → API keys) and click **Test connection**.
3. Add your domain to the allowed origins of your Pushwi site.

== Frequently Asked Questions ==

= The service worker returns the home page =

Choose any permalink structure other than "Plain".

= WordPress is in a subdirectory =

Copy `/pushwi-sw.js` to your domain root; the settings page shows its URL.

== Changelog ==

= 1.0.0 =
* Initial release.
