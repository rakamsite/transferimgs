<?php

namespace MediaFlattenMigrator;

final class Redirect_Export_Service {
	const SAMPLE_LIMIT = 20;
	const STATE_OPTION  = 'media_flatten_last_redirect_export_result';
	const EXPORT_DIR    = 'media-flatten-exports';

	/** @var \wpdb */
	private $wpdb;

	/** @var Manifest_Repository */
	private $repository;

	/** @var string */
	private $uploads_base_dir;

	/** @var string */
	private $uploads_base_url;

	/** @var string */
	private $exports_dir;

	public function __construct( Manifest_Repository $repository ) {
		global $wpdb;

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'WordPress could not determine the uploads directory: ' . $uploads['error'] );
		}

		$this->wpdb             = $wpdb;
		$this->repository       = $repository;
		$this->uploads_base_dir = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$this->uploads_base_url = untrailingslashit( (string) $uploads['baseurl'] );
		$this->exports_dir      = $this->uploads_base_dir . '/' . self::EXPORT_DIR;
	}

	/**
	 * Read the latest stored export state.
	 *
	 * @return array<string, mixed>
	 */
	public function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Return a readiness summary based on the last verification and audit state.
	 *
	 * @return array<string, mixed>
	 */
	public function readiness() {
		$verify = get_option( Admin_Controller::VERIFY_RESULT_OPTION, array() );
		$audit  = get_option( Admin_Controller::OLD_URL_AUDIT_RESULT_OPTION, array() );
		$state  = $this->state();
		$counts = $this->repository->status_counts();
		$statuses = array();
		foreach ( $counts as $row ) {
			$statuses[ $row['status'] ] = (int) $row['item_count'];
		}

		$duplicate_groups = $this->repository->get_duplicate_logical_groups();
		$preview_state    = isset( $state['preview'] ) && is_array( $state['preview'] ) ? $state['preview'] : array();
		$export_states    = isset( $state['exports'] ) && is_array( $state['exports'] ) ? $state['exports'] : array();
		$preview_summary  = $this->preview_status( $preview_state );
		$export_summary   = $this->export_status( $export_states );
		$redirect_conflicts = array();
		if ( ! empty( $preview_state['duplicate_conflicts'] ) && is_array( $preview_state['duplicate_conflicts'] ) ) {
			$redirect_conflicts = $preview_state['duplicate_conflicts'];
		} else {
			foreach ( array( 'apache', 'nginx', 'csv' ) as $format ) {
				if ( ! empty( $export_states[ $format ]['duplicate_conflicts'] ) && is_array( $export_states[ $format ]['duplicate_conflicts'] ) ) {
					$redirect_conflicts = $export_states[ $format ]['duplicate_conflicts'];
					break;
				}
			}
		}
		$ready            = ! empty( $verify['pass'] )
			&& ! empty( $verify['pre_redirect_ready'] )
			&& ! empty( $verify['redirect_export_ready'] )
			&& ! empty( $audit['safe'] )
			&& 0 === (int) $this->repository->count_invalid_migrated_url_rows()
			&& empty( $statuses['copying'] )
			&& empty( $statuses['copied'] )
			&& empty( $statuses['failed'] )
			&& empty( $duplicate_groups )
			&& empty( $redirect_conflicts );

		$warnings = array();
		if ( empty( $verify ) ) {
			$warnings[] = 'Verification has not been run yet.';
		} elseif ( empty( $verify['pass'] ) ) {
			$warnings[] = 'Verification has failures.';
		}
		if ( empty( $audit ) ) {
			$warnings[] = 'Old URL audit has not been run yet.';
		} elseif ( empty( $audit['safe'] ) ) {
			$warnings[] = 'Old URL audit found remaining dated upload URLs.';
		}
		if ( ! empty( $statuses['copying'] ) || ! empty( $statuses['copied'] ) || ! empty( $statuses['failed'] ) ) {
			$warnings[] = 'The manifest still contains incomplete migration statuses.';
		}
		if ( ! empty( $duplicate_groups ) ) {
			$warnings[] = 'Duplicate manifest logical rows exist.';
		}
		if ( ! empty( $redirect_conflicts ) ) {
			$warnings[] = 'Duplicate redirect path conflicts were found in the latest preview or export.';
		}

		return array(
			'ready'                         => $ready,
			'verify'                        => $verify,
			'old_url_audit'                 => $audit,
			'status_counts'                 => $statuses,
			'duplicate_manifest_rows'       => count( $duplicate_groups ),
			'redirect_path_conflicts'       => count( $redirect_conflicts ),
			'redirect_preview_status'       => $preview_summary,
			'redirect_export_status'        => $export_summary,
			'redirect_preview_ready'        => ! empty( $preview_summary['ready'] ),
			'redirect_preview_has_run'      => ! empty( $preview_summary['has_run'] ),
			'redirect_export_has_run'       => ! empty( $export_summary['has_run'] ),
			'redirect_export_ready'         => $ready,
			'warnings'                      => $warnings,
			'migrated_rows_available'       => $this->repository->count_migrated_rows(),
			'redirect_rules_to_export'      => (int) ( $preview_state['redirect_rule_count'] ?? $export_summary['redirect_rule_count'] ?? 0 ),
			'export_warnings_count'         => 0,
			'export_errors_count'           => 0,
			'latest_exports'                => $this->latest_exports(),
			'generated_at'                  => $state['generated_at'] ?? null,
		);
	}

	/**
	 * Produce a read-only preview of the redirect export.
	 *
	 * @param int $sample_limit Sample rows to return.
	 * @param bool $store       Whether to persist the latest preview summary.
	 * @return array<string, mixed>
	 */
	public function preview( $sample_limit = self::SAMPLE_LIMIT, $store = false ) {
		$built = $this->build_mappings( 500, false, max( 1, (int) $sample_limit ) );
		$built['readiness'] = $this->readiness();
		$built['ready']     = $built['readiness']['ready'] && empty( $built['duplicate_conflicts'] );
		if ( $store ) {
			$this->store_latest_preview( $built );
		}
		return $built;
	}

	/**
	 * Generate and optionally write one redirect export file.
	 *
	 * @param string      $format        Export format.
	 * @param string|null $output_path    Optional destination path.
	 * @param int         $batch_size     Read batch size.
	 * @param bool        $store_latest   Whether to store the latest export metadata.
	 * @return array<string, mixed>
	 */
	public function generate( $format, $output_path = null, $batch_size = 500, $store_latest = true ) {
		$format = strtolower( trim( (string) $format ) );
		if ( ! in_array( $format, array( 'apache', 'nginx', 'csv' ), true ) ) {
			throw new \InvalidArgumentException( 'Unknown redirect export format.' );
		}

		$built = $this->build_mappings( max( 1, (int) $batch_size ), true, self::SAMPLE_LIMIT );
		if ( ! empty( $built['errors'] ) ) {
			throw new \RuntimeException( 'Redirect export cannot be generated until mapping errors are resolved: ' . implode( ' ', $built['errors'] ) );
		}
		if ( ! empty( $built['duplicate_conflicts'] ) ) {
			throw new \RuntimeException( 'Conflicting redirect mappings were found. Resolve duplicate old-path targets before exporting.' );
		}

		$content = $this->render_export( $format, $built );
		$written  = null;
		if ( null !== $output_path && '' !== (string) $output_path ) {
			$this->ensure_directory_for_path( $output_path );
			if ( false === file_put_contents( $output_path, $content ) ) {
				throw new \RuntimeException( 'Could not write redirect export file: ' . $output_path );
			}
			$written = wp_normalize_path( $output_path );
		}

		$export_info = array(
			'format'                    => $format,
			'generated_at'              => current_time( 'mysql' ),
			'file_name'                 => null,
			'file_path'                 => $written,
			'bytes'                     => strlen( $content ),
			'redirect_rule_count'       => $built['redirect_rule_count'],
			'export_rule_count'         => $built['redirect_rule_count'],
			'total_migrated_mappings'   => $built['total_migrated_mappings'],
			'deduplicated_mappings'     => $built['deduplicated_mappings'],
			'skipped_same_path'         => $built['skipped_same_path'],
			'duplicate_conflict_count'  => count( $built['duplicate_conflicts'] ),
			'duplicate_conflicts'       => $built['duplicate_conflicts'],
			'extension_counts'          => $built['extension_counts'],
			'unicode_filename_count'    => $built['unicode_filename_count'],
			'preview_samples'           => $built['samples'],
			'warnings'                  => $this->build_export_warnings( $built ),
			'errors'                    => $built['errors'],
			'ready'                     => $this->readiness()['ready'],
		);

		if ( null === $written ) {
			$export_info['content'] = $content;
		} else {
			$export_info['file_name'] = basename( $written );
		}

		if ( $store_latest && null !== $written && $this->is_inside_exports_dir( $written ) ) {
			$this->store_latest_export( $format, $export_info );
		}

		return $export_info;
	}

	/**
	 * Return the latest stored export file for a format.
	 *
	 * @param string $format Export format.
	 * @return array<string, mixed>
	 */
	public function latest_export( $format ) {
		$state = $this->state();
		$format = strtolower( trim( (string) $format ) );
		if ( empty( $state['exports'][ $format ] ) || ! is_array( $state['exports'][ $format ] ) ) {
			return array();
		}

		return $state['exports'][ $format ];
	}

	/**
	 * Return the current export directory, creating it if needed.
	 *
	 * @return string
	 */
	public function ensure_exports_dir() {
		if ( ! file_exists( $this->exports_dir ) ) {
			if ( ! wp_mkdir_p( $this->exports_dir ) ) {
				throw new \RuntimeException( 'Could not create the redirect export directory.' );
			}
		}

		$this->ensure_index_html();
		return $this->exports_dir;
	}

	/**
	 * Build a plugin-managed export filename.
	 *
	 * @param string $format Export format.
	 * @return string
	 */
	public function build_filename( $format ) {
		$format = strtolower( trim( (string) $format ) );
		$timestamp = current_time( 'Ymd-His' );
		if ( 'apache' === $format ) {
			return 'media-flatten-redirects-apache-' . $timestamp . '.conf';
		}
		if ( 'nginx' === $format ) {
			return 'media-flatten-redirects-nginx-' . $timestamp . '.conf';
		}

		return 'media-flatten-redirects-' . $timestamp . '.csv';
	}

	/**
	 * Return a public-safe admin-post download URL.
	 *
	 * @param string $format Export format.
	 * @return string
	 */
	public function download_url( $format ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=media_flatten_download_redirect_export&format=' . rawurlencode( strtolower( (string) $format ) ) ),
			Admin_Controller::NONCE_ACTION
		);
	}

	/**
	 * Collect and deduplicate mappings from migrated manifest rows.
	 *
	 * @param int  $batch_size  Batch size.
	 * @param bool $for_export  Whether the caller intends to generate a file.
	 * @return array<string, mixed>
	 */
	private function build_mappings( $batch_size, $for_export, $sample_limit = self::SAMPLE_LIMIT ) {
		$batch_size = max( 1, (int) $batch_size );
		$sample_limit = max( 0, (int) $sample_limit );
		$after_id   = 0;
		$seen       = array();
		$records    = array();
		$errors     = array();
		$warnings   = array();
		$samples    = array();
		$duplicate_conflicts = array();
		$stats      = array(
			'total_migrated_mappings' => 0,
			'redirect_rule_count'     => 0,
			'deduplicated_mappings'   => 0,
			'skipped_same_path'       => 0,
			'extension_counts'        => array(
				'webp'     => 0,
				'png'      => 0,
				'jpg/jpeg' => 0,
				'gif'      => 0,
				'svg'      => 0,
				'pdf'      => 0,
				'other'    => 0,
			),
			'unicode_filename_count'  => 0,
		);

		do {
			$rows = $this->repository->get_redirect_export_rows( $after_id, $batch_size );
			foreach ( $rows as $row ) {
				$after_id = (int) $row['id'];
				$stats['total_migrated_mappings']++;

				$old_url = trim( (string) $row['old_url'] );
				$new_url = 'omitted_size_collision' === (string) $row['status']
					? trim( (string) $row['main_new_url'] )
					: trim( (string) $row['new_url'] );
				if ( '' === $old_url || '' === $new_url ) {
					$errors[] = sprintf( 'Migrated manifest row %d is missing a URL mapping.', $row['id'] );
					continue;
				}

				$old_path_raw = $this->extract_url_path( $old_url );
				$new_path_raw = $this->extract_url_path( $new_url );
				if ( '' === $old_path_raw || '' === $new_path_raw ) {
					$errors[] = sprintf( 'Migrated manifest row %d has an invalid URL path.', $row['id'] );
					continue;
				}

				$old_path = $this->normalize_redirect_path( $old_path_raw );
				$new_path = $this->normalize_redirect_path( $new_path_raw );
				if ( '' === $old_path || '' === $new_path ) {
					$errors[] = sprintf( 'Migrated manifest row %d could not be normalized into a redirect path.', $row['id'] );
					continue;
				}

				if ( $old_path === $new_path ) {
					++$stats['skipped_same_path'];
					continue;
				}

				$old_rel_path = (string) $row['old_rel_path'];
				$basename     = $this->exact_basename( '' !== $old_rel_path ? $old_rel_path : $old_path_raw );
				if ( preg_match( '/[^\x00-\x7F]/', $basename ) ) {
					++$stats['unicode_filename_count'];
				}
				$extension = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
				if ( 'jpg' === $extension || 'jpeg' === $extension ) {
					++$stats['extension_counts']['jpg/jpeg'];
				} elseif ( isset( $stats['extension_counts'][ $extension ] ) ) {
					++$stats['extension_counts'][ $extension ];
				} else {
					++$stats['extension_counts']['other'];
				}

				$record = array(
					'attachment_id'       => (int) $row['attachment_id'],
					'file_kind'           => (string) $row['file_kind'],
					'size_key'            => '' === (string) $row['size_key'] ? '' : (string) $row['size_key'],
					'status'              => (string) $row['status'],
					'old_rel_path'        => (string) $row['old_rel_path'],
					'new_rel_path'        => 'omitted_size_collision' === (string) $row['status']
						? (string) $row['main_new_rel_path']
						: (string) $row['new_rel_path'],
					'old_url'             => $old_url,
					'new_url'             => $new_url,
					'old_path_for_redirect' => $old_path,
					'new_path_for_redirect' => $new_path,
					'extension'           => '' !== $extension ? $extension : 'other',
				);

				if ( ! isset( $seen[ $old_path ] ) ) {
					$seen[ $old_path ] = $record;
					$records[]         = $record;
					if ( count( $samples ) < $sample_limit ) {
						$samples[] = $record;
					}
					++$stats['redirect_rule_count'];
					continue;
				}

				if ( $seen[ $old_path ]['new_path_for_redirect'] === $new_path ) {
					++$stats['deduplicated_mappings'];
					continue;
				}

				$duplicate_conflicts[ $old_path ] = array(
					'old_path_for_redirect'    => $old_path,
					'existing_new_path'        => $seen[ $old_path ]['new_path_for_redirect'],
					'conflicting_new_path'      => $new_path,
					'existing_attachment_id'    => $seen[ $old_path ]['attachment_id'],
					'conflicting_attachment_id' => (int) $row['attachment_id'],
				);
			}
		} while ( count( $rows ) === $batch_size );

		if ( $for_export ) {
			foreach ( $duplicate_conflicts as $duplicate ) {
				$errors[] = sprintf(
					'Duplicate redirect path %s maps to both %s and %s.',
					$duplicate['old_path_for_redirect'],
					$duplicate['existing_new_path'],
					$duplicate['conflicting_new_path']
				);
			}
		}

		return array(
			'mappings'                => $records,
			'samples'                 => $samples,
			'errors'                  => $errors,
			'warnings'                => $this->build_export_warnings(
				array(
					'redirect_rule_count'     => $stats['redirect_rule_count'],
					'deduplicated_mappings'   => $stats['deduplicated_mappings'],
					'skipped_same_path'       => $stats['skipped_same_path'],
					'duplicate_conflicts'     => $duplicate_conflicts,
					'extension_counts'        => $stats['extension_counts'],
					'unicode_filename_count'  => $stats['unicode_filename_count'],
					'total_migrated_mappings'  => $stats['total_migrated_mappings'],
				)
			),
			'duplicate_conflicts'     => $duplicate_conflicts,
			'total_migrated_mappings' => $stats['total_migrated_mappings'],
			'redirect_rule_count'     => $stats['redirect_rule_count'],
			'deduplicated_mappings'   => $stats['deduplicated_mappings'],
			'skipped_same_path'       => $stats['skipped_same_path'],
			'extension_counts'        => $stats['extension_counts'],
			'unicode_filename_count'  => $stats['unicode_filename_count'],
		);
	}

	/**
	 * Build export content for the selected format.
	 *
	 * @param string               $format Export format.
	 * @param array<string, mixed>  $built  Mapping build result.
	 * @return string
	 */
	private function render_export( $format, array $built ) {
		if ( 'csv' === $format ) {
			return $this->render_csv( $built['mappings'] );
		}

		$site_url = home_url( '/' );
		$time     = current_time( 'mysql' );
		$count    = count( $built['mappings'] );
		$lines    = array();

		if ( 'apache' === $format ) {
			$lines[] = '# Generated by Media Flatten Migrator';
			$lines[] = '# Site URL: ' . $site_url;
			$lines[] = '# Generation time: ' . $time;
			$lines[] = '# Number of redirect rules: ' . $count;
			$lines[] = '# Warning: test before using in production.';
			$lines[] = '# Add these before the WordPress rewrite block if using .htaccess.';
			$lines[] = '# Path segments are URL-encoded when needed.';
			$lines[] = '';

			foreach ( $built['mappings'] as $mapping ) {
				$lines[] = 'Redirect 301 ' . $mapping['old_path_for_redirect'] . ' ' . $mapping['new_path_for_redirect'];
			}

			return implode( "\n", $lines ) . "\n";
		}

		$lines[] = '# Generated by Media Flatten Migrator';
		$lines[] = '# Site URL: ' . $site_url;
		$lines[] = '# Generation time: ' . $time;
		$lines[] = '# Number of redirect rules: ' . $count;
		$lines[] = '# Warning: test before using in production.';
		$lines[] = '# Path segments are URL-encoded when needed.';
		$lines[] = '';

		foreach ( $built['mappings'] as $mapping ) {
			$lines[] = 'location = ' . $mapping['old_path_for_redirect'] . ' {';
			$lines[] = '	return 301 ' . $mapping['new_path_for_redirect'] . ';';
			$lines[] = '}';
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render CSV output for manual review.
	 *
	 * @param array<int, array<string, mixed>> $mappings Export mappings.
	 * @return string
	 */
	private function render_csv( array $mappings ) {
		$handle = fopen( 'php://temp', 'r+' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open a temporary stream for CSV export.' );
		}

		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv(
			$handle,
			array(
				'attachment_id',
				'file_kind',
				'size_key',
				'old_rel_path',
				'new_rel_path',
				'old_url',
				'new_url',
				'old_path_for_redirect',
				'new_path_for_redirect',
				'extension',
				'status',
			)
		);

		foreach ( $mappings as $mapping ) {
			fputcsv(
				$handle,
				array(
					$mapping['attachment_id'],
					$mapping['file_kind'],
					$mapping['size_key'],
					$mapping['old_rel_path'],
					$mapping['new_rel_path'],
					$mapping['old_url'],
					$mapping['new_url'],
					$mapping['old_path_for_redirect'],
					$mapping['new_path_for_redirect'],
					$mapping['extension'],
					$mapping['status'],
				)
			);
		}

		rewind( $handle );
		$content = stream_get_contents( $handle );
		fclose( $handle );

		return false === $content ? '' : $content;
	}

	/**
	 * @param array<string, mixed> $built Built mapping summary.
	 * @return array<int, string>
	 */
	private function build_export_warnings( array $built ) {
		$warnings = array();
		if ( ! empty( $built['duplicate_conflicts'] ) ) {
			$warnings[] = 'Duplicate redirect paths were found and must be resolved manually.';
		}
		if ( ! empty( $built['deduplicated_mappings'] ) ) {
			$warnings[] = sprintf( '%d duplicate redirect mapping(s) were deduplicated.', (int) $built['deduplicated_mappings'] );
		}
		if ( ! empty( $built['skipped_same_path'] ) ) {
			$warnings[] = sprintf( '%d migrated row(s) already pointed at the same path and were skipped.', (int) $built['skipped_same_path'] );
		}
		if ( ! empty( $built['unicode_filename_count'] ) ) {
			$warnings[] = sprintf( '%d mapping(s) use Persian / non-ASCII filenames.', (int) $built['unicode_filename_count'] );
		}

		return $warnings;
	}

	/**
	 * Persist the latest export metadata.
	 *
	 * @param string               $format Export format.
	 * @param array<string, mixed>  $export Export metadata.
	 * @return void
	 */
	private function store_latest_export( $format, array $export ) {
		$state = $this->state();
		if ( empty( $state ) || ! is_array( $state ) ) {
			$state = array();
		}
		if ( empty( $state['exports'] ) || ! is_array( $state['exports'] ) ) {
			$state['exports'] = array();
		}

		$state['generated_at']            = current_time( 'mysql' );
		$state['ready']                   = $this->readiness()['ready'];
		$state['warnings']                = $export['warnings'];
		$state['errors']                  = $export['errors'];
		$state['exports'][ $format ]   = array(
			'format'                 => $format,
			'generated_at'           => $export['generated_at'],
			'file_name'              => basename( (string) $export['file_path'] ),
			'file_path'              => $export['file_path'],
			'bytes'                  => $export['bytes'],
			'redirect_rule_count'    => $export['redirect_rule_count'],
			'total_migrated_mappings'=> $export['total_migrated_mappings'],
			'deduplicated_mappings'  => $export['deduplicated_mappings'],
			'skipped_same_path'      => $export['skipped_same_path'],
			'duplicate_conflicts'    => $export['duplicate_conflicts'],
			'status'                 => empty( $export['errors'] ) && empty( $export['duplicate_conflicts'] ) ? 'pass' : 'fail',
			'preview'                => array(
				'generated_at'            => $export['generated_at'],
				'ready'                   => empty( $export['errors'] ) && empty( $export['duplicate_conflicts'] ),
				'status'                  => empty( $export['errors'] ) && empty( $export['duplicate_conflicts'] ) ? 'pass' : 'fail',
				'redirect_rule_count'     => $export['redirect_rule_count'],
				'total_migrated_mappings' => $export['total_migrated_mappings'],
				'deduplicated_mappings'   => $export['deduplicated_mappings'],
				'skipped_same_path'       => $export['skipped_same_path'],
				'duplicate_conflicts'     => $export['duplicate_conflicts'],
				'extension_counts'        => $export['extension_counts'],
				'unicode_filename_count'  => $export['unicode_filename_count'],
				'warnings'                => $export['warnings'],
				'errors'                  => $export['errors'],
			),
			'extension_counts'       => $export['extension_counts'],
			'unicode_filename_count' => $export['unicode_filename_count'],
			'warnings'               => $export['warnings'],
			'errors'                 => $export['errors'],
		);

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Persist the latest preview summary without creating a file.
	 *
	 * @param array<string, mixed> $preview Preview data.
	 * @return void
	 */
	private function store_latest_preview( array $preview ) {
		$state = $this->state();
		if ( empty( $state ) || ! is_array( $state ) ) {
			$state = array();
		}
		if ( empty( $state['exports'] ) || ! is_array( $state['exports'] ) ) {
			$state['exports'] = array();
		}

		$state['generated_at']   = current_time( 'mysql' );
		$state['ready']          = ! empty( $preview['ready'] );
		$state['warnings']       = $preview['warnings'];
		$state['errors']         = $preview['errors'];
		$state['preview']        = array(
			'generated_at'           => $preview['generated_at'] ?? current_time( 'mysql' ),
			'ready'                  => ! empty( $preview['ready'] ),
			'status'                 => ! empty( $preview['ready'] ) ? 'pass' : 'fail',
			'redirect_rule_count'    => $preview['redirect_rule_count'],
			'total_migrated_mappings'=> $preview['total_migrated_mappings'],
			'deduplicated_mappings'  => $preview['deduplicated_mappings'],
			'skipped_same_path'      => $preview['skipped_same_path'],
			'duplicate_conflicts'    => $preview['duplicate_conflicts'],
			'extension_counts'       => $preview['extension_counts'],
			'unicode_filename_count' => $preview['unicode_filename_count'],
			'warnings'               => $preview['warnings'],
			'errors'                 => $preview['errors'],
		);
		$state['latest_preview'] = array(
			'generated_at'            => $preview['generated_at'] ?? current_time( 'mysql' ),
			'ready'                   => ! empty( $preview['ready'] ),
			'status'                  => ! empty( $preview['ready'] ) ? 'pass' : 'fail',
			'redirect_rule_count'     => $preview['redirect_rule_count'],
			'total_migrated_mappings' => $preview['total_migrated_mappings'],
			'deduplicated_mappings'   => $preview['deduplicated_mappings'],
			'skipped_same_path'       => $preview['skipped_same_path'],
			'duplicate_conflicts'     => $preview['duplicate_conflicts'],
			'extension_counts'        => $preview['extension_counts'],
			'unicode_filename_count'  => $preview['unicode_filename_count'],
		);

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Return the latest exports for every supported format.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function latest_exports() {
		$state = $this->state();
		return isset( $state['exports'] ) && is_array( $state['exports'] ) ? $state['exports'] : array();
	}

	/**
	 * Summarize the latest redirect preview state.
	 *
	 * @param array<string, mixed> $preview_state Preview state.
	 * @return array<string, mixed>
	 */
	private function preview_status( array $preview_state ) {
		if ( empty( $preview_state ) ) {
			return array(
				'has_run'    => false,
				'ready'      => false,
				'status'     => 'not_run',
				'label'      => 'Preview not run yet.',
				'generated_at' => null,
			);
		}

		$ready = empty( $preview_state['errors'] ) && empty( $preview_state['duplicate_conflicts'] );
		return array(
			'has_run'       => true,
			'ready'         => $ready,
			'status'        => $ready ? 'pass' : 'fail',
			'label'         => $ready ? 'Preview passed.' : 'Preview failed.',
			'generated_at'  => $preview_state['generated_at'] ?? null,
			'redirect_rule_count' => (int) ( $preview_state['redirect_rule_count'] ?? 0 ),
			'warnings'      => isset( $preview_state['warnings'] ) && is_array( $preview_state['warnings'] ) ? $preview_state['warnings'] : array(),
			'errors'        => isset( $preview_state['errors'] ) && is_array( $preview_state['errors'] ) ? $preview_state['errors'] : array(),
		);
	}

	/**
	 * Summarize the latest redirect export states.
	 *
	 * @param array<string, array<string, mixed>> $export_states Export states.
	 * @return array<string, mixed>
	 */
	private function export_status( array $export_states ) {
		$latest = array();
		foreach ( $export_states as $format => $export ) {
			if ( ! is_array( $export ) ) {
				continue;
			}
			if ( empty( $latest ) ) {
				$latest = $export + array( 'format' => $format );
				continue;
			}
			$latest_time = strtotime( (string) ( $latest['generated_at'] ?? '' ) ) ?: 0;
			$export_time = strtotime( (string) ( $export['generated_at'] ?? '' ) ) ?: 0;
			if ( $export_time >= $latest_time ) {
				$latest = $export + array( 'format' => $format );
			}
		}

		if ( empty( $latest ) ) {
			return array(
				'has_run'    => false,
				'ready'      => false,
				'status'     => 'not_run',
				'label'      => 'Final redirect export not run yet.',
				'generated_at' => null,
			);
		}

		$ready = empty( $latest['errors'] ) && empty( $latest['duplicate_conflicts'] );
		return array(
			'has_run'       => true,
			'ready'         => $ready,
			'status'        => $ready ? 'pass' : 'fail',
			'label'         => $ready ? 'Final redirect export passed.' : 'Final redirect export failed.',
			'generated_at'  => $latest['generated_at'] ?? null,
			'format'        => $latest['format'] ?? null,
			'file_name'     => $latest['file_name'] ?? null,
			'redirect_rule_count' => (int) ( $latest['redirect_rule_count'] ?? 0 ),
			'warnings'      => isset( $latest['warnings'] ) && is_array( $latest['warnings'] ) ? $latest['warnings'] : array(),
			'errors'        => isset( $latest['errors'] ) && is_array( $latest['errors'] ) ? $latest['errors'] : array(),
		);
	}

	/**
	 * Ensure the export directory has a listing guard file.
	 *
	 * @return void
	 */
	private function ensure_index_html() {
		$index = $this->exports_dir . '/index.html';
		if ( file_exists( $index ) ) {
			return;
		}

		@file_put_contents( $index, '' );
	}

	/**
	 * Ensure the destination directory exists for a file path.
	 *
	 * @param string $output_path Destination path.
	 * @return void
	 */
	private function ensure_directory_for_path( $output_path ) {
		$dir = dirname( $output_path );
		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Could not create the export output directory: ' . $dir );
		}
	}

	/**
	 * Determine whether a path is inside the managed export directory.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_inside_exports_dir( $path ) {
		$normalized_path = wp_normalize_path( (string) $path );
		$normalized_dir  = trailingslashit( wp_normalize_path( $this->exports_dir ) );

		return 0 === strpos( $normalized_path, $normalized_dir );
	}

	/**
	 * Extract the path from a URL or path string.
	 *
	 * @param string $value URL or path.
	 * @return string
	 */
	private function extract_url_path( $value ) {
		$value = trim( str_replace( '\\/', '/', (string) $value ) );
		if ( '' === $value ) {
			return '';
		}

		$parsed = wp_parse_url( $value, PHP_URL_PATH );
		if ( is_string( $parsed ) && '' !== $parsed ) {
			return $parsed;
		}

		if ( 0 === strpos( $value, '/wp-content/' ) ) {
			return $value;
		}

		$position = strpos( $value, '/wp-content/' );
		if ( false !== $position ) {
			return substr( $value, $position );
		}

		return $value;
	}

	/**
	 * Normalize and URL-encode a redirect path without double-encoding.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize_redirect_path( $path ) {
		$path = str_replace( '\\', '/', trim( (string) $path ) );
		if ( '' === $path ) {
			return '';
		}

		$prefix = '/';
		$path   = ltrim( $path, '/' );

		$segments = explode( '/', $path );
		$encoded  = array();
		foreach ( $segments as $segment ) {
			$encoded[] = rawurlencode( rawurldecode( $segment ) );
		}

		return $prefix . implode( '/', $encoded );
	}

	/**
	 * Get the exact filename from a relative path.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function exact_basename( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		$position = strrpos( $path, '/' );
		return false === $position ? $path : substr( $path, $position + 1 );
	}
}
