<?php

namespace MediaFlattenMigrator;

final class Admin_Controller {
	const PAGE_SLUG       = 'media-flatten-migrator';
	const NONCE_ACTION    = 'media_flatten_admin';
	const JOB_OPTION      = 'media_flatten_current_job';
	const BATCH_LOCK_OPTION = 'media_flatten_batch_lock';
	const VERIFY_RESULT_OPTION = 'media_flatten_last_verify_result';
	const OLD_URL_AUDIT_RESULT_OPTION = 'media_flatten_last_old_url_audit_result';
	const REDIRECT_EXPORT_RESULT_OPTION = 'media_flatten_last_redirect_export_result';
	const DELETE_RESULT_OPTION = 'media_flatten_last_delete_result';
	const STALE_SECONDS   = 900;
	const LOG_LIMIT       = 60;

	/** @var array<string, int> */
	private $defaults = array(
		'scan'         => 50,
		'resolve'      => 100,
		'migrate'      => 5,
		'replace_urls' => 50,
		'verify'       => 100,
		'old_url_audit' => 100,
		'delete_old_files' => 10,
	);

	/** @var array<string, int> */
	private $maximums = array(
		'scan'         => 200,
		'resolve'      => 500,
		'migrate'      => 20,
		'replace_urls' => 500,
		'verify'       => 500,
		'old_url_audit' => 500,
		'delete_old_files' => 50,
	);

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_media_flatten_get_report', array( $this, 'ajax_get_report' ) );
		add_action( 'wp_ajax_media_flatten_start_job', array( $this, 'ajax_start_job' ) );
		add_action( 'wp_ajax_media_flatten_run_batch', array( $this, 'ajax_run_batch' ) );
		add_action( 'wp_ajax_media_flatten_stop_job', array( $this, 'ajax_stop_job' ) );
		add_action( 'wp_ajax_media_flatten_clear_stale_lock', array( $this, 'ajax_clear_stale_lock' ) );
		add_action( 'wp_ajax_media_flatten_start_verify', array( $this, 'ajax_start_verify' ) );
		add_action( 'wp_ajax_media_flatten_run_verify_batch', array( $this, 'ajax_run_verify_batch' ) );
		add_action( 'wp_ajax_media_flatten_get_verify_result', array( $this, 'ajax_get_verify_result' ) );
		add_action( 'wp_ajax_media_flatten_start_old_url_audit', array( $this, 'ajax_start_old_url_audit' ) );
		add_action( 'wp_ajax_media_flatten_run_old_url_audit_batch', array( $this, 'ajax_run_old_url_audit_batch' ) );
		add_action( 'wp_ajax_media_flatten_get_old_url_audit_result', array( $this, 'ajax_get_old_url_audit_result' ) );
		add_action( 'wp_ajax_media_flatten_preview_redirects', array( $this, 'ajax_preview_redirects' ) );
		add_action( 'wp_ajax_media_flatten_generate_redirect_export', array( $this, 'ajax_generate_redirect_export' ) );
		add_action( 'wp_ajax_media_flatten_start_delete_old_files', array( $this, 'ajax_start_delete_old_files' ) );
		add_action( 'wp_ajax_media_flatten_delete_old_files_dry_run', array( $this, 'ajax_delete_old_files_dry_run' ) );
		add_action( 'wp_ajax_media_flatten_delete_old_files_batch', array( $this, 'ajax_delete_old_files_batch' ) );
		add_action( 'wp_ajax_media_flatten_get_delete_report', array( $this, 'ajax_get_delete_report' ) );
		add_action( 'admin_post_media_flatten_download_redirect_export', array( $this, 'admin_post_download_redirect_export' ) );
	}

	/** @return void */
	public function add_menu() {
		add_management_page(
			'Media Flatten Migrator',
			'Media Flatten Migrator',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @param string $hook_suffix Current admin hook.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'tools_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'media-flatten-admin',
			plugins_url( '../assets/admin.css', __FILE__ ),
			array(),
			'1.1.0'
		);
		wp_enqueue_script(
			'media-flatten-admin',
			plugins_url( '../assets/admin.js', __FILE__ ),
			array(),
			'1.1.0',
			true
		);
		wp_localize_script(
			'media-flatten-admin',
			'MediaFlattenAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'defaults'  => $this->defaults,
				'confirm'   => 'This action writes changes using the existing migration rules. Continue?',
			)
		);
	}

	/** @return void */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'media-flatten-migrator' ) );
		}
		?>
		<div class="wrap media-flatten-admin">
			<h1>Media Flatten Migrator</h1>
			<p>Run each migration phase explicitly and in small, resumable batches. Opening this page never starts a migration.</p>

			<div id="mfm-notice" class="mfm-notice" hidden></div>
			<section class="mfm-panel">
				<h2>Status / Report</h2>
				<div id="mfm-status-cards" class="mfm-cards"><p>Loading report...</p></div>
				<p>
					<button class="button" data-mfm-refresh>Refresh Report</button>
					<button class="button button-secondary" data-mfm-action="install">Install / Check Manifest Table</button>
					<button class="button" id="mfm-clear-lock" hidden>Clear Stale Lock</button>
				</p>
			</section>

			<div class="mfm-grid">
				<?php $this->render_operation( 'Scan', 'scan', 50 ); ?>
				<?php $this->render_operation( 'Resolve Targets', 'resolve', 100 ); ?>
				<?php $this->render_operation( 'Migrate Files', 'migrate', 5 ); ?>
				<?php $this->render_operation( 'Replace URLs', 'replace_urls', 50 ); ?>
			</div>

			<section class="mfm-panel">
				<h2>Verify</h2>
				<p>Run comprehensive read-only verification of migrated files, metadata, database URLs, and WooCommerce attachment references.</p>
				<label>
					Batch size
					<input type="number" min="1" max="<?php echo esc_attr( $this->maximums['verify'] ); ?>"
						value="100" data-mfm-batch="verify">
				</label>
				<p>
					<button class="button button-primary" data-mfm-action="verify">Run Verify</button>
					<button class="button" id="mfm-refresh-verify">Refresh Verify Report</button>
				</p>
				<div id="mfm-verify-status" class="mfm-verify-status">Not run yet.</div>
				<pre id="mfm-verify-result">No verification result stored.</pre>
			</section>

			<section class="mfm-panel">
				<h2>Pre-Redirect Safety Audit</h2>
				<p>Run a read-only audit of remaining dated upload URLs before any future redirect export.</p>
				<label>
					Batch size
					<input type="number" min="1" max="<?php echo esc_attr( $this->maximums['old_url_audit'] ); ?>"
						value="100" data-mfm-batch="old_url_audit">
				</label>
				<p>
					<button class="button button-primary" data-mfm-action="old_url_audit">Run Old URL Audit</button>
					<button class="button" id="mfm-refresh-audit">Refresh Old URL Audit Result</button>
				</p>
				<div id="mfm-audit-status" class="mfm-verify-status">Not run yet.</div>
				<pre id="mfm-audit-result">No old URL audit result stored.</pre>
			</section>

			<section class="mfm-panel">
				<h2>Redirect Export</h2>
				<p>Preview and export exact mapping-based redirects from migrated manifest rows only. No files or database content are changed here.</p>
				<p>
					<button class="button button-primary" data-mfm-redirect-action="preview">Preview Redirects</button>
					<button class="button" data-mfm-redirect-action="apache">Generate Apache Redirect File</button>
					<button class="button" data-mfm-redirect-action="nginx">Generate Nginx Redirect File</button>
					<button class="button" data-mfm-redirect-action="csv">Generate CSV Mapping File</button>
				</p>
				<p>
					<a class="button" data-mfm-download-format="apache" data-mfm-download-href="<?php echo esc_url( $this->download_redirect_url( 'apache' ) ); ?>" href="<?php echo esc_url( $this->download_redirect_url( 'apache' ) ); ?>">Download Latest Apache File</a>
					<a class="button" data-mfm-download-format="nginx" data-mfm-download-href="<?php echo esc_url( $this->download_redirect_url( 'nginx' ) ); ?>" href="<?php echo esc_url( $this->download_redirect_url( 'nginx' ) ); ?>">Download Latest Nginx File</a>
					<a class="button" data-mfm-download-format="csv" data-mfm-download-href="<?php echo esc_url( $this->download_redirect_url( 'csv' ) ); ?>" href="<?php echo esc_url( $this->download_redirect_url( 'csv' ) ); ?>">Download Latest CSV File</a>
				</p>
				<div id="mfm-redirect-status" class="mfm-verify-status">Not run yet.</div>
				<pre id="mfm-redirect-result">No redirect export preview stored.</pre>
			</section>

			<section class="mfm-panel">
				<h2>Delete Old Files</h2>
				<p><strong>Destructive:</strong> this deletes old source files after a successful migration. Take a full backup first.</p>
				<label style="display:block;">
					<input type="checkbox" id="mfm-delete-confirm-check">
					I have a full backup and understand this will delete old source files.
				</label>
				<label style="display:block;margin-top:8px;">
					Type DELETE OLD FILES to confirm
					<input type="text" id="mfm-delete-confirm-phrase" autocomplete="off" spellcheck="false">
				</label>
				<label>
					Batch size
					<input type="number" min="1" max="<?php echo esc_attr( $this->maximums['delete_old_files'] ); ?>"
						value="<?php echo esc_attr( $this->defaults['delete_old_files'] ); ?>" data-mfm-batch="delete_old_files">
				</label>
				<p>
					<button class="button button-primary" data-mfm-action="delete_old_files_dry_run">Dry Run Delete Old Files</button>
					<button class="button button-primary" data-mfm-action="delete_old_files" id="mfm-delete-run">Delete Old Files Batch</button>
					<button class="button" id="mfm-refresh-delete">Refresh Deletion Report</button>
				</p>
				<div id="mfm-delete-status" class="mfm-verify-status">Not run yet.</div>
				<pre id="mfm-delete-result">No deletion report stored.</pre>
			</section>

			<section class="mfm-panel">
				<h2>Current Job</h2>
				<div class="mfm-progress"><span id="mfm-progress-bar"></span></div>
				<p id="mfm-progress-text">No job running.</p>
				<p>
					<button class="button" id="mfm-resume" hidden>Resume Current Job</button>
					<button class="button" id="mfm-stop" disabled>Stop</button>
				</p>
				<h3>Latest Batch Result</h3>
				<pre id="mfm-latest">None.</pre>
			</section>

			<section class="mfm-panel">
				<h2>Logs</h2>
				<pre id="mfm-logs">No logs yet.</pre>
			</section>
		</div>
		<?php
	}

	/**
	 * @param string $title       Panel title.
	 * @param string $type        Job type.
	 * @param int    $default     Default batch size.
	 * @return void
	 */
	private function render_operation( $title, $type, $default ) {
		?>
		<section class="mfm-panel">
			<h2><?php echo esc_html( $title ); ?></h2>
			<label>
				Batch size
				<input type="number" min="1" max="<?php echo esc_attr( $this->maximums[ $type ] ); ?>"
					value="<?php echo esc_attr( $default ); ?>" data-mfm-batch="<?php echo esc_attr( $type ); ?>">
			</label>
			<p>
				<button class="button" data-mfm-action="<?php echo esc_attr( $type . '_dry_run' ); ?>">Dry Run</button>
				<button class="button button-primary" data-mfm-action="<?php echo esc_attr( $type ); ?>">Run</button>
			</p>
		</section>
		<?php
	}

	/** @return void */
	public function ajax_get_report() {
		$this->ajax_guard(
			function () {
				$repository = new Manifest_Repository();
				$statuses   = array();
				foreach ( $repository->status_counts() as $row ) {
					$statuses[ $row['status'] ] = (int) $row['item_count'];
				}

				$filename_counts = ( new Usage_Reporter( $repository ) )->filename_counts();
				$extensions      = array();
				foreach ( $filename_counts['extensions'] as $row ) {
					$extensions[ $row['extension'] ] = (int) $row['file_count'];
				}

				$job = get_option( self::JOB_OPTION, array() );
				$verify = get_option( self::VERIFY_RESULT_OPTION, array() );
				$audit = get_option( self::OLD_URL_AUDIT_RESULT_OPTION, array() );
				$redirect_export = get_option( self::REDIRECT_EXPORT_RESULT_OPTION, array() );
				$delete_result = get_option( self::DELETE_RESULT_OPTION, array() );
				$redirect_service = null;
				$redirect_readiness = array();
				$delete_service = null;
				$delete_readiness = array();
				if ( $repository->table_exists() ) {
					$redirect_service   = new Redirect_Export_Service( $repository );
					$redirect_readiness = $redirect_service->readiness();
					$delete_service     = new Old_File_Deletion_Service( $repository );
					$delete_readiness   = $delete_service->readiness();
				}

				return array(
					'table_exists'            => $repository->table_exists(),
					'total_rows'              => $repository->count_rows(),
					'statuses'                => $statuses,
					'extensions'              => $extensions,
					'non_ascii'               => (int) $filename_counts['non_ascii_filenames'],
					'job'                     => $job,
					'lock_is_stale'           => $this->is_stale( $job ) || $this->batch_lock_is_stale(),
					'verify'                  => $verify,
					'old_url_audit'           => $audit,
					'redirect_export'         => $redirect_export,
					'redirect_preview_status' => $redirect_readiness['redirect_preview_status'] ?? array(),
					'redirect_export_status'   => $redirect_readiness['redirect_export_status'] ?? array(),
					'redirect_preview_ready'   => ! empty( $redirect_readiness['redirect_preview_ready'] ),
					'redirect_export_ready'    => ! empty( $redirect_readiness['redirect_export_ready'] ),
					'redirect_readiness'      => $redirect_readiness,
					'delete_old_files'        => $delete_result,
					'delete_old_files_ready'  => ! empty( $delete_readiness['delete_old_files_ready'] ),
					'delete_readiness'        => $delete_readiness,
				);
			}
		);
	}

	/** @return void */
	public function ajax_start_verify() {
		$_POST['job_type'] = 'verify';
		$this->ajax_start_job();
	}

	/** @return void */
	public function ajax_run_verify_batch() {
		$_POST['required_job_type'] = 'verify';
		$this->ajax_run_batch();
	}

	/** @return void */
	public function ajax_get_verify_result() {
		$this->ajax_guard(
			static function () {
				return array( 'result' => get_option( self::VERIFY_RESULT_OPTION, array() ) );
			}
		);
	}

	/** @return void */
	public function ajax_start_old_url_audit() {
		$_POST['job_type'] = 'old_url_audit';
		$this->ajax_start_job();
	}

	/** @return void */
	public function ajax_run_old_url_audit_batch() {
		$_POST['required_job_type'] = 'old_url_audit';
		$this->ajax_run_batch();
	}

	/** @return void */
	public function ajax_get_old_url_audit_result() {
		$this->ajax_guard(
			static function () {
				return array( 'result' => get_option( self::OLD_URL_AUDIT_RESULT_OPTION, array() ) );
			}
		);
	}

	/** @return void */
	public function ajax_delete_old_files_dry_run() {
		$_POST['job_type'] = 'delete_old_files_dry_run';
		$this->ajax_start_job();
	}

	/** @return void */
	public function ajax_delete_old_files_batch() {
		$_POST['required_job_type'] = 'delete_old_files';
		$this->ajax_run_batch();
	}

	/** @return void */
	public function ajax_get_delete_report() {
		$this->ajax_guard(
			function () {
				$service = $this->delete_old_files_service();
				return array(
					'result'    => $service->report( 100, true ),
					'readiness' => $service->readiness(),
				);
			}
		);
	}

	/** @return void */
	public function ajax_preview_redirects() {
		$this->ajax_guard(
			function () {
				$sample_limit = absint( $_POST['sample_limit'] ?? Redirect_Export_Service::SAMPLE_LIMIT );
				$sample_limit = max( 1, min( 500, $sample_limit ) );
				$service      = $this->redirect_export_service();
				$result       = $service->preview( $sample_limit, true );
				return array(
					'result'    => $result,
					'readiness' => $service->readiness(),
				);
			}
		);
	}

	/** @return void */
	public function ajax_generate_redirect_export() {
		$this->ajax_guard(
			function () {
				$format  = sanitize_key( wp_unslash( $_POST['format'] ?? '' ) );
				if ( ! in_array( $format, array( 'apache', 'nginx', 'csv' ), true ) ) {
					throw new \InvalidArgumentException( 'Use apache, nginx, or csv as the redirect export format.' );
				}
				$service = $this->redirect_export_service();
				$dir     = $service->ensure_exports_dir();
				$file    = trailingslashit( $dir ) . $service->build_filename( $format );
				$result  = $service->generate( $format, $file, 500, true );
				return array(
					'result'    => $result,
					'state'     => get_option( self::REDIRECT_EXPORT_RESULT_OPTION, array() ),
					'readiness' => $service->readiness(),
				);
			}
		);
	}

	/** @return void */
	public function ajax_start_delete_old_files() {
		$_POST['job_type'] = 'delete_old_files';
		$this->ajax_start_job();
	}

	/** @return void */
	public function admin_post_download_redirect_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this action.', 'media-flatten-migrator' ) );
		}
		if ( false === check_admin_referer( self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'media-flatten-migrator' ) );
		}

		$format  = sanitize_key( wp_unslash( $_GET['format'] ?? '' ) );
		if ( ! in_array( $format, array( 'apache', 'nginx', 'csv' ), true ) ) {
			wp_die( esc_html__( 'Unknown redirect export format.', 'media-flatten-migrator' ) );
		}
		$service = $this->redirect_export_service();
		$latest  = $service->latest_export( $format );
		if ( empty( $latest['file_path'] ) || ! file_exists( $latest['file_path'] ) ) {
			wp_die( esc_html__( 'No generated export file is available for download.', 'media-flatten-migrator' ) );
		}

		$exports_dir = wp_normalize_path( trailingslashit( $service->ensure_exports_dir() ) );
		$file_path   = wp_normalize_path( $latest['file_path'] );
		if ( 0 !== strpos( $file_path, $exports_dir ) ) {
			wp_die( esc_html__( 'The requested file is not inside the export directory.', 'media-flatten-migrator' ) );
		}

		$mime = 'text/plain; charset=utf-8';
		if ( 'csv' === $format ) {
			$mime = 'text/csv; charset=utf-8';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		readfile( $file_path );
		exit;
	}

	/** @return void */
	public function ajax_start_job() {
		$this->ajax_guard(
			function () {
				$type      = sanitize_key( wp_unslash( $_POST['job_type'] ?? '' ) );
				$base_type = $this->base_type( $type );
				$dry_run   = $this->is_dry_run( $type );

				if ( ! isset( $this->defaults[ $base_type ] ) && 'install' !== $type ) {
					throw new \InvalidArgumentException( 'Unknown job type.' );
				}

				$batch_size = 'install' === $type ? 1 : absint( $_POST['batch_size'] ?? $this->defaults[ $base_type ] );
				if ( 'install' !== $type && ( $batch_size < 1 || $batch_size > $this->maximums[ $base_type ] ) ) {
					throw new \InvalidArgumentException( 'Batch size is outside the safe range for this operation.' );
				}

				$current = get_option( self::JOB_OPTION, array() );
				if ( $current && 'running' === ( $current['status'] ?? '' ) && ! $dry_run
					&& $type === ( $current['job_type'] ?? '' ) && ! $this->is_stale( $current )
				) {
					return array( 'job' => $current, 'resumed' => true );
				}
				if ( $current && 'paused' === ( $current['status'] ?? '' ) && ! $dry_run && $type === ( $current['job_type'] ?? '' ) ) {
					$current['status']         = 'running';
					$current['heartbeat_unix'] = time();
					$this->save_job( $current );
					return array( 'job' => $current, 'resumed' => true );
				}
				if ( $current && 'running' === ( $current['status'] ?? '' ) && ! $this->is_stale( $current ) ) {
					throw new \RuntimeException( 'Another Media Flatten Migrator job is already running.' );
				}
				if ( $current && $this->is_stale( $current ) ) {
					throw new \RuntimeException( 'A stale job lock exists. Review it and use Clear Stale Lock before starting another job.' );
				}
				if ( 'delete_old_files' === $base_type && ! $dry_run ) {
					$confirmed = ! empty( $_POST['delete_confirm_checked'] ) && 'DELETE OLD FILES' === trim( (string) ( $_POST['delete_confirm_phrase'] ?? '' ) );
					if ( ! $confirmed ) {
						throw new \RuntimeException( 'Old-file deletion requires the backup checkbox and the exact confirmation phrase DELETE OLD FILES.' );
					}
				}

				$job = $this->new_job( $type, $base_type, $batch_size, $dry_run );
				if ( ! $dry_run ) {
					$this->save_job( $job );
				}

				return array( 'job' => $job, 'resumed' => false );
			}
		);
	}

	/** @return void */
	public function ajax_run_batch() {
		$this->ajax_guard(
			function () {
				$dry_job = json_decode( wp_unslash( $_POST['dry_job'] ?? '' ), true );
				if ( is_array( $dry_job ) && ! empty( $dry_job['dry_run'] ) ) {
					$job = $dry_job;
					$base_type = sanitize_key( $job['base_type'] ?? '' );
					if ( ! isset( $this->defaults[ $base_type ] )
						|| ( $base_type . '_dry_run' ) !== ( $job['job_type'] ?? '' )
						|| (int) ( $job['batch_size'] ?? 0 ) < 1
						|| (int) $job['batch_size'] > $this->maximums[ $base_type ]
					) {
						throw new \RuntimeException( 'Invalid dry-run job state.' );
					}
				} else {
					$job = get_option( self::JOB_OPTION, array() );
				}
				if ( ! $job || 'running' !== ( $job['status'] ?? '' ) ) {
					throw new \RuntimeException( 'There is no running job to process.' );
				}
				$required_job_type = sanitize_key( wp_unslash( $_POST['required_job_type'] ?? '' ) );
				if ( $required_job_type && $required_job_type !== ( $job['job_type'] ?? '' ) ) {
					throw new \RuntimeException( 'The requested batch endpoint does not match the current job.' );
				}

				$locked = false;
				if ( empty( $job['dry_run'] ) ) {
					$locked = $this->acquire_batch_lock();
					if ( ! $locked ) {
						throw new \RuntimeException( 'Another migration batch request is still running.' );
					}
				}

				try {
					$result = $this->process_batch( $job );
					$job    = $result['job'];
					if ( empty( $job['dry_run'] ) ) {
						$stored = get_option( self::JOB_OPTION, array() );
						if ( 'paused' === ( $stored['status'] ?? '' ) && 'complete' !== $job['status'] ) {
							$job['status'] = 'paused';
						}
						$this->save_job( $job );
					}
				} catch ( \Throwable $exception ) {
					if ( empty( $job['dry_run'] ) ) {
						++$job['failed_count'];
						$job['status'] = 'paused';
						$this->append_log( $job, 'Batch error: ' . $exception->getMessage() );
						$this->save_job( $job );
					}
					throw $exception;
				} finally {
					if ( $locked ) {
						delete_option( self::BATCH_LOCK_OPTION );
					}
				}

				return $result;
			}
		);
	}

	/** @return void */
	public function ajax_stop_job() {
		$this->ajax_guard(
			function () {
				$job = get_option( self::JOB_OPTION, array() );
				if ( $job && 'running' === ( $job['status'] ?? '' ) ) {
					$job['status'] = 'paused';
					$this->append_log( $job, 'Job paused by administrator.' );
					$this->save_job( $job );
				}
				return array( 'job' => $job );
			}
		);
	}

	/** @return void */
	public function ajax_clear_stale_lock() {
		$this->ajax_guard(
			function () {
				$job = get_option( self::JOB_OPTION, array() );
				if ( ! $this->is_stale( $job ) ) {
					if ( ! $this->batch_lock_is_stale() ) {
						throw new \RuntimeException( 'The current job lock is not stale.' );
					}
				}
				delete_option( self::JOB_OPTION );
				delete_option( self::BATCH_LOCK_OPTION );
				return array( 'cleared' => true );
			}
		);
	}

	/**
	 * @param callable $callback Secured callback.
	 * @return void
	 */
	private function ajax_guard( $callback ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'You do not have permission to perform this action.' ), 403 );
		}
		if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed. Refresh the page and try again.' ), 403 );
		}

		ob_start();
		try {
			$data = call_user_func( $callback );
			if ( is_wp_error( $data ) ) {
				throw new \RuntimeException( $data->get_error_message() );
			}
			ob_end_clean();
			wp_send_json_success( $data );
		} catch ( \Throwable $exception ) {
			ob_end_clean();
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 500 );
		}
	}

	/**
	 * @return Redirect_Export_Service
	 */
	private function redirect_export_service() {
		$repository = new Manifest_Repository();
		if ( ! $repository->table_exists() ) {
			throw new \RuntimeException( 'The manifest table is not installed. Run Install / Check Manifest Table first.' );
		}

		return new Redirect_Export_Service( $repository );
	}

	/**
	 * @return Old_File_Deletion_Service
	 */
	private function delete_old_files_service() {
		$repository = new Manifest_Repository();
		if ( ! $repository->table_exists() ) {
			throw new \RuntimeException( 'The manifest table is not installed. Run Install / Check Manifest Table first.' );
		}

		return new Old_File_Deletion_Service( $repository );
	}

	/**
	 * @param string $format Redirect export format.
	 * @return string
	 */
	private function download_redirect_url( $format ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=media_flatten_download_redirect_export&format=' . rawurlencode( sanitize_key( $format ) ) ),
			self::NONCE_ACTION
		);
	}

	/**
	 * @param string $type       Requested job type.
	 * @param string $base_type  Base job type.
	 * @param int    $batch_size Batch size.
	 * @param bool   $dry_run    Dry-run flag.
	 * @return array<string, mixed>
	 */
	private function new_job( $type, $base_type, $batch_size, $dry_run ) {
		$repository = new Manifest_Repository();
		if ( ! in_array( $type, array( 'install', 'scan_dry_run' ), true ) && ! $repository->table_exists() ) {
			throw new \RuntimeException( 'The manifest table is not installed. Run Install / Check Manifest Table first.' );
		}

		$total = 1;
		if ( 'scan' === $base_type ) {
			$total = ( new Attachment_Scanner() )->count_attachments();
		} elseif ( 'resolve' === $base_type ) {
			$total = $repository->count_rows( true );
		} elseif ( 'migrate' === $base_type ) {
			$total = $repository->count_attachments();
		} elseif ( 'replace_urls' === $base_type ) {
			global $wpdb;
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ) * 2;
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
			$total += (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		} elseif ( 'verify' === $base_type ) {
			$total = ( new Verification_Service( $repository ) )->estimate_total();
		} elseif ( 'old_url_audit' === $base_type ) {
			$total = ( new Old_URL_Audit_Service( $repository ) )->estimate_total();
		} elseif ( 'delete_old_files' === $base_type ) {
			$total = ( new Old_File_Deletion_Service( $repository ) )->estimate_total();
		}

		$job = array(
			'job_type'          => $type,
			'base_type'         => $base_type,
			'dry_run'           => $dry_run,
			'batch_size'        => $batch_size,
			'started_at'        => current_time( 'mysql' ),
			'heartbeat_unix'    => time(),
			'last_processed_id' => 0,
			'area_index'        => 0,
			'total_estimated'   => $total,
			'processed_items'   => 0,
			'success_count'     => 0,
			'skipped_count'     => 0,
			'failed_count'      => 0,
			'status'            => 'running',
			'logs'              => array( sprintf( 'Started %s%s.', $base_type, $dry_run ? ' dry run' : '' ) ),
		);
		if ( 'verify' === $base_type ) {
			$job['verification_result'] = ( new Verification_Service( $repository ) )->initial_result();
		}
		if ( 'old_url_audit' === $base_type ) {
			$job['old_url_audit_result'] = ( new Old_URL_Audit_Service( $repository ) )->initial_result();
		}
		if ( 'delete_old_files' === $base_type ) {
			$delete_service = new Old_File_Deletion_Service( $repository );
			if ( ! $dry_run ) {
				$report = $delete_service->report( 100, true );
				if ( empty( $report['ready'] ) ) {
					throw new \RuntimeException( 'Old-file deletion is not ready yet. Review verification, old URL audit, redirect export, and deletion report results before proceeding.' );
				}
			}
			$job['delete_old_files_result'] = $delete_service->initial_result();
		}

		return $job;
	}

	/**
	 * @param array<string, mixed> $job Job state.
	 * @return array<string, mixed>
	 */
	private function process_batch( array $job ) {
		$repository = new Manifest_Repository();
		$batch      = (int) $job['batch_size'];
		$cursor     = (int) $job['last_processed_id'];
		$dry_run    = ! empty( $job['dry_run'] );
		$done       = false;
		$latest     = array();

		if ( 'install' === $job['base_type'] ) {
			Schema::install();
			if ( ! $repository->table_exists() ) {
				throw new \RuntimeException( 'Manifest table installation did not complete.' );
			}
			$job['processed_items'] = 1;
			$job['success_count']   = 1;
			$done                   = true;
			$latest                 = array( 'message' => 'Manifest table installed or updated.' );
		} elseif ( 'scan' === $job['base_type'] ) {
			$result = ( new Attachment_Scanner() )->scan_batch( $cursor, $batch );
			$scan   = $result['result'];
			$writes = array( 'inserted' => 0, 'updated' => 0 );
			if ( ! $dry_run ) {
				$writes = $repository->save_files( $scan->files() );
			}
			$summary                  = $scan->summary();
			$job['last_processed_id'] = $result['last_processed_id'];
			$job['processed_items']  += $summary['total_attachments'];
			$job['success_count']    += $summary['total_attachments'];
			$job['skipped_count']    += $summary['missing_files'];
			$done                     = $result['done'];
			$latest                   = array_merge( $summary, $writes );
		} elseif ( 'resolve' === $job['base_type'] ) {
			$result                    = ( new Target_Resolver( $repository ) )->resolve_batch( $cursor, $batch, $dry_run );
			$summary                   = $result['summary'];
			$job['last_processed_id']  = $result['last_processed_id'];
			$job['processed_items']   += $summary['processed'];
			$job['success_count']     += $summary['resolved'];
			$job['skipped_count']     += $summary['missing'] + $summary['blocked_collision'];
			$done                      = $result['done'];
			$latest                    = $summary;
		} elseif ( 'migrate' === $job['base_type'] ) {
			$events = array();
			$summary = ( new Batch_Migrator( $repository, new Single_Attachment_Migrator( $repository ) ) )->run(
				$batch,
				$cursor,
				$dry_run,
				static function ( $row ) use ( &$events ) {
					$events[] = $row;
				},
				$batch
			);
			$last = $summary['last_processed_attachment_id'];
			$job['last_processed_id'] = null === $last ? $cursor : (int) $last;
			$job['processed_items']  += $summary['scanned_candidate_attachments'];
			$job['success_count']    += $dry_run ? $summary['would_migrate_attachments'] : $summary['migrated_attachments'];
			$job['failed_count']     += $summary['failed_attachments'];
			$job['skipped_count']    += $summary['skipped_missing']
				+ $summary['skipped_blocked_collision']
				+ $summary['skipped_already_migrated']
				+ $summary['skipped_failed']
				+ $summary['skipped_incomplete_targets']
				+ $summary['skipped_other_ineligible']
				+ $summary['skipped_preflight_blocked'];
			$done   = $summary['scanned_candidate_attachments'] < $batch;
			$latest = array( 'summary' => $summary, 'events' => $events );
		} elseif ( 'replace_urls' === $job['base_type'] ) {
			$areas    = array( 'post_content', 'post_excerpt', 'postmeta', 'options' );
			$area     = $areas[ (int) $job['area_index'] ];
			$replacer = new URL_Replacer( $repository->get_migrated_url_mappings() );
			$result   = $replacer->run_batch( $area, $cursor, $batch, $dry_run );
			$summary  = $result['summary'];
			$job['processed_items'] += $summary['scanned'];
			$job['success_count']   += $summary['changed_rows'];
			$job['skipped_count']   += $summary['skipped_unsafe_serialized_rows'];
			$job['last_processed_id'] = $result['last_processed_id'];
			$latest = array( 'area' => $area, 'summary' => $summary );
			if ( $result['done'] ) {
				++$job['area_index'];
				$job['last_processed_id'] = 0;
				$done = $job['area_index'] >= count( $areas );
			}
		} elseif ( 'verify' === $job['base_type'] ) {
			$verifier = new Verification_Service( $repository );
			$stages   = $verifier->stages();
			$stage    = $stages[ (int) $job['area_index'] ];
			$result   = $verifier->run_batch( $stage, $cursor, $batch, $job['verification_result'] );
			$job['verification_result'] = $result['result'];
			$job['processed_items']    += $result['processed'];
			$job['last_processed_id']   = $result['last_processed_id'];
			$latest = array(
				'stage'    => $stage,
				'processed' => $result['processed'],
				'errors'   => $result['result']['errors_count'],
				'warnings' => $result['result']['warnings_count'],
			);
			if ( $result['done'] ) {
				++$job['area_index'];
				$job['last_processed_id'] = 0;
				$done = $job['area_index'] >= count( $stages );
			}
			if ( $done ) {
				$job['verification_result'] = $verifier->finalize( $job['verification_result'] );
				$job['success_count']       = $job['verification_result']['pass'] ? 1 : 0;
				$job['failed_count']        = $job['verification_result']['errors_count'];
				update_option( self::VERIFY_RESULT_OPTION, $job['verification_result'], false );
				$latest['result'] = $job['verification_result'];
			}
		} elseif ( 'old_url_audit' === $job['base_type'] ) {
			$audit_service = new Old_URL_Audit_Service( $repository );
			$stages        = $audit_service->stages();
			$stage         = $stages[ (int) $job['area_index'] ];
			$result        = $audit_service->run_batch( $stage, $cursor, $batch, $job['old_url_audit_result'] );
			$job['old_url_audit_result'] = $result['result'];
			$job['processed_items']     += $result['processed'];
			$job['last_processed_id']    = $result['last_processed_id'];
			$latest = array(
				'stage'      => $stage,
				'processed'  => $result['processed'],
				'migrated'   => $result['result']['migrated_mapping_old_url_remaining'],
				'non_migrated' => $result['result']['non_migrated_manifest_url_remaining'],
				'orphan'     => $result['result']['orphan_old_upload_url_remaining'],
			);
			if ( $result['done'] ) {
				++$job['area_index'];
				$job['last_processed_id'] = 0;
				$done = $job['area_index'] >= count( $stages );
			}
			if ( $done ) {
				$job['old_url_audit_result'] = $audit_service->finalize( $job['old_url_audit_result'] );
				$job['success_count']        = $job['old_url_audit_result']['safe'] ? 1 : 0;
				$job['failed_count']         = $job['old_url_audit_result']['migrated_mapping_old_url_remaining']
					+ $job['old_url_audit_result']['non_migrated_manifest_url_remaining']
					+ $job['old_url_audit_result']['orphan_old_upload_url_remaining'];
				update_option( self::OLD_URL_AUDIT_RESULT_OPTION, $job['old_url_audit_result'], false );
				$latest['result'] = $job['old_url_audit_result'];
			}
		} elseif ( 'delete_old_files' === $job['base_type'] ) {
			$delete_service = new Old_File_Deletion_Service( $repository );
			$result         = $delete_service->run_batch( $cursor, $batch, $job['delete_old_files_result'], $dry_run );
			$job['delete_old_files_result'] = $result['result'];
			$job['processed_items']        += $result['processed'];
			$job['last_processed_id']       = $result['last_manifest_id'];
			$job['success_count']          += $dry_run ? $result['summary']['eligible'] : $result['summary']['deleted'];
			$job['skipped_count']          += $result['summary']['skipped'];
			$job['failed_count']           += $result['summary']['failed'];
			$done                           = $result['done'];
			$latest                         = array( 'summary' => $result['summary'], 'result' => $result['result'] );
			if ( $done ) {
				$job['delete_old_files_result'] = $delete_service->finalize( $job['delete_old_files_result'], $dry_run );
				$latest['result']               = $job['delete_old_files_result'];
			}
			if ( ! $dry_run ) {
				$delete_service->store_state( $job['delete_old_files_result'] );
			}
		}

		$job['heartbeat_unix'] = time();
		$this->append_log(
			$job,
			sprintf(
				'%s batch: processed %d total, success %d, skipped %d, failed %d.',
				$job['base_type'],
				$job['processed_items'],
				$job['success_count'],
				$job['skipped_count'],
				$job['failed_count']
			)
		);
		if ( $done ) {
			$job['status'] = 'complete';
			$this->append_log( $job, 'Job complete.' );
		}

		return array(
			'job'    => $job,
			'latest' => $latest,
			'done'   => $done,
		);
	}

	/** @param array<string,mixed> $job Job state. @return void */
	private function save_job( array $job ) {
		update_option( self::JOB_OPTION, $job, false );
	}

	/** @param array<string,mixed> $job Job state. @param string $message Log line. @return void */
	private function append_log( array &$job, $message ) {
		$job['logs'][] = current_time( 'mysql' ) . ' ' . $message;
		$job['logs']   = array_slice( $job['logs'], -self::LOG_LIMIT );
	}

	/** @param string $type Job type. @return string */
	private function base_type( $type ) {
		return preg_replace( '/_dry_run$/', '', $type );
	}

	/** @param string $type Job type. @return bool */
	private function is_dry_run( $type ) {
		return (bool) preg_match( '/_dry_run$/', $type );
	}

	/** @param array<string,mixed> $job Job state. @return bool */
	private function is_stale( $job ) {
		return is_array( $job )
			&& 'running' === ( $job['status'] ?? '' )
			&& ! empty( $job['heartbeat_unix'] )
			&& ( time() - (int) $job['heartbeat_unix'] ) > self::STALE_SECONDS;
	}

	/** @return bool */
	private function acquire_batch_lock() {
		if ( add_option( self::BATCH_LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		if ( $this->batch_lock_is_stale() ) {
			delete_option( self::BATCH_LOCK_OPTION );
			return add_option( self::BATCH_LOCK_OPTION, time(), '', false );
		}

		return false;
	}

	/** @return bool */
	private function batch_lock_is_stale() {
		$created = (int) get_option( self::BATCH_LOCK_OPTION, 0 );
		return $created > 0 && ( time() - $created ) > self::STALE_SECONDS;
	}
}
