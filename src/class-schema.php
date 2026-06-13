<?php

namespace MediaFlattenMigrator;

final class Schema {
	const VERSION        = '1.1.0';
	const VERSION_OPTION = 'media_flatten_manifest_schema_version';

	/**
	 * Return the current site's manifest table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . 'media_flatten_manifest';
	}

	/**
	 * Create or update the manifest table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			old_rel_path text NOT NULL,
			new_rel_path text NULL,
			old_url text NULL,
			new_url text NULL,
			old_abs_path text NULL,
			new_abs_path text NULL,
			file_kind varchar(32) NOT NULL,
			size_key varchar(191) NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			error_message text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			migrated_at datetime NULL,
			verified_at datetime NULL,
			old_deleted_at datetime NULL,
			old_delete_status varchar(32) NULL,
			old_delete_error text NULL,
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY file_kind (file_kind),
			UNIQUE KEY unique_file_item (attachment_id, file_kind, size_key)
		) {$charset_collate};";

		dbDelta( $sql );

		$found = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);
		if ( $table_name === $found ) {
			update_option( self::VERSION_OPTION, self::VERSION );
		}
	}
}
