<?php

namespace MediaFlattenMigrator;

final class Verification_Service {
	const SAMPLE_LIMIT = 20;

	/** @var \wpdb */
	private $wpdb;

	/** @var Manifest_Repository */
	private $repository;

	/** @var string */
	private $uploads_base_dir;

	/** @var array<int, array<string, mixed>> */
	private $mappings;

	/** @var Old_URL_Audit_Service */
	private $old_url_audit;

	/**
	 * @param Manifest_Repository $repository Manifest repository.
	 */
	public function __construct( Manifest_Repository $repository ) {
		global $wpdb;

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'WordPress could not determine the uploads directory: ' . $uploads['error'] );
		}

		$this->wpdb             = $wpdb;
		$this->repository       = $repository;
		$this->uploads_base_dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$this->mappings         = $repository->get_migrated_url_mappings();
		$this->old_url_audit    = new Old_URL_Audit_Service( $repository );
	}

	/**
	 * Create a fresh structured verification result.
	 *
	 * @return array<string, mixed>
	 */
	public function initial_result() {
		$status_counts = array();
		foreach ( $this->repository->status_counts() as $row ) {
			$status_counts[ $row['status'] ] = (int) $row['item_count'];
		}

		$result = array(
			'pass'                              => true,
			'verified_at'                       => null,
			'errors_count'                      => 0,
			'warnings_count'                    => 0,
			'info_count'                        => 0,
			'status_counts'                     => $status_counts,
			'extension_counts'                  => array( 'webp' => 0, 'png' => 0, 'jpg/jpeg' => 0, 'gif' => 0, 'svg' => 0, 'pdf' => 0, 'other' => 0 ),
			'unicode_filename_count'            => 0,
			'basename_preserved_count'          => 0,
			'old_url_occurrences'               => array( 'post_content' => 0, 'post_excerpt' => 0, 'postmeta' => 0, 'options' => 0 ),
			'new_url_occurrences'               => array( 'post_content' => 0, 'post_excerpt' => 0, 'postmeta' => 0, 'options' => 0 ),
			'dated_upload_url_occurrences'      => 0,
			'missing_new_files'                 => 0,
			'integrity_mismatches'              => 0,
			'old_source_missing_for_comparison' => 0,
			'metadata_errors'                   => 0,
			'woocommerce_reference_errors'      => 0,
			'duplicate_manifest_rows'           => 0,
			'pre_redirect_ready'                => false,
			'redirect_export_ready'             => false,
			'migrated_mapping_old_url_remaining' => 0,
			'non_migrated_manifest_url_remaining' => 0,
			'orphan_old_upload_url_remaining'   => 0,
			'generic_dated_upload_occurrences'  => 0,
			'old_url_audit_samples'             => array(
				'migrated_mapping_old_url_remaining'  => array(),
				'non_migrated_manifest_url_remaining' => array(),
				'orphan_old_upload_url_remaining'     => array(),
			),
			'remaining_old_url_samples'         => array( 'post_content' => array(), 'post_excerpt' => array(), 'postmeta' => array(), 'options' => array() ),
			'sample_errors'                     => array(),
			'sample_warnings'                   => array(),
			'sample_info'                       => array(),
		);

		foreach ( array( 'copying', 'copied', 'failed' ) as $unsafe_status ) {
			if ( ! empty( $status_counts[ $unsafe_status ] ) ) {
				$this->error(
					$result,
					sprintf( 'Manifest contains %d row(s) with unsafe incomplete status %s.', $status_counts[ $unsafe_status ], $unsafe_status )
				);
			}
		}
		foreach ( array( 'pending', 'missing', 'blocked_collision', 'resolved' ) as $incomplete_status ) {
			if ( ! empty( $status_counts[ $incomplete_status ] ) ) {
				$this->warning(
					$result,
					sprintf( 'Manifest contains %d non-migrated row(s) with status %s.', $status_counts[ $incomplete_status ], $incomplete_status )
				);
			}
		}

		return $result;
	}

	/**
	 * Return ordered verification stages.
	 *
	 * @return array<int, string>
	 */
	public function stages() {
		return array(
			'manifest',
			'duplicate_manifest',
			'metadata',
			'audit_post_content',
			'audit_post_excerpt',
			'audit_postmeta',
			'audit_options',
			'wc_product',
			'wc_variation',
			'wc_gallery',
		);
	}

	/**
	 * Estimate records inspected by a full verification.
	 *
	 * @return int
	 */
	public function estimate_total() {
		$total = $this->repository->count_migrated_rows() + $this->repository->count_migrated_attachments();
		$total += $this->old_url_audit->estimate_total();
		$total += (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->wpdb->postmeta} WHERE meta_key IN ('_thumbnail_id', '_product_image_gallery')"
		);

		return $total;
	}

	/**
	 * Run one bounded verification stage batch.
	 *
	 * @param string               $stage     Verification stage.
	 * @param int                  $after_id  Cursor.
	 * @param int                  $limit     Batch size.
	 * @param array<string, mixed> $result    Accumulated result.
	 * @return array<string, mixed>
	 */
	public function run_batch( $stage, $after_id, $limit, array $result ) {
		$limit = max( 1, (int) $limit );
		if ( 'manifest' === $stage ) {
			return $this->verify_manifest_batch( $after_id, $limit, $result );
		}
		if ( 'metadata' === $stage ) {
			return $this->verify_metadata_batch( $after_id, $limit, $result );
		}
		if ( 'duplicate_manifest' === $stage ) {
			return $this->verify_duplicate_manifest_rows( $result );
		}
		if ( 0 === strpos( $stage, 'audit_' ) ) {
			return $this->verify_old_url_audit_batch( substr( $stage, 6 ), $after_id, $limit, $result );
		}
		if ( in_array( $stage, array( 'wc_product', 'wc_variation', 'wc_gallery' ), true ) ) {
			return $this->verify_woocommerce_batch( $stage, $after_id, $limit, $result );
		}

		throw new \InvalidArgumentException( 'Unknown verification stage.' );
	}

	/**
	 * Run every verification stage read-only.
	 *
	 * @param int $batch_size Batch size.
	 * @return array<string, mixed>
	 */
	public function run( $batch_size = 100 ) {
		$result = $this->initial_result();

		foreach ( $this->stages() as $stage ) {
			$after_id = 0;
			do {
				$batch    = $this->run_batch( $stage, $after_id, $batch_size, $result );
				$result   = $batch['result'];
				$after_id = $batch['last_processed_id'];
			} while ( ! $batch['done'] );
		}

		return $this->finalize( $result );
	}

	/**
	 * Finalize PASS/FAIL and timestamp.
	 *
	 * @param array<string, mixed> $result Accumulated result.
	 * @return array<string, mixed>
	 */
	public function finalize( array $result ) {
		foreach ( array(
			'migrated_mapping_old_url_remaining'  => 'Migrated old upload URLs remain in scanned content.',
			'non_migrated_manifest_url_remaining' => 'Known non-migrated manifest old URLs remain in scanned content.',
			'orphan_old_upload_url_remaining'     => 'Orphan dated upload URLs remain in scanned content.',
		) as $field => $message ) {
			if ( $result[ $field ] > 0 ) {
				$this->error( $result, $message );
			}
		}

		$result['pre_redirect_ready'] = 0 === (int) $result['missing_new_files']
			&& 0 === (int) $result['integrity_mismatches']
			&& 0 === (int) $result['metadata_errors']
			&& 0 === (int) $result['migrated_mapping_old_url_remaining']
			&& 0 === (int) $result['non_migrated_manifest_url_remaining']
			&& 0 === (int) $result['orphan_old_upload_url_remaining'];
		$result['redirect_export_ready'] = 0 === (int) $result['errors_count']
			&& $result['pre_redirect_ready']
			&& 0 === (int) $this->repository->count_invalid_migrated_url_rows()
			&& empty( $result['status_counts']['copying'] )
			&& empty( $result['status_counts']['copied'] )
			&& empty( $result['status_counts']['failed'] )
			&& 0 === (int) $result['duplicate_manifest_rows'];
		$result['pass']        = 0 === (int) $result['errors_count'];
		$result['verified_at'] = current_time( 'mysql' );
		return $result;
	}

	/** @return array<string, mixed> */
	private function verify_manifest_batch( $after_id, $limit, array $result ) {
		$rows = $this->repository->get_migrated_rows( $after_id, $limit );

		foreach ( $rows as $row ) {
			$after_id = (int) $row['id'];
			foreach ( array( 'attachment_id', 'old_rel_path', 'new_rel_path', 'old_abs_path', 'new_abs_path', 'old_url', 'new_url' ) as $field ) {
				if ( '' === (string) $row[ $field ] ) {
					$this->error( $result, sprintf( 'Migrated manifest row %d is missing %s.', $row['id'], $field ) );
				}
			}
			if ( (int) $row['attachment_id'] < 1 ) {
				$this->error( $result, sprintf( 'Migrated manifest row %d has an invalid attachment_id.', $row['id'] ) );
			}

			$old_basename = $this->exact_basename( $row['old_rel_path'] );
			if ( $row['new_rel_path'] !== $old_basename ) {
				$this->error( $result, sprintf( 'Manifest row %d does not preserve the exact filename.', $row['id'] ) );
			} else {
				++$result['basename_preserved_count'];
			}

			$extension = strtolower( pathinfo( $old_basename, PATHINFO_EXTENSION ) );
			if ( 'jpg' === $extension || 'jpeg' === $extension ) {
				++$result['extension_counts']['jpg/jpeg'];
			} elseif ( isset( $result['extension_counts'][ $extension ] ) && 'other' !== $extension ) {
				++$result['extension_counts'][ $extension ];
			} else {
				++$result['extension_counts']['other'];
			}
			if ( preg_match( '/[^\x00-\x7F]/', $old_basename ) ) {
				++$result['unicode_filename_count'];
			}

			$new_exists = '' !== (string) $row['new_abs_path'] && file_exists( $row['new_abs_path'] );
			if ( ! $new_exists ) {
				++$result['missing_new_files'];
				$this->error( $result, sprintf( 'Migrated target file is missing for manifest row %d.', $row['id'] ) );
				continue;
			}

			if ( ! file_exists( $row['old_abs_path'] ) ) {
				++$result['old_source_missing_for_comparison'];
				$this->info( $result, sprintf( 'Old source is unavailable for comparison for manifest row %d.', $row['id'] ) );
				continue;
			}

			clearstatcache( true, $row['old_abs_path'] );
			clearstatcache( true, $row['new_abs_path'] );
			$size_matches = @filesize( $row['old_abs_path'] ) === @filesize( $row['new_abs_path'] );
			$old_md5      = @md5_file( $row['old_abs_path'] );
			$new_md5      = @md5_file( $row['new_abs_path'] );
			if ( ! $size_matches || false === $old_md5 || false === $new_md5 || $old_md5 !== $new_md5 ) {
				++$result['integrity_mismatches'];
				$this->error( $result, sprintf( 'File integrity mismatch for manifest row %d.', $row['id'] ) );
			} else {
				$this->info( $result, sprintf( 'Old source remains available for manifest row %d.', $row['id'] ) );
			}
		}

		return $this->batch_response( $rows, $after_id, $limit, $result );
	}

	/** @return array<string, mixed> */
	private function verify_metadata_batch( $after_id, $limit, array $result ) {
		$ids = $this->repository->get_migrated_attachment_ids( $after_id, $limit );

		foreach ( $ids as $attachment_id ) {
			$after_id = $attachment_id;
			$rows     = $this->repository->get_attachment_rows( $attachment_id );
			$main     = null;
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				$this->metadata_error( $result, sprintf( 'Migrated attachment ID %d does not reference an attachment post.', $attachment_id ) );
				continue;
			}
			foreach ( $rows as $row ) {
				if ( 'migrated' === $row['status'] && 'main' === $row['file_kind'] ) {
					$main = $row;
					break;
				}
			}
			if ( ! $main ) {
				$this->metadata_error( $result, sprintf( 'Attachment %d has migrated rows but no migrated main row.', $attachment_id ) );
				continue;
			}

			$current = get_post_meta( $attachment_id, '_wp_attached_file', true );
			if ( $current !== $main['new_rel_path'] ) {
				$this->metadata_error( $result, sprintf( 'Attachment %d _wp_attached_file does not match the migrated main target.', $attachment_id ) );
			}

			$metadata = wp_get_attachment_metadata( $attachment_id, true );
			if ( ! is_array( $metadata ) ) {
				continue;
			}
			if ( ! empty( $metadata['file'] ) && ( preg_match( '~^[0-9]{4}/[0-9]{2}/~', $metadata['file'] ) || false !== strpos( $metadata['file'], '/' ) ) ) {
				$this->metadata_error( $result, sprintf( 'Attachment %d metadata file is not root-level.', $attachment_id ) );
			}
			foreach ( array( 'sizes', 'backup_sizes' ) as $group ) {
				if ( empty( $metadata[ $group ] ) || ! is_array( $metadata[ $group ] ) ) {
					continue;
				}
				foreach ( $metadata[ $group ] as $key => $item ) {
					if ( ! empty( $item['file'] ) && false !== strpos( str_replace( '\\', '/', $item['file'] ), '/' ) ) {
						$this->metadata_error( $result, sprintf( 'Attachment %d %s filename %s contains a path.', $attachment_id, $group, $key ) );
					}
				}
			}
			if ( ! empty( $metadata['original_image'] ) && false !== strpos( str_replace( '\\', '/', $metadata['original_image'] ), '/' ) ) {
				$this->metadata_error( $result, sprintf( 'Attachment %d original_image contains a path.', $attachment_id ) );
			}
		}

		return $this->batch_response( $ids, $after_id, $limit, $result );
	}

	/** @return array<string, mixed> */
	private function verify_duplicate_manifest_rows( array $result ) {
		$groups = $this->repository->get_duplicate_logical_groups();

		foreach ( $groups as $group ) {
			$result['duplicate_manifest_rows'] += (int) $group['duplicate_count'];
			$this->error(
				$result,
				sprintf(
					'Duplicate manifest logical rows detected for attachment %d, file_kind %s, size_key %s.',
					$group['attachment_id'],
					$group['file_kind'],
					'__NULL__' === $group['normalized_size_key'] ? '(null)' : $group['normalized_size_key']
				)
			);
		}

		return array(
			'result'            => $result,
			'processed'         => count( $groups ),
			'last_processed_id' => 0,
			'done'              => true,
		);
	}

	/**
	 * @param string               $area     Audit area.
	 * @param int                  $after_id Cursor.
	 * @param int                  $limit    Batch size.
	 * @param array<string, mixed> $result   Result.
	 * @return array<string, mixed>
	 */
	private function verify_old_url_audit_batch( $area, $after_id, $limit, array $result ) {
		$audit_batch = $this->old_url_audit->run_batch(
			$area,
			$after_id,
			$limit,
			array(
				'migrated_mapping_old_url_remaining'  => $result['migrated_mapping_old_url_remaining'],
				'non_migrated_manifest_url_remaining' => $result['non_migrated_manifest_url_remaining'],
				'orphan_old_upload_url_remaining'     => $result['orphan_old_upload_url_remaining'],
				'generic_dated_upload_occurrences'    => $result['generic_dated_upload_occurrences'],
				'samples'                             => $result['old_url_audit_samples'],
			)
		);
		$audit_result = $audit_batch['result'];

		$result['migrated_mapping_old_url_remaining']  = $audit_result['migrated_mapping_old_url_remaining'];
		$result['non_migrated_manifest_url_remaining'] = $audit_result['non_migrated_manifest_url_remaining'];
		$result['orphan_old_upload_url_remaining']     = $audit_result['orphan_old_upload_url_remaining'];
		$result['generic_dated_upload_occurrences']    = $audit_result['generic_dated_upload_occurrences'];
		$result['old_url_audit_samples']               = $audit_result['samples'];

		return array(
			'result'            => $result,
			'processed'         => $audit_batch['processed'],
			'last_processed_id' => $audit_batch['last_processed_id'],
			'done'              => $audit_batch['done'],
		);
	}

	/** @return array<string, mixed> */
	private function verify_woocommerce_batch( $stage, $after_id, $limit, array $result ) {
		$post_type = 'wc_product' === $stage ? 'product' : 'product_variation';
		$meta_key  = 'wc_gallery' === $stage ? '_product_image_gallery' : '_thumbnail_id';
		$sql       = "SELECT pm.meta_id, pm.meta_value
			FROM {$this->wpdb->postmeta} pm
			INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
			WHERE pm.meta_id > %d AND pm.meta_key = %s";
		if ( 'wc_gallery' === $stage ) {
			$sql .= " AND p.post_type = 'product'";
		} else {
			$sql .= ' AND p.post_type = %s';
		}
		$sql .= ' ORDER BY pm.meta_id ASC LIMIT %d';
		$params = 'wc_gallery' === $stage
			? array( (int) $after_id, $meta_key, $limit )
			: array( (int) $after_id, $meta_key, $post_type, $limit );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ), ARRAY_A );

		foreach ( $rows as $row ) {
			$after_id = (int) $row['meta_id'];
			$ids      = 'wc_gallery' === $stage
				? array_filter( array_map( 'absint', explode( ',', $row['meta_value'] ) ) )
				: array( absint( $row['meta_value'] ) );
			foreach ( $ids as $attachment_id ) {
				$attachment = get_post( $attachment_id );
				if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
					++$result['woocommerce_reference_errors'];
					$this->error( $result, sprintf( 'WooCommerce %s meta row %d points to missing attachment %d.', $stage, $after_id, $attachment_id ) );
					continue;
				}
				$current = get_post_meta( $attachment_id, '_wp_attached_file', true );
				$path    = $this->uploads_base_dir . '/' . ltrim( wp_normalize_path( $current ), '/' );
				if ( '' === $current || ! file_exists( $path ) ) {
					++$result['woocommerce_reference_errors'];
					$this->error( $result, sprintf( 'WooCommerce %s attachment %d current file is missing.', $stage, $attachment_id ) );
				}
			}
		}

		return $this->batch_response( $rows, $after_id, $limit, $result );
	}

	/** @return string */
	private function exact_basename( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$position = strrpos( $path, '/' );
		return false === $position ? $path : substr( $path, $position + 1 );
	}

	/** @return array<string, mixed> */
	private function batch_response( array $rows, $after_id, $limit, array $result ) {
		return array(
			'result'            => $result,
			'processed'         => count( $rows ),
			'last_processed_id' => (int) $after_id,
			'done'              => count( $rows ) < $limit,
		);
	}

	/** @return void */
	private function metadata_error( array &$result, $message ) {
		++$result['metadata_errors'];
		$this->error( $result, $message );
	}

	/** @return void */
	private function error( array &$result, $message ) {
		++$result['errors_count'];
		$this->sample( $result['sample_errors'], $message );
	}

	/** @return void */
	private function warning( array &$result, $message ) {
		++$result['warnings_count'];
		$this->sample( $result['sample_warnings'], $message );
	}

	/** @return void */
	private function info( array &$result, $message ) {
		++$result['info_count'];
		$this->sample( $result['sample_info'], $message );
	}

	/** @return void */
	private function sample( array &$samples, $value ) {
		if ( count( $samples ) < self::SAMPLE_LIMIT && ! in_array( $value, $samples, true ) ) {
			$samples[] = $value;
		}
	}
}
