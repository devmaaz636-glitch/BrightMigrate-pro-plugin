<?php
/**
 * Plugin header and file safety checks.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Requirements;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Checks the plugin header and direct-access guards.
 *
 * @coversNothing
 */
class StandardsTest extends TestCase {

	/**
	 * The plugin header carries every required field.
	 */
	public function test_plugin_header() {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/brightmigrate-pro.php' );
		$this->assertIsString( $contents );

		$fields = array(
			'Plugin Name:       BrightMigrate Pro',
			'Requires at least: 6.2',
			'Requires PHP:      7.4',
			'Author:            Bright Ideas Communication',
			'Text Domain:       brightmigrate-pro',
			'License:           GPL v2 or later',
			'Update URI:        https://updates.example.com/brightmigrate-pro',
		);

		foreach ( $fields as $field ) {
			$this->assertStringContainsString( $field, $contents );
		}

		$this->assertStringContainsString( 'Description:', $contents );
		$this->assertStringContainsString( 'Version:', $contents );
		$this->assertSame( '7.4', Requirements::MIN_PHP );
		$this->assertSame( '6.2', Requirements::MIN_WP );
	}

	/**
	 * Composer autoloads the plugin namespace from src/.
	 */
	public function test_composer_autoload() {
		$json = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );
		$this->assertSame( 'src/', $json['autoload']['psr-4']['BrightIdeas\\Migrate\\'] );
	}

	/**
	 * Every PHP file refuses direct access and does not eval or unserialize.
	 */
	public function test_php_files_are_guarded() {
		$root  = dirname( __DIR__, 2 );
		$paths = array( $root . '/brightmigrate-pro.php', $root . '/uninstall.php' );

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root . '/src', RecursiveDirectoryIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = $file->getPathname();
			}
		}

		foreach ( $paths as $path ) {
			$contents = file_get_contents( $path );
			$this->assertStringContainsString( "defined( 'ABSPATH' )", $contents, $path );
			$this->assertStringNotContainsString( 'eval(', $contents, $path );
			$this->assertStringNotContainsString( 'unserialize(', $contents, $path );
		}
	}
}
