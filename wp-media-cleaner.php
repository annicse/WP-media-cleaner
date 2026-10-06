<?php
/**
 * Plugin Name: WP Media Cleaner
 * Plugin URI: https://github.com/imrulhasan/wp-media-cleaner
 * Description: Find and clean up unused images, flag oversized media files.
 * Version: 1.0.0
 * Author: Imrul Hasan
 * Author URI: https://github.com/imrulhasan
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-media-cleaner
 * Requires PHP: 8.1
 * Network: true
 */

defined('ABSPATH') || exit;

define('WPMC_VERSION', '1.0.0');
define('WPMC_FILE', __FILE__);
define('WPMC_PATH', plugin_dir_path(__FILE__));
define('WPMC_URL', plugin_dir_url(__FILE__));

require_once WPMC_PATH . 'vendor/autoload.php';
require_once WPMC_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

use ImrulHasan\WPMC\Plugin;

Plugin::instance();
