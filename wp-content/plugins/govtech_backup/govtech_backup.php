<?php
/**
 * Plugin Name:       Govtech Backup
 * Plugin URI:        https://wons.bt/
 * Description:       Creates backups via webhook, verifies license, and uploads to S3 based on license details.
 * Version:           1.0.5
 * Author:            WONS
 * Author URI:        https://wons.bt/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       govtech-backup
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Define Constants
 */
define('GOVTECH_BACKUP_VERSION', '1.0.5');
define('GOVTECH_BACKUP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GOVTECH_BACKUP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GOVTECH_BACKUP_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once GOVTECH_BACKUP_PLUGIN_DIR . 'includes/class-govtech-backup.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_govtech_backup() {
    $plugin = Govtech_Backup::get_instance();
}
run_govtech_backup();
