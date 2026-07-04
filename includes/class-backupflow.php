<?php
/**
 * Main plugin container.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow {
	private static $instance = null;

	public $jobs;
	public $database;
	public $files;
	public $storage;
	public $backup_manager;
	public $restore_manager;
	public $migrator;
	public $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function activate() {
		backupflow_ensure_storage_dirs();

		if ( false === get_option( 'backupflow_settings', false ) ) {
			backupflow_update_settings( backupflow_default_settings() );
		}

		if ( false === get_option( 'backupflow_backups', false ) ) {
			add_option( 'backupflow_backups', array(), '', false );
		}

		set_transient( 'backupflow_activation_redirect', 1, 60 );
	}

	public static function deactivate() {
		delete_transient( 'backupflow_activation_redirect' );
	}

	private function __construct() {
		$this->jobs            = new BackupFlow_Job_Store();
		$this->database        = new BackupFlow_Database();
		$this->files           = new BackupFlow_File_System();
		$this->storage         = new BackupFlow_Storage();
		$this->backup_manager  = new BackupFlow_Backup_Manager( $this->jobs, $this->database, $this->files, $this->storage );
		$this->restore_manager = new BackupFlow_Restore_Manager( $this->jobs, $this->database, $this->files );
		$this->migrator        = new BackupFlow_Migrator();

		if ( is_admin() ) {
			$this->admin = new BackupFlow_Admin( $this->backup_manager, $this->restore_manager, $this->jobs, $this->storage, $this->migrator );
		}
	}
}
