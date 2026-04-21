=== Divi Fragment Cache ===
Contributors: echo2k
Tags: divi, cache, performance, shortcode
Requires at least: 5.8
Tested up to: 6.6.2
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cache dei frammenti HTML generati dagli shortcode dei moduli Divi (et_pb_*), con invalidazione automatica al salvataggio del post.
Fragment caching for Divi module shortcodes (et_pb_*), with automatic invalidation on post updates.

== Description ==

Divi Fragment Cache hooks into Divi module shortcodes (`et_pb_*`) and caches the generated HTML (plus any builder-generated CSS, when available).

Key features:

* Per-fragment (module) cache keyed by post, locale, attributes and occurrence.
* Automatic invalidation when the post is saved or deleted.
* Query params for bypass/purge (useful for debugging).
* Settings page for TTL and allow/deny lists.

== Installation ==

1. Upload the `divi-fragment-cache` folder to `wp-content/plugins/` or install the zip via WordPress.
2. Activate the plugin from “Plugins”.
3. (Optional) Configure TTL and allow/deny lists in “Settings → Divi Fragment Cache”.

== Frequently Asked Questions ==

= Does it work without Divi? =

The plugin only targets `et_pb_*` shortcodes. Without Divi it effectively does nothing.

= How do I bypass the cache? =

Add `?divi_fc_bypass=1` to the URL.

= How do I purge the cache for the current page? =

If you have permissions (administrator or can edit the post), add `?divi_fc_purge=1` to the URL.

== Changelog ==

= 0.1.0 =
* Initial release.
