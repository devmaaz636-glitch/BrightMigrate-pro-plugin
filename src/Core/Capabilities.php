<?php
/**
 * Access rules for every BrightMigrate screen and action.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrators only. The capability is intentionally not filterable.
 *
 * @since 1.0.0
 */
class Capabilities {

	/**
	 * Site admin capability.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const SITE = 'manage_options';

	/**
	 * Network admin capability.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const NETWORK = 'manage_network_options';

	/**
	 * Capability required for this admin context.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_multisite     Whether the installation is multisite.
	 * @param bool $is_network_admin Whether the request is in network admin.
	 * @return string
	 */
	public static function required( $is_multisite, $is_network_admin ) {
		if ( $is_multisite && $is_network_admin ) {
			return self::NETWORK;
		}

		return self::SITE;
	}

	/**
	 * Whether the current user may use BrightMigrate in this request.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		if ( is_multisite() && is_network_admin() ) {
			return current_user_can( self::NETWORK );
		}

		return current_user_can( self::SITE );
	}

	/**
	 * Stop the request with a generic permission message.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deny() {
		wp_die(
			esc_html__( 'You do not have permission to use BrightMigrate.', 'brightmigrate-pro' ),
			esc_html__( 'Not allowed', 'brightmigrate-pro' ),
			array( 'response' => 403 )
		);
	}
}
