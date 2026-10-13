<?php
/**
 * Storage path rules.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Storage;
use PHPUnit\Framework\TestCase;

/**
 * Rejects unsafe storage paths and protects deletion.
 *
 * @covers \BrightIdeas\Migrate\Core\Storage
 */
class StoragePathTest extends TestCase {

	/**
	 * Directory context used by the lexical checks.
	 *
	 * @return array
	 */
	private function context() {
		return array(
			'abspath'          => '/var/www/html',
			'content_dir'      => '/var/www/html/wp-content',
			'plugin_dir'       => '/var/www/html/wp-content/plugins',
			'themes_dir'       => '/var/www/html/wp-content/themes',
			'mu_plugins_dir'   => '/var/www/html/wp-content/mu-plugins',
			'uploads_dir'      => '/var/www/html/wp-content/uploads',
			'case_insensitive' => false,
		);
	}

	/**
	 * A private folder under wp-content is accepted.
	 */
	public function test_accepts_content_subdirectory() {
		$result = Storage::lexical_check( '/var/www/html/wp-content/brightmigrate-abc123', $this->context() );
		$this->assertTrue( $result['valid'] );
		$this->assertSame( '/var/www/html/wp-content/brightmigrate-abc123', $result['path'] );
	}

	/**
	 * An absolute path outside the web root is accepted.
	 */
	public function test_accepts_path_outside_abspath() {
		$result = Storage::lexical_check( '/var/backups/client', $this->context() );
		$this->assertTrue( $result['valid'] );
	}

	/**
	 * Dangerous and public locations are rejected.
	 *
	 * @dataProvider rejected_paths
	 *
	 * @param string $path Candidate.
	 * @param string $code Expected code.
	 */
	public function test_rejects_unsafe_paths( $path, $code ) {
		$result = Storage::lexical_check( $path, $this->context() );
		$this->assertFalse( $result['valid'] );
		$this->assertSame( $code, $result['code'] );
	}

	/**
	 * Paths that must never be used for storage.
	 *
	 * @return array
	 */
	public function rejected_paths() {
		return array(
			'traversal'       => array( '/var/www/html/wp-content/brightmigrate/../../wp-config.php', 'traversal' ),
			'null byte'       => array( "/var/www/html/wp-content/brightmigrate\0", 'null_byte' ),
			'wrapper'         => array( 'php://filter/resource=/etc/passwd', 'wrapper' ),
			'relative'        => array( 'wp-content/brightmigrate-abc', 'relative' ),
			'plugins'         => array( '/var/www/html/wp-content/plugins/akismet', 'plugin_dir' ),
			'themes'          => array( '/var/www/html/wp-content/themes/twentytwentyfive', 'themes_dir' ),
			'mu-plugins'      => array( '/var/www/html/wp-content/mu-plugins/load.php', 'mu_plugins_dir' ),
			'uploads'         => array( '/var/www/html/wp-content/uploads/2026/10/secret.bmx', 'uploads_dir' ),
			'abspath'         => array( '/var/www/html', 'abspath' ),
			'content root'    => array( '/var/www/html/wp-content', 'content_root' ),
			'outside content' => array( '/var/www/html/wp-config.php', 'outside_content' ),
			'sibling prefix'  => array( '/var/www/html/wp-content-evil/secret', 'outside_content' ),
			'unc'             => array( '\\\\server\\share\\backup', 'unc' ),
			'empty'           => array( '   ', 'empty' ),
			'illegal char'    => array( '/var/backups/site*.bmx', 'illegal_char' ),
		);
	}

	/**
	 * Windows drive roots are roots. A folder on another drive is not.
	 */
	public function test_filesystem_roots() {
		$this->assertTrue( Storage::is_filesystem_root( '/' ) );
		$this->assertTrue( Storage::is_filesystem_root( 'C:\\' ) );
		$this->assertTrue( Storage::is_filesystem_root( 'C:/' ) );
		$this->assertFalse( Storage::is_filesystem_root( 'C:/backups' ) );
		$this->assertFalse( Storage::is_filesystem_root( '/var/backups' ) );
	}

	/**
	 * Guard files deny web access.
	 */
	public function test_guard_files_deny_http() {
		$files = Storage::guard_files();
		$this->assertStringContainsString( "defined( 'ABSPATH' )", $files['index.php'] );
		$this->assertStringContainsString( 'Require all denied', $files['.htaccess'] );
		$this->assertStringContainsString( 'Deny from all', $files['.htaccess'] );
		$this->assertStringContainsString( 'deny users="*"', $files['web.config'] );
	}

	/**
	 * Removal refuses roots and does not follow a symlink.
	 */
	public function test_remove_directory_refuses_roots_and_skips_link_targets() {
		$this->assertFalse( Storage::remove_storage_directory( '/' ) );
		$this->assertFalse( Storage::remove_storage_directory( 'C:/' ) );

		$root   = sys_get_temp_dir() . '/bmx-storage-' . bin2hex( random_bytes( 4 ) );
		$target = sys_get_temp_dir() . '/bmx-target-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root );
		mkdir( $target );
		file_put_contents( $root . '/note.txt', 'archive' );
		file_put_contents( $target . '/keep.txt', 'keep' );

		$linked = false;
		$link   = $root . '/linked';
		if ( function_exists( 'symlink' ) ) {
			set_error_handler(
				static function () {
					return true;
				}
			);
			$linked = symlink( $target, $link );
			restore_error_handler();
		}

		$this->assertTrue( Storage::remove_storage_directory( $root ) );
		$this->assertDirectoryDoesNotExist( $root );
		$this->assertFileExists( $target . '/keep.txt' );

		if ( is_dir( $target ) ) {
			unlink( $target . '/keep.txt' );
			rmdir( $target );
		}

		if ( $linked && file_exists( $link ) ) {
			$this->fail( 'Symlink should have been removed with the storage directory.' );
		}
	}

	/**
	 * Usage measurement sums file sizes and can stop early.
	 */
	public function test_bounded_usage() {
		$dir = sys_get_temp_dir() . '/bmx-usage-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		file_put_contents( $dir . '/a.txt', '12345' );
		file_put_contents( $dir . '/b.txt', '123' );

		$usage = Storage::bounded_usage( $dir, 10 );
		$this->assertSame( 8.0, $usage['bytes'] );
		$this->assertFalse( $usage['truncated'] );

		$stopped = Storage::bounded_usage( $dir, 1 );
		$this->assertTrue( $stopped['truncated'] );

		unlink( $dir . '/a.txt' );
		unlink( $dir . '/b.txt' );
		rmdir( $dir );
	}
}
