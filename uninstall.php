<?php
/**
 * Removes BrightMigrate data when the plugin is deleted.
 *
 * @package BrightMigrate
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'BMX_PLUGIN_DIR' ) ) {
	define( 'BMX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

$bmx_composer = BMX_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $bmx_composer ) ) {
	require_once $bmx_composer;
}

require_once BMX_PLUGIN_DIR . 'src/Core/Autoloader.php';
BrightIdeas\Migrate\Core\Autoloader::register();
BrightIdeas\Migrate\Core\Uninstaller::uninstall();
