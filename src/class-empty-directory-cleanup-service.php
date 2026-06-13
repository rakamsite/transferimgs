<?php

namespace MediaFlattenMigrator;

final class Empty_Directory_Cleanup_Service {
	const STATE_OPTION = 'media_flatten_cleanup_state';
	const SAMPLE_LIMIT = 20;
	const LOG_LIMIT    = 500;
	const EXPORT_DIR   = 'media-flatten-exports';

	/** @var Manifest_Repository */
	private $repository;

	/** @var string */
	private $uploads_base_dir;

	/** @var string */
	private $exports_dir;

	public function __construct( Manifest_Repository $repository ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'WordPress could not determine the uploads directory: ' . $uploads['error'] );
		}

		$this->repository       = $repository;
		$this->uploads_base_dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$this->exports_dir      = $this->uploads_base_dir . '/' . self::EXPORT_DIR;
	}

	/**
	 * Read the latest stored cleanup result.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Return a lightweight readiness summary based on stored state and related phase summaries.
	 *
	 * @return array<string, mixed>
	 */
	public function readiness() {
		$verify   = get_option( Admin_Controller::VERIFY_RESULT_OPTION, array() );
		$audit    = get_option( Admin_Controller::OLD_URL_AUDIT_RESULT_OPTION, array() );
		$redirect = ( new Redirect_Export_Service( $this->repository ) )->readiness();
		$delete   = ( new Old_File_Deletion_Service( $this->repository ) )->readiness();
		$state    = $this->state();
		$counts   = $this->repository->status_counts();
		$status_counts = array();
		foreach ( $counts as $row ) {
			$status_counts[ $row['status'] ] = (int) $row['item_count'];
		}

		$delete_complete = 0 === (int) ( $delete['eligible_count'] ?? 0 );
		$delete_complete = $delete_complete && 0 === (int) ( $delete['failed_count'] ?? 0 );
		$ready = ! empty( $verify['pass'] )
			&& ! empty( $verify['pre_redirect_ready'] )
			&& ! empty( $verify['redirect_export_ready'] )
			&& ! empty( $audit['safe'] )
			&& ! empty( $redirect['redirect_export_has_run'] )
			&& ! empty( $redirect['redirect_export_ready'] )
			&& $delete_complete
			&& empty( $status_counts['copying'] )
			&& empty( $status_counts['copied'] )
			&& empty( $status_counts['failed'] )
			&& 0 === (int) $this->repository->count_invalid_migrated_url_rows()
			&& 0 === (int) $this->repository->count_failed_old_file_deletions();

		$warnings = array();
		if ( empty( $verify ) ) {
			$warnings[] = 'Verification has not been run yet.';
		} elseif ( empty( $verify['pass'] ) ) {
			$warnings[] = 'Verification has failures.';
		}
		if ( empty( $audit ) ) {
			$warnings[] = 'Old URL audit has not been run yet.';
		} elseif ( empty( $audit['safe'] ) ) {
			$warnings[] = 'Old URL audit still reports unsafe dated upload URLs.';
		}
		if ( empty( $redirect['redirect_export_has_run'] ) || empty( $redirect['redirect_export_ready'] ) ) {
			$warnings[] = 'A successful final redirect export is required before cleanup.';
		}
		if ( ! $delete_complete ) {
			$warnings[] = 'Old-file deletion has not fully completed yet.';
		}
		return array(
			'ready'                      => $ready,
			'cleanup_ready'              => $ready,
			'verify'                     => $verify,
			'old_url_audit'              => $audit,
			'redirect_readiness'         => $redirect,
			'delete_readiness'           => $delete,
			'status_counts'              => $status_counts,
			'duplicate_manifest_rows'    => count( $this->repository->get_duplicate_logical_groups() ),
			'directory_state'            => array(),
			'remaining_old_directories'  => (int) ( $state['remaining_count'] ?? 0 ),
			'empty_month_dirs_found'     => (int) ( $state['empty_month_dirs_found'] ?? 0 ),
			'empty_year_dirs_found'      => (int) ( $state['empty_year_dirs_found'] ?? 0 ),
			'removed_count'              => (int) ( $state['removed_count'] ?? 0 ),
			'skipped_not_empty_count'    => (int) ( $state['skipped_not_empty_count'] ?? 0 ),
			'skipped_unsafe_count'       => (int) ( $state['skipped_unsafe_count'] ?? 0 ),
			'already_missing_count'      => (int) ( $state['already_missing_count'] ?? 0 ),
			'failed_count'               => (int) ( $state['failed_count'] ?? 0 ),
			'warning_count'              => (int) ( $state['warning_count'] ?? 0 ),
			'last_dry_run_at'            => $state['last_dry_run_at'] ?? null,
			'last_cleanup_at'            => $state['last_cleanup_at'] ?? null,
			'last_batch_at'              => $state['last_batch_at'] ?? null,
			'last_processed_path'        => $state['last_processed_path'] ?? null,
			'dry_run_status'             => $state['dry_run_status'] ?? 'not_run',
			'dry_run_completed_at'       => $state['dry_run_completed_at'] ?? null,
			'dry_run_pass'               => ! empty( $state['dry_run_pass'] ),
			'generated_at'               => $state['generated_at'] ?? null,
			'completed_at'               => $state['completed_at'] ?? null,
			'warnings'                   => $warnings,
		);
	}

	/**
	 * Return a current filesystem snapshot of old year/month directories.
	 *
	 * @param int $sample_limit Sample cap.
	 * @return array<string, mixed>
	 */
	public function directory_state( $sample_limit = self::SAMPLE_LIMIT ) {
		$sample_limit = max( 0, (int) $sample_limit );
		$summary = array(
			'month_dirs_total'      => 0,
			'year_dirs_total'       => 0,
			'empty_month_dirs'      => 0,
			'empty_year_dirs'       => 0,
			'non_empty_month_dirs'  => 0,
			'non_empty_year_dirs'   => 0,
			'already_missing'       => 0,
			'unsafe'                => 0,
			'samples'               => array(
				'empty'      => array(),
				'not_empty'  => array(),
				'unsafe'     => array(),
				'missing'    => array(),
			),
		);

		foreach ( $this->scan_candidates() as $candidate ) {
			$summary_key = 'month' === $candidate['kind'] ? 'month_dirs_total' : 'year_dirs_total';
			++$summary[ $summary_key ];

			$check = $this->evaluate_directory( $candidate );
			switch ( $check['status'] ) {
				case 'eligible':
					if ( 'month' === $candidate['kind'] ) {
						++$summary['empty_month_dirs'];
					} else {
						++$summary['empty_year_dirs'];
					}
					if ( count( $summary['samples']['empty'] ) < $sample_limit ) {
						$summary['samples']['empty'][] = $this->sample_directory( $candidate, $check );
					}
					break;
				case 'not_empty':
					if ( 'month' === $candidate['kind'] ) {
						++$summary['non_empty_month_dirs'];
					} else {
						++$summary['non_empty_year_dirs'];
					}
					if ( count( $summary['samples']['not_empty'] ) < $sample_limit ) {
						$summary['samples']['not_empty'][] = $this->sample_directory( $candidate, $check );
					}
					break;
				case 'already_missing':
					++$summary['already_missing'];
					if ( count( $summary['samples']['missing'] ) < $sample_limit ) {
						$summary['samples']['missing'][] = $this->sample_directory( $candidate, $check );
					}
					break;
				default:
					++$summary['unsafe'];
					if ( count( $summary['samples']['unsafe'] ) < $sample_limit ) {
						$summary['samples']['unsafe'][] = $this->sample_directory( $candidate, $check );
					}
					break;
			}
		}

		$summary['total_candidates'] = $summary['month_dirs_total'] + $summary['year_dirs_total'];
		$summary['remaining_count']   = $summary['empty_month_dirs'] + $summary['empty_year_dirs'] + $summary['non_empty_month_dirs'] + $summary['non_empty_year_dirs'];

		return $summary;
	}

	/**
	 * Count current candidate directories.
	 *
	 * @return int
	 */
	public function estimate_total() {
		return $this->directory_state()['total_candidates'];
	}

	/**
	 * Run a read-only dry-run report across all candidate directories.
	 *
	 * @param int  $batch_size Batch size.
	 * @param bool $store      Whether to persist the report.
	 * @return array<string, mixed>
	 */
	public function report( $batch_size = 20, $store = true ) {
		$result = $this->initial_result();
		$cursor = '';
		do {
			$batch  = $this->run_batch( $cursor, $batch_size, $result, true );
			$result = $batch['result'];
			$cursor = $batch['last_path'];
		} while ( ! $batch['done'] );

		$result = $this->finalize( $result, true );
		$result['dry_run_pass']        = ! empty( $result['pass'] );
		$result['dry_run_completed_at'] = current_time( 'mysql' );
		$result['dry_run_status']      = $result['dry_run_pass'] ? 'pass' : 'fail';
		$result['remaining_count']     = $this->directory_state()['remaining_count'];
		if ( $store ) {
			$this->store_state( $result );
		}

		return $result;
	}

	/**
	 * Create a fresh result envelope.
	 *
	 * @return array<string, mixed>
	 */
	public function initial_result() {
		return array(
			'pass'                   => false,
			'ready'                  => false,
			'cleanup_ready'          => false,
			'generated_at'           => null,
			'completed_at'           => null,
			'dry_run_pass'           => false,
			'dry_run_completed_at'    => null,
			'dry_run_status'         => 'not_run',
			'last_batch_at'          => null,
			'last_processed_path'    => '',
			'processed'              => 0,
			'eligible_count'         => 0,
			'removed_count'          => 0,
			'skipped_not_empty_count' => 0,
			'skipped_unsafe_count'    => 0,
			'already_missing_count'   => 0,
			'failed_count'           => 0,
			'warning_count'          => 0,
			'remaining_count'        => 0,
			'empty_month_dirs_found'  => 0,
			'empty_year_dirs_found'   => 0,
			'status_counts'          => array(),
			'duplicate_manifest_rows'=> 0,
			'verify'                 => array(),
			'old_url_audit'          => array(),
			'redirect_readiness'     => array(),
			'delete_readiness'       => array(),
			'errors_count'           => 0,
			'warnings_count'         => 0,
			'info_count'             => 0,
			'errors'                 => array(),
			'warnings'               => array(),
			'info'                   => array(),
			'samples'                => array(
				'eligible'        => array(),
				'removed'         => array(),
				'not_empty'       => array(),
				'already_missing' => array(),
				'unsafe'          => array(),
				'failed'          => array(),
			),
			'logs'                   => array(),
		);
	}

	/**
	 * Run one cleanup batch.
	 *
	 * @param string               $after_path Cursor path.
	 * @param int                  $limit      Batch size.
	 * @param array<string, mixed>  $result     Result accumulator.
	 * @param bool                 $dry_run    Dry-run flag.
	 * @return array<string, mixed>
	 */
	public function run_batch( $after_path, $limit, array $result, $dry_run ) {
		$candidates = $this->scan_candidates();
		$batch_limit = max( 1, (int) $limit );
		$processed = 0;
		$eligible = 0;
		$removed = 0;
		$skipped_not_empty = 0;
		$skipped_unsafe = 0;
		$already_missing = 0;
		$failed = 0;
		$last_path = '';
		$started = '' === (string) $after_path;

		foreach ( $candidates as $candidate ) {
			if ( ! $started ) {
				if ( strcmp( $candidate['path'], (string) $after_path ) <= 0 ) {
					continue;
				}
				$started = true;
			}

			if ( $processed >= $batch_limit ) {
				break;
			}

			++$processed;
			$result['processed']++;
			$last_path = $candidate['path'];

			$check = $this->evaluate_directory( $candidate );
			if ( 'eligible' === $check['status'] ) {
				++$eligible;
				$result['eligible_count']++;
				$result['empty_month_dirs_found'] += 'month' === $candidate['kind'] ? 1 : 0;
				$result['empty_year_dirs_found'] += 'year' === $candidate['kind'] ? 1 : 0;
				$this->sample( $result['samples']['eligible'], $this->sample_directory( $candidate, $check ) );
				$this->log( $result, sprintf( 'Empty directory candidate %s is safe to remove.', $candidate['relative_path'] ) );

				if ( ! $dry_run ) {
					$delete = $this->remove_directory( $candidate, $result, $check );
					$removed += $delete['removed'];
				}
				continue;
			}

			if ( 'not_empty' === $check['status'] ) {
				++$skipped_not_empty;
				$result['skipped_not_empty_count']++;
				$this->sample( $result['samples']['not_empty'], $this->sample_directory( $candidate, $check ) );
				$this->log( $result, sprintf( 'Directory %s is not empty and was skipped.', $candidate['relative_path'] ) );
				continue;
			}

			if ( 'already_missing' === $check['status'] ) {
				++$already_missing;
				$result['already_missing_count']++;
				$this->sample( $result['samples']['already_missing'], $this->sample_directory( $candidate, $check ) );
				$this->log( $result, sprintf( 'Directory %s is already missing.', $candidate['relative_path'] ) );
				continue;
			}

			++$skipped_unsafe;
			$result['skipped_unsafe_count']++;
			if ( 'failed' === $check['status'] ) {
				++$failed;
				$result['failed_count']++;
				$result['errors_count']++;
				$result['errors'][] = $check['message'];
				$this->sample( $result['samples']['failed'], $this->sample_directory( $candidate, $check ) );
			} else {
				$this->sample( $result['samples']['unsafe'], $this->sample_directory( $candidate, $check ) );
			}
			$this->log( $result, $check['message'] );
		}

		$result['last_processed_path'] = $last_path;
		$result['last_batch_at']       = current_time( 'mysql' );
		$result['remaining_count']     = $this->directory_state()['remaining_count'];
		$this->append_counts( $result );

		$done = true;
		if ( '' !== $last_path ) {
			foreach ( $candidates as $candidate ) {
				if ( strcmp( $candidate['path'], $last_path ) > 0 ) {
					$done = false;
					break;
				}
			}
		}

		return array(
			'result'           => $result,
			'processed'        => $processed,
			'last_path'        => $last_path,
			'done'             => $done,
			'summary'          => array(
				'processed'          => $processed,
				'eligible'           => $eligible,
				'removed'            => $removed,
				'skipped_not_empty'  => $skipped_not_empty,
				'skipped_unsafe'     => $skipped_unsafe,
				'already_missing'    => $already_missing,
				'failed'             => $failed,
				'last_path'          => $last_path,
			),
		);
	}

	/**
	 * Finalize a result and store timestamps/readiness.
	 *
	 * @param array<string, mixed> $result Result.
	 * @param bool                 $read_only Whether the result came from a dry run.
	 * @return array<string, mixed>
	 */
	public function finalize( array $result, $read_only = false ) {
		$gate = $this->readiness();
		$result['verify']            = $gate['verify'];
		$result['old_url_audit']     = $gate['old_url_audit'];
		$result['redirect_readiness'] = $gate['redirect_readiness'];
		$result['delete_readiness']   = $gate['delete_readiness'];
		$result['status_counts']     = $gate['status_counts'];
		$result['duplicate_manifest_rows'] = $gate['duplicate_manifest_rows'];
		$result['cleanup_ready']     = $gate['cleanup_ready'];
		$result['ready']             = $read_only ? ( 0 === (int) $result['errors_count'] ) : $gate['cleanup_ready'];
		$result['pass']              = $result['ready'];
		$result['completed_at']      = current_time( 'mysql' );
		$result['generated_at']      = $result['generated_at'] ?? current_time( 'mysql' );
		if ( $read_only ) {
			$result['dry_run_pass'] = 0 === (int) $result['errors_count'];
		}
		$this->append_counts( $result );
		return $result;
	}

	/**
	 * Store the latest cleanup result.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return void
	 */
	public function store_state( array $result ) {
		$state = $this->state();
		if ( empty( $state ) || ! is_array( $state ) ) {
			$state = array();
		}

		$is_dry_run_result = ! empty( $result['dry_run_completed_at'] )
			|| in_array( (string) ( $result['dry_run_status'] ?? '' ), array( 'pass', 'fail' ), true );
		$state['generated_at']        = $result['generated_at'] ?? current_time( 'mysql' );
		$state['completed_at']        = $result['completed_at'] ?? null;
		if ( $is_dry_run_result ) {
			$state['dry_run_pass']        = ! empty( $result['dry_run_pass'] );
			$state['dry_run_completed_at'] = $result['dry_run_completed_at'] ?? ( ! empty( $result['dry_run_pass'] ) ? ( $result['completed_at'] ?? current_time( 'mysql' ) ) : null );
			$state['dry_run_status']      = $result['dry_run_status'] ?? ( ! empty( $result['dry_run_pass'] ) ? 'pass' : 'fail' );
			$state['last_dry_run_at']     = $state['dry_run_completed_at'];
		} else {
			$state['dry_run_pass']        = ! empty( $state['dry_run_pass'] );
			$state['dry_run_completed_at'] = $state['dry_run_completed_at'] ?? null;
			$state['dry_run_status']      = $state['dry_run_status'] ?? 'not_run';
		}
		$state['last_cleanup_at']      = $result['completed_at'] ?? $state['last_cleanup_at'] ?? null;
		$state['last_batch_at']        = $result['last_batch_at'] ?? null;
		$state['last_processed_path']  = $result['last_processed_path'] ?? '';
		$state['ready']                = ! empty( $result['ready'] );
		$state['cleanup_ready']        = ! empty( $result['cleanup_ready'] );
		$state['removed_count']        = (int) ( $result['removed_count'] ?? 0 );
		$state['skipped_not_empty_count'] = (int) ( $result['skipped_not_empty_count'] ?? 0 );
		$state['skipped_unsafe_count'] = (int) ( $result['skipped_unsafe_count'] ?? 0 );
		$state['already_missing_count'] = (int) ( $result['already_missing_count'] ?? 0 );
		$state['failed_count']         = (int) ( $result['failed_count'] ?? 0 );
		$state['warning_count']        = (int) ( $result['warning_count'] ?? 0 );
		$state['remaining_count']      = (int) ( $result['remaining_count'] ?? 0 );
		$state['empty_month_dirs_found'] = (int) ( $result['empty_month_dirs_found'] ?? 0 );
		$state['empty_year_dirs_found']  = (int) ( $result['empty_year_dirs_found'] ?? 0 );
		$state['warnings']             = $result['warnings'] ?? array();
		$state['errors']               = $result['errors'] ?? array();
		$state['info']                 = $result['info'] ?? array();
		$state['samples']              = $result['samples'] ?? array();
		$state['logs']                 = array_slice( isset( $result['logs'] ) && is_array( $result['logs'] ) ? $result['logs'] : array(), - self::LOG_LIMIT );
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Return a current filesystem snapshot for final reporting.
	 *
	 * @param int $sample_limit Sample cap.
	 * @return array<string, mixed>
	 */
	public function current_snapshot( $sample_limit = self::SAMPLE_LIMIT ) {
		return $this->directory_state( $sample_limit );
	}

	/**
	 * Return all candidate directories in deterministic order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function scan_candidates() {
		$candidates = array();
		foreach ( $this->glob_directories( $this->uploads_base_dir . '/' . '[0-9][0-9][0-9][0-9]' ) as $year_dir ) {
			$year_norm = wp_normalize_path( $year_dir );
			if ( ! $this->is_valid_year_dir( $year_norm ) ) {
				continue;
			}
			$candidates[] = $this->candidate_record( $year_norm, 'year' );

			foreach ( $this->glob_directories( $year_norm . '/' . '[0-9][0-9]' ) as $month_dir ) {
				$month_norm = wp_normalize_path( $month_dir );
				if ( ! $this->is_valid_month_dir( $month_norm ) ) {
					continue;
				}
				$candidates[] = $this->candidate_record( $month_norm, 'month' );
			}
		}

		usort(
			$candidates,
			static function ( array $a, array $b ) {
				if ( $a['depth'] !== $b['depth'] ) {
					return $a['depth'] > $b['depth'] ? -1 : 1;
				}

				return strcmp( $a['path'], $b['path'] );
			}
		);

		return $candidates;
	}

	/**
	 * @param string $pattern Glob pattern.
	 * @return array<int, string>
	 */
	private function glob_directories( $pattern ) {
		$matches = glob( $pattern, GLOB_ONLYDIR );
		return is_array( $matches ) ? $matches : array();
	}

	/**
	 * @param string $path Absolute path.
	 * @param string $kind Directory kind.
	 * @return array<string, mixed>
	 */
	private function candidate_record( $path, $kind ) {
		$path = wp_normalize_path( $path );
		return array(
			'path'          => $path,
			'relative_path' => ltrim( str_replace( $this->uploads_base_dir, '', $path ), '/\\' ),
			'kind'          => $kind,
			'depth'         => 'month' === $kind ? 2 : 1,
		);
	}

	/**
	 * @param string $path Normalized absolute path.
	 * @return bool
	 */
	private function is_valid_year_dir( $path ) {
		$relative = ltrim( str_replace( $this->uploads_base_dir, '', $path ), '/\\' );
		return (bool) preg_match( '~^[0-9]{4}$~', $relative );
	}

	/**
	 * @param string $path Normalized absolute path.
	 * @return bool
	 */
	private function is_valid_month_dir( $path ) {
		$relative = ltrim( str_replace( $this->uploads_base_dir, '', $path ), '/\\' );
		return (bool) preg_match( '~^[0-9]{4}/[0-9]{2}$~', $relative );
	}

	/**
	 * Evaluate one directory candidate.
	 *
	 * @param array<string, mixed> $candidate Candidate record.
	 * @return array<string, mixed>
	 */
	private function evaluate_directory( array $candidate ) {
		$path = wp_normalize_path( (string) ( $candidate['path'] ?? '' ) );
		if ( '' === $path ) {
			return array( 'status' => 'failed', 'message' => 'Directory path is empty.' );
		}
		if ( 0 !== strpos( $path, $this->uploads_base_dir . '/' ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s is outside uploads.', $candidate['relative_path'] ) );
		}
		if ( $path === $this->uploads_base_dir ) {
			return array( 'status' => 'failed', 'message' => 'Uploads root will never be removed.' );
		}
		if ( $this->is_export_directory( $path ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s is the export directory and will not be removed.', $candidate['relative_path'] ) );
		}
		if ( is_link( $path ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s is a symlink and will not be removed.', $candidate['relative_path'] ) );
		}
		if ( ! file_exists( $path ) ) {
			return array( 'status' => 'already_missing', 'message' => sprintf( 'Directory %s is already missing.', $candidate['relative_path'] ) );
		}
		if ( ! is_dir( $path ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Path %s is not a directory.', $candidate['relative_path'] ) );
		}

		$real = realpath( $path );
		if ( false === $real ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s could not be resolved safely.', $candidate['relative_path'] ) );
		}
		$real = wp_normalize_path( $real );
		if ( 0 !== strpos( $real, $this->uploads_base_dir . '/' ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s resolves outside uploads.', $candidate['relative_path'] ) );
		}

		$relative = ltrim( str_replace( $this->uploads_base_dir, '', $real ), '/\\' );
		if ( ! preg_match( '~^[0-9]{4}(?:/[0-9]{2})?$~', $relative ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s is not an old year/month uploads folder.', $candidate['relative_path'] ) );
		}

		try {
			$iterator = new \FilesystemIterator( $real, \FilesystemIterator::SKIP_DOTS );
			foreach ( $iterator as $ignored ) {
				return array( 'status' => 'not_empty', 'message' => sprintf( 'Directory %s is not empty.', $candidate['relative_path'] ) );
			}
		} catch ( \UnexpectedValueException $exception ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Directory %s could not be inspected: %s', $candidate['relative_path'], $exception->getMessage() ) );
		}

		return array(
			'status'  => 'eligible',
			'message' => sprintf( 'Directory %s is empty and safe to remove.', $candidate['relative_path'] ),
		);
	}

	/**
	 * Remove one eligible directory.
	 *
	 * @param array<string, mixed> $candidate Candidate record.
	 * @param array<string, mixed> &$result Result accumulator.
	 * @param array<string, mixed> $check Safety check.
	 * @return array<string, int>
	 */
	private function remove_directory( array $candidate, array &$result, array $check ) {
		$path = wp_normalize_path( (string) $candidate['path'] );
		if ( ! file_exists( $path ) ) {
			++$result['already_missing_count'];
			$this->log( $result, sprintf( 'Directory %s was already missing at delete time.', $candidate['relative_path'] ) );
			return array( 'removed' => 0 );
		}

		$deleted = false;
		$error = null;
		$handler = static function ( $severity, $message ) use ( &$error ) {
			$error = $message;
			return true;
		};
		set_error_handler( $handler );
		try {
			$deleted = rmdir( $path );
		} catch ( \Throwable $exception ) {
			$deleted = false;
			$error = $exception->getMessage();
		}
		restore_error_handler();
		clearstatcache( true, $path );

		if ( $deleted && ! file_exists( $path ) ) {
			++$result['removed_count'];
			$this->sample( $result['samples']['removed'], $this->sample_directory( $candidate, array( 'status' => 'removed', 'message' => 'Removed.' ) ) );
			$this->log( $result, sprintf( 'Removed empty directory %s.', $candidate['relative_path'] ) );
			return array( 'removed' => 1 );
		}

		if ( ! file_exists( $path ) ) {
			++$result['already_missing_count'];
			$this->log( $result, sprintf( 'Directory %s disappeared before deletion.', $candidate['relative_path'] ) );
			return array( 'removed' => 0 );
		}

		++$result['failed_count'];
		++$result['errors_count'];
		$message = sprintf(
			'Could not remove directory %s%s',
			$candidate['relative_path'],
			$error ? ' (' . $error . ')' : ''
		);
		$result['errors'][] = $message;
		$this->log( $result, $message );
		return array( 'removed' => 0 );
	}

	/**
	 * Check whether a path is the plugin export directory.
	 *
	 * @param string $path Normalized absolute path.
	 * @return bool
	 */
	private function is_export_directory( $path ) {
		$export = wp_normalize_path( $this->exports_dir );
		return 0 === strpos( $path, $export );
	}

	/**
	 * Convert a timestamp-like value to unix time.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private function timestamp_value( $value ) {
		if ( empty( $value ) ) {
			return 0;
		}

		$time = strtotime( (string) $value );
		return false === $time ? 0 : (int) $time;
	}

	/**
	 * Sample one directory.
	 *
	 * @param array<string, mixed> $candidate Candidate record.
	 * @param array<string, mixed> $check Check result.
	 * @return array<string, mixed>
	 */
	private function sample_directory( array $candidate, array $check ) {
		return array(
			'path'          => (string) $candidate['path'],
			'relative_path' => (string) $candidate['relative_path'],
			'kind'          => (string) $candidate['kind'],
			'result'        => (string) $check['status'],
			'message'       => (string) $check['message'],
		);
	}

	/**
	 * Add a sampled row if there is room.
	 *
	 * @param array<int, array<string, mixed>> $sample_list Sample list.
	 * @param array<string, mixed>             $sample      Sample row.
	 * @return void
	 */
	private function sample( array &$sample_list, array $sample ) {
		if ( count( $sample_list ) < self::SAMPLE_LIMIT ) {
			$sample_list[] = $sample;
		}
	}

	/**
	 * Append a log line.
	 *
	 * @param array<string, mixed> &$result Result accumulator.
	 * @param string               $message Message.
	 * @return void
	 */
	private function log( array &$result, $message ) {
		$result['logs'][] = current_time( 'mysql' ) . ' ' . $message;
		$result['logs']   = array_slice( $result['logs'], - self::LOG_LIMIT );
	}

	/**
	 * Append aggregate counts after a batch.
	 *
	 * @param array<string, mixed> &$result Result accumulator.
	 * @return void
	 */
	private function append_counts( array &$result ) {
		$result['warning_count']  = count( $result['warnings'] );
		$result['warnings_count'] = count( $result['warnings'] );
		$result['info_count']     = count( $result['info'] );
	}
}
