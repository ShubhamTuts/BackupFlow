<?php
/**
 * Local storage adapter.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Storage_Local {
	public function key() {
		return 'local';
	}

	public function label() {
		return __( 'Website Server', 'backupflow' );
	}

	public function configured() {
		return is_dir( backupflow_backups_dir() ) && backupflow_is_writable_path( backupflow_backups_dir() );
	}

	public function upload( $file_path, $remote_name = '' ) {
		return array(
			'storage' => 'local',
			'path'    => $file_path,
			'name'    => $remote_name ? $remote_name : basename( $file_path ),
		);
	}

	public function upload_resumable( $file_path, $remote_name = '', $state = array(), $time_budget = 8 ) {
		return array(
			'done'     => true,
			'progress' => 100,
			'state'    => is_array( $state ) ? $state : array(),
			'remote'   => $this->upload( $file_path, $remote_name ),
		);
	}

	public function list_backups() {
		return array();
	}
}
