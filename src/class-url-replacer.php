<?php

namespace MediaFlattenMigrator;

final class URL_Replacer {
	/** @var \wpdb */
	private $wpdb;

	/** @var array<string, string> */
	private $replacements;

	/** @var array<int, array<string, string>> */
	private $mapping_samples;

	/** @var int */
	private $mapping_count;

	/** @var array<int, array<int, string>> */
	private $mapping_variant_sets;

	public function __construct( array $mappings ) {
		global $wpdb;

		$this->wpdb            = $wpdb;
		$this->replacements    = $this->build_replacements( $mappings );
		$this->mapping_samples = array_slice( $mappings, 0, 10 );
		$this->mapping_count   = count( $mappings );
		$this->mapping_variant_sets = $this->build_mapping_variant_sets( $mappings );
	}

	/**
	 * Replace migrated URL mappings in the four allowed database fields.
	 *
	 * @param int  $batch_size Records to read per query.
	 * @param bool $dry_run    Whether to avoid database writes.
	 * @return array<string, mixed>
	 */
	public function run( $batch_size, $dry_run ) {
		$summary = $this->empty_summary();
		$summary['mapping_count'] = $this->mapping_count;

		if ( ! $this->replacements ) {
			return $summary;
		}

		$this->process_posts( $batch_size, $dry_run, $summary );
		$this->process_single_field_table(
			$this->wpdb->postmeta,
			'meta_id',
			'meta_value',
			'postmeta.meta_value',
			$batch_size,
			$dry_run,
			$summary
		);
		$this->process_single_field_table(
			$this->wpdb->options,
			'option_id',
			'option_value',
			'options.option_value',
			$batch_size,
			$dry_run,
			$summary
		);

		$summary['samples'] = $this->mapping_samples;

		return $summary;
	}

	/**
	 * Process one bounded database-area batch for the admin job runner.
	 *
	 * @param string $area       One allowed database area.
	 * @param int    $after_id   Primary-key cursor.
	 * @param int    $batch_size Maximum records to inspect.
	 * @param bool   $dry_run    Whether to avoid writes.
	 * @return array<string, mixed>
	 */
	public function run_batch( $area, $after_id, $batch_size, $dry_run ) {
		$allowed = array(
			'post_content' => array( $this->wpdb->posts, 'ID', 'post_content', 'plain' ),
			'post_excerpt' => array( $this->wpdb->posts, 'ID', 'post_excerpt', 'plain' ),
			'postmeta'     => array( $this->wpdb->postmeta, 'meta_id', 'meta_value', 'structured' ),
			'options'      => array( $this->wpdb->options, 'option_id', 'option_value', 'structured' ),
		);
		if ( ! isset( $allowed[ $area ] ) ) {
			throw new \InvalidArgumentException( 'Unknown URL replacement area.' );
		}

		$batch_size = max( 1, (int) $batch_size );
		list( $table, $id_field, $value_field, $mode ) = $allowed[ $area ];
		$extra_fields = '';
		if ( 'postmeta' === $area ) {
			$extra_fields = ', post_id';
		} elseif ( 'options' === $area ) {
			$extra_fields = ', option_name';
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT {$id_field}, {$value_field}{$extra_fields}
				FROM {$table}
				WHERE {$id_field} > %d
				ORDER BY {$id_field} ASC
				LIMIT %d",
				(int) $after_id,
				$batch_size
			),
			ARRAY_A
		);
		$summary = array(
			'scanned'                        => 0,
			'changed_rows'                   => 0,
			'replacement_count'              => 0,
			'skipped_unsafe_serialized_rows' => 0,
		);

