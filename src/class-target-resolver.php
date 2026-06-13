<?php

namespace MediaFlattenMigrator;

final class Target_Resolver {
	const BATCH_SIZE = 500;

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
		$this->uploads_base_url = untrailingslashit( $uploads['baseurl'] );
	}

	/**
	 * Resolve every non-migrated manifest target.
	 *
	 * @param bool          $dry_run       Whether to avoid database writes.
	 * @param callable|null $batch_callback Optional callback for each resolved batch.
	 * @return array<string, mixed>
	 */
	public function resolve( $dry_run, $batch_callback = null ) {
		$target_counts = $this->count_targets();
		$summary       = array(
			'processed'         => 0,
			'resolved'          => 0,
			'missing'           => 0,
			'blocked_collision' => 0,
			'updated'           => 0,
		);
		$after_id      = 0;

		do {
			$rows = $this->repository->get_non_migrated_rows(
				$after_id,
				self::BATCH_SIZE
			);
			$batch_results = array();

			foreach ( $rows as $row ) {
				$after_id = (int) $row['id'];
				$target   = $this->resolve_row( $row, $target_counts );

				++$summary['processed'];
				++$summary[ $target['status'] ];

				$batch_results[] = array_merge(
					array(
						'id'            => $row['id'],
						'attachment_id' => $row['attachment_id'],
						'old_rel_path'  => $row['old_rel_path'],
					),
					$target
				);

				if ( ! $dry_run ) {
					if ( $this->target_changed( $row, $target ) ) {
						$this->repository->update_target( $row['id'], $target );
						++$summary['updated'];
					}
				}
			}

			if ( $batch_results && is_callable( $batch_callback ) ) {
				call_user_func( $batch_callback, $batch_results );
			}
		} while ( count( $rows ) === self::BATCH_SIZE );

		return array(
			'summary' => $summary,
		);
	}

	/**
	 * Resolve one bounded manifest batch.
	 *
	 * @param int  $after_id Cursor manifest ID.
	 * @param int  $limit    Maximum rows.
	 * @param bool $dry_run  Whether to avoid writes.
	 * @return array<string, mixed>
	 */
	public function resolve_batch( $after_id, $limit, $dry_run ) {
		$limit         = max( 1, (int) $limit );
		$target_counts = $this->count_targets();
		$rows          = $this->repository->get_non_migrated_rows( $after_id, $limit );
		$summary       = array(
			'processed'         => 0,
			'resolved'          => 0,
			'missing'           => 0,
			'blocked_collision' => 0,
			'updated'           => 0,
		);
		$results       = array();

		foreach ( $rows as $row ) {
			$after_id = (int) $row['id'];
			$target   = $this->resolve_row( $row, $target_counts );
			++$summary['processed'];
			++$summary[ $target['status'] ];

			if ( ! $dry_run && $this->target_changed( $row, $target ) ) {
				$this->repository->update_target( $row['id'], $target );
				++$summary['updated'];
			}

			$results[] = array_merge(
				array(
					'id'            => $row['id'],
					'attachment_id' => $row['attachment_id'],
					'old_rel_path'  => $row['old_rel_path'],
				),
				$target
			);
		}

		return array(
			'summary'           => $summary,
			'rows'              => $results,
			'last_processed_id' => (int) $after_id,
			'done'              => count( $rows ) < $limit,
		);
	}

	/**
	 * Count exact target filenames across every non-migrated manifest row.
	 *
	 * @return array<string, int>
	 */
	private function count_targets() {
		$counts   = array();
		$after_id = 0;

		do {
			$rows = $this->repository->get_non_migrated_rows( $after_id, self::BATCH_SIZE );

			foreach ( $rows as $row ) {
				$after_id = (int) $row['id'];
				$basename = $this->exact_basename( $row['old_rel_path'] );

				if ( '' !== $basename ) {
					$counts[ $basename ] = isset( $counts[ $basename ] ) ? $counts[ $basename ] + 1 : 1;
				}
			}
		} while ( count( $rows ) === self::BATCH_SIZE );

		return $counts;
	}

	/**
	 * @param array<string, mixed> $row           Manifest row.
	 * @param array<string, int>   $target_counts Target filename counts.
	 * @return array<string, mixed>
	 */
	private function resolve_row( array $row, array $target_counts ) {
		$new_rel_path = $this->exact_basename( $row['old_rel_path'] );
		$new_abs_path = $this->uploads_base_dir . '/' . $new_rel_path;
		$new_url      = $this->uploads_base_url . '/' . $new_rel_path;
		$source_path  = wp_normalize_path( (string) $row['old_abs_path'] );
		$target_path  = wp_normalize_path( $new_abs_path );
		$source_exists = file_exists( $source_path );

		$status        = $source_exists ? 'resolved' : 'missing';
		$error_message = $source_exists ? null : 'Source file does not exist.';

		if ( '' === $new_rel_path ) {
			$status        = 'blocked_collision';
			$error_message = 'Target filename is empty.';
		} elseif ( isset( $target_counts[ $new_rel_path ] ) && $target_counts[ $new_rel_path ] > 1 ) {
			$status        = 'blocked_collision';
			$error_message = 'Multiple manifest rows resolve to the same target filename.';
		} elseif ( file_exists( $target_path ) && ! $this->is_same_file( $source_path, $target_path ) ) {
			$status        = 'blocked_collision';
			$error_message = 'A different file already exists at the uploads-root target path.';
		}

		return array(
			'new_rel_path'  => $new_rel_path,
			'new_abs_path'  => $new_abs_path,
			'new_url'       => $new_url,
			'status'        => $status,
			'error_message' => $error_message,
		);
	}

	/**
	 * @param array<string, mixed> $row    Current manifest row.
	 * @param array<string, mixed> $target Proposed target values.
	 * @return bool
	 */
	private function target_changed( array $row, array $target ) {
		foreach ( array( 'new_rel_path', 'new_abs_path', 'new_url', 'status', 'error_message' ) as $field ) {
			if ( $row[ $field ] !== $target[ $field ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $source_path Source absolute path.
	 * @param string $target_path Target absolute path.
	 * @return bool
	 */
	private function is_same_file( $source_path, $target_path ) {
		if ( $source_path === $target_path ) {
			return true;
		}

		$source_realpath = realpath( $source_path );
		$target_realpath = realpath( $target_path );

		return false !== $source_realpath
			&& false !== $target_realpath
			&& wp_normalize_path( $source_realpath ) === wp_normalize_path( $target_realpath );
	}

	/**
	 * Return the final path component without decoding, sanitizing, or normalization.
	 *
	 * @param string $path Relative uploads path.
	 * @return string
	 */
	private function exact_basename( $path ) {
		$path     = str_replace( '\\', '/', (string) $path );
		$position = strrpos( $path, '/' );

		return false === $position ? $path : substr( $path, $position + 1 );
	}
}
