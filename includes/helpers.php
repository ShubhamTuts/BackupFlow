<?php
/**
 * Shared helpers for BackupFlow.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function backupflow_default_settings() {
	return array(
		'retention_count'           => 8,
		'exclude_paths'             => "wp-content/cache\nwp-content/upgrade\nwp-content/backupflow\nwp-content/uploads/backupflow\nnode_modules\n.git",
		'skip_wp_config_restore'    => true,
		'safety_backup_before_restore' => true,
		'ftp'                       => array(
			'host'      => '',
			'port'      => 21,
			'username'  => '',
			'password'  => '',
			'path'      => '/backupflow',
			'passive'   => true,
			'timeout'   => 30,
		),
		'google_drive'              => array(
			'client_id'     => '',
			'client_secret' => '',
			'refresh_token' => '',
			'folder_id'     => '',
		),
	);
}

function backupflow_get_settings() {
	$settings = get_option( 'backupflow_settings', array() );
	return wp_parse_args( is_array( $settings ) ? $settings : array(), backupflow_default_settings() );
}

function backupflow_update_settings( $settings ) {
	update_option( 'backupflow_settings', wp_parse_args( $settings, backupflow_default_settings() ), false );
}

function backupflow_storage_root() {
	return trailingslashit( WP_CONTENT_DIR ) . 'backupflow';
}

function backupflow_backups_dir() {
	return trailingslashit( backupflow_storage_root() ) . 'backups';
}

function backupflow_tmp_dir() {
	return trailingslashit( backupflow_storage_root() ) . 'tmp';
}

function backupflow_logs_dir() {
	return trailingslashit( backupflow_storage_root() ) . 'logs';
}

function backupflow_jobs_dir() {
	return trailingslashit( backupflow_storage_root() ) . 'jobs';
}

function backupflow_imports_dir() {
	return trailingslashit( backupflow_tmp_dir() ) . 'imports';
}

function backupflow_secure_dir( $dir ) {
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}

	$index = trailingslashit( $dir ) . 'index.php';
	if ( ! file_exists( $index ) ) {
		backupflow_put_contents( $index, "<?php\n// Silence is golden.\n" );
	}

	$htaccess = trailingslashit( $dir ) . '.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		backupflow_put_contents( $htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
	}

	return true;
}

function backupflow_ensure_storage_dirs() {
	backupflow_secure_dir( backupflow_storage_root() );
	backupflow_secure_dir( backupflow_backups_dir() );
	backupflow_secure_dir( backupflow_tmp_dir() );
	backupflow_secure_dir( backupflow_logs_dir() );
	backupflow_secure_dir( backupflow_jobs_dir() );
	backupflow_secure_dir( backupflow_imports_dir() );
}

function backupflow_clean_path( $path ) {
	$path = str_replace( '\\', '/', (string) $path );
	$path = preg_replace( '#/+#', '/', $path );
	return rtrim( $path, '/' );
}

function backupflow_path_is_inside( $path, $root ) {
	$path = backupflow_clean_path( wp_normalize_path( $path ) );
	$root = backupflow_clean_path( wp_normalize_path( $root ) );
	return $path === $root || 0 === strpos( $path, trailingslashit( $root ) );
}

function backupflow_format_bytes( $bytes ) {
	$bytes = max( 0, (float) $bytes );
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$power = $bytes > 0 ? min( floor( log( $bytes, 1024 ) ), count( $units ) - 1 ) : 0;
	return round( $bytes / pow( 1024, $power ), 2 ) . ' ' . $units[ $power ];
}

function backupflow_size_to_bytes( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return 0;
	}

	$unit = strtolower( substr( $value, -1 ) );
	$num  = (float) $value;
	switch ( $unit ) {
		case 'g':
			$num *= 1024;
			// no break.
		case 'm':
			$num *= 1024;
			// no break.
		case 'k':
			$num *= 1024;
	}

	return (int) $num;
}

function backupflow_backup_catalog() {
	$catalog = get_option( 'backupflow_backups', array() );
	return is_array( $catalog ) ? $catalog : array();
}

function backupflow_save_backup_catalog( $catalog ) {
	update_option( 'backupflow_backups', is_array( $catalog ) ? $catalog : array(), false );
}

function backupflow_add_backup_record( $record ) {
	$catalog = backupflow_backup_catalog();
	$id      = isset( $record['id'] ) ? sanitize_key( $record['id'] ) : backupflow_generate_id( 'backup' );
	$record['id'] = $id;
	$catalog[ $id ] = $record;
	uasort(
		$catalog,
		static function ( $a, $b ) {
			return strcmp( isset( $b['created_at'] ) ? $b['created_at'] : '', isset( $a['created_at'] ) ? $a['created_at'] : '' );
		}
	);
	backupflow_save_backup_catalog( $catalog );
	return $id;
}

function backupflow_get_backup_record( $id ) {
	$catalog = backupflow_backup_catalog();
	$id      = sanitize_key( $id );
	return isset( $catalog[ $id ] ) && is_array( $catalog[ $id ] ) ? $catalog[ $id ] : null;
}

function backupflow_delete_backup_record( $id ) {
	$catalog = backupflow_backup_catalog();
	$id      = sanitize_key( $id );
	if ( isset( $catalog[ $id ] ) ) {
		unset( $catalog[ $id ] );
		backupflow_save_backup_catalog( $catalog );
	}
}

function backupflow_generate_id( $prefix ) {
	return sanitize_key( $prefix . '-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false ) );
}

function backupflow_json_success( $data = array() ) {
	wp_send_json_success( $data );
}

function backupflow_json_error( $message, $data = array(), $status_code = 400 ) {
	wp_send_json_error(
		array_merge(
			array(
				'message' => $message,
			),
			$data
		),
		$status_code
	);
}

function backupflow_user_can_manage() {
	return current_user_can( 'manage_options' );
}

function backupflow_verify_ajax() {
	if ( ! backupflow_user_can_manage() ) {
		backupflow_json_error( __( 'You do not have permission to manage backups.', 'backupflow' ), array(), 403 );
	}

	check_ajax_referer( 'backupflow_admin', 'nonce' );
}

function backupflow_wp_filesystem() {
	global $wp_filesystem;

	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	if ( ! $wp_filesystem ) {
		WP_Filesystem();
	}

	return $wp_filesystem;
}

function backupflow_put_contents( $path, $contents ) {
	$wp_filesystem = backupflow_wp_filesystem();
	return $wp_filesystem ? $wp_filesystem->put_contents( $path, $contents, FS_CHMOD_FILE ) : false;
}

function backupflow_copy_file( $source, $destination ) {
	$wp_filesystem = backupflow_wp_filesystem();
	if ( ! $wp_filesystem ) {
		return false;
	}

	wp_mkdir_p( dirname( $destination ) );
	return $wp_filesystem->copy( $source, $destination, true, FS_CHMOD_FILE );
}

function backupflow_delete_dir( $dir, $allowed_root ) {
	$dir          = wp_normalize_path( $dir );
	$allowed_root = wp_normalize_path( $allowed_root );

	if ( ! backupflow_path_is_inside( $dir, $allowed_root ) || ! is_dir( $dir ) ) {
		return false;
	}

	$wp_filesystem = backupflow_wp_filesystem();
	return $wp_filesystem ? $wp_filesystem->delete( $dir, true ) : false;
}

function backupflow_is_writable_path( $path ) {
	$wp_filesystem = backupflow_wp_filesystem();
	return $wp_filesystem ? $wp_filesystem->is_writable( $path ) : false;
}

function backupflow_post_key( $key, $default = '' ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify nonces before using admin POST values.
	return isset( $_POST[ $key ] ) ? sanitize_key( wp_unslash( $_POST[ $key ] ) ) : $default;
}

function backupflow_post_text( $key, $default = '' ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify nonces before using admin POST values.
	return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : $default;
}

function backupflow_post_textarea( $key, $default = '' ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify nonces before using admin POST values.
	return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : $default;
}

function backupflow_post_int( $key, $default = 0 ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify nonces before using admin POST values.
	return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default;
}

function backupflow_post_bool( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Callers verify nonces before using admin POST values.
	return ! empty( $_POST[ $key ] );
}

function backupflow_uploaded_file( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Upload arrays are normalized and sanitized below after nonce verification by caller.
	$file = isset( $_FILES[ $key ] ) && is_array( $_FILES[ $key ] ) ? wp_unslash( $_FILES[ $key ] ) : array();

	return array(
		'name'     => isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '',
		'type'     => isset( $file['type'] ) ? sanitize_mime_type( $file['type'] ) : '',
		'tmp_name' => isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '',
		'error'    => isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE,
		'size'     => isset( $file['size'] ) ? (int) $file['size'] : 0,
	);
}

function backupflow_import_state_path( $import_id ) {
	return trailingslashit( backupflow_imports_dir() ) . sanitize_key( $import_id ) . '.json';
}

function backupflow_read_json_file( $path, $default = array() ) {
	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		return $default;
	}

	$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$data = json_decode( (string) $json, true );
	return is_array( $data ) ? $data : $default;
}

function backupflow_write_json_file( $path, $data ) {
	wp_mkdir_p( dirname( $path ) );
	return false !== file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}

function backupflow_encrypt_secret( $value ) {
	$value = (string) $value;
	if ( '' === $value || ! function_exists( 'openssl_encrypt' ) || ! defined( 'AUTH_KEY' ) ) {
		return $value;
	}

	$key = hash( 'sha256', AUTH_KEY, true );
	$iv  = random_bytes( 16 );
	$raw = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	if ( false === $raw ) {
		return $value;
	}

	return 'bfenc:' . base64_encode( $iv . $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

function backupflow_decrypt_secret( $value ) {
	$value = (string) $value;
	if ( 0 !== strpos( $value, 'bfenc:' ) || ! function_exists( 'openssl_decrypt' ) || ! defined( 'AUTH_KEY' ) ) {
		return $value;
	}

	$decoded = base64_decode( substr( $value, 6 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	if ( false === $decoded || strlen( $decoded ) <= 16 ) {
		return '';
	}

	$key = hash( 'sha256', AUTH_KEY, true );
	$iv  = substr( $decoded, 0, 16 );
	$raw = substr( $decoded, 16 );
	$out = openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
	return false === $out ? '' : $out;
}

function backupflow_sanitize_bool( $value ) {
	return filter_var( $value, FILTER_VALIDATE_BOOLEAN );
}

function backupflow_rel_path( $path, $root ) {
	$path = backupflow_clean_path( wp_normalize_path( $path ) );
	$root = trailingslashit( backupflow_clean_path( wp_normalize_path( $root ) ) );
	return ltrim( substr( $path, strlen( $root ) ), '/' );
}

function backupflow_manifest_from_zip( $zip_path ) {
	$zip_path = wp_normalize_path( (string) $zip_path );
	if ( '' === $zip_path || ! class_exists( 'ZipArchive' ) || ! file_exists( $zip_path ) ) {
		return null;
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		return null;
	}

	$json = $zip->getFromName( 'backupflow-manifest.json' );
	$zip->close();

	if ( ! $json ) {
		return null;
	}

	$manifest = json_decode( $json, true );
	return is_array( $manifest ) ? $manifest : null;
}
