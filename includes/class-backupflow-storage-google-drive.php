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
		$result = $this->upload_resumable( $file_path, $remote_name, array(), 300 );
		while ( empty( $result['done'] ) ) {
			$result = $this->upload_resumable( $file_path, $remote_name, $result['state'], 300 );
		}

		return $result['remote'];
	}

	public function upload_resumable( $file_path, $remote_name = '', $state = array(), $time_budget = 8 ) {
		if ( ! $this->configured() ) {
			throw new RuntimeException( esc_html__( 'Google Drive is not configured.', 'backupflow' ) );
		}

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			throw new RuntimeException( esc_html__( 'The backup archive is missing before Google Drive upload.', 'backupflow' ) );
		}

		$state       = is_array( $state ) ? $state : array();
		$access_token = $this->access_token();
		$remote_name  = $remote_name ? basename( $remote_name ) : basename( $file_path );
		$file_size    = filesize( $file_path );
		$metadata     = array(
			'name' => $remote_name,
		);

		if ( $this->settings['folder_id'] ) {
			$metadata['parents'] = array( $this->settings['folder_id'] );
		}

		if ( empty( $state['session_uri'] ) ) {
			$response = wp_remote_post(
				'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable',
				array(
					'timeout' => 30,
					'headers' => array(
						'Authorization'           => 'Bearer ' . $access_token,
						'Content-Type'            => 'application/json; charset=UTF-8',
						'X-Upload-Content-Type'   => 'application/zip',
						'X-Upload-Content-Length' => (string) $file_size,
					),
					'body'    => wp_json_encode( $metadata ),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( esc_html( $response->get_error_message() ) );
			}

			$session_uri = wp_remote_retrieve_header( $response, 'location' );
			if ( ! $session_uri ) {
				throw new RuntimeException( esc_html__( 'Google Drive did not start a resumable upload session.', 'backupflow' ) );
			}

			$state['session_uri'] = $session_uri;
			$state['offset']      = 0;
		}

		$chunk_size = 8 * 1024 * 1024;
		$offset     = isset( $state['offset'] ) ? (int) $state['offset'] : 0;
		$length     = min( $chunk_size, max( 0, $file_size - $offset ) );
		$handle     = fopen( $file_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			throw new RuntimeException( esc_html__( 'Could not read the backup archive for Google Drive upload.', 'backupflow' ) );
		}

		fseek( $handle, $offset );
		$body = $length > 0 ? fread( $handle, $length ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$end = $offset + strlen( $body ) - 1;
		$response = wp_remote_request(
			$state['session_uri'],
			array(
				'method'  => 'PUT',
				'timeout' => max( 30, (int) $time_budget + 20 ),
				'headers' => array(
					'Content-Length' => (string) strlen( $body ),
					'Content-Range'  => 'bytes ' . $offset . '-' . $end . '/' . $file_size,
					'Content-Type'   => 'application/zip',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 308 === (int) $code ) {
			$range = wp_remote_retrieve_header( $response, 'range' );
			if ( $range && preg_match( '/bytes=0-(\d+)/', $range, $matches ) ) {
				$state['offset'] = (int) $matches[1] + 1;
			} else {
				$state['offset'] = $offset + strlen( $body );
			}

			return array(
				'done'     => false,
				'progress' => $file_size > 0 ? (int) floor( ( $state['offset'] / $file_size ) * 100 ) : 0,
				'state'    => $state,
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code > 299 || empty( $data['id'] ) ) {
			throw new RuntimeException( esc_html__( 'Google Drive upload failed.', 'backupflow' ) );
		}

		return array(
			'done'     => true,
			'progress' => 100,
			'state'    => array_merge(
				$state,
				array(
					'offset' => $file_size,
					'file_id'=> sanitize_text_field( $data['id'] ),
				)
			),
			'remote'   => array(
				'storage' => 'google_drive',
				'id'      => sanitize_text_field( $data['id'] ),
				'name'    => $remote_name,
			),
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
		$result = $this->download_resumable( $file_id, $local_path, array(), 300 );
		while ( empty( $result['done'] ) ) {
			$result = $this->download_resumable( $file_id, $local_path, $result['state'], 300 );
		}

		return $local_path;
	}

	public function download_resumable( $file_id, $local_path, $state = array(), $time_budget = 8 ) {
		$access_token = $this->access_token();
		$state        = is_array( $state ) ? $state : array();
		$chunk_size   = 8 * 1024 * 1024;
		$offset       = isset( $state['offset'] ) ? (int) $state['offset'] : ( file_exists( $local_path ) ? filesize( $local_path ) : 0 );
		$end          = $offset + $chunk_size - 1;

		$response = wp_remote_get(
			'https://www.googleapis.com/drive/v3/files/' . rawurlencode( $file_id ) . '?alt=media',
			array(
				'timeout' => max( 30, (int) $time_budget + 20 ),
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Range'         => 'bytes=' . $offset . '-' . $end,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $code, array( 200, 206 ), true ) ) {
			throw new RuntimeException( esc_html__( 'Google Drive download failed.', 'backupflow' ) );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body && 0 === $offset ) {
			throw new RuntimeException( esc_html__( 'Google Drive returned an empty backup file.', 'backupflow' ) );
		}

		wp_mkdir_p( dirname( $local_path ) );
		$write_flags = ( 200 === $code || 0 === $offset ) ? 0 : FILE_APPEND;
		file_put_contents( $local_path, $body, $write_flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$downloaded = filesize( $local_path );
		$total      = 0;
		$range      = wp_remote_retrieve_header( $response, 'content-range' );
		if ( $range && preg_match( '#/(\d+)$#', $range, $matches ) ) {
			$total = (int) $matches[1];
		} elseif ( 200 === $code ) {
			$total = $downloaded;
		}

		return array(
			'done'     => $total > 0 && $downloaded >= $total,
			'progress' => $total > 0 ? (int) floor( ( $downloaded / $total ) * 100 ) : 0,
			'state'    => array(
				'offset' => $downloaded,
				'total'  => $total,
			),
		);
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
