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
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	public function get( $job_id ) {
		$jobs   = $this->all();
		$job_id = sanitize_key( $job_id );
		return isset( $jobs[ $job_id ] ) && is_array( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
	}

	public function create( $type, $payload = array() ) {
		$job_id = backupflow_generate_id( $type );
		$job    = array(
			'id'         => $job_id,
			'type'       => sanitize_key( $type ),
			'status'     => 'queued',
			'progress'   => 0,
			'step'       => 'queued',
			'message'    => __( 'Queued', 'backupflow' ),
			'logs'       => array(),
			'payload'    => is_array( $payload ) ? $payload : array(),
			'result'     => array(),
			'error'      => '',
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		);

		$this->save( $job );
		return $job;
	}

	public function save( $job ) {
		$jobs = $this->all();
		$job['updated_at'] = current_time( 'mysql' );
		$jobs[ sanitize_key( $job['id'] ) ] = $this->trim_job( $job );

		uasort(
			$jobs,
			static function ( $a, $b ) {
				return strcmp( isset( $b['updated_at'] ) ? $b['updated_at'] : '', isset( $a['updated_at'] ) ? $a['updated_at'] : '' );
			}
		);

		$jobs = array_slice( $jobs, 0, 30, true );
		update_option( self::OPTION, $jobs, false );
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

		$job['status']  = 'cancelled';
		$job['step']    = 'cancelled';
		$job['message'] = __( 'Process cancelled by the administrator.', 'backupflow' );
		$job['logs'][]  = array(
			'time'    => current_time( 'H:i:s' ),
			'level'   => 'warning',
			'message' => $job['message'],
		);

		return $this->save( $job );
	}

	public function delete( $job_id ) {
		$jobs   = $this->all();
		$job_id = sanitize_key( $job_id );
		if ( isset( $jobs[ $job_id ] ) ) {
			unset( $jobs[ $job_id ] );
			update_option( self::OPTION, $jobs, false );
		}
	}

	private function trim_job( $job ) {
		if ( isset( $job['logs'] ) && is_array( $job['logs'] ) && count( $job['logs'] ) > 120 ) {
			$job['logs'] = array_slice( $job['logs'], -120 );
		}

		return $job;
	}
}
