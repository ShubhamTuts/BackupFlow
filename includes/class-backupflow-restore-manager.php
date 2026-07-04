<?php
/**
 * Restore and migration job orchestration.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Restore_Manager {
	private $jobs;
	private $database;
	private $files;

	public function __construct( BackupFlow_Job_Store $jobs, BackupFlow_Database $database, BackupFlow_File_System $files ) {
		$this->jobs     = $jobs;
		$this->database = $database;
		$this->files    = $files;
	}

	public function start( $backup_id, $restore_mode = 'full' ) {
		$record = backupflow_get_backup_record( $backup_id );
		if ( ! $record || empty( $record['path'] ) || ! file_exists( $record['path'] ) || ! backupflow_path_is_inside( $record['path'], backupflow_backups_dir() ) ) {
			throw new RuntimeException( esc_html__( 'Backup file not found.', 'backupflow' ) );
		}

		$manifest = backupflow_manifest_from_zip( $record['path'] );
		if ( ! $manifest ) {
			throw new RuntimeException( esc_html__( 'Backup manifest is missing or invalid.', 'backupflow' ) );
		}

		$restore_mode = sanitize_key( $restore_mode );
		if ( ! in_array( $restore_mode, array( 'full', 'files', 'database' ), true ) ) {
			$restore_mode = 'full';
		}

		$restore_id = backupflow_generate_id( 'restore' );
		$tmp_dir    = trailingslashit( backupflow_tmp_dir() ) . $restore_id;
		$payload    = array(
			'restore_id'      => $restore_id,
			'backup_id'       => sanitize_key( $backup_id ),
			'zip_path'        => $record['path'],
			'tmp_dir'         => $tmp_dir,
			'manifest'        => $manifest,
			'restore_mode'    => $restore_mode,
			'destination_url' => home_url(),
			'zip_index'       => 0,
			'file_total'      => 0,
			'file_restored'   => 0,
			'sql_path'        => trailingslashit( $tmp_dir ) . 'database.sql',
		);

		$job = $this->jobs->create( 'restore', $payload );
		$this->jobs->log( $job['id'], __( 'Restore job created.', 'backupflow' ) );

		return $job;
	}

	public function process( $job_id ) {
		$job = $this->jobs->get( $job_id );
		if ( ! $job || 'restore' !== $job['type'] ) {
			throw new RuntimeException( esc_html__( 'Restore job not found.', 'backupflow' ) );
		}

		if ( in_array( $job['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
			return $job;
		}

		try {
			switch ( $job['step'] ) {
				case 'queued':
					return $this->prepare( $job );
				case 'safety_backup':
					return $this->safety_backup( $job );
				case 'restore_files':
					return $this->restore_files( $job );
				case 'restore_database':
					return $this->restore_database_and_rewrite( $job );
				default:
					return $this->jobs->fail( $job['id'], __( 'Unknown restore step.', 'backupflow' ) );
			}
		} catch ( Throwable $e ) {
			return $this->jobs->fail( $job['id'], $e->getMessage() );
		}
	}

	private function prepare( $job ) {
		$payload  = $job['payload'];
		$settings = backupflow_get_settings();

		if ( ! wp_mkdir_p( $payload['tmp_dir'] ) ) {
			throw new RuntimeException( esc_html__( 'Could not create the temporary restore folder.', 'backupflow' ) );
		}

		$file_total = $this->files->count_zip_file_entries( $payload['zip_path'] );
		$payload['file_total'] = $file_total;
		$job['payload']        = $payload;
		$job['status']         = 'running';
		$job['progress']       = 5;
		$job['message']        = __( 'Preparing restore workspace', 'backupflow' );

		$wants_db    = in_array( $payload['restore_mode'], array( 'full', 'database' ), true );
		$wants_files = in_array( $payload['restore_mode'], array( 'full', 'files' ), true );
		$needs_db    = $wants_db && ! empty( $payload['manifest']['includes']['database'] );
		$needs_files = $wants_files && ! empty( $payload['manifest']['includes']['files'] ) && $file_total > 0;

		$payload['restore_include_database'] = $needs_db;
		$payload['restore_include_files']    = $needs_files;
		$job['payload']                      = $payload;

		if ( $needs_db && ! empty( $settings['safety_backup_before_restore'] ) ) {
			$job['step'] = 'safety_backup';
		} elseif ( $needs_files ) {
			$job['step'] = 'restore_files';
		} elseif ( $needs_db ) {
			$job['step'] = 'restore_database';
		} else {
			throw new RuntimeException( esc_html__( 'This backup does not contain restorable files or database tables.', 'backupflow' ) );
		}

		$this->jobs->log( $job['id'], __( 'Restore workspace ready. Backup manifest verified.', 'backupflow' ), 'success' );
		return $this->jobs->save( $job );
	}

	private function safety_backup( $job ) {
		$payload = $job['payload'];
		$safety_id = backupflow_generate_id( 'safety' );
		$safety_sql = trailingslashit( $payload['tmp_dir'] ) . 'safety-database.sql';
		$safety_zip = trailingslashit( backupflow_backups_dir() ) . sanitize_file_name( 'backupflow-safety-before-restore-' . gmdate( 'Ymd-His' ) . '.zip' );

		$this->jobs->log( $job['id'], __( 'Creating a safety database backup before restore.', 'backupflow' ) );
		$this->database->export_to_file( $safety_sql );

		$manifest = array(
			'plugin'      => 'BackupFlow',
			'version'     => BACKUPFLOW_VERSION,
			'backup_id'   => $safety_id,
			'backup_type' => 'database',
			'home_url'    => home_url(),
			'site_url'    => site_url(),
			'created_gmt' => gmdate( 'c' ),
			'includes'    => array(
				'files'    => false,
				'database' => true,
			),
		);

		$this->files->add_file_to_zip( $safety_zip, $safety_sql, 'database.sql' );
		$this->files->add_json_to_zip( $safety_zip, 'backupflow-manifest.json', $manifest );

		$size = file_exists( $safety_zip ) ? filesize( $safety_zip ) : 0;
		backupflow_add_backup_record(
			array(
				'id'          => $safety_id,
				'name'        => basename( $safety_zip ),
				'type'        => 'database',
				'destination' => 'local',
				'created_at'  => current_time( 'mysql' ),
				'created_gmt' => gmdate( 'c' ),
				'size'        => $size,
				'size_human'  => backupflow_format_bytes( $size ),
				'path'        => $safety_zip,
				'source_url'  => home_url(),
				'site_url'    => site_url(),
				'includes'    => array(
					'files'    => false,
					'database' => true,
				),
				'status'      => 'safety',
				'manifest'    => $manifest,
			)
		);

		$needs_files = ! empty( $payload['restore_include_files'] ) && ! empty( $payload['file_total'] );
		$job['progress'] = 14;
		$job['message']  = __( 'Safety backup created', 'backupflow' );
		$job['step']     = $needs_files ? 'restore_files' : 'restore_database';

		$this->jobs->log( $job['id'], __( 'Safety database backup created.', 'backupflow' ), 'success' );
		return $this->jobs->save( $job );
	}

	private function restore_files( $job ) {
		$payload  = $job['payload'];
		$settings = backupflow_get_settings();
		$total    = max( 1, (int) $payload['file_total'] );
		$result   = $this->files->extract_files_from_zip_chunk(
			$payload['zip_path'],
			ABSPATH,
			(int) $payload['zip_index'],
			180,
			! empty( $settings['skip_wp_config_restore'] )
		);

		$payload['zip_index']     = (int) $result['next'];
		$payload['file_restored'] = min( $total, (int) $payload['file_restored'] + (int) $result['restored'] );
		$job['payload']           = $payload;
		$job['progress']          = min( 58, 16 + (int) floor( 42 * ( $payload['file_restored'] / $total ) ) );
		$job['message']           = __( 'Restoring website files', 'backupflow' );

		$this->jobs->log(
			$job['id'],
			sprintf(
				/* translators: 1: restored files, 2: total files */
				__( 'Restored files %1$d/%2$d.', 'backupflow' ),
				$payload['file_restored'],
				$total
			)
		);

		if ( ! empty( $result['done'] ) ) {
			$job['step'] = ! empty( $payload['restore_include_database'] ) ? 'restore_database' : 'complete';
			if ( 'complete' === $job['step'] ) {
				$this->cleanup_tmp( $payload['tmp_dir'] );
				return $this->jobs->complete( $job['id'], array(), __( 'Your files were restored successfully.', 'backupflow' ) );
			}
			$this->jobs->log( $job['id'], __( 'File restore complete. Database restore is next.', 'backupflow' ), 'success' );
		}

		return $this->jobs->save( $job );
	}

	private function restore_database_and_rewrite( $job ) {
		$payload = $job['payload'];

		$this->jobs->log( $job['id'], __( 'Extracting database from the backup archive.', 'backupflow' ) );
		if ( ! $this->files->extract_zip_entry_to_file( $payload['zip_path'], 'database.sql', $payload['sql_path'] ) ) {
			throw new RuntimeException( esc_html__( 'The backup does not contain a database.sql file.', 'backupflow' ) );
		}

		$this->jobs->update(
			$job['id'],
			array(
				'progress' => 66,
				'message'  => __( 'Importing database tables', 'backupflow' ),
			)
		);
		$this->jobs->log( $job['id'], __( 'Importing database tables.', 'backupflow' ) );
		$this->database->import_file(
			$payload['sql_path'],
			function ( $count ) use ( $job ) {
				if ( 0 === $count % 100 ) {
					$this->jobs->log(
						$job['id'],
						sprintf(
							/* translators: %d: SQL statement count */
							__( 'Imported %d SQL statements.', 'backupflow' ),
							$count
						)
					);
				}
			}
		);

		$source_url      = isset( $payload['manifest']['home_url'] ) ? $payload['manifest']['home_url'] : '';
		$destination_url = $payload['destination_url'];
		$changed         = 0;

		if ( $source_url && untrailingslashit( $source_url ) !== untrailingslashit( $destination_url ) ) {
			$this->jobs->log(
				$job['id'],
				sprintf(
					/* translators: 1: old URL, 2: new URL */
					__( 'Rewriting URLs from %1$s to %2$s.', 'backupflow' ),
					$source_url,
					$destination_url
				)
			);
			$changed = $this->database->search_replace_url( $source_url, $destination_url );
		} else {
			update_option( 'home', $destination_url );
			update_option( 'siteurl', $destination_url );
		}

		flush_rewrite_rules();
		$this->cleanup_tmp( $payload['tmp_dir'] );

		return $this->jobs->complete(
			$job['id'],
			array(
				'rows_rewritten' => $changed,
			),
			__( 'Your site was restored successfully.', 'backupflow' )
		);
	}

	private function cleanup_tmp( $tmp_dir ) {
		backupflow_delete_dir( $tmp_dir, backupflow_tmp_dir() );
	}
}
