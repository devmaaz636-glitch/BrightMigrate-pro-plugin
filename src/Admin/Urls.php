<?php
/**
 * Admin URLs.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds admin URLs that stay on the network screen when the request is there.
 *
 * @since 1.0.0
 */
class Urls {

	/**
	 * URL of a BrightMigrate admin page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Page slug.
	 * @return string
	 */
	public static function page( $slug ) {
		$base = is_network_admin() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
		return add_query_arg( 'page', $slug, $base );
	}
}
