<?php
/**
 * Admin UI and actions.
 *
 * @package BackupFlow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BackupFlow_Admin {
	private $backup_manager;
	private $restore_manager;
	private $jobs;
	private $storage;
	private $migrator;

	public function __construct( BackupFlow_Backup_Manager $backup_manager, BackupFlow_Restore_Manager $restore_manager, BackupFlow_Job_Store $jobs, BackupFlow_Storage $storage, BackupFlow_Migrator $migrator ) {
		$this->backup_manager  = $backup_manager;
		$this->restore_manager = $restore_manager;
		$this->jobs            = $jobs;
		$this->storage         = $storage;
		$this->migrator        = $migrator;

		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_google_return' ) );
		add_filter( 'plugin_action_links_' . BACKUPFLOW_BASENAME, array( $this, 'plugin_action_links' ) );

		add_action( 'wp_ajax_backupflow_start_backup', array( $this, 'ajax_start_backup' ) );
		add_action( 'wp_ajax_backupflow_process_job', array( $this, 'ajax_process_job' ) );
		add_action( 'wp_ajax_backupflow_get_job', array( $this, 'ajax_get_job' ) );
		add_action( 'wp_ajax_backupflow_start_restore', array( $this, 'ajax_start_restore' ) );
		add_action( 'wp_ajax_backupflow_import_backup', array( $this, 'ajax_import_backup' ) );
		add_action( 'wp_ajax_backupflow_cancel_job', array( $this, 'ajax_cancel_job' ) );
		add_action( 'wp_ajax_backupflow_delete_backup', array( $this, 'ajax_delete_backup' ) );

		add_action( 'admin_post_backupflow_download_backup', array( $this, 'download_backup' ) );
		add_action( 'admin_post_backupflow_import_backup', array( $this, 'import_backup' ) );
		add_action( 'admin_post_backupflow_save_settings', array( $this, 'save_settings' ) );
	}

	public function admin_menu() {
		add_menu_page(
			__( 'BackupFlow', 'backupflow' ),
			__( 'BackupFlow', 'backupflow' ),
			'manage_options',
			'backupflow',
			array( $this, 'render_dashboard' ),
			'dashicons-cloud-upload',
			58
		);

		add_submenu_page( 'backupflow', __( 'Dashboard', 'backupflow' ), __( 'Dashboard', 'backupflow' ), 'manage_options', 'backupflow', array( $this, 'render_dashboard' ) );
		add_submenu_page( 'backupflow', __( 'Create Backup', 'backupflow' ), __( 'Create Backup', 'backupflow' ), 'manage_options', 'backupflow-create', array( $this, 'render_create' ) );
		add_submenu_page( 'backupflow', __( 'Backups', 'backupflow' ), __( 'Backups', 'backupflow' ), 'manage_options', 'backupflow-backups', array( $this, 'render_backups' ) );
		add_submenu_page( 'backupflow', __( 'Restore & Migrate', 'backupflow' ), __( 'Restore & Migrate', 'backupflow' ), 'manage_options', 'backupflow-restore', array( $this, 'render_restore' ) );
		add_submenu_page( 'backupflow', __( 'Storage', 'backupflow' ), __( 'Storage', 'backupflow' ), 'manage_options', 'backupflow-storage', array( $this, 'render_storage' ) );
		add_submenu_page( 'backupflow', __( 'Settings', 'backupflow' ), __( 'Settings', 'backupflow' ), 'manage_options', 'backupflow-settings', array( $this, 'render_settings' ) );
		add_submenu_page( 'backupflow', __( 'Add-ons', 'backupflow' ), __( 'Add-ons', 'backupflow' ), 'manage_options', 'backupflow-addons', array( $this, 'render_addons' ) );
		add_submenu_page( null, __( 'BackupFlow Wizard', 'backupflow' ), __( 'BackupFlow Wizard', 'backupflow' ), 'manage_options', 'backupflow-wizard', array( $this, 'render_wizard' ) );
	}

	public function enqueue( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'backupflow' ) ) {
			return;
		}

		wp_enqueue_style( 'backupflow-admin', BACKUPFLOW_URL . 'assets/css/admin.css', array(), BACKUPFLOW_VERSION );
		wp_enqueue_script( 'backupflow-admin', BACKUPFLOW_URL . 'assets/js/admin.js', array( 'jquery' ), BACKUPFLOW_VERSION, true );
		wp_localize_script(
			'backupflow-admin',
			'BackupFlowAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'backupflow_admin' ),
				'dashboardUrl' => admin_url( 'admin.php?page=backupflow' ),
				'strings' => array(
					'working'        => __( 'Working...', 'backupflow' ),
					'complete'       => __( 'Complete', 'backupflow' ),
					'failed'         => __( 'Failed', 'backupflow' ),
					'cancel'         => __( 'Cancel', 'backupflow' ),
					'close'          => __( 'Close', 'backupflow' ),
					'downloadBackup' => __( 'Download Backup', 'backupflow' ),
					'backDashboard'  => __( 'Back to Dashboard', 'backupflow' ),
					'cancelled'      => __( 'Cancelled', 'backupflow' ),
					'cancelConfirm'  => __( 'Are you sure you want to cancel the process?', 'backupflow' ),
					'chooseBackup'   => __( 'Choose a backup ZIP first.', 'backupflow' ),
					'uploading'      => __( 'Uploading backup', 'backupflow' ),
					'uploadComplete' => __( 'Backup uploaded. Starting restore...', 'backupflow' ),
					'restoreConfirm' => __( 'This restore can replace site files or database data. Continue?', 'backupflow' ),
					'deleteConfirm'  => __( 'Delete this backup permanently?', 'backupflow' ),
				),
			)
		);
	}

	public function activation_redirect() {
		if ( ! get_transient( 'backupflow_activation_redirect' ) || wp_doing_ajax() ) {
			return;
		}

		delete_transient( 'backupflow_activation_redirect' );

		if ( ! backupflow_user_can_manage() || isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=backupflow-wizard' ) );
		exit;
	}

	public function maybe_handle_google_return() {
		if ( ! backupflow_user_can_manage() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'backupflow-storage' !== $page || ! $code ) {
			return;
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $state, 'backupflow_google_oauth' ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'backupflow-storage', 'backupflow_notice' => 'google_state_failed' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		try {
			$settings = backupflow_get_settings();
			$adapter  = new BackupFlow_Storage_Google_Drive( $settings['google_drive'] );
			$token    = $adapter->exchange_code( $code, admin_url( 'admin.php?page=backupflow-storage' ) );
			$settings['google_drive']['refresh_token'] = backupflow_encrypt_secret( $token );
			backupflow_update_settings( $settings );
			wp_safe_redirect( add_query_arg( array( 'page' => 'backupflow-storage', 'backupflow_notice' => 'google_connected' ), admin_url( 'admin.php' ) ) );
		} catch ( Throwable $e ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'backupflow-storage', 'backupflow_notice' => 'google_failed' ), admin_url( 'admin.php' ) ) );
		}
		exit;
	}

	public function plugin_action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=backupflow-wizard' ) ) . '">' . esc_html__( 'Create Backup', 'backupflow' ) . '</a>';
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=backupflow-settings' ) ) . '">' . esc_html__( 'Settings', 'backupflow' ) . '</a>';
		return $links;
	}

	public function ajax_start_backup() {
		backupflow_verify_ajax();
		$job = $this->backup_manager->start(
			array(
				'backup_type' => backupflow_post_key( 'backup_type', 'full' ),
				'destination' => backupflow_post_key( 'destination', 'local' ),
			)
		);

		backupflow_json_success( array( 'job' => $job ) );
	}

	public function ajax_process_job() {
		backupflow_verify_ajax();
		$job_id = backupflow_post_key( 'job_id' );
		$job    = $this->jobs->get( $job_id );
		if ( ! $job ) {
			backupflow_json_error( __( 'Job not found.', 'backupflow' ), array(), 404 );
		}

		$job = 'restore' === $job['type'] ? $this->restore_manager->process( $job_id ) : $this->backup_manager->process( $job_id );
		$job = $this->prepare_job_response( $job );
		backupflow_json_success( array( 'job' => $job ) );
	}

	public function ajax_get_job() {
		backupflow_verify_ajax();
		$job_id = backupflow_post_key( 'job_id' );
		$job    = $this->jobs->get( $job_id );
		if ( ! $job ) {
			backupflow_json_error( __( 'Job not found.', 'backupflow' ), array(), 404 );
		}

		backupflow_json_success( array( 'job' => $job ) );
	}

	public function ajax_start_restore() {
		backupflow_verify_ajax();
		$backup_id     = backupflow_post_key( 'backup_id' );
		$restore_mode  = backupflow_post_key( 'restore_mode', 'full' );

		try {
			$job = $this->restore_manager->start( $backup_id, $restore_mode );
			backupflow_json_success( array( 'job' => $job ) );
		} catch ( Throwable $e ) {
			backupflow_json_error( $e->getMessage() );
		}
	}

	public function ajax_import_backup() {
		backupflow_verify_ajax();

		try {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$file   = backupflow_uploaded_file( 'backupflow_import' );
			$upload = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => array(
						'zip' => 'application/zip',
					),
				)
			);

			if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
				throw new RuntimeException( esc_html( isset( $upload['error'] ) ? $upload['error'] : __( 'Upload failed.', 'backupflow' ) ) );
			}

			$record = $this->migrator->import_local_backup( $upload['file'], $file['name'] );
			wp_delete_file( $upload['file'] );
			backupflow_json_success( array( 'backup' => $record ) );
		} catch ( Throwable $e ) {
			backupflow_json_error( $e->getMessage() );
		}
	}

	public function ajax_cancel_job() {
		backupflow_verify_ajax();
		$job_id = backupflow_post_key( 'job_id' );
		$job    = $this->jobs->cancel( $job_id );

		if ( ! $job ) {
			backupflow_json_error( __( 'Job not found.', 'backupflow' ), array(), 404 );
		}

		backupflow_json_success( array( 'job' => $job ) );
	}

	public function ajax_delete_backup() {
		backupflow_verify_ajax();
		$backup_id = backupflow_post_key( 'backup_id' );
		$record    = backupflow_get_backup_record( $backup_id );
		if ( $record && ! empty( $record['path'] ) && file_exists( $record['path'] ) && backupflow_path_is_inside( $record['path'], backupflow_backups_dir() ) ) {
			wp_delete_file( $record['path'] );
		}
		backupflow_delete_backup_record( $backup_id );
		backupflow_json_success( array( 'backup_id' => $backup_id ) );
	}

	public function download_backup() {
		if ( ! backupflow_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'backupflow' ) );
		}

		$backup_id = isset( $_GET['backup_id'] ) ? sanitize_key( wp_unslash( $_GET['backup_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce action is scoped to the requested backup ID and verified on the next line.
		check_admin_referer( 'backupflow_download_backup_' . $backup_id );

		$record = backupflow_get_backup_record( $backup_id );
		if ( ! $record || empty( $record['path'] ) || ! file_exists( $record['path'] ) || ! backupflow_path_is_inside( $record['path'], backupflow_backups_dir() ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'backupflow' ) );
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . basename( $record['path'] ) . '"' );
		header( 'Content-Length: ' . filesize( $record['path'] ) );
		readfile( $record['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public function import_backup() {
		if ( ! backupflow_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'backupflow' ) );
		}

		check_admin_referer( 'backupflow_import_backup' );

		try {
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$file   = backupflow_uploaded_file( 'backupflow_import' );
			$upload = wp_handle_upload(
				$file,
				array(
					'test_form' => false,
					'mimes'     => array(
						'zip' => 'application/zip',
					),
				)
			);

			if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
				throw new RuntimeException( esc_html( isset( $upload['error'] ) ? $upload['error'] : __( 'Upload failed.', 'backupflow' ) ) );
			}

			$this->migrator->import_local_backup( $upload['file'], $file['name'] );
			wp_delete_file( $upload['file'] );
			$notice = 'imported';
		} catch ( Throwable $e ) {
			$notice = 'import_failed';
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'backupflow-restore', 'backupflow_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function save_settings() {
		if ( ! backupflow_user_can_manage() ) {
			wp_die( esc_html__( 'Permission denied.', 'backupflow' ) );
		}

		check_admin_referer( 'backupflow_save_settings' );

		$settings = backupflow_get_settings();
		$section  = backupflow_post_key( 'backupflow_section', 'general' );

		if ( 'general' === $section ) {
			$settings['retention_count'] = max( 1, backupflow_post_int( 'retention_count', 8 ) );
			$settings['exclude_paths']   = backupflow_post_textarea( 'exclude_paths' );
			$settings['skip_wp_config_restore'] = backupflow_post_bool( 'skip_wp_config_restore' );
			$settings['safety_backup_before_restore'] = backupflow_post_bool( 'safety_backup_before_restore' );
		}

		if ( 'ftp' === $section ) {
			$settings['ftp']['host']     = backupflow_post_text( 'ftp_host' );
			$settings['ftp']['port']     = max( 1, backupflow_post_int( 'ftp_port', 21 ) );
			$settings['ftp']['username'] = backupflow_post_text( 'ftp_username' );
			$settings['ftp']['path']     = '/' . trim( backupflow_post_text( 'ftp_path', '/backupflow' ), '/' );
			$settings['ftp']['passive']  = backupflow_post_bool( 'ftp_passive' );
			if ( backupflow_post_text( 'ftp_password' ) ) {
				$settings['ftp']['password'] = backupflow_encrypt_secret( backupflow_post_text( 'ftp_password' ) );
			}
		}

		if ( 'google_drive' === $section ) {
			$settings['google_drive']['client_id'] = backupflow_post_text( 'google_client_id' );
			$settings['google_drive']['folder_id'] = backupflow_post_text( 'google_folder_id' );
			if ( backupflow_post_text( 'google_client_secret' ) ) {
				$settings['google_drive']['client_secret'] = backupflow_encrypt_secret( backupflow_post_text( 'google_client_secret' ) );
			}
		}

		backupflow_update_settings( $settings );
		wp_safe_redirect( add_query_arg( array( 'page' => $this->redirect_page_for_section( $section ), 'backupflow_notice' => 'settings_saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_dashboard() {
		$this->render_shell( __( 'Dashboard', 'backupflow' ), array( $this, 'view_dashboard' ) );
	}

	public function render_create() {
		$this->render_shell( __( 'Create Backup', 'backupflow' ), array( $this, 'view_create' ) );
	}

	public function render_wizard() {
		$this->render_shell( __( 'Welcome Wizard', 'backupflow' ), array( $this, 'view_wizard' ), 'wizard' );
	}

	public function render_backups() {
		$this->render_shell( __( 'Backups', 'backupflow' ), array( $this, 'view_backups' ) );
	}

	public function render_restore() {
		$this->render_shell( __( 'Restore & Migrate', 'backupflow' ), array( $this, 'view_restore' ) );
	}

	public function render_storage() {
		$this->render_shell( __( 'Storage', 'backupflow' ), array( $this, 'view_storage' ) );
	}

	public function render_settings() {
		$this->render_shell( __( 'Settings', 'backupflow' ), array( $this, 'view_settings' ) );
	}

	public function render_addons() {
		$this->render_shell( __( 'Premium Roadmap', 'backupflow' ), array( $this, 'view_addons' ) );
	}

	public function render_shell( $title, $callback, $variant = '' ) {
		$classes = 'wrap backupflow-wrap';
		if ( $variant ) {
			$classes .= ' backupflow-' . sanitize_html_class( $variant );
		}
		?>
		<div class="<?php echo esc_attr( $classes ); ?>">
			<?php $this->notices(); ?>
			<header class="backupflow-topbar">
				<div class="backupflow-brand">
					<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/BackupFlow.png' ); ?>" alt="" />
					<div>
						<span><?php esc_html_e( 'BackupFlow', 'backupflow' ); ?></span>
						<strong><?php echo esc_html( $title ); ?></strong>
					</div>
				</div>
				<nav class="backupflow-actions" aria-label="<?php esc_attr_e( 'BackupFlow actions', 'backupflow' ); ?>">
					<a class="backupflow-link" href="<?php echo esc_url( admin_url( 'admin.php?page=backupflow-backups' ) ); ?>"><?php esc_html_e( 'Backups', 'backupflow' ); ?></a>
					<a class="backupflow-button backupflow-button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=backupflow-restore' ) ); ?>"><?php esc_html_e( 'Import', 'backupflow' ); ?></a>
					<a class="backupflow-button" href="<?php echo esc_url( admin_url( 'admin.php?page=backupflow-create' ) ); ?>"><?php esc_html_e( 'Create Backup', 'backupflow' ); ?></a>
				</nav>
			</header>
			<div class="backupflow-layout">
				<main class="backupflow-main">
					<?php call_user_func( $callback ); ?>
				</main>
				<?php $this->sidebar(); ?>
			</div>
			<?php $this->job_modal(); ?>
		</div>
		<?php
	}

	public function view_dashboard() {
		$catalog = backupflow_backup_catalog();
		$latest  = $catalog ? reset( $catalog ) : null;
		?>
		<section class="backupflow-hero">
			<div>
				<p class="backupflow-eyebrow"><?php esc_html_e( 'Simple Backup, Restore, Migrate', 'backupflow' ); ?></p>
				<h2><?php esc_html_e( 'Ready to protect this WordPress site?', 'backupflow' ); ?></h2>
				<p><?php esc_html_e( 'Create a complete backup, restore in one click, or import a backup from another domain with URL rewriting handled during restore.', 'backupflow' ); ?></p>
			</div>
			<button class="backupflow-button backupflow-start-backup" data-backup-type="full" data-destination="local"><?php esc_html_e( 'Run Full Backup', 'backupflow' ); ?></button>
		</section>

		<section class="backupflow-metrics" aria-label="<?php esc_attr_e( 'Backup status', 'backupflow' ); ?>">
			<?php $this->metric( __( 'Latest backup', 'backupflow' ), $latest ? esc_html( $latest['created_at'] ) : __( 'Not created yet', 'backupflow' ), $latest ? backupflow_format_bytes( $latest['size'] ) : __( 'Start with the wizard', 'backupflow' ) ); ?>
			<?php $this->metric( __( 'Local storage', 'backupflow' ), backupflow_is_writable_path( backupflow_backups_dir() ) ? __( 'Writable', 'backupflow' ) : __( 'Needs attention', 'backupflow' ), backupflow_backups_dir() ); ?>
			<?php $this->metric( __( 'Migration target', 'backupflow' ), home_url(), __( 'URL rewrite uses this site URL', 'backupflow' ) ); ?>
			<?php $this->metric( __( 'Runtime', 'backupflow' ), 'WP ' . get_bloginfo( 'version' ), 'PHP ' . PHP_VERSION ); ?>
		</section>

		<section class="backupflow-panel">
			<div class="backupflow-section-head">
				<div>
					<h3><?php esc_html_e( 'Recent backups', 'backupflow' ); ?></h3>
					<p><?php esc_html_e( 'Download, restore, or delete ready backups from one place.', 'backupflow' ); ?></p>
				</div>
				<a class="backupflow-link" href="<?php echo esc_url( admin_url( 'admin.php?page=backupflow-backups' ) ); ?>"><?php esc_html_e( 'View all', 'backupflow' ); ?></a>
			</div>
			<?php $this->backup_table( array_slice( $catalog, 0, 5, true ) ); ?>
		</section>
		<?php
	}

	public function view_create() {
		?>
		<section class="backupflow-panel">
			<div class="backupflow-section-head">
				<div>
					<h3><?php esc_html_e( 'Create a backup', 'backupflow' ); ?></h3>
					<p><?php esc_html_e( 'Choose what to include and where BackupFlow should store the archive.', 'backupflow' ); ?></p>
				</div>
			</div>
			<?php $this->backup_builder( false ); ?>
		</section>
		<?php
	}

	public function view_wizard() {
		?>
		<section class="backupflow-wizard-intro">
			<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/BackupFlow.png' ); ?>" alt="" />
			<div>
				<h2><?php esc_html_e( 'Ready to set up your first backup?', 'backupflow' ); ?></h2>
				<p><?php esc_html_e( 'You are a few steps away from creating a portable backup of this site.', 'backupflow' ); ?></p>
			</div>
		</section>
		<?php $this->backup_builder( true ); ?>
		<?php
	}

	public function view_backups() {
		?>
		<section class="backupflow-panel">
			<div class="backupflow-section-head">
				<div>
					<h3><?php esc_html_e( 'Backup library', 'backupflow' ); ?></h3>
					<p><?php esc_html_e( 'Every local, imported, and cloud-uploaded backup remains restorable from this catalog while the archive exists locally.', 'backupflow' ); ?></p>
				</div>
				<button class="backupflow-button backupflow-start-backup" data-backup-type="full" data-destination="local"><?php esc_html_e( 'Run Full Backup', 'backupflow' ); ?></button>
			</div>
			<?php $this->backup_table( backupflow_backup_catalog() ); ?>
		</section>
		<?php
	}

	public function view_restore() {
		?>
		<section class="backupflow-import-band backupflow-import-workflow">
			<div>
				<p class="backupflow-eyebrow"><?php esc_html_e( 'Restore from file', 'backupflow' ); ?></p>
				<h3><?php esc_html_e( 'Upload a BackupFlow ZIP', 'backupflow' ); ?></h3>
				<p><?php esc_html_e( 'Choose a backup file, then select whether to restore the database, files, or the full site.', 'backupflow' ); ?></p>
			</div>
			<div class="backupflow-upload-box" data-import-uploader>
				<input id="backupflow-import-file" data-import-file type="file" accept=".zip" />
				<label for="backupflow-import-file">
					<span class="dashicons dashicons-upload" aria-hidden="true"></span>
					<strong><?php esc_html_e( 'Drop your backup ZIP here or choose a file', 'backupflow' ); ?></strong>
					<small><?php esc_html_e( 'BackupFlow will upload the file and show restore progress before making changes.', 'backupflow' ); ?></small>
				</label>
				<div class="backupflow-selected-file" data-selected-file hidden></div>
				<div class="backupflow-restore-choice" data-restore-choice hidden>
					<h4><?php esc_html_e( 'What do you want to restore today?', 'backupflow' ); ?></h4>
					<div>
						<button type="button" data-import-restore-mode="database"><img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/database.png' ); ?>" alt="" /><span><?php esc_html_e( 'Database only', 'backupflow' ); ?></span></button>
						<button type="button" data-import-restore-mode="files"><img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/local-server.png' ); ?>" alt="" /><span><?php esc_html_e( 'Files only', 'backupflow' ); ?></span></button>
						<button type="button" data-import-restore-mode="full"><img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/BackupFlow.png' ); ?>" alt="" /><span><?php esc_html_e( 'Full restore', 'backupflow' ); ?></span></button>
					</div>
				</div>
			</div>
		</section>
		<section class="backupflow-panel">
			<div class="backupflow-section-head">
				<div>
					<h3><?php esc_html_e( 'Restore points', 'backupflow' ); ?></h3>
					<p><?php esc_html_e( 'Start restore from any compatible backup. A live status window shows file extraction, database import, and URL rewrite progress.', 'backupflow' ); ?></p>
				</div>
			</div>
			<?php $this->backup_table( backupflow_backup_catalog(), true ); ?>
		</section>
		<?php
	}

	public function view_storage() {
		$settings = backupflow_get_settings();
		$google   = new BackupFlow_Storage_Google_Drive( $settings['google_drive'] );
		$google_url = '';
		if ( ! empty( $settings['google_drive']['client_id'] ) && ! empty( $settings['google_drive']['client_secret'] ) ) {
			$google_url = add_query_arg( 'state', wp_create_nonce( 'backupflow_google_oauth' ), $google->auth_url( admin_url( 'admin.php?page=backupflow-storage' ) ) );
		}
		?>
		<section class="backupflow-storage-grid">
			<?php $this->storage_tile( 'local-server.png', __( 'Website Server', 'backupflow' ), __( 'Included', 'backupflow' ), __( 'Store backups locally in protected wp-content storage.', 'backupflow' ) ); ?>
			<?php $this->storage_tile( 'ftp.png', __( 'FTP', 'backupflow' ), __( 'Included', 'backupflow' ), __( 'Upload completed backups to your own FTP location.', 'backupflow' ) ); ?>
			<?php $this->storage_tile( 'google-drive.png', __( 'Google Drive', 'backupflow' ), __( 'Included', 'backupflow' ), __( 'Connect a Google OAuth app and upload backups to Drive.', 'backupflow' ) ); ?>
		</section>

		<section class="backupflow-settings-grid">
			<form class="backupflow-panel" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( 'backupflow_save_settings' ); ?>
				<input type="hidden" name="action" value="backupflow_save_settings" />
				<input type="hidden" name="backupflow_section" value="ftp" />
				<h3><?php esc_html_e( 'FTP settings', 'backupflow' ); ?></h3>
				<div class="backupflow-fields">
					<label><?php esc_html_e( 'Host', 'backupflow' ); ?><input type="text" name="ftp_host" value="<?php echo esc_attr( $settings['ftp']['host'] ); ?>" /></label>
					<label><?php esc_html_e( 'Port', 'backupflow' ); ?><input type="number" name="ftp_port" value="<?php echo esc_attr( $settings['ftp']['port'] ); ?>" /></label>
					<label><?php esc_html_e( 'Username', 'backupflow' ); ?><input type="text" name="ftp_username" value="<?php echo esc_attr( $settings['ftp']['username'] ); ?>" /></label>
					<label><?php esc_html_e( 'Password', 'backupflow' ); ?><input type="password" name="ftp_password" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'backupflow' ); ?>" /></label>
					<label><?php esc_html_e( 'Remote folder', 'backupflow' ); ?><input type="text" name="ftp_path" value="<?php echo esc_attr( $settings['ftp']['path'] ); ?>" /></label>
					<label class="backupflow-checkbox"><input type="checkbox" name="ftp_passive" value="1" <?php checked( ! empty( $settings['ftp']['passive'] ) ); ?> /><?php esc_html_e( 'Use passive mode', 'backupflow' ); ?></label>
				</div>
				<button class="backupflow-button" type="submit"><?php esc_html_e( 'Save FTP', 'backupflow' ); ?></button>
			</form>

			<form class="backupflow-panel" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php wp_nonce_field( 'backupflow_save_settings' ); ?>
				<input type="hidden" name="action" value="backupflow_save_settings" />
				<input type="hidden" name="backupflow_section" value="google_drive" />
				<h3><?php esc_html_e( 'Google Drive settings', 'backupflow' ); ?></h3>
				<div class="backupflow-fields">
					<label><?php esc_html_e( 'Client ID', 'backupflow' ); ?><input type="text" name="google_client_id" value="<?php echo esc_attr( $settings['google_drive']['client_id'] ); ?>" /></label>
					<label><?php esc_html_e( 'Client Secret', 'backupflow' ); ?><input type="password" name="google_client_secret" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'backupflow' ); ?>" /></label>
					<label><?php esc_html_e( 'Folder ID', 'backupflow' ); ?><input type="text" name="google_folder_id" value="<?php echo esc_attr( $settings['google_drive']['folder_id'] ); ?>" /></label>
				</div>
				<div class="backupflow-form-actions">
					<button class="backupflow-button" type="submit"><?php esc_html_e( 'Save Google Settings', 'backupflow' ); ?></button>
					<?php if ( $google_url ) : ?>
						<a class="backupflow-button backupflow-button-secondary" href="<?php echo esc_url( $google_url ); ?>"><?php esc_html_e( 'Connect Google Drive', 'backupflow' ); ?></a>
					<?php endif; ?>
				</div>
			</form>
		</section>
		<?php $this->premium_storage_grid(); ?>
		<?php
	}

	public function view_settings() {
		$settings = backupflow_get_settings();
		?>
		<form class="backupflow-panel backupflow-settings-wide" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<?php wp_nonce_field( 'backupflow_save_settings' ); ?>
			<input type="hidden" name="action" value="backupflow_save_settings" />
			<input type="hidden" name="backupflow_section" value="general" />
			<h3><?php esc_html_e( 'Backup behavior', 'backupflow' ); ?></h3>
			<div class="backupflow-fields">
				<label><?php esc_html_e( 'Backups to keep', 'backupflow' ); ?><input type="number" min="1" name="retention_count" value="<?php echo esc_attr( $settings['retention_count'] ); ?>" /></label>
				<label><?php esc_html_e( 'Excluded paths', 'backupflow' ); ?><textarea name="exclude_paths" rows="8"><?php echo esc_textarea( $settings['exclude_paths'] ); ?></textarea></label>
				<label class="backupflow-checkbox"><input type="checkbox" name="skip_wp_config_restore" value="1" <?php checked( ! empty( $settings['skip_wp_config_restore'] ) ); ?> /><?php esc_html_e( 'Do not overwrite wp-config.php during restore', 'backupflow' ); ?></label>
				<label class="backupflow-checkbox"><input type="checkbox" name="safety_backup_before_restore" value="1" <?php checked( ! empty( $settings['safety_backup_before_restore'] ) ); ?> /><?php esc_html_e( 'Create a safety database backup before database restore', 'backupflow' ); ?></label>
			</div>
			<button class="backupflow-button" type="submit"><?php esc_html_e( 'Save Settings', 'backupflow' ); ?></button>
		</form>
		<?php
	}

	public function view_addons() {
		?>
		<section class="backupflow-panel">
			<div class="backupflow-section-head">
				<div>
					<h3><?php esc_html_e( 'Coming soon premium power', 'backupflow' ); ?></h3>
					<p><?php esc_html_e( 'BackupFlow is built so future add-ons can register storage, schedules, WP-CLI commands, cloning, and professional support without changing the free backup engine.', 'backupflow' ); ?></p>
				</div>
			</div>
			<?php $this->premium_storage_grid(); ?>
			<div class="backupflow-roadmap">
				<span><?php esc_html_e( 'Automatic backups', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Selective schedules', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Website cloning', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'SFTP / FTPS', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Dropbox', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Microsoft OneDrive', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Amazon S3', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Cloudflare R2', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'WP-CLI', 'backupflow' ); ?></span>
				<span><?php esc_html_e( 'Priority support', 'backupflow' ); ?></span>
			</div>
		</section>
		<?php
	}

	private function backup_builder( $wizard = false ) {
		?>
		<div class="backupflow-builder<?php echo $wizard ? ' is-wizard' : ''; ?>" data-current-step="1">
			<aside class="backupflow-stepper" aria-label="<?php esc_attr_e( 'Backup setup steps', 'backupflow' ); ?>">
				<button class="is-active" data-step="1"><span>1</span><strong><?php esc_html_e( 'What?', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Select files and database', 'backupflow' ); ?></small></button>
				<button data-step="2"><span>2</span><strong><?php esc_html_e( 'When?', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Run now or schedule later', 'backupflow' ); ?></small></button>
				<button data-step="3"><span>3</span><strong><?php esc_html_e( 'Where?', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Choose backup storage', 'backupflow' ); ?></small></button>
			</aside>
			<div class="backupflow-builder-panel">
				<section class="backupflow-builder-step is-active" data-step-panel="1">
					<h3><?php esc_html_e( 'What do you want to backup?', 'backupflow' ); ?></h3>
					<div class="backupflow-toggle-list">
						<label class="backupflow-toggle-row">
							<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/local-server.png' ); ?>" alt="" />
							<span><strong><?php esc_html_e( 'Files', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Include WordPress core, plugins, themes, uploads, and content files.', 'backupflow' ); ?></small></span>
							<input type="checkbox" data-backup-part="files" checked />
						</label>
						<label class="backupflow-toggle-row">
							<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/database.png' ); ?>" alt="" />
							<span><strong><?php esc_html_e( 'Database', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Include posts, pages, users, settings, plugin data, and serialized WordPress options.', 'backupflow' ); ?></small></span>
							<input type="checkbox" data-backup-part="database" checked />
						</label>
					</div>
					<div class="backupflow-builder-actions">
						<button class="backupflow-button backupflow-next-step" data-next-step="2" type="button"><?php esc_html_e( 'Save & Continue', 'backupflow' ); ?></button>
					</div>
				</section>
				<section class="backupflow-builder-step" data-step-panel="2">
					<h3><?php esc_html_e( 'When should BackupFlow run?', 'backupflow' ); ?></h3>
					<div class="backupflow-choice-list">
						<label class="backupflow-choice is-selected"><input type="radio" name="backupflow_when" value="now" checked /><strong><?php esc_html_e( 'Run now', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Create a manual backup immediately.', 'backupflow' ); ?></small></label>
						<label class="backupflow-choice is-locked"><input type="radio" disabled /><strong><?php esc_html_e( 'Automatic schedule', 'backupflow' ); ?></strong><small><?php esc_html_e( 'Daily, weekly, monthly, and custom intervals are coming soon.', 'backupflow' ); ?></small></label>
					</div>
					<div class="backupflow-builder-actions">
						<button class="backupflow-button backupflow-button-secondary backupflow-next-step" data-next-step="1" type="button"><?php esc_html_e( 'Back', 'backupflow' ); ?></button>
						<button class="backupflow-button backupflow-next-step" data-next-step="3" type="button"><?php esc_html_e( 'Save & Continue', 'backupflow' ); ?></button>
					</div>
				</section>
				<section class="backupflow-builder-step" data-step-panel="3">
					<h3><?php esc_html_e( 'Where to store your backup?', 'backupflow' ); ?></h3>
					<div class="backupflow-destination-grid">
						<button class="backupflow-destination is-selected" data-destination="local" type="button"><img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/local-server.png' ); ?>" alt="" /><span><?php esc_html_e( 'Website Server', 'backupflow' ); ?></span></button>
						<button class="backupflow-destination" data-destination="ftp" type="button"><img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/ftp.png' ); ?>" alt="" /><span><?php esc_html_e( 'FTP', 'backupflow' ); ?></span></button>
						<button class="backupflow-destination" data-destination="google_drive" type="button"><img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/google-drive.png' ); ?>" alt="" /><span><?php esc_html_e( 'Google Drive', 'backupflow' ); ?></span></button>
					</div>
					<div class="backupflow-locked-grid">
						<span><?php esc_html_e( 'Coming soon:', 'backupflow' ); ?></span>
						<em><?php esc_html_e( 'Dropbox', 'backupflow' ); ?></em>
						<em><?php esc_html_e( 'OneDrive', 'backupflow' ); ?></em>
						<em><?php esc_html_e( 'Amazon S3', 'backupflow' ); ?></em>
						<em><?php esc_html_e( 'Cloudflare R2', 'backupflow' ); ?></em>
					</div>
					<div class="backupflow-builder-actions">
						<button class="backupflow-button backupflow-button-secondary backupflow-next-step" data-next-step="2" type="button"><?php esc_html_e( 'Back', 'backupflow' ); ?></button>
						<button class="backupflow-button backupflow-run-builder" type="button"><?php esc_html_e( 'Create Backup', 'backupflow' ); ?></button>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	private function backup_table( $catalog, $restore_only = false ) {
		if ( ! $catalog ) {
			echo '<div class="backupflow-empty"><strong>' . esc_html__( 'No backups yet.', 'backupflow' ) . '</strong><p>' . esc_html__( 'Create your first backup to unlock restore and migration actions.', 'backupflow' ) . '</p></div>';
			return;
		}
		?>
		<table class="backupflow-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Backup', 'backupflow' ); ?></th>
					<th><?php esc_html_e( 'Type', 'backupflow' ); ?></th>
					<th><?php esc_html_e( 'Location', 'backupflow' ); ?></th>
					<th><?php esc_html_e( 'Size', 'backupflow' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'backupflow' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $catalog as $record ) : ?>
					<tr data-backup-row="<?php echo esc_attr( $record['id'] ); ?>">
						<td>
							<strong><?php echo esc_html( $record['name'] ); ?></strong>
							<small><?php echo esc_html( isset( $record['created_at'] ) ? $record['created_at'] : '' ); ?><?php echo ! empty( $record['source_url'] ) ? ' | ' . esc_html( $record['source_url'] ) : ''; ?></small>
						</td>
						<td><?php echo esc_html( ucfirst( isset( $record['type'] ) ? $record['type'] : 'full' ) ); ?></td>
						<td><?php echo esc_html( isset( $record['destination'] ) ? $record['destination'] : 'local' ); ?></td>
						<td><?php echo esc_html( isset( $record['size_human'] ) ? $record['size_human'] : backupflow_format_bytes( isset( $record['size'] ) ? $record['size'] : 0 ) ); ?></td>
						<td class="backupflow-table-actions">
							<button class="backupflow-icon-action backupflow-restore-backup" data-backup-id="<?php echo esc_attr( $record['id'] ); ?>" title="<?php esc_attr_e( 'Restore', 'backupflow' ); ?>" type="button"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Restore', 'backupflow' ); ?></span></button>
							<?php if ( ! $restore_only ) : ?>
								<a class="backupflow-icon-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'backupflow_download_backup', 'backup_id' => $record['id'] ), admin_url( 'admin-post.php' ) ), 'backupflow_download_backup_' . $record['id'] ) ); ?>" title="<?php esc_attr_e( 'Download', 'backupflow' ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Download', 'backupflow' ); ?></span></a>
								<button class="backupflow-icon-action backupflow-delete-backup" data-backup-id="<?php echo esc_attr( $record['id'] ); ?>" title="<?php esc_attr_e( 'Delete', 'backupflow' ); ?>" type="button"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text"><?php esc_html_e( 'Delete', 'backupflow' ); ?></span></button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function metric( $label, $value, $meta ) {
		?>
		<div class="backupflow-metric">
			<span><?php echo esc_html( $label ); ?></span>
			<strong><?php echo esc_html( $value ); ?></strong>
			<small><?php echo esc_html( $meta ); ?></small>
		</div>
		<?php
	}

	private function storage_tile( $icon, $title, $badge, $copy ) {
		?>
		<div class="backupflow-storage-tile">
			<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/' . $icon ); ?>" alt="" />
			<div>
				<strong><?php echo esc_html( $title ); ?></strong>
				<span><?php echo esc_html( $badge ); ?></span>
				<p><?php echo esc_html( $copy ); ?></p>
			</div>
		</div>
		<?php
	}

	private function premium_storage_grid() {
		$items = array(
			array( 'dropbox.png', __( 'Dropbox', 'backupflow' ) ),
			array( 'microsoft-onedrive.png', __( 'Microsoft OneDrive', 'backupflow' ) ),
			array( 'amazons3.png', __( 'Amazon S3', 'backupflow' ) ),
			array( 'cloudflare-r2.png', __( 'Cloudflare R2', 'backupflow' ) ),
			array( 'ftp.png', __( 'SFTP / FTPS', 'backupflow' ) ),
			array( 'local-server.png', __( 'WP-CLI', 'backupflow' ) ),
		);
		?>
		<section class="backupflow-premium-grid" aria-label="<?php esc_attr_e( 'Premium storage roadmap', 'backupflow' ); ?>">
			<?php foreach ( $items as $item ) : ?>
				<div>
					<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/' . $item[0] ); ?>" alt="" />
					<strong><?php echo esc_html( $item[1] ); ?></strong>
					<span><?php esc_html_e( 'Coming soon', 'backupflow' ); ?></span>
				</div>
			<?php endforeach; ?>
		</section>
		<?php
	}

	private function sidebar() {
		?>
		<aside class="backupflow-sidebar">
			<section class="backupflow-side-card backupflow-side-brand">
				<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/BackupFlow.png' ); ?>" alt="" />
				<h3><?php esc_html_e( 'BackupFlow Free', 'backupflow' ); ?></h3>
				<p><?php esc_html_e( 'Local backups, FTP, Google Drive, one-click restore, migration URL rewrite, database-only and files-only backups.', 'backupflow' ); ?></p>
			</section>
			<section class="backupflow-side-card">
				<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/pageforge.png' ); ?>" alt="" />
				<h3><?php esc_html_e( 'PageForge', 'backupflow' ); ?></h3>
				<p><?php esc_html_e( 'Programmatic SEO pages and growth content for WordPress.', 'backupflow' ); ?></p>
				<a href="https://wordpress.org/plugins/pageforge/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Plugin', 'backupflow' ); ?></a>
			</section>
			<section class="backupflow-side-card">
				<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/immersa-core.png' ); ?>" alt="" />
				<h3><?php esc_html_e( 'Immersa Builder + Core', 'backupflow' ); ?></h3>
				<p><?php esc_html_e( 'Build a WordPress website in one click with starter templates and Core AI.', 'backupflow' ); ?></p>
				<a href="https://wordpress.org/themes/immersa-builder/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Theme', 'backupflow' ); ?></a>
				<a href="https://wordpress.org/plugins/immersa-core-starter-templates-ai/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Plugin', 'backupflow' ); ?></a>
			</section>
		</aside>
		<?php
	}

	private function job_modal() {
		?>
		<div class="backupflow-modal" hidden>
			<div class="backupflow-modal-backdrop"></div>
			<div class="backupflow-modal-panel" role="dialog" aria-modal="true" aria-labelledby="backupflow-modal-title">
				<div class="backupflow-modal-head">
					<img src="<?php echo esc_url( BACKUPFLOW_URL . 'assets/img/BackupFlow.png' ); ?>" alt="" />
					<div>
						<h2 id="backupflow-modal-title"><?php esc_html_e( 'Process running', 'backupflow' ); ?></h2>
						<p data-job-message><?php esc_html_e( 'Preparing...', 'backupflow' ); ?></p>
					</div>
				</div>
				<div class="backupflow-progress"><span style="width:0%"></span><strong>0%</strong></div>
				<div class="backupflow-log" data-job-log></div>
				<div class="backupflow-cancel-confirm" data-cancel-confirm hidden>
					<strong><?php esc_html_e( 'Are you sure you want to cancel the process?', 'backupflow' ); ?></strong>
					<div>
						<button class="backupflow-button backupflow-button-secondary" data-cancel-no type="button"><?php esc_html_e( 'No, continue', 'backupflow' ); ?></button>
						<button class="backupflow-button" data-cancel-yes type="button"><?php esc_html_e( 'Yes, cancel', 'backupflow' ); ?></button>
					</div>
				</div>
				<div class="backupflow-modal-actions">
					<a class="backupflow-button" data-backup-download hidden href="#"><?php esc_html_e( 'Download Backup', 'backupflow' ); ?></a>
					<a class="backupflow-button backupflow-button-secondary" data-dashboard-link hidden href="<?php echo esc_url( admin_url( 'admin.php?page=backupflow' ) ); ?>"><?php esc_html_e( 'Back to Dashboard', 'backupflow' ); ?></a>
					<button class="backupflow-button backupflow-button-secondary" data-job-close type="button"><?php esc_html_e( 'Cancel', 'backupflow' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	private function notices() {
		$notice = isset( $_GET['backupflow_notice'] ) ? sanitize_key( wp_unslash( $_GET['backupflow_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $notice ) {
			return;
		}

		$messages = array(
			'settings_saved'      => __( 'BackupFlow settings saved.', 'backupflow' ),
			'imported'            => __( 'Backup imported and added to restore points.', 'backupflow' ),
			'import_failed'       => __( 'Backup import failed. Check the ZIP file and try again.', 'backupflow' ),
			'google_connected'    => __( 'Google Drive connected successfully.', 'backupflow' ),
			'google_failed'       => __( 'Google Drive connection failed.', 'backupflow' ),
			'google_state_failed' => __( 'Google Drive security check failed. Please try again.', 'backupflow' ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>';
		}
	}

	private function redirect_page_for_section( $section ) {
		return in_array( $section, array( 'ftp', 'google_drive' ), true ) ? 'backupflow-storage' : 'backupflow-settings';
	}

	private function prepare_job_response( $job ) {
		if ( empty( $job['result']['backup']['id'] ) ) {
			return $job;
		}

		$backup_id = sanitize_key( $job['result']['backup']['id'] );
		$job['result']['backup']['download_url'] = wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'backupflow_download_backup',
					'backup_id' => $backup_id,
				),
				admin_url( 'admin-post.php' )
			),
			'backupflow_download_backup_' . $backup_id
		);

		return $job;
	}
}
