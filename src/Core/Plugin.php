<?php
/**
 * Plugin bootstrap.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

use BrightIdeas\Migrate\Admin\Assets;
use BrightIdeas\Migrate\Admin\ExportActions;
use BrightIdeas\Migrate\Admin\Menu;
use BrightIdeas\Migrate\Admin\SettingsScreen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Starts BrightMigrate on admin, cron, and WP-CLI requests only.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Callback for plugins_loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! self::should_boot() ) {
			return;
		}

		add_action( 'init', array( self::class, 'load_textdomain' ) );

		if ( Requirements::current_failures() ) {
			add_action( 'admin_notices', array( Requirements::class, 'render_blocking_notice' ) );
			add_action( 'network_admin_notices', array( Requirements::class, 'render_blocking_notice' ) );
			return;
		}

		Activator::maybe_upgrade();

		if ( is_admin() ) {
			add_action( 'admin_init', array( Activator::class, 'maybe_continue_install' ) );
			Menu::hooks();
			Assets::hooks();
			SettingsScreen::hooks();
			ExportActions::hooks();
		}

		add_action( Options::INSTALL_HOOK, array( Activator::class, 'process_install_batch' ) );
		add_action( 'wp_initialize_site', array( Activator::class, 'install_new_site' ), 10, 1 );
	}

	/**
	 * Load translations on init, which is the earliest WordPress recommends.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'brightmigrate-pro',
			false,
			dirname( BMX_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Front-end requests do not boot the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function should_boot() {
		if ( is_admin() || wp_doing_cron() ) {
			return true;
		}

		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
