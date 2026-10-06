# Changelog

## Unreleased — Bulletproofing the unused-image scan

Prompted by analyzing a real production database export (WPML multilingual site,
The SEO Framework, Yoast leftovers, ACF PRO, custom Gutenberg blocks). Found
several ways a genuinely-used image could still be flagged "unused" and cleaned
up, plus reliability gaps in how a scan runs. All changes favor treating an
image as "used" whenever there's any doubt — never the other way around.

### Detection: closed false-"unused" gaps

- **WPML translation-duplicate images now cross-checked.** WPML can create a
  separate Media Library attachment per language for the same image
  (`_wpml_media_duplicate` meta). Before: each duplicate was scored for
  "unused" completely independently, so a per-language copy could be flagged
  unused and deleted even while its sibling in another language was actively
  used — because WPML doesn't guarantee every duplicate is independently
  referenced in scanned content. Now: `Scanner::reconcileWpmlDuplicates()` runs
  after every scan, looks up each "unused" image's translation group via
  `icl_translations`, and if any sibling copy is used, marks the whole group
  used. (`src/Scanner.php`, `src/BackgroundJob.php`, `src/CLI/Commands.php`)

- **WPML String Translation content is now scanned.** WPML keeps its own
  snapshot of translatable content — including rendered block HTML with
  `<img>` tags and raw upload paths — in `icl_strings` / `icl_string_translations`,
  completely separate from `wp_posts`/`wp_postmeta`. Before: this was never
  read, so an image referenced only in a translation-job snapshot (e.g. one
  that hadn't been fully re-saved back into the live post) could look unused.
  Now: `Scanner::indexWpmlStrings()` scans both tables for upload paths and
  `wp-image-###` markers, the same way post content already is. Tables are
  skipped automatically on non-WPML sites. (`src/Scanner.php`)

- **Underscore-prefixed SEO image fields (Yoast) are no longer silently
  excluded.** `indexAcfMeta()` skipped every postmeta key starting with `_`,
  because most private/core underscore meta (`_edit_last`, `_wpml_media_duplicate`,
  etc.) holds small numbers that aren't attachment IDs and would cause false
  matches. But that blanket rule also hid genuinely useful fields like Yoast's
  per-post social-share image (`_yoast_wpseo_opengraph-image-id`,
  `_yoast_wpseo_twitter-image-id`), which use the same underscore convention.
  Before: a custom social-share image could be deleted as "unused" even while
  set as a post's Facebook/Twitter image. Now: a small, explicit, filterable
  allow-list (`wpmc_known_underscore_image_meta_keys`) is checked in addition to
  the normal public-key scan — nothing else about the underscore exclusion
  changes, so the false-positive risk it guards against is untouched.
  (`src/Scanner.php`)

- **Site-wide SEO image settings (The SEO Framework) are now indexed.**
  Confirmed in the sample database: The SEO Framework stores its site-wide
  social/logo image (`homepage_social_image_id`, `knowledge_logo_id`) inside
  one serialized option (`autodescription-site-settings`), which didn't match
  any of the existing option-name patterns (`widget_*`, `theme_mods_*`,
  `options_*`). Before: setting a sitewide OG/logo image via TSF was invisible
  to the scanner. Now: `Scanner::indexSeoPluginOptions()` reads known option
  fields directly (filterable via `wpmc_seo_plugin_option_sources` for other
  SEO plugins). (`src/Scanner.php`)

### Reliability: a scan can no longer get stuck or lose partial progress

- **Stuck scans now self-heal instead of blocking forever.** Before: starting
  a scan set `wpmc_scan_status` to `scanning`, and nothing but a fully completed
  scan ever set it back to `idle`. If a background worker crashed or a request
  timed out mid-scan, the site was stuck showing "Scan in progress" forever —
  no button, no CLI reset, no timeout. Now: the start time is recorded
  (`wpmc_scan_started_at`), and a scan sitting at `scanning` for more than 45
  minutes is treated as dead. The next scan attempt (button click, monthly
  cron, or `wp wpmc scan`) automatically clears the stale state and starts
  fresh; the admin page shows a "previous scan appears stuck" notice and lets
  you restart immediately instead of hiding the button; and a new
  `wp wpmc reset-scan` CLI command clears it on demand.
  (`src/BackgroundJob.php`, `src/Admin/AdminPage.php`, `src/Admin/AjaxHandlers.php`,
  `assets/admin.js`, `src/CLI/Commands.php`)

- **Usage data is now saved in batches, and progressively during the scan.**
  Before: `persistIndexToDatabase()` ran one `INSERT` per usage row found — on
  a mid-size multilingual site this can mean several thousand sequential
  database round-trips inside a single request, all executed only after the
  *entire* index had been built in memory. If that request timed out at any
  point, none of it had been saved and the whole index was lost, making
  everything look unused. Now: `Database::recordUsageBatch()` inserts up to
  500 rows per statement, and `Scanner::buildIndex()` flushes to the database
  after each indexing stage (featured images, then content, then custom
  fields/meta, then options/URLs) instead of only once at the very end — a
  crash partway through only risks the stage in progress, not everything
  already found. (`src/Database.php`, `src/Scanner.php`)

### Internal cleanup

- `Database::tableExists()` is now the single shared implementation (was
  duplicated privately in `Cleaner.php`; `Scanner.php`'s new WPML checks reuse
  the same one). (`src/Database.php`, `src/Cleaner.php`)

## Rebuild (production-ready architecture)

### Phase 1 — Plugin rebuild

- Replaced 4 flat PHP files with namespaced class-based architecture (`ImrulHasan\WPMC`)
- Fixed all SQL injection vulnerabilities (options_find, delete query, ACF operator precedence)
- Replaced wp-cron abuse (10s rescheduling) with Action Scheduler for background batching
- **Multisite**: network activation runs setup per site; `wp_initialize_site` for new sites; per-site backups; CLI `--url` for subsites
- Built 11 image-usage checks: featured image, post content, ACF meta, ACF blocks, options, widgets, customizer, WooCommerce gallery, site icon/logo, URL in postmeta, URL in post content
- Admin UI under Tools > Media Cleaner with 4 tabs: Unused Images, Large Files, Deleted, Settings
- WP-CLI commands: scan, clean, status, large, deleted, restore, purge
- Large-file flagging (configurable threshold, default 500KB)
- Eliminated temp-file caching (Cacher.php) in favor of direct DB inserts

### Phase 2 — Recovery system

- File backup before every deletion: copies originals + thumbnails to `wp-content/uploads/wpmc-backups/`
- `manifest.json` per backup with full metadata, file list, and post URLs
- `wpmc_deleted` DB table as permanent deletion log (never auto-purged)
- Deleted tab in admin with clickable post links for page-by-page verification
- One-click restore from admin UI or `wp wpmc restore` CLI
- Configurable backup retention (default 90 days, auto-cleanup after scans)

### Phase 3 — Scan performance (inverted scanner)

- One-pass index: build “used” set from DB once (featured, content regex, ACF meta, Woo galleries, options/widgets/theme_mods, site identity), then compare attachments to that set instead of per-image DB checks
- Index persisted to `wpmc_usage` so async batch jobs use `Database::hasUsage()` only (no shared memory)
- BackgroundJob: BUILD_INDEX action runs first, then N batch actions, then SCAN_DONE
- CLI scan: `buildIndex()` once, then batch loop; optional `--deep` flag for slow URL-in-content check on images not in index
