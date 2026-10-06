<?php

namespace ImrulHasan\WPMC\CLI;

use ImrulHasan\WPMC\BackgroundJob;
use ImrulHasan\WPMC\Database;
use ImrulHasan\WPMC\Scanner;
use ImrulHasan\WPMC\Cleaner;
use ImrulHasan\WPMC\Optimizer;
use WP_CLI;
use WP_CLI\Utils;

/**
 * WP Media Cleaner (WPMC) — Image cleanup and optimization commands.
 */
final class Commands
{
    /**
     * Run a full image usage scan (synchronous). Builds a usage index once, then marks each image used/unused.
     *
     * ## OPTIONS
     *
     * [--batch-size=<number>]
     * : Images per batch. Default: 200.
     *
     * [--deep]
     * : Extra per-image URL LIKE fallback for anything the inverted URL index missed. Usually unnecessary.
     *
     * ## EXAMPLES
     *
     *     wp wpmc scan
     *     wp wpmc scan --batch-size=500
     *     wp wpmc scan --deep
     *
     * @when after_wp_load
     */
    public function scan(array $args, array $assocArgs): void
    {
        $batchSize = (int) ($assocArgs['batch-size'] ?? 200);
        $deep      = Utils\get_flag_value($assocArgs, 'deep', false);
        $total     = Database::totalImageAttachments();

        if ($total === 0) {
            WP_CLI::success('No image attachments found.');
            return;
        }

        $totalBatches = (int) ceil($total / max($batchSize, 1));
        WP_CLI::log("Found {$total} image attachments. Scanning in {$totalBatches} batches of {$batchSize}...");
        WP_CLI::log('');

        update_option('wpmc_scan_status', 'scanning');
        update_option('wpmc_scan_started_at', time());

        Database::truncateForScan();

        WP_CLI::log('Building index...');
        $indexStart = microtime(true);
        Scanner::buildIndex();
        $indexTime = round(microtime(true) - $indexStart, 1);
        WP_CLI::log('Index built: ' . Scanner::indexedCount() . ' used IDs in ' . $indexTime . 's.');
        WP_CLI::log('');

        $startTime   = microtime(true);
        $offset      = 0;
        $batchNum    = 0;

        while ($offset < $total) {
            $batchNum++;
            $rangeStart = $offset + 1;
            $rangeEnd   = min($offset + $batchSize, $total);
            WP_CLI::log("Batch {$batchNum}/{$totalBatches}: scanning images {$rangeStart}–{$rangeEnd}...");

            $batchStart = microtime(true);
            $ids = Database::getImageAttachmentsBatch($offset, $batchSize);
            if (empty($ids)) {
                WP_CLI::log("  No more images found, finishing early.");
                break;
            }

            Scanner::scanBatch($ids, $deep);

            $batchTime = round(microtime(true) - $batchStart, 1);
            $unused    = Database::countUnused();
            WP_CLI::log("  Done in {$batchTime}s — {$unused} unused so far.");

            $offset += $batchSize;
        }

        WP_CLI::log('');
        WP_CLI::log('Reconciling featured images...');
        $fixed = Scanner::reconcileFeaturedImages();
        if ($fixed > 0) {
            WP_CLI::log("  Corrected {$fixed} image(s) that are featured but were marked unused.");
        }

        WP_CLI::log('Reconciling WPML translation duplicates...');
        $wpmlFixed = Scanner::reconcileWpmlDuplicates();
        if ($wpmlFixed > 0) {
            WP_CLI::log("  Corrected {$wpmlFixed} image(s) that are a used translation's duplicate.");
        }

        WP_CLI::log('Flagging large files...');
        Optimizer::flagLargeFiles();

        update_option('wpmc_scan_status', 'idle');
        delete_option('wpmc_scan_started_at');
        update_option('wpmc_last_scan', current_time('mysql'));

        $elapsed = round(microtime(true) - $startTime, 1);
        $unused  = Database::countUnused();
        $large   = Database::countLargeFiles();

        WP_CLI::success("Scan complete in {$elapsed}s. {$unused} unused images, {$large} large files flagged.");
    }

