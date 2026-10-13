<?php
/**
 * Canonical option and hook names.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Names for options, site options, and the install cron hook.
 *
 * @since 1.0.0
 */
class Options {

	/**
	 * Stored schema version.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const DB_VERSION = 'brightmigrate_db_version';

	/**
	 * Random suffix for the default storage directory.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const STORAGE_SUFFIX = 'brightmigrate_storage_suffix';

	/**
	 * Settings array (storage, chunks, retention, email, uninstall).
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const SETTINGS = 'brightmigrate_settings';

	/**
	 * Network install queue offset.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const INSTALL_OFFSET = 'brightmigrate_install_offset';

	/**
	 * Short lock so two requests do not install the same sites.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const INSTALL_LOCK = 'brightmigrate_install_lock';

	/**
	 * Cron hook that continues multisite installation.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const INSTALL_HOOK = 'brightmigrate_install_sites_batch';

	/**
	 * Prefix owned by this plugin for options and transients.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const PREFIX = 'brightmigrate_';
}
