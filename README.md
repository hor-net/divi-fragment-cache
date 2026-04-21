# Divi Fragment Cache

WordPress plugin that adds fragment caching for Divi module shortcodes (`et_pb_*`), reducing PHP work and render time on pages with many repeated sections/modules.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Divi theme/builder (uses `et_pb_*` shortcodes)

## How it works

- Hooks into shortcode execution via `pre_do_shortcode_tag` and `do_shortcode_tag`.
- For each Divi module it computes a cache key based on:
  - current post
  - locale
  - shortcode tag
  - occurrence (render order)
  - shortcode attributes and inner content
- Caches:
  - module HTML
  - builder-generated CSS during the first execution (when available)

## Settings

Path: “Settings → Divi Fragment Cache”.

- Cache TTL (seconds): `0` disables caching.
- Cache for logged-in users: disabled by default.
- Debug header: adds `X-Divi-FC` to the response (hits/misses/bypass/purges).
- Deny/allow shortcodes: one tag per line.

## Debug / Query params

- Cache bypass:
  - `?divi_fc_bypass=1`
- Purge cache for current post:
  - `?divi_fc_purge=1`

## Hooks / Filters

- `divi_fragment_cache_ttl` (int): TTL in seconds.
- `divi_fragment_cache_logged_in` (bool): enable/disable caching for logged-in users.
- `divi_fragment_cache_denied_tags` (array): denied shortcode tags.
- `divi_fragment_cache_allowed_tags` (array): allowed shortcode tags (if not empty, acts as a whitelist).

## Uninstall

Removes the `dfc_options` option and the `_divi_fragment_cache_keys` post meta used to track cache keys associated with posts.
