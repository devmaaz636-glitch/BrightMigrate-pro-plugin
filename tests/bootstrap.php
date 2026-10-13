<?php
/**
 * PHPUnit bootstrap.
 *
 * Defines ABSPATH so plugin files can be loaded without WordPress.
 *
 * @package BrightMigrate
 */

if ( ! defined( 'ABSPATH' ) ) {
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Lets plugin files load under PHPUnit.
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

$bmx_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! is_readable( $bmx_autoload ) ) {
	fwrite( STDERR, "Run composer install before PHPUnit.\n" );
	exit( 1 );
}

require $bmx_autoload;
