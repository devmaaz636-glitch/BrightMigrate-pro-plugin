<?php
/**
 * Uninstall cleanup.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

use BrightIdeas\Migrate\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes tables, options, and transients. Archives are removed only when the setting says so.
 *
 * @since 1.0.0
 */
class Uninstaller {

	/**
	 * Sites cleaned per loop during uninstall.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const BATCH_SIZE = 50;

	/**
	 * Entry point from uninstall.php.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		if ( ! self::user_may_uninstall() ) {
			return;
		}

		if ( is_multisite() ) {
			$offset = 0;
			do {
				$site_ids = get_sites(
					array(
						'fields' => 'ids',
						'number' => self::BATCH_SIZE,
						'offset' => $offset,
					)
				);
				foreach ( $site_ids as $site_id ) {
					switch_to_blog( (int) $site_id );
					self::uninstall_current_site();
					restore_current_blog();
				}
				$batch_count = count( $site_ids );
				$offset     += self::BATCH_SIZE;
			} while ( self::BATCH_SIZE === $batch_count );

			self::delete_network_meta();
		} else {
			self::uninstall_current_site();
		}

		wp_clear_scheduled_hook( Options::INSTALL_HOOK );
	}

	/**
	 * Clean the blog currently selected.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function uninstall_current_site() {
		$paths = array();
		if ( self::should_delete_archives() ) {
			$active  = Storage::active_path();
			$default = Storage::default_path();
			if ( '' !== $active ) {
				$paths[] = $active;
			}
			if ( '' !== $default && $default !== $active ) {
				$paths[] = $default;
			}
		}

		wp_clear_scheduled_hook( Options::INSTALL_HOOK );
		self::drop_tables();
		self::delete_options();

		foreach ( $paths as $path ) {
			$checked = Storage::validate_location( $path );
			if ( $checked['valid'] ) {
				Storage::remove_storage_directory( $checked['path'] );
			}
		}
	}

	/**
	 * Whether this site opted in to deleting archive files.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function should_delete_archives() {
		$settings = get_option( Options::SETTINGS, array() );
		return is_array( $settings ) && isset( $settings['delete_archives'] ) && '1' === (string) $settings['delete_archives'];
	}

	/**
	 * Drop the four plugin tables for the current prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function drop_tables() {
		global $wpdb;

		foreach ( Schema::SUFFIXES as $suffix ) {
			$table = Schema::table( $suffix );
			if ( '' === $table || ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Identifier is allow-listed by Schema::table().
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Delete options and transients that use our prefix.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function delete_options() {
		global $wpdb;

		$like_option    = $wpdb->esc_like( Options::PREFIX ) . '%';
		$like_transient = $wpdb->esc_like( '_transient_' . Options::PREFIX ) . '%';
		$like_timeout   = $wpdb->esc_like( '_transient_timeout_' . Options::PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove every option with our prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like_option,
				$like_transient,
				$like_timeout
			)
		);
	}

	/**
	 * Delete network-level options and site transients.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function delete_network_meta() {
		global $wpdb;

		if ( empty( $wpdb->sitemeta ) ) {
			return;
		}

		$like_meta      = $wpdb->esc_like( Options::PREFIX ) . '%';
		$like_transient = $wpdb->esc_like( '_site_transient_' . Options::PREFIX ) . '%';
		$like_timeout   = $wpdb->esc_like( '_site_transient_timeout_' . Options::PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must remove network meta with our prefix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s OR meta_key LIKE %s OR meta_key LIKE %s",
				$like_meta,
				$like_transient,
				$like_timeout
			)
		);
	}

	/**
	 * Uninstall is reached from an authorized plugin deletion or WP-CLI.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private static function user_may_uninstall() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return current_user_can( 'activate_plugins' )
			|| current_user_can( 'delete_plugins' )
			|| current_user_can( 'manage_network_plugins' );
	}
}
