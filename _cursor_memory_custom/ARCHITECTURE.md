# SIA Plugin Architecture

## Overview

WordPress plugin by Stem Agency (GitHub: designcontainer) to find/clean unused images and flag oversized files. Internal/agency use.

## Tech Stack

- PHP 8.1+, WordPress 6.0+
- Namespace: `StemAgency\Sia`
- Composer package: `designcontainer/sia`
- Background jobs: Action Scheduler (`woocommerce/action-scheduler ^3.9`)
- PSR-4 autoloading via Composer

## File Structure

```
sia/
  sia.php                    # Plugin header, constants (SIA_FILE, SIA_PATH, SIA_URL, SIA_VERSION), autoloader, boots Plugin::instance()
  composer.json              # designcontainer/sia, action-scheduler dep, PSR-4 StemAgency\Sia => src/
  src/
    Plugin.php               # Singleton. Registers activation/deactivation/uninstall hooks, admin page, CLI, BackgroundJob
    Database.php             # Static class. All table names, createTables (dbDelta), dropTables, truncate, CRUD queries
    Scanner.php              # Static class. 10 usage checks (featured, content, acf_meta, acf_block, options, widget, customizer, woo_gallery, site_identity, url_in_meta). scanBatch() entry point
    Cleaner.php              # Static class. trashImage, forceDeleteImage, bulkTrash, dismiss, bulkDismiss
    Optimizer.php            # Static class. flagLargeFiles() — iterates all attachments, checks filesize vs threshold
    BackgroundJob.php        # Static class. Action Scheduler hooks: sia_scan_batch, sia_scan_complete, sia_scheduled_scan. startScan queues batches
    Admin/
      AdminPage.php          # Tools > SIA menu page. 3 tabs: Unused, Large Files, Settings. Uses WP built-in table markup
      AjaxHandlers.php       # AJAX: sia_start_scan, sia_trash, sia_dismiss, sia_scan_status. All verify nonce + manage_options
    CLI/
      Commands.php           # WP-CLI: wp sia scan, clean, status, large. Progress bars via WP_CLI\Utils
  assets/
    admin.css                # Styles for admin page (badges, toolbar, table)
    admin.js                 # jQuery: select all, bulk trash/dismiss, scan polling, AJAX calls
```

## Database Tables

- `{prefix}sia_results` — One row per image attachment after scan. Columns: id, attachment_id (UNIQUE), status (unused/used/kept/deleted), file_size, scanned_at
- `{prefix}sia_usage` — Where each image is used. Columns: id, attachment_id, used_in_post_id, usage_type. UNIQUE(att, post, type)
- `{prefix}sia_large` — Images over size threshold. Columns: id, attachment_id (UNIQUE), file_size, width, height, flagged_at

## WP Options

- `sia_large_threshold` (int, bytes, default 512000)
- `sia_scan_schedule` (string: weekly/monthly/off)
- `sia_batch_size` (int, 10-500, default 100)
- `sia_last_scan` (datetime string)
- `sia_scan_status` (string: idle/scanning)

## Scanner Checks (11 total, in order)

1. `featured` — postmeta `_thumbnail_id = {id}`
2. `content` — post_content LIKE wp-image-{id}, attachment_id="{id}", data-id="{id}"
3. `acf_meta` — postmeta value = plain id, or serialized containing id. meta_key NOT LIKE `_%`
4. `acf_block` — post_content LIKE `"image":{id}`
5. `options` — options table value containing id (esc_like)
6. `widget` — options where name LIKE widget_% and value contains id
7. `customizer` — options where name LIKE theme_mods_% and value contains id
8. `woo_gallery` — postmeta `_product_image_gallery` containing id
9. `site_identity` — site_icon option and custom_logo theme_mod
10. `url_in_meta` — attachment URL path in any postmeta value
11. `url_in_content` — (PLANNED) attachment URL path in post_content

## Action Scheduler Hooks

- `sia_scan_batch` (args: offset, limit) — processes one batch via Scanner::scanBatch()
- `sia_scan_complete` — runs Optimizer::flagLargeFiles(), resets status
- `sia_scheduled_scan` — recurring, calls BackgroundJob::startScan()
- Group: `sia`

## Key Design Decisions

- Flag-first approach: images are flagged as unused, user reviews in admin UI, then trashes manually
- Trash-based deletion (not force delete) — WP trash keeps files on disk for 30 days
- All SQL uses $wpdb->prepare() — no string concatenation
- Action Scheduler replaces wp-cron abuse (old MVP rescheduled every 10s)
- No Cacher/temp files — direct DB inserts
- ACF query uses proper parentheses: (OR conditions) AND meta_key filter
