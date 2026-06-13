<?php

namespace MediaFlattenMigrator;

final class Usage_Reporter {
	const BATCH_SIZE = 500;

	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $manifest_table;

	/** @var Manifest_Repository */
	private $repository;

	public function __construct( Manifest_Repository $repository ) {
		global $wpdb;

		$this->wpdb           = $wpdb;
		$this->manifest_table = Schema::table_name();
		$this->repository     = $repository;
	}

	/**
	 * Return read-only attachment usage totals.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function usage_counts() {
		if ( ! $this->repository->table_exists() ) {
			return array();
		}

		$manifest_ids = "(SELECT DISTINCT attachment_id FROM {$this->manifest_table}) manifest_ids";
		$metrics      = array(
			'post_featured_images' => "SELECT COUNT(DISTINCT manifest_ids.attachment_id)
				FROM {$manifest_ids}
				INNER JOIN {$this->wpdb->postmeta} pm
					ON pm.meta_key = '_thumbnail_id'
					AND CAST(pm.meta_value AS UNSIGNED) = manifest_ids.attachment_id
				INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type NOT IN ('product', 'product_variation')",
			'product_featured_images' => "SELECT COUNT(DISTINCT manifest_ids.attachment_id)
				FROM {$manifest_ids}
				INNER JOIN {$this->wpdb->postmeta} pm
					ON pm.meta_key = '_thumbnail_id'
					AND CAST(pm.meta_value AS UNSIGNED) = manifest_ids.attachment_id
				INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'product'",
			'variation_featured_images' => "SELECT COUNT(DISTINCT manifest_ids.attachment_id)
				FROM {$manifest_ids}
				INNER JOIN {$this->wpdb->postmeta} pm
					ON pm.meta_key = '_thumbnail_id'
					AND CAST(pm.meta_value AS UNSIGNED) = manifest_ids.attachment_id
				INNER JOIN {$this->wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = 'product_variation'",
			'product_gallery_images' => "SELECT COUNT(DISTINCT manifest_ids.attachment_id)
				FROM {$manifest_ids}
				INNER JOIN {$this->wpdb->postmeta} pm
					ON pm.meta_key = '_product_image_gallery'
					AND FIND_IN_SET(CAST(manifest_ids.attachment_id AS CHAR), pm.meta_value) > 0
				INNER JOIN {$this->wpdb->posts} p
					ON p.ID = pm.post_id
					AND p.post_type = 'product'",
			'old_url_in_post_content' => "SELECT COUNT(DISTINCT m.attachment_id)
				FROM {$this->manifest_table} m
				INNER JOIN {$this->wpdb->posts} p
					ON m.old_url IS NOT NULL
					AND m.old_url <> ''
					AND LOCATE(m.old_url, p.post_content) > 0",
			'old_url_in_post_excerpt' => "SELECT COUNT(DISTINCT m.attachment_id)
				FROM {$this->manifest_table} m
				INNER JOIN {$this->wpdb->posts} p
					ON m.old_url IS NOT NULL
					AND m.old_url <> ''
					AND LOCATE(m.old_url, p.post_excerpt) > 0",
		);

		$rows = array();
		foreach ( $metrics as $metric => $sql ) {
			$rows[] = array(
				'metric'           => $metric,
				'attachment_count' => (int) $this->wpdb->get_var( $sql ),
			);
		}

		return $rows;
	}

	/**
	 * Return file extension groups and non-ASCII filename count.
	 *
	 * @return array<string, mixed>
	 */
	public function filename_counts() {
		if ( ! $this->repository->table_exists() ) {
			return array(
				'extensions'            => array(),
				'non_ascii_filenames'   => 0,
			);
		}

		$extensions          = array(
			'webp'     => 0,
			'png'      => 0,
			'jpg/jpeg' => 0,
			'gif'      => 0,
			'svg'      => 0,
			'pdf'      => 0,
			'other'    => 0,
		);
		$non_ascii_filenames = 0;
		$after_id            = 0;

		do {
			$rows = $this->repository->get_rows( $after_id, self::BATCH_SIZE );

			foreach ( $rows as $row ) {
				$after_id = (int) $row['id'];
				$filename = $this->exact_basename( $row['old_rel_path'] );
				$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

				if ( 'jpg' === $extension || 'jpeg' === $extension ) {
					++$extensions['jpg/jpeg'];
				} elseif ( isset( $extensions[ $extension ] ) && 'other' !== $extension ) {
					++$extensions[ $extension ];
				} else {
					++$extensions['other'];
				}

				if ( preg_match( '/[^\x00-\x7F]/', $filename ) ) {
					++$non_ascii_filenames;
				}
			}
		} while ( count( $rows ) === self::BATCH_SIZE );

		$extension_rows = array();
		foreach ( $extensions as $extension => $count ) {
			$extension_rows[] = array(
				'extension'  => $extension,
				'file_count' => $count,
			);
		}

		return array(
			'extensions'          => $extension_rows,
			'non_ascii_filenames' => $non_ascii_filenames,
		);
	}

	/**
	 * @param string $path Relative uploads path.
	 * @return string
	 */
	private function exact_basename( $path ) {
		$path     = str_replace( '\\', '/', (string) $path );
		$position = strrpos( $path, '/' );

		return false === $position ? $path : substr( $path, $position + 1 );
	}
}
