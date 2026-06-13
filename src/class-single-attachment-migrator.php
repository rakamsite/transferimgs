<?php

namespace MediaFlattenMigrator;

final class Single_Attachment_Migrator {
	const STATUS_MIGRATED           = 'migrated';
	const STATUS_ADOPTED_ROOT_SIZE  = 'adopted_root_size';
	const STATUS_OMITTED_COLLISION  = 'omitted_size_collision';

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
	 * Build a read-only migration plan.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>
	 */
	public function plan( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$rows          = $this->repository->get_attachment_rows( $attachment_id );
		$current_file  = get_post_meta( $attachment_id, '_wp_attached_file', true );
		$metadata      = wp_get_attachment_metadata( $attachment_id, true );
		$attachment    = get_post( $attachment_id );
		$errors        = array();
		$warnings      = array();
		$file_rows     = array();
		$row_actions   = array();
		$copy_row_ids  = array();
		$adopt_row_ids = array();
		$omit_row_ids  = array();
		$copy_paths    = array();
		$main_rows     = array();
		$has_completed = false;
		$all_completed = ! empty( $rows );

		if ( ! $rows ) {
			$errors[] = 'No manifest rows exist for this attachment.';
		}
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			$errors[] = 'The selected ID is not a valid attachment.';
		}

		foreach ( $rows as $row ) {
			if ( 'main' === $row['file_kind'] ) {
				$main_rows[] = $row;
			}
			$has_completed = $has_completed || $this->is_completed_status( $row['status'] );
			$all_completed = $all_completed && $this->is_completed_status( $row['status'] );
		}

		if ( 1 !== count( $main_rows ) ) {
			$errors[] = 'The attachment must have exactly one main manifest row.';
		}

		$main_row = 1 === count( $main_rows ) ? $main_rows[0] : null;
		$proposed_file = $main_row ? (string) $main_row['new_rel_path'] : '';

