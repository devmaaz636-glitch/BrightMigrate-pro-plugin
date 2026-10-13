<?php
/**
 * Unlimited archive size and per-request chunk planning.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Limits;
use PHPUnit\Framework\TestCase;

/**
 * Proves there is no archive size cap and that one step fits the server.
 *
 * @covers \BrightIdeas\Migrate\Core\Limits
 */
class LimitsTest extends TestCase {

	/**
	 * The archive itself is not limited to any byte count.
	 */
	public function test_archive_has_no_size_cap() {
		$this->assertSame( 0, Limits::archive_cap() );
	}

	/**
	 * Ini suffixes become bytes. Zero and -1 stay unlimited.
	 */
	public function test_ini_sizes() {
		$this->assertSame( 0, Limits::ini_bytes( '0' ) );
		$this->assertSame( 0, Limits::ini_bytes( '-1' ) );
		$this->assertSame( 8388608, Limits::ini_bytes( '8M' ) );
		$this->assertSame( 524288, Limits::ini_bytes( '512K' ) );
		$this->assertSame( 2147483648, Limits::ini_bytes( '2G' ) );
	}

	/**
	 * An automatic step uses 8 MB when the server does not limit the request.
	 */
	public function test_automatic_chunk_on_an_unlimited_server() {
		$this->assertSame( Limits::AUTO_CHUNK, Limits::chunk_bytes( 0, '0', '0' ) );
	}

	/**
	 * A saved setting is used when the server can accept it.
	 */
	public function test_setting_is_used_inside_the_server_limit() {
		$this->assertSame( 4 * 1048576, Limits::chunk_bytes( 4, '64M', '64M' ) );
		$this->assertSame( Limits::MAX_CHUNK, Limits::chunk_bytes( 10, '128M', '128M' ) );
	}

	/**
	 * The step shrinks so a large site can still upload on a small post limit.
	 */
	public function test_chunk_shrinks_to_fit_post_max_size() {
		$bytes = Limits::chunk_bytes( 10, '2M', '8M' );
		$this->assertLessThan( 2 * 1048576, $bytes );
		$this->assertGreaterThan( 0, $bytes );
	}
}
