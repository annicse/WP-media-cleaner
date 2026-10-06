<?php

namespace StemAgency\Sia;

final class Database
{
    public static function prefix(): string
    {
        global $wpdb;
        return $wpdb->prefix;
    }

    public static function resultsTable(): string
    {
        return self::prefix() . 'sia_results';
    }

    public static function usageTable(): string
    {
        return self::prefix() . 'sia_usage';
    }

    public static function largeTable(): string
    {
        return self::prefix() . 'sia_large';
    }

    public static function deletedTable(): string
    {
        return self::prefix() . 'sia_deleted';
    }

    public static function createTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $results = self::resultsTable();
        $usage   = self::usageTable();
        $large   = self::largeTable();
        $deleted = self::deletedTable();

        $sql = [
            "CREATE TABLE IF NOT EXISTS {$results} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attachment_id BIGINT UNSIGNED NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'unused',
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY attachment_id (attachment_id),
                KEY status (status)
            ) {$charset};",

            "CREATE TABLE IF NOT EXISTS {$usage} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attachment_id BIGINT UNSIGNED NOT NULL,
                used_in_post_id BIGINT UNSIGNED NULL,
                usage_type VARCHAR(50) NOT NULL,
                PRIMARY KEY (id),
                KEY attachment_id (attachment_id),
                UNIQUE KEY att_post_type (attachment_id, used_in_post_id, usage_type)
            ) {$charset};",

            "CREATE TABLE IF NOT EXISTS {$large} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attachment_id BIGINT UNSIGNED NOT NULL,
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NOT NULL DEFAULT 0,
                height INT UNSIGNED NOT NULL DEFAULT 0,
                flagged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY attachment_id (attachment_id)
            ) {$charset};",

            "CREATE TABLE IF NOT EXISTS {$deleted} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                attachment_id BIGINT UNSIGNED NOT NULL,
                post_title VARCHAR(255) NOT NULL DEFAULT '',
                file_path VARCHAR(500) NOT NULL DEFAULT '',
                mime_type VARCHAR(100) NOT NULL DEFAULT '',
                file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
                backup_path VARCHAR(500) NOT NULL DEFAULT '',
                used_in_posts LONGTEXT NULL,
                deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                deleted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
                restored_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY attachment_id (attachment_id),
                KEY deleted_at (deleted_at)
            ) {$charset};",
        ];

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ($sql as $query) {
            dbDelta($query);
        }
    }

    public static function dropTables(): void
    {
        global $wpdb;

        $tables = [
            self::usageTable(),
            self::resultsTable(),
            self::largeTable(),
            self::deletedTable(),
        ];

        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
    }

    public static function truncateForScan(): void
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE " . self::usageTable());
        $wpdb->query("TRUNCATE TABLE " . self::resultsTable());
    }

    public static function totalImageAttachments(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'"
        );
    }

    public static function getImageAttachmentsBatch(int $offset, int $limit): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE %s
             ORDER BY ID ASC
             LIMIT %d OFFSET %d",
            'image/%',
            $limit,
            $offset
        ));
    }

    public static function recordUsage(int $attachmentId, ?int $postId, string $type): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . self::usageTable() . " (attachment_id, used_in_post_id, usage_type)
             VALUES (%d, %d, %s)",
            $attachmentId,
            $postId ?? 0,
            $type
        ));
    }

    /**
     * Insert many usage rows in a handful of multi-row statements instead of one
     * query per row. A scan can produce thousands of usage rows; inserting them
     * one at a time means thousands of sequential round-trips in a single PHP
     * request, which risks a timeout partway through (silently leaving the
     * index incomplete). Batching this into ~500-row statements cuts that to a
     * handful of queries.
     *
     * @param list<array{attachment_id: int, post_id: int, type: string}> $rows
     */
    public static function recordUsageBatch(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        global $wpdb;
        $table = self::usageTable();

        foreach (array_chunk($rows, 500) as $chunk) {
            $placeholders = [];
            $values       = [];

            foreach ($chunk as $row) {
                $placeholders[] = '(%d, %d, %s)';
                $values[]       = (int) $row['attachment_id'];
                $values[]       = (int) ($row['post_id'] ?? 0);
                $values[]       = (string) $row['type'];
            }

            $sql = "INSERT IGNORE INTO {$table} (attachment_id, used_in_post_id, usage_type) VALUES "
                . implode(', ', $placeholders);

            $wpdb->query($wpdb->prepare($sql, $values));
        }
    }

    /**
     * Whether a given DB table exists. Shared check for optional
     * integrations (WPML, legacy plugin tables) that may not be present.
     */
    public static function tableExists(string $table): bool
    {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }

    /**
     * Whether the attachment appears in the usage table (e.g. after index was persisted).
     */
    public static function hasUsage(int $attachmentId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM " . self::usageTable() . " WHERE attachment_id = %d LIMIT 1",
            $attachmentId
        ));
    }

    public static function markResult(int $attachmentId, string $status, int $fileSize = 0): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::resultsTable() . " (attachment_id, status, file_size, scanned_at)
             VALUES (%d, %s, %d, NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), file_size = VALUES(file_size), scanned_at = NOW()",
            $attachmentId,
            $status,
            $fileSize
        ));
    }

    public static function recordLargeFile(int $attachmentId, int $fileSize, int $width, int $height): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO " . self::largeTable() . " (attachment_id, file_size, width, height, flagged_at)
             VALUES (%d, %d, %d, %d, NOW())
             ON DUPLICATE KEY UPDATE file_size = VALUES(file_size), width = VALUES(width), height = VALUES(height), flagged_at = NOW()",
            $attachmentId,
            $fileSize,
            $width,
            $height
        ));
    }

    public static function getUnusedImages(int $perPage = 20, int $page = 1, string $orderby = 'scanned_at', string $order = 'DESC'): array
    {
        global $wpdb;

        $allowed_orderby = ['attachment_id', 'file_size', 'scanned_at'];
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'scanned_at';
        $order   = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $offset  = ($page - 1) * $perPage;
        $table   = self::resultsTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, p.post_title, p.post_mime_type, p.guid
             FROM {$table} r
             LEFT JOIN {$wpdb->posts} p ON p.ID = r.attachment_id
             WHERE r.status = 'unused'
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            $perPage,
            $offset
        ));
    }

    public static function countUnused(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::resultsTable() . " WHERE status = 'unused'"
        );
    }

    public static function getLargeFiles(int $perPage = 20, int $page = 1, string $orderby = 'file_size', string $order = 'DESC'): array
    {
        global $wpdb;

        $allowed_orderby = ['attachment_id', 'file_size', 'width', 'height', 'flagged_at'];
        $orderby = in_array($orderby, $allowed_orderby, true) ? $orderby : 'file_size';
        $order   = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $offset  = ($page - 1) * $perPage;
        $table   = self::largeTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*, p.post_title, p.post_mime_type, p.guid
             FROM {$table} l
             LEFT JOIN {$wpdb->posts} p ON p.ID = l.attachment_id
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d",
            $perPage,
            $offset
        ));
    }

    public static function countLargeFiles(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::largeTable()
        );
    }

    public static function setResultStatus(int $attachmentId, string $status): void
    {
        global $wpdb;

        $wpdb->update(
            self::resultsTable(),
            ['status' => $status],
            ['attachment_id' => $attachmentId],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Get usage records for an attachment (post IDs, titles, URLs, usage types).
     */
    public static function getUsageForAttachment(int $attachmentId): array
    {
        global $wpdb;

        $usage = self::usageTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.used_in_post_id, u.usage_type, p.post_title, p.post_type
             FROM {$usage} u
             LEFT JOIN {$wpdb->posts} p ON p.ID = u.used_in_post_id
             WHERE u.attachment_id = %d",
            $attachmentId
        ), ARRAY_A) ?: [];
    }

    public static function recordDeletion(
        int $attachmentId,
        string $postTitle,
        string $filePath,
        string $mimeType,
        int $fileSize,
        string $backupPath,
        string $usedInPostsJson,
        int $deletedBy
    ): void {
        global $wpdb;

        $wpdb->insert(
            self::deletedTable(),
            [
                'attachment_id' => $attachmentId,
                'post_title'    => $postTitle,
                'file_path'     => $filePath,
                'mime_type'     => $mimeType,
                'file_size'     => $fileSize,
                'backup_path'   => $backupPath,
                'used_in_posts' => $usedInPostsJson,
                'deleted_at'    => current_time('mysql'),
                'deleted_by'    => $deletedBy,
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );
    }

    public static function getDeletions(int $perPage = 20, int $page = 1): array
    {
        global $wpdb;

        $offset = ($page - 1) * $perPage;
        $table  = self::deletedTable();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE restored_at IS NULL
             ORDER BY deleted_at DESC
             LIMIT %d OFFSET %d",
            $perPage,
            $offset
        ));
    }

    public static function countDeletions(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::deletedTable() . " WHERE restored_at IS NULL"
        );
    }

    /**
     * Total rows in the deletion log (including restored).
     */
    public static function countAllDeletions(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::deletedTable());
    }

    /**
     * Empty the SIA deletion log table.
     */
    public static function truncateDeletedTable(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::deletedTable());
    }

    /**
     * Attachment IDs SIA has recorded as deleted (deletion log + results status).
     *
     * @return int[]
     */
    public static function getSiaDeletedAttachmentIds(): array
    {
        global $wpdb;

        $deletedTable = self::deletedTable();
        $resultsTable = self::resultsTable();

        $fromLog = $wpdb->get_col(
            "SELECT DISTINCT attachment_id FROM {$deletedTable} WHERE restored_at IS NULL"
        );

        $fromResults = $wpdb->get_col(
            "SELECT attachment_id FROM {$resultsTable} WHERE status = 'deleted'"
        );

        $ids = array_merge(
            array_map('intval', $fromLog ?: []),
            array_map('intval', $fromResults ?: [])
        );

        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids);

        return $ids;
    }

    public static function getDeletionByAttachmentId(int $attachmentId): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::deletedTable() . "
             WHERE attachment_id = %d AND restored_at IS NULL
             ORDER BY deleted_at DESC LIMIT 1",
            $attachmentId
        ));
    }

    public static function markRestored(int $attachmentId): void
    {
        global $wpdb;

        $wpdb->update(
            self::deletedTable(),
            ['restored_at' => current_time('mysql')],
            ['attachment_id' => $attachmentId, 'restored_at' => null],
            ['%s'],
            ['%d']
        );
    }
}