    /**
     * Delete (trash) unused images found by the last scan.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : List images that would be deleted without actually deleting them.
     *
     * [--force]
     * : Permanently delete instead of trashing.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp wpmc clean --dry-run
     *     wp wpmc clean --yes
     *     wp wpmc clean --force --yes
     *
     * @when after_wp_load
     */
    public function clean(array $args, array $assocArgs): void
    {
        $dryRun = Utils\get_flag_value($assocArgs, 'dry-run', false);
        $force  = Utils\get_flag_value($assocArgs, 'force', false);
        $total  = Database::countUnused();

        if ($total === 0) {
            WP_CLI::success('No unused images to clean. Run "wp wpmc scan" first.');
            return;
        }

        $images = Database::getUnusedImages($total, 1);

        if ($dryRun) {
            $action = $force ? 'permanently deleted' : 'moved to trash';
            WP_CLI::log("Dry run: {$total} image(s) would be {$action}:");
            WP_CLI::log('');

            $rows = [];
            foreach ($images as $img) {
                $rows[] = [
                    'ID'    => $img->attachment_id,
                    'Title' => $img->post_title ?: '(untitled)',
                    'Size'  => $img->file_size ? size_format($img->file_size) : '—',
                    'File'  => basename(get_attached_file((int) $img->attachment_id) ?: ''),
                ];
            }
            Utils\format_items('table', $rows, ['ID', 'Title', 'Size', 'File']);
            return;
        }

        $prompt = $force
            ? "Permanently delete {$total} unused images? Backups will be made, but WP trash will be bypassed."
            : "Move {$total} unused images to trash? Backups will be made.";

        WP_CLI::confirm($prompt, $assocArgs);

        $action   = $force ? 'Deleting' : 'Trashing';
        $progress = Utils\make_progress_bar("{$action} images", $total);
        $deleted  = 0;

        foreach ($images as $img) {
            $id = (int) $img->attachment_id;
            $ok = $force ? Cleaner::forceDeleteImage($id) : Cleaner::trashImage($id);
            if ($ok) {
                $deleted++;
            }
            $progress->tick();
        }

        $progress->finish();
        WP_CLI::success("{$deleted} images " . ($force ? 'permanently deleted' : 'trashed') . ".");
    }

    /**
     * Clear WPMC recovery data: truncate wp_wpmc_deleted and delete wpmc-backups/.
     * Does not touch the WordPress media library or WP trash.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be removed without deleting.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp wpmc empty-trash
     *     wp wpmc empty-trash --dry-run
     *     wp wpmc empty-trash --yes
     *
     * @subcommand empty-trash
     * @when after_wp_load
     */
    public function empty_trash(array $args, array $assocArgs): void
    {
        $dryRun = Utils\get_flag_value($assocArgs, 'dry-run', false);
        $stats  = Cleaner::getRecoveryStoreStats();

        if ($stats['log_rows'] === 0 && $stats['backup_dirs'] === 0) {
            WP_CLI::success('WPMC trash is already empty (no deletion log rows, no backup folders).');
            return;
        }

        WP_CLI::log('This clears WPMC recovery storage only — not WordPress media or WP trash.');
        WP_CLI::log('');
        WP_CLI::log('Will remove:');
        WP_CLI::log('  - Rows in ' . Database::deletedTable() . ': ' . $stats['log_rows']);
        WP_CLI::log('  - Backup date folders in wpmc-backups/: ' . $stats['backup_dirs']);
        WP_CLI::log('  - Approx. backup size: ' . size_format($stats['backup_bytes']));
        WP_CLI::log('  - Path: ' . Cleaner::backupDir());
        WP_CLI::log('');

        if ($dryRun) {
            WP_CLI::success('Dry run only — nothing removed.');
            return;
        }

        WP_CLI::confirm(
            'Type Y to permanently wipe the WPMC deletion log and all wpmc-backups. Restore will no longer be possible. Continue?',
            $assocArgs
        );

        $result = Cleaner::clearRecoveryStore();

        WP_CLI::success(
            sprintf(
                'Cleared %d deletion log row(s) and %d backup folder(s).',
                $result['log_rows'],
                $result['backup_dirs']
            )
        );
    }

    /**
     * Show scan status and summary.
     *
     * ## EXAMPLES
     *
     *     wp wpmc status
     *
     * @when after_wp_load
     */
    public function status(array $args, array $assocArgs): void
    {
        $status   = get_option('wpmc_scan_status', 'idle');
        $lastScan = get_option('wpmc_last_scan', 'never');
        $total    = Database::totalImageAttachments();
        $unused   = Database::countUnused();
        $large    = Database::countLargeFiles();

        $rows = [
            ['Key' => 'Scan status', 'Value' => $status],
            ['Key' => 'Last scan', 'Value' => $lastScan],
            ['Key' => 'Total image attachments', 'Value' => $total],
            ['Key' => 'Unused images', 'Value' => $unused],
            ['Key' => 'Large files flagged', 'Value' => $large],
            ['Key' => 'Large file threshold', 'Value' => size_format((int) get_option('wpmc_large_threshold', 512000))],
        ];

        Utils\format_items('table', $rows, ['Key', 'Value']);
    }

    /**
     * Force scan status back to idle. Use this if a scan is stuck (a crashed
     * or timed-out background worker can leave status at "scanning" forever,
     * since only a completed scan flips it back automatically).
     *
     * ## EXAMPLES
     *
     *     wp wpmc reset-scan
     *
     * @subcommand reset-scan
     * @when after_wp_load
     */
    public function reset_scan(array $args, array $assocArgs): void
    {
        $status = get_option('wpmc_scan_status', 'idle');

        if ($status !== 'scanning') {
            WP_CLI::success('No scan in progress — nothing to reset.');
            return;
        }

        BackgroundJob::unscheduleAll();
        WP_CLI::success('Scan status reset to idle and any queued scan actions were cleared.');
    }

