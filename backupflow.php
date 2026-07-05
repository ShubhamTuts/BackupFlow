<?php
/**
 * Plugin Name:       BackupFlow - Easy Backup, Restore & Migration
 * Plugin URI:        https://themefreex.com/backupflow/
 * Description:       Simple branded backups, one-click restore, and migration for WordPress with local, FTP, and Google Drive storage.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Author:            Codefreex
 * Author URI:        https://codefreex.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       backupflow
 * Domain Path:       /languages
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BACKUPFLOW_VERSION', '1.0.0' );
define( 'BACKUPFLOW_FILE', __FILE__ );
define( 'BACKUPFLOW_BASENAME', plugin_basename( __FILE__ ) );
define( 'BACKUPFLOW_DIR', plugin_dir_path( __FILE__ ) );
define( 'BACKUPFLOW_URL', plugin_dir_url( __FILE__ ) );

require_once BACKUPFLOW_DIR . 'includes/helpers.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-job-store.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-database.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-file-system.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-storage.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-storage-local.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-storage-ftp.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-storage-google-drive.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-migrator.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-preflight.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-backup-manager.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-restore-manager.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow-admin.php';
require_once BACKUPFLOW_DIR . 'includes/class-backupflow.php';

register_activation_hook( __FILE__, array( 'BackupFlow', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BackupFlow', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		BackupFlow::instance();
	}
);
