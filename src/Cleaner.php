<?php

namespace StemAgency\Sia;

final class Cleaner
{
    /**
     * Get the base backup directory path.
     */
    public static function backupDir(): string
    {
        $uploadDir = wp_upload_dir();
        return trailingslashit($uploadDir['basedir']) . 'sia-backups';
    }

    /**
     * Trash a single attachment (recoverable via WP trash).
     * Always backs up files and records the deletion first.
     *
     * Uses wp_trash_post() so media actually enters the trash even when
     * MEDIA_TRASH is false (wp_delete_attachment would permanently delete).
     */
    public static function trashImage(int $attachmentId): bool
    {
        self::backupAttachment($attachmentId);
        self::releaseExternalReferences($attachmentId);

        $result = wp_trash_post($attachmentId);
        if ($result) {
            update_post_meta($attachmentId, '_sia_trashed', '1');
            Database::setResultStatus($attachmentId, 'deleted');
            return true;
        }
        return false;
    }

    /**
     * Permanently delete an attachment and all its files.
     * Always backs up files and records the deletion first.
     */
    public static function forceDeleteImage(int $attachmentId): bool
    {
        self::backupAttachment($attachmentId);
        self::releaseExternalReferences($attachmentId);

        $result = wp_delete_attachment($attachmentId, true);
        if ($result) {
            delete_post_meta($attachmentId, '_sia_trashed');
            Database::setResultStatus($attachmentId, 'deleted');
            return true;
        }
        return false;
    }

    /**
     * Stats for SIA recovery storage (deletion log + backup folders).
     *
     * @return array{log_rows: int, backup_dirs: int, backup_bytes: int}
     */
    public static function getRecoveryStoreStats(): array
    {
        $baseDir = self::backupDir();
        $dirs    = (is_dir($baseDir) ? (glob($baseDir . '/*', GLOB_ONLYDIR) ?: []) : []);
        $bytes   = 0;

        foreach ($dirs as $dir) {
            $bytes += self::directorySize($dir);
        }

        return [
            'log_rows'     => Database::countAllDeletions(),
            'backup_dirs'  => count($dirs),
            'backup_bytes' => $bytes,
        ];
    }

    /**
     * Wipe sia_deleted rows and all files under uploads/sia-backups/.
     *
     * @return array{log_rows: int, backup_dirs: int}
     */
    public static function clearRecoveryStore(): array
    {
        $stats = self::getRecoveryStoreStats();

        Database::truncateDeletedTable();

        $baseDir = self::backupDir();
        $removed = 0;

        if (is_dir($baseDir)) {
            foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $dateDir) {
                self::removeDirectory($dateDir);
                $removed++;
            }
        }

