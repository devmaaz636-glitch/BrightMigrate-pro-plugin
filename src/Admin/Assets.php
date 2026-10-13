<?php
/**
 * Admin assets.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin;

use BrightIdeas\Migrate\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads CSS and JavaScript only on BrightMigrate screens.
 *
 * @since 1.0.0
 */
class Assets {

	/**
	 * Script and style handle.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const HANDLE = 'brightmigrate-admin';

	/**
	 * Hook suffixes that belong to this plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const HOOKS = array(
		'toplevel_page_brightmigrate',
		'brightmigrate_page_brightmigrate-export',
		'brightmigrate_page_brightmigrate-import',
		'brightmigrate_page_brightmigrate-backups',
		'brightmigrate_page_brightmigrate-reports',
		'brightmigrate_page_brightmigrate-settings',
	);

	/**
	 * Register the enqueue callback.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Enqueue assets when the current admin page is ours.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, self::HOOKS, true ) ) {
			return;
		}

		if ( ! Capabilities::current_user_can() ) {
			return;
		}

		wp_enqueue_style(
			self::HANDLE,
			BMX_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			self::asset_version( 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			self::HANDLE,
			BMX_PLUGIN_URL . 'assets/js/admin.js',
			array( 'wp-i18n' ),
			self::asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_set_script_translations( self::HANDLE, 'brightmigrate-pro', BMX_PLUGIN_DIR . 'languages' );

		$config = array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'brightmigrate_export' ),
			'i18n'    => array(
				'failed'         => __( 'The export could not continue.', 'brightmigrate-pro' ),
				'stopped'        => __( 'Export stopped.', 'brightmigrate-pro' ),
				'uploadFailed'   => __( 'The upload could not continue.', 'brightmigrate-pro' ),
				'working'        => __( 'Working…', 'brightmigrate-pro' ),
				'copied'         => __( 'Download link copied.', 'brightmigrate-pro' ),
				'exporting'      => __( 'Exporting', 'brightmigrate-pro' ),
				'exportDone'     => __( 'Export complete', 'brightmigrate-pro' ),
				'importing'      => __( 'Importing', 'brightmigrate-pro' ),
				'importDone'     => __( 'Import complete', 'brightmigrate-pro' ),
				'downloading'    => __( 'Downloading', 'brightmigrate-pro' ),
				'downloadDone'   => __( 'Download complete', 'brightmigrate-pro' ),
				'downloadFailed' => __( 'The download could not finish.', 'brightmigrate-pro' ),
				'cancelled'      => __( 'Cancelled.', 'brightmigrate-pro' ),
			),
		);
		wp_add_inline_script(
			self::HANDLE,
			'window.brightmigrateAdmin = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Cache-bust a plugin asset when the file changes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Path under the plugin directory.
	 * @return string
	 */
	private static function asset_version( $relative ) {
		$path = BMX_PLUGIN_DIR . ltrim( $relative, '/' );
		$time = is_readable( $path ) ? filemtime( $path ) : false;
		if ( false === $time ) {
			return BMX_VERSION;
		}
		return BMX_VERSION . '.' . (string) $time;
	}
}
