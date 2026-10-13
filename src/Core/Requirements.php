<?php
/**
 * Runtime requirement checks.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares the server with the versions and extensions this plugin needs.
 *
 * @since 1.0.0
 */
class Requirements {

	/**
	 * Oldest PHP version this plugin will activate on.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const MIN_PHP = '7.4';

	/**
	 * Oldest WordPress version this plugin will activate on.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const MIN_WP = '6.2';

	/**
	 * Extensions required for activation.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const REQUIRED = array( 'zlib', 'json', 'mysqli' );

	/**
	 * Extensions that unlock extra features but must not block activation.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const RECOMMENDED = array( 'zip', 'mbstring', 'openssl' );

	/**
	 * Failure codes for the current process.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $php_version Installed PHP version.
	 * @param string   $wp_version  Installed WordPress version.
	 * @param string[] $extensions  Loaded extension names.
	 * @return string[] Empty when the server can run the plugin.
	 */
	public static function failures( $php_version, $wp_version, array $extensions ) {
		$codes = array();

		if ( version_compare( (string) $php_version, self::MIN_PHP, '<' ) ) {
			$codes[] = 'php';
		}

		if ( version_compare( (string) $wp_version, self::MIN_WP, '<' ) ) {
			$codes[] = 'wp';
		}

		foreach ( self::REQUIRED as $extension ) {
			if ( ! in_array( $extension, $extensions, true ) ) {
				$codes[] = 'ext:' . $extension;
			}
		}

		return $codes;
	}

	/**
	 * Recommended extensions that are not loaded.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $extensions Loaded extension names.
	 * @return string[]
	 */
	public static function missing_recommended( array $extensions ) {
		$missing = array();

		foreach ( self::RECOMMENDED as $extension ) {
			if ( ! in_array( $extension, $extensions, true ) ) {
				$missing[] = $extension;
			}
		}

		return $missing;
	}

	/**
	 * Whether this PHP build uses 32-bit integers.
	 *
	 * @since 1.0.0
	 *
	 * @param int $int_size PHP_INT_SIZE.
	 * @return bool
	 */
	public static function is_32_bit( $int_size ) {
		return 4 === (int) $int_size;
	}

	/**
	 * Failure codes for the running site.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function current_failures() {
		return self::failures( PHP_VERSION, self::wp_version(), get_loaded_extensions() );
	}

	/**
	 * Plain-language message for one failure code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Failure code from failures().
	 * @return string
	 */
	public static function message( $code ) {
		if ( 'php' === $code ) {
			return sprintf(
				/* translators: 1: required PHP version, 2: current PHP version. */
				__( 'BrightMigrate Pro needs PHP %1$s or newer. This server is running PHP %2$s.', 'brightmigrate-pro' ),
				self::MIN_PHP,
				PHP_VERSION
			);
		}

		if ( 'wp' === $code ) {
			return sprintf(
				/* translators: 1: required WordPress version, 2: current WordPress version. */
				__( 'BrightMigrate Pro needs WordPress %1$s or newer. This site is running WordPress %2$s.', 'brightmigrate-pro' ),
				self::MIN_WP,
				self::wp_version()
			);
		}

		if ( 0 === strpos( (string) $code, 'ext:' ) ) {
			return sprintf(
				/* translators: %s: PHP extension name. */
				__( 'BrightMigrate Pro needs the PHP %s extension. Ask your host to enable it.', 'brightmigrate-pro' ),
				sanitize_key( substr( (string) $code, 4 ) )
			);
		}

		return __( 'BrightMigrate Pro cannot run on this server.', 'brightmigrate-pro' );
	}

	/**
	 * Show blocking requirement failures to administrators only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_blocking_notice() {
		if ( ! Capabilities::current_user_can() ) {
			return;
		}

		$codes = self::current_failures();
		if ( array() === $codes ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: log-free requirement summary. */
				__( 'BrightMigrate Pro is active but cannot run. %s', 'brightmigrate-pro' ),
				implode( ' ', array_map( array( self::class, 'message' ), $codes ) )
			)
		);
		echo '</p></div>';
	}

	/**
	 * WordPress version from the global set before plugins load.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function wp_version() {
		global $wp_version;

		return is_string( $wp_version ) ? $wp_version : '0';
	}
}
