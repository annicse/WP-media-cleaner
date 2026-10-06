# SIA — Key Decisions & Context

## Agency Context

- Built by Stem Agency (GitHub handle: designcontainer)
- Internal/agency use only (not for WordPress.org or commercial distribution)
- Sites use: ACF (Image, Repeater, Flexible Content, Group, Post Object, URL fields), Gutenberg, ACF Blocks
- No Elementor/Divi — only Gutenberg + ACF
- Hosted on WPEngine
- Some images are uploaded independently and referenced by URL, not attachment ID
- Sites can have thousands of articles/pages — manual review of every image is impractical

## User Preferences

- Delete behavior: flag first, review in admin UI, then trash (not auto-delete)
- Optimization scope: simple file size threshold flagging (not format conversion or compression analysis)
- Need post URLs in deletion log so user can page-by-page verify after deletion
- 90-day backup retention before cleanup
- WP-CLI is preferred for large sites (run via WPEngine SSH)

## Technical Decisions

- Namespace: `StemAgency\Sia` (refactored from Jetwp\Sia)
- Composer package: `designcontainer/sia`
- Background processing: Action Scheduler (not wp-cron)
- PHP 8.1+ (readonly properties, named arguments)
- No separate Cacher temp files — direct DB inserts
- All queries via $wpdb->prepare()

## Implemented in Phase 2 (Recovery System)

1. Recovery system: file backup to wp-content/uploads/sia-backups/ before any deletion
2. sia_deleted table with post URLs (JSON) and manifest.json per backup
3. Restore functionality (admin UI Restore button + `wp sia restore <id>` CLI)
4. checkUrlInPostContent() scanner check — find image URLs in post_content (11th check)
5. Removed LIMIT 5 from checkUrlInPostmeta()
6. Deleted tab in admin UI with post links, thumbnails from backup, restore button
7. Backup retention cleanup (runs after each scan, configurable via settings, default 90 days)
8. CLI: `wp sia deleted`, `wp sia restore <id>`, `wp sia purge --dry-run`
9. Settings: backup retention days (7-365, default 90)

## Planned but not yet implemented

1. Testing (PHPUnit + manual on Valet)
