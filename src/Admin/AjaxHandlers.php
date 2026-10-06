<?php

namespace StemAgency\Sia\Admin;

use StemAgency\Sia\BackgroundJob;
use StemAgency\Sia\Cleaner;

final class AjaxHandlers
{
    public function __construct()
    {
        add_action('wp_ajax_sia_start_scan', [$this, 'startScan']);
        add_action('wp_ajax_sia_trash', [$this, 'trashImages']);
        add_action('wp_ajax_sia_dismiss', [$this, 'dismissImages']);
        add_action('wp_ajax_sia_scan_status', [$this, 'scanStatus']);
        add_action('wp_ajax_sia_restore', [$this, 'restoreImages']);
    }

    public function startScan(): void
    {
        $this->verifyRequest();

        BackgroundJob::startScan();

        wp_send_json_success(['message' => 'Scan started.']);
    }

    public function trashImages(): void
    {
        $this->verifyRequest();

        $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
        $count = Cleaner::bulkTrash($ids);

        wp_send_json_success(['deleted' => $count]);
    }

    public function dismissImages(): void
    {
        $this->verifyRequest();

        $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
        $count = Cleaner::bulkDismiss($ids);

        wp_send_json_success(['dismissed' => $count]);
    }

    public function restoreImages(): void
    {
        $this->verifyRequest();

        $ids      = array_map('intval', (array) ($_POST['ids'] ?? []));
        $restored = 0;

        foreach ($ids as $id) {
            if (Cleaner::restoreAttachment($id)) {
                $restored++;
            }
        }

        wp_send_json_success(['restored' => $restored]);
    }

    public function scanStatus(): void
    {
        $this->verifyRequest('GET');

        wp_send_json_success([
            'status'   => get_option('sia_scan_status', 'idle'),
            'lastScan' => get_option('sia_last_scan', ''),
            'stale'    => BackgroundJob::isScanStale(),
        ]);
    }

    private function verifyRequest(string $method = 'POST'): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized', 403);
        }

        $nonce = ($method === 'GET')
            ? sanitize_text_field($_GET['nonce'] ?? '')
            : sanitize_text_field($_POST['nonce'] ?? '');

        if (!wp_verify_nonce($nonce, 'sia_admin')) {
            wp_send_json_error('Invalid nonce', 403);
        }
    }
}
