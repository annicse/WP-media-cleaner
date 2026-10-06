<?php

namespace ImrulHasan\WPMC;

/**
 * Inverted scanner: builds an index of all referenced image IDs from the DB once,
 * then compares attachment list against it. O(posts + meta) instead of O(images × checks).
 *
 * Only image attachments (mime image/*) are indexed — videos and other media are ignored.
 */
final class Scanner
{
    /**
     * In-memory index: attachment_id => list of [post_id, type].
     *
     * @var array<int, list<array{post_id: int, type: string}>>
     */
    private static array $usedIndex = [];

    /**
     * Set of image attachment IDs (id => true). Loaded once per buildIndex().
     *
     * @var array<int, true>
     */
    private static array $imageIds = [];

    /**
     * Relative upload file path => attachment ID (images only).
     *
     * @var array<string, int>
     */
    private static array $fileToId = [];

    /**
     * Usage rows found since the last persistIndexToDatabase() call, awaiting write.
     * Kept separate from $usedIndex (which stays fully populated for the in-memory
     * scan path) so buildIndex() can flush progress to wpmc_usage incrementally
     * without losing the in-memory index used by scanBatch() in the same request.
     *
     * @var list<array{attachment_id: int, post_id: int, type: string}>
     */
    private static array $unflushedRows = [];

    /**
     * Classic / leftover HTML markers (blocks handled via parse_blocks).
     */
    private const HTML_ID_PATTERN = '/wp-image-(\d+)|attachment_id=["\'](\d+)["\']|data-id=["\'](\d+)["\']/';

    /**
     * Underscore-prefixed postmeta keys known to store a real attachment ID
     * (not ACF's private field-key reference rows). indexAcfMeta() otherwise
     * excludes ALL underscore-prefixed keys, since most private/core underscore
     * meta (_edit_last, _wpml_media_duplicate, etc.) hold small numeric values
     * that are NOT attachment IDs and would cause false "used" matches if
     * included blindly. These specific keys are safe, well-known exceptions.
     * Extend via the `wpmc_known_underscore_image_meta_keys` filter.
     */
    private const KNOWN_UNDERSCORE_IMAGE_META_KEYS = [
        '_yoast_wpseo_opengraph-image-id',
        '_yoast_wpseo_twitter-image-id',
    ];

    /**
     * Build the full usage index from the database (featured, content, ACF, options, etc.).
     * Call once per scan before processing batches.
     *
     * Flushes to wpmc_usage after each stage (via persistIndexToDatabase()) so a
     * crash/timeout partway through a large scan doesn't discard everything
     * already found — only what hasn't been reached yet is at risk.
     */
    public static function buildIndex(): void
    {
        self::$usedIndex     = [];
        self::$imageIds      = [];
        self::$fileToId      = [];
        self::$unflushedRows = [];

        self::loadImageAttachmentSet();

        self::indexFeaturedImages();
        self::persistIndexToDatabase();

        self::indexPostContent();
        self::persistIndexToDatabase();

        self::indexAcfMeta();
        self::persistIndexToDatabase();

        self::indexWooGalleries();
        self::indexOptions();
        self::indexAcfOptions();
        self::indexSiteIdentity();
        self::indexSeoPluginOptions();
        self::persistIndexToDatabase();

        self::indexAttachmentUrls();
        self::indexWpmlStrings();
        self::persistIndexToDatabase();
    }

