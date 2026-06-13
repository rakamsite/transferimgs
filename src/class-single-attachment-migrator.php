<?php

namespace MediaFlattenMigrator;

final class Single_Attachment_Migrator {
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

		$this->repository = $repository;
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
		$errors        = array();
		$attachment    = get_post( $attachment_id );

		if ( ! $rows ) {
			$errors[] = 'No manifest rows exist for this attachment.';
		}
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			$errors[] = 'The selected ID is not a valid attachment.';
		}

		$all_migrated = ! empty( $rows );
		$has_migrated = false;
		$main_rows    = array();
		$target_paths = array();
		$file_rows    = array();

		foreach ( $rows as $row ) {
			$all_migrated = $all_migrated && 'migrated' === $row['status'];
			$has_migrated = $has_migrated || 'migrated' === $row['status'];

			if ( 'main' === $row['file_kind'] ) {
				$main_rows[] = $row;
			}

			if ( 'migrated' !== $row['status'] && 'resolved' !== $row['status'] ) {
				$errors[] = sprintf( 'Manifest row %d has non-resolved status: %s.', $row['id'], $row['status'] );
			}

			foreach ( array( 'new_rel_path', 'new_abs_path', 'new_url' ) as $field ) {
				if ( '' === (string) $row[ $field ] ) {
					$errors[] = sprintf( 'Manifest row %d is missing %s.', $row['id'], $field );
				}
			}

			$expected_rel = $this->exact_basename( $row['old_rel_path'] );
			$expected_old_abs = $this->uploads_base_dir . '/' . ltrim( str_replace( '\\', '/', $row['old_rel_path'] ), '/' );
			$expected_abs = $this->uploads_base_dir . '/' . $expected_rel;
			$expected_url = $this->uploads_base_url . '/' . $expected_rel;
			if ( wp_normalize_path( (string) $row['old_abs_path'] ) !== wp_normalize_path( $expected_old_abs ) ) {
				$errors[] = sprintf( 'Manifest row %d source path is outside or inconsistent with uploads.', $row['id'] );
			}
			if ( $row['new_rel_path'] !== $expected_rel ) {
				$errors[] = sprintf( 'Manifest row %d does not preserve the exact source filename.', $row['id'] );
			}
			if ( wp_normalize_path( (string) $row['new_abs_path'] ) !== wp_normalize_path( $expected_abs ) ) {
				$errors[] = sprintf( 'Manifest row %d target path is not in the uploads root.', $row['id'] );
			}
			if ( $row['new_url'] !== $expected_url ) {
				$errors[] = sprintf( 'Manifest row %d target URL does not match the uploads root URL.', $row['id'] );
			}

			$source_path   = wp_normalize_path( (string) $row['old_abs_path'] );
			$target_path   = wp_normalize_path( (string) $row['new_abs_path'] );
			$source_exists = file_exists( $source_path );
			$target_exists = file_exists( $target_path );
			$same_path     = $this->is_same_path( $source_path, $target_path );

			if ( ! $source_exists && 'migrated' !== $row['status'] ) {
				$errors[] = sprintf( 'Source file does not exist for manifest row %d.', $row['id'] );
			}
			if ( $target_exists && ! $same_path && 'migrated' !== $row['status'] ) {
				$errors[] = sprintf( 'Target file already exists for manifest row %d.', $row['id'] );
			}
			if ( 'migrated' === $row['status'] && ! $target_exists ) {
				$errors[] = sprintf( 'Migrated target file is missing for manifest row %d.', $row['id'] );
			}
			if ( isset( $target_paths[ $target_path ] ) && $target_paths[ $target_path ] !== (int) $row['id'] ) {
				$errors[] = sprintf( 'Multiple manifest rows use target path: %s.', $target_path );
			}
			$target_paths[ $target_path ] = (int) $row['id'];

			$file_rows[] = array(
				'row_id'        => (int) $row['id'],
				'file_kind'     => $row['file_kind'],
				'size_key'      => null === $row['size_key'] ? '-' : $row['size_key'],
				'source_path'   => $source_path,
				'target_path'   => $target_path,
				'source_exists' => $source_exists ? 'yes' : 'no',
				'target_exists' => $target_exists ? 'yes' : 'no',
				'same_path'     => $same_path ? 'yes' : 'no',
			);
		}

		if ( 1 !== count( $main_rows ) ) {
			$errors[] = 'The attachment must have exactly one main manifest row.';
		}
		if ( $has_migrated && ! $all_migrated ) {
			$errors[] = 'Attachment manifest rows are only partially migrated; refusing to continue.';
		}

		$proposed_file = 1 === count( $main_rows ) ? $main_rows[0]['new_rel_path'] : '';
		if ( $rows && ! $all_migrated ) {
			if ( 1 === count( $main_rows ) && $current_file !== $main_rows[0]['old_rel_path'] ) {
				$errors[] = 'Current _wp_attached_file does not match the main manifest source path.';
			}

			$metadata_errors = $this->validate_metadata_mapping( $metadata, $rows );
			$errors          = array_merge( $errors, $metadata_errors );
		} elseif ( $all_migrated ) {
			if ( $current_file !== $proposed_file ) {
				$errors[] = 'Migrated _wp_attached_file does not match the manifest target.';
			}
			$errors = array_merge( $errors, $this->validate_migrated_metadata( $metadata, $rows ) );
		}

		return array(
			'attachment_id'        => $attachment_id,
			'current_attached_file' => $current_file,
			'proposed_attached_file' => $proposed_file,
			'rows'                 => $rows,
			'files'                => $file_rows,
			'metadata'             => $metadata,
			'all_migrated'         => $all_migrated,
			'allowed'              => empty( $errors ),
			'errors'               => array_values( array_unique( $errors ) ),
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
			return array( 'copied' => 0, 'migrated' => count( $plan['rows'] ) );
		}
		if ( ! $plan['allowed'] ) {
			throw new \RuntimeException( implode( ' ', $plan['errors'] ) );
		}

		$attachment_id = (int) $plan['attachment_id'];
		$row_ids       = array_map(
			static function ( $row ) {
				return (int) $row['id'];
			},
			$plan['rows']
		);
		$copied_paths  = array();
		$metadata_was_changed = false;
		$file_was_changed     = false;

		try {
			$this->repository->set_rows_status( $row_ids, 'copying' );

			foreach ( $plan['files'] as $file ) {
				if ( 'yes' === $file['same_path'] ) {
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

			$this->repository->set_rows_status( $row_ids, 'copied' );

			$new_metadata = $this->build_new_metadata( $plan['metadata'], $plan['rows'] );
			$file_was_changed = true;
			update_attached_file( $attachment_id, $plan['proposed_attached_file'] );

			if ( get_post_meta( $attachment_id, '_wp_attached_file', true ) !== $plan['proposed_attached_file'] ) {
				throw new \RuntimeException( 'Could not update _wp_attached_file.' );
			}

			if ( is_array( $new_metadata ) ) {
				$metadata_was_changed = true;
				wp_update_attachment_metadata( $attachment_id, $new_metadata );

				if ( wp_get_attachment_metadata( $attachment_id, true ) !== $new_metadata ) {
					throw new \RuntimeException( 'Could not update attachment metadata.' );
				}
			}

			$this->repository->set_rows_status( $row_ids, 'migrated', null, true );

			return array(
				'copied'   => count( $copied_paths ),
				'migrated' => count( $row_ids ),
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
				if ( wp_get_attachment_metadata( $attachment_id, true ) !== $plan['metadata'] ) {
					$error_message .= ' Rollback could not restore attachment metadata.';
				}
			}

			$cleanup_errors = $this->remove_copied_files( $copied_paths );
			if ( $cleanup_errors ) {
				$error_message .= ' ' . implode( ' ', $cleanup_errors );
			}

			try {
				$this->repository->set_rows_status( $row_ids, 'failed', $error_message );
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
				$errors[] = 'No resolved main manifest row exists for attachment metadata.';
			} elseif ( $metadata['file'] !== $map['main']['old_rel_path'] ) {
				$errors[] = 'Attachment metadata file does not match the main manifest source path.';
			}
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size ) {
				if ( ! isset( $map['image_size'][ $size_key ] ) ) {
					$errors[] = 'No resolved manifest row exists for metadata size: ' . $size_key . '.';
				} elseif ( ! empty( $size['file'] ) && $size['file'] !== $this->exact_basename( $map['image_size'][ $size_key ]['old_rel_path'] ) ) {
					$errors[] = 'Metadata filename does not match manifest source for size: ' . $size_key . '.';
				}
			}
		}
		if ( ! empty( $metadata['original_image'] ) && empty( $map['original_image'] ) ) {
			$errors[] = 'No resolved manifest row exists for original_image.';
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
					$errors[] = 'No resolved manifest row exists for backup size: ' . $backup_key . '.';
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
	 * @return array<int, string>
	 */
	private function validate_migrated_metadata( $metadata, array $rows ) {
		if ( ! is_array( $metadata ) ) {
			return array();
		}

		$errors = array();
		$map    = $this->build_row_map( $rows );

		if ( empty( $map['main'] ) || (string) ( $metadata['file'] ?? '' ) !== $map['main']['new_rel_path'] ) {
			$errors[] = 'Migrated attachment metadata file does not match the manifest target.';
		}
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => $size ) {
				if ( ! isset( $map['image_size'][ $size_key ] )
					|| (string) ( $size['file'] ?? '' ) !== $this->exact_basename( $map['image_size'][ $size_key ]['new_rel_path'] )
				) {
					$errors[] = 'Migrated metadata does not match manifest target for size: ' . $size_key . '.';
				}
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
	 * @param array|false                       $metadata Attachment metadata.
	 * @param array<int, array<string, mixed>> $rows     Manifest rows.
	 * @return array|false
	 */
	private function build_new_metadata( $metadata, array $rows ) {
		if ( ! is_array( $metadata ) ) {
			return false;
		}

		$map = $this->build_row_map( $rows );

		$metadata['file'] = $map['main']['new_rel_path'];

		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_key => &$size ) {
				$size['file'] = $this->exact_basename( $map['image_size'][ $size_key ]['new_rel_path'] );
			}
			unset( $size );
		}
		if ( ! empty( $metadata['original_image'] ) ) {
			$metadata['original_image'] = $this->exact_basename( $map['original_image']['new_rel_path'] );
		}
		if ( ! empty( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
			foreach ( $metadata['backup_sizes'] as $backup_key => &$backup ) {
				if ( ! empty( $backup['file'] ) ) {
					$backup['file'] = $this->exact_basename( $map['backup_size'][ $backup_key ]['new_rel_path'] );
				}
			}
			unset( $backup );
		}

		return $metadata;
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
