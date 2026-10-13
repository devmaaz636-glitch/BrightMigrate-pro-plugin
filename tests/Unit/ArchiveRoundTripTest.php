<?php
/**
 * Archive write and read round trip.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Archive\ArchiveException;
use BrightIdeas\Migrate\Archive\Crypto;
use BrightIdeas\Migrate\Archive\Format;
use BrightIdeas\Migrate\Archive\Reader;
use BrightIdeas\Migrate\Archive\Writer;
use PHPUnit\Framework\TestCase;

/**
 * Builds .bmx parts and reads them back without loading a whole archive.
 *
 * @covers \BrightIdeas\Migrate\Archive\Writer
 * @covers \BrightIdeas\Migrate\Archive\Reader
 */
class ArchiveRoundTripTest extends TestCase {

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
	 * An empty archive still verifies.
	 */
	public function test_empty_archive_verifies() {
		$dir    = $this->temp_dir();
		$path   = $dir . '/empty.bmx';
		$writer = new Writer(
			$path,
			array(
				'type'         => 'full',
				'source_url'   => 'https://example.test',
				'table_prefix' => 'wp_',
			)
		);
		$paths  = $writer->finish();
		$this->assertSame( array( $path ), $paths );

		$reader  = new Reader( $path );
		$summary = $reader->summary();
		$this->assertSame( 'bmx', $summary['format'] );
		$this->assertSame( 'full', $summary['type'] );
		$this->assertSame( 0, $summary['contents']['files'] );
		$this->assertArrayNotHasKey( 'entries', $summary );
		$this->assertSame( 20, strlen( $summary['archive_size'] ) );
		$this->assertTrue( $reader->verify() );
		mkdir( $dir . '/out' );
		$reader->extract( $dir . '/out' );
		$reader->close();
	}

	/**
	 * Files and a database dump survive a write, verify, and extract.
	 */
	public function test_round_trip_extracts_the_same_bytes() {
		$dir = $this->temp_dir();
		$this->put( $dir . '/theme.css', 'body{color:rebeccapurple}' );
		$this->put( $dir . '/dump.sql', "INSERT INTO t VALUES ('BMX_SECRET_ROW_plain');\n" );
		$this->put( $dir . '/empty.txt', '' );

		$archive = $dir . '/site.bmx';
		$writer  = new Writer( $archive, array( 'type' => 'themekit' ) );
		$writer->add_file( $dir . '/theme.css', 'assets/theme.css' );
		$writer->add_database_file( $dir . '/dump.sql', 'database/site.sql' );
		$writer->add_file( $dir . '/empty.txt', 'empty.txt' );
		$this->assertSame( array( $archive ), $writer->finish() );

		$reader = new Reader( $archive );
		$seen   = array();
		$reader->each(
			static function ( $entry ) use ( &$seen ) {
				$seen[] = $entry['path'];
				if ( 'database/site.sql' === $entry['path'] ) {
					self::assertSame( 'database', $entry['kind'] );
					self::assertFalse( $entry['encrypted'] );
				}
			}
		);
		$this->assertSame( array( 'assets/theme.css', 'database/site.sql', 'empty.txt' ), $seen );
		$this->assertTrue( $reader->verify() );

		$out = $dir . '/extracted';
		mkdir( $out );
		$reader->extract( $out );
		$reader->close();
		$this->assertSame( 'body{color:rebeccapurple}', $this->read_small( $out . '/assets/theme.css' ) );
		$this->assertSame( "INSERT INTO t VALUES ('BMX_SECRET_ROW_plain');\n", $this->read_small( $out . '/database/site.sql' ) );
		$this->assertSame( '', $this->read_small( $out . '/empty.txt' ) );
		$this->assertTrue( $this->contains( $archive, 'BMX_SECRET_ROW_plain' ) );
	}

	/**
	 * A file larger than the 512 KB buffer is copied in chunks.
	 */
	public function test_file_larger_than_the_buffer_round_trips() {
		$dir = $this->temp_dir();
		$src = $dir . '/wide.bin';
		$this->put_repeat( $src, 'abc', 200000 );
		$archive = $dir . '/wide.bmx';
		$writer  = new Writer( $archive );
		$writer->add_file( $src, 'wide.bin' );
		$writer->finish();

		$reader = new Reader( $archive );
		$this->assertTrue( $reader->verify() );
		$out = $dir . '/out';
		mkdir( $out );
		$reader->extract( $out );
		$reader->close();
		$this->assertSame( $this->hash_file( $src ), $this->hash_file( $out . '/wide.bin' ) );
	}