    /**
     * Safety net: re-mark as used any "unused" result that is still a post/term featured image.
     * Catches edge cases where the inverted index missed a _thumbnail_id row.
     *
     * @return int Number of images corrected.
     */
    public static function reconcileFeaturedImages(): int
    {
        global $wpdb;

        $resultsTable = Database::resultsTable();

        $rows = $wpdb->get_results(
            "SELECT DISTINCT r.attachment_id, pm.post_id
             FROM {$resultsTable} r
             INNER JOIN {$wpdb->postmeta} pm
               ON pm.meta_key = '_thumbnail_id'
              AND pm.meta_value = CAST(r.attachment_id AS CHAR)
             WHERE r.status = 'unused'",
            ARRAY_A
        );

        $termRows = $wpdb->get_results(
            "SELECT DISTINCT r.attachment_id, tm.term_id
             FROM {$resultsTable} r
             INNER JOIN {$wpdb->termmeta} tm
               ON tm.meta_key = '_thumbnail_id'
              AND tm.meta_value = CAST(r.attachment_id AS CHAR)
             WHERE r.status = 'unused'",
            ARRAY_A
        );

        $fixed = [];

        if ($rows) {
            foreach ($rows as $row) {
                $id = (int) $row['attachment_id'];
                Database::recordUsage($id, (int) $row['post_id'], 'featured');
                $fixed[$id] = true;
            }
        }

        if ($termRows) {
            foreach ($termRows as $row) {
                $id = (int) $row['attachment_id'];
                Database::recordUsage($id, 0, 'featured_term');
                $fixed[$id] = true;
            }
        }

        foreach (array_keys($fixed) as $id) {
            $fileSize = self::getFileSize($id);
            Database::markResult($id, 'used', $fileSize);
        }

        return count($fixed);
    }

    /**
     * Flush usage rows found since the last call to wpmc_usage, in batched
     * multi-row INSERTs, so async batch jobs can use them via hasUsage().
     *
     * Safe to call repeatedly (including mid-buildIndex()): only rows added
     * since the last flush are sent, and INSERT IGNORE makes re-sending a
     * row that's already there a harmless no-op.
     */
    public static function persistIndexToDatabase(): void
    {
        if (empty(self::$unflushedRows)) {
            return;
        }

        Database::recordUsageBatch(self::$unflushedRows);
        self::$unflushedRows = [];
    }

    /**
     * Safety net: if a WPML-duplicated image (per-language copy of the same
     * media item, sharing a translation group/trid) is marked "unused" but a
     * sibling copy in another language IS used, treat this one as used too.
     * WPML's media duplication doesn't guarantee every duplicate ends up
     * independently referenced in scanned content, so without this check a
     * live per-language image can be flagged as safe to delete.
     *
     * @return int Number of images corrected.
     */
    public static function reconcileWpmlDuplicates(): int
    {
        global $wpdb;

        $translationsTable = $wpdb->prefix . 'icl_translations';
        if (!Database::tableExists($translationsTable)) {
            return 0;
        }

        $resultsTable = Database::resultsTable();

        $unusedIds = $wpdb->get_col(
            "SELECT attachment_id FROM {$resultsTable} WHERE status = 'unused'"
        );

        if (empty($unusedIds)) {
            return 0;
        }

        $fixed = 0;

        foreach ($unusedIds as $id) {
            $id = (int) $id;

            $trid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT trid FROM {$translationsTable}
                 WHERE element_type = 'post_attachment' AND element_id = %d",
                $id
            ));

            if ($trid <= 0) {
                continue;
            }

            $siblings = $wpdb->get_col($wpdb->prepare(
                "SELECT element_id FROM {$translationsTable}
                 WHERE element_type = 'post_attachment' AND trid = %d AND element_id != %d",
                $trid,
                $id
            ));

            $siblingUsed = false;

