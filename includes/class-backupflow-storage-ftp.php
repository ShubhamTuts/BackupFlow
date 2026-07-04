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
		if ( ! $this->configured() ) {
			throw new RuntimeException( esc_html__( 'FTP is not configured.', 'backupflow' ) );
		}

		$conn = $this->connect();
		$this->ensure_remote_path( $conn, $this->settings['path'] );

		$remote_name = $remote_name ? basename( $remote_name ) : basename( $file_path );
		$remote_path = trailingslashit( $this->settings['path'] ) . $remote_name;

		if ( ! ftp_put( $conn, $remote_path, $file_path, FTP_BINARY ) ) {
			ftp_close( $conn );
			throw new RuntimeException( esc_html__( 'Could not upload the backup to FTP.', 'backupflow' ) );
		}

		ftp_close( $conn );
		return array(
			'storage' => 'ftp',
			'path'    => $remote_path,
			'name'    => $remote_name,
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
