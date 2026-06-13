<?php

namespace MediaFlattenMigrator;

final class Final_Migration_Report_Service {
	const STATE_OPTION = 'media_flatten_last_final_report_result';
	const SAMPLE_LIMIT = 20;

	/** @var Manifest_Repository */
	private $repository;

	public function __construct( Manifest_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Read the latest stored final migration report.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Generate a fresh final migration report and optionally store it.
	 *
	 * @param int  $sample_limit Sample limit for the directory snapshot.
	 * @param bool $store        Whether to persist the report.
	 * @return array<string, mixed>
	 */
	public function run( $sample_limit = self::SAMPLE_LIMIT, $store = true ) {
		$verify         = get_option( Admin_Controller::VERIFY_RESULT_OPTION, array() );
		$audit          = get_option( Admin_Controller::OLD_URL_AUDIT_RESULT_OPTION, array() );
		$redirect       = ( new Redirect_Export_Service( $this->repository ) )->readiness();
		$delete         = ( new Old_File_Deletion_Service( $this->repository ) )->readiness();
		$cleanup_service = new Empty_Directory_Cleanup_Service( $this->repository );
		$cleanup_snapshot = $cleanup_service->current_snapshot( $sample_limit );
		$cleanup_state   = $cleanup_service->state();
		$status_counts   = $this->repository->status_counts();
		$counts          = array();
		foreach ( $status_counts as $row ) {
			$counts[ $row['status'] ] = (int) $row['item_count'];
		}

		$remaining_old_url_occurrences = (int) ( $audit['migrated_mapping_old_url_remaining'] ?? 0 )
			+ (int) ( $audit['non_migrated_manifest_url_remaining'] ?? 0 )
			+ (int) ( $audit['orphan_old_upload_url_remaining'] ?? 0 );
		$old_files_remaining = (int) ( $delete['eligible_count'] ?? 0 );
		$old_file_deletion_failures = (int) ( $delete['failed_count'] ?? 0 );
		$cleanup_failures = (int) ( $cleanup_state['failed_count'] ?? 0 );
		$empty_directories_remaining = (int) ( $cleanup_snapshot['empty_month_dirs'] ?? 0 ) + (int) ( $cleanup_snapshot['empty_year_dirs'] ?? 0 );
		$non_empty_directories_remaining = (int) ( $cleanup_snapshot['non_empty_month_dirs'] ?? 0 ) + (int) ( $cleanup_snapshot['non_empty_year_dirs'] ?? 0 );
		$unsafe_directories_remaining = (int) ( $cleanup_snapshot['unsafe'] ?? 0 );
		$dir_ready = 0 === $empty_directories_remaining && 0 === $non_empty_directories_remaining;
		$pass = ! empty( $verify['pass'] )
			&& ! empty( $audit['safe'] )
			&& ! empty( $redirect['redirect_export_ready'] )
			&& 0 === $remaining_old_url_occurrences
			&& 0 === $old_files_remaining
			&& 0 === $old_file_deletion_failures
			&& 0 === $cleanup_failures
			&& 0 === $unsafe_directories_remaining
			&& $dir_ready
			&& 0 === (int) $this->repository->count_invalid_migrated_url_rows()
			&& 0 === (int) ( $counts['copying'] ?? 0 )
			&& 0 === (int) ( $counts['copied'] ?? 0 )
			&& 0 === (int) ( $counts['failed'] ?? 0 )
		&& 0 === (int) $this->repository->count_failed_old_file_deletions()
		&& 0 === count( $this->repository->get_duplicate_logical_groups() );

		$result = array(
			'pass'                        => $pass,
			'status'                      => $pass ? 'PASS' : 'NOT READY',
			'ready'                       => $pass,
			'generated_at'                => current_time( 'mysql' ),
			'verified_at'                 => $verify['verified_at'] ?? null,
			'audited_at'                  => $audit['audited_at'] ?? null,
			'redirect_export_at'          => $redirect['generated_at'] ?? ( $redirect['redirect_export_status']['generated_at'] ?? null ),
			'last_old_file_deletion_at'   => $delete['completed_at'] ?? ( $delete['last_batch_at'] ?? null ),
			'last_cleanup_at'             => $cleanup_state['last_cleanup_at'] ?? ( $cleanup_state['completed_at'] ?? null ),
			'errors_count'                => 0,
			'warnings_count'              => 0,
			'info_count'                  => 0,
			'status_counts'               => $counts,
			'total_manifest_rows'         => $this->repository->count_rows(),
			'total_migrated_rows'         => $this->repository->count_migrated_rows(),
			'missing_rows'                => (int) ( $counts['missing'] ?? 0 ),
			'blocked_collision_rows'      => (int) ( $counts['blocked_collision'] ?? 0 ),
			'failed_rows'                 => (int) ( $counts['failed'] ?? 0 ),
			'deleted_old_files'           => (int) ( $delete['deleted_count'] ?? 0 ),
			'old_files_remaining'         => $old_files_remaining,
			'old_file_deletion_failures'   => $old_file_deletion_failures,
			'empty_directories_removed'    => (int) ( $cleanup_state['removed_count'] ?? 0 ),
			'empty_month_directories_remaining' => (int) ( $cleanup_snapshot['empty_month_dirs'] ?? 0 ),
			'empty_year_directories_remaining'  => (int) ( $cleanup_snapshot['empty_year_dirs'] ?? 0 ),
			'non_empty_month_directories_remaining' => (int) ( $cleanup_snapshot['non_empty_month_dirs'] ?? 0 ),
			'non_empty_year_directories_remaining'  => (int) ( $cleanup_snapshot['non_empty_year_dirs'] ?? 0 ),
			'unsafe_directories_remaining'   => $unsafe_directories_remaining,
			'cleanup_failures'               => $cleanup_failures,
			'old_yyyy_mm_directories_remaining' => $empty_directories_remaining + $non_empty_directories_remaining,
			'remaining_old_url_occurrences' => $remaining_old_url_occurrences,
			'redirect_export_status'       => $redirect['redirect_export_status'] ?? array(),
			'redirect_preview_status'      => $redirect['redirect_preview_status'] ?? array(),
			'verify_pass'                  => ! empty( $verify['pass'] ),
			'old_url_audit_safe'          => ! empty( $audit['safe'] ),
			'redirect_export_ready'       => ! empty( $redirect['redirect_export_ready'] ),
			'delete_old_files_ready'      => ! empty( $delete['ready'] ),
			'cleanup_ready'               => $dir_ready && 0 === $cleanup_failures && 0 === $unsafe_directories_remaining,
			'cleanup_snapshot'            => $cleanup_snapshot,
			'delete_readiness'            => $delete,
			'cleanup_state'               => $cleanup_state,
			'verify'                      => $verify,
			'old_url_audit'               => $audit,
			'redirect_readiness'          => $redirect,
			'errors'                      => array(),
			'warnings'                    => array(),
			'info'                        => array(),
			'sample_errors'               => array(),
			'sample_warnings'             => array(),
		);

		$result['warnings'] = $this->build_warnings( $result );
		$result['warnings_count'] = count( $result['warnings'] );

		if ( $store ) {
			update_option( self::STATE_OPTION, $result, false );
		}

		return $result;
	}

	/**
	 * Build human readable warnings for the final report.
	 *
	 * @param array<string, mixed> $result Final report data.
	 * @return array<int, string>
	 */
	private function build_warnings( array $result ) {
		$warnings = array();
		if ( empty( $result['verify_pass'] ) ) {
			$warnings[] = 'Verification has not passed.';
		}
		if ( empty( $result['old_url_audit_safe'] ) ) {
			$warnings[] = 'Old URL audit has not passed.';
		}
		if ( empty( $result['redirect_export_ready'] ) ) {
			$warnings[] = 'A successful final redirect export is still required.';
		}
		if ( 0 !== (int) $result['remaining_old_url_occurrences'] ) {
			$warnings[] = 'Old dated upload URLs still remain in database content.';
		}
		if ( 0 !== (int) $result['old_files_remaining'] ) {
			$warnings[] = 'Some migrated files are still eligible for old-file deletion.';
		}
		if ( 0 !== (int) $result['old_file_deletion_failures'] ) {
			$warnings[] = 'Some old-file deletions failed and still need attention.';
		}
		if ( 0 !== (int) $result['old_yyyy_mm_directories_remaining'] ) {
			$warnings[] = 'Old year/month upload directories still remain.';
		}
		if ( 0 !== (int) $result['cleanup_failures'] ) {
			$warnings[] = 'Some empty-directory cleanup attempts failed and still need attention.';
		}
		if ( 0 !== (int) $result['unsafe_directories_remaining'] ) {
			$warnings[] = 'Some old upload directories are still unsafe to clean up.';
		}
		if ( ! empty( $result['non_empty_month_directories_remaining'] ) || ! empty( $result['non_empty_year_directories_remaining'] ) ) {
			$warnings[] = 'Some old upload directories are still not empty.';
		}

		return $warnings;
	}
}
