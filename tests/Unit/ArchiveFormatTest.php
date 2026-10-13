<?php
/**
 * Binary archive constants.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Archive\ArchiveException;
use BrightIdeas\Migrate\Archive\Crypto;
use BrightIdeas\Migrate\Archive\Format;
use PHPUnit\Framework\TestCase;

/**
 * Checks header sizes, checksum width, and the 32-bit offset rule.
 *
 * @covers \BrightIdeas\Migrate\Archive\Format
 * @covers \BrightIdeas\Migrate\Archive\Crypto
 */
class ArchiveFormatTest extends TestCase {

	/**
	 * Magic, header, trailer, and entry prefix stay at the documented sizes.
	 */
	public function test_header_sizes() {
		$this->assertSame( 8, strlen( Format::MAGIC ) );
		$this->assertSame( "BMXARCH\x01", Format::MAGIC );
		$this->assertSame( 7, strlen( Format::TRAILER_MAGIC ) );
		$this->assertSame( "BMXEND\x01", Format::TRAILER_MAGIC );
		$this->assertSame( 256, Format::HEADER_BYTES );
		$this->assertSame( 64, Format::TRAILER_BYTES );
		$this->assertSame( 62, Format::ENTRY_PREFIX_BYTES );
		$this->assertSame( 524288, Format::BUFFER_BYTES );

		$crc    = hash( 'crc32b', 'bmx', true );
		$sha    = hash( 'sha256', 'bmx', true );
		$header = Format::pack_header(
			array(
				'flags'           => 0,
				'created'         => 0,
				'part_index'      => 1,
				'final'           => true,
				'iterations'      => 0,
				'salt'            => '',
				'manifest_offset' => 256,
				'manifest_stored' => 10,
				'manifest_plain'  => 10,
				'entries_offset'  => 266,
				'trailer_offset'  => 266,
				'payload_length'  => 0,
			)
		);
		$this->assertSame( 256, strlen( $header ) );
		$decoded = Format::unpack_header( $header );
		$this->assertSame( 1, $decoded['part_index'] );
		$this->assertTrue( $decoded['final'] );
		$this->assertSame( 266, $decoded['entries_offset'] );

		$trailer = Format::pack_trailer( 330, $crc, $sha, 1, true );
		$this->assertSame( 64, strlen( $trailer ) );
		$this->assertSame( Format::TRAILER_MAGIC, substr( $trailer, 0, 7 ) );
		$this->assertSame( "\0", substr( $trailer, 7, 1 ) );
		$this->assertSame( bin2hex( $sha ), Format::unpack_trailer( $trailer )['sha256'] );

		$entry = Format::pack_entry_header( 'a.txt', Format::ENTRY_FILE, 3, 3, $crc, $sha );
		$this->assertSame( 67, strlen( $entry ) );
		$unpacked = Format::unpack_entry_header( $entry );
		$this->assertSame( 'a.txt', $unpacked['path'] );
		$this->assertSame( 'file', Format::entry_kind( $unpacked['flags'] ) );
		$this->assertFalse( Format::entry_is_encrypted( $unpacked['flags'] ) );
	}

	/**
	 * A file that does not start with the magic is rejected.
	 */
	public function test_bad_magic_is_rejected() {
		$this->expectException( ArchiveException::class );
		Format::unpack_header( str_repeat( "\0", 256 ) );
	}

	/**
	 * Sizes in the manifest use a fixed 20-digit field.
	 */
	public function test_fixed_width_sizes() {
		$this->assertSame( '00000000000000000042', Format::digits( 42 ) );
		$this->assertSame( 42, Format::parse_digits( '00000000000000000042' ) );
		$this->assertSame( 0, Format::parse_digits( '00000000000000000000' ) );
	}

	/**
	 * A negative offset cannot be stored.
	 */
	public function test_negative_offset_is_rejected() {
		$this->expectException( ArchiveException::class );
		Format::assert_offset( -1 );
	}

	/**
	 * 32-bit PHP must refuse offsets above 2 GB. 64-bit PHP may record them.
	 */
	public function test_thirty_two_bit_policy() {
		$this->assertSame( 2147483647, Format::MAX_32BIT_OFFSET );
		$this->assertSame( 4 === PHP_INT_SIZE, Format::is_32_bit() );
		if ( Format::is_32_bit() ) {
			$this->expectException( ArchiveException::class );
			Format::parse_digits( '2147483648' );
			return;
		}
		$this->assertSame( 2147483648, Format::parse_digits( '00000000002147483648' ) );
		Format::assert_offset( 2147483648 );
	}

	/**
	 * Ciphertext framing is 36 bytes per 512 KB chunk.
	 */
	public function test_ciphertext_framing() {
		$this->assertSame( 0, Crypto::stored_length( 0 ) );
		$this->assertSame( 37, Crypto::stored_length( 1 ) );
		$this->assertSame( Format::BUFFER_BYTES + 36, Crypto::stored_length( Format::BUFFER_BYTES ) );
		$this->assertSame( Format::BUFFER_BYTES + 1 + 72, Crypto::stored_length( Format::BUFFER_BYTES + 1 ) );
	}

	/**
	 * The key is 32 bytes and weak iteration counts are rejected.
	 */
	public function test_key_derivation() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM is not available.' );
		}
		$key = Crypto::key( 'correct-horse', random_bytes( 16 ), Format::KDF_ITERATIONS );
		$this->assertSame( 32, strlen( $key ) );
	}

	/**
	 * Iteration counts below the archive minimum are rejected.
	 */
	public function test_weak_iterations_are_rejected() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM is not available.' );
		}
		$this->expectException( ArchiveException::class );
		Crypto::key( 'correct-horse', random_bytes( 16 ), 1000 );
	}
}
