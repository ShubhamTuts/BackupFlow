<?php
/**
 * FTP storage adapter.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Storage_FTP {
	private $settings;

	public function __construct( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings['password'] = isset( $settings['password'] ) ? backupflow_decrypt_secret( $settings['password'] ) : '';
		$this->settings = wp_parse_args(
			$settings,
			array(
				'host'     => '',
				'port'     => 21,
				'username' => '',
				'password' => '',
				'path'     => '/backupflow',
				'passive'  => true,
				'timeout'  => 30,
			)
		);
	}

	public function key() {
		return 'ftp';
	}

	public function label() {
		return __( 'FTP', 'backupflow' );
	}

	public function configured() {
		return function_exists( 'ftp_connect' ) && $this->settings['host'] && $this->settings['username'];
	}

	public function upload( $file_path, $remote_name = '' ) {
		$result = $this->upload_resumable( $file_path, $remote_name, array(), 300 );
		while ( empty( $result['done'] ) ) {
			$result = $this->upload_resumable( $file_path, $remote_name, $result['state'], 300 );
		}

		return $result['remote'];
	}

	public function upload_resumable( $file_path, $remote_name = '', $state = array(), $time_budget = 8 ) {
		if ( ! $this->configured() ) {
			throw new RuntimeException( esc_html__( 'FTP is not configured.', 'backupflow' ) );
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new RuntimeException( esc_html__( 'The backup archive is missing before FTP upload.', 'backupflow' ) );
		}

		$state       = is_array( $state ) ? $state : array();
		$size        = filesize( $file_path );
		$remote_name = $remote_name ? basename( $remote_name ) : basename( $file_path );
		$tmp_name    = isset( $state['tmp_name'] ) ? basename( $state['tmp_name'] ) : $remote_name . '.part';
		$remote_path = trailingslashit( $this->settings['path'] ) . $remote_name;
		$tmp_path    = trailingslashit( $this->settings['path'] ) . $tmp_name;
		$offset      = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
		$conn        = $this->connect();
		$this->ensure_remote_path( $conn, $this->settings['path'] );

		$remote_size = ftp_size( $conn, $tmp_path );
		if ( $remote_size > 0 ) {
			$offset = max( $offset, (int) $remote_size );
		}

		$stream = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $stream ) {
			ftp_close( $conn );
			throw new RuntimeException( esc_html__( 'Could not read the backup archive for FTP upload.', 'backupflow' ) );
		}

		fseek( $stream, $offset );
		$ret        = ftp_nb_fput( $conn, $tmp_path, $stream, FTP_BINARY, $offset );
		$started_at = microtime( true );
		while ( FTP_MOREDATA === $ret && microtime( true ) - $started_at < max( 2, (int) $time_budget ) ) {
			$ret = ftp_nb_continue( $conn );
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$remote_size = ftp_size( $conn, $tmp_path );

		if ( FTP_FAILED === $ret ) {
			ftp_close( $conn );
			throw new RuntimeException( esc_html__( 'Could not upload the backup to FTP.', 'backupflow' ) );
		}

		if ( $remote_size >= $size || FTP_FINISHED === $ret ) {
			@ftp_delete( $conn, $remote_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! ftp_rename( $conn, $tmp_path, $remote_path ) ) {
				ftp_close( $conn );
				throw new RuntimeException( esc_html__( 'Could not finalize the FTP backup upload.', 'backupflow' ) );
			}
			ftp_close( $conn );
			return array(
				'done'     => true,
				'progress' => 100,
				'state'    => array(
					'offset'   => $size,
					'tmp_name' => $tmp_name,
				),
				'remote'   => array(
					'storage' => 'ftp',
					'path'    => $remote_path,
					'name'    => $remote_name,
				),
			);
		}

		ftp_close( $conn );
		return array(
			'done'     => false,
			'progress' => $size > 0 ? (int) floor( ( max( 0, (int) $remote_size ) / $size ) * 100 ) : 0,
			'state'    => array(
				'offset'   => max( 0, (int) $remote_size ),
				'tmp_name' => $tmp_name,
			),
		);
	}

	public function list_backups() {
		if ( ! $this->configured() ) {
			return array();
		}

		$conn  = $this->connect();
		$path  = $this->settings['path'];
		$files = ftp_nlist( $conn, $path );
		ftp_close( $conn );

		$backups = array();
		foreach ( (array) $files as $file ) {
			if ( preg_match( '/\.zip$/i', $file ) ) {
				$backups[] = array(
					'name' => basename( $file ),
					'path' => $file,
				);
			}
		}

		return $backups;
	}

	public function download_to( $remote_path, $local_path ) {
		$conn = $this->connect();
		if ( ! ftp_get( $conn, $local_path, $remote_path, FTP_BINARY ) ) {
			ftp_close( $conn );
			throw new RuntimeException( esc_html__( 'Could not download the FTP backup.', 'backupflow' ) );
		}
		ftp_close( $conn );
		return $local_path;
	}

	private function connect() {
		$conn = ftp_connect( $this->settings['host'], (int) $this->settings['port'], (int) $this->settings['timeout'] );
		if ( ! $conn ) {
			throw new RuntimeException( esc_html__( 'Could not connect to the FTP server.', 'backupflow' ) );
		}

		if ( ! ftp_login( $conn, $this->settings['username'], $this->settings['password'] ) ) {
			ftp_close( $conn );
			throw new RuntimeException( esc_html__( 'FTP login failed.', 'backupflow' ) );
		}

		ftp_pasv( $conn, (bool) $this->settings['passive'] );
		return $conn;
	}

	private function ensure_remote_path( $conn, $path ) {
		$parts   = array_filter( explode( '/', trim( $path, '/' ) ) );
		$current = '';

		foreach ( $parts as $part ) {
			$current .= '/' . $part;
			@ftp_mkdir( $conn, $current ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
