<?php

namespace StemAgency\Sia;

final class Optimizer
{
    /**
     * Scan all image attachments and flag those over the size threshold.
     */
    public static function flagLargeFiles(): void
    {
        global $wpdb;

        $threshold = (int) get_option('sia_large_threshold', 512000);

        $wpdb->query("TRUNCATE TABLE " . Database::largeTable());

        $offset    = 0;
        $batchSize = 200;

        while (true) {
            $ids = Database::getImageAttachmentsBatch($offset, $batchSize);
            if (empty($ids)) {
                break;
            }

            foreach ($ids as $id) {
                $id   = (int) $id;
                $file = get_attached_file($id);

                if (!$file || !file_exists($file)) {
                    continue;
                }

                $size = (int) filesize($file);
                if ($size < $threshold) {
                    continue;
                }

                $meta   = wp_get_attachment_metadata($id);
                $width  = (int) ($meta['width'] ?? 0);
                $height = (int) ($meta['height'] ?? 0);

                Database::recordLargeFile($id, $size, $width, $height);
            }

            $offset += $batchSize;
        }
    }
}
