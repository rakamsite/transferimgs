<?php

namespace MediaFlattenMigrator;

final class Batch_Migrator {
	const QUERY_BATCH_SIZE = 500;

	/** @var Manifest_Repository */
	private $repository;

	/** @var Single_Attachment_Migrator */
	private $migrator;

	public function __construct( Manifest_Repository $repository, Single_Attachment_Migrator $migrator ) {
		$this->repository = $repository;
		$this->migrator   = $migrator;
	}

	/**
	 * Process up to the requested number of eligible attachments.
	 *
	 * @param int           $batch_size  Maximum eligible attachments to process.
	 * @param int           $start_after Only inspect attachment IDs greater than this value.
	 * @param bool          $dry_run     Whether to avoid all writes and copies.
	 * @param callable|null $callback    Optional result-row callback.
	 * @param int|null      $scan_limit  Optional maximum candidate attachments to inspect.
	 * @return array<string, int|null>
	 */
	public function run( $batch_size, $start_after, $dry_run, $callback = null, $scan_limit = null ) {
		$summary = $this->empty_summary();
		$after_id = (int) $start_after;
		$stop     = false;
		$query_size = null === $scan_limit
			? self::QUERY_BATCH_SIZE
			: min( self::QUERY_BATCH_SIZE, max( 1, (int) $scan_limit ) );

		do {
			$candidates = $this->repository->get_attachment_summaries( $after_id, $query_size );

			foreach ( $candidates as $candidate ) {
				$attachment_id = (int) $candidate['attachment_id'];
				$after_id      = $attachment_id;
				$classification = $this->classify( $candidate );

				++$summary['scanned_candidate_attachments'];
				if ( null === $summary['first_processed_attachment_id'] ) {
					$summary['first_processed_attachment_id'] = $attachment_id;
				}
				$summary['last_processed_attachment_id'] = $attachment_id;

				if ( 'eligible' !== $classification ) {
					++$summary[ 'skipped_' . $classification ];
					$this->emit(
						$callback,
						$attachment_id,
						'skipped',
						$classification
					);
					if ( null !== $scan_limit && $summary['scanned_candidate_attachments'] >= (int) $scan_limit ) {
						$stop = true;
						break;
					}
					continue;
				}

				if ( $summary['eligible_attachments'] >= $batch_size ) {
					$stop = true;
					break;
				}

				$this->emit( $callback, $attachment_id, $dry_run ? 'preflight' : 'processing', 'eligible manifest rows' );

				try {
					$plan = $this->migrator->plan( $attachment_id );
					if ( ! $plan['allowed'] || $plan['all_migrated'] ) {
						$reason = $plan['errors'] ? implode( ' ', $plan['errors'] ) : 'Attachment is not eligible after preflight.';

						if ( $dry_run ) {
							++$summary['skipped_preflight_blocked'];
							$this->emit( $callback, $attachment_id, 'skipped', $reason );
						} else {
							$row_ids = array_map(
								static function ( $row ) {
									return (int) $row['id'];
								},
								$plan['rows']
							);
							if ( $row_ids ) {
								$this->repository->set_rows_status( $row_ids, 'failed', $reason );
							}
							++$summary['failed_attachments'];
							$this->emit( $callback, $attachment_id, 'failed', $reason );
						}
						if ( null !== $scan_limit && $summary['scanned_candidate_attachments'] >= (int) $scan_limit ) {
							$stop = true;
							break;
						}
						continue;
					}

					++$summary['eligible_attachments'];

					if ( $dry_run ) {
						++$summary['would_migrate_attachments'];
						$this->emit( $callback, $attachment_id, 'would_migrate', 'eligible' );
					} else {
						$this->migrator->migrate( $plan );
						++$summary['migrated_attachments'];
						$this->emit( $callback, $attachment_id, 'migrated', 'success' );
					}
				} catch ( \Throwable $exception ) {
					$reason = $exception->getMessage();
					if ( ! $dry_run ) {
						try {
							$rows = $this->repository->get_attachment_rows( $attachment_id );
							$row_ids = array_map(
								static function ( $row ) {
									return (int) $row['id'];
								},
								$rows
							);
							if ( $row_ids ) {
								$this->repository->set_rows_status( $row_ids, 'failed', $reason );
							}
						} catch ( \Throwable $status_exception ) {
							$reason .= ' Manifest failure status could not be saved: ' . $status_exception->getMessage();
						}
					}

					++$summary['failed_attachments'];
					$this->emit( $callback, $attachment_id, 'failed', $reason );
				}

				if ( $summary['eligible_attachments'] >= $batch_size ) {
					$stop = true;
					break;
				}
				if ( null !== $scan_limit && $summary['scanned_candidate_attachments'] >= (int) $scan_limit ) {
					$stop = true;
					break;
				}
			}
		} while ( ! $stop && count( $candidates ) === $query_size );

		$summary['expected_batch_count'] = $summary['eligible_attachments'];

		return $summary;
	}

