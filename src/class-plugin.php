<?php

namespace MediaFlattenMigrator;

final class Plugin {
	/**
	 * Register the plugin's WP-CLI command.
	 *
	 * @return void
	 */
	public static function init() {
		if ( is_admin() ) {
			( new Admin_Controller() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'media-flatten', new CLI_Command() );
		}
	}
}
