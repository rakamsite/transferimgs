<?php

namespace MediaFlattenMigrator;

final class Old_URL_Audit_Service {
	const SAMPLE_LIMIT = 20;

	/** @var \wpdb */
	private $wpdb;

	/** @var array<string, array<string, mixed>> */
	private $known_urls;

	public function __construct( Manifest_Repository $repository ) {
		global $wpdb;

		$this->wpdb      = $wpdb;
		$this->known_urls = $this->build_known_urls( $repository->get_all_old_url_rows() );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function initial_result() {
		return array(
			'safe'                                  => true,
			'audited_at'                            => null,
			'migrated_mapping_old_url_remaining'    => 0,
			'non_migrated_manifest_url_remaining'   => 0,
			'orphan_old_upload_url_remaining'       => 0,
			'generic_dated_upload_occurrences'      => 0,
			'samples'                               => array(
				'migrated_mapping_old_url_remaining'  => array(),
				'non_migrated_manifest_url_remaining' => array(),
				'orphan_old_upload_url_remaining'     => array(),
			),
		);
	}

	/**
	 * @return array<int, string>
	 */
	public function stages() {
		return array( 'post_content', 'post_excerpt', 'postmeta', 'options' );
	}

	/**
	 * @return int
	 */
	public function estimate_total() {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->posts}" ) * 2
			+ (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->postmeta}" )
			+ (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->wpdb->options}" );
	}

	/**
	 * @param string               $stage    Audit area.
	 * @param int                  $after_id Cursor.
	 * @param int                  $limit    Batch size.
	 * @param array<string, mixed> $result   Accumulated result.
	 * @return array<string, mixed>
	 */
	public function run_batch( $stage, $after_id, $limit, array $result ) {
		$areas = array(
			'post_content' => array( $this->wpdb->posts, 'ID', 'post_content', '', 'post_id' ),
			'post_excerpt' => array( $this->wpdb->posts, 'ID', 'post_excerpt', '', 'post_id' ),
			'postmeta'     => array( $this->wpdb->postmeta, 'meta_id', 'meta_value', ', post_id', 'post_id' ),
			'options'      => array( $this->wpdb->options, 'option_id', 'option_value', ', option_name', 'option_name' ),
		);
		if ( ! isset( $areas[ $stage ] ) ) {
			throw new \InvalidArgumentException( 'Unknown old URL audit stage.' );
		}

		list( $table, $id_field, $value_field, $extra_fields, $context_field ) = $areas[ $stage ];
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT {$id_field}, {$value_field}{$extra_fields}
				FROM {$table}
				WHERE {$id_field} > %d
				ORDER BY {$id_field} ASC
				LIMIT %d",
				(int) $after_id,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$after_id = (int) $row[ $id_field ];
			$matches  = $this->extract_dated_upload_urls( (string) $row[ $value_field ] );

			foreach ( $matches as $fragment ) {
				++$result['generic_dated_upload_occurrences'];
				$category = $this->classify_fragment( $fragment );
				if ( 'migrated' === $category ) {
					++$result['migrated_mapping_old_url_remaining'];
					$this->add_sample( $result['samples']['migrated_mapping_old_url_remaining'], $stage, $after_id, $fragment, $row, $context_field );
				} elseif ( 'known_non_migrated' === $category ) {
					++$result['non_migrated_manifest_url_remaining'];
					$this->add_sample( $result['samples']['non_migrated_manifest_url_remaining'], $stage, $after_id, $fragment, $row, $context_field );
				} else {
					++$result['orphan_old_upload_url_remaining'];
					$this->add_sample( $result['samples']['orphan_old_upload_url_remaining'], $stage, $after_id, $fragment, $row, $context_field );
				}
			}
		}