	/**
	 * A password encrypts the manifest and database, and leaves files readable.
	 */
	public function test_password_hides_the_database_and_not_files() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM is not available.' );
		}
		$dir      = $this->temp_dir();
		$password = 'correct-horse-battery-staple-9f3a';
		$secret   = 'BMX_SECRET_ROW_9f3a';
		$this->put( $dir . '/theme.css', 'body{color:rebeccapurple}' );
		$this->put( $dir . '/dump.sql', "INSERT INTO t VALUES ('" . $secret . "');\n" );

		$archive = $dir . '/locked.bmx';
		$writer  = new Writer(
			$archive,
			array(
				'password' => $password,
				'type'     => 'full',
			)
		);
		$writer->add_file( $dir . '/theme.css', 'assets/theme.css' );
		$writer->add_database_file( $dir . '/dump.sql', 'database/site.sql' );
		$writer->finish();

		$this->assertTrue( $this->contains( $archive, 'body{color:rebeccapurple}' ) );
		$this->assertFalse( $this->contains( $archive, $password ) );
		$this->assertFalse( $this->contains( $archive, $secret ) );

		$reader  = new Reader( $archive, $password );
		$summary = $reader->summary();
		$this->assertTrue( $summary['encrypted']['manifest'] );
		$this->assertTrue( $summary['encrypted']['database'] );
		$this->assertFalse( $summary['encrypted']['files'] );
		$reader->each(
			static function ( $entry ) {
				if ( 'database/site.sql' === $entry['path'] ) {
					self::assertTrue( $entry['encrypted'] );
				}
				if ( 'assets/theme.css' === $entry['path'] ) {
					self::assertFalse( $entry['encrypted'] );
				}
			}
		);
		$this->assertTrue( $reader->verify() );
		$out = $dir . '/out';
		mkdir( $out );
		$reader->extract( $out );
		$reader->close();
		$this->assertSame( "INSERT INTO t VALUES ('" . $secret . "');\n", $this->read_small( $out . '/database/site.sql' ) );
	}

	/**
	 * The wrong password does not reveal why decryption failed.
	 */
	public function test_wrong_password_is_rejected() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM is not available.' );
		}
		$dir = $this->temp_dir();
		$this->put( $dir . '/dump.sql', 'SELECT 1;' );
		$archive = $dir . '/locked.bmx';
		$writer  = new Writer( $archive, array( 'password' => 'right-password-value' ) );
		$writer->add_database_file( $dir . '/dump.sql', 'database/site.sql' );
		$writer->finish();

		try {
			new Reader( $archive, 'wrong-password-value' );
			$this->fail( 'A wrong password opened the archive.' );
		} catch ( ArchiveException $exception ) {
			$this->assertStringNotContainsString( 'wrong-password-value', $exception->getMessage() );
			$this->assertStringNotContainsString( 'right-password-value', $exception->getMessage() );
		}
	}

	/**
	 * Optional file encryption hides file bytes as well as the database.
	 */
	public function test_encrypt_files_hides_file_bytes() {
		if ( ! Crypto::available() ) {
			$this->markTestSkipped( 'OpenSSL AES-256-GCM is not available.' );
		}
		$dir = $this->temp_dir();
		$this->put( $dir . '/theme.css', 'body{color:rebeccapurple-encrypted}' );
		$archive = $dir . '/files.bmx';
		$writer  = new Writer(
			$archive,
			array(
				'password'      => 'file-key-staple-9f3a',
				'encrypt_files' => true,
			)
		);
		$writer->add_file( $dir . '/theme.css', 'assets/theme.css' );
		$writer->finish();
		$this->assertFalse( $this->contains( $archive, 'body{color:rebeccapurple-encrypted}' ) );

		$reader = new Reader( $archive, 'file-key-staple-9f3a' );
		$this->assertTrue( $reader->summary()['encrypted']['files'] );
		$this->assertTrue( $reader->verify() );
		$out = $dir . '/out';
		mkdir( $out );
		$reader->extract( $out );
		$reader->close();
		$this->assertSame( 'body{color:rebeccapurple-encrypted}', $this->read_small( $out . '/assets/theme.css' ) );
	}

	/**
	 * Changing one payload byte fails verification.
	 */
	public function test_tampered_byte_fails_verification() {
		$dir = $this->temp_dir();
		$this->put( $dir . '/note.txt', 'hello archive' );
		$archive = $dir . '/note.bmx';
		$writer  = new Writer( $archive );
		$writer->add_file( $dir . '/note.txt', 'note.txt' );
		$writer->finish();

		$handle = fopen( $archive, 'r+b' );
		$stat   = fstat( $handle );
		$at     = (int) $stat['size'] - Format::TRAILER_BYTES - 1;
		fseek( $handle, $at, SEEK_SET );
		$byte = fread( $handle, 1 );
		fseek( $handle, $at, SEEK_SET );
		fwrite( $handle, chr( ord( $byte ) ^ 0xff ) );
		fclose( $handle );

		$reader = new Reader( $archive );
		$this->expectException( ArchiveException::class );
		$reader->verify();
	}

	/**
	 * Parts split between entries and keep an entry that is larger than the limit.
	 */
	public function test_split_parts_round_trip() {
		$dir   = $this->temp_dir();
		$files = array( 'one.txt', 'two.txt', 'three.txt' );
		foreach ( $files as $name ) {
			$this->put( $dir . '/' . $name, str_repeat( 'Z', 200 ) );
		}
		$writer = new Writer(
			$dir . '/bundle.bmx',
			array(
				'split_bytes' => 80,
			)
		);
		foreach ( $files as $name ) {
			$writer->add_file( $dir . '/' . $name, $name );
		}
		$paths = $writer->finish();
		$this->assertCount( 3, $paths );
		$this->assertFileDoesNotExist( $dir . '/bundle.bmx' );
		$this->assertSame( $dir . '/bundle.001.bmx', $paths[0] );
		$this->assertSame( $dir . '/bundle.003.bmx', $paths[2] );

		foreach ( $paths as $index => $path ) {
			$reader  = new Reader( $path );
			$summary = $reader->summary();
			$this->assertSame( $index + 1, $summary['part']['index'] );
			$this->assertSame( 2 === $index, $summary['part']['final'] );
			$header = $this->header( $path );
			$this->assertSame( Format::FLAG_SPLIT, $header['flags'] & Format::FLAG_SPLIT );
			$this->assertTrue( $reader->verify() );
			$out = $dir . '/part-' . $index;
			mkdir( $out );
			$reader->extract( $out );
			$reader->close();
			$this->assertSame( str_repeat( 'Z', 200 ), $this->read_small( $out . '/' . $files[ $index ] ) );
		}
	}

	/**
	 * Unsafe paths, symlinks, and duplicate paths are rejected.
	 */
	public function test_rejects_unsafe_sources() {
		$dir = $this->temp_dir();
		$this->put( $dir . '/ok.txt', 'ok' );
		$writer = new Writer( $dir . '/unsafe.bmx', array( 'type' => 'content' ) );
		try {
			$writer->add_file( $dir . '/ok.txt', '../ok.txt' );
			$this->fail( 'A parent path was accepted.' );
		} catch ( ArchiveException $exception ) {
			$this->assertStringNotContainsString( 'password', $exception->getMessage() );
		}

		$writer = new Writer( $dir . '/dup.bmx' );
		$writer->add_file( $dir . '/ok.txt', 'ok.txt' );
		try {
			$writer->add_file( $dir . '/ok.txt', 'ok.txt' );
			$this->fail( 'A duplicate path was accepted.' );
		} catch ( ArchiveException $exception ) {
			$this->addToAssertionCount( 1 );
		}

		$link = $dir . '/link.txt';
		if ( $this->try_symlink( $dir . '/ok.txt', $link ) ) {
			$writer = new Writer( $dir . '/link.bmx' );
			$this->expectException( ArchiveException::class );
			$writer->add_file( $link, 'link.txt' );
		}
	}

	/**
	 * Content packs cannot carry PHP outside themes and plugins.
	 */
	public function test_content_pack_blocks_stray_php() {
		$dir = $this->temp_dir();
		$this->put( $dir . '/shell.php', "<?php echo 'no';\n" );
		$blocked = new Writer( $dir . '/blocked.bmx', array( 'type' => 'content' ) );
		try {
			$blocked->add_file( $dir . '/shell.php', 'wp-content/uploads/shell.php' );
			$this->fail( 'An executable was packed outside themes and plugins.' );
		} catch ( ArchiveException $exception ) {
			$this->addToAssertionCount( 1 );
		}

		$allowed = new Writer( $dir . '/allowed.bmx', array( 'type' => 'content' ) );
		$allowed->add_file( $dir . '/shell.php', 'wp-content/themes/demo/functions.php' );
		$allowed->finish();
		$reader = new Reader( $dir . '/allowed.bmx' );
		$this->assertTrue( $reader->verify() );
		$reader->close();
	}

	/**
	 * Closing a writer without finish() leaves no archive behind.
	 */
	public function test_unfinished_writer_removes_its_work_files() {
		$dir = $this->temp_dir();
		$this->put( $dir . '/ok.txt', 'ok' );
		$dest   = $dir . '/partial.bmx';
		$writer = new Writer( $dest );
		$writer->add_file( $dir . '/ok.txt', 'ok.txt' );
		unset( $writer );
		$this->assertFileDoesNotExist( $dest );
		$leftovers = glob( $dir . '/*.work-*' );
		$this->assertSame( array(), is_array( $leftovers ) ? $leftovers : array() );
	}

	/**
	 * Read the 256-byte header.
	 *
	 * @param string $path Archive path.
	 * @return array
	 */
	private function header( $path ) {
		$handle = fopen( $path, 'rb' );
		$bytes  = fread( $handle, Format::HEADER_BYTES );
		fclose( $handle );
		return Format::unpack_header( $bytes );
	}

	/**
	 * Create and remember a temporary directory.
	 *
	 * @return string
	 */
	private function temp_dir() {
		$dir = sys_get_temp_dir() . '/bmx-round-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		mkdir( $dir . '/out-placeholder' );
		rmdir( $dir . '/out-placeholder' );
		$this->dirs[] = $dir;
		return $dir;
	}

	/**
	 * Write a small fixture.
	 *
	 * @param string $path    Destination.
	 * @param string $content Bytes.
	 * @return void
	 */
	private function put( $path, $content ) {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		$handle = fopen( $path, 'wb' );
		if ( '' !== $content ) {
			fwrite( $handle, $content );
		}
		fclose( $handle );
	}

	/**
	 * Write a repeated fixture without one giant fwrite.
	 *
	 * @param string $path  Destination.
	 * @param string $unit  Text to repeat.
	 * @param int    $times Repeat count.
	 * @return void
	 */
	private function put_repeat( $path, $unit, $times ) {
		$handle = fopen( $path, 'wb' );
		$chunk  = str_repeat( $unit, 1000 );
		$left   = $times;
		while ( $left > 0 ) {
			$units = $left > 1000 ? 1000 : $left;
			fwrite( $handle, substr( $chunk, 0, strlen( $unit ) * $units ) );
			$left -= $units;
		}
		fclose( $handle );
	}

	/**
	 * Read a small extracted file.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function read_small( $path ) {
		$handle = fopen( $path, 'rb' );
		$data   = '';
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 8192 );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			$data .= $chunk;
		}
		fclose( $handle );
		return $data;
	}

	/**
	 * Stream a SHA-256 of a file.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function hash_file( $path ) {
		$hash   = hash_init( 'sha256' );
		$handle = fopen( $path, 'rb' );
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, Format::BUFFER_BYTES );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			hash_update( $hash, $chunk );
		}
		fclose( $handle );
		return hash_final( $hash );
	}

	/**
	 * Search an archive in overlapping buffers.
	 *
	 * @param string $path   Archive path.
	 * @param string $needle Text that must not require loading the file.
	 * @return bool
	 */
	private function contains( $path, $needle ) {
		$handle = fopen( $path, 'rb' );
		$carry  = '';
		$found  = false;
		$keep   = strlen( $needle ) - 1;
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, Format::BUFFER_BYTES );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			$window = $carry . $chunk;
			if ( false !== strpos( $window, $needle ) ) {
				$found = true;
				break;
			}
			$carry = $keep > 0 ? substr( $window, -$keep ) : '';
		}
		fclose( $handle );
		return $found;
	}

	/**
	 * Create a symlink without a privilege warning.
	 *
	 * @param string $target Existing file.
	 * @param string $link   Link path.
	 * @return bool
	 */
	private function try_symlink( $target, $link ) {
		set_error_handler(
			static function () {
				return true;
			}
		);
		$linked = symlink( $target, $link );
		restore_error_handler();
		return (bool) $linked;
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
		$handle = opendir( $dir );
		if ( false === $handle ) {
			return;
		}
		$name = readdir( $handle );
		while ( false !== $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $dir . '/' . $name;
				if ( is_link( $path ) || is_file( $path ) ) {
					unlink( $path );
				} elseif ( is_dir( $path ) ) {
					$this->remove_dir( $path );
				}
			}
			$name = readdir( $handle );
		}
		closedir( $handle );
		rmdir( $dir );
	}
}
