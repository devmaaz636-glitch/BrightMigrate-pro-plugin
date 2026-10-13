<?php
/**
 * Settings sanitizer.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Allow-lists and clamps stored settings.
 *
 * @covers \BrightIdeas\Migrate\Core\Settings
 */
class SettingsSanitizerTest extends TestCase {

	/**
	 * Accept any absolute path the test asks for.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	public function accept_path( $path ) {
		return array(
			'valid' => true,
			'path'  => str_replace( '\\', '/', $path ),
			'code'  => 'ok',
		);
	}

	/**
	 * Reject every path.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	public function reject_path( $path ) {
		unset( $path );
		return array(
			'valid' => false,
			'path'  => '',
			'code'  => 'plugin_dir',
		);
	}

	/**
	 * Numbers are clamped, email is normalized, and unknown keys are dropped.
	 */
	public function test_allow_list_and_clamps() {
		$result = Settings::sanitize_settings(
			array(
				'storage_location' => '',
				'upload_chunk_mb'  => 50,
				'retention_count'  => 999999,
				'notify_email'     => 'Ops@Example.com',
				'delete_archives'  => '1',
				'evil'             => 'nope',
			),
			array(),
			array( $this, 'accept_path' )
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 10, $result['settings']['upload_chunk_mb'] );
		$this->assertSame( 10000, $result['settings']['retention_count'] );
		$this->assertSame( 'ops@example.com', $result['settings']['notify_email'] );
		$this->assertSame( '1', $result['settings']['delete_archives'] );
		$this->assertArrayNotHasKey( 'evil', $result['settings'] );
	}

	/**
	 * Zero means automatic chunk size. One is raised to the 2 MB minimum.
	 */
	public function test_chunk_bounds() {
		$auto = Settings::sanitize_settings(
			array( 'upload_chunk_mb' => 0 ),
			array(),
			array( $this, 'accept_path' )
		);
		$low  = Settings::sanitize_settings(
			array( 'upload_chunk_mb' => 1 ),
			array(),
			array( $this, 'accept_path' )
		);

		$this->assertSame( 0, $auto['settings']['upload_chunk_mb'] );
		$this->assertSame( 2, $low['settings']['upload_chunk_mb'] );
	}

	/**
	 * A bad email or path keeps the previous value and records an error.
	 */
	public function test_invalid_values_keep_previous() {
		$previous = array(
			'notify_email'     => 'keep@example.com',
			'storage_location' => '/var/backups/old',
		);

		$email = Settings::sanitize_settings(
			array( 'notify_email' => "bad@example.com\nBcc: x" ),
			$previous,
			array( $this, 'accept_path' )
		);
		$path  = Settings::sanitize_settings(
			array( 'storage_location' => '/var/www/html/wp-content/plugins/evil' ),
			$previous,
			array( $this, 'reject_path' )
		);

		$this->assertContains( 'email_invalid', $email['errors'] );
		$this->assertSame( 'keep@example.com', $email['settings']['notify_email'] );
		$this->assertContains( 'storage_rejected', $path['errors'] );
		$this->assertSame( '/var/backups/old', $path['settings']['storage_location'] );
	}

	/**
	 * A non-array submission does not wipe stored settings.
	 */
	public function test_non_array_keeps_previous() {
		$result = Settings::sanitize_settings(
			null,
			array( 'retention_count' => 4 ),
			array( $this, 'accept_path' )
		);

		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 4, $result['settings']['retention_count'] );
	}
}
