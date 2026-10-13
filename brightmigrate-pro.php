<?php
/**
 * BrightMigrate Pro
 *
 * @package           BrightMigrate
 * @author            Bright Ideas Communication
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       BrightMigrate Pro
 * Description:       Full-site backup, content-only transfer, and theme-kit migration with resumable chunked archives and no size limit of its own.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Bright Ideas Communication
 * Text Domain:       brightmigrate-pro
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://updates.example.com/brightmigrate-pro
 * Network:           true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BMX_VERSION', '1.0.0' );
define( 'BMX_DB_VERSION', '1.0.0' );
define( 'BMX_PLUGIN_FILE', __FILE__ );
define( 'BMX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BMX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'BMX_TEXT_DOMAIN', 'brightmigrate-pro' );
define( 'BMX_MIN_PHP', '7.4' );
define( 'BMX_MIN_WP', '6.2' );
define( 'BMX_UPDATE_URI', 'https://updates.example.com/brightmigrate-pro' );

$bmx_composer = BMX_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $bmx_composer ) ) {
	require_once $bmx_composer;
}

require_once BMX_PLUGIN_DIR . 'src/Core/Autoloader.php';
BrightIdeas\Migrate\Core\Autoloader::register();

register_activation_hook( BMX_PLUGIN_FILE, array( BrightIdeas\Migrate\Core\Activator::class, 'activate' ) );
register_deactivation_hook( BMX_PLUGIN_FILE, array( BrightIdeas\Migrate\Core\Deactivator::class, 'deactivate' ) );
add_action( 'plugins_loaded', array( BrightIdeas\Migrate\Core\Plugin::class, 'boot' ) );
