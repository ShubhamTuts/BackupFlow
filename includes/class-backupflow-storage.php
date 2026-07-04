<?php
/**
 * Storage adapter registry.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Storage {
	public function adapter( $key ) {
		$key      = sanitize_key( $key );
		$settings = backupflow_get_settings();

		switch ( $key ) {
			case 'ftp':
				return new BackupFlow_Storage_FTP( $settings['ftp'] );
			case 'google_drive':
				return new BackupFlow_Storage_Google_Drive( $settings['google_drive'] );
			case 'local':
			default:
				return new BackupFlow_Storage_Local();
		}
	}

	public function labels() {
		return array(
			'local'        => __( 'Website Server', 'backupflow' ),
			'ftp'          => __( 'FTP', 'backupflow' ),
			'google_drive' => __( 'Google Drive', 'backupflow' ),
		);
	}
}