		return array(
			'result'            => $result,
			'processed'         => count( $rows ),
			'last_processed_id' => (int) $after_id,
			'done'              => count( $rows ) < max( 1, (int) $limit ),
		);
	}

	/**
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
	 * @param array<string, mixed> $result Audit result.
	 * @return array<string, mixed>
	 */
	public function finalize( array $result ) {
		$result['safe']       = 0 === (int) $result['migrated_mapping_old_url_remaining']
			&& 0 === (int) $result['non_migrated_manifest_url_remaining']
			&& 0 === (int) $result['orphan_old_upload_url_remaining'];
		$result['audited_at'] = current_time( 'mysql' );
		return $result;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Manifest rows.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_known_urls( array $rows ) {
		$known = array();

		foreach ( $rows as $row ) {
			foreach ( $this->build_variants( (string) $row['old_url'] ) as $variant ) {
				$known[ $variant ] = array(
					'status' => in_array( $row['status'], array( 'migrated', 'adopted_root_size', 'omitted_size_collision' ), true )
						? 'migrated'
						: 'known_non_migrated',
				);
			}
		}

		return $known;
	}

	/**
	 * @param string $value Stored value.
	 * @return array<int, string>
	 */
	private function extract_dated_upload_urls( $value ) {
		$patterns = array(
			'~https?:\\\\/\\\\/[^\\s"\'<>]+?\\\\/wp-content\\\\/uploads\\\\/[0-9]{4}\\\\/[0-9]{2}\\\\/[^\\s"\'<>]+~u',
			'~https?://[^\\s"\'<>]+?/wp-content/uploads/[0-9]{4}/[0-9]{2}/[^\\s"\'<>]+~u',
			'~/wp-content/uploads/[0-9]{4}/[0-9]{2}/[^\\s"\'<>]+~u',
			'~\\\\/wp-content\\\\/uploads\\\\/[0-9]{4}\\\\/[0-9]{2}\\\\/[^\\s"\'<>]+~u',
		);
		$matches = array();

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $value, $found ) ) {
				foreach ( $found[0] as $fragment ) {
					$matches[ $fragment ] = true;
				}
			}
		}

		return array_keys( $matches );
	}

	/**
	 * @param string $fragment Matched URL-like fragment.
	 * @return string
	 */
	private function classify_fragment( $fragment ) {
		$variants = $this->build_variants( $fragment );

		foreach ( $variants as $variant ) {
			if ( isset( $this->known_urls[ $variant ] ) ) {
				return $this->known_urls[ $variant ]['status'];
			}
		}

		return 'orphan';
	}

	/**
	 * @param string $value URL-like fragment.
	 * @return array<int, string>
	 */
	private function build_variants( $value ) {
		$variants = array();
		$variants[] = $value;
		$variants[] = str_replace( '\\/', '/', $value );
		$variants[] = str_replace( '/', '\\/', $value );

		foreach ( $variants as $variant ) {
			$decoded = rawurldecode( str_replace( '\\/', '/', $variant ) );
			$variants[] = $decoded;
			$variants[] = str_replace( '/', '\\/', $decoded );
			$variants[] = $this->encode_url_path( $decoded );
			$variants[] = str_replace( '/', '\\/', $this->encode_url_path( $decoded ) );
		}

		$normalized = array();
		foreach ( $variants as $variant ) {
			if ( '' !== $variant ) {
				$normalized[ $variant ] = true;
			}
		}

		return array_keys( $normalized );
	}

	/**
	 * @param string               $sample_list Sample accumulator.
	 * @param string               $area        Area.
	 * @param int                  $row_id      Area row ID.
	 * @param string               $fragment    Matched fragment.
	 * @param array<string, mixed> $row         Source row.
	 * @param string               $context     Extra context field.
	 * @return void
	 */
	private function add_sample( array &$sample_list, $area, $row_id, $fragment, array $row, $context ) {
		if ( count( $sample_list ) >= self::SAMPLE_LIMIT ) {
			return;
		}

		$sample = array(
			'area'         => $area,
			'row_id'       => (int) $row_id,
			'url_fragment' => $fragment,
		);

		if ( 'postmeta' === $area && isset( $row['post_id'] ) ) {
			$sample['post_id'] = (int) $row['post_id'];
		}
		if ( 'options' === $area && isset( $row['option_name'] ) ) {
			$sample['option_name'] = $row['option_name'];
		}

		$sample_list[] = $sample;
	}

	/**
	 * @param string $url URL or path.
	 * @return string
	 */
	private function encode_url_path( $url ) {
		$path = $this->extract_path( $url );
		if ( '' === $path ) {
			return $url;
		}

		return str_replace( $path, $this->encode_path( $path ), $url );
	}

	/**
	 * @param string $path Path.
	 * @return string
	 */
	private function encode_path( $path ) {
		$segments = explode( '/', $path );
		foreach ( $segments as &$segment ) {
			$segment = rawurlencode( rawurldecode( $segment ) );
		}
		unset( $segment );

		return implode( '/', $segments );
	}

	/**
	 * Extract the uploads path even when the original URL contains raw Unicode.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	private function extract_path( $url ) {
		if ( 0 === strpos( $url, '/wp-content/' ) || 0 === strpos( $url, '\\/wp-content\\/' ) ) {
			return str_replace( '\\/', '/', $url );
		}

		$normalized = str_replace( '\\/', '/', $url );
		$position   = strpos( $normalized, '/wp-content/' );
		if ( false === $position ) {
			return '';
		}

		return substr( $normalized, $position );
	}
}
