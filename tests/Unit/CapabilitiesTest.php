<?php
/**
 * Capability mapping.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Capabilities;
use PHPUnit\Framework\TestCase;

/**
 * Maps administrator capabilities without loading WordPress.
 *
 * @covers \BrightIdeas\Migrate\Core\Capabilities
 */
class CapabilitiesTest extends TestCase {

	/**
	 * Network admin is the only context that uses the network capability.
	 */
	public function test_required_capability() {
		$this->assertSame( 'manage_network_options', Capabilities::required( true, true ) );
		$this->assertSame( 'manage_options', Capabilities::required( true, false ) );
		$this->assertSame( 'manage_options', Capabilities::required( false, false ) );
		$this->assertSame( 'manage_options', Capabilities::required( false, true ) );
	}
}
