# SIA — Implementation Plans

## Phase 1: Plugin Rebuild (COMPLETED)

Restructured MVP from flat procedural code to namespaced class-based plugin:
- Replaced main.php, search.php, Cacher.php, t_db.php with src/ classes
- Fixed all SQL injection vulnerabilities
- Added Action Scheduler for background processing
- Built admin UI (Tools > SIA) with Unused, Large Files, Settings tabs
- Added WP-CLI commands: scan, clean, status, large
- Added 10 scanner checks (up from 5 in MVP, 2 of which were dead code)

## Phase 2: Recovery System (NEXT)

### Goal
Add a 3-layer safety net so deleted images can be restored even after WP trash auto-empties.

### Layers
1. WordPress Trash (0-30 days) — already done
2. File backup to wp-content/sia-backups/{date}/{id}/ with manifest.json — before any deletion
3. sia_deleted DB table with full metadata + post URLs for review

### Tasks
1. Add sia_deleted table to Database.php
2. Add backupAttachment() and restoreAttachment() to Cleaner.php
3. Wire backup into trashImage() and forceDeleteImage()
4. Add wp sia restore, wp sia deleted, wp sia cleanup-backups CLI commands
5. Add Deleted tab to admin UI with restore button
6. Add cleanupOldBackups() to Cleaner
7. Add checkUrlInPostContent() to Scanner.php
8. Remove LIMIT 5 from checkUrlInPostmeta()

### New option
- sia_backup_retention_days (default 90)

## Phase 3: Testing (PLANNED)

- Manual testing on Laravel Valet
- PHPUnit with WordPress test scaffold
- Test suites: Database, Scanner, Cleaner, Optimizer
