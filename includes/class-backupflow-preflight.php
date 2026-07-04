<?php
/**
 * Runtime readiness checks for large backup and restore jobs.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Preflight {
	public function check( $context = 'backup', $args = array() ) {
		$context = sanitize_key( $context );
		$args    = is_array( $args ) ? $args : array();
		$checks  = array();
		$expected_size = isset( $args['expected_size'] ) ? (int) $args['expected_size'] : 0;
		if ( 'backup' === $context && $expected_size <= 0 ) {
			$expected_size = $this->estimate_backup_size( isset( $args['backup_type'] ) ? sanitize_key( $args['backup_type'] ) : 'full' );
		}

		$checks[] = $this->check_single_site();
		$checks[] = $this->check_php_bits();
		$checks[] = $this->check_zip();
		$checks[] = $this->check_storage_writable();
		$checks[] = $this->check_disk_space( $expected_size );
		$checks[] = $this->check_database();

		if ( in_array( $context, array( 'import', 'restore' ), true ) ) {
			$checks[] = $this->check_tmp_writable();
		}

		$destination = isset( $args['destination'] ) ? sanitize_key( $args['destination'] ) : '';
		if ( 'ftp' === $destination ) {
			$checks[] = $this->check_storage_config( 'ftp' );
			$checks[] = $this->check_ftp();
		}
		if ( 'google_drive' === $destination ) {
			$checks[] = $this->check_storage_config( 'google_drive' );
			$checks[] = $this->check_http();
		}

		$blocked = false;
		foreach ( $checks as $check ) {
			if ( ! empty( $check['blocking'] ) && 'ready' !== $check['status'] ) {
				$blocked = true;
				break;
			}
		}

		return array(
			'context' => $context,
			'ready'   => ! $blocked,
			'checks'  => $checks,
			'message' => $blocked ? __( 'BackupFlow needs attention before this job can run.', 'backupflow' ) : __( 'BackupFlow is ready to run this job.', 'backupflow' ),
		);
	}

	private function check_single_site() {
		return array(
			'key'      => 'single_site',
			'label'    => __( 'WordPress mode', 'backupflow' ),
			'status'   => is_multisite() ? 'blocked' : 'ready',
			'blocking' => true,
			'message'  => is_multisite() ? __( 'Multisite migration is not supported in this release.', 'backupflow' ) : __( 'Single-site WordPress is supported.', 'backupflow' ),
		);
	}

	private function check_php_bits() {
		$ok = PHP_INT_SIZE >= 8;
		return array(
			'key'      => 'php_64_bit',
			'label'    => __( 'PHP architecture', 'backupflow' ),
			'status'   => $ok ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $ok ? __( '64-bit PHP is available for large archives.', 'backupflow' ) : __( '64-bit PHP is required for very large backups.', 'backupflow' ),
		);
	}

	private function check_zip() {
		$ok = class_exists( 'ZipArchive' );
		return array(
			'key'      => 'ziparchive',
			'label'    => __( 'ZIP support', 'backupflow' ),
			'status'   => $ok ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $ok ? __( 'The PHP ZipArchive extension is available.', 'backupflow' ) : __( 'The PHP ZipArchive extension is required to create and restore BackupFlow ZIP files.', 'backupflow' ),
		);
	}

	private function check_storage_writable() {
		backupflow_ensure_storage_dirs();
		$ok = is_dir( backupflow_backups_dir() ) && backupflow_is_writable_path( backupflow_backups_dir() );
		return array(
			'key'      => 'backup_storage',
			'label'    => __( 'Backup storage', 'backupflow' ),
			'status'   => $ok ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $ok ? __( 'Backup storage is writable.', 'backupflow' ) : __( 'BackupFlow cannot write to its backup storage folder.', 'backupflow' ),
		);
	}

	private function check_tmp_writable() {
		backupflow_ensure_storage_dirs();
		$ok = is_dir( backupflow_tmp_dir() ) && backupflow_is_writable_path( backupflow_tmp_dir() );
		return array(
			'key'      => 'temporary_storage',
			'label'    => __( 'Temporary storage', 'backupflow' ),
			'status'   => $ok ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $ok ? __( 'Temporary storage is writable.', 'backupflow' ) : __( 'BackupFlow cannot write temporary restore files.', 'backupflow' ),
		);
	}

	private function check_disk_space( $expected_size = 0 ) {
		$free = @disk_free_space( WP_CONTENT_DIR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $free ) {
			return array(
				'key'      => 'disk_space',
				'label'    => __( 'Disk space', 'backupflow' ),
				'status'   => 'warning',
				'blocking' => false,
				'message'  => __( 'BackupFlow could not read free disk space. Make sure the server has enough room for the backup and temporary files.', 'backupflow' ),
			);
		}

		$minimum = $expected_size > 0 ? $expected_size + ( 512 * 1024 * 1024 ) : 1024 * 1024 * 1024;
		return array(
			'key'      => 'disk_space',
			'label'    => __( 'Disk space', 'backupflow' ),
			'status'   => $free >= $minimum ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $free >= $minimum ? sprintf( __( '%s free on this server.', 'backupflow' ), backupflow_format_bytes( $free ) ) : sprintf( __( 'Only %1$s is free. BackupFlow needs at least %2$s for this job.', 'backupflow' ), backupflow_format_bytes( $free ), backupflow_format_bytes( $minimum ) ),
		);
	}

	private function check_database() {
		global $wpdb;

		$ok = (bool) $wpdb->get_var( 'SELECT 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array(
			'key'      => 'database',
			'label'    => __( 'Database access', 'backupflow' ),
			'status'   => $ok ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $ok ? __( 'Database connection is available.', 'backupflow' ) : __( 'BackupFlow could not confirm database access.', 'backupflow' ),
		);
	}

	private function check_ftp() {
		return array(
			'key'      => 'ftp_extension',
			'label'    => __( 'FTP support', 'backupflow' ),
			'status'   => function_exists( 'ftp_connect' ) ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => function_exists( 'ftp_connect' ) ? __( 'The PHP FTP extension is available.', 'backupflow' ) : __( 'The PHP FTP extension is required for FTP backups.', 'backupflow' ),
		);
	}

	private function check_storage_config( $destination ) {
		$settings = backupflow_get_settings();
		if ( 'ftp' === $destination ) {
			$ok = ! empty( $settings['ftp']['host'] ) && ! empty( $settings['ftp']['username'] ) && ! empty( $settings['ftp']['password'] );
			return array(
				'key'      => 'ftp_config',
				'label'    => __( 'FTP settings', 'backupflow' ),
				'status'   => $ok ? 'ready' : 'blocked',
				'blocking' => true,
				'message'  => $ok ? __( 'FTP settings are ready.', 'backupflow' ) : __( 'Configure FTP host, username, and password before using FTP storage.', 'backupflow' ),
			);
		}

		if ( 'google_drive' === $destination ) {
			$ok = ! empty( $settings['google_drive']['client_id'] ) && ! empty( $settings['google_drive']['client_secret'] ) && ! empty( $settings['google_drive']['refresh_token'] );
			return array(
				'key'      => 'google_drive_config',
				'label'    => __( 'Google Drive settings', 'backupflow' ),
				'status'   => $ok ? 'ready' : 'blocked',
				'blocking' => true,
				'message'  => $ok ? __( 'Google Drive is connected.', 'backupflow' ) : __( 'Connect Google Drive before using Google Drive storage.', 'backupflow' ),
			);
		}

		return array(
			'key'      => 'storage_config',
			'label'    => __( 'Storage settings', 'backupflow' ),
			'status'   => 'ready',
			'blocking' => false,
			'message'  => __( 'Storage is ready.', 'backupflow' ),
		);
	}

	private function check_http() {
		$response = wp_remote_head(
			'https://www.googleapis.com',
			array(
				'timeout' => 8,
			)
		);

		$ok = ! is_wp_error( $response );
		return array(
			'key'      => 'outbound_http',
			'label'    => __( 'Outbound connection', 'backupflow' ),
			'status'   => $ok ? 'ready' : 'blocked',
			'blocking' => true,
			'message'  => $ok ? __( 'The server can reach Google services.', 'backupflow' ) : __( 'The server cannot reach Google services right now.', 'backupflow' ),
		);
	}

	private function estimate_backup_size( $backup_type ) {
		$backup_type = in_array( $backup_type, array( 'full', 'files', 'database' ), true ) ? $backup_type : 'full';
		$total       = 0;

		if ( in_array( $backup_type, array( 'full', 'files' ), true ) ) {
			$total += $this->estimate_files_size();
		}

		if ( in_array( $backup_type, array( 'full', 'database' ), true ) ) {
			$total += $this->estimate_database_size();
		}

		return $total;
	}

	private function estimate_files_size() {
		$settings = backupflow_get_settings();
		$excludes = preg_split( '/\r\n|\r|\n/', (string) $settings['exclude_paths'] );
		$root     = trailingslashit( wp_normalize_path( ABSPATH ) );
		$total    = 0;
		$count    = 0;
		$started  = microtime( true );

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $file ) {
				if ( $file->isLink() || ! $file->isFile() ) {
					continue;
				}
				$rel = backupflow_rel_path( wp_normalize_path( $file->getPathname() ), $root );
				if ( $this->is_excluded( $rel, $excludes ) ) {
					continue;
				}
				$total += (int) $file->getSize();
				$count++;

				if ( $count >= 5000 || microtime( true ) - $started >= 1.5 ) {
					break;
				}
			}
		} catch ( Throwable $e ) {
			return 0;
		}

		return $total;
	}

	private function estimate_database_size() {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$sql  = "SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function is_excluded( $relative_path, $exclude_paths ) {
		$relative_path = backupflow_clean_path( $relative_path );
		$exclude_paths = is_array( $exclude_paths ) ? $exclude_paths : array();

		foreach ( $exclude_paths as $exclude ) {
			$exclude = trim( backupflow_clean_path( $exclude ) );
			if ( '' === $exclude ) {
				continue;
			}

			if ( $relative_path === $exclude || 0 === strpos( $relative_path, trailingslashit( $exclude ) ) ) {
				return true;
			}
		}

		return false;
	}
}
