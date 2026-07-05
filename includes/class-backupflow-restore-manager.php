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
	const ACTIVE_LOCK = 'backupflow_restore_active_job';

	private $jobs;
	private $database;
	private $files;

	public function __construct( BackupFlow_Job_Store $jobs, BackupFlow_Database $database, BackupFlow_File_System $files ) {
		$this->jobs     = $jobs;
		$this->database = $database;
		$this->files    = $files;
	}

	public function start( $backup_id, $restore_mode = 'full' ) {
		$this->clear_abandoned_restore_jobs();

		foreach ( $this->jobs->all() as $existing ) {
			if ( isset( $existing['type'], $existing['status'] ) && 'restore' === $existing['type'] && ! in_array( $existing['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
				throw new RuntimeException( esc_html__( 'Another restore is already running. Finish or cancel it before starting a new restore.', 'backupflow' ) );
			}
		}

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
			'database_parts'  => array(),
			'database_part_files' => array(),
			'database_part_index' => 0,
			'database_import_state' => array(),
			'rewrite_state'   => array(),
			'rewrite_pairs'   => array(),
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

		set_transient( self::ACTIVE_LOCK, $job['id'], 90 );

		try {
			switch ( $job['step'] ) {
				case 'queued':
					return $this->prepare( $job );
				case 'safety_backup':
					return $this->safety_backup( $job );
				case 'restore_files':
					return $this->restore_files( $job );
				case 'restore_database':
					return $this->extract_database_parts( $job );
				case 'import_database':
					return $this->import_database( $job );
				case 'rewrite_urls':
					return $this->rewrite_urls( $job );
				case 'verify_restore':
					return $this->verify_restore( $job );
				default:
					return $this->jobs->fail( $job['id'], __( 'Unknown restore step.', 'backupflow' ) );
			}
		} catch ( Throwable $e ) {
			return $this->jobs->fail( $job['id'], $e->getMessage() );
		}
	}

	private function clear_abandoned_restore_jobs() {
		$active_job_id = sanitize_key( (string) get_transient( self::ACTIVE_LOCK ) );

		foreach ( $this->jobs->all() as $existing ) {
			if ( empty( $existing['id'] ) || empty( $existing['type'] ) || 'restore' !== $existing['type'] ) {
				continue;
			}

			if ( in_array( isset( $existing['status'] ) ? $existing['status'] : '', array( 'complete', 'failed', 'cancelled' ), true ) ) {
				continue;
			}

			if ( $active_job_id && $active_job_id === $existing['id'] ) {
				continue;
			}

			if ( ! empty( $existing['payload']['tmp_dir'] ) ) {
				$this->cleanup_tmp( $existing['payload']['tmp_dir'] );
			}

			$this->jobs->cancel( $existing['id'] );
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
		$payload['database_parts']           = $needs_db ? $this->database_parts( $payload ) : array();
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
		$safety_zip = trailingslashit( backupflow_backups_dir() ) . sanitize_file_name( 'backupflow-safety-before-restore-' . gmdate( 'Ymd-His' ) . '.zip' );

		if ( empty( $payload['safety_export_state'] ) ) {
			$payload['safety_export_state'] = $this->database->prepare_export_state( trailingslashit( $payload['tmp_dir'] ) . 'safety' );
			$this->jobs->log( $job['id'], __( 'Creating a safety database backup before restore.', 'backupflow' ) );
		}

		$payload['safety_export_state'] = $this->database->export_chunk(
			$payload['safety_export_state'],
			500,
			10,
			function ( $index, $total, $table ) use ( $job ) {
				if ( 0 === $index % 5 || $index === $total || 1 === $index ) {
					$this->jobs->log(
						$job['id'],
						sprintf(
							/* translators: 1: table index, 2: total tables, 3: table name */
							__( 'Safety backup export %1$d/%2$d: %3$s', 'backupflow' ),
							$index,
							$total,
							$table
						)
					);
				}
			}
		);

		$state = $payload['safety_export_state'];
		if ( empty( $state['done'] ) ) {
			$total           = max( 1, count( isset( $state['tables'] ) ? (array) $state['tables'] : array() ) );
			$job['payload']  = $payload;
			$job['progress'] = min( 13, 6 + (int) floor( 7 * ( (int) $state['table_index'] / $total ) ) );
			$job['message']  = __( 'Creating safety database backup', 'backupflow' );
			return $this->jobs->save( $job );
		}

		$manifest = array(
			'plugin'         => 'BackupFlow',
			'version'        => BACKUPFLOW_VERSION,
			'format_version' => 2,
			'backup_id'      => $safety_id,
			'backup_type'    => 'database',
			'home_url'       => home_url(),
			'site_url'       => site_url(),
			'created_gmt'    => gmdate( 'c' ),
			'includes'       => array(
				'files'    => false,
				'database' => true,
			),
			'database_parts' => array(),
		);

		foreach ( (array) $state['parts'] as $part ) {
			if ( ! empty( $part['path'] ) && ! empty( $part['name'] ) && file_exists( $part['path'] ) ) {
				$this->files->add_file_to_zip( $safety_zip, $part['path'], $part['name'] );
				$manifest['database_parts'][] = array(
					'name'   => $part['name'],
					'size'   => isset( $part['size'] ) ? (int) $part['size'] : 0,
					'sha256' => ! empty( $part['path'] ) && file_exists( $part['path'] ) ? hash_file( 'sha256', $part['path'] ) : '',
				);
			}
		}
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
		$job['payload']  = $payload;
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
			300,
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

	private function extract_database_parts( $job ) {
		$payload = $job['payload'];
		$parts   = isset( $payload['database_parts'] ) ? (array) $payload['database_parts'] : $this->database_parts( $payload );

		if ( ! $parts ) {
			throw new RuntimeException( esc_html__( 'The backup does not contain database parts.', 'backupflow' ) );
		}

		$index = isset( $payload['database_extract_index'] ) ? (int) $payload['database_extract_index'] : 0;
		$part  = isset( $parts[ $index ] ) ? $parts[ $index ] : null;
		if ( ! $part ) {
			$job['step']     = 'import_database';
			$job['progress'] = 68;
			$job['message']  = __( 'Database files ready for import', 'backupflow' );
			$job['payload']  = $payload;
			return $this->jobs->save( $job );
		}

		$name        = isset( $part['name'] ) ? $part['name'] : '';
		$destination = trailingslashit( $payload['tmp_dir'] ) . 'database-import/' . basename( $name );
		$this->jobs->log(
			$job['id'],
			sprintf(
				/* translators: %s: database part name */
				__( 'Extracting database part %s.', 'backupflow' ),
				basename( $name )
			)
		);

		if ( ! $this->files->extract_zip_entry_to_file( $payload['zip_path'], $name, $destination ) ) {
			throw new RuntimeException( esc_html__( 'Could not extract a database part from the backup.', 'backupflow' ) );
		}

		$payload['database_part_files'][] = array(
			'name' => $name,
			'path' => $destination,
		);
		$payload['database_extract_index'] = $index + 1;
		$job['payload']                    = $payload;
		$job['progress']                   = min( 67, 58 + (int) floor( 9 * ( ( $index + 1 ) / max( 1, count( $parts ) ) ) ) );
		$job['message']                    = __( 'Extracting database files', 'backupflow' );

		return $this->jobs->save( $job );
	}

	private function import_database( $job ) {
		global $wpdb;

		$payload = $job['payload'];
		$files   = isset( $payload['database_part_files'] ) ? array_values( (array) $payload['database_part_files'] ) : array();
		$index   = isset( $payload['database_part_index'] ) ? (int) $payload['database_part_index'] : 0;

		if ( ! isset( $files[ $index ] ) ) {
			$job['step']     = 'rewrite_urls';
			$job['progress'] = 84;
			$job['message']  = __( 'Database import complete', 'backupflow' );
			$job['payload']  = $payload;
			$this->jobs->log( $job['id'], __( 'Database import complete. URL rewrite is next.', 'backupflow' ), 'success' );
			return $this->jobs->save( $job );
		}

		if ( empty( $payload['database_import_state'] ) || $payload['database_import_state']['file_path'] !== $files[ $index ]['path'] ) {
			$payload['database_import_state'] = array(
				'file_path'             => $files[ $index ]['path'],
				'offset'                => 0,
				'statement'             => '',
				'statements_done'       => 0,
				'source_prefix'         => isset( $payload['manifest']['table_prefix'] ) ? (string) $payload['manifest']['table_prefix'] : '',
				'destination_prefix'    => $wpdb->prefix,
				'foreign_key_fallbacks' => 0,
				'done'                  => false,
			);
			$this->jobs->log(
				$job['id'],
				sprintf(
					/* translators: 1: database part number, 2: total database parts */
					__( 'Importing database part %1$d/%2$d.', 'backupflow' ),
					$index + 1,
					count( $files )
				)
			);
		}

		$fallbacks_before = isset( $payload['database_import_state']['foreign_key_fallbacks'] ) ? (int) $payload['database_import_state']['foreign_key_fallbacks'] : 0;

		$payload['database_import_state'] = $this->database->import_file_chunk(
			$payload['database_import_state'],
			150,
			10,
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

		$fallbacks_after = isset( $payload['database_import_state']['foreign_key_fallbacks'] ) ? (int) $payload['database_import_state']['foreign_key_fallbacks'] : 0;
		if ( $fallbacks_after > $fallbacks_before ) {
			$this->jobs->log(
				$job['id'],
				__( 'One or more plugin tables were imported without rejected foreign key constraints so the migration could continue safely.', 'backupflow' ),
				'warning'
			);
		}

		if ( ! empty( $payload['database_import_state']['done'] ) ) {
			$payload['database_part_index']++;
			$payload['database_import_state'] = array();
		}

		$job['payload']  = $payload;
		$job['progress'] = min( 83, 68 + (int) floor( 15 * ( min( count( $files ), $payload['database_part_index'] ) / max( 1, count( $files ) ) ) ) );
		$job['message']  = __( 'Importing database tables', 'backupflow' );

		return $this->jobs->save( $job );
	}

	private function rewrite_urls( $job ) {
		$payload = $job['payload'];
		$source_url      = isset( $payload['manifest']['home_url'] ) ? $payload['manifest']['home_url'] : '';
		$destination_url = $payload['destination_url'];

		if ( $source_url && untrailingslashit( $source_url ) !== untrailingslashit( $destination_url ) ) {
			if ( empty( $payload['rewrite_pairs'] ) ) {
				$payload['rewrite_pairs'] = $this->database->url_pairs( $source_url, $destination_url );
				if ( ! empty( $payload['manifest']['site_url'] ) ) {
					$payload['rewrite_pairs'] = array_merge( $payload['rewrite_pairs'], $this->database->url_pairs( $payload['manifest']['site_url'], site_url() ) );
				}
				if ( ! empty( $payload['manifest']['content_url'] ) ) {
					$payload['rewrite_pairs'] = array_merge( $payload['rewrite_pairs'], $this->database->url_pairs( $payload['manifest']['content_url'], content_url() ) );
				}
				$payload['rewrite_state'] = $this->database->prepare_rewrite_state();
				$this->jobs->log(
					$job['id'],
					sprintf(
						/* translators: 1: old URL, 2: new URL */
						__( 'Rewriting URLs from %1$s to %2$s.', 'backupflow' ),
						$source_url,
						$destination_url
					)
				);
			}

			$payload['rewrite_state'] = $this->database->search_replace_url_chunk(
				$payload['rewrite_state'],
				$payload['rewrite_pairs'],
				300,
				10,
				function ( $table, $changed ) use ( $job ) {
					$this->jobs->log(
						$job['id'],
						sprintf(
							/* translators: 1: table name, 2: changed row count */
							__( 'Rewritten URLs in %1$s. Rows updated: %2$d.', 'backupflow' ),
							$table,
							$changed
						)
					);
				}
			);

			$total_tables     = max( 1, count( isset( $payload['rewrite_state']['tables'] ) ? (array) $payload['rewrite_state']['tables'] : array() ) );
			$job['progress']  = ! empty( $payload['rewrite_state']['done'] ) ? 98 : min( 97, 84 + (int) floor( 13 * ( (int) $payload['rewrite_state']['table_index'] / $total_tables ) ) );
			$job['message']   = __( 'Rewriting site URLs', 'backupflow' );
			$job['payload']   = $payload;

			if ( empty( $payload['rewrite_state']['done'] ) ) {
				return $this->jobs->save( $job );
			}
		} else {
			update_option( 'home', $destination_url );
			update_option( 'siteurl', $destination_url );
		}

		$job['step'] = 'verify_restore';
		$job['payload'] = $payload;
		return $this->jobs->save( $job );
	}

	private function verify_restore( $job ) {
		$payload = $job['payload'];
		$changed = isset( $payload['rewrite_state']['changed'] ) ? (int) $payload['rewrite_state']['changed'] : 0;

		update_option( 'home', $payload['destination_url'] );
		update_option( 'siteurl', $payload['destination_url'] );
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

	private function database_parts( $payload ) {
		if ( ! empty( $payload['manifest']['database_parts'] ) && is_array( $payload['manifest']['database_parts'] ) ) {
			return array_values( $payload['manifest']['database_parts'] );
		}

		if ( in_array( 'database.sql', $this->files->list_zip_entries( $payload['zip_path'] ), true ) ) {
			return array(
				array(
					'name' => 'database.sql',
				),
			);
		}

		$entries = $this->files->list_zip_entries( $payload['zip_path'], 'database/' );
		return array_map(
			static function ( $entry ) {
				return array(
					'name' => $entry,
				);
			},
			$entries
		);
	}

	private function cleanup_tmp( $tmp_dir ) {
		backupflow_delete_dir( $tmp_dir, backupflow_tmp_dir() );
	}
}
