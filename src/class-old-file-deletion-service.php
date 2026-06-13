<?php

namespace MediaFlattenMigrator;

final class Old_File_Deletion_Service {
	const STATE_OPTION = 'media_flatten_last_delete_result';
	const SAMPLE_LIMIT  = 20;
	const LOG_LIMIT     = 500;

	/** @var Manifest_Repository */
	private $repository;

	/** @var string */
	private $uploads_base_dir;

	/** @var string */
	private $uploads_base_url;

	public function __construct( Manifest_Repository $repository ) {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'WordPress could not determine the uploads directory: ' . $uploads['error'] );
		}

		$this->repository       = $repository;
		$this->uploads_base_dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$this->uploads_base_url = untrailingslashit( (string) $uploads['baseurl'] );
	}

	/**
	 * Read the latest stored deletion result.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Return a lightweight readiness summary from the latest stored state.
	 *
	 * @return array<string, mixed>
	 */
	public function readiness() {
		$verify  = get_option( Admin_Controller::VERIFY_RESULT_OPTION, array() );
		$audit   = get_option( Admin_Controller::OLD_URL_AUDIT_RESULT_OPTION, array() );
		$redirect = ( new Redirect_Export_Service( $this->repository ) )->readiness();
		$state   = $this->state();
		$counts  = $this->repository->status_counts();
		$status_counts = array();
		foreach ( $counts as $row ) {
			$status_counts[ $row['status'] ] = (int) $row['item_count'];
		}

		$duplicate_groups = $this->repository->get_duplicate_logical_groups();
		$delete_ready = ! empty( $verify['pass'] )
			&& ! empty( $verify['pre_redirect_ready'] )
			&& ! empty( $verify['redirect_export_ready'] )
			&& ! empty( $audit['safe'] )
			&& ! empty( $redirect['ready'] )
			&& 0 === (int) $this->repository->count_invalid_migrated_url_rows()
			&& empty( $status_counts['copying'] )
			&& empty( $status_counts['copied'] )
			&& empty( $status_counts['failed'] )
			&& empty( $duplicate_groups )
			&& 0 === (int) $this->repository->count_failed_old_file_deletions();

		return array(
			'ready'                      => $delete_ready,
			'delete_old_files_ready'     => $delete_ready,
			'verify'                     => $verify,
			'old_url_audit'              => $audit,
			'redirect_readiness'         => $redirect,
			'status_counts'              => $status_counts,
			'duplicate_manifest_rows'    => count( $duplicate_groups ),
			'eligible_count'             => $this->repository->count_deletion_candidates(),
			'deleted_count'              => $this->repository->count_deleted_old_files(),
			'already_missing_count'      => $this->repository->count_already_missing_old_files(),
			'failed_count'               => $this->repository->count_failed_old_file_deletions(),
			'remaining_count'            => $this->repository->count_deletion_candidates(),
			'bytes_eligible'             => (int) ( $state['bytes_eligible'] ?? 0 ),
			'bytes_freed'                => (int) ( $state['bytes_freed'] ?? 0 ),
			'last_batch_at'              => $state['last_batch_at'] ?? null,
			'last_manifest_id'           => (int) ( $state['last_manifest_id'] ?? 0 ),
			'latest_errors'              => isset( $state['errors'] ) && is_array( $state['errors'] ) ? $state['errors'] : array(),
			'latest_warnings'            => isset( $state['warnings'] ) && is_array( $state['warnings'] ) ? $state['warnings'] : array(),
			'logs'                       => isset( $state['logs'] ) && is_array( $state['logs'] ) ? $state['logs'] : array(),
			'generated_at'               => $state['generated_at'] ?? null,
			'completed_at'               => $state['completed_at'] ?? null,
		);
	}

	/**
	 * Run a read-only dry-run report across all eligible rows, optionally storing it.
	 *
	 * @param int  $batch_size Batch size.
	 * @param bool $store      Whether to persist the report.
	 * @return array<string, mixed>
	 */
	public function report( $batch_size = 100, $store = true ) {
		$result = $this->initial_result();
		$after  = 0;
		do {
			$batch  = $this->run_batch( $after, $batch_size, $result, true );
			$result = $batch['result'];
			$after  = $batch['last_manifest_id'];
		} while ( ! $batch['done'] );

		$result = $this->finalize( $result, true );
		$gate   = $this->readiness();
		$result['deleted_count']         = $gate['deleted_count'];
		$result['already_missing_count'] = $gate['already_missing_count'];
		$result['failed_count']          = $gate['failed_count'] + (int) $result['failed_count'];
		$result['remaining_count']       = $gate['remaining_count'];
		$result['eligible_count']        = max( (int) $result['eligible_count'], (int) $gate['eligible_count'] );
		$result['delete_old_files_ready'] = $gate['delete_old_files_ready'] && 0 === (int) $result['failed_count'];
		$result['ready']                 = $result['delete_old_files_ready'];
		$result['pass']                  = $result['ready'];
		$result['skipped_count']         = (int) $result['already_missing_count'] + (int) $result['failed_count'];
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
			'pass'                 => false,
			'delete_old_files_ready' => false,
			'ready'                => false,
			'generated_at'         => null,
			'completed_at'         => null,
			'last_batch_at'        => null,
			'last_manifest_id'     => 0,
			'processed'            => 0,
			'eligible_count'       => 0,
			'deleted_count'        => 0,
			'already_missing_count' => 0,
			'failed_count'         => 0,
			'skipped_count'        => 0,
			'remaining_count'      => 0,
			'bytes_eligible'       => 0,
			'bytes_freed'          => 0,
			'status_counts'        => array(),
			'duplicate_manifest_rows' => 0,
			'verify'               => array(),
			'old_url_audit'        => array(),
			'redirect_readiness'   => array(),
			'errors_count'         => 0,
			'warnings_count'       => 0,
			'info_count'           => 0,
			'errors'               => array(),
			'warnings'             => array(),
			'info'                 => array(),
			'samples'              => array(
				'eligible'        => array(),
				'already_missing' => array(),
				'failed'          => array(),
				'skipped'         => array(),
			),
			'logs'                 => array(),
		);
	}

	/**
	 * Estimate the total number of manifest rows to inspect.
	 *
	 * @return int
	 */
	public function estimate_total() {
		return $this->repository->count_deletion_candidates();
	}

	/**
	 * Run one deletion batch.
	 *
	 * @param int                  $after_id  Cursor.
	 * @param int                  $limit     Batch size.
	 * @param array<string, mixed> $result    Result accumulator.
	 * @param bool                 $dry_run   Dry-run flag.
	 * @return array<string, mixed>
	 */
	public function run_batch( $after_id, $limit, array $result, $dry_run ) {
		$rows = $this->repository->get_deletion_rows( $after_id, $limit );
		$batch_eligible = 0;
		$batch_deleted = 0;
		$batch_already_missing = 0;
		$batch_failed = 0;
		$batch_bytes_eligible = 0;
		$batch_bytes_freed = 0;
		foreach ( $rows as $row ) {
			$after_id = (int) $row['id'];
			$result['processed']++;

			$check = $this->evaluate_row( $row );
			if ( 'eligible' === $check['status'] ) {
				$result['eligible_count']++;
				$batch_eligible++;
				$batch_bytes_eligible += (int) $check['bytes'];
				$result['bytes_eligible'] += (int) $check['bytes'];
				$this->sample( $result['samples']['eligible'], $this->sample_row( $row, $check ) );
				$this->log( $result, sprintf( 'Manifest row %d is eligible for old-file deletion.', $row['id'] ) );

				if ( ! $dry_run ) {
					$delete = $this->delete_row_file( $row, $result, $check );
					$batch_deleted += $delete['deleted'];
					$batch_bytes_freed += $delete['bytes'];
				}
				continue;
			}

			if ( 'already_missing' === $check['status'] ) {
				$result['already_missing_count']++;
				$batch_already_missing++;
				$this->sample( $result['samples']['already_missing'], $this->sample_row( $row, $check ) );
				$this->log( $result, sprintf( 'Manifest row %d old file is already missing.', $row['id'] ) );
				if ( ! $dry_run ) {
					$this->repository->update_old_delete_status( (int) $row['id'], 'already_missing', null );
				}
				continue;
			}

			$result['failed_count']++;
			$batch_failed++;
			$result['errors_count']++;
			$this->sample( $result['samples']['failed'], $this->sample_row( $row, $check ) );
			$result['errors'][] = $check['message'];
			$this->log( $result, $check['message'] );
			if ( ! $dry_run ) {
				$this->repository->update_old_delete_status( (int) $row['id'], 'failed', $check['message'] );
			}
		}

		$result['last_manifest_id'] = (int) $after_id;
		$result['remaining_count']  = $this->repository->count_deletion_candidates();
		$result['last_batch_at']    = current_time( 'mysql' );
		$this->append_counts( $result );

		return array(
			'result'            => $result,
			'processed'         => count( $rows ),
			'last_manifest_id'   => (int) $after_id,
			'done'              => count( $rows ) < max( 1, (int) $limit ),
			'summary'           => array(
				'processed'         => count( $rows ),
				'eligible'          => $batch_eligible,
				'deleted'           => $batch_deleted,
				'already_missing'   => $batch_already_missing,
				'failed'            => $batch_failed,
				'skipped'           => $batch_already_missing + $batch_failed,
				'bytes_eligible'    => $batch_bytes_eligible,
				'bytes_freed'       => $batch_bytes_freed,
				'last_manifest_id'  => (int) $after_id,
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
		$result['status_counts']     = $gate['status_counts'];
		$result['duplicate_manifest_rows'] = $gate['duplicate_manifest_rows'];
		$result['delete_old_files_ready'] = $gate['delete_old_files_ready'];
		$result['ready']             = $gate['delete_old_files_ready']
			&& 0 === (int) $result['failed_count']
			&& 0 === (int) $gate['failed_count']
			&& 0 === (int) $gate['duplicate_manifest_rows']
			&& empty( $gate['status_counts']['copying'] )
			&& empty( $gate['status_counts']['copied'] )
			&& empty( $gate['status_counts']['failed'] )
			&& empty( $gate['verify']['missing_new_files'] )
			&& empty( $gate['verify']['integrity_mismatches'] )
			&& empty( $gate['verify']['metadata_errors'] )
			&& empty( $gate['old_url_audit']['migrated_mapping_old_url_remaining'] )
			&& empty( $gate['old_url_audit']['non_migrated_manifest_url_remaining'] )
			&& empty( $gate['old_url_audit']['orphan_old_upload_url_remaining'] );
		$result['pass']             = $result['ready'];
		$result['completed_at']     = $read_only ? null : current_time( 'mysql' );
		$result['generated_at']     = $result['generated_at'] ?? current_time( 'mysql' );
		$this->append_counts( $result );
		return $result;
	}

	/**
	 * Store the latest deletion result.
	 *
	 * @param array<string, mixed> $result Result.
	 * @return void
	 */
	public function store_state( array $result ) {
		$state = $this->state();
		$state['generated_at']          = $result['generated_at'] ?? current_time( 'mysql' );
		$state['completed_at']          = $result['completed_at'] ?? null;
		$state['last_batch_at']         = $result['last_batch_at'] ?? null;
		$state['last_manifest_id']      = $result['last_manifest_id'] ?? 0;
		$state['ready']                 = ! empty( $result['ready'] );
		$state['delete_old_files_ready'] = ! empty( $result['delete_old_files_ready'] );
		$state['eligible_count']        = (int) ( $result['eligible_count'] ?? 0 );
		$state['deleted_count']         = (int) ( $result['deleted_count'] ?? 0 );
		$state['already_missing_count'] = (int) ( $result['already_missing_count'] ?? 0 );
		$state['failed_count']          = (int) ( $result['failed_count'] ?? 0 );
		$state['remaining_count']       = (int) ( $result['remaining_count'] ?? 0 );
		$state['bytes_eligible']        = (int) ( $result['bytes_eligible'] ?? 0 );
		$state['bytes_freed']           = (int) ( $result['bytes_freed'] ?? 0 );
		$state['warnings']              = $result['warnings'] ?? array();
		$state['errors']                = $result['errors'] ?? array();
		$state['info']                  = $result['info'] ?? array();
		$state['samples']               = $result['samples'] ?? array();
		$state['logs']                  = array_slice( isset( $result['logs'] ) && is_array( $result['logs'] ) ? $result['logs'] : array(), - self::LOG_LIMIT );
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Return whether a row can be deleted or not.
	 *
	 * @param array<string, mixed> $row Manifest row.
	 * @return array<string, mixed>
	 */
	private function evaluate_row( array $row ) {
		$old_abs = wp_normalize_path( (string) ( $row['old_abs_path'] ?? '' ) );
		$new_abs = wp_normalize_path( (string) ( $row['new_abs_path'] ?? '' ) );
		$old_rel = str_replace( '\\', '/', ltrim( str_replace( $this->uploads_base_dir, '', $old_abs ), '/' ) );

		if ( '' === $old_abs || '' === $new_abs ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d is missing deletion paths.', $row['id'] ) );
		}
		if ( 0 !== strpos( $old_abs, $this->uploads_base_dir . '/' ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d old path is outside uploads.', $row['id'] ) );
		}
		if ( ! preg_match( '~/[0-9]{4}/[0-9]{2}/~', '/' . $old_rel ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d old path is not in a dated uploads folder.', $row['id'] ) );
		}
		if ( $old_abs === $new_abs ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d old and new paths are identical.', $row['id'] ) );
		}
		if ( is_link( $old_abs ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d old file is a symlink and will not be deleted.', $row['id'] ) );
		}
		if ( ! file_exists( $new_abs ) ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d new file is missing.', $row['id'] ) );
		}
		if ( ! file_exists( $old_abs ) ) {
			return array( 'status' => 'already_missing', 'message' => sprintf( 'Manifest row %d old file is already missing.', $row['id'] ) );
		}
		clearstatcache( true, $old_abs );
		clearstatcache( true, $new_abs );
		$old_size = @filesize( $old_abs );
		$new_size = @filesize( $new_abs );
		if ( false === $old_size || false === $new_size || $old_size !== $new_size ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d file size mismatch blocks deletion.', $row['id'] ) );
		}
		$old_md5 = @md5_file( $old_abs );
		$new_md5 = @md5_file( $new_abs );
		if ( false === $old_md5 || false === $new_md5 || $old_md5 !== $new_md5 ) {
			return array( 'status' => 'failed', 'message' => sprintf( 'Manifest row %d checksum mismatch blocks deletion.', $row['id'] ) );
		}

		return array(
			'status'  => 'eligible',
			'message' => sprintf( 'Manifest row %d is safe to delete.', $row['id'] ),
			'bytes'   => (int) $old_size,
		);
	}

	/**
	 * Delete one eligible row's file.
	 *
	 * @param array<string, mixed> $row Manifest row.
	 * @param array<string, mixed> &$result Result accumulator.
	 * @param array<string, mixed> $check Preflight check.
	 * @return array<string, int>
	 */
	private function delete_row_file( array $row, array &$result, array $check ) {
		$old_abs = wp_normalize_path( (string) $row['old_abs_path'] );
		$bytes   = (int) ( $check['bytes'] ?? 0 );

		if ( @unlink( $old_abs ) ) {
			$result['deleted_count']++;
			$result['bytes_freed'] += $bytes;
			$this->repository->update_old_delete_status( (int) $row['id'], 'deleted', null );
			$this->sample( $result['samples']['eligible'], array_merge( $this->sample_row( $row, $check ), array( 'result' => 'deleted' ) ) );
			$this->log( $result, sprintf( 'Deleted old file for manifest row %d: %s', $row['id'], $row['old_rel_path'] ) );
			return array( 'deleted' => 1, 'bytes' => $bytes );
		}

		$result['failed_count']++;
		$result['errors_count']++;
		$message = sprintf( 'Could not delete old file for manifest row %d: %s', $row['id'], $row['old_rel_path'] );
		$result['errors'][] = $message;
		$this->repository->update_old_delete_status( (int) $row['id'], 'failed', $message );
		$this->log( $result, $message );
		return array( 'deleted' => 0, 'bytes' => 0 );
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
	 * @param array<string, mixed> $row Manifest row.
	 * @param array<string, mixed> $check Preflight check.
	 * @return array<string, mixed>
	 */
	private function sample_row( array $row, array $check ) {
		return array(
			'manifest_id'  => (int) $row['id'],
			'attachment_id'=> (int) $row['attachment_id'],
			'old_rel_path' => (string) $row['old_rel_path'],
			'new_rel_path' => (string) $row['new_rel_path'],
			'old_abs_path' => (string) $row['old_abs_path'],
			'new_abs_path' => (string) $row['new_abs_path'],
			'result'       => (string) $check['status'],
			'message'      => (string) $check['message'],
		);
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
		$result['skipped_count'] = (int) $result['already_missing_count'] + (int) $result['failed_count'];
		$result['warnings_count'] = count( $result['warnings'] );
		$result['info_count']     = count( $result['info'] );
	}
}
