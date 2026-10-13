<?php
/**
 * Admin menu.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin;

use BrightIdeas\Migrate\Admin\Screens\BackupsScreen;
use BrightIdeas\Migrate\Admin\Screens\DashboardScreen;
use BrightIdeas\Migrate\Admin\Screens\ExportScreen;
use BrightIdeas\Migrate\Admin\Screens\ImportScreen;
use BrightIdeas\Migrate\Admin\Screens\ReportsScreen;
use BrightIdeas\Migrate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the BrightMigrate menu for site admin and network admin.
 *
 * @since 1.0.0
 */
class Menu {

	/**
	 * Hook menu registration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_menu', array( self::class, 'register_site_menu' ) );
		add_action( 'network_admin_menu', array( self::class, 'register_network_menu' ) );
	}

	/**
	 * Site admin menu. Requires manage_options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_site_menu() {
		self::register( Capabilities::SITE );
	}

	/**
	 * Network admin menu. Requires manage_network_options.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_network_menu() {
		self::register( Capabilities::NETWORK );
	}

	/**
	 * Dashboard callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		( new DashboardScreen() )->render();
	}

	/**
	 * Export callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_export() {
		( new ExportScreen() )->render();
	}

	/**
	 * Import callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_import() {
		( new ImportScreen() )->render();
	}

	/**
	 * Backups callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_backups() {
		( new BackupsScreen() )->render();
	}

	/**
	 * Reports callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_reports() {
		( new ReportsScreen() )->render();
	}

	/**
	 * Settings callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_settings() {
		( new SettingsScreen() )->render();
	}

	/**
	 * Add the top-level menu and its screens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $capability Capability required to see the menu.
	 * @return void
	 */
	private static function register( $capability ) {
		add_menu_page(
			__( 'BrightMigrate', 'brightmigrate-pro' ),
			__( 'BrightMigrate', 'brightmigrate-pro' ),
			$capability, // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Caller passes Capabilities::SITE or Capabilities::NETWORK only.
			'brightmigrate',
			array( self::class, 'render_dashboard' ),
			'dashicons-migrate',
			76
		);

		$pages = array(
			'brightmigrate'          => array( __( 'Dashboard', 'brightmigrate-pro' ), 'render_dashboard' ),
			'brightmigrate-export'   => array( __( 'Export', 'brightmigrate-pro' ), 'render_export' ),
			'brightmigrate-import'   => array( __( 'Import', 'brightmigrate-pro' ), 'render_import' ),
			'brightmigrate-backups'  => array( __( 'Backups', 'brightmigrate-pro' ), 'render_backups' ),
			'brightmigrate-reports'  => array( __( 'Reports & Logs', 'brightmigrate-pro' ), 'render_reports' ),
			'brightmigrate-settings' => array( __( 'Settings', 'brightmigrate-pro' ), 'render_settings' ),
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				'brightmigrate',
				$page[0],
				$page[0],
				$capability, // phpcs:ignore WordPress.WP.Capabilities.Undetermined -- Caller passes Capabilities::SITE or Capabilities::NETWORK only.
				$slug,
				array( self::class, $page[1] )
			);
		}
	}
}
