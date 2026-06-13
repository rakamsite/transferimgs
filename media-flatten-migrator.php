<?php
/**
 * Plugin Name: Media Flatten Migrator
 * Description: Safely assess and migrate dated uploads into the uploads root.
 * Version: 1.2.0
 * Author: Media Flatten Migrator
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/class-scan-result.php';
require_once __DIR__ . '/src/class-attachment-scanner.php';
require_once __DIR__ . '/src/class-schema.php';
require_once __DIR__ . '/src/class-manifest-repository.php';
require_once __DIR__ . '/src/class-target-resolver.php';
require_once __DIR__ . '/src/class-usage-reporter.php';
require_once __DIR__ . '/src/class-single-attachment-migrator.php';
require_once __DIR__ . '/src/class-batch-migrator.php';
require_once __DIR__ . '/src/class-url-replacer.php';
require_once __DIR__ . '/src/class-old-url-audit-service.php';
require_once __DIR__ . '/src/class-verification-service.php';
require_once __DIR__ . '/src/class-redirect-export-service.php';
require_once __DIR__ . '/src/class-old-file-deletion-service.php';
require_once __DIR__ . '/src/class-empty-directory-cleanup-service.php';
require_once __DIR__ . '/src/class-final-migration-report-service.php';
require_once __DIR__ . '/src/class-cli-command.php';
require_once __DIR__ . '/src/class-admin-controller.php';
require_once __DIR__ . '/src/class-plugin.php';

register_activation_hook( __FILE__, array( '\MediaFlattenMigrator\Schema', 'install' ) );

\MediaFlattenMigrator\Plugin::init();
