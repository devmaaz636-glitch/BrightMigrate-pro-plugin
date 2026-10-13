<?php
/**
 * Plugin activation and per-site installation.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

use BrightIdeas\Migrate\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates tables, storage, and default options.
 *
 * Network installs run a few sites per request so a large network does not
 * time out inside the activation click.
 *
 * @since 1.0.0
 */
class Activator {

	/**
	 * Sites installed in one batch.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const BATCH_SIZE = 5;

	/**
	 * Stop a batch after this many seconds.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const BATCH_SECONDS = 15;

	/**
	 * Activation hook.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( ! self::user_may_activate() ) {
			self::abort( array( 'denied' ) );
		}

		$failures = Requirements::current_failures();
		if ( $failures ) {
			self::abort( $failures );
		}

		if ( is_multisite() && $network_wide ) {
			update_site_option( Options::INSTALL_OFFSET, 0 );
			self::process_install_batch();
			return;
		}

		self::install_site();
	}

	/**
	 * Install the current site when its schema is older than this build.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$current = get_option( Options::DB_VERSION, '' );
		if ( is_string( $current ) && '' !== $current && version_compare( $current, BMX_DB_VERSION, '>=' ) ) {
			return;
		}

		self::install_site();
	}

	/**
	 * Continue a network install from an admin request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_continue_install() {
		if ( ! is_multisite() ) {
			return;
		}

		$offset = get_site_option( Options::INSTALL_OFFSET, false );
		if ( false === $offset ) {
			return;
		}

		self::process_install_batch();
	}

	/**
	 * Install tables for the next few sites.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function process_install_batch() {
		if ( ! is_multisite() ) {
			self::install_site();
			return;
		}

		if ( get_site_transient( Options::INSTALL_LOCK ) ) {
			return;
		}

		set_site_transient( Options::INSTALL_LOCK, '1', 30 );

		$offset = (int) get_site_option( Options::INSTALL_OFFSET, 0 );
		$sites  = get_sites(
			array(
				'fields' => 'ids',
				'number' => self::BATCH_SIZE,
				'offset' => $offset,
			)
		);

		if ( empty( $sites ) ) {
			delete_site_option( Options::INSTALL_OFFSET );
			delete_site_transient( Options::INSTALL_LOCK );
			wp_clear_scheduled_hook( Options::INSTALL_HOOK );
			return;
		}

		$processed = 0;
		$started   = microtime( true );
		foreach ( $sites as $site_id ) {
			if ( $processed > 0 && ( microtime( true ) - $started ) > self::BATCH_SECONDS ) {
				break;
			}

			switch_to_blog( (int) $site_id );
			self::install_site();
			restore_current_blog();
			++$processed;
		}

		$site_count = count( $sites );
		$finished   = ( $site_count === $processed && self::BATCH_SIZE > $site_count );
		if ( $finished || 0 === $processed ) {
			if ( $finished ) {
				delete_site_option( Options::INSTALL_OFFSET );
				wp_clear_scheduled_hook( Options::INSTALL_HOOK );
			}
		} else {
			update_site_option( Options::INSTALL_OFFSET, $offset + $processed );
			wp_clear_scheduled_hook( Options::INSTALL_HOOK );
			wp_schedule_single_event( time() + 15, Options::INSTALL_HOOK );
		}

		delete_site_transient( Options::INSTALL_LOCK );
	}

	/**
	 * Prepare a site created after network activation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Site $new_site New site.
	 * @return void
	 */
	public static function install_new_site( $new_site ) {
		if ( ! is_multisite() || ! is_plugin_active_for_network( BMX_PLUGIN_BASENAME ) ) {
			return;
		}

		$site_id = ( $new_site instanceof \WP_Site ) ? (int) $new_site->blog_id : 0;
		if ( $site_id <= 0 ) {
			return;
		}

		switch_to_blog( $site_id );
		self::install_site();
		restore_current_blog();
	}

	/**
	 * Tables, storage, and default options for the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function install_site() {
		Schema::install();
		Storage::provision();

		if ( false === get_option( Options::SETTINGS, false ) ) {
			add_option( Options::SETTINGS, Settings::defaults(), '', 'no' );
		}

		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Options::SETTINGS, false );
		}

		update_option( Options::DB_VERSION, BMX_DB_VERSION, true );
	}

	/**
	 * Whether this request is allowed to activate the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function user_may_activate() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return current_user_can( 'activate_plugins' ) || current_user_can( 'manage_network_plugins' );
	}

	/**
	 * Deactivate and stop with a readable message.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $codes Failure codes. "denied" is a permission failure.
	 * @return void
	 */
	private static function abort( array $codes ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( BMX_PLUGIN_BASENAME );

		if ( in_array( 'denied', $codes, true ) ) {
			$message = __( 'You do not have permission to activate BrightMigrate Pro.', 'brightmigrate-pro' );
		} else {
			$messages = array();
			foreach ( $codes as $code ) {
				$messages[] = Requirements::message( $code );
			}
			$message = implode( ' ', $messages );
		}

		wp_die(
			esc_html( $message ),
			esc_html__( 'BrightMigrate Pro could not be activated', 'brightmigrate-pro' ),
			array( 'back_link' => true )
		);
	}
}
