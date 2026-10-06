# SIA — Unused Image Cleanup for WordPress

Find and clean up unused images in your media library. Flag oversized files for manual review.

## Features

- **Unused image detection** — scans featured images, post content, ACF fields, ACF blocks, widgets, customizer, WooCommerce galleries, site icon/logo, and image URLs in postmeta.
- **Admin UI** — review unused images before deleting; trash or dismiss individually or in bulk. Browse large files in a separate tab.
- **Background processing** — uses Action Scheduler for WPEngine-friendly batched scanning (no wp-cron abuse).
- **WP-CLI support** — `wp sia scan`, `wp sia clean`, `wp sia status`, `wp sia large` for server-side usage.
- **Large file flagging** — images over a configurable threshold (default 500 KB) are listed for manual optimization.

See [CHANGELOG.md](CHANGELOG.md) for the full rebuild summary.

## Requirements

- WordPress 6.0+
- PHP 8.1+

## Installation

```bash
cd wp-content/plugins/sia
composer install
```

Activate the plugin in wp-admin. Tables are created automatically on activation.

## WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp sia scan` | Run a full scan with progress bar |
| `wp sia scan --batch-size=500` | Custom batch size |
| `wp sia clean --dry-run` | List what would be deleted |
| `wp sia clean --yes` | Trash all unused images |
| `wp sia clean --force --yes` | Permanently delete unused (from last scan) |
| `wp sia empty-trash` | Wipe `sia_deleted` log + `sia-backups/` (asks Y/N) |
| `wp sia empty-trash --yes` | Same, skip confirmation |
| `wp sia purge` | Remove only backups older than retention days |
| `wp sia status` | Show scan status and counts |
| `wp sia large` | List oversized files |
| `wp sia large --format=csv` | Export as CSV |

### Multisite

On a multisite install, target a specific site with `--url`:

```bash
wp sia scan --url=subsite.example.com
wp sia status --url=subsite.example.com
wp sia clean --dry-run --url=subsite.example.com
```

Use the site’s URL as configured in **Sites** (e.g. `https://subsite.example.com` or the path-based URL). Each site has its own scan results, backups, and settings.

## WPEngine

For best results, run scans via SSH instead of the admin UI:

```bash
wp sia scan --batch-size=200
```

Or set up a WPEngine cron job pointing to `wp sia scan`.

## Admin UI

Navigate to **Tools > SIA** in wp-admin. Three tabs:

1. **Unused Images** — scan results with thumbnail, filename, type, size. Trash or dismiss.
2. **Large Files** — images over the threshold with dimensions and edit links.
3. **Settings** — threshold, schedule (weekly/monthly/off), batch size.