            foreach ($siblings as $siblingId) {
                $siblingId = (int) $siblingId;

                $isUsed = (bool) $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM {$resultsTable} WHERE attachment_id = %d AND status = 'used' LIMIT 1",
                    $siblingId
                ));

                if ($isUsed || Database::hasUsage($siblingId)) {
                    $siblingUsed = true;
                    break;
                }
            }

            if ($siblingUsed) {
                Database::recordUsage($id, 0, 'wpml_sibling');
                Database::markResult($id, 'used', self::getFileSize($id));
                $fixed++;
            }
        }

        return $fixed;
    }

    /**
     * Process a batch of attachment IDs against the built index.
     * If index was built in this request, uses in-memory index; otherwise relies on wpmc_usage.
     *
     * @param int[] $attachmentIds
     * @param bool  $deep Extra per-image URL LIKE fallback (CLI --deep). Prefer inverted URL index.
     */
    public static function scanBatch(array $attachmentIds, bool $deep = false): void
    {
        $useMemoryIndex = !empty(self::$usedIndex);

        foreach ($attachmentIds as $id) {
            $id       = (int) $id;
            $fileSize = self::getFileSize($id);

            if ($useMemoryIndex) {
                if (isset(self::$usedIndex[$id])) {
                    foreach (self::$usedIndex[$id] as $usage) {
                        Database::recordUsage($id, $usage['post_id'], $usage['type']);
                    }
                    Database::markResult($id, 'used', $fileSize);
                } elseif ($deep && self::deepUrlCheck($id)) {
                    Database::recordUsage($id, 0, 'url_deep');
                    Database::markResult($id, 'used', $fileSize);
                } else {
                    Database::markResult($id, 'unused', $fileSize);
                }
            } else {
                // Async path: index was persisted to wpmc_usage by build-index action
                if (Database::hasUsage($id)) {
                    Database::markResult($id, 'used', $fileSize);
                } else {
                    Database::markResult($id, 'unused', $fileSize);
                }
            }
        }
    }

    /**
     * Optional slow check: does this image's URL appear in postmeta or post_content?
     * Only used with --deep. Uses LIMIT 1 to stop at first match.
     */
    public static function deepUrlCheck(int $attachmentId): bool
    {
        global $wpdb;

        $url = wp_get_attachment_url($attachmentId);
        if (!$url) {
            return false;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return false;
        }

        $like = '%' . $wpdb->esc_like($path) . '%';

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1",
            $like
        ));
        if ($found) {
            return true;
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type NOT IN ('attachment', 'revision') AND post_content LIKE %s
             LIMIT 1",
            $like
        ));

        return (bool) $found;
    }

    public static function isIndexBuilt(): bool
    {
        return !empty(self::$usedIndex);
    }

    public static function indexedCount(): int
    {
        return count(self::$usedIndex);
    }

    // ── Index builders ─────────────────────────────────────────────

    /**
     * Load all image attachment IDs and their _wp_attached_file paths once.
     */
    private static function loadImageAttachmentSet(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT p.ID, pm.meta_value AS file
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID AND pm.meta_key = '_wp_attached_file'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'",
            ARRAY_A
        );

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            self::$imageIds[$id] = true;

            $file = (string) ($row['file'] ?? '');
            if ($file !== '') {
                $normalized = self::normalizeUploadPath($file);
                if ($normalized !== '') {
                    self::$fileToId[$normalized] = $id;
                }
            }
        }
    }

    private static function isImageAttachment(int $id): bool
    {
        return $id > 0 && isset(self::$imageIds[$id]);
    }

    private static function indexFeaturedImages(): void
    {
        global $wpdb;

        // Avoid REGEXP (host/version quirks). Validate numeric IDs in PHP.
        $offset    = 0;
        $batchSize = 1000;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key = '_thumbnail_id' AND meta_value != '' AND meta_value != '0'
                 ORDER BY meta_id ASC LIMIT %d OFFSET %d",
                $batchSize,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $id = self::parseAttachmentId((string) $row['meta_value']);
                if ($id > 0) {
                    self::addToIndex($id, (int) $row['post_id'], 'featured');
                }
            }

            $offset += $batchSize;
        }

        // Category / taxonomy featured images (common with some themes & WooCommerce).
        $offset = 0;
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT term_id, meta_value FROM {$wpdb->termmeta}
                 WHERE meta_key = '_thumbnail_id' AND meta_value != '' AND meta_value != '0'
                 ORDER BY meta_id ASC LIMIT %d OFFSET %d",
                $batchSize,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $id = self::parseAttachmentId((string) $row['meta_value']);
                if ($id > 0) {
                    self::addToIndex($id, 0, 'featured_term');
                }
            }

            $offset += $batchSize;
        }
    }

    /**
     * Parse a postmeta/termmeta value into an attachment ID.
     */
    private static function parseAttachmentId(string $raw): int
    {
        $raw = trim($raw, " \t\n\r\0\x0B\"'");

        if ($raw === '' || !ctype_digit($raw)) {
            return 0;
        }

        return (int) $raw;
    }

    /**
     * Post content: classic HTML markers, native Gutenberg attrs, and ACF block data.
     */
    private static function indexPostContent(): void
    {
        global $wpdb;

        $batchSize = 200;
        $offset    = 0;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_type NOT IN ('attachment', 'revision') AND post_content != ''
                 ORDER BY ID ASC LIMIT %d OFFSET %d",
                $batchSize,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $postId  = (int) $row['ID'];
                $content = $row['post_content'];

                if (preg_match_all(self::HTML_ID_PATTERN, $content, $m, PREG_SET_ORDER)) {
                    foreach ($m as $match) {
                        $id = (int) ($match[1] ?: $match[2] ?: $match[3] ?: 0);
                        self::addToIndex($id, $postId, 'content');
                    }
                }

                if (function_exists('parse_blocks') && str_contains($content, '<!-- wp:')) {
                    self::indexBlocks(parse_blocks($content), $postId);
                }
            }
            $offset += $batchSize;
        }
    }

    /**
     * Recursively walk parsed Gutenberg blocks (core + ACF).
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    private static function indexBlocks(array $blocks, int $postId): void
    {
        foreach ($blocks as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            if ($name !== '') {
                if (str_starts_with($name, 'acf/')) {
                    self::indexAcfBlockData($attrs['data'] ?? [], $postId);
                } else {
                    self::indexCoreBlockAttrs($attrs, $postId);
                }
            }

            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::indexBlocks($block['innerBlocks'], $postId);
            }
        }
    }

    /**
     * Native block attributes: id, mediaId, ids[], etc.
     *
     * @param array<string, mixed> $attrs
     */
    private static function indexCoreBlockAttrs(array $attrs, int $postId): void
    {
        foreach (['id', 'mediaId', 'mediaID'] as $key) {
            if (isset($attrs[$key]) && is_numeric($attrs[$key])) {
                self::addToIndex((int) $attrs[$key], $postId, 'content');
            }
        }

        if (!empty($attrs['ids']) && is_array($attrs['ids'])) {
            foreach ($attrs['ids'] as $id) {
                if (is_numeric($id)) {
                    self::addToIndex((int) $id, $postId, 'content');
                }
            }
        }
    }

    /**
     * ACF block `data` object: skip `_field` keys; collect numeric / array / ACF image-array values.
     * Only keys that look like media fields are considered (avoids repeater counts like "logos":24).
     *
     * @param mixed $data
     */
    private static function indexAcfBlockData(mixed $data, int $postId): void
    {
        if (!is_array($data)) {
            return;
        }

        foreach ($data as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }

            if (!self::isLikelyMediaField($key)) {
                continue;
            }

            foreach (self::collectAttachmentIdsFromValue($value) as $id) {
                self::addToIndex($id, $postId, 'acf_block');
            }
        }
    }

    /**
     * Whether an ACF field name likely stores an image/media attachment ID.
     */
    private static function isLikelyMediaField(string $key): bool
    {
        $key = strtolower($key);

        // Row fields like logos_0_logo, slide_0_image, hero_background_image, picture.
        if (preg_match('/(^|_)(image|img|logo|picture|photo|thumbnail|thumb|poster|icon|avatar|banner|media|portrait|cover|gallery|bg)(_|$|\d)/', $key)) {
            return true;
        }

        return str_contains($key, 'background_image')
            || str_ends_with($key, '_image')
            || str_contains($key, '_image_');
    }

    /**
     * Pull candidate attachment IDs from an ACF value (int, list, or image array with ID/id).
     *
     * @return int[]
     */
    private static function collectAttachmentIdsFromValue(mixed $value): array
    {
        // Accept int IDs or digit-only strings; reject floats / non-digit strings.
        if (is_int($value) || (is_float($value) && $value == (int) $value)) {
            $id = (int) $value;
            return $id > 0 ? [$id] : [];
        }
        if (is_string($value) && ctype_digit($value)) {
            $id = (int) $value;
            return $id > 0 ? [$id] : [];
        }

        if (!is_array($value)) {
            return [];
        }

        // ACF image/file array return format.
        if (isset($value['ID']) && is_numeric($value['ID'])) {
            return [(int) $value['ID']];
        }
        if (isset($value['id']) && is_numeric($value['id'])) {
            return [(int) $value['id']];
        }

        // List of IDs or nested values (gallery).
        $ids = [];
        $isList = array_is_list($value);
        if ($isList) {
            foreach ($value as $item) {
                foreach (self::collectAttachmentIdsFromValue($item) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * ACF and other meta: plain numeric values + serialized values containing IDs.
     * Candidates are filtered to real image attachments only.
     */
    private static function indexAcfMeta(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT CAST(meta_value AS UNSIGNED) AS image_id, post_id
             FROM {$wpdb->postmeta}
             WHERE meta_key NOT LIKE '\_%'
             AND meta_value REGEXP '^[0-9]+\$'
             AND meta_value != '0'",
            ARRAY_A
        );

        if ($rows) {
            foreach ($rows as $row) {
                self::addToIndex((int) $row['image_id'], (int) $row['post_id'], 'acf_meta');
            }
        }

        self::indexKnownSeoImageMeta();

        $offset    = 0;
        $batchSize = 500;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_key NOT LIKE '\_%%' AND (meta_value LIKE '%%a:%%' OR meta_value LIKE '%%i:%%')
                 AND LENGTH(meta_value) > 5
                 ORDER BY meta_id ASC LIMIT %d OFFSET %d",
                $batchSize,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $postId = (int) $row['post_id'];
                if (preg_match_all('/(?:i:|")(\d+)(?:;|")/', $row['meta_value'], $m)) {
                    foreach ($m[1] as $id) {
                        self::addToIndex((int) $id, $postId, 'acf_meta');
                    }
                }
            }
            $offset += $batchSize;
        }
    }

    /**
     * SEO plugins (e.g. Yoast) sometimes store a per-post attachment ID under an
     * underscore-prefixed meta key. indexAcfMeta()'s main query excludes ALL
     * underscore-prefixed keys to avoid false matches from core/private meta
     * (_edit_last, _wpml_media_duplicate, etc. hold small numbers that aren't
     * attachment IDs). This targets only the specific, known-safe key names.
     */
    private static function indexKnownSeoImageMeta(): void
    {
        global $wpdb;

        $keys = apply_filters('wpmc_known_underscore_image_meta_keys', self::KNOWN_UNDERSCORE_IMAGE_META_KEYS);
        $keys = array_values(array_filter(array_map('strval', (array) $keys)));

        if (empty($keys)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($keys), '%s'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT CAST(meta_value AS UNSIGNED) AS image_id, post_id
             FROM {$wpdb->postmeta}
             WHERE meta_key IN ({$placeholders})
             AND meta_value REGEXP '^[0-9]+\$'
             AND meta_value != '0'",
            $keys
        ), ARRAY_A);

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            self::addToIndex((int) $row['image_id'], (int) $row['post_id'], 'seo_meta');
        }
    }

    /**
     * Some SEO plugins store a site-wide social/logo image ID inside one big
     * serialized option instead of an individually named option — e.g. The SEO
     * Framework's "autodescription-site-settings" holds `homepage_social_image_id`
     * and `knowledge_logo_id`. These don't match indexOptions()'s "widget_" or
     * "theme_mods_" patterns, or indexAcfOptions()'s "options_" pattern, so
     * they're otherwise invisible to the scanner. Extend via `wpmc_seo_plugin_option_sources`.
     */
    private static function indexSeoPluginOptions(): void
    {
        $sources = apply_filters('wpmc_seo_plugin_option_sources', [
            'autodescription-site-settings' => ['homepage_social_image_id', 'knowledge_logo_id'],
        ]);

        foreach ((array) $sources as $optionName => $fieldKeys) {
            $value = get_option((string) $optionName);
            if (!is_array($value)) {
                continue;
            }

            foreach ((array) $fieldKeys as $fieldKey) {
                if (!isset($value[$fieldKey])) {
                    continue;
                }

                foreach (self::collectAttachmentIdsFromValue($value[$fieldKey]) as $id) {
                    self::addToIndex($id, 0, 'seo_options');
                }
            }
        }
    }

    private static function indexWooGalleries(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_value != ''",
            ARRAY_A
        );

        if ($rows) {
            foreach ($rows as $row) {
                $postId = (int) $row['post_id'];
                foreach (array_filter(array_map('intval', explode(',', $row['meta_value']))) as $id) {
                    self::addToIndex($id, $postId, 'woo_gallery');
                }
            }
        }
    }

    /**
     * Widget and theme_mods options only (parse for numeric IDs).
     */
    private static function indexOptions(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'widget\_%%' OR option_name LIKE 'theme\_mods\_%%'",
            ARRAY_A
        );

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $type = str_starts_with($row['option_name'], 'widget_') ? 'widget' : 'customizer';
            if (preg_match_all('/\b(\d{2,})\b/', $row['option_value'], $m)) {
                foreach ($m[1] as $id) {
                    self::addToIndex((int) $id, 0, $type);
                }
            }
        }
    }

    /**
     * ACF Options pages store values as options_{field} (and sometimes options_{field}_*).
     */
    private static function indexAcfOptions(): void
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'options\_%%' AND option_name NOT LIKE '\_%%'",
            ARRAY_A
        );

        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $name  = $row['option_name'];
            $value = $row['option_value'];

            // Skip ACF field-key reference rows (options__fieldname => field_xxx).
            if (str_contains($name, '__') || str_starts_with((string) $value, 'field_')) {
                continue;
            }

            if (is_numeric($value) && (int) $value > 0 && ctype_digit((string) $value)) {
                self::addToIndex((int) $value, 0, 'acf_options');
                continue;
            }

            if (is_string($value) && (str_contains($value, 'a:') || str_contains($value, 'i:'))) {
                if (preg_match_all('/(?:i:|")(\d+)(?:;|")/', $value, $m)) {
                    foreach ($m[1] as $id) {
                        self::addToIndex((int) $id, 0, 'acf_options');
                    }
                }
            }
        }
    }

    private static function indexSiteIdentity(): void
    {
        $siteIcon   = (int) get_option('site_icon');
        $customLogo = (int) get_theme_mod('custom_logo');

        if ($siteIcon > 0) {
            self::addToIndex($siteIcon, 0, 'site_identity');
        }
        if ($customLogo > 0) {
            self::addToIndex($customLogo, 0, 'site_identity');
        }
    }

    /**
     * Find upload paths in post_content and postmeta; map back to image attachment IDs.
     * Runs for every scan (not only CLI --deep).
     */
    private static function indexAttachmentUrls(): void
    {
        if (empty(self::$fileToId)) {
            return;
        }

        global $wpdb;

        $batchSize = 200;
        $offset    = 0;
        $pathRegex = self::uploadsPathRegex();

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE post_type NOT IN ('attachment', 'revision')
                   AND post_content LIKE %s
                 ORDER BY ID ASC LIMIT %d OFFSET %d",
                '%wp-content/uploads/%',
                $batchSize,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                self::indexUrlsInString((string) $row['post_content'], (int) $row['ID'], 'url_content', $pathRegex);
            }
            $offset += $batchSize;
        }

        $offset = 0;
        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT post_id, meta_value FROM {$wpdb->postmeta}
                 WHERE meta_value LIKE %s
                 ORDER BY meta_id ASC LIMIT %d OFFSET %d",
                '%wp-content/uploads/%',
                $batchSize,
                $offset
            ), ARRAY_A);

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                self::indexUrlsInString((string) $row['meta_value'], (int) $row['post_id'], 'url_meta', $pathRegex);
            }
            $offset += $batchSize;
        }
    }

    private static function indexUrlsInString(string $text, int $postId, string $type, string $pathRegex): void
    {
        if (!preg_match_all($pathRegex, $text, $m)) {
            return;
        }

        foreach ($m[1] as $relative) {
            $id = self::attachmentIdFromUploadPath($relative);
            if ($id > 0) {
                self::addToIndex($id, $postId, $type);
            }
        }
    }

    private static function uploadsPathRegex(): string
    {
        return '#(?:(?:https?:)?//[^/\"\'\s]+)?(?:/?(?:[^/\"\'\s]+/)*)?wp-content/uploads/([^\"\'\s?]+)#i';
    }

    /**
     * WPML String Translation keeps its own snapshot of translatable content —
     * including rendered block HTML with <img> tags and raw upload paths — in
     * icl_strings / icl_string_translations, entirely separate from wp_posts
     * and wp_postmeta. A translation job that never got fully re-saved back
     * into the live post can leave an image referenced only here, so without
     * this it could look unused even though it's a real translation asset.
     *
     * Note: icl_translate is intentionally NOT scanned — its field_data is
     * base64/gzip-encoded per row (encoding varies by field type and WPML
     * version), and decoding it reliably is out of scope for now.
     */
    private static function indexWpmlStrings(): void
    {
        global $wpdb;

        $pathRegex = self::uploadsPathRegex();
        $tables    = [$wpdb->prefix . 'icl_strings', $wpdb->prefix . 'icl_string_translations'];

        foreach ($tables as $table) {
            if (!Database::tableExists($table)) {
                continue;
            }

            $offset    = 0;
            $batchSize = 200;

            while (true) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT id, value FROM {$table}
                     WHERE (value LIKE %s OR value LIKE %s)
                     ORDER BY id ASC LIMIT %d OFFSET %d",
                    '%wp-content/uploads/%',
                    '%wp-image-%',
                    $batchSize,
                    $offset
                ), ARRAY_A);

                if (empty($rows)) {
                    break;
                }

                foreach ($rows as $row) {
                    $value = (string) $row['value'];

                    self::indexUrlsInString($value, 0, 'wpml_string', $pathRegex);

                    if (preg_match_all(self::HTML_ID_PATTERN, $value, $m, PREG_SET_ORDER)) {
                        foreach ($m as $match) {
                            $id = (int) ($match[1] ?: $match[2] ?: $match[3] ?: 0);
                            self::addToIndex($id, 0, 'wpml_string');
                        }
                    }
                }

                $offset += $batchSize;
            }
        }
    }

    /**
     * Map an uploads-relative path (possibly a resized variant) to an image attachment ID.
     */
    private static function attachmentIdFromUploadPath(string $relative): int
    {
        $relative = self::normalizeUploadPath($relative);
        if ($relative === '') {
            return 0;
        }

        if (isset(self::$fileToId[$relative])) {
            return self::$fileToId[$relative];
        }

        // Strip -100x100 / -scaled before extension.
        $original = preg_replace('/-scaled(?=\.[a-z0-9]+$)/i', '', $relative) ?? $relative;
        $original = preg_replace('/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $original) ?? $original;

        return self::$fileToId[$original] ?? 0;
    }

    private static function normalizeUploadPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if (preg_match('#wp-content/uploads/(.+)$#i', $path, $m)) {
            $path = $m[1];
        }

        // Drop query strings / fragments if present.
        $path = strtok($path, '?#') ?: $path;

        return strtolower($path);
    }

    /**
     * Record usage only for real image attachments (excludes videos and non-media IDs).
     */
    private static function addToIndex(int $imageId, int $postId, string $type): void
    {
        if (!self::isImageAttachment($imageId)) {
            return;
        }

        self::$usedIndex[$imageId][] = ['post_id' => $postId, 'type' => $type];
        self::$unflushedRows[]       = ['attachment_id' => $imageId, 'post_id' => $postId, 'type' => $type];
    }

    private static function getFileSize(int $attachmentId): int
    {
        $file = get_attached_file($attachmentId);
        return ($file && file_exists($file)) ? (int) filesize($file) : 0;
    }
}