        return [
            'log_rows'    => $stats['log_rows'],
            'backup_dirs' => $removed,
        ];
    }

    private static function directorySize(string $dir): int
    {
        $size = 0;

        if (!is_dir($dir)) {
            return 0;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Remove rows in external tables that FK-reference the attachment.
     * The legacy Stem Image Analyzer table blocks DELETE on wp_posts otherwise.
     */
    private static function releaseExternalReferences(int $attachmentId): void
    {
        global $wpdb;

        /** @var array<string, string> $tables table name => column holding attachment ID */
        $tables = [
            $wpdb->prefix . 'stem_image_analyzer' => 'image_id',
        ];

        /**
         * Tables that must be cleared before an attachment can be deleted.
         *
         * @param array<string, string> $tables Map of table => column.
         * @param int                   $attachmentId
         */
        $tables = apply_filters('sia_attachment_reference_tables', $tables, $attachmentId);

        foreach ($tables as $table => $column) {
            if (!is_string($table) || !is_string($column) || $table === '' || $column === '') {
                continue;
            }

            if (!Database::tableExists($table)) {
                continue;
            }

            $wpdb->delete($table, [$column => $attachmentId], ['%d']);
        }
    }

    /**
     * Trash multiple attachments. Returns count of successfully trashed.
     */
    public static function bulkTrash(array $attachmentIds): int
    {
        $count = 0;
        foreach ($attachmentIds as $id) {
            if (self::trashImage((int) $id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Mark attachment as "kept" so it won't be shown in unused list.
     */
    public static function dismiss(int $attachmentId): void
    {
        Database::setResultStatus($attachmentId, 'kept');
    }

    /**
     * Dismiss multiple attachments.
     */
    public static function bulkDismiss(array $attachmentIds): int
    {
        $count = 0;
        foreach ($attachmentIds as $id) {
            self::dismiss((int) $id);
            $count++;
        }
        return $count;
    }

    /**
     * Back up all files for an attachment and record the deletion in the DB.
     */
    private static function backupAttachment(int $attachmentId): void
    {
        $post = get_post($attachmentId);
        if (!$post) {
            return;
        }

        $mainFile = get_attached_file($attachmentId);
        $metadata = wp_get_attachment_metadata($attachmentId);
        $fileSize = ($mainFile && file_exists($mainFile)) ? (int) filesize($mainFile) : 0;

        $date      = current_time('Y-m-d');
        $backupDir = self::backupDir() . "/{$date}/{$attachmentId}";

        wp_mkdir_p($backupDir);

        $copiedFiles = [];

        if ($mainFile && file_exists($mainFile)) {
            $dest = $backupDir . '/' . basename($mainFile);
            copy($mainFile, $dest);
            $copiedFiles[] = basename($mainFile);
        }

        if (!empty($metadata['sizes']) && $mainFile) {
            $uploadDir = dirname($mainFile);
            foreach ($metadata['sizes'] as $size) {
                $thumbPath = $uploadDir . '/' . $size['file'];
                if (file_exists($thumbPath)) {
                    $dest = $backupDir . '/' . $size['file'];
                    copy($thumbPath, $dest);
                    $copiedFiles[] = $size['file'];
                }
            }
        }

        $usageRows = Database::getUsageForAttachment($attachmentId);
        $usedInPosts = [];

        foreach ($usageRows as $row) {
            $postId = (int) $row['used_in_post_id'];
            $url    = $postId > 0 ? get_permalink($postId) : '';

            $usedInPosts[] = [
                'post_id'    => $postId,
                'title'      => $row['post_title'] ?? '',
                'url'        => $url ?: '',
                'post_type'  => $row['post_type'] ?? '',
                'usage_type' => $row['usage_type'],
            ];
        }

        $manifest = [
            'attachment_id'  => $attachmentId,
            'post_title'     => $post->post_title,
            'post_mime_type' => $post->post_mime_type,
            'original_path'  => $mainFile ? str_replace(ABSPATH, '', $mainFile) : '',
            'file_size'      => $fileSize,
            'metadata'       => $metadata ?: [],
            'backed_up_files' => $copiedFiles,
            'used_in_posts'  => $usedInPosts,
            'deleted_at'     => current_time('mysql'),
            'deleted_by'     => get_current_user_id(),
        ];

        file_put_contents(
            $backupDir . '/manifest.json',
            wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $relativePath = str_replace(ABSPATH, '', $backupDir);

        Database::recordDeletion(
            $attachmentId,
            $post->post_title,
            $mainFile ? str_replace(ABSPATH, '', $mainFile) : '',
            $post->post_mime_type,
            $fileSize,
            $relativePath,
            wp_json_encode($usedInPosts),
            get_current_user_id()
        );
    }

    /**
     * Restore a previously deleted attachment from backup.
     */
    public static function restoreAttachment(int $attachmentId): bool
    {
        $record = Database::getDeletionByAttachmentId($attachmentId);
        if (!$record) {
            return false;
        }

        $backupDir = ABSPATH . $record->backup_path;
        $manifestFile = $backupDir . '/manifest.json';

        if (!file_exists($manifestFile)) {
            return false;
        }

        $manifest = json_decode(file_get_contents($manifestFile), true);
        if (!$manifest) {
            return false;
        }

        $originalRelPath = $manifest['original_path'] ?? '';
        if (!$originalRelPath) {
            return false;
        }

        $originalAbsPath = ABSPATH . $originalRelPath;
        $originalDir     = dirname($originalAbsPath);

        wp_mkdir_p($originalDir);

        $mainBasename = basename($originalAbsPath);
        $backupMain   = $backupDir . '/' . $mainBasename;
        if (file_exists($backupMain)) {
            copy($backupMain, $originalAbsPath);
        } else {
            return false;
        }

        if (!empty($manifest['backed_up_files'])) {
            foreach ($manifest['backed_up_files'] as $file) {
                if ($file === $mainBasename) {
                    continue;
                }
                $src  = $backupDir . '/' . $file;
                $dest = $originalDir . '/' . $file;
                if (file_exists($src)) {
                    copy($src, $dest);
                }
            }
        }

        $uploadDir = wp_upload_dir();
        $uploadBase = trailingslashit($uploadDir['basedir']);
        $relToUploads = str_replace($uploadBase, '', $originalAbsPath);

        $newAttachmentId = wp_insert_attachment(
            [
                'post_title'     => $manifest['post_title'] ?? '',
                'post_mime_type' => $manifest['post_mime_type'] ?? '',
                'post_status'    => 'inherit',
                'guid'           => trailingslashit($uploadDir['baseurl']) . $relToUploads,
            ],
            $originalAbsPath
        );

        if (is_wp_error($newAttachmentId) || !$newAttachmentId) {
            return false;
        }

        if (!empty($manifest['metadata'])) {
            wp_update_attachment_metadata($newAttachmentId, $manifest['metadata']);
        } else {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $newMeta = wp_generate_attachment_metadata($newAttachmentId, $originalAbsPath);
            wp_update_attachment_metadata($newAttachmentId, $newMeta);
        }

        Database::markRestored($attachmentId);

        return true;
    }

    /**
     * Remove backup directories older than the retention period.
     */
    public static function cleanupOldBackups(): int
    {
        $retentionDays = (int) get_option('sia_backup_retention_days', 90);
        $baseDir       = self::backupDir();
        $removed       = 0;

        if (!is_dir($baseDir)) {
            return 0;
        }

        $cutoff = strtotime("-{$retentionDays} days");
        $dirs   = glob($baseDir . '/*', GLOB_ONLYDIR);

        if (!$dirs) {
            return 0;
        }

        foreach ($dirs as $dateDir) {
            $dirName = basename($dateDir);
            $dirDate = strtotime($dirName);

            if ($dirDate && $dirDate < $cutoff) {
                self::removeDirectory($dateDir);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }
}
