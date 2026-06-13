<?php

namespace MediaFlattenMigrator;

final class Manifest_Repository {
	/** @var \wpdb */
	private $wpdb;

	/** @var string */
	private $table_name;

	public function __construct() {
		global $wpdb;

		$this->wpdb       = $wpdb;
		$this->table_name = Schema::table_name();
	}

	/**
	 * Check whether the manifest table exists.
	 *
	 * @return bool
	 */
	public function table_exists() {
		$found = $this->wpdb->get_var(
			$this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table_name ) )
		);

		return $this->table_name === $found;
	}

	/**
	 * Insert or update scan files without creating duplicate logical rows.
	 *
	 * @param array<int, array<string, mixed>> $files Scan file rows.
	 * @return array<string, int>
	 */
	public function save_files( array $files ) {
		$counts = array(
			'inserted' => 0,
			'updated'  => 0,
		);

		foreach ( $files as $file ) {
			$existing_id = $this->find_existing_id(
				(int) $file['attachment_id'],
				$file['type'],
				in_array( $file['type'], array( 'image_size', 'backup_size' ), true ) ? $file['size_name'] : null
			);

			if ( $existing_id ) {
				$this->update_file( $existing_id, $file );
				++$counts['updated'];
			} else {
				$this->insert_file( $file );
				++$counts['inserted'];
			}
		}

		return $counts;
	}

	/**
	 * Return manifest counts grouped by status and file kind.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function grouped_counts() {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$sql = "SELECT status, file_kind, COUNT(*) AS item_count
			FROM {$this->table_name}
			GROUP BY status, file_kind
			ORDER BY status ASC, file_kind ASC";

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count manifest rows.
	 *
	 * @param bool $non_migrated_only Whether to exclude migrated rows.
	 * @return int
	 */
	public function count_rows( $non_migrated_only = false ) {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		$where = $non_migrated_only ? " WHERE migrated_at IS NULL AND status <> 'migrated'" : '';
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name}{$where}" );
	}

	/**
	 * Count distinct manifest attachments.
	 *
	 * @return int
	 */
	public function count_attachments() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(DISTINCT attachment_id) FROM {$this->table_name}" );
	}

	/**
	 * Read non-migrated manifest rows in ID order.
	 *
	 * @param int               $after_id Only return IDs greater than this value.
	 * @param int               $limit    Maximum rows to return.
	 * @param array<int,string> $statuses Optional status filter.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_non_migrated_rows( $after_id, $limit, array $statuses = array() ) {
		$where  = "migrated_at IS NULL AND status <> 'migrated' AND id > %d";
		$params = array( (int) $after_id );

		if ( $statuses ) {
			$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$where       .= " AND status IN ({$placeholders})";
			$params       = array_merge( $params, $statuses );
		}

		$params[] = max( 1, (int) $limit );
		$sql      = $this->wpdb->prepare(
			"SELECT id, attachment_id, old_rel_path, old_abs_path, new_rel_path,
				new_abs_path, new_url, status, error_message
			FROM {$this->table_name}
			WHERE {$where}
			ORDER BY id ASC
			LIMIT %d",
			$params
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Read all manifest rows in ID order.
	 *
	 * @param int $after_id Only return IDs greater than this value.
	 * @param int $limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_rows( $after_id, $limit ) {
		$sql = $this->wpdb->prepare(
			"SELECT id, attachment_id, old_rel_path, old_abs_path, new_rel_path,
				new_abs_path, new_url, status, error_message
			FROM {$this->table_name}
			WHERE id > %d
			ORDER BY id ASC
			LIMIT %d",
			(int) $after_id,
			max( 1, (int) $limit )
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Read migrated manifest rows with every field required for verification.
	 *
	 * @param int $after_id Only return IDs greater than this value.
	 * @param int $limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_migrated_rows( $after_id, $limit ) {
		$sql = $this->wpdb->prepare(
			"SELECT id, attachment_id, old_rel_path, new_rel_path, old_url, new_url,
				old_abs_path, new_abs_path, file_kind, size_key, status
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size') AND id > %d
			ORDER BY id ASC
			LIMIT %d",
			(int) $after_id,
			max( 1, (int) $limit )
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Read distinct attachment IDs that have migrated manifest rows.
	 *
	 * @param int $after_id Only return attachment IDs greater than this value.
	 * @param int $limit    Maximum IDs to return.
	 * @return array<int, int>
	 */
	public function get_migrated_attachment_ids( $after_id, $limit ) {
		$sql = $this->wpdb->prepare(
			"SELECT DISTINCT attachment_id
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision') AND attachment_id > %d
			ORDER BY attachment_id ASC
			LIMIT %d",
			(int) $after_id,
			max( 1, (int) $limit )
		);

		return array_map( 'intval', $this->wpdb->get_col( $sql ) );
	}

	/**
	 * Return migrated rows eligible for old-file deletion.
	 *
	 * @param int $after_id Only return IDs greater than this value.
	 * @param int $limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_deletion_rows( $after_id, $limit ) {
		$sql = $this->wpdb->prepare(
			"SELECT m.id, m.attachment_id, m.old_rel_path, m.old_abs_path, m.new_rel_path, m.new_abs_path,
				m.old_url, m.new_url, m.file_kind, m.size_key, m.status, m.old_deleted_at, m.old_delete_status, m.old_delete_error,
				main_row.new_abs_path AS main_new_abs_path, main_row.new_rel_path AS main_new_rel_path
			FROM {$this->table_name} m
			LEFT JOIN {$this->table_name} main_row
				ON main_row.attachment_id = m.attachment_id
				AND main_row.file_kind = 'main'
				AND main_row.status = 'migrated'
			WHERE m.status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND ( m.old_delete_status IS NULL OR m.old_delete_status = '' )
				AND m.id > %d
			ORDER BY m.id ASC
			LIMIT %d",
			(int) $after_id,
			max( 1, (int) $limit )
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count migrated rows waiting for old-file deletion.
	 *
	 * @return int
	 */
	public function count_deletion_candidates() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND ( old_delete_status IS NULL OR old_delete_status = '' )"
		);
	}

	/**
	 * Count migrated rows whose old file has already been deleted.
	 *
	 * @return int
	 */
	public function count_deleted_old_files() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND old_delete_status = 'deleted'"
		);
	}

	/**
	 * Count migrated rows whose old file was already missing.
	 *
	 * @return int
	 */
	public function count_already_missing_old_files() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND old_delete_status = 'already_missing'"
		);
	}

	/**
	 * Count migrated rows whose old-file deletion failed.
	 *
	 * @return int
	 */
	public function count_failed_old_file_deletions() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND old_delete_status = 'failed'"
		);
	}

	/**
	 * Update only the old-file deletion tracking fields.
	 *
	 * @param int         $id           Manifest row ID.
	 * @param string      $status       Deletion status.
	 * @param string|null $error_message Error message.
	 * @return void
	 */
	public function update_old_delete_status( $id, $status, $error_message = null ) {
		$data = array(
			'old_delete_status' => $status,
			'old_delete_error'  => $error_message,
			'old_deleted_at'    => current_time( 'mysql' ),
			'updated_at'        => current_time( 'mysql' ),
		);

		$result = $this->wpdb->update(
			$this->table_name,
			$data,
			array( 'id' => (int) $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Could not update old-file deletion status: ' . $this->wpdb->last_error );
		}
	}

	/** @return int */
	public function count_migrated_rows() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')"
		);
	}

	/** @return int */
	public function count_migrated_attachments() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(DISTINCT attachment_id)
			FROM {$this->table_name}
			WHERE status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')"
		);
	}

	/**
	 * Update only Phase 2 target fields for a non-migrated row.
	 *
	 * @param int                  $id     Manifest row ID.
	 * @param array<string, mixed> $target Resolved target values.
	 * @return void
	 */
	public function update_target( $id, array $target ) {
		$data = array(
			'new_rel_path'  => $target['new_rel_path'],
			'new_abs_path'  => $target['new_abs_path'],
			'new_url'       => $target['new_url'],
			'status'        => $target['status'],
			'error_message' => $target['error_message'],
			'updated_at'    => current_time( 'mysql' ),
		);

		$result = $this->wpdb->update(
			$this->table_name,
			$data,
			array(
				'id'          => (int) $id,
				'migrated_at' => null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d', null )
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Could not update manifest target row: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Return status totals for the manifest.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function status_counts() {
		if ( ! $this->table_exists() ) {
			return array();
		}

		$sql = "SELECT status, COUNT(*) AS item_count
			FROM {$this->table_name}
			GROUP BY status
			ORDER BY status ASC";
		$raw = $this->wpdb->get_results( $sql, ARRAY_A );
		$counts = array(
			'pending'           => 0,
			'missing'           => 0,
			'blocked_collision' => 0,
			'resolved'          => 0,
			'copying'           => 0,
			'copied'            => 0,
			'migrated'          => 0,
			'adopted_root_size' => 0,
			'omitted_size_collision' => 0,
			'failed'            => 0,
		);

		foreach ( $raw as $row ) {
			$counts[ $row['status'] ] = (int) $row['item_count'];
		}

		$rows = array();
		foreach ( $counts as $status => $count ) {
			$rows[] = array(
				'status'     => $status,
				'item_count' => $count,
			);
		}

		return $rows;
	}

	/**
	 * Return all manifest rows for one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_attachment_rows( $attachment_id ) {
		$sql = $this->wpdb->prepare(
			"SELECT id, attachment_id, old_rel_path, new_rel_path, old_url, new_url,
				old_abs_path, new_abs_path, file_kind, size_key, status,
				error_message, migrated_at, verified_at
			FROM {$this->table_name}
			WHERE attachment_id = %d
			ORDER BY id ASC",
			(int) $attachment_id
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Return attachment-level manifest state summaries in attachment ID order.
	 *
	 * @param int $after_attachment_id Only return attachment IDs greater than this value.
	 * @param int $limit               Maximum attachments to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_attachment_summaries( $after_attachment_id, $limit ) {
		$sql = $this->wpdb->prepare(
			"SELECT attachment_id,
				COUNT(*) AS row_count,
				SUM(status = 'resolved') AS resolved_rows,
				SUM(status = 'missing') AS missing_rows,
				SUM(status = 'blocked_collision') AS collision_rows,
				SUM(status = 'failed') AS failed_rows,
				SUM(status = 'migrated') AS migrated_rows,
				SUM(status = 'adopted_root_size') AS adopted_rows,
				SUM(status = 'omitted_size_collision') AS omitted_rows,
				SUM(file_kind = 'main') AS main_rows,
				SUM(file_kind = 'main' AND status = 'resolved') AS main_resolved_rows,
				SUM(file_kind = 'main' AND status = 'missing') AS main_missing_rows,
				SUM(file_kind = 'main' AND status = 'blocked_collision') AS main_collision_rows,
				SUM(file_kind = 'main' AND status = 'failed') AS main_failed_rows,
				SUM(file_kind = 'main' AND status = 'migrated') AS main_migrated_rows,
				SUM(file_kind = 'image_size' AND status = 'blocked_collision') AS image_collision_rows,
				SUM(file_kind <> 'image_size' AND status = 'blocked_collision') AS non_image_collision_rows,
				SUM(new_rel_path IS NULL OR new_rel_path = ''
					OR new_abs_path IS NULL OR new_abs_path = ''
					OR new_url IS NULL OR new_url = '') AS incomplete_target_rows
			FROM {$this->table_name}
			WHERE attachment_id > %d
			GROUP BY attachment_id
			ORDER BY attachment_id ASC
			LIMIT %d",
			(int) $after_attachment_id,
			max( 1, (int) $limit )
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Return exact URL mappings from migrated manifest rows.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_migrated_url_mappings() {
		$sql = "SELECT m.id, m.old_url,
				CASE
					WHEN m.status = 'omitted_size_collision' THEN main_row.new_url
					ELSE m.new_url
				END AS new_url
			FROM {$this->table_name} m
			LEFT JOIN {$this->table_name} main_row
				ON main_row.attachment_id = m.attachment_id
				AND main_row.file_kind = 'main'
				AND main_row.status = 'migrated'
			WHERE m.status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND m.old_url IS NOT NULL
				AND m.old_url <> ''
				AND (
					( m.status <> 'omitted_size_collision' AND m.new_url IS NOT NULL AND m.new_url <> '' )
					OR
					( m.status = 'omitted_size_collision' AND main_row.new_url IS NOT NULL AND main_row.new_url <> '' )
				)
				AND m.old_url <> CASE
					WHEN m.status = 'omitted_size_collision' THEN main_row.new_url
					ELSE m.new_url
				END
			ORDER BY m.id ASC";

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Return redirect-export rows, including omitted sizes that fall back to the main file URL.
	 *
	 * @param int $after_id Only return IDs greater than this value.
	 * @param int $limit    Maximum rows to return.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_redirect_export_rows( $after_id, $limit ) {
		$sql = $this->wpdb->prepare(
			"SELECT m.id, m.attachment_id, m.old_rel_path, m.new_rel_path, m.old_url, m.new_url,
				m.old_abs_path, m.new_abs_path, m.file_kind, m.size_key, m.status,
				main_row.new_rel_path AS main_new_rel_path,
				main_row.new_url AS main_new_url
			FROM {$this->table_name} m
			LEFT JOIN {$this->table_name} main_row
				ON main_row.attachment_id = m.attachment_id
				AND main_row.file_kind = 'main'
				AND main_row.status = 'migrated'
			WHERE m.status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND m.id > %d
			ORDER BY m.id ASC
			LIMIT %d",
			(int) $after_id,
			max( 1, (int) $limit )
		);

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Return every manifest old URL with status for audit classification.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_all_old_url_rows() {
		$sql = "SELECT id, attachment_id, old_url, status
			FROM {$this->table_name}
			WHERE old_url IS NOT NULL
				AND old_url <> ''
			ORDER BY id ASC";

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Return duplicate logical manifest groups using attachment, kind, and normalized size key.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_duplicate_logical_groups() {
		$sql = "SELECT attachment_id, file_kind,
				COALESCE(NULLIF(size_key, ''), '__NULL__') AS normalized_size_key,
				COUNT(*) AS duplicate_count
			FROM {$this->table_name}
			GROUP BY attachment_id, file_kind, COALESCE(NULLIF(size_key, ''), '__NULL__')
			HAVING COUNT(*) > 1
			ORDER BY attachment_id ASC, file_kind ASC, normalized_size_key ASC";

		return $this->wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Count migrated rows missing old or new URL values.
	 *
	 * @return int
	 */
	public function count_invalid_migrated_url_rows() {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name} m
			LEFT JOIN {$this->table_name} main_row
				ON main_row.attachment_id = m.attachment_id
				AND main_row.file_kind = 'main'
				AND main_row.status = 'migrated'
			WHERE m.status IN ('migrated', 'adopted_root_size', 'omitted_size_collision')
				AND (
					m.old_url IS NULL
					OR m.old_url = ''
					OR (
						m.status = 'omitted_size_collision'
						AND ( main_row.new_url IS NULL OR main_row.new_url = '' )
					)
					OR (
						m.status <> 'omitted_size_collision'
						AND ( m.new_url IS NULL OR m.new_url = '' )
					)
				)"
		);
	}

	/**
	 * Set migration state for selected manifest rows.
	 *
	 * @param array<int, int> $row_ids       Manifest row IDs.
	 * @param string          $status        New status.
	 * @param string|null     $error_message Optional error message.
	 * @param bool            $set_migrated  Whether to set migrated_at.
	 * @return void
	 */
	public function set_rows_status( array $row_ids, $status, $error_message = null, $set_migrated = false ) {
		$now = current_time( 'mysql' );

		foreach ( array_map( 'intval', $row_ids ) as $row_id ) {
			$data = array(
				'status'        => $status,
				'error_message' => $error_message,
				'updated_at'    => $now,
				'migrated_at'   => null,
			);
			$formats = array( '%s', '%s', '%s', null );

			if ( $set_migrated ) {
				$data['migrated_at'] = $now;
				$formats[3]          = '%s';
			}

			$result = $this->wpdb->update(
				$this->table_name,
				$data,
				array( 'id' => $row_id ),
				$formats,
				array( '%d' )
			);

			if ( false === $result ) {
				throw new \RuntimeException( 'Could not update manifest migration status: ' . $this->wpdb->last_error );
			}
		}
	}

	/**
	 * @param int         $attachment_id Attachment ID.
	 * @param string      $file_kind     Manifest file kind.
	 * @param string|null $size_key      Image size key, or null.
	 * @return int
	 */
	private function find_existing_id( $attachment_id, $file_kind, $size_key ) {
		if ( null === $size_key ) {
			$sql = $this->wpdb->prepare(
				"SELECT id FROM {$this->table_name}
				WHERE attachment_id = %d AND file_kind = %s AND size_key IS NULL
				LIMIT 1",
				$attachment_id,
				$file_kind
			);
		} else {
			$sql = $this->wpdb->prepare(
				"SELECT id FROM {$this->table_name}
				WHERE attachment_id = %d AND file_kind = %s AND size_key = %s
				LIMIT 1",
				$attachment_id,
				$file_kind,
				$size_key
			);
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @param array<string, mixed> $file Scan file row.
	 * @return void
	 */
	private function insert_file( array $file ) {
		$now      = current_time( 'mysql' );
		$exists   = 'yes' === $file['exists'];
		$size_key = in_array( $file['type'], array( 'image_size', 'backup_size' ), true ) ? $file['size_name'] : null;
		$data     = array(
			'attachment_id' => (int) $file['attachment_id'],
			'old_rel_path'  => $file['source_relative'],
			'new_rel_path'  => null,
			'old_url'       => $file['source_url'],
			'new_url'       => null,
			'old_abs_path'  => $file['source_path'],
			'new_abs_path'  => null,
			'file_kind'     => $file['type'],
			'size_key'      => $size_key,
			'status'        => $exists ? 'pending' : 'missing',
			'error_message' => $exists ? null : 'Source file does not exist.',
			'created_at'    => $now,
			'updated_at'    => $now,
			'migrated_at'   => null,
			'verified_at'   => null,
		);
		$formats  = array( '%d', '%s', null, '%s', null, '%s', null, '%s', '%s', '%s', '%s', '%s', '%s', null, null );

		$result = $this->wpdb->insert( $this->table_name, $data, $formats );
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not insert manifest row: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * @param int                  $id   Manifest row ID.
	 * @param array<string, mixed> $file Scan file row.
	 * @return void
	 */
	private function update_file( $id, array $file ) {
		$exists = 'yes' === $file['exists'];
		$data   = array(
			'old_rel_path'  => $file['source_relative'],
			'old_url'       => $file['source_url'],
			'old_abs_path'  => $file['source_path'],
			'updated_at'    => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		$current = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT status, migrated_at FROM {$this->table_name} WHERE id = %d",
				(int) $id
			),
			ARRAY_A
		);

		if ( $current && empty( $current['migrated_at'] ) && 'migrated' !== $current['status'] ) {
			$data['status']        = $exists ? 'pending' : 'missing';
			$data['error_message'] = $exists ? null : 'Source file does not exist.';
			$formats[]             = '%s';
			$formats[]             = '%s';
		}

		$result = $this->wpdb->update( $this->table_name, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Could not update manifest row: ' . $this->wpdb->last_error );
		}
	}
}