	/**
	 * Return attachment-level migration readiness totals.
	 *
	 * @return array<string, int>
	 */
	public function report_counts() {
		$counts   = array(
			'eligible_attachments_ready'       => 0,
			'migrated_attachments'             => 0,
			'already_migrated_attachments'     => 0,
			'attachments_blocked_missing'      => 0,
			'attachments_blocked_collision'    => 0,
			'failed_attachments'               => 0,
			'remaining_resolved_attachments'   => 0,
			'attachments_incomplete_targets'   => 0,
			'attachments_other_ineligible'     => 0,
		);
		if ( ! $this->repository->table_exists() ) {
			return $counts;
		}

		$after_id = 0;

		do {
			$candidates = $this->repository->get_attachment_summaries( $after_id, self::QUERY_BATCH_SIZE );

			foreach ( $candidates as $candidate ) {
				$after_id  = (int) $candidate['attachment_id'];
				$row_count = (int) $candidate['row_count'];
				$matched   = false;

				if ( $row_count > 0 && (int) $candidate['resolved_rows'] === $row_count
					&& 0 === (int) $candidate['incomplete_target_rows']
				) {
					++$counts['eligible_attachments_ready'];
					++$counts['remaining_resolved_attachments'];
					$matched = true;
				}
				if ( $row_count > 0 && (int) $candidate['migrated_rows'] === $row_count ) {
					++$counts['migrated_attachments'];
					++$counts['already_migrated_attachments'];
					$matched = true;
				}
				if ( (int) $candidate['missing_rows'] > 0 ) {
					++$counts['attachments_blocked_missing'];
					$matched = true;
				}
				if ( (int) $candidate['collision_rows'] > 0 ) {
					++$counts['attachments_blocked_collision'];
					$matched = true;
				}
				if ( (int) $candidate['failed_rows'] > 0 ) {
					++$counts['failed_attachments'];
					$matched = true;
				}
				if ( (int) $candidate['incomplete_target_rows'] > 0 ) {
					++$counts['attachments_incomplete_targets'];
					$matched = true;
				}
				if ( ! $matched ) {
					++$counts['attachments_other_ineligible'];
				}
			}
		} while ( count( $candidates ) === self::QUERY_BATCH_SIZE );

		return $counts;
	}

	/**
	 * @param array<string, mixed> $candidate Attachment manifest summary.
	 * @return string
	 */
	private function classify( array $candidate ) {
		$row_count = (int) $candidate['row_count'];

		if ( $row_count > 0 && (int) $candidate['migrated_rows'] === $row_count ) {
			return 'already_migrated';
		}
		if ( (int) $candidate['missing_rows'] > 0 ) {
			return 'missing';
		}
		if ( (int) $candidate['collision_rows'] > 0 ) {
			return 'blocked_collision';
		}
		if ( (int) $candidate['failed_rows'] > 0 ) {
			return 'failed';
		}
		if ( (int) $candidate['incomplete_target_rows'] > 0 ) {
			return 'incomplete_targets';
		}
		if ( $row_count > 0 && (int) $candidate['resolved_rows'] === $row_count ) {
			return 'eligible';
		}

		return 'other_ineligible';
	}

	/**
	 * @return array<string, int|null>
	 */
	private function empty_summary() {
		return array(
			'scanned_candidate_attachments' => 0,
			'eligible_attachments'          => 0,
			'would_migrate_attachments'     => 0,
			'migrated_attachments'          => 0,
			'skipped_missing'               => 0,
			'skipped_blocked_collision'     => 0,
			'skipped_already_migrated'      => 0,
			'skipped_failed'                => 0,
			'skipped_incomplete_targets'    => 0,
			'skipped_other_ineligible'      => 0,
			'skipped_preflight_blocked'     => 0,
			'failed_attachments'            => 0,
			'first_processed_attachment_id' => null,
			'last_processed_attachment_id'  => null,
			'expected_batch_count'          => 0,
		);
	}

	/**
	 * @param callable|null $callback      Optional callback.
	 * @param int           $attachment_id Attachment ID.
	 * @param string        $result        Processing result.
	 * @param string        $reason        Result reason.
	 * @return void
	 */
	private function emit( $callback, $attachment_id, $result, $reason ) {
		if ( is_callable( $callback ) ) {
			call_user_func(
				$callback,
				array(
					'attachment_id' => $attachment_id,
					'result'        => $result,
					'reason'        => $reason,
				)
			);
		}
	}
}
