<?php
/**
 * PSR-4 fallback autoloader for environments without Composer.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads BrightIdeas\Migrate classes from src/ when Composer is not installed.
 *
 * @since 1.0.0
 */
class Autoloader {

	/**
	 * Namespace prefix this loader owns.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const PREFIX = 'BrightIdeas\\Migrate\\';

	/**
	 * Register the loader.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Load a class file if it belongs to this plugin and stays inside src/.
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		if ( false !== strpos( $relative, '..' ) ) {
			return;
		}

		$base = rtrim( BMX_PLUGIN_DIR, '/\\' ) . '/src/';
		$path = $base . str_replace( '\\', '/', $relative ) . '.php';

		if ( ! is_readable( $path ) ) {
			return;
		}

		$real_file = realpath( $path );
		$real_base = realpath( $base );
		if ( false === $real_file || false === $real_base ) {
			return;
		}

		$real_file = str_replace( '\\', '/', $real_file );
		$real_base = rtrim( str_replace( '\\', '/', $real_base ), '/' ) . '/';
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$real_file = strtolower( $real_file );
			$real_base = strtolower( $real_base );
		}

		if ( 0 !== strpos( $real_file, $real_base ) ) {
			return;
		}

		require_once $path;
	}
}
