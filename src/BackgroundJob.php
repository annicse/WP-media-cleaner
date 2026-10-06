<?php

namespace StemAgency\Sia;

final class BackgroundJob
{
    private const BUILD_INDEX_HOOK = 'sia_build_index';
    private const SCAN_BATCH_HOOK  = 'sia_scan_batch';
    private const SCAN_DONE_HOOK   = 'sia_scan_complete';
    private const RECURRING_HOOK   = 'sia_scheduled_scan';
    private const GROUP            = 'sia';

    /**
     * How long a scan can sit in "scanning" with no completion before it's
     * treated as dead (crashed worker, timed-out request, etc.) rather than
     * genuinely still running.
     */
    private const STALE_SCAN_SECONDS = 45 * MINUTE_IN_SECONDS;

    public static function register(): void
    {
        add_action(self::BUILD_INDEX_HOOK, [self::class, 'processBuildIndex']);
        add_action(self::SCAN_BATCH_HOOK, [self::class, 'processScanBatch'], 10, 2);
        add_action(self::SCAN_DONE_HOOK, [self::class, 'onScanComplete']);
        add_action(self::RECURRING_HOOK, [self::class, 'startScan']);
    }

    /**
     * Queue a full scan: clear old data, then enqueue build-index only.
     * Batches are chained after the index is persisted (avoids race with empty usage table).
     */
    public static function startScan(): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        $status = get_option('sia_scan_status', 'idle');
        if ($status === 'scanning' && !self::isScanStale()) {
            return;
        }

        if ($status === 'scanning') {
            // Previous run never called onScanComplete() (crashed worker, timed-out
            // request, etc.) — clear any orphaned queued actions before restarting.
            self::unscheduleAll();
        }

        update_option('sia_scan_status', 'scanning');
        update_option('sia_scan_started_at', time());

        Database::truncateForScan();

        as_enqueue_async_action(self::BUILD_INDEX_HOOK, [], self::GROUP);
    }

    /**
     * Whether the current "scanning" status is old enough to be considered dead
     * rather than genuinely in progress. Returns false while status is 'idle'.
     */
    public static function isScanStale(): bool
    {
        if (get_option('sia_scan_status', 'idle') !== 'scanning') {
            return false;
        }

        $startedAt = (int) get_option('sia_scan_started_at', 0);
        if ($startedAt <= 0) {
            // Status says "scanning" but we never recorded a start time (e.g. a
            // scan kicked off before this check existed) — treat as stale so it
            // can't get stuck forever with no way out.
            return true;
        }

        return (time() - $startedAt) > self::STALE_SCAN_SECONDS;
    }

    /**
     * Build usage index, persist to sia_usage, then enqueue scan batches + completion.
     */
    public static function processBuildIndex(): void
    {
        Scanner::buildIndex();
        Scanner::persistIndexToDatabase();

        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        $total     = Database::totalImageAttachments();
        $batchSize = (int) get_option('sia_batch_size', 100);
        $batchSize = max($batchSize, 1);
        $batches   = (int) ceil($total / $batchSize);

        for ($i = 0; $i < $batches; $i++) {
            as_enqueue_async_action(
                self::SCAN_BATCH_HOOK,
                ['offset' => $i * $batchSize, 'limit' => $batchSize],
                self::GROUP
            );
        }

        as_enqueue_async_action(self::SCAN_DONE_HOOK, [], self::GROUP);
    }

    /**
     * Process one batch of image IDs.
     */
    public static function processScanBatch(int $offset, int $limit): void
    {
        $ids = Database::getImageAttachmentsBatch($offset, $limit);
        if (empty($ids)) {
            return;
        }
        Scanner::scanBatch($ids);
    }

    /**
     * Runs after all scan batches are done.
     */
    public static function onScanComplete(): void
    {
        Scanner::reconcileFeaturedImages();
        Scanner::reconcileWpmlDuplicates();
        Optimizer::flagLargeFiles();
        Cleaner::cleanupOldBackups();

        update_option('sia_scan_status', 'idle');
        delete_option('sia_scan_started_at');
        update_option('sia_last_scan', current_time('mysql'));
    }

    /**
     * Schedule a recurring monthly/weekly scan.
     */
    public static function scheduleRecurring(): void
    {
        if (!function_exists('as_next_scheduled_action')) {
            add_action('action_scheduler_init', [self::class, 'scheduleRecurring']);
            return;
        }

        $schedule = get_option('sia_scan_schedule', 'monthly');
        if ($schedule === 'off') {
            return;
        }

        $interval = $schedule === 'weekly' ? WEEK_IN_SECONDS : MONTH_IN_SECONDS;

        if (false === as_next_scheduled_action(self::RECURRING_HOOK, [], self::GROUP)) {
            as_schedule_recurring_action(
                time() + $interval,
                $interval,
                self::RECURRING_HOOK,
                [],
                self::GROUP
            );
        }
    }

    public static function unscheduleAll(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

        as_unschedule_all_actions(self::BUILD_INDEX_HOOK, [], self::GROUP);
        as_unschedule_all_actions(self::SCAN_BATCH_HOOK, [], self::GROUP);
        as_unschedule_all_actions(self::SCAN_DONE_HOOK, [], self::GROUP);
        as_unschedule_all_actions(self::RECURRING_HOOK, [], self::GROUP);

        update_option('sia_scan_status', 'idle');
        delete_option('sia_scan_started_at');
    }
}