		foreach ( $rows as $row ) {
			$after_id = (int) $row[ $id_field ];
			$result   = 'plain' === $mode
				? $this->transform_plain_string( $row[ $value_field ] )
				: $this->transform_structured_value( $row[ $value_field ] );
			++$summary['scanned'];

			if ( ! empty( $result['unsafe_serialized'] ) ) {
				++$summary['skipped_unsafe_serialized_rows'];
			}
			if ( ! $result['changed'] ) {
				continue;
			}

			++$summary['changed_rows'];
			$summary['replacement_count'] += $result['replacements'];
			if ( $dry_run ) {
				continue;
			}

			$updated = $this->wpdb->update(
				$table,
				array( $value_field => $result['value'] ),
				array( $id_field => $after_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $updated ) {
				throw new \RuntimeException( 'Could not update URL references in ' . $area . ': ' . $this->wpdb->last_error );
			}

			if ( 'post_content' === $area || 'post_excerpt' === $area ) {
				clean_post_cache( $after_id );
			} elseif ( 'postmeta' === $area ) {
				wp_cache_delete( (int) $row['post_id'], 'post_meta' );
			} else {
				wp_cache_delete( $row['option_name'], 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
		}

		return array(
			'area'              => $area,
			'summary'           => $summary,
			'last_processed_id' => (int) $after_id,
			'done'              => count( $rows ) < $batch_size,
		);
	}

	/**
	 * Count remaining migrated URL references and dated upload URL occurrences.
	 *
	 * @return array<string, int>
	 */
	public function remaining_counts() {
		$seen = array(
			'post_content' => array(),
			'post_excerpt' => array(),
			'postmeta'     => array(),
			'options'      => array(),
		);
		$dated_occurrences = 0;

		$this->scan_remaining_posts( $seen, $dated_occurrences );
		$this->scan_remaining_field( $this->wpdb->postmeta, 'meta_id', 'meta_value', 'postmeta', $seen, $dated_occurrences );
		$this->scan_remaining_field( $this->wpdb->options, 'option_id', 'option_value', 'options', $seen, $dated_occurrences );

		return array(
			'migrated_rows_old_url_in_post_content' => count( $seen['post_content'] ),
			'migrated_rows_old_url_in_post_excerpt' => count( $seen['post_excerpt'] ),
			'migrated_rows_old_url_in_postmeta'     => count( $seen['postmeta'] ),
			'migrated_rows_old_url_in_options'      => count( $seen['options'] ),
			'dated_upload_url_occurrences_remaining' => $dated_occurrences,
		);
	}

	/**
	 * @param array<string,array<int,bool>> $seen              Seen mappings.
	 * @param int                          $dated_occurrences Dated URL occurrences.
	 * @return void
	 */
	private function scan_remaining_posts( array &$seen, &$dated_occurrences ) {
		$after_id = 0;

		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT ID, post_content, post_excerpt
					FROM {$this->wpdb->posts}
					WHERE ID > %d ORDER BY ID ASC LIMIT %d",
					$after_id,
					500
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$after_id = (int) $row['ID'];
				$this->inspect_remaining_string( $row['post_content'], 'post_content', $seen, $dated_occurrences );
				$this->inspect_remaining_string( $row['post_excerpt'], 'post_excerpt', $seen, $dated_occurrences );
			}
		} while ( count( $rows ) === 500 );
	}

