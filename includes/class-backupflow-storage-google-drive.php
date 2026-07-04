<?php
/**
 * Google Drive storage adapter.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Storage_Google_Drive {
	private $settings;

	public function __construct( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();
		$settings['client_secret'] = isset( $settings['client_secret'] ) ? backupflow_decrypt_secret( $settings['client_secret'] ) : '';
		$settings['refresh_token'] = isset( $settings['refresh_token'] ) ? backupflow_decrypt_secret( $settings['refresh_token'] ) : '';
		$this->settings = wp_parse_args(
			$settings,
			array(
				'client_id'     => '',
				'client_secret' => '',
				'refresh_token' => '',
				'folder_id'     => '',
			)
		);
	}

	public function key() {
		return 'google_drive';
	}

	public function label() {
		return __( 'Google Drive', 'backupflow' );
	}

	public function configured() {
		return $this->settings['client_id'] && $this->settings['client_secret'] && $this->settings['refresh_token'];
	}

	public function auth_url( $redirect_uri ) {
		$args = array(
			'client_id'     => $this->settings['client_id'],
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => 'https://www.googleapis.com/auth/drive.file',
			'access_type'   => 'offline',
			'prompt'        => 'consent',
		);

		return add_query_arg( $args, 'https://accounts.google.com/o/oauth2/v2/auth' );
	}

	public function exchange_code( $code, $redirect_uri ) {
		if ( ! $this->settings['client_id'] || ! $this->settings['client_secret'] ) {
			throw new RuntimeException( esc_html__( 'Google Drive client credentials are required first.', 'backupflow' ) );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $this->settings['client_id'],
					'client_secret' => $this->settings['client_secret'],
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['refresh_token'] ) ) {
			throw new RuntimeException( esc_html__( 'Google did not return a refresh token. Reconnect with consent prompt enabled.', 'backupflow' ) );
		}

		return sanitize_text_field( $body['refresh_token'] );
	}

	public function upload( $file_path, $remote_name = '' ) {
		if ( ! $this->configured() ) {
			throw new RuntimeException( esc_html__( 'Google Drive is not configured.', 'backupflow' ) );
		}

		$access_token = $this->access_token();
		$remote_name  = $remote_name ? basename( $remote_name ) : basename( $file_path );
		$metadata     = array(
			'name' => $remote_name,
		);

		if ( $this->settings['folder_id'] ) {
			$metadata['parents'] = array( $this->settings['folder_id'] );
		}

		$boundary = 'backupflow_' . wp_generate_password( 12, false, false );
		$body     = "--{$boundary}\r\n";
		$body    .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
		$body    .= wp_json_encode( $metadata ) . "\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Type: application/zip\r\n\r\n";
		$body    .= file_get_contents( $file_path ) . "\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body    .= "--{$boundary}--";

		$response = wp_remote_post(
			'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
			array(
				'timeout' => 300,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'multipart/related; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code > 299 || empty( $data['id'] ) ) {
			throw new RuntimeException( esc_html__( 'Google Drive upload failed.', 'backupflow' ) );
		}

		return array(
			'storage' => 'google_drive',
			'id'      => sanitize_text_field( $data['id'] ),
			'name'    => $remote_name,
		);
	}

	public function list_backups() {
		if ( ! $this->configured() ) {
			return array();
		}

		$access_token = $this->access_token();
		$query        = "mimeType='application/zip' and trashed=false";
		if ( $this->settings['folder_id'] ) {
			$query .= " and '" . str_replace( "'", "\\'", $this->settings['folder_id'] ) . "' in parents";
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'q'      => $query,
					'fields' => 'files(id,name,size,createdTime)',
				),
				'https://www.googleapis.com/drive/v3/files'
			),
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$out  = array();
		foreach ( isset( $body['files'] ) ? (array) $body['files'] : array() as $file ) {
			$out[] = array(
				'id'         => sanitize_text_field( $file['id'] ),
				'name'       => sanitize_file_name( $file['name'] ),
				'size'       => isset( $file['size'] ) ? (int) $file['size'] : 0,
				'created_at' => isset( $file['createdTime'] ) ? sanitize_text_field( $file['createdTime'] ) : '',
			);
		}

		return $out;
	}

	public function download_to( $file_id, $local_path ) {
		$access_token = $this->access_token();
		$response     = wp_remote_get(
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media',
			array(
				'timeout' => 300,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			throw new RuntimeException( esc_html__( 'Google Drive returned an empty backup file.', 'backupflow' ) );
		}

		file_put_contents( $local_path, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return $local_path;
	}

	private function access_token() {
		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'client_id'     => $this->settings['client_id'],
					'client_secret' => $this->settings['client_secret'],
					'refresh_token' => $this->settings['refresh_token'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			throw new RuntimeException( esc_html__( 'Could not refresh the Google Drive access token.', 'backupflow' ) );
		}

		return sanitize_text_field( $body['access_token'] );
	}
}
