<?php
/**
 * Backup job orchestration.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Backup_Manager {
	private $jobs;
	private $database;
	private $files;
	private $storage;

	public function __construct( BackupFlow_Job_Store $jobs, BackupFlow_Database $database, BackupFlow_File_System $files, BackupFlow_Storage $storage ) {
		$this->jobs     = $jobs;
		$this->database = $database;
		$this->files    = $files;
		$this->storage  = $storage;
	}

	public function start( $args ) {
		$type        = isset( $args['backup_type'] ) ? sanitize_key( $args['backup_type'] ) : 'full';
		$destination = isset( $args['destination'] ) ? sanitize_key( $args['destination'] ) : 'local';
		$type        = in_array( $type, array( 'full', 'files', 'database' ), true ) ? $type : 'full';
		$destination = in_array( $destination, array( 'local', 'ftp', 'google_drive' ), true ) ? $destination : 'local';

		$include_files = in_array( $type, array( 'full', 'files' ), true );
		$include_db    = in_array( $type, array( 'full', 'database' ), true );
		$site_host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$backup_id     = backupflow_generate_id( 'backup' );
		$file_name     = sanitize_file_name( 'backupflow-' . ( $site_host ? $site_host : 'site' ) . '-' . gmdate( 'Ymd-His' ) . '-' . $type . '.zip' );
		$tmp_dir       = trailingslashit( backupflow_tmp_dir() ) . $backup_id;
		$zip_path      = trailingslashit( backupflow_backups_dir() ) . $file_name;

		$payload = array(
			'backup_id'     => $backup_id,
			'backup_type'   => $type,
			'destination'   => $destination,
			'include_files' => $include_files,
			'include_db'    => $include_db,
			'tmp_dir'       => $tmp_dir,
			'zip_path'      => $zip_path,
			'file_name'     => $file_name,
			'file_list'     => trailingslashit( $tmp_dir ) . 'file-list.txt',
			'sql_path'      => trailingslashit( $tmp_dir ) . 'database.sql',
			'file_total'    => 0,
			'file_index'    => 0,
			'db_export_state' => array(),
			'file_scan_state' => array(),
			'remote_upload' => array(),
			'created_gmt'   => gmdate( 'c' ),
			'manifest'      => $this->manifest( $backup_id, $type, $destination, $include_files, $include_db ),
		);

		$job = $this->jobs->create( 'backup', $payload );
		$this->jobs->log( $job['id'], __( 'Backup job created.', 'backupflow' ) );

		return $job;
	}

	public function process( $job_id ) {
		$job = $this->jobs->get( $job_id );
		if ( ! $job || 'backup' !== $job['type'] ) {
			throw new RuntimeException( esc_html__( 'Backup job not found.', 'backupflow' ) );
		}

		if ( in_array( $job['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
			return $job;
		}

		try {
			switch ( $job['step'] ) {
				case 'queued':
					return $this->prepare( $job );
				case 'database':
					return $this->export_database( $job );
				case 'scan_files':
					return $this->scan_files( $job );
				case 'zip_files':
					return $this->zip_files( $job );
				case 'finalize':
					return $this->finalize( $job );
				case 'upload_remote':
					return $this->upload_remote( $job );
				default:
					return $this->jobs->fail( $job['id'], __( 'Unknown backup step.', 'backupflow' ) );
			}
		} catch ( Throwable $e ) {
			return $this->jobs->fail( $job['id'], $e->getMessage() );
		}
	}

	private function prepare( $job ) {
		$payload = $job['payload'];

		backupflow_ensure_storage_dirs();
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( esc_html__( 'The PHP ZipArchive extension is required on this server.', 'backupflow' ) );
		}

		if ( ! wp_mkdir_p( $payload['tmp_dir'] ) ) {
			throw new RuntimeException( esc_html__( 'Could not create the temporary backup folder.', 'backupflow' ) );
		}

		$job['status']   = 'running';
		$job['progress'] = 5;
		$job['message']  = __( 'Preparing backup workspace', 'backupflow' );
		$job['step']     = $payload['include_db'] ? 'database' : ( $payload['include_files'] ? 'scan_files' : 'finalize' );

		$this->jobs->log( $job['id'], __( 'Workspace ready. Checking selected backup parts.', 'backupflow' ) );
		return $this->jobs->save( $job );
	}

	private function export_database( $job ) {
		$payload = $job['payload'];

		if ( empty( $payload['db_export_state'] ) ) {
			$payload['db_export_state'] = $this->database->prepare_export_state( $payload['tmp_dir'] );
			$this->jobs->log( $job['id'], __( 'Exporting database tables in resumable parts.', 'backupflow' ) );
		}

		$payload['db_export_state'] = $this->database->export_chunk(
			$payload['db_export_state'],
			500,
			10,
			function ( $index, $total, $table ) use ( $job ) {
				if ( 0 === $index % 5 || $index === $total || 1 === $index ) {
					$this->jobs->log(
						$job['id'],
						sprintf(
							/* translators: 1: table index, 2: total tables, 3: table name */
							__( 'Database export %1$d/%2$d: %3$s', 'backupflow' ),
							$index,
							$total,
							$table
						)
					);
				}
			}
		);

		$state = $payload['db_export_state'];
		$total = max( 1, count( isset( $state['tables'] ) ? (array) $state['tables'] : array() ) );
		$done  = ! empty( $state['done'] );

		$payload['manifest']['database_parts'] = array_map(
			static function ( $part ) use ( $done ) {
				return array(
					'name'   => $part['name'],
					'size'   => isset( $part['size'] ) ? (int) $part['size'] : 0,
					'sha256' => $done && ! empty( $part['path'] ) && file_exists( $part['path'] ) ? hash_file( 'sha256', $part['path'] ) : '',
				);
			},
			isset( $state['parts'] ) ? (array) $state['parts'] : array()
		);
		$payload['manifest']['database_rows_exported'] = isset( $state['rows_exported'] ) ? (int) $state['rows_exported'] : 0;
		$payload['manifest']['tables']                 = isset( $state['tables'] ) ? (array) $state['tables'] : $this->database->get_tables();
		$job['payload']                                = $payload;
		$job['progress']                               = $done ? 30 : min( 29, 8 + (int) floor( 21 * ( (int) $state['table_index'] / $total ) ) );
		$job['message']                                = $done ? __( 'Database export complete', 'backupflow' ) : __( 'Exporting database tables', 'backupflow' );
		$job['step']                                   = $done ? ( $payload['include_files'] ? 'scan_files' : 'finalize' ) : 'database';

		if ( $done ) {
			$this->jobs->log( $job['id'], __( 'Database export added to the backup package.', 'backupflow' ), 'success' );
		}
		return $this->jobs->save( $job );
	}

	private function scan_files( $job ) {
		$payload  = $job['payload'];
		$settings = backupflow_get_settings();
		$excludes = preg_split( '/\r\n|\r|\n/', (string) $settings['exclude_paths'] );

		if ( empty( $payload['file_scan_state'] ) ) {
			$payload['file_scan_state'] = $this->files->prepare_file_scan_state( $payload['file_list'], ABSPATH, $excludes );
			$this->jobs->log( $job['id'], __( 'Scanning WordPress files and applying exclusions.', 'backupflow' ) );
		}

		$payload['file_scan_state'] = $this->files->scan_files_chunk(
			$payload['file_scan_state'],
			10,
			function ( $count, $excluded ) use ( $job ) {
				$this->jobs->log(
					$job['id'],
					sprintf(
						/* translators: 1: included files, 2: excluded files */
						__( 'Scanned %1$d files, skipped %2$d excluded files.', 'backupflow' ),
						$count,
						$excluded
					)
				);
			}
		);

		$result = $payload['file_scan_state'];
		$done   = ! empty( $result['done'] );
		$payload['file_total'] = (int) $result['total'];
		$payload['excluded']   = (int) $result['excluded'];
		$payload['manifest']['file_count'] = (int) $result['total'];
		$payload['manifest']['excluded_files'] = (int) $result['excluded'];
		$payload['manifest']['file_bytes_total'] = isset( $result['bytes_total'] ) ? (int) $result['bytes_total'] : 0;
		$job['payload']        = $payload;
		$job['progress']       = $done ? 36 : 33;
		$job['message']        = $done ? __( 'File scan complete', 'backupflow' ) : __( 'Scanning website files', 'backupflow' );
		$job['step']           = $done ? ( $payload['file_total'] > 0 ? 'zip_files' : 'finalize' ) : 'scan_files';

		if ( $done ) {
			$this->jobs->log(
				$job['id'],
				sprintf(
					/* translators: 1: total files */
					__( '%d files are ready to package.', 'backupflow' ),
					$payload['file_total']
				),
				'success'
			);
		}

		return $this->jobs->save( $job );
	}

	private function zip_files( $job ) {
		$payload = $job['payload'];
		$total   = max( 1, (int) $payload['file_total'] );
		$index   = (int) $payload['file_index'];
		$chunk   = 500;

		$result = $this->files->add_files_to_zip_chunk( $payload['zip_path'], $payload['file_list'], ABSPATH, $index, $chunk );

		$payload['file_index'] = min( $total, (int) $result['next'] );
		$job['payload']        = $payload;
		$job['progress']       = min( 84, 38 + (int) floor( 44 * ( $payload['file_index'] / $total ) ) );
		$job['message']        = __( 'Packaging website files', 'backupflow' );

		$this->jobs->log(
			$job['id'],
			sprintf(
				/* translators: 1: current file count, 2: total file count */
				__( 'Packaged files %1$d/%2$d.', 'backupflow' ),
				$payload['file_index'],
				$total
			)
		);

		if ( $payload['file_index'] >= $total ) {
			$job['step'] = 'finalize';
			$this->jobs->log( $job['id'], __( 'All selected files are in the archive.', 'backupflow' ), 'success' );
		}

		return $this->jobs->save( $job );
	}

	private function finalize( $job ) {
		$payload = $job['payload'];

		if ( ! empty( $payload['include_db'] ) && ! empty( $payload['db_export_state']['parts'] ) ) {
			foreach ( (array) $payload['db_export_state']['parts'] as $part ) {
				if ( ! empty( $part['path'] ) && ! empty( $part['name'] ) && file_exists( $part['path'] ) ) {
					$this->files->add_file_to_zip( $payload['zip_path'], $part['path'], $part['name'] );
				}
			}
		}

		$payload['manifest']['finished_gmt'] = gmdate( 'c' );
		$payload['manifest']['size_bytes']   = file_exists( $payload['zip_path'] ) ? filesize( $payload['zip_path'] ) : 0;
		$this->files->add_json_to_zip( $payload['zip_path'], 'backupflow-manifest.json', $payload['manifest'] );

		clearstatcache( true, $payload['zip_path'] );
		$size = file_exists( $payload['zip_path'] ) ? filesize( $payload['zip_path'] ) : 0;
		if ( ! $size ) {
			throw new RuntimeException( esc_html__( 'The backup archive was not created correctly.', 'backupflow' ) );
		}

		$this->jobs->log( $job['id'], __( 'Backup archive finalized.', 'backupflow' ) );

		$job['payload'] = $payload;
		if ( 'local' !== $payload['destination'] ) {
			$job['step']     = 'upload_remote';
			$job['progress'] = 90;
			$job['message']  = __( 'Uploading backup to remote storage', 'backupflow' );
			$this->jobs->log( $job['id'], __( 'Backup archive is ready. Remote upload is starting.', 'backupflow' ) );
			return $this->jobs->save( $job );
		}

		return $this->complete_backup( $job, $this->storage->adapter( 'local' )->upload( $payload['zip_path'], $payload['file_name'] ) );
	}

	private function upload_remote( $job ) {
		$payload = $job['payload'];
		$adapter = $this->storage->adapter( $payload['destination'] );

		if ( method_exists( $adapter, 'upload_resumable' ) ) {
			$result = $adapter->upload_resumable( $payload['zip_path'], $payload['file_name'], isset( $payload['remote_upload'] ) ? $payload['remote_upload'] : array(), 8 );
			$payload['remote_upload'] = isset( $result['state'] ) ? $result['state'] : array();
			$job['payload']           = $payload;
			$job['progress']          = isset( $result['progress'] ) ? max( 90, min( 99, (int) $result['progress'] ) ) : 94;
			$job['message']           = __( 'Uploading backup to remote storage', 'backupflow' );

			if ( empty( $result['done'] ) ) {
				return $this->jobs->save( $job );
			}

			$storage_result = isset( $result['remote'] ) ? $result['remote'] : array();
		} else {
			$storage_result = $adapter->upload( $payload['zip_path'], $payload['file_name'] );
		}

		if ( 'local' !== $payload['destination'] ) {
			$this->jobs->log(
				$job['id'],
				sprintf(
					/* translators: %s: storage destination */
					__( 'Uploaded backup to %s.', 'backupflow' ),
					$payload['destination']
				),
				'success'
			);
		}

		return $this->complete_backup( $job, $storage_result );
	}

	private function complete_backup( $job, $storage_result ) {
		$payload = $job['payload'];
		$size    = file_exists( $payload['zip_path'] ) ? filesize( $payload['zip_path'] ) : 0;

		$record = array(
			'id'            => $payload['backup_id'],
			'name'          => $payload['file_name'],
			'type'          => $payload['backup_type'],
			'destination'   => $payload['destination'],
			'created_at'    => current_time( 'mysql' ),
			'created_gmt'   => gmdate( 'c' ),
			'size'          => $size,
			'size_human'    => backupflow_format_bytes( $size ),
			'path'          => $payload['zip_path'],
			'remote'        => $storage_result,
			'source_url'    => home_url(),
			'site_url'      => site_url(),
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
			'includes'      => array(
				'files'    => (bool) $payload['include_files'],
				'database' => (bool) $payload['include_db'],
			),
			'manifest'      => $payload['manifest'],
			'status'        => 'ready',
		);

		backupflow_add_backup_record( $record );
		$this->apply_retention();
		$this->cleanup_tmp( $payload['tmp_dir'] );

		return $this->jobs->complete(
			$job['id'],
			array(
				'backup' => $record,
			),
			__( 'Backup created successfully.', 'backupflow' )
		);
	}

	private function manifest( $backup_id, $type, $destination, $include_files, $include_db ) {
		global $wpdb;

		return array(
			'plugin'        => 'BackupFlow',
			'version'       => BACKUPFLOW_VERSION,
			'format_version'=> 2,
			'backup_id'     => $backup_id,
			'backup_type'   => $type,
			'destination'   => $destination,
			'created_gmt'   => gmdate( 'c' ),
			'home_url'      => home_url(),
			'site_url'      => site_url(),
			'content_url'   => content_url(),
			'ABSPATH'       => wp_normalize_path( ABSPATH ),
			'table_prefix'  => $wpdb->prefix,
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
			'includes'      => array(
				'files'    => (bool) $include_files,
				'database' => (bool) $include_db,
			),
			'database_parts'=> array(),
			'file_count'    => 0,
		);
	}

	private function apply_retention() {
		$settings = backupflow_get_settings();
		$limit    = max( 1, (int) $settings['retention_count'] );
		$catalog  = backupflow_backup_catalog();

		if ( count( $catalog ) <= $limit ) {
			return;
		}

		$index = 0;
		foreach ( $catalog as $id => $record ) {
			$index++;
			if ( $index <= $limit ) {
				continue;
			}

			if ( ! empty( $record['path'] ) && file_exists( $record['path'] ) && backupflow_path_is_inside( $record['path'], backupflow_backups_dir() ) ) {
				wp_delete_file( $record['path'] );
			}
			unset( $catalog[ $id ] );
		}

		backupflow_save_backup_catalog( $catalog );
	}

	private function cleanup_tmp( $tmp_dir ) {
		backupflow_delete_dir( $tmp_dir, backupflow_tmp_dir() );
	}
}
