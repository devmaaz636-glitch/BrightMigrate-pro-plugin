<?php
/**
 * Requirement checks.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * Checks PHP, WordPress, and extension requirements.
 *
 * @covers \BrightIdeas\Migrate\Core\Requirements
 */
class RequirementsTest extends TestCase {

	/**
	 * A current environment produces no failures.
	 */
	public function test_current_environment_passes() {
		$codes = Requirements::failures( '8.2.12', '7.1.3', array( 'zlib', 'json', 'mysqli', 'openssl' ) );
		$this->assertSame( array(), $codes );
	}

	/**
	 * Old PHP, old WordPress, and a missing extension are all reported.
	 */
	public function test_failures_are_specific() {
		$codes = Requirements::failures( '7.3.0', '6.1.0', array( 'json' ) );
		$this->assertContains( 'php', $codes );
		$this->assertContains( 'wp', $codes );
		$this->assertContains( 'ext:zlib', $codes );
		$this->assertContains( 'ext:mysqli', $codes );
		$this->assertNotContains( 'ext:json', $codes );
	}

	/**
	 * Recommended extensions do not block activation.
	 */
	public function test_recommended_extensions_are_separate() {
		$missing = Requirements::missing_recommended( array( 'zlib', 'json', 'mysqli', 'mbstring' ) );
		$this->assertSame( array( 'zip', 'openssl' ), $missing );
		$this->assertSame( array(), Requirements::failures( '8.1.0', '6.2', array( 'zlib', 'json', 'mysqli' ) ) );
	}

	/**
	 * 32-bit builds are detected from PHP_INT_SIZE.
	 */
	public function test_32_bit_detection() {
		$this->assertTrue( Requirements::is_32_bit( 4 ) );
		$this->assertFalse( Requirements::is_32_bit( 8 ) );
	}
}
