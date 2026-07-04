<?php
/**
 * Stores resumable backup and restore jobs.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Job_Store {
	const OPTION = 'backupflow_jobs';

	public function all() {
		$summaries = get_option( self::OPTION, array() );
		$summaries = is_array( $summaries ) ? $summaries : array();
		$jobs      = array();

		foreach ( $summaries as $job_id => $summary ) {
			$job = $this->get( $job_id );
			if ( $job ) {
				$jobs[ $job_id ] = $job;
			} elseif ( is_array( $summary ) ) {
				$jobs[ $job_id ] = $summary;
			}
		}

		return $jobs;
	}

	public function get( $job_id ) {
		$job_id = sanitize_key( $job_id );
		if ( '' === $job_id ) {
			return null;
		}

		$path = $this->path( $job_id );
		if ( file_exists( $path ) ) {
			$job = backupflow_read_json_file( $path, array() );
			return $job ? $this->normalize_job( $job ) : null;
		}

		$summaries = get_option( self::OPTION, array() );
		return isset( $summaries[ $job_id ] ) && is_array( $summaries[ $job_id ] ) ? $this->normalize_job( $summaries[ $job_id ] ) : null;
	}

	public function create( $type, $payload = array() ) {
		$job_id  = backupflow_generate_id( $type );
		$payload = is_array( $payload ) ? $payload : array();
		$payload = wp_parse_args(
			$payload,
			array(
				'format_version'  => 2,
				'current_step'    => 'queued',
				'step_offset'     => 0,
				'bytes_done'      => 0,
				'bytes_total'     => 0,
				'retry_count'     => 0,
				'checksum_status' => 'pending',
				'cancel_requested'=> false,
			)
		);

		$job = array(
			'id'         => $job_id,
			'type'       => sanitize_key( $type ),
			'status'     => 'queued',
			'progress'   => 0,
			'step'       => 'queued',
			'message'    => __( 'Queued', 'backupflow' ),
			'logs'       => array(),
			'payload'    => $payload,
			'result'     => array(),
			'error'      => '',
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		$this->save( $job );
		return $job;
	}

	public function save( $job ) {
		$job = $this->normalize_job( $job );
		$job['payload']['current_step'] = $job['step'];
		$job['updated_at'] = current_time( 'mysql' );

		backupflow_ensure_storage_dirs();
		backupflow_write_json_file( $this->path( $job['id'] ), $job );
		$this->save_summary( $job );

		return $job;
	}

	public function update( $job_id, $changes ) {
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return null;
		}

		foreach ( $changes as $key => $value ) {
			$job[ $key ] = $value;
		}

		return $this->save( $job );
	}

	public function log( $job_id, $message, $level = 'info' ) {
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return null;
		}

		$job['logs'][] = array(
			'time'    => current_time( 'H:i:s' ),
			'level'   => sanitize_key( $level ),
			'message' => wp_strip_all_tags( (string) $message ),
		);

		return $this->save( $job );
	}

	public function complete( $job_id, $result = array(), $message = '' ) {
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return null;
		}

		$job['status']   = 'complete';
		$job['progress'] = 100;
		$job['step']     = 'complete';
		$job['message']  = $message ? $message : __( 'Complete', 'backupflow' );
		$job['result']   = is_array( $result ) ? $result : array();
		$job['logs'][]   = array(
			'time'    => current_time( 'H:i:s' ),
			'level'   => 'success',
			'message' => $job['message'],
		);

		return $this->save( $job );
	}

	public function fail( $job_id, $message ) {
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return null;
		}

		$job['status']  = 'failed';
		$job['step']    = 'failed';
		$job['message'] = wp_strip_all_tags( (string) $message );
		$job['error']   = $job['message'];
		$job['logs'][]  = array(
			'time'    => current_time( 'H:i:s' ),
			'level'   => 'error',
			'message' => $job['message'],
		);

		return $this->save( $job );
	}

	public function cancel( $job_id ) {
		$job = $this->get( $job_id );
		if ( ! $job ) {
			return null;
		}

		if ( in_array( $job['status'], array( 'complete', 'failed', 'cancelled' ), true ) ) {
			return $job;
		}

		$job['status']                      = 'cancelled';
		$job['step']                        = 'cancelled';
		$job['message']                     = __( 'Process cancelled by the administrator.', 'backupflow' );
		$job['payload']['cancel_requested'] = true;
		$job['logs'][]                      = array(
			'time'    => current_time( 'H:i:s' ),
			'level'   => 'warning',
			'message' => $job['message'],
		);

		return $this->save( $job );
	}

	public function delete( $job_id ) {
		$job_id    = sanitize_key( $job_id );
		$summaries = get_option( self::OPTION, array() );
		if ( isset( $summaries[ $job_id ] ) ) {
			unset( $summaries[ $job_id ] );
			update_option( self::OPTION, $summaries, false );
		}

		$path = $this->path( $job_id );
		if ( file_exists( $path ) && backupflow_path_is_inside( $path, backupflow_jobs_dir() ) ) {
			wp_delete_file( $path );
		}
	}

	private function save_summary( $job ) {
		$summaries = get_option( self::OPTION, array() );
		$summaries = is_array( $summaries ) ? $summaries : array();

		$summaries[ sanitize_key( $job['id'] ) ] = array(
			'id'         => $job['id'],
			'type'       => $job['type'],
			'status'     => $job['status'],
			'progress'   => $job['progress'],
			'step'       => $job['step'],
			'message'    => $job['message'],
			'error'      => $job['error'],
			'result'     => isset( $job['result']['backup']['id'] ) ? array( 'backup' => array( 'id' => $job['result']['backup']['id'] ) ) : array(),
			'created_at' => $job['created_at'],
			'updated_at' => $job['updated_at'],
		);

		uasort(
			$summaries,
			static function ( $a, $b ) {
				return strcmp( isset( $b['updated_at'] ) ? $b['updated_at'] : '', isset( $a['updated_at'] ) ? $a['updated_at'] : '' );
			}
		);

		$summaries = array_slice( $summaries, 0, 30, true );
		update_option( self::OPTION, $summaries, false );
	}

	private function normalize_job( $job ) {
		$job = is_array( $job ) ? $job : array();
		$job = wp_parse_args(
			$job,
			array(
				'id'         => '',
				'type'       => '',
				'status'     => 'queued',
				'progress'   => 0,
				'step'       => 'queued',
				'message'    => '',
				'logs'       => array(),
				'payload'    => array(),
				'result'     => array(),
				'error'      => '',
				'created_at' => current_time( 'mysql' ),
				'updated_at' => current_time( 'mysql' ),
			)
		);

		$job['payload'] = wp_parse_args(
			is_array( $job['payload'] ) ? $job['payload'] : array(),
			array(
				'format_version'  => 2,
				'current_step'    => isset( $job['step'] ) ? $job['step'] : 'queued',
				'step_offset'     => 0,
				'bytes_done'      => 0,
				'bytes_total'     => 0,
				'retry_count'     => 0,
				'checksum_status' => 'pending',
				'cancel_requested'=> false,
			)
		);

		if ( isset( $job['logs'] ) && is_array( $job['logs'] ) && count( $job['logs'] ) > 180 ) {
			$job['logs'] = array_slice( $job['logs'], -180 );
		}

		return $job;
	}

	private function path( $job_id ) {
		return trailingslashit( backupflow_jobs_dir() ) . sanitize_key( $job_id ) . '.json';
	}
}
