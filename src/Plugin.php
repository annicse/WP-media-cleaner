<?php

namespace StemAgency\Sia;

use StemAgency\Sia\Admin\AdminPage;
use StemAgency\Sia\Admin\AjaxHandlers;
use StemAgency\Sia\CLI\Commands;

final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        register_activation_hook(SIA_FILE, [$this, 'onActivation']);
        register_deactivation_hook(SIA_FILE, [$this, 'onDeactivation']);
        register_uninstall_hook(SIA_FILE, [self::class, 'onUninstall']);

        add_action('init', [$this, 'init']);
        add_action('wp_initialize_site', [$this, 'onNewSite'], 10, 1);

        if (is_admin()) {
            new AdminPage();
            new AjaxHandlers();
        }

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('sia', Commands::class);
        }
    }

    public function init(): void
    {
        BackgroundJob::register();
    }

    // ── Single-site activation ──────────────────────────────────────

    public static function activateSite(): void
    {
        Database::createTables();

        $defaults = [
            'sia_large_threshold'       => 512000,
            'sia_scan_schedule'         => 'monthly',
            'sia_batch_size'            => 100,
            'sia_last_scan'             => '',
            'sia_scan_status'           => 'idle',
            'sia_backup_retention_days' => 90,
        ];

        foreach ($defaults as $key => $value) {
            add_option($key, $value);
        }

        BackgroundJob::scheduleRecurring();
    }

    public static function deactivateSite(): void
    {
        BackgroundJob::unscheduleAll();
    }

    public static function uninstallSite(): void
    {
        Database::dropTables();

        $options = [
            'sia_large_threshold',
            'sia_scan_schedule',
            'sia_batch_size',
            'sia_last_scan',
            'sia_scan_status',
            'sia_backup_retention_days',
        ];

        foreach ($options as $option) {
            delete_option($option);
        }
    }

    // ── Multisite-aware hooks ───────────────────────────────────────

    public function onActivation(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            self::forEachSite([self::class, 'activateSite']);
        } else {
            self::activateSite();
        }
    }

    public function onDeactivation(bool $networkWide = false): void
    {
        if ($networkWide && is_multisite()) {
            self::forEachSite([self::class, 'deactivateSite']);
        } else {
            self::deactivateSite();
        }
    }

    public static function onUninstall(): void
    {
        if (is_multisite()) {
            self::forEachSite([self::class, 'uninstallSite']);
        } else {
            self::uninstallSite();
        }
    }

    /**
     * When a new site is created in the network, set up SIA tables and options.
     */
    public function onNewSite(\WP_Site $site): void
    {
        if (!is_plugin_active_for_network(plugin_basename(SIA_FILE))) {
            return;
        }

        switch_to_blog((int) $site->blog_id);
        self::activateSite();
        restore_current_blog();
    }

    /**
     * Run a callback on every site in the network.
     */
    private static function forEachSite(callable $callback): void
    {
        $siteIds = get_sites([
            'fields'     => 'ids',
            'number'     => 0,
            'network_id' => get_current_network_id(),
        ]);

        foreach ($siteIds as $siteId) {
            switch_to_blog((int) $siteId);
            call_user_func($callback);
            restore_current_blog();
        }
    }
}
