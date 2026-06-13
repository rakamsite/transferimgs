<?php

namespace MediaFlattenMigrator;

final class Attachment_Scanner {
	/** @var string */
	private $uploads_base_dir;

	/** @var string */
	private $uploads_base_url;

	public function __construct() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'WordPress could not determine the uploads directory: ' . $uploads['error'] );
		}

		$this->uploads_base_dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$this->uploads_base_url = untrailingslashit( $uploads['baseurl'] );
	}

	/**
	 * Perform a read-only attachment and filesystem scan.
	 *
	 * @return Scan_Result
	 */
	public function scan() {
		$after_id    = 0;
		$attachments = array();
		$files       = array();

		do {
			$batch = $this->scan_batch( $after_id, 500 );
			$attachments = array_merge( $attachments, $batch['result']->attachments() );
			$files       = array_merge( $files, $batch['result']->files() );
			$after_id    = $batch['last_processed_id'];
		} while ( ! $batch['done'] );

		$collisions = $this->detect_collisions( $files );
		foreach ( $files as &$file ) {
			$file['collision'] = isset( $collisions[ $file['target_relative'] ] ) ? 'yes' : 'no';
		}
		unset( $file );

		return new Scan_Result( $attachments, $files, $collisions );
	}

	/**
	 * Scan one bounded attachment batch.
	 *
	 * @param int $after_id Only scan attachment IDs greater than this value.
	 * @param int $limit    Maximum attachment count.
	 * @return array<string, mixed>
	 */
	public function scan_batch( $after_id, $limit ) {
		$ids         = $this->find_attachment_ids( $after_id, $limit );
		$attachments = array();
		$files       = array();

		foreach ( $ids as $attachment_id ) {
			$attached_file = get_post_meta( $attachment_id, '_wp_attached_file', true );
			$metadata      = wp_get_attachment_metadata( $attachment_id );
			$file_rows     = $this->build_file_rows( $attachment_id, $attached_file, $metadata );
			$files         = array_merge( $files, $file_rows );
			$size_names    = array();
			$metadata_file = $attached_file;

			if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
				$size_names = array_keys( $metadata['sizes'] );
			}
			if ( is_array( $metadata ) && ! empty( $metadata['file'] ) ) {
				$metadata_file = $metadata['file'];
			}

			$attachments[] = array(
				'attachment_id' => $attachment_id,
				'main_file'     => $attached_file,
				'image_sizes'   => $size_names ? implode( ', ', $size_names ) : '-',
				'original_image' => is_array( $metadata ) && ! empty( $metadata['original_image'] )
					? $this->relative_to_main_directory( $metadata_file, $metadata['original_image'] )
					: '-',
				'metadata'      => is_array( $metadata ) ? 'present' : 'missing',
			);
		}

		$collisions = $this->detect_collisions( $files );
		foreach ( $files as &$file ) {
			$file['collision'] = isset( $collisions[ $file['target_relative'] ] ) ? 'yes' : 'no';
			$file['exists']    = $file['exists'] ? 'yes' : 'no';
		}
		unset( $file );

		return array(
			'result'            => new Scan_Result( $attachments, $files, $collisions ),
			'last_processed_id' => $ids ? (int) end( $ids ) : (int) $after_id,
			'done'              => count( $ids ) < max( 1, (int) $limit ),
		);
	}

	/**
	 * Count attachments that match the dated uploads pattern.
	 *
	 * @return int
	 */
	public function count_attachments() {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'attachment'
					AND pm.meta_key = '_wp_attached_file'
					AND pm.meta_value REGEXP %s",
				'^[0-9]{4}/[0-9]{2}/.+'
			)
		);
	}

	/**
	 * @param int $after_id Only return attachment IDs greater than this value.
	 * @param int $limit    Maximum IDs.
	 * @return array<int, int>
	 */
	private function find_attachment_ids( $after_id, $limit ) {
		global $wpdb;

		$pattern = '^[0-9]{4}/[0-9]{2}/.+';
		$sql     = $wpdb->prepare(
			"SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = 'attachment'
				AND pm.meta_key = '_wp_attached_file'
				AND pm.meta_value REGEXP %s
				AND p.ID > %d
			ORDER BY p.ID ASC
			LIMIT %d",
			$pattern,
			(int) $after_id,
			max( 1, (int) $limit )
		);

		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * @param int          $attachment_id Attachment ID.
	 * @param string       $attached_file Main attachment path relative to uploads.
	 * @param array|false  $metadata      Attachment metadata.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_file_rows( $attachment_id, $attached_file, $metadata ) {
		$metadata_file = is_array( $metadata ) && ! empty( $metadata['file'] )
			? $metadata['file']
			: $attached_file;

		$references = array(
			array(
				'type'     => 'main',
				'size_name' => '-',
				'relative' => $attached_file,
			),
		);

		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size_name => $size ) {
				if ( empty( $size['file'] ) ) {
					continue;
				}

				$references[] = array(
					'type'      => 'image_size',
					'size_name' => $size_name,
					'relative'  => $this->relative_to_main_directory( $metadata_file, $size['file'] ),
				);
			}
		}

		if ( is_array( $metadata ) && ! empty( $metadata['original_image'] ) ) {
			$references[] = array(
				'type'      => 'original_image',
				'size_name' => '-',
				'relative'  => $this->relative_to_main_directory( $metadata_file, $metadata['original_image'] ),
			);
		}

		if ( is_array( $metadata ) && ! empty( $metadata['backup_sizes'] ) && is_array( $metadata['backup_sizes'] ) ) {
			foreach ( $metadata['backup_sizes'] as $backup_key => $backup ) {
				if ( empty( $backup['file'] ) ) {
					continue;
				}

				$references[] = array(
					'type'      => 'backup_size',
					'size_name' => $backup_key,
					'relative'  => $this->relative_to_main_directory( $metadata_file, $backup['file'] ),
				);
			}
		}

		$rows = array();
		foreach ( $references as $reference ) {
			$relative = $this->normalize_relative_path( $reference['relative'] );
			if ( '' === $relative ) {
				continue;
			}

			$source_path     = $this->uploads_base_dir . '/' . $relative;
			$target_relative = $this->exact_basename( $relative );

			$rows[] = array(
				'attachment_id'  => $attachment_id,
				'type'           => $reference['type'],
				'size_name'      => $reference['size_name'],
				'source_relative' => $relative,
				'target_relative' => $target_relative,
				'source_path'     => $source_path,
				'source_url'      => $this->uploads_base_url . '/' . $relative,
				'target_path'     => $this->uploads_base_dir . '/' . $target_relative,
				'exists'          => file_exists( $source_path ),
			);
		}

		return $rows;
	}

	/**
	 * @param string $main_file Main attachment path.
	 * @param string $file      Filename from attachment metadata.
	 * @return string
	 */
	private function relative_to_main_directory( $main_file, $file ) {
		$file = wp_normalize_path( $file );
		if ( false !== strpos( $file, '/' ) ) {
			return $file;
		}

		$directory = dirname( wp_normalize_path( $main_file ) );
		return '.' === $directory ? $file : $directory . '/' . $file;
	}

	/**
	 * Keep filesystem lookups inside the uploads directory.
	 *
	 * @param string $path Relative uploads path.
	 * @return string
	 */
	private function normalize_relative_path( $path ) {
		$path  = ltrim( wp_normalize_path( (string) $path ), '/' );
		$parts = array();

		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}

			if ( '..' === $part ) {
				return '';
			}

			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	/**
	 * Return the final path component without decoding or sanitizing it.
	 *
	 * @param string $path Relative uploads path.
	 * @return string
	 */
	private function exact_basename( $path ) {
		$position = strrpos( $path, '/' );

		return false === $position ? $path : substr( $path, $position + 1 );
	}

	/**
	 * @param array<int, array<string, mixed>> $files Referenced files.
	 * @return array<string, array<string, mixed>>
	 */
	private function detect_collisions( array $files ) {
		$targets = array();

		foreach ( $files as $file ) {
			$targets[ $file['target_relative'] ][ $file['source_relative'] ] = true;
		}

		$collisions = array();
		foreach ( $targets as $target_relative => $source_set ) {
			$sources          = array_keys( $source_set );
			$target_path      = $this->uploads_base_dir . '/' . $target_relative;
			$existing_at_root = file_exists( $target_path ) && ! isset( $source_set[ $target_relative ] );

			if ( count( $sources ) > 1 || $existing_at_root ) {
				$collisions[ $target_relative ] = array(
					'target_relative' => $target_relative,
					'sources'         => implode( ', ', $sources ),
					'existing_at_root' => $existing_at_root ? 'yes' : 'no',
				);
			}
		}

		return $collisions;
	}
}
