<?php
/**
 * Byte formatting.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Format;
use PHPUnit\Framework\TestCase;

/**
 * Formats byte counts, including values above 2 GB.
 *
 * @covers \BrightIdeas\Migrate\Core\Format
 */
class FormatTest extends TestCase {

	/**
	 * Sizes stay readable above 2 GB, including values that do not fit in a 32-bit int.
	 */
	public function test_bytes() {
		$this->assertSame( '0 B', Format::bytes( 0 ) );
		$this->assertSame( '0 B', Format::bytes( -5 ) );
		$this->assertSame( '1 KB', Format::bytes( 1024 ) );
		$this->assertSame( '1.5 KB', Format::bytes( 1536 ) );
		$this->assertSame( '5 GB', Format::bytes( 5 * 1024 * 1024 * 1024 ) );
		$this->assertSame( '5 GB+', Format::bytes( 5368709120, true ) );
	}
}