    /**
     * List images over the size threshold.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table. Options: table, csv, json, yaml.
     *
     * ## EXAMPLES
     *
     *     wp wpmc large
     *     wp wpmc large --format=csv
     *
     * @when after_wp_load
     */
    public function large(array $args, array $assocArgs): void
    {
        $total = Database::countLargeFiles();

        if ($total === 0) {
            WP_CLI::success('No large files. Run "wp wpmc scan" first.');
            return;
        }

        $files  = Database::getLargeFiles($total, 1);
        $format = $assocArgs['format'] ?? 'table';
        $rows   = [];

        foreach ($files as $f) {
            $rows[] = [
                'ID'         => $f->attachment_id,
                'Title'      => $f->post_title ?: '(untitled)',
                'Size'       => size_format($f->file_size),
                'Dimensions' => $f->width && $f->height ? "{$f->width}x{$f->height}" : '—',
                'Type'       => $f->post_mime_type ?? '',
            ];
        }

        Utils\format_items($format, $rows, ['ID', 'Title', 'Size', 'Dimensions', 'Type']);
    }

    /**
     * List deleted images with their backup status.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format. Default: table. Options: table, csv, json, yaml.
     *
     * ## EXAMPLES
     *
     *     wp wpmc deleted
     *     wp wpmc deleted --format=json
     *
     * @when after_wp_load
     */
    public function deleted(array $args, array $assocArgs): void
    {
        $total = Database::countDeletions();

        if ($total === 0) {
            WP_CLI::success('No deleted images in the log.');
            return;
        }

        $deletions = Database::getDeletions($total, 1);
        $format    = $assocArgs['format'] ?? 'table';
        $rows      = [];

        foreach ($deletions as $d) {
            $hasBackup = $d->backup_path && is_dir(ABSPATH . $d->backup_path);
            $posts     = json_decode($d->used_in_posts ?: '[]', true);
            $postInfo  = [];
            foreach ($posts as $p) {
                $postInfo[] = ($p['title'] ?: "ID {$p['post_id']}") . " ({$p['usage_type']})";
            }

            $rows[] = [
                'ID'         => $d->attachment_id,
                'Title'      => $d->post_title ?: '(untitled)',
                'Size'       => $d->file_size ? size_format($d->file_size) : '—',
                'Deleted'    => $d->deleted_at,
                'Backup'     => $hasBackup ? 'Yes' : 'Expired',
                'Referenced' => $postInfo ? implode('; ', $postInfo) : 'None',
            ];
        }

        Utils\format_items($format, $rows, ['ID', 'Title', 'Size', 'Deleted', 'Backup', 'Referenced']);
    }

    /**
     * Restore a deleted image from backup.
     *
     * ## OPTIONS
     *
     * <id>
     * : The original attachment ID to restore.
     *
     * ## EXAMPLES
     *
     *     wp wpmc restore 1234
     *
     * @when after_wp_load
     */
    public function restore(array $args, array $assocArgs): void
    {
        $id = (int) ($args[0] ?? 0);

        if (!$id) {
            WP_CLI::error('Please provide an attachment ID.');
        }

        $record = Database::getDeletionByAttachmentId($id);
        if (!$record) {
            WP_CLI::error("No deletion record found for attachment ID {$id}.");
        }

        WP_CLI::log("Restoring attachment {$id} ({$record->post_title})...");

        if (Cleaner::restoreAttachment($id)) {
            WP_CLI::success("Attachment {$id} restored successfully.");
        } else {
            WP_CLI::error("Failed to restore attachment {$id}. Backup files may have been cleaned up.");
        }
    }

    /**
     * Clean up old backup files beyond the retention period.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be cleaned up without actually deleting.
     *
     * ## EXAMPLES
     *
     *     wp wpmc purge
     *     wp wpmc purge --dry-run
     *
     * @when after_wp_load
     */
    public function purge(array $args, array $assocArgs): void
    {
        $dryRun    = Utils\get_flag_value($assocArgs, 'dry-run', false);
        $retention = (int) get_option('wpmc_backup_retention_days', 90);
        $baseDir   = Cleaner::backupDir();

        WP_CLI::log("Retention period: {$retention} days.");
        WP_CLI::log("Backup directory: {$baseDir}");

        if (!is_dir($baseDir)) {
            WP_CLI::success('No backup directory found. Nothing to clean up.');
            return;
        }

        $cutoff = strtotime("-{$retention} days");
        $dirs   = glob($baseDir . '/*', GLOB_ONLYDIR);

        if (!$dirs) {
            WP_CLI::success('No backup date directories found.');
            return;
        }

        $toRemove = [];
        foreach ($dirs as $dateDir) {
            $dirDate = strtotime(basename($dateDir));
            if ($dirDate && $dirDate < $cutoff) {
                $toRemove[] = $dateDir;
            }
        }

        if (empty($toRemove)) {
            WP_CLI::success('No expired backups to clean up.');
            return;
        }

        if ($dryRun) {
            WP_CLI::log("Dry run: " . count($toRemove) . " backup directories would be removed:");
            foreach ($toRemove as $dir) {
                WP_CLI::log("  " . basename($dir));
            }
            return;
        }

        $removed = Cleaner::cleanupOldBackups();
        WP_CLI::success("{$removed} expired backup directories removed.");
    }
}
