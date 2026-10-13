<?php
/**
 * .wpress header and round trip.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Archive\ArchiveException;
use BrightIdeas\Migrate\Archive\Wpress;
use PHPUnit\Framework\TestCase;

/**
 * Checks the .wpress header and a small write/read round trip.
 *
 * @covers \BrightIdeas\Migrate\Archive\Wpress
 */
class WpressTest extends TestCase {

	/**
	 * Directories created by this test.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	/**
	 * Remove temporary directories.
	 */
	protected function tearDown(): void {
		foreach ( $this->dirs as $dir ) {
			$this->remove_dir( $dir );
		}
		parent::tearDown();
	}

	/**
	 * A header is 4377 bytes and keeps the file name and directory apart.
	 */
	public function test_header_size_and_fields() {
		$bytes  = Wpress::header( 'wp-content/uploads/note.txt', 4, 10 );
		$header = Wpress::parse_header( $bytes );

		$this->assertSame( 4377, strlen( $bytes ) );
		$this->assertSame( 'note.txt', $header['name'] );
		$this->assertSame( 'wp-content/uploads', $header['prefix'] );
		$this->assertSame( 4, $header['size'] );
		$this->assertSame( 10, $header['mtime'] );
		$this->assertSame( '', $header['crc'] );
		$this->assertFalse( $header['eof'] );
	}

	/**
	 * Newer files store an 8-character checksum at the end of the header.
	 */
	public function test_checksum_field_is_read() {
		$block = str_repeat( "\0", 4377 );
		$block = substr_replace( $block, 'readme.txt', 0, 10 );
		$block = substr_replace( $block, '4', 255, 1 );
		$block = substr_replace( $block, '10', 269, 2 );
		$block = substr_replace( $block, 'wp-content', 281, 10 );
		$block = substr_replace( $block, 'abcdef12', 4369, 8 );

		$header = Wpress::parse_header( $block );

		$this->assertSame( 'readme.txt', $header['name'] );
		$this->assertSame( 'wp-content', $header['prefix'] );
		$this->assertSame( 'abcdef12', $header['crc'] );
	}

	/**
	 * A parent-directory path is refused.
	 */
	public function test_parent_directory_is_refused() {
		$this->expectException( ArchiveException::class );
		Wpress::header( '../secret.txt', 1, 1 );
	}

	/**
	 * Two files can be written in short steps and read back.
	 */
	public function test_round_trip_reads_package_and_file() {
		$dir     = $this->make_dir();
		$note    = $dir . '/note.txt';
		$partial = $dir . '/out.wpress.partial';
		$archive = $dir . '/out.wpress';
		file_put_contents( $note, 'hello' );

		Wpress::append_bytes( $partial, 'package.json', '{"SiteURL":"http://example.test"}', 100 );
		$slice = Wpress::append_file( $partial, $note, 'wp-content/uploads/note.txt', 0, false, 0, 0 );
		$this->assertFalse( $slice['done'] );
		$this->assertTrue( $slice['header_done'] );

		$done = Wpress::append_file(
			$partial,
			$note,
			'wp-content/uploads/note.txt',
			$slice['body_written'],
			$slice['header_done'],
			$slice['body_size'],
			microtime( true ) + 5
		);
		$this->assertTrue( $done['done'] );
		Wpress::finish( $partial, $archive );

		$summary = Wpress::summary( $archive );
		$this->assertSame( 'wpress', $summary['format'] );
		$this->assertSame( 'archive', $summary['type'] );
		$this->assertSame( 'http://example.test', $summary['source_url'] );
		$this->assertSame( 2, $summary['contents']['files'] );
		$this->assertSame( 0, $summary['contents']['database_parts'] );
		$this->assertSame( 'hello', $this->entry( $archive, 'wp-content/uploads/note.txt' ) );
	}

	/**
	 * Random bytes are not treated as an archive.
	 */
	public function test_random_bytes_are_rejected() {
		$dir  = $this->make_dir();
		$path = $dir . '/nope.wpress';
		file_put_contents( $path, random_bytes( 80 ) );

		$this->expectException( ArchiveException::class );
		$this->expectExceptionMessage( 'This file is not a .wpress archive.' );
		Wpress::summary( $path );
	}

	/**
	 * Read one small entry back from a finished archive.
	 *
	 * @param string $path         Archive path.
	 * @param string $archive_path Entry path.
	 * @return string
	 */
	private function entry( $path, $archive_path ) {
		$handle = fopen( $path, 'rb' );
		$this->assertNotFalse( $handle );
		try {
			while ( ! feof( $handle ) ) {
				$block = fread( $handle, 4377 );
				if ( ! is_string( $block ) || '' === $block ) {
					break;
				}
				$header = Wpress::parse_header( $block );
				if ( ! empty( $header['eof'] ) ) {
					break;
				}
				$body = '';
				$left = (int) $header['size'];
				while ( $left > 0 ) {
					$chunk = fread( $handle, $left );
					$this->assertIsString( $chunk );
					$body .= $chunk;
					$left -= strlen( $chunk );
				}
				$prefix = (string) $header['prefix'];
				$full   = '' === $prefix ? $header['name'] : $prefix . '/' . $header['name'];
				if ( $archive_path === $full ) {
					return $body;
				}
			}
		} finally {
			fclose( $handle );
		}
		$this->fail( 'Missing archive entry.' );
	}

	/**
	 * Create a temporary directory.
	 *
	 * @return string
	 */
	private function make_dir() {
		$dir = sys_get_temp_dir() . '/bmx-wpress-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * Delete a temporary directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}
}