		foreach ( $rows as $row ) {
			$row_id         = (int) $row['id'];
			$source_path    = wp_normalize_path( (string) $row['old_abs_path'] );
			$target_path    = wp_normalize_path( (string) $row['new_abs_path'] );
			$source_exists  = '' !== $source_path && file_exists( $source_path );
			$target_exists  = '' !== $target_path && file_exists( $target_path );
			$same_path      = $this->is_same_path( $source_path, $target_path );
			$action         = 'block';
			$final_status   = '';
			$note           = '';

			$common_errors = $this->validate_row_paths( $row );
			foreach ( $common_errors as $common_error ) {
				$errors[] = $common_error;
			}

			if ( $all_completed ) {
				if ( in_array( (string) $row['status'], array( self::STATUS_MIGRATED, self::STATUS_ADOPTED_ROOT_SIZE ), true ) && ! $target_exists ) {
					$errors[] = sprintf( 'Completed manifest row %d is missing its target file.', $row_id );
				}
				if ( self::STATUS_ADOPTED_ROOT_SIZE === $row['status'] ) {
					$action       = 'adopted';
					$final_status = self::STATUS_ADOPTED_ROOT_SIZE;
					$note         = 'Root derivative already adopted.';
				} elseif ( self::STATUS_OMITTED_COLLISION === $row['status'] ) {
					$action       = 'omitted';
					$final_status = self::STATUS_OMITTED_COLLISION;
					$note         = 'Derivative already omitted from metadata.';
				} else {
					$action       = 'complete';
					$final_status = self::STATUS_MIGRATED;
					$note         = 'Already migrated.';
				}
			} elseif ( $this->is_completed_status( $row['status'] ) ) {
				$errors[] = sprintf( 'Attachment %d has a partial completed state and cannot continue safely.', $attachment_id );
			} elseif ( 'resolved' === $row['status'] ) {
				if ( ! $source_exists ) {
					$errors[] = sprintf( 'Source file does not exist for manifest row %d.', $row_id );
				} elseif ( $target_exists && ! $same_path ) {
					$errors[] = sprintf( 'Target file already exists for manifest row %d.', $row_id );
				} else {
					$action       = 'copy';
					$final_status = self::STATUS_MIGRATED;
					$note         = 'Resolved and ready to copy.';
					$copy_row_ids[] = $row_id;
					$copy_paths[] = $target_path;
				}
			} elseif ( 'blocked_collision' === $row['status'] && 'image_size' === $row['file_kind'] ) {
				if ( ! $source_exists ) {
					$errors[] = sprintf( 'Blocked image size row %d cannot be handled because the source file is missing.', $row_id );
				} elseif ( $this->is_existing_root_collision( $row ) ) {
					$safe_target = $this->is_safe_root_derivative_target( $target_path );
					if ( ! $safe_target ) {
						$errors[] = sprintf( 'Blocked image size row %d cannot adopt an unsafe root target.', $row_id );
					} else {
						$action       = 'adopt';
						$final_status = self::STATUS_ADOPTED_ROOT_SIZE;
						$note         = 'Safe existing root derivative will be adopted.';
						$adopt_row_ids[] = $row_id;
						$hash_warning = $this->adoption_hash_warning( $source_path, $target_path, $row );
						if ( $hash_warning ) {
							$warnings[] = $hash_warning;
						}
					}
				} elseif ( $this->is_duplicate_target_collision( $row ) ) {
					$action       = 'omit';
					$final_status = self::STATUS_OMITTED_COLLISION;
					$note         = 'Derivative will be omitted from metadata because the target filename collides.';
					$omit_row_ids[] = $row_id;
					$warnings[] = sprintf(
						'Image size %s for attachment %d will be omitted from metadata because multiple manifest rows share the same root filename.',
						(string) $row['size_key'],
						$attachment_id
					);
				} else {
					$errors[] = sprintf( 'Blocked image size row %d has a collision that cannot be handled safely.', $row_id );
				}
			} else {
				$errors[] = sprintf( 'Manifest row %d has non-migratable status: %s.', $row_id, $row['status'] );
			}

			$row_actions[ $row_id ] = array(
				'action'       => $action,
				'final_status' => $final_status,
				'note'         => $note,
			);
			$file_rows[] = array(
				'row_id'        => $row_id,
				'file_kind'     => (string) $row['file_kind'],
				'size_key'      => null === $row['size_key'] ? '-' : (string) $row['size_key'],
				'status'        => (string) $row['status'],
				'action'        => $action,
				'note'          => $note,
				'source_path'   => $source_path,
				'target_path'   => $target_path,
				'source_exists' => $source_exists ? 'yes' : 'no',
				'target_exists' => $target_exists ? 'yes' : 'no',
				'same_path'     => $same_path ? 'yes' : 'no',
			);
		}

		if ( $has_completed && ! $all_completed ) {
			$errors[] = 'Attachment manifest rows are only partially completed; refusing to continue.';
		}

		if ( ! $all_completed && $main_row && $current_file !== $main_row['old_rel_path'] ) {
			$errors[] = 'Current _wp_attached_file does not match the main manifest source path.';
		}
		if ( $all_completed && $main_row && $current_file !== $proposed_file ) {
			$errors[] = 'Migrated _wp_attached_file does not match the manifest target.';
		}

		$metadata_errors = $all_completed
			? $this->validate_migrated_metadata( $metadata, $rows, $row_actions )
			: $this->validate_metadata_mapping( $metadata, $rows );
		$errors = array_merge( $errors, $metadata_errors );

