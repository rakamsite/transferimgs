<?php

namespace MediaFlattenMigrator;

final class CLI_Command {
	/**
	 * Create or update the manifest table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten install
	 *
	 * @return void
	 */
	public function install() {
		Schema::install();

		$repository = new Manifest_Repository();
		if ( ! $repository->table_exists() ) {
			\WP_CLI::error( 'Manifest table installation did not complete.' );
		}

		\WP_CLI::success( 'Manifest table installed or updated: ' . Schema::table_name() );
	}

	/**
	 * Scan dated upload folders and optionally save results to the manifest.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report scan results without writing to the manifest table.
	 *
	 * [--samples=<count>]
	 * : Number of flattened mapping samples to display.
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten scan
	 *     wp media-flatten scan --dry-run
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function scan( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );
		$result  = $this->run_scan();

		\WP_CLI::log( 'Attachment metadata references:' );
		$this->format_items(
			$result->attachments(),
			array( 'attachment_id', 'main_file', 'image_sizes', 'original_image', 'metadata' )
		);

		\WP_CLI::log( 'Scan summary:' );
		$this->display_summary( $result );

		$sample_count = max( 0, (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'samples', 10 ) );
		$samples      = array_slice( $result->files(), 0, $sample_count );

		\WP_CLI::log( 'Sample flattened mappings:' );
		$this->format_items(
			$samples,
			array( 'attachment_id', 'type', 'size_name', 'source_relative', 'target_relative', 'exists', 'collision' )
		);

		$missing_files = array_filter(
			$result->files(),
			static function ( $file ) {
				return 'no' === $file['exists'];
			}
		);

		\WP_CLI::log( 'Missing files:' );
		$this->format_items(
			array_values( $missing_files ),
			array( 'attachment_id', 'type', 'size_name', 'source_relative' )
		);

		\WP_CLI::log( 'Flattened filename collisions:' );
		$this->format_items(
			array_values( $result->collisions() ),
			array( 'target_relative', 'sources', 'existing_at_root' )
		);

		if ( $dry_run ) {
			\WP_CLI::success( 'Read-only scan complete. No files or database records were changed.' );
			return;
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$counts = $repository->save_files( $result->files() );
			\WP_CLI::success(
				sprintf(
					'Manifest scan complete. Inserted %d rows and updated %d rows.',
					$counts['inserted'],
					$counts['updated']
				)
			);
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Resolve uploads-root target paths without changing files or attachment data.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Show proposed targets and collisions without updating the manifest.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten resolve-targets
	 *     wp media-flatten resolve-targets --dry-run
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function resolve_targets( $args, $assoc_args ) {
		$dry_run = isset( $assoc_args['dry-run'] );

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$resolver = new Target_Resolver( $repository );
			\WP_CLI::log( 'Proposed target paths and collision results:' );
			$result = $resolver->resolve(
				$dry_run,
				function ( $rows ) {
					$this->format_items(
						$rows,
						array(
							'id',
							'attachment_id',
							'old_rel_path',
							'new_rel_path',
							'new_abs_path',
							'new_url',
							'status',
							'error_message',
						)
					);
				}
			);

			\WP_CLI::log( 'Target resolution summary:' );
			$this->format_key_value_summary( $result['summary'] );

			if ( $dry_run ) {
				\WP_CLI::success( 'Read-only target resolution complete. No database records or files were changed.' );
				return;
			}

			\WP_CLI::success( 'Manifest target resolution complete. No files or attachment records were changed.' );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Safely migrate one resolved attachment into the uploads root.
	 *
	 * ## OPTIONS
	 *
	 * <attachment_id>
	 * : Attachment ID to migrate.
	 *
	 * [--dry-run]
	 * : Show preflight results without copying files or changing the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten migrate-one 123 --dry-run
	 *     wp media-flatten migrate-one 123
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function migrate_one( $args, $assoc_args ) {
		$attachment_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$dry_run       = isset( $assoc_args['dry-run'] );

		if ( ! $attachment_id ) {
			\WP_CLI::error( 'A valid attachment_id is required.' );
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$migrator = new Single_Attachment_Migrator( $repository );
			$plan     = $migrator->plan( $attachment_id );

			\WP_CLI::log( 'Attachment migration plan:' );
			$this->format_items(
				array(
					array(
						'attachment_id'          => $plan['attachment_id'],
						'current_attached_file'  => $plan['current_attached_file'],
						'proposed_attached_file' => $plan['proposed_attached_file'],
						'allowed'                => $plan['allowed'] ? 'yes' : 'no',
						'already_migrated'       => $plan['all_migrated'] ? 'yes' : 'no',
					),
				),
				array( 'attachment_id', 'current_attached_file', 'proposed_attached_file', 'allowed', 'already_migrated' )
			);

			\WP_CLI::log( 'Manifest source and target files:' );
			$this->format_items(
				$plan['files'],
				array( 'row_id', 'file_kind', 'size_key', 'source_path', 'target_path', 'source_exists', 'target_exists', 'same_path' )
			);

			if ( $plan['errors'] ) {
				\WP_CLI::log( 'Migration blockers:' );
				foreach ( $plan['errors'] as $error ) {
					\WP_CLI::warning( $error );
				}
			}

			if ( $dry_run ) {
				\WP_CLI::success( 'Read-only migrate-one preflight complete. No database records or files were changed.' );
				return;
			}

			if ( ! $plan['allowed'] ) {
				\WP_CLI::error( 'Migration is blocked. Resolve the reported preflight errors before retrying.' );
			}
			if ( $plan['all_migrated'] ) {
				\WP_CLI::success( 'Attachment is already migrated and verified against the manifest. No changes were made.' );
				return;
			}

			$result = $migrator->migrate( $plan );
			\WP_CLI::success(
				sprintf(
					'Attachment %d migrated successfully. Copied %d files and migrated %d manifest rows.',
					$attachment_id,
					$result['copied'],
					$result['migrated']
				)
			);
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Migrate a controlled batch using the migrate-one service.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<count>]
	 * : Maximum eligible attachments to process. Default: 50. Maximum: 1000.
	 *
	 * [--limit=<count>]
	 * : Alias for --batch. Do not use both options together.
	 *
	 * [--start-after=<attachment_id>]
	 * : Only inspect attachment IDs greater than this value.
	 *
	 * [--dry-run]
	 * : Show migrations and skips without changing files or database records.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten migrate --batch=50 --dry-run
	 *     wp media-flatten migrate --batch=50
	 *     wp media-flatten migrate --batch=50 --start-after=1234
	 *     wp media-flatten migrate --limit=500
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function migrate( $args, $assoc_args ) {
		if ( isset( $assoc_args['batch'] ) && isset( $assoc_args['limit'] ) ) {
			\WP_CLI::error( 'Use either --batch or --limit, not both. --limit is an alias for --batch.' );
		}

		$batch_size = isset( $assoc_args['limit'] )
			? (int) $assoc_args['limit']
			: (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 50 );
		$start_after = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'start-after', 0 );
		$dry_run     = isset( $assoc_args['dry-run'] );

		if ( $batch_size < 1 ) {
			\WP_CLI::error( 'Batch size must be a positive integer.' );
		}
		if ( $batch_size > 1000 ) {
			\WP_CLI::error( 'Batch size must not exceed 1000.' );
		}
		if ( $start_after < 0 ) {
			\WP_CLI::error( '--start-after must be zero or a positive attachment ID.' );
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$single_migrator = new Single_Attachment_Migrator( $repository );
			$batch_migrator  = new Batch_Migrator( $repository, $single_migrator );

			\WP_CLI::log(
				sprintf(
					'Batch migration: maximum %d eligible attachments, starting after attachment ID %d%s.',
					$batch_size,
					$start_after,
					$dry_run ? ' (dry-run)' : ''
				)
			);

			$summary = $batch_migrator->run(
				$batch_size,
				$start_after,
				$dry_run,
				static function ( $row ) {
					\WP_CLI::log(
						sprintf(
							'Attachment %d: %s (%s)',
							$row['attachment_id'],
							$row['result'],
							$row['reason']
						)
					);
				}
			);

			\WP_CLI::log( 'Batch migration summary:' );
			$this->format_key_value_summary( $summary );

			if ( $dry_run ) {
				\WP_CLI::success( 'Read-only batch migration preview complete. No files or database records were changed.' );
				return;
			}

			\WP_CLI::success( 'Batch migration run complete.' );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Replace migrated media URL mappings in allowed WordPress fields.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<count>]
	 * : Records to scan per query. Default: 500. Maximum: 5000.
	 *
	 * [--dry-run]
	 * : Report changes without writing to the database.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten replace-urls --dry-run
	 *     wp media-flatten replace-urls
	 *     wp media-flatten replace-urls --batch=500 --dry-run
	 *     wp media-flatten replace-urls --batch=500
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function replace_urls( $args, $assoc_args ) {
		$batch_size = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 500 );
		$dry_run    = isset( $assoc_args['dry-run'] );

		if ( $batch_size < 1 ) {
			\WP_CLI::error( 'Batch size must be a positive integer.' );
		}
		if ( $batch_size > 5000 ) {
			\WP_CLI::error( 'Batch size must not exceed 5000.' );
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$mappings = $repository->get_migrated_url_mappings();
			if ( ! $mappings ) {
				\WP_CLI::warning( 'No migrated manifest URL mappings exist. Nothing to replace.' );
			}

			$replacer = new URL_Replacer( $mappings );
			$result   = $replacer->run( $batch_size, $dry_run );
			$samples  = $result['samples'];
			unset( $result['samples'] );

			\WP_CLI::log( 'URL replacement summary:' );
			$this->format_key_value_summary( $result );

			\WP_CLI::log( 'Sample migrated URL mappings:' );
			$this->format_items( $samples, array( 'old_url', 'new_url' ) );

			if ( $dry_run ) {
				\WP_CLI::success( 'Read-only URL replacement preview complete. No database records were changed.' );
				return;
			}

			\WP_CLI::success( 'Migrated URL replacement complete.' );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Export exact redirect mappings from migrated manifest rows.
	 *
	 * ## OPTIONS
	 *
	 * --format=<apache|nginx|csv>
	 * : Export format to generate.
	 *
	 * [--output=<path>]
	 * : Optional destination path. If omitted, the export is printed to STDOUT.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten redirects --format=apache
	 *     wp media-flatten redirects --format=nginx --output=/tmp/media-flatten.conf
	 *     wp media-flatten redirects --format=csv
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function redirects( $args, $assoc_args ) {
		$format = strtolower( trim( (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', '' ) ) );
		$output = isset( $assoc_args['output'] ) ? (string) $assoc_args['output'] : null;

		if ( ! in_array( $format, array( 'apache', 'nginx', 'csv' ), true ) ) {
			\WP_CLI::error( 'Use --format=apache, --format=nginx, or --format=csv.' );
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$service = new Redirect_Export_Service( $repository );
			$readiness = $service->readiness();
			if ( empty( $readiness['ready'] ) ) {
				\WP_CLI::warning( 'Redirect export readiness checks have not fully passed. Review the warnings before using the generated file.' );
				foreach ( $readiness['warnings'] as $warning ) {
					\WP_CLI::warning( $warning );
				}
			}

			$result = $service->generate( $format, $output, 500, true );
			$this->format_key_value_summary(
				array(
					'format'                  => $result['format'],
					'generated_at'            => $result['generated_at'],
					'redirect_rule_count'     => $result['redirect_rule_count'],
					'total_migrated_mappings'  => $result['total_migrated_mappings'],
					'deduplicated_mappings'    => $result['deduplicated_mappings'],
					'skipped_same_path'        => $result['skipped_same_path'],
					'unicode_filename_count'   => $result['unicode_filename_count'],
					'ready'                   => $result['ready'] ? 'yes' : 'no',
				)
			);

			if ( $result['warnings'] ) {
				\WP_CLI::log( 'Export warnings:' );
				foreach ( $result['warnings'] as $warning ) {
					\WP_CLI::warning( $warning );
				}
			}
			if ( $result['errors'] ) {
				\WP_CLI::log( 'Export errors:' );
				foreach ( $result['errors'] as $error ) {
					\WP_CLI::warning( $error );
				}
			}

			if ( null === $output ) {
				\WP_CLI::line( $result['content'] );
			} else {
				\WP_CLI::success( 'Redirect export written to: ' . $output );
			}
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Safely delete old source files after all readiness gates pass.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<count>]
	 * : Maximum files to process per batch. Default: 10. Maximum: 50.
	 *
	 * [--dry-run]
	 * : Show deletion candidates without deleting files or changing the database.
	 *
	 * [--yes]
	 * : Required for actual deletion.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten delete-old-files --dry-run
	 *     wp media-flatten delete-old-files --batch=10 --yes
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function delete_old_files( $args, $assoc_args ) {
		$batch_size = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 10 );
		$dry_run    = isset( $assoc_args['dry-run'] );
		$yes        = isset( $assoc_args['yes'] );

		if ( $batch_size < 1 ) {
			\WP_CLI::error( 'Batch size must be a positive integer.' );
		}
		if ( $batch_size > 50 ) {
			\WP_CLI::error( 'Batch size must not exceed 50.' );
		}
		if ( ! $dry_run && ! $yes ) {
			\WP_CLI::error( 'Actual deletion requires --yes. Use --dry-run first to review the candidate files.' );
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$service   = new Old_File_Deletion_Service( $repository );
			$readiness = $service->readiness();

			if ( empty( $readiness['ready'] ) ) {
				\WP_CLI::warning( 'Delete readiness has not fully passed. Review the reported blockers before deleting files.' );
				$this->format_key_value_summary(
					array(
						'ready'                 => ! empty( $readiness['ready'] ) ? 'yes' : 'no',
						'delete_old_files_ready' => ! empty( $readiness['delete_old_files_ready'] ) ? 'yes' : 'no',
						'eligible_count'        => $readiness['eligible_count'] ?? 0,
						'deleted_count'         => $readiness['deleted_count'] ?? 0,
						'already_missing_count' => $readiness['already_missing_count'] ?? 0,
						'failed_count'          => $readiness['failed_count'] ?? 0,
						'bytes_eligible'        => $readiness['bytes_eligible'] ?? 0,
						'bytes_freed'           => $readiness['bytes_freed'] ?? 0,
					)
				);
				foreach ( $readiness['verify']['errors'] ?? array() as $message ) {
					\WP_CLI::warning( $message );
				}
				foreach ( $readiness['old_url_audit']['errors'] ?? array() as $message ) {
					\WP_CLI::warning( $message );
				}
				foreach ( $readiness['latest_errors'] ?? array() as $message ) {
					\WP_CLI::warning( $message );
				}
				if ( ! $dry_run ) {
					\WP_CLI::error( 'Delete readiness failed. Use --dry-run to review the candidate files only.' );
				}
			}

			if ( $dry_run ) {
				$result = $service->report( $batch_size, false );
				$this->format_key_value_summary(
					array(
						'ready'                 => ! empty( $result['ready'] ) ? 'yes' : 'no',
						'eligible_count'        => $result['eligible_count'] ?? 0,
						'deleted_count'         => $result['deleted_count'] ?? 0,
						'already_missing_count' => $result['already_missing_count'] ?? 0,
						'failed_count'          => $result['failed_count'] ?? 0,
						'bytes_eligible'        => $result['bytes_eligible'] ?? 0,
						'bytes_freed'           => $result['bytes_freed'] ?? 0,
					)
				);
				$this->format_items(
					$result['samples']['eligible'] ?? array(),
					array( 'manifest_id', 'attachment_id', 'old_rel_path', 'new_rel_path', 'old_abs_path', 'new_abs_path', 'result', 'message' )
				);
				if ( ! empty( $result['errors'] ) ) {
					\WP_CLI::log( 'Deletion errors:' );
					foreach ( $result['errors'] as $message ) {
						\WP_CLI::warning( $message );
					}
				}
				\WP_CLI::success( 'Read-only old-file deletion preview complete. No files or database records were changed.' );
				return;
			}

			$cursor  = 0;
			$summary = $service->initial_result();
			do {
				$batch_result = $service->run_batch( $cursor, $batch_size, $summary, false );
				$summary      = $batch_result['result'];
				$cursor       = $batch_result['last_manifest_id'];
				$this->format_key_value_summary(
					array(
						'processed'        => $batch_result['summary']['processed'] ?? 0,
						'eligible'         => $batch_result['summary']['eligible'] ?? 0,
						'deleted'          => $batch_result['summary']['deleted'] ?? 0,
						'already_missing'  => $batch_result['summary']['already_missing'] ?? 0,
						'failed'           => $batch_result['summary']['failed'] ?? 0,
						'bytes_freed'      => $batch_result['summary']['bytes_freed'] ?? 0,
						'last_manifest_id' => $batch_result['summary']['last_manifest_id'] ?? $cursor,
					)
				);
			} while ( ! $batch_result['done'] );

			$summary = $service->finalize( $summary, false );
			$service->store_state( $summary );

			$this->format_key_value_summary(
				array(
					'pass'                  => ! empty( $summary['pass'] ) ? 'yes' : 'no',
					'ready'                 => ! empty( $summary['ready'] ) ? 'yes' : 'no',
					'eligible_count'        => $summary['eligible_count'] ?? 0,
					'deleted_count'         => $summary['deleted_count'] ?? 0,
					'already_missing_count' => $summary['already_missing_count'] ?? 0,
					'failed_count'          => $summary['failed_count'] ?? 0,
					'bytes_eligible'        => $summary['bytes_eligible'] ?? 0,
					'bytes_freed'           => $summary['bytes_freed'] ?? 0,
				)
			);

			if ( ! empty( $summary['errors'] ) ) {
				\WP_CLI::log( 'Deletion errors:' );
				foreach ( $summary['errors'] as $message ) {
					\WP_CLI::warning( $message );
				}
			}

			\WP_CLI::success( 'Old-file deletion complete.' );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Remove only empty old year/month upload directories after migration is complete.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<count>]
	 * : Maximum directories to process per batch. Default: 20. Maximum: 100.
	 *
	 * [--dry-run]
	 * : Show cleanup candidates without deleting directories or changing the database.
	 *
	 * [--yes]
	 * : Required for actual cleanup.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten cleanup-empty-dirs --dry-run
	 *     wp media-flatten cleanup-empty-dirs --batch=20 --yes
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function cleanup_empty_dirs( $args, $assoc_args ) {
		$batch_size = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 20 );
		$dry_run    = isset( $assoc_args['dry-run'] );
		$yes        = isset( $assoc_args['yes'] );

		if ( $batch_size < 1 ) {
			\WP_CLI::error( 'Batch size must be a positive integer.' );
		}
		if ( $batch_size > 100 ) {
			\WP_CLI::error( 'Batch size must not exceed 100.' );
		}
		if ( ! $dry_run && ! $yes ) {
			\WP_CLI::error( 'Actual cleanup requires --yes. Use --dry-run first to review the candidate directories.' );
		}

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$service   = new Empty_Directory_Cleanup_Service( $repository );
			$readiness = $service->readiness();

			if ( empty( $readiness['ready'] ) ) {
				\WP_CLI::warning( 'Cleanup readiness has not fully passed. Review the reported blockers before deleting directories.' );
				$this->format_key_value_summary(
					array(
						'ready'                      => ! empty( $readiness['ready'] ) ? 'yes' : 'no',
						'cleanup_ready'              => ! empty( $readiness['cleanup_ready'] ) ? 'yes' : 'no',
						'remaining_old_directories'  => $readiness['remaining_old_directories'] ?? 0,
						'empty_month_dirs_found'     => $readiness['empty_month_dirs_found'] ?? 0,
						'empty_year_dirs_found'      => $readiness['empty_year_dirs_found'] ?? 0,
						'removed_count'              => $readiness['removed_count'] ?? 0,
						'skipped_not_empty_count'    => $readiness['skipped_not_empty_count'] ?? 0,
						'skipped_unsafe_count'       => $readiness['skipped_unsafe_count'] ?? 0,
						'failed_count'               => $readiness['failed_count'] ?? 0,
					)
				);
				foreach ( $readiness['warnings'] ?? array() as $message ) {
					\WP_CLI::warning( $message );
				}
				if ( ! $dry_run ) {
					\WP_CLI::error( 'Cleanup readiness failed. Use --dry-run to review the candidate directories only.' );
				}
			}

			if ( $dry_run ) {
				$result = $service->report( $batch_size, false );
				$this->format_key_value_summary(
					array(
						'ready'                      => ! empty( $result['ready'] ) ? 'yes' : 'no',
						'remaining_old_directories'  => $result['remaining_count'] ?? 0,
						'empty_month_dirs_found'     => $result['empty_month_dirs_found'] ?? 0,
						'empty_year_dirs_found'      => $result['empty_year_dirs_found'] ?? 0,
						'removed_count'              => $result['removed_count'] ?? 0,
						'skipped_not_empty_count'    => $result['skipped_not_empty_count'] ?? 0,
						'skipped_unsafe_count'       => $result['skipped_unsafe_count'] ?? 0,
						'already_missing_count'      => $result['already_missing_count'] ?? 0,
						'failed_count'               => $result['failed_count'] ?? 0,
					)
				);
				$this->format_items(
					$result['samples']['eligible'] ?? array(),
					array( 'path', 'relative_path', 'kind', 'result', 'message' )
				);
				$this->format_items(
					$result['samples']['not_empty'] ?? array(),
					array( 'path', 'relative_path', 'kind', 'result', 'message' )
				);
				$this->format_items(
					$result['samples']['unsafe'] ?? array(),
					array( 'path', 'relative_path', 'kind', 'result', 'message' )
				);
				if ( ! empty( $result['errors'] ) ) {
					\WP_CLI::log( 'Cleanup errors:' );
					foreach ( $result['errors'] as $message ) {
						\WP_CLI::warning( $message );
					}
				}
				\WP_CLI::success( 'Read-only empty-directory cleanup preview complete. No directories or database records were changed.' );
				return;
			}

			$cursor  = '';
			$summary = $service->initial_result();
			do {
				$batch_result = $service->run_batch( $cursor, $batch_size, $summary, false );
				$summary      = $batch_result['result'];
				$cursor       = $batch_result['last_path'];
				$this->format_key_value_summary(
					array(
						'processed'               => $batch_result['summary']['processed'] ?? 0,
						'eligible'                => $batch_result['summary']['eligible'] ?? 0,
						'removed'                 => $batch_result['summary']['removed'] ?? 0,
						'skipped_not_empty'       => $batch_result['summary']['skipped_not_empty'] ?? 0,
						'skipped_unsafe'          => $batch_result['summary']['skipped_unsafe'] ?? 0,
						'already_missing'         => $batch_result['summary']['already_missing'] ?? 0,
						'failed'                  => $batch_result['summary']['failed'] ?? 0,
						'last_path'               => $batch_result['summary']['last_path'] ?? $cursor,
					)
				);
			} while ( ! $batch_result['done'] );

			$summary = $service->finalize( $summary, false );
			$service->store_state( $summary );

			$this->format_key_value_summary(
				array(
					'pass'                    => ! empty( $summary['pass'] ) ? 'yes' : 'no',
					'ready'                   => ! empty( $summary['ready'] ) ? 'yes' : 'no',
					'remaining_old_directories' => $summary['remaining_count'] ?? 0,
					'empty_month_dirs_found'   => $summary['empty_month_dirs_found'] ?? 0,
					'empty_year_dirs_found'    => $summary['empty_year_dirs_found'] ?? 0,
					'removed_count'            => $summary['removed_count'] ?? 0,
					'skipped_not_empty_count'  => $summary['skipped_not_empty_count'] ?? 0,
					'skipped_unsafe_count'     => $summary['skipped_unsafe_count'] ?? 0,
					'already_missing_count'    => $summary['already_missing_count'] ?? 0,
					'failed_count'             => $summary['failed_count'] ?? 0,
				)
			);

			if ( ! empty( $summary['errors'] ) ) {
				\WP_CLI::log( 'Cleanup errors:' );
				foreach ( $summary['errors'] as $message ) {
					\WP_CLI::warning( $message );
				}
			}

			\WP_CLI::success( 'Empty-directory cleanup complete.' );
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Run the pre-redirect old dated upload URL audit.
	 *
	 * ## OPTIONS
	 *
	 * [--json]
	 * : Output valid machine-readable JSON.
	 *
	 * [--strict]
	 * : Exit with an error when unsafe old URLs remain.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten audit-old-urls
	 *     wp media-flatten audit-old-urls --json
	 *     wp media-flatten audit-old-urls --strict
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function audit_old_urls( $args, $assoc_args ) {
		$json   = isset( $assoc_args['json'] );
		$strict = isset( $assoc_args['strict'] );

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$result = ( new Old_URL_Audit_Service( $repository ) )->run( 100 );
			if ( $json ) {
				\WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			} else {
				\WP_CLI::log( 'Old URL audit: ' . ( $result['safe'] ? 'SAFE' : 'UNSAFE' ) );
				$this->format_key_value_summary(
					array(
						'audited_at'                          => $result['audited_at'],
						'migrated_mapping_old_url_remaining' => $result['migrated_mapping_old_url_remaining'],
						'non_migrated_manifest_url_remaining' => $result['non_migrated_manifest_url_remaining'],
						'orphan_old_upload_url_remaining'    => $result['orphan_old_upload_url_remaining'],
						'generic_dated_upload_occurrences'   => $result['generic_dated_upload_occurrences'],
					)
				);
			}

			if ( $strict && ! $result['safe'] ) {
				\WP_CLI::error( 'Old URL audit found unsafe dated upload URLs.' );
			}
			if ( ! $json ) {
				\WP_CLI::success( 'Read-only old URL audit complete. No files or database records were changed.' );
			}
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Run comprehensive read-only migration verification.
	 *
	 * ## OPTIONS
	 *
	 * [--json]
	 * : Output valid machine-readable JSON.
	 *
	 * [--strict]
	 * : Exit with an error when verification errors exist.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten verify
	 *     wp media-flatten verify --json
	 *     wp media-flatten verify --strict
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Named arguments.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$json   = isset( $assoc_args['json'] );
		$strict = isset( $assoc_args['strict'] );

		try {
			$repository = new Manifest_Repository();
			if ( ! $repository->table_exists() ) {
				\WP_CLI::error( 'Manifest table is not installed. Run: wp media-flatten install' );
			}

			$result = ( new Verification_Service( $repository ) )->run( 100 );
			if ( $json ) {
				\WP_CLI::line( wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			} else {
				\WP_CLI::log( 'Verification result: ' . ( $result['pass'] ? 'PASS' : 'FAIL' ) );
				$this->format_key_value_summary(
					array(
						'verified_at'          => $result['verified_at'],
						'errors_count'         => $result['errors_count'],
						'warnings_count'       => $result['warnings_count'],
						'info_count'           => $result['info_count'],
						'missing_new_files'    => $result['missing_new_files'],
						'integrity_mismatches' => $result['integrity_mismatches'],
						'metadata_errors'      => $result['metadata_errors'],
						'migrated_old_urls'    => $result['migrated_mapping_old_url_remaining'],
						'non_migrated_old_urls' => $result['non_migrated_manifest_url_remaining'],
						'orphan_old_urls'      => $result['orphan_old_upload_url_remaining'],
						'pre_redirect_ready'   => $result['pre_redirect_ready'] ? 'yes' : 'no',
						'redirect_export_ready' => $result['redirect_export_ready'] ? 'yes' : 'no',
					)
				);
				\WP_CLI::log( 'Sample errors:' );
				foreach ( $result['sample_errors'] as $message ) {
					\WP_CLI::warning( $message );
				}
				\WP_CLI::log( 'Sample warnings:' );
				foreach ( $result['sample_warnings'] as $message ) {
					\WP_CLI::warning( $message );
				}
			}

			if ( $strict && ! $result['pass'] ) {
				\WP_CLI::error( 'Verification failed with ' . $result['errors_count'] . ' error(s).' );
			}
			if ( ! $json ) {
				\WP_CLI::success( 'Read-only verification complete. No files or database records were changed.' );
			}
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * Print a complete read-only file and collision report.
	 *
	 * ## EXAMPLES
	 *
	 *     wp media-flatten report
	 *
	 * @return void
	 */
	public function report() {
		$result = $this->run_scan();

		\WP_CLI::log( 'Scan summary:' );
		$this->display_summary( $result );

		\WP_CLI::log( 'Referenced files:' );
		$this->format_items(
			$result->files(),
			array( 'attachment_id', 'type', 'size_name', 'source_relative', 'target_relative', 'exists', 'collision' )
		);

		\WP_CLI::log( 'Flattened filename collisions:' );
		$this->format_items(
			array_values( $result->collisions() ),
			array( 'target_relative', 'sources', 'existing_at_root' )
		);

		$repository = new Manifest_Repository();
		\WP_CLI::log( 'Manifest counts by status and file kind:' );
		$this->format_items(
			$repository->grouped_counts(),
			array( 'status', 'file_kind', 'item_count' )
		);

		\WP_CLI::log( 'Manifest status totals:' );
		$this->format_items(
			$repository->status_counts(),
			array( 'status', 'item_count' )
		);

		$usage_reporter = new Usage_Reporter( $repository );
		$batch_reporter = new Batch_Migrator( $repository, new Single_Attachment_Migrator( $repository ) );
		$verify         = get_option( Admin_Controller::VERIFY_RESULT_OPTION, array() );
		$old_url_audit  = get_option( Admin_Controller::OLD_URL_AUDIT_RESULT_OPTION, array() );
		$delete_result   = get_option( Admin_Controller::DELETE_RESULT_OPTION, array() );

		\WP_CLI::log( 'Attachment batch migration readiness:' );
		$this->format_key_value_summary( $batch_reporter->report_counts() );

		\WP_CLI::log( 'Manifest attachment usage:' );
		$this->format_items(
			$usage_reporter->usage_counts(),
			array( 'metric', 'attachment_count' )
		);

		$filename_counts = $usage_reporter->filename_counts();
		\WP_CLI::log( 'Manifest file extension counts:' );
		$this->format_items(
			$filename_counts['extensions'],
			array( 'extension', 'file_count' )
		);

		\WP_CLI::log( 'Filename summary:' );
		$this->format_items(
			array(
				array(
					'metric' => 'non_ascii_filenames',
					'value'  => $filename_counts['non_ascii_filenames'],
				),
			),
			array( 'metric', 'value' )
		);

		$verify = get_option( Admin_Controller::VERIFY_RESULT_OPTION, array() );
		\WP_CLI::log( 'Latest verification summary:' );
		$this->format_key_value_summary(
			$verify
				? array(
					'status'               => ! empty( $verify['pass'] ) ? 'PASS' : 'FAIL',
					'verified_at'          => $verify['verified_at'] ?? '-',
					'errors_count'         => $verify['errors_count'] ?? 0,
					'warnings_count'       => $verify['warnings_count'] ?? 0,
					'missing_new_files'    => $verify['missing_new_files'] ?? 0,
					'integrity_mismatches' => $verify['integrity_mismatches'] ?? 0,
					'metadata_errors'      => $verify['metadata_errors'] ?? 0,
					'pre_redirect_ready'   => ! empty( $verify['pre_redirect_ready'] ) ? 'yes' : 'no',
					'redirect_export_ready' => ! empty( $verify['redirect_export_ready'] ) ? 'yes' : 'no',
				)
				: array( 'status' => 'Not run yet' )
		);

		$old_url_audit = get_option( Admin_Controller::OLD_URL_AUDIT_RESULT_OPTION, array() );
		$redirect_export = get_option( Admin_Controller::REDIRECT_EXPORT_RESULT_OPTION, array() );
		$redirect_service = new Redirect_Export_Service( $repository );
		$redirect_readiness = $redirect_service->readiness();
		$delete_service   = new Old_File_Deletion_Service( $repository );
		$delete_readiness = $delete_service->readiness();
		\WP_CLI::log( 'Latest old URL audit summary:' );
		$this->format_key_value_summary(
			$old_url_audit
				? array(
					'status'                              => ! empty( $old_url_audit['safe'] ) ? 'SAFE' : 'UNSAFE',
					'audited_at'                          => $old_url_audit['audited_at'] ?? '-',
					'migrated_mapping_old_url_remaining' => $old_url_audit['migrated_mapping_old_url_remaining'] ?? 0,
					'non_migrated_manifest_url_remaining' => $old_url_audit['non_migrated_manifest_url_remaining'] ?? 0,
					'orphan_old_upload_url_remaining'    => $old_url_audit['orphan_old_upload_url_remaining'] ?? 0,
				)
				: array( 'status' => 'Not run yet' )
		);

		\WP_CLI::log( 'Latest redirect export summary:' );
		$this->format_key_value_summary(
			array(
				'generated_at'            => $redirect_readiness['generated_at'] ?? ( $redirect_export['generated_at'] ?? '-' ),
				'preview_status'          => $redirect_readiness['redirect_preview_status']['label'] ?? 'Preview not run yet.',
				'export_status'           => $redirect_readiness['redirect_export_status']['label'] ?? 'Final redirect export not run yet.',
				'preview_ready'           => ! empty( $redirect_readiness['redirect_preview_ready'] ) ? 'yes' : 'no',
				'export_ready'            => ! empty( $redirect_readiness['redirect_export_ready'] ) ? 'yes' : 'no',
				'latest_apache_file'      => $redirect_export['exports']['apache']['file_name'] ?? '-',
				'latest_nginx_file'       => $redirect_export['exports']['nginx']['file_name'] ?? '-',
				'latest_csv_file'         => $redirect_export['exports']['csv']['file_name'] ?? '-',
				'redirect_rule_count'     => $redirect_readiness['redirect_rules_to_export'] ?? ( $redirect_readiness['redirect_preview_status']['redirect_rule_count'] ?? 0 ),
				'export_warnings_count'   => isset( $redirect_export['warnings'] ) ? count( $redirect_export['warnings'] ) : 0,
				'export_errors_count'     => isset( $redirect_export['errors'] ) ? count( $redirect_export['errors'] ) : 0,
			)
		);

		\WP_CLI::log( 'Latest old-file deletion summary:' );
		$this->format_key_value_summary(
			$delete_result
				? array(
					'status'                 => ! empty( $delete_result['ready'] ) ? 'READY' : 'NOT READY',
					'generated_at'           => $delete_result['generated_at'] ?? '-',
					'completed_at'           => $delete_result['completed_at'] ?? '-',
					'dry_run_status'         => $delete_readiness['dry_run_status'] ?? ( $delete_result['dry_run_status'] ?? 'not_run' ),
					'dry_run_completed_at'   => $delete_readiness['dry_run_completed_at'] ?? ( $delete_result['dry_run_completed_at'] ?? '-' ),
					'delete_old_files_ready' => ! empty( $delete_readiness['delete_old_files_ready'] ) ? 'yes' : 'no',
					'final_redirect_export_has_run' => ! empty( $delete_readiness['final_redirect_export_has_run'] ) ? 'yes' : 'no',
					'final_redirect_export_format'  => $delete_readiness['final_redirect_export_format'] ?? '-',
					'final_redirect_export_file'    => $delete_readiness['final_redirect_export_file'] ?? '-',
					'eligible_count'         => $delete_result['eligible_count'] ?? 0,
					'deleted_count'          => $delete_result['deleted_count'] ?? 0,
					'already_missing_count'  => $delete_result['already_missing_count'] ?? 0,
					'failed_count'           => $delete_result['failed_count'] ?? 0,
					'bytes_eligible'         => $delete_result['bytes_eligible'] ?? 0,
					'bytes_freed'            => $delete_result['bytes_freed'] ?? 0,
					'latest_errors_count'    => isset( $delete_result['errors'] ) && is_array( $delete_result['errors'] ) ? count( $delete_result['errors'] ) : 0,
				)
				: array( 'status' => 'Not run yet' )
		);

		$cleanup_result = get_option( Admin_Controller::CLEANUP_RESULT_OPTION, array() );
		$final_report   = get_option( Admin_Controller::FINAL_REPORT_OPTION, array() );
		$cleanup_service = new Empty_Directory_Cleanup_Service( $repository );
		$cleanup_readiness = $cleanup_service->readiness();

		\WP_CLI::log( 'Latest cleanup summary:' );
		$this->format_key_value_summary(
			$cleanup_result
				? array(
					'status'                    => ! empty( $cleanup_result['ready'] ) ? 'READY' : 'NOT READY',
					'generated_at'              => $cleanup_result['generated_at'] ?? '-',
					'completed_at'              => $cleanup_result['completed_at'] ?? '-',
					'dry_run_status'            => $cleanup_readiness['dry_run_status'] ?? ( $cleanup_result['dry_run_status'] ?? 'not_run' ),
					'dry_run_completed_at'      => $cleanup_readiness['dry_run_completed_at'] ?? ( $cleanup_result['dry_run_completed_at'] ?? '-' ),
					'cleanup_ready'             => ! empty( $cleanup_readiness['cleanup_ready'] ) ? 'yes' : 'no',
					'remaining_old_directories' => $cleanup_result['remaining_count'] ?? 0,
					'empty_month_dirs_found'    => $cleanup_result['empty_month_dirs_found'] ?? 0,
					'empty_year_dirs_found'     => $cleanup_result['empty_year_dirs_found'] ?? 0,
					'removed_count'             => $cleanup_result['removed_count'] ?? 0,
					'skipped_not_empty_count'   => $cleanup_result['skipped_not_empty_count'] ?? 0,
					'skipped_unsafe_count'      => $cleanup_result['skipped_unsafe_count'] ?? 0,
					'failed_count'              => $cleanup_result['failed_count'] ?? 0,
					'warning_count'             => $cleanup_result['warning_count'] ?? 0,
					'last_cleanup_at'           => $cleanup_result['last_cleanup_at'] ?? '-',
				)
				: array( 'status' => 'Not run yet' )
		);

		$final_service = new Final_Migration_Report_Service( $repository );
		$final_state   = $final_report ? $final_report : $final_service->state();
		\WP_CLI::log( 'Final migration report:' );
		$this->format_key_value_summary(
			$final_state
				? array(
					'status'                          => $final_state['status'] ?? ( ! empty( $final_state['pass'] ) ? 'PASS' : 'NOT READY' ),
					'generated_at'                    => $final_state['generated_at'] ?? '-',
					'verified_at'                     => $final_state['verified_at'] ?? '-',
					'audited_at'                      => $final_state['audited_at'] ?? '-',
					'redirect_export_at'              => $final_state['redirect_export_at'] ?? '-',
					'last_old_file_deletion_at'       => $final_state['last_old_file_deletion_at'] ?? '-',
					'last_cleanup_at'                 => $final_state['last_cleanup_at'] ?? '-',
					'total_manifest_rows'             => $final_state['total_manifest_rows'] ?? 0,
					'total_migrated_rows'             => $final_state['total_migrated_rows'] ?? 0,
					'missing_rows'                    => $final_state['missing_rows'] ?? 0,
					'blocked_collision_rows'          => $final_state['blocked_collision_rows'] ?? 0,
					'failed_rows'                     => $final_state['failed_rows'] ?? 0,
					'deleted_old_files'               => $final_state['deleted_old_files'] ?? 0,
					'old_files_remaining'             => $final_state['old_files_remaining'] ?? 0,
					'old_file_deletion_failures'      => $final_state['old_file_deletion_failures'] ?? 0,
					'empty_directories_removed'       => $final_state['empty_directories_removed'] ?? 0,
					'old_yyyy_mm_directories_remaining' => $final_state['old_yyyy_mm_directories_remaining'] ?? 0,
					'unsafe_directories_remaining'    => $final_state['unsafe_directories_remaining'] ?? 0,
					'cleanup_failures'                => $final_state['cleanup_failures'] ?? 0,
					'remaining_old_url_occurrences'   => $final_state['remaining_old_url_occurrences'] ?? 0,
					'pass'                            => ! empty( $final_state['pass'] ) ? 'yes' : 'no',
				)
				: array( 'status' => 'Not run yet' )
		);

		\WP_CLI::success( 'Read-only report complete. No files or database records were changed.' );
	}

	/**
	 * @return Scan_Result
	 */
	private function run_scan() {
		try {
			$scanner = new Attachment_Scanner();
			return $scanner->scan();
		} catch ( \RuntimeException $exception ) {
			\WP_CLI::error( $exception->getMessage() );
		}
	}

	/**
	 * @param Scan_Result $result Scan result.
	 * @return void
	 */
	private function display_summary( Scan_Result $result ) {
		$this->format_key_value_summary( $result->summary() );
	}

	/**
	 * @param array<string, int> $summary Summary values.
	 * @return void
	 */
	private function format_key_value_summary( array $summary ) {
		$rows    = array();

		foreach ( $summary as $metric => $value ) {
			$rows[] = array(
				'metric' => $metric,
				'value'  => $value,
			);
		}

		$this->format_items( $rows, array( 'metric', 'value' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $items  Rows to display.
	 * @param array<int, string>               $fields Fields to display.
	 * @return void
	 */
	private function format_items( array $items, array $fields ) {
		if ( empty( $items ) ) {
			\WP_CLI::log( 'None.' );
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $items, $fields );
	}
}