	/**
	 * @param string                        $table             Allowed table.
	 * @param string                        $id_field          Primary key.
	 * @param string                        $value_field       Value field.
	 * @param string                        $area              Report area.
	 * @param array<string,array<int,bool>> $seen              Seen mappings.
	 * @param int                           $dated_occurrences Dated URL occurrences.
	 * @return void
	 */
	private function scan_remaining_field( $table, $id_field, $value_field, $area, array &$seen, &$dated_occurrences ) {
		$after_id = 0;

		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT {$id_field}, {$value_field}
					FROM {$table}
					WHERE {$id_field} > %d ORDER BY {$id_field} ASC LIMIT %d",
					$after_id,
					500
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$after_id = (int) $row[ $id_field ];
				$this->inspect_remaining_string( $row[ $value_field ], $area, $seen, $dated_occurrences );
			}
		} while ( count( $rows ) === 500 );
	}

	/**
	 * @param string                        $value             Stored value.
	 * @param string                        $area              Report area.
	 * @param array<string,array<int,bool>> $seen              Seen mappings.
	 * @param int                           $dated_occurrences Dated URL occurrences.
	 * @return void
	 */
	private function inspect_remaining_string( $value, $area, array &$seen, &$dated_occurrences ) {
		foreach ( $this->mapping_variant_sets as $mapping_index => $variants ) {
			foreach ( $variants as $variant ) {
				if ( false !== strpos( $value, $variant ) ) {
					$seen[ $area ][ $mapping_index ] = true;
					break;
				}
			}
		}

		$dated_occurrences += preg_match_all(
			'~(?:/|\\\\/)wp-content(?:/|\\\\/)uploads(?:/|\\\\/)[0-9]{4}(?:/|\\\\/)[0-9]{2}(?:/|\\\\/)~',
			$value
		);
	}

	/**
	 * @param int                 $batch_size Batch size.
	 * @param bool                $dry_run    Whether to avoid writes.
	 * @param array<string,mixed> $summary    Summary accumulator.
	 * @return void
	 */
	private function process_posts( $batch_size, $dry_run, array &$summary ) {
		$after_id = 0;

		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT ID, post_content, post_excerpt
					FROM {$this->wpdb->posts}
					WHERE ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					$after_id,
					$batch_size
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$after_id = (int) $row['ID'];
				$data     = array();
				$formats  = array();

				foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
					$result = $this->transform_plain_string( $row[ $field ] );
					if ( $result['changed'] ) {
						$data[ $field ] = $result['value'];
						$formats[]      = '%s';
						++$summary[ 'changed_rows_posts.' . $field ];
						$summary['replacement_count'] += $result['replacements'];
					}
				}

				if ( $data && ! $dry_run ) {
					$result = $this->wpdb->update( $this->wpdb->posts, $data, array( 'ID' => $after_id ), $formats, array( '%d' ) );
					if ( false === $result ) {
						throw new \RuntimeException( 'Could not update posts URL references: ' . $this->wpdb->last_error );
					}
					clean_post_cache( $after_id );
				}
			}
		} while ( count( $rows ) === $batch_size );
	}

	/**
	 * @param string              $table      Allowed WordPress table.
	 * @param string              $id_field   Primary key field.
	 * @param string              $value_field Value field.
	 * @param string              $summary_key Summary key.
	 * @param int                 $batch_size Batch size.
	 * @param bool                $dry_run    Whether to avoid writes.
	 * @param array<string,mixed> $summary    Summary accumulator.
	 * @return void
	 */
	private function process_single_field_table( $table, $id_field, $value_field, $summary_key, $batch_size, $dry_run, array &$summary ) {
		$after_id = 0;
		$extra_fields = '';
		if ( $table === $this->wpdb->postmeta ) {
			$extra_fields = ', post_id';
		} elseif ( $table === $this->wpdb->options ) {
			$extra_fields = ', option_name';
		}

		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT {$id_field}, {$value_field}{$extra_fields}
					FROM {$table}
					WHERE {$id_field} > %d
					ORDER BY {$id_field} ASC
					LIMIT %d",
					$after_id,
					$batch_size
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$after_id = (int) $row[ $id_field ];
				$result   = $this->transform_structured_value( $row[ $value_field ] );

				if ( $result['unsafe_serialized'] ) {
					++$summary['skipped_unsafe_serialized_rows'];
				}
				if ( ! $result['changed'] ) {
					continue;
				}

				++$summary[ 'changed_rows_' . $summary_key ];
				$summary['replacement_count'] += $result['replacements'];

				if ( ! $dry_run ) {
					$updated = $this->wpdb->update(
						$table,
						array( $value_field => $result['value'] ),
						array( $id_field => $after_id ),
						array( '%s' ),
						array( '%d' )
					);
					if ( false === $updated ) {
						throw new \RuntimeException( 'Could not update URL references in ' . $summary_key . ': ' . $this->wpdb->last_error );
					}

					if ( $table === $this->wpdb->postmeta ) {
						wp_cache_delete( (int) $row['post_id'], 'post_meta' );
					} elseif ( $table === $this->wpdb->options ) {
						wp_cache_delete( $row['option_name'], 'options' );
						wp_cache_delete( 'alloptions', 'options' );
						wp_cache_delete( 'notoptions', 'options' );
					}
				}
			}
		} while ( count( $rows ) === $batch_size );
	}

	/**
	 * @param string $value Stored value.
	 * @return array<string, mixed>
	 */
	private function transform_structured_value( $value ) {
		if ( is_serialized( $value ) ) {
			if ( preg_match( '~(?:^|[;{}])(?:R|r):[0-9]+;~', $value ) ) {
				return array(
					'value'             => $value,
					'changed'           => false,
					'replacements'      => 0,
					'unsafe_serialized' => true,
				);
			}

			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
			if ( $this->contains_object( $decoded ) ) {
				return array(
					'value'             => $value,
					'changed'           => false,
					'replacements'      => 0,
					'unsafe_serialized' => true,
				);
			}

			$result = $this->transform_recursive( $decoded );
			return array(
				'value'             => $result['changed'] ? serialize( $result['value'] ) : $value,
				'changed'           => $result['changed'],
				'replacements'      => $result['replacements'],
				'unsafe_serialized' => false,
			);
		}

		$decoded = json_decode( $value );
		if ( JSON_ERROR_NONE === json_last_error() && ( is_array( $decoded ) || is_object( $decoded ) ) ) {
			$result = $this->transform_recursive( $decoded );
			$encoded = $result['changed']
				? wp_json_encode( $result['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
				: $value;
			if ( false === $encoded ) {
				throw new \RuntimeException( 'Could not safely encode JSON after URL replacement.' );
			}

			return array(
				'value'             => $encoded,
				'changed'           => $result['changed'],
				'replacements'      => $result['replacements'],
				'unsafe_serialized' => false,
			);
		}

		$result                      = $this->transform_plain_string( $value );
		$result['unsafe_serialized'] = false;
		return $result;
	}

	/**
	 * @param mixed $value Value to inspect.
	 * @return bool
	 */
	private function contains_object( $value ) {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->contains_object( $item ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param mixed $value Recursive value.
	 * @return array<string, mixed>
	 */
	private function transform_recursive( $value ) {
		if ( is_string( $value ) ) {
			if ( is_serialized( $value ) ) {
				$result = $this->transform_structured_value( $value );
				return array(
					'value'        => $result['value'],
					'changed'      => $result['changed'],
					'replacements' => $result['replacements'],
				);
			}

			return $this->transform_plain_string( $value );
		}

		$changed      = false;
		$replacements = 0;

		if ( is_array( $value ) ) {
			$new_value = array();
			foreach ( $value as $key => $item ) {
				$item_result = $this->transform_recursive( $item );
				$new_value[ $key ] = $item_result['value'];
				$changed           = $changed || $item_result['changed'];
				$replacements     += $item_result['replacements'];
			}
			$value = $new_value;
		} elseif ( is_object( $value ) && $value instanceof \stdClass ) {
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$item_result = $this->transform_recursive( $item );
				$value->{$key} = $item_result['value'];
				$changed       = $changed || $item_result['changed'];
				$replacements += $item_result['replacements'];
			}
		}

		return array(
			'value'        => $value,
			'changed'      => $changed,
			'replacements' => $replacements,
		);
	}

	/**
	 * @param string $value Plain string.
	 * @return array<string, mixed>
	 */
	private function transform_plain_string( $value ) {
		$replacements = 0;
		$new_value    = str_replace(
			array_keys( $this->replacements ),
			array_values( $this->replacements ),
			(string) $value,
			$replacements
		);

		return array(
			'value'        => $new_value,
			'changed'      => $new_value !== $value,
			'replacements' => $replacements,
		);
	}

	/**
	 * @param array<int, array<string, string>> $mappings Migrated mappings.
	 * @return array<string, string>
	 */
	private function build_replacements( array $mappings ) {
		$replacements = array();

		foreach ( $mappings as $mapping ) {
			$old_url = $mapping['old_url'];
			$new_url = $mapping['new_url'];
			$old_path = (string) parse_url( $old_url, PHP_URL_PATH );
			$new_path = (string) parse_url( $new_url, PHP_URL_PATH );

			$this->add_variant( $replacements, $old_url, $new_url );
			$this->add_variant( $replacements, $old_path, $new_path );
			$this->add_variant( $replacements, $this->encode_url_path( $old_url ), $this->encode_url_path( $new_url ) );
			$this->add_variant( $replacements, $this->encode_path( $old_path ), $this->encode_path( $new_path ) );
		}

		uksort(
			$replacements,
			static function ( $left, $right ) {
				return strlen( $right ) <=> strlen( $left );
			}
		);

		return $replacements;
	}

	/**
	 * @param array<int, array<string, string>> $mappings Migrated mappings.
	 * @return array<int, array<int, string>>
	 */
	private function build_mapping_variant_sets( array $mappings ) {
		$sets = array();

		foreach ( $mappings as $mapping ) {
			$variants = array();
			$old_url  = $mapping['old_url'];
			$old_path = (string) parse_url( $old_url, PHP_URL_PATH );

			foreach ( array( $old_url, $old_path, $this->encode_url_path( $old_url ), $this->encode_path( $old_path ) ) as $variant ) {
				if ( '' !== $variant ) {
					$variants[ $variant ] = true;
					$variants[ str_replace( '/', '\\/', $variant ) ] = true;
				}
			}

			$sets[] = array_keys( $variants );
		}

		return $sets;
	}

	/**
	 * @param array<string, string> $replacements Replacement map.
	 * @param string                $old          Old variant.
	 * @param string                $new          New variant.
	 * @return void
	 */
	private function add_variant( array &$replacements, $old, $new ) {
		if ( '' === $old || $old === $new ) {
			return;
		}
		if ( isset( $replacements[ $old ] ) && $replacements[ $old ] !== $new ) {
			throw new \RuntimeException( 'Conflicting migrated URL mappings exist for: ' . $old );
		}

		$replacements[ $old ] = $new;
		$escaped_old          = str_replace( '/', '\\/', $old );
		$escaped_new          = str_replace( '/', '\\/', $new );
		if ( $escaped_old !== $old ) {
			if ( isset( $replacements[ $escaped_old ] ) && $replacements[ $escaped_old ] !== $escaped_new ) {
				throw new \RuntimeException( 'Conflicting migrated URL mappings exist for: ' . $escaped_old );
			}
			$replacements[ $escaped_old ] = $escaped_new;
		}
	}

	/**
	 * @param string $url URL.
	 * @return string
	 */
	private function encode_url_path( $url ) {
		$path = $this->extract_path( $url );
		return str_replace( $path, $this->encode_path( $path ), $url );
	}

	/**
	 * @param string $path URL path.
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

	/**
	 * @return array<string, mixed>
	 */
	private function empty_summary() {
		return array(
			'mapping_count'                    => 0,
			'changed_rows_posts.post_content'  => 0,
			'changed_rows_posts.post_excerpt'  => 0,
			'changed_rows_postmeta.meta_value' => 0,
			'changed_rows_options.option_value' => 0,
			'replacement_count'                => 0,
			'skipped_unsafe_serialized_rows'   => 0,
			'samples'                          => array(),
		);
	}
}