		return array(
			'attachment_id'                 => $attachment_id,
			'current_attached_file'         => $current_file,
			'proposed_attached_file'        => $proposed_file,
			'rows'                          => $rows,
			'row_actions'                   => $row_actions,
			'files'                         => $file_rows,
			'metadata'                      => $metadata,
			'all_migrated'                  => $all_completed,
			'allowed'                       => empty( $errors ),
			'errors'                        => array_values( array_unique( $errors ) ),
			'warnings'                      => array_values( array_unique( $warnings ) ),
			'copy_row_ids'                  => array_values( array_unique( $copy_row_ids ) ),
			'adopt_row_ids'                 => array_values( array_unique( $adopt_row_ids ) ),
			'omit_row_ids'                  => array_values( array_unique( $omit_row_ids ) ),
			'copy_paths'                    => array_values( array_unique( $copy_paths ) ),
			'adopted_size_rows'             => count( $adopt_row_ids ),
			'omitted_size_rows'             => count( $omit_row_ids ),
			'adopted_hash_mismatch_warnings' => $this->count_hash_mismatch_warnings( $warnings ),
			'has_adopted_sizes'             => ! empty( $adopt_row_ids ),
			'has_omitted_sizes'             => ! empty( $omit_row_ids ),
		);
	}

	/**
	 * Execute one planned attachment migration.
	 *
	 * @param array<string, mixed> $plan Migration plan.
	 * @return array<string, int>
	 */
	public function migrate( array $plan ) {
		if ( $plan['all_migrated'] ) {
			return array(
				'copied'   => 0,
				'migrated' => count( $plan['rows'] ),
				'adopted'  => (int) ( $plan['adopted_size_rows'] ?? 0 ),
				'omitted'  => (int) ( $plan['omitted_size_rows'] ?? 0 ),
			);
		}
		if ( ! $plan['allowed'] ) {
			throw new \RuntimeException( implode( ' ', $plan['errors'] ) );
		}

		$attachment_id       = (int) $plan['attachment_id'];
		$copied_paths        = array();
		$metadata_was_changed = false;
		$file_was_changed     = false;
		$copy_row_ids         = array_map( 'intval', $plan['copy_row_ids'] ?? array() );
		$adopt_row_ids        = array_map( 'intval', $plan['adopt_row_ids'] ?? array() );
		$omit_row_ids         = array_map( 'intval', $plan['omit_row_ids'] ?? array() );
		$actionable_row_ids   = array_values( array_unique( array_merge( $copy_row_ids, $adopt_row_ids, $omit_row_ids ) ) );

		try {
			if ( $copy_row_ids ) {
				$this->repository->set_rows_status( $copy_row_ids, 'copying' );
			}

			foreach ( $plan['files'] as $file ) {
				if ( 'copy' !== (string) $file['action'] || 'yes' === $file['same_path'] ) {
					continue;
				}
				if ( ! file_exists( $file['source_path'] ) ) {
					throw new \RuntimeException( 'Source file disappeared before copy: ' . $file['source_path'] );
				}
				if ( file_exists( $file['target_path'] ) ) {
					throw new \RuntimeException( 'Target file appeared before copy; refusing to overwrite: ' . $file['target_path'] );
				}
				if ( ! copy( $file['source_path'], $file['target_path'] ) ) {
					throw new \RuntimeException( 'Copy failed: ' . $file['source_path'] );
				}
				$copied_paths[] = $file['target_path'];
				$this->verify_copy( $file['source_path'], $file['target_path'] );
			}

			if ( $copy_row_ids ) {
				$this->repository->set_rows_status( $copy_row_ids, 'copied' );
			}

			$new_metadata = $this->build_new_metadata( $plan['metadata'], $plan['rows'], $plan['row_actions'] );
			$file_was_changed = true;
			update_attached_file( $attachment_id, $plan['proposed_attached_file'] );

			if ( get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $plan['proposed_attached_file'] ) {
				throw new \RuntimeException( 'Could not update _wp_attached_file.' );
			}

			if ( is_array( $new_metadata ) ) {
				$metadata_was_changed = true;
				wp_update_attachment_metadata( $attachment_id, $new_metadata );
				$this->assert_targeted_metadata_state(
					$attachment_id,
					$plan['rows'],
					$plan['row_actions'],
					$plan['proposed_attached_file'],
					true
				);
			}

			if ( $copy_row_ids ) {
				$this->repository->set_rows_status( $copy_row_ids, self::STATUS_MIGRATED, null, true );
			}
			foreach ( $adopt_row_ids as $row_id ) {
				$this->repository->set_rows_status(
					array( $row_id ),
					self::STATUS_ADOPTED_ROOT_SIZE,
					'Safely adopted an existing uploads-root derivative file without overwriting it.',
					true
				);
			}
			foreach ( $omit_row_ids as $row_id ) {
				$this->repository->set_rows_status(
					array( $row_id ),
					self::STATUS_OMITTED_COLLISION,
					'Removed this derivative size from attachment metadata because its flattened root filename collides.',
					true
				);
			}

			return array(
				'copied'   => count( $copied_paths ),
				'migrated' => count( $copy_row_ids ),
				'adopted'  => count( $adopt_row_ids ),
				'omitted'  => count( $omit_row_ids ),
			);
		} catch ( \Throwable $exception ) {
			$error_message = $exception->getMessage();

			if ( $file_was_changed ) {
				update_attached_file( $attachment_id, $plan['current_attached_file'] );
				if ( get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $plan['current_attached_file'] ) {
					$error_message .= ' Rollback could not restore _wp_attached_file.';
				}
			}
			if ( $metadata_was_changed && is_array( $plan['metadata'] ) ) {
				wp_update_attachment_metadata( $attachment_id, $plan['metadata'] );
				$rollback_errors = $this->validate_metadata_mapping(
					wp_get_attachment_metadata( $attachment_id, true ),
					$plan['rows']
				);
				if ( $rollback_errors ) {
					$error_message .= ' Rollback could not restore migration-critical attachment metadata fields.';
				}
			}

			$cleanup_errors = $this->remove_copied_files( $copied_paths );
			if ( $cleanup_errors ) {
				$error_message .= ' ' . implode( ' ', $cleanup_errors );
			}

			try {
				if ( $actionable_row_ids ) {
					$this->repository->set_rows_status( $actionable_row_ids, 'failed', $error_message );
				}
			} catch ( \Throwable $status_exception ) {
				throw new \RuntimeException(
					$error_message . ' Manifest failure status could not be saved: ' . $status_exception->getMessage()
				);
			}

			throw new \RuntimeException( $error_message );
		}
	}

	/**
	 * @param array|false                       $metadata Attachment metadata.
	 * @param array<int, array<string, mixed>> $rows     Manifest rows.
	 * @return array<int, string>
	 */
	private function validate_metadata_mapping( $metadata, array $rows ) {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$errors = array();
		$map    = $this->build_row_map( $rows );

		if ( ! empty( $metadata['file'] ) ) {
			if ( empty( $map['main'] ) ) {
				$errors[] = 'No main manifest row exists for attachment metadata.';
			} elseif ( $metadata['file'] !== $map['main']['old_rel_path'] ) {
				$errors[] = 'Attachment metadata file does not match the main manifest source path.';
			}
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size ) {
				if ( ! isset( $map['image_size'][ $size_key ] ) ) {
					$errors[] = 'No manifest row exists for metadata size: ' . $size_key . '.';
				} elseif ( ! empty( $size['file'] ) && $size['file'] !== $this->exact_basename( $map['image_size'][ $size_key ]['old_rel_path'] ) ) {
					$errors[] = 'Metadata filename does not match manifest source for size: ' . $size_key . '.';
				}
			}
		}
		if ( ! empty( $metadata['original_image'] ) && empty( $map['original_image'] ) ) {
			$errors[] = 'No manifest row exists for original_image.';
		} elseif ( ! empty( $metadata['original_image'] )
			&& $metadata['original_image'] !== $this->exact_basename( $map['original_image']['old_rel_path'] )
		) {
			$errors[] = 'Metadata original_image filename does not match the manifest source.';
		}
		if ( ! empty( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
			foreach ( $metadata['backup_sizes'] as $backup_key => $backup ) {
				if ( empty( $backup['file'] ) ) {
					continue;
				}
				if ( ! isset( $map['backup_size'][ $backup_key ] ) ) {
					$errors[] = 'No manifest row exists for backup size: ' . $backup_key . '.';
				} elseif ( $backup['file'] !== $this->exact_basename( $map['backup_size'][ $backup_key ]['old_rel_path'] ) ) {
					$errors[] = 'Metadata filename does not match manifest source for backup size: ' . $backup_key . '.';
				}
			}
		}

		return $errors;
	}

	/**
	 * @param array|false                       $metadata Attachment metadata.
	 * @param array<int, array<string, mixed>> $rows     Manifest rows.
	 * @param array<int, array<string, string>> $row_actions Action map.
	 * @return array<int, string>
	 */
	private function validate_migrated_metadata( $metadata, array $rows, array $row_actions ) {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$errors = array();
		$map    = $this->build_row_map( $rows );

		if ( empty( $map['main'] ) || (string) ( $metadata['file'] ?? '' ) !== $map['main']['new_rel_path'] ) {
			$errors[] = 'Migrated attachment metadata file does not match the manifest target.';
		}

		$expected_sizes = array();
		$omitted_sizes  = array();
		foreach ( $map['image_size'] as $size_key => $row ) {
			$action = $row_actions[ (int) $row['id'] ]['action'] ?? '';
			if ( in_array( $action, array( 'copy', 'complete', 'adopt', 'adopted' ), true ) ) {
				$expected_sizes[ $size_key ] = $this->exact_basename( $row['new_rel_path'] );
			} elseif ( in_array( $action, array( 'omit', 'omitted' ), true ) ) {
				$omitted_sizes[ $size_key ] = true;
			}
		}

		$metadata_sizes = ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( $expected_sizes as $size_key => $expected_file ) {
			if ( empty( $metadata_sizes[ $size_key ]['file'] ) || (string) $metadata_sizes[ $size_key ]['file'] !== $expected_file ) {
				$errors[] = 'Migrated metadata does not match manifest target for size: ' . $size_key . '.';
			}
		}
		foreach ( array_keys( $omitted_sizes ) as $size_key ) {
			if ( isset( $metadata_sizes[ $size_key ] ) ) {
				$errors[] = 'Migrated metadata still references omitted collision size: ' . $size_key . '.';
			}
		}

		if ( ! empty( $metadata['original_image'] )
			&& ( empty( $map['original_image'] )
				|| $metadata['original_image'] !== $this->exact_basename( $map['original_image']['new_rel_path'] ) )
		) {
			$errors[] = 'Migrated metadata original_image does not match the manifest target.';
		}
		if ( ! empty( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
			foreach ( $metadata['backup_sizes'] as $backup_key => $backup ) {
				if ( ! empty( $backup['file'] )
					&& ( ! isset( $map['backup_size'][ $backup_key ] )
						|| $backup['file'] !== $this->exact_basename( $map['backup_size'][ $backup_key ]['new_rel_path'] ) )
				) {
					$errors[] = 'Migrated metadata does not match manifest target for backup size: ' . $backup_key . '.';
				}
			}
		}

		return $errors;
	}

	/**
	 * @param array|false                        $metadata Attachment metadata.
	 * @param array<int, array<string, mixed>>  $rows     Manifest rows.
	 * @param array<int, array<string, string>> $row_actions Action map.
	 * @return array|false
	 */
	private function build_new_metadata( $metadata, array $rows, array $row_actions ) {
		if ( ! is_array( $metadata ) ) {
			return false;
		}

		$map = $this->build_row_map( $rows );
		$metadata['file'] = $map['main']['new_rel_path'];

		$metadata_sizes = ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( $map['image_size'] as $size_key => $row ) {
			$action = $row_actions[ (int) $row['id'] ]['action'] ?? '';
			if ( in_array( $action, array( 'copy', 'complete', 'adopt', 'adopted' ), true ) ) {
				if ( isset( $metadata_sizes[ $size_key ] ) && is_array( $metadata_sizes[ $size_key ] ) ) {
					$metadata_sizes[ $size_key ]['file'] = $this->exact_basename( $row['new_rel_path'] );
				}
			} elseif ( in_array( $action, array( 'omit', 'omitted' ), true ) ) {
				unset( $metadata_sizes[ $size_key ] );
			}
		}
		$metadata['sizes'] = $metadata_sizes;

		if ( ! empty( $metadata['original_image'] ) && ! empty( $map['original_image']['new_rel_path'] ) ) {
			$metadata['original_image'] = $this->exact_basename( $map['original_image']['new_rel_path'] );
		}
		if ( ! empty( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
			foreach ( $metadata['backup_sizes'] as $backup_key => &$backup ) {
				if ( ! empty( $backup['file'] ) && isset( $map['backup_size'][ $backup_key ] ) ) {
					$backup['file'] = $this->exact_basename( $map['backup_size'][ $backup_key ]['new_rel_path'] );
				}
			}
			unset( $backup );
		}

		return $metadata;
	}

	/**
	 * Validate only migration-critical metadata fields after an update.
	 *
	 * @param int                             $attachment_id   Attachment ID.
	 * @param array<int, array<string,mixed>> $rows           Manifest rows.
	 * @param array<int, array<string,string>> $row_actions    Action map.
	 * @param string                          $attached_file   Expected attached file.
	 * @param bool                            $expect_migrated Whether migrated targets should be present.
	 * @return void
	 */
	private function assert_targeted_metadata_state( $attachment_id, array $rows, array $row_actions, $attached_file, $expect_migrated ) {
		$current_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( $current_file !== $attached_file ) {
			throw new \RuntimeException( 'Could not update _wp_attached_file.' );
		}

		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		if ( ! is_array( $metadata ) ) {
			return;
		}

		$errors = $expect_migrated
			? $this->validate_migrated_metadata( $metadata, $rows, $row_actions )
			: $this->validate_metadata_mapping( $metadata, $rows );
		if ( $errors ) {
			throw new \RuntimeException( implode( ' ', $errors ) );
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Manifest rows.
	 * @return array<string, mixed>
	 */
	private function build_row_map( array $rows ) {
		$map = array(
			'main'           => null,
			'image_size'     => array(),
			'backup_size'    => array(),
			'original_image' => null,
		);

		foreach ( $rows as $row ) {
			if ( 'main' === $row['file_kind'] ) {
				$map['main'] = $row;
			} elseif ( 'image_size' === $row['file_kind'] ) {
				$map['image_size'][ $row['size_key'] ] = $row;
			} elseif ( 'backup_size' === $row['file_kind'] ) {
				$map['backup_size'][ $row['size_key'] ] = $row;
			} elseif ( 'original_image' === $row['file_kind'] ) {
				$map['original_image'] = $row;
			}
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $row Manifest row.
	 * @return array<int, string>
	 */
	private function validate_row_paths( array $row ) {
		$errors           = array();
		$expected_rel     = $this->exact_basename( $row['old_rel_path'] );
		$expected_old_abs = $this->uploads_base_dir . '/' . ltrim( str_replace( '\\', '/', $row['old_rel_path'] ), '/' );
		$expected_abs     = $this->uploads_base_dir . '/' . $expected_rel;
		$expected_url     = $this->uploads_base_url . '/' . $expected_rel;

		foreach ( array( 'new_rel_path', 'new_abs_path', 'new_url' ) as $field ) {
			if ( '' === (string) $row[ $field ] ) {
				$errors[] = sprintf( 'Manifest row %d is missing %s.', (int) $row['id'], $field );
			}
		}
		if ( wp_normalize_path( (string) $row['old_abs_path'] ) !== wp_normalize_path( $expected_old_abs ) ) {
			$errors[] = sprintf( 'Manifest row %d source path is outside or inconsistent with uploads.', (int) $row['id'] );
		}
		if ( (string) $row['new_rel_path'] !== $expected_rel ) {
			$errors[] = sprintf( 'Manifest row %d does not preserve the exact source filename.', (int) $row['id'] );
		}
		if ( wp_normalize_path( (string) $row['new_abs_path'] ) !== wp_normalize_path( $expected_abs ) ) {
			$errors[] = sprintf( 'Manifest row %d target path is not in the uploads root.', (int) $row['id'] );
		}
		if ( (string) $row['new_url'] !== $expected_url ) {
			$errors[] = sprintf( 'Manifest row %d target URL does not match the uploads root URL.', (int) $row['id'] );
		}

		return $errors;
	}

	/**
	 * @param string               $source_path Source path.
	 * @param string               $target_path Target path.
	 * @param array<string, mixed> $row         Manifest row.
	 * @return string
	 */
	private function adoption_hash_warning( $source_path, $target_path, array $row ) {
		clearstatcache( true, $source_path );
		clearstatcache( true, $target_path );

		if ( ! file_exists( $source_path ) || ! file_exists( $target_path ) ) {
			return '';
		}

		$size_mismatch = filesize( $source_path ) !== filesize( $target_path );
		$source_md5 = md5_file( $source_path );
		$target_md5 = md5_file( $target_path );
		if ( $size_mismatch || ( false !== $source_md5 && false !== $target_md5 && $source_md5 !== $target_md5 ) ) {
			return sprintf(
				'Adopted root size for manifest row %d differs from the old derivative; continuing without overwrite.',
				(int) $row['id']
			);
		}

		return '';
	}

	/**
	 * @param array<int, string> $warnings Warning list.
	 * @return int
	 */
	private function count_hash_mismatch_warnings( array $warnings ) {
		$count = 0;
		foreach ( $warnings as $warning ) {
			if ( false !== strpos( $warning, 'differs from the old derivative' ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * @param array<string, mixed> $row Manifest row.
	 * @return bool
	 */
	private function is_existing_root_collision( array $row ) {
		return false !== strpos( (string) ( $row['error_message'] ?? '' ), 'A different file already exists at the uploads-root target path.' );
	}

	/**
	 * @param array<string, mixed> $row Manifest row.
	 * @return bool
	 */
	private function is_duplicate_target_collision( array $row ) {
		return false !== strpos( (string) ( $row['error_message'] ?? '' ), 'Multiple manifest rows resolve to the same target filename.' );
	}

	/**
	 * @param string $path Absolute path.
	 * @return bool
	 */
	private function is_safe_root_derivative_target( $path ) {
		$path = wp_normalize_path( (string) $path );
		if ( '' === $path || ! file_exists( $path ) || ! is_file( $path ) || is_link( $path ) ) {
			return false;
		}
		if ( 0 !== strpos( $path, $this->uploads_base_dir . '/' ) ) {
			return false;
		}

		$relative = ltrim( str_replace( $this->uploads_base_dir, '', $path ), '/\\' );
		return ! preg_match( '~^[0-9]{4}/[0-9]{2}/~', $relative );
	}

	/**
	 * @param string $status Status value.
	 * @return bool
	 */
	private function is_completed_status( $status ) {
		return in_array(
			(string) $status,
			array( self::STATUS_MIGRATED, self::STATUS_ADOPTED_ROOT_SIZE, self::STATUS_OMITTED_COLLISION ),
			true
		);
	}

	/**
	 * @param string $source_path Source path.
	 * @param string $target_path Target path.
	 * @return void
	 */
	private function verify_copy( $source_path, $target_path ) {
		clearstatcache( true, $source_path );
		clearstatcache( true, $target_path );

		if ( ! file_exists( $target_path ) || filesize( $source_path ) !== filesize( $target_path ) ) {
			throw new \RuntimeException( 'Copied file size verification failed: ' . $target_path );
		}

		$source_md5 = md5_file( $source_path );
		$target_md5 = md5_file( $target_path );
		if ( false === $source_md5 || false === $target_md5 || $source_md5 !== $target_md5 ) {
			throw new \RuntimeException( 'Copied file checksum verification failed: ' . $target_path );
		}
	}

	/**
	 * Remove only files created during the failed attempt.
	 *
	 * @param array<int, string> $paths Copied target paths.
	 * @return array<int, string>
	 */
	private function remove_copied_files( array $paths ) {
		$errors = array();

		foreach ( array_reverse( $paths ) as $path ) {
			if ( file_exists( $path ) && ! unlink( $path ) ) {
				$errors[] = 'Could not remove copied target during rollback: ' . $path;
			}
		}

		return $errors;
	}

	/**
	 * @param string $source_path Source path.
	 * @param string $target_path Target path.
	 * @return bool
	 */
	private function is_same_path( $source_path, $target_path ) {
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
	 * @param string $path Path.
	 * @return string
	 */
	private function exact_basename( $path ) {
		$path     = str_replace( '\\', '/', (string) $path );
		$position = strrpos( $path, '/' );

		return false === $position ? $path : substr( $path, $position + 1 );
	}
}
