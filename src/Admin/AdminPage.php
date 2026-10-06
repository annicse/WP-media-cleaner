<?php

namespace StemAgency\Sia\Admin;

use StemAgency\Sia\Database;
use StemAgency\Sia\BackgroundJob;
use StemAgency\Sia\Cleaner;

final class AdminPage
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenuPage(): void
    {
        add_management_page(
            'SIA — Image Cleanup',
            'SIA',
            'manage_options',
            'sia',
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if ($hook !== 'tools_page_sia') {
            return;
        }

        wp_enqueue_style('sia-admin', SIA_URL . 'assets/admin.css', [], SIA_VERSION);
        wp_enqueue_script('sia-admin', SIA_URL . 'assets/admin.js', ['jquery'], SIA_VERSION, true);
        wp_localize_script('sia-admin', 'siaAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sia_admin'),
        ]);
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $tab = sanitize_text_field($_GET['tab'] ?? 'unused');

        ?>
        <div class="wrap sia-wrap">
            <h1>SIA — Image Cleanup</h1>

            <?php $this->renderTabs($tab); ?>

            <div class="sia-content">
                <?php
                switch ($tab) {
                    case 'large':
                        $this->renderLargeFilesTab();
                        break;
                    case 'deleted':
                        $this->renderDeletedTab();
                        break;
                    case 'settings':
                        $this->renderSettingsTab();
                        break;
                    default:
                        $this->renderUnusedTab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function renderTabs(string $active): void
    {
        $tabs = [
            'unused'   => 'Unused Images',
            'large'    => 'Large Files',
            'deleted'  => 'Deleted',
            'settings' => 'Settings',
        ];

        $unusedCount  = Database::countUnused();
        $largeCount   = Database::countLargeFiles();
        $deletedCount = Database::countDeletions();

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $slug => $label) {
            $class = ($active === $slug) ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url   = admin_url("tools.php?page=sia&tab={$slug}");
            $badge = '';
            if ($slug === 'unused' && $unusedCount > 0) {
                $badge = " <span class='sia-badge'>{$unusedCount}</span>";
            }
            if ($slug === 'large' && $largeCount > 0) {
                $badge = " <span class='sia-badge'>{$largeCount}</span>";
            }
            if ($slug === 'deleted' && $deletedCount > 0) {
                $badge = " <span class='sia-badge sia-badge-info'>{$deletedCount}</span>";
            }
            echo "<a href='{$url}' class='{$class}'>{$label}{$badge}</a>";
        }
        echo '</nav>';
    }

    private function renderUnusedTab(): void
    {
        $status   = get_option('sia_scan_status', 'idle');
        $lastScan = get_option('sia_last_scan', '');

        echo '<div class="sia-toolbar">';
        echo '<div class="sia-toolbar-left">';
        if ($status === 'scanning' && !BackgroundJob::isScanStale()) {
            echo '<span class="sia-status scanning">Scan in progress&hellip;</span>';
        } else {
            if ($status === 'scanning') {
                echo '<span class="sia-status sia-status-stale">Previous scan appears stuck — starting a new one will reset it.</span> ';
            }
            echo '<button class="button button-primary" id="sia-start-scan">Run Scan Now</button>';
        }
        if ($lastScan) {
            echo '<span class="sia-last-scan">Last scan: ' . esc_html($lastScan) . '</span>';
        }
        echo '</div>';
        echo '<div class="sia-toolbar-right">';
        echo '<button class="button" id="sia-bulk-trash" disabled>Trash Selected</button> ';
        echo '<button class="button" id="sia-bulk-dismiss" disabled>Dismiss Selected</button>';
        echo '</div>';
        echo '</div>';

        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage = 20;
        $total   = Database::countUnused();
        $images  = Database::getUnusedImages($perPage, $page);
        $pages   = (int) ceil($total / $perPage);

        if (empty($images)) {
            echo '<div class="sia-empty">';
            if ($lastScan) {
                echo '<p>No unused images found. Your media library is clean.</p>';
            } else {
                echo '<p>No scan results yet. Click "Run Scan Now" to start.</p>';
            }
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped sia-table">';
        echo '<thead><tr>';
        echo '<th class="check-column"><input type="checkbox" id="sia-select-all" /></th>';
        echo '<th class="sia-col-thumb">Thumbnail</th>';
        echo '<th>Filename</th>';
        echo '<th>Type</th>';
        echo '<th>Size</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($images as $img) {
            $thumb = wp_get_attachment_image((int) $img->attachment_id, [60, 60]);
            $title = esc_html($img->post_title ?: '(untitled)');
            $mime  = esc_html($img->post_mime_type ?? '');
            $size  = $img->file_size ? size_format($img->file_size) : '—';

            echo '<tr data-id="' . (int) $img->attachment_id . '">';
            echo '<td class="check-column"><input type="checkbox" class="sia-check" value="' . (int) $img->attachment_id . '" /></td>';
            echo "<td class='sia-col-thumb'>{$thumb}</td>";
            echo "<td><strong>{$title}</strong><br><small>ID: {$img->attachment_id}</small></td>";
            echo "<td>{$mime}</td>";
            echo "<td>{$size}</td>";
            echo '<td>';
            echo '<button class="button button-small sia-trash-one" data-id="' . (int) $img->attachment_id . '">Trash</button> ';
            echo '<button class="button button-small sia-dismiss-one" data-id="' . (int) $img->attachment_id . '">Dismiss</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $page,
                'total'   => $pages,
            ]);
            echo '</div></div>';
        }
    }

    private function renderLargeFilesTab(): void
    {
        $threshold = (int) get_option('sia_large_threshold', 512000);
        $page      = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage   = 20;
        $total     = Database::countLargeFiles();
        $files     = Database::getLargeFiles($perPage, $page);
        $pages     = (int) ceil($total / $perPage);

        echo '<div class="sia-toolbar"><div class="sia-toolbar-left">';
        echo '<p>Images larger than <strong>' . size_format($threshold) . '</strong>. Change threshold in <a href="' . admin_url('tools.php?page=sia&tab=settings') . '">Settings</a>.</p>';
        echo '</div></div>';

        if (empty($files)) {
            echo '<div class="sia-empty"><p>No large files found.</p></div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped sia-table">';
        echo '<thead><tr>';
        echo '<th class="sia-col-thumb">Thumbnail</th>';
        echo '<th>Filename</th>';
        echo '<th>Type</th>';
        echo '<th>Dimensions</th>';
        echo '<th>Size</th>';
        echo '<th>Edit</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($files as $f) {
            $thumb = wp_get_attachment_image((int) $f->attachment_id, [60, 60]);
            $title = esc_html($f->post_title ?: '(untitled)');
            $mime  = esc_html($f->post_mime_type ?? '');
            $dims  = $f->width && $f->height ? "{$f->width} &times; {$f->height}" : '—';
            $size  = size_format($f->file_size);
            $edit  = get_edit_post_link((int) $f->attachment_id);

            echo '<tr>';
            echo "<td class='sia-col-thumb'>{$thumb}</td>";
            echo "<td><strong>{$title}</strong><br><small>ID: {$f->attachment_id}</small></td>";
            echo "<td>{$mime}</td>";
            echo "<td>{$dims}</td>";
            echo "<td><strong>{$size}</strong></td>";
            echo '<td>' . ($edit ? '<a href="' . esc_url($edit) . '" class="button button-small">Edit</a>' : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $page,
                'total'   => $pages,
            ]);
            echo '</div></div>';
        }
    }

    private function renderDeletedTab(): void
    {
        $page      = max(1, (int) ($_GET['paged'] ?? 1));
        $perPage   = 20;
        $total     = Database::countDeletions();
        $deletions = Database::getDeletions($perPage, $page);
        $pages     = (int) ceil($total / $perPage);
        $retention = (int) get_option('sia_backup_retention_days', 90);

        echo '<div class="sia-toolbar"><div class="sia-toolbar-left">';
        echo '<p>Images deleted by SIA. Backups are kept for <strong>' . $retention . ' days</strong>. ';
        echo 'Click a post link to verify nothing is broken, then restore if needed.</p>';
        echo '</div></div>';

        if (empty($deletions)) {
            echo '<div class="sia-empty"><p>No deleted images to show.</p></div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped sia-table">';
        echo '<thead><tr>';
        echo '<th class="sia-col-thumb">Thumb</th>';
        echo '<th>Image</th>';
        echo '<th>Size</th>';
        echo '<th>Referenced In</th>';
        echo '<th>Deleted</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($deletions as $d) {
            $title = esc_html($d->post_title ?: '(untitled)');
            $size  = $d->file_size ? size_format($d->file_size) : '—';
            $date  = esc_html($d->deleted_at);
            $user  = $d->deleted_by ? get_userdata((int) $d->deleted_by) : null;
            $by    = $user ? esc_html($user->display_name) : 'System';

            $thumbHtml = '—';
            if ($d->backup_path) {
                $backupDir  = ABSPATH . $d->backup_path;
                $manifestF  = $backupDir . '/manifest.json';
                if (file_exists($manifestF)) {
                    $manifest = json_decode(file_get_contents($manifestF), true);
                    if (!empty($manifest['backed_up_files'][0])) {
                        $thumbFile = $backupDir . '/' . $manifest['backed_up_files'][0];
                        if (file_exists($thumbFile)) {
                            $uploadDir = wp_upload_dir();
                            $relPath = str_replace(ABSPATH, '', $thumbFile);
                            $thumbUrl = site_url('/' . $relPath);
                            $thumbHtml = '<img src="' . esc_url($thumbUrl) . '" style="max-width:60px;max-height:60px;border-radius:4px;" />';
                        }
                    }
                }
            }

            $postsHtml = '—';
            if ($d->used_in_posts) {
                $posts = json_decode($d->used_in_posts, true);
                if (!empty($posts)) {
                    $links = [];
                    foreach ($posts as $p) {
                        $pTitle = esc_html($p['title'] ?: "(ID {$p['post_id']})");
                        $pUrl   = $p['url'] ?? '';
                        $pType  = esc_html($p['usage_type'] ?? '');
                        if ($pUrl) {
                            $links[] = "<a href='" . esc_url($pUrl) . "' target='_blank'>{$pTitle}</a> <small>({$pType})</small>";
                        } else {
                            $links[] = "{$pTitle} <small>({$pType})</small>";
                        }
                    }
                    $postsHtml = implode('<br>', $links);
                } else {
                    $postsHtml = '<em>No references found</em>';
                }
            }

            echo '<tr data-id="' . (int) $d->attachment_id . '">';
            echo "<td class='sia-col-thumb'>{$thumbHtml}</td>";
            echo "<td><strong>{$title}</strong><br><small>ID: {$d->attachment_id}</small><br><small>" . esc_html($d->mime_type) . "</small></td>";
            echo "<td>{$size}</td>";
            echo "<td>{$postsHtml}</td>";
            echo "<td>{$date}<br><small>by {$by}</small></td>";
            echo '<td>';
            echo '<button class="button button-small sia-restore-one" data-id="' . (int) $d->attachment_id . '">Restore</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base'    => add_query_arg('paged', '%#%'),
                'format'  => '',
                'current' => $page,
                'total'   => $pages,
            ]);
            echo '</div></div>';
        }
    }

    private function renderSettingsTab(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('sia_settings')) {
            $threshold = max(0, (int) ($_POST['sia_large_threshold'] ?? 512000));
            $schedule  = sanitize_text_field($_POST['sia_scan_schedule'] ?? 'monthly');
            $batch     = max(10, min(500, (int) ($_POST['sia_batch_size'] ?? 100)));
            $retention = max(7, (int) ($_POST['sia_backup_retention_days'] ?? 90));

            update_option('sia_large_threshold', $threshold);
            update_option('sia_scan_schedule', $schedule);
            update_option('sia_batch_size', $batch);
            update_option('sia_backup_retention_days', $retention);

            BackgroundJob::unscheduleAll();
            BackgroundJob::scheduleRecurring();

            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $threshold = (int) get_option('sia_large_threshold', 512000);
        $schedule  = get_option('sia_scan_schedule', 'monthly');
        $batch     = (int) get_option('sia_batch_size', 100);
        $retention = (int) get_option('sia_backup_retention_days', 90);

        ?>
        <form method="post">
            <?php wp_nonce_field('sia_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="sia_large_threshold">Large file threshold</label></th>
                    <td>
                        <input type="number" id="sia_large_threshold" name="sia_large_threshold"
                               value="<?php echo esc_attr($threshold); ?>" min="0" step="1024" class="regular-text" />
                        <p class="description">In bytes. Default: 512000 (500 KB). Files above this size appear in the Large Files tab.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sia_scan_schedule">Auto-scan schedule</label></th>
                    <td>
                        <select id="sia_scan_schedule" name="sia_scan_schedule">
                            <option value="weekly" <?php selected($schedule, 'weekly'); ?>>Weekly</option>
                            <option value="monthly" <?php selected($schedule, 'monthly'); ?>>Monthly</option>
                            <option value="off" <?php selected($schedule, 'off'); ?>>Off (manual only)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="sia_batch_size">Batch size</label></th>
                    <td>
                        <input type="number" id="sia_batch_size" name="sia_batch_size"
                               value="<?php echo esc_attr($batch); ?>" min="10" max="500" class="small-text" />
                        <p class="description">Images processed per background batch. Lower = less server load. Range: 10–500.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="sia_backup_retention_days">Backup retention</label></th>
                    <td>
                        <input type="number" id="sia_backup_retention_days" name="sia_backup_retention_days"
                               value="<?php echo esc_attr($retention); ?>" min="7" max="365" class="small-text" /> days
                        <p class="description">How long to keep file backups of deleted images. The deletion log is kept forever. Default: 90 days.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button('Save Settings'); ?>
        </form>
        <?php
    }
}
