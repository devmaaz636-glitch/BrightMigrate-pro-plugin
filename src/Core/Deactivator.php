<?php
/**
 * Plugin deactivation.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clears scheduled work and leaves tables, settings, and archives in place.
 *
 * @since 1.0.0
 */
class Deactivator {

	/**
	 * Deactivation hook.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Options::INSTALL_HOOK );
		delete_site_transient( Options::INSTALL_LOCK );
	}
}
