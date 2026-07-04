<?php
/**
 * Backup import helper for migration workflows.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Migrator {
	public function import_local_backup( $source_path, $name ) {
		$source_path = wp_normalize_path( $source_path );
		$name        = sanitize_file_name( $name ? $name : 'backupflow-import.zip' );

		if ( ! file_exists( $source_path ) || ! is_readable( $source_path ) ) {
			throw new RuntimeException( esc_html__( 'The uploaded backup file is missing or unreadable.', 'backupflow' ) );
		}

		if ( ! preg_match( '/\.zip$/i', $name ) ) {
			throw new RuntimeException( esc_html__( 'Only BackupFlow ZIP backups can be imported.', 'backupflow' ) );
		}

		backupflow_ensure_storage_dirs();
		$destination = trailingslashit( backupflow_backups_dir() ) . sanitize_file_name( 'imported-' . gmdate( 'Ymd-His' ) . '-' . $name );

		if ( ! backupflow_copy_file( $source_path, $destination ) ) {
			throw new RuntimeException( esc_html__( 'Could not move the uploaded backup into BackupFlow storage.', 'backupflow' ) );
		}

		$manifest = backupflow_manifest_from_zip( $destination );
		if ( ! $manifest ) {
			wp_delete_file( $destination );
			throw new RuntimeException( esc_html__( 'This ZIP does not include a valid BackupFlow manifest.', 'backupflow' ) );
		}

		$backup_id = backupflow_generate_id( 'imported' );
		$size      = filesize( $destination );
		$record    = array(
			'id'          => $backup_id,
			'name'        => basename( $destination ),
			'type'        => isset( $manifest['backup_type'] ) ? sanitize_key( $manifest['backup_type'] ) : 'full',
			'destination' => 'imported',
			'created_at'  => current_time( 'mysql' ),
			'created_gmt' => gmdate( 'c' ),
			'size'        => $size,
			'size_human'  => backupflow_format_bytes( $size ),
			'path'        => $destination,
			'source_url'  => isset( $manifest['home_url'] ) ? esc_url_raw( $manifest['home_url'] ) : '',
			'site_url'    => isset( $manifest['site_url'] ) ? esc_url_raw( $manifest['site_url'] ) : '',
			'includes'    => isset( $manifest['includes'] ) && is_array( $manifest['includes'] ) ? $manifest['includes'] : array(),
			'manifest'    => $manifest,
			'status'      => 'imported',
		);

		backupflow_add_backup_record( $record );
		return $record;
	}
}
