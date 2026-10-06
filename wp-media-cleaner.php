<?php
/**
 * Plugin Name: SIA
 * Description: Find and clean up unused images, flag oversized media files.
 * Version: 1.0.0
 * Author: Stem Agency
 * Text Domain: sia
 * Requires PHP: 8.1
 * Network: true
 */

defined('ABSPATH') || exit;

define('SIA_VERSION', '1.0.0');
define('SIA_FILE', __FILE__);
define('SIA_PATH', plugin_dir_path(__FILE__));
define('SIA_URL', plugin_dir_url(__FILE__));

require_once SIA_PATH . 'vendor/autoload.php';
require_once SIA_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

use StemAgency\Sia\Plugin;

Plugin::instance();
