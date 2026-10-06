# WP Media Cleaner (WPMC)

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENCE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.1-8892BF.svg)](https://php.net)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D%206.0-21759B.svg)](https://wordpress.org)
[![Action Scheduler](https://img.shields.io/badge/Queue-Action%20Scheduler-orange.svg)](https://actionscheduler.org)

**WP Media Cleaner (WPMC)** is a high-performance, developer-grade WordPress plugin designed to detect unused images in your Media Library, safely clean them up with multi-tier recovery backups, and flag oversized assets for optimization.

Built from the ground up for high-traffic environments, managed WordPress hosts (such as WPEngine), and complex enterprise tech stacks (ACF Pro, Gutenberg Blocks, WPML, and WooCommerce).

---

## Table of Contents

- [Key Highlights](#key-highlights)
- [How It Works](#how-it-works)
- [Requirements](#requirements)
- [Installation](#installation)
- [Admin UI Walkthrough](#admin-ui-walkthrough)
- [WP-CLI Command Reference](#wp-cli-command-reference)
  - [Scanning & Status](#1-scanning--status)
  - [Cleaning & Cleanup](#2-cleaning--cleanup)
  - [Inspection & Recovery](#3-inspection--recovery)
  - [Multisite Usage](#multisite-usage)
- [Developer Extensibility (Filters)](#developer-extensibility-filters)
- [Hosting & Production Tips](#hosting--production-tips)
- [Changelog & History](#changelog--history)
- [License & Author](#license--author)

---

## Key Highlights

- **Inverted Fast Scanner ($O(P + M)$ Complexity)**  
  Instead of executing thousands of slow database queries per image, WPMC compiles an in-memory and database index of all actively referenced images once, then checks attachments against that index in high-throughput batches.
- **11-Point Deep Detection Engine**  
  Scans featured images, post content, Gutenberg image/gallery blocks, ACF fields (Image, Repeater, Flexible Content, Group, Post Object), widgets, customizer settings, WooCommerce product galleries, site icon/logo, SEO meta (Yoast, The SEO Framework), and raw upload URLs.
- **WPML Multi-lingual Safe**  
  Automatically reconciles translation duplicates (`icl_translations`) so translated duplicate media items are never mistakenly cleaned while active in another language. Scans WPML String Translation tables (`icl_strings` / `icl_string_translations`) as well.
- **3-Layer Safety Net (Zero-Panic Recovery)**  
  1. **WordPress Trash:** Files are moved to trash first (recoverable via standard WP trash).
  2. **Physical File Backups:** Originals and all generated thumbnail sub-sizes are backed up to `wp-content/uploads/wpmc-backups/{date}/{id}/` along with a detailed `manifest.json`.
  3. **Permanent Deletion Log:** Preserves original metadata and a JSON map of all referencing posts for verification.
- **Asynchronous Background Processing**  
  Powered by WooCommerce Action Scheduler. Avoids server timeouts, respects memory ceilings, and eliminates fragile `wp-cron` re-scheduling.
- **Self-Healing Stuck Scans**  
  Stuck or crashed background workers are automatically detected via timestamps (45-minute stale threshold) and cleared cleanly without locking up wp-admin.

---

## How It Works

```
┌─────────────────────────┐
│   1. Build Usage Index  │ ──> Compiles referenced image IDs across posts,
│   (Featured, ACF, Woo)  │     meta, options, blocks, and WPML strings.
└───────────┬─────────────┘
            ▼
┌─────────────────────────┐
│   2. Batch Comparison   │ ──> Compares media library attachments against
│   (Async Action Queue)  │     the index and marks each "used" or "unused".
└───────────┬─────────────┘
            ▼
┌─────────────────────────┐
│   3. Review & Manage    │ ──> Review results in wp-admin or via WP-CLI.
│  (wp-admin or WP-CLI)   │     Dismiss false-positives or batch trash.
└───────────┬─────────────┘
            ▼
┌─────────────────────────┐
│   4. Backup & Removal   │ ──> Files backed up to uploads/wpmc-backups/
│ (3-Tier Safety Archive) │     before trashing or force-deleting.
└─────────────────────────┘
```

---

## Requirements

| Requirement | Minimum Supported | Recommended |
|-------------|-------------------|-------------|
| **PHP**     | `8.1`             | `8.2+`      |
| **WordPress** | `6.0`           | `6.4+`      |
| **Database** | MySQL `5.7+` / MariaDB `10.4+` | MySQL `8.0+` |

---

## Installation

### Via Git & Composer (Recommended)

1. Clone or copy the plugin into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins
   git clone https://github.com/imrulhasan/wp-media-cleaner.git
   cd wp-media-cleaner
   ```

2. Install runtime dependencies and build autoloaders:
   ```bash
   composer install --no-dev -o
   ```

3. Activate the plugin:
   - Via **wp-admin**: Navigate to **Plugins** and click **Activate** under **WP Media Cleaner**.
   - Via **WP-CLI**:
     ```bash
     wp plugin activate wp-media-cleaner
     ```

> [!NOTE]
> Database tables (`wpmc_results`, `wpmc_usage`, `wpmc_large`, `wpmc_deleted`) and default options are created automatically during activation.

---

## Admin UI Walkthrough

Go to **Tools > Media Cleaner** in the WordPress admin panel. The interface is split into four focused tabs:

### 1. Unused Images
Displays all detected unused media items with thumbnails, filenames, MIME types, and file sizes.
- **Actions:** Trash or Dismiss individual files, or perform bulk trash/dismissal using checkboxes.
- **Run Scan Now:** Kicks off an immediate background scan with live status polling.

### 2. Large Files
Lists media files that exceed your configured size threshold (default: **500 KB**).
- Displays dimensions ($W \times H$), file size, and provides direct links to the WordPress media edit screen for rapid manual optimization.

### 3. Deleted
The recovery center. Shows every attachment cleaned by the plugin:
- Displays thumbnails served directly from the backup directory.
- Lists all posts that historically referenced the image.
- Includes a one-click **Restore** button that pulls files back into place and reinstates the attachment.

### 4. Settings
- **Large file threshold:** Size limit in bytes (e.g., `512000` for 500 KB).
- **Auto-scan schedule:** Choose `Weekly`, `Monthly`, or `Off` (manual/CLI only).
- **Batch size:** Number of images processed per background action (10–500).
- **Backup retention:** Retention period in days for physical backup folders (default: 90 days). The deletion DB audit log is never deleted.

---

## WP-CLI Command Reference

WPMC provides first-class CLI commands under the `wp wpmc` namespace.

### 1. Scanning & Status

```bash
# Run a full scan (synchronous with live batch progress)
wp wpmc scan

# Run scan with custom batch sizing
wp wpmc scan --batch-size=500

# Run with deep URL LIKE fallback (rarely needed)
wp wpmc scan --deep

# Inspect current scan status, last scan timestamp, and totals
wp wpmc status

# Reset scan state if a worker timed out or crashed
wp wpmc reset-scan
```

### 2. Cleaning & Cleanup

```bash
# Dry run: preview which images would be trashed
wp wpmc clean --dry-run

# Trash all unused images (prompts for confirmation)
wp wpmc clean

# Trash all unused images without interactive prompt
wp wpmc clean --yes

# Permanently delete without moving to WP trash (backups are still created)
wp wpmc clean --force --yes

# Remove file backups older than the retention days threshold
wp wpmc purge
wp wpmc purge --dry-run

# Completely wipe WPMC recovery data (wpmc_deleted log + backup files)
wp wpmc empty-trash
wp wpmc empty-trash --yes
```

### 3. Inspection & Recovery

```bash
# List oversized media files
wp wpmc large

# Export large files list as CSV
wp wpmc large --format=csv > large-images.csv

# List all deleted records and backup availability
wp wpmc deleted

# Restore an attachment by its original ID
wp wpmc restore 1234
```

### Multisite Usage

On multisite installations, pass `--url` to target any subsite:

```bash
wp wpmc scan --url=subsite.example.com
wp wpmc status --url=subsite.example.com
wp wpmc clean --dry-run --url=subsite.example.com
```

---

## Developer Extensibility (Filters)

WPMC provides WordPress filter hooks for themes and custom plugins:

### 1. `wpmc_known_underscore_image_meta_keys`
By default, the scanner ignores meta keys starting with `_` to prevent false positive numbers from matching. Add private postmeta keys that store valid image attachment IDs:

```php
add_filter('wpmc_known_underscore_image_meta_keys', function (array $keys): array {
    $keys[] = '_my_custom_post_header_image_id';
    return $keys;
});
```

### 2. `wpmc_seo_plugin_option_sources`
Registers sitewide serialized options that contain image attachment IDs (e.g., custom theme options or third-party SEO plugins):

```php
add_filter('wpmc_seo_plugin_option_sources', function (array $sources): array {
    $sources['my_theme_settings'] = ['hero_fallback_image_id', 'footer_badge_id'];
    return $sources;
});
```

### 3. `wpmc_attachment_reference_tables`
Clears custom or legacy database table foreign keys before an attachment is deleted to prevent MySQL foreign key constraints:

```php
add_filter('wpmc_attachment_reference_tables', function (array $tables, int $attachmentId): array {
    $tables['wp_my_custom_gallery_cache'] = 'attachment_id';
    return $tables;
}, 10, 2);
```

---

## Hosting & Production Tips

### WPEngine & Enterprise Hosting

On large sites with tens of thousands of media attachments:
1. Prefer running scans over **SSH** using `wp wpmc scan --batch-size=200` to avoid web server HTTP timeouts.
2. Set up an automated recurring cron job in your hosting dashboard:
   ```bash
   wp wpmc scan --batch-size=250 --path=/nas/content/live/yoursite
   ```
3. When testing for the first time, always run `wp wpmc clean --dry-run` to preview the deletion queue.

---

## Changelog & History

See [CHANGELOG.md](CHANGELOG.md) for full version history, bug fixes, and architectural notes.

---

## License & Author

- **Author:** [Imrul Hasan](https://github.com/imrulhasan)
- **License:** Released under the [GNU General Public License v2.0 or later](LICENCE.md).
