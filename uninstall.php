<?php
/**
 * BackupFlow uninstall cleanup.
 *
 * Backups are intentionally preserved in wp-content/backupflow to avoid data loss.
 *
 * @package BackupFlow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'backupflow_settings' );
delete_option( 'backupflow_jobs' );
delete_option( 'backupflow_backups' );
delete_transient( 'backupflow_activation_redirect' );
