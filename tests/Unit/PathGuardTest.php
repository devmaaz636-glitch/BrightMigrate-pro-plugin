<?php
/**
 * Archive path rules.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Archive\ArchiveException;
use BrightIdeas\Migrate\Archive\PathGuard;
use PHPUnit\Framework\TestCase;

/**
 * Rejects paths that would escape an archive or write PHP in the wrong place.
 *
 * @covers \BrightIdeas\Migrate\Archive\PathGuard
 */
class PathGuardTest extends TestCase {

	/**
	 * Relative paths are normalised to forward slashes.
	 */
	public function test_accepts_a_relative_path() {
		$this->assertSame( 'wp-content/uploads/a.jpg', PathGuard::archive_path( 'wp-content\\uploads\\a.jpg' ) );
	}

	/**
	 * Paths that leave the archive are rejected.
	 *
	 * @dataProvider unsafe_paths
	 *
	 * @param string $path Candidate path.
	 */
	public function test_rejects_unsafe_paths( $path ) {
		$this->expectException( ArchiveException::class );
		PathGuard::archive_path( $path );
	}

	/**
	 * Paths that must not enter an archive.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function unsafe_paths() {
		return array(
			'empty'      => array( '' ),
			'parent'     => array( '../secret.txt' ),
			'dot-inside' => array( 'dir/../../secret.txt' ),
			'absolute'   => array( '/etc/passwd' ),
			'windows'    => array( 'C:/Windows/a.txt' ),
			'wrapper'    => array( 'php://filter/convert.base64-encode/resource=x' ),
			'null'       => array( "dir/a.php\0.txt" ),
		);
	}

	/**
	 * A content pack may contain PHP only inside themes and plugins.
	 */
	public function test_content_policy_limits_executables() {
		$this->assertTrue( PathGuard::allows_executable( 'wp-content/themes/demo/functions.php', 'content' ) );
		$this->assertTrue( PathGuard::allows_executable( 'wp-content/plugins/demo/demo.php', 'content' ) );
		$this->assertTrue( PathGuard::allows_executable( 'wp-content/mu-plugins/demo.php', 'content' ) );
		$this->assertTrue( PathGuard::allows_executable( 'wp-content/uploads/photo.jpg', 'content' ) );
		$this->assertFalse( PathGuard::allows_executable( 'wp-content/uploads/shell.php', 'content' ) );
		$this->assertFalse( PathGuard::allows_executable( 'wp-content/uploads/shell.PHTML', 'content' ) );
		$this->assertTrue( PathGuard::allows_executable( 'wp-content/uploads/shell.php', 'full' ) );
	}

	/**
	 * Extraction stays inside the destination and refuses symlinks.
	 */
	public function test_destination_stays_inside_the_root() {
		$root = $this->temp_dir();
		try {
			$dest = PathGuard::destination( 'nested/file.txt', $root );
			$this->assertSame( $this->normal( realpath( $root ) ) . '/nested/file.txt', $this->normal( $dest ) );

			$linked = $this->try_symlink( $root, $root . '/link' );
			if ( ! $linked ) {
				$this->markTestSkipped( 'Symlinks are not available.' );
			}
			$this->expectException( ArchiveException::class );
			PathGuard::destination( 'link/escape.txt', $root );
		} finally {
			$this->remove_dir( $root );
		}
	}

	/**
	 * Create a temporary directory.
	 *
	 * @return string
	 */
	private function temp_dir() {
		$dir = sys_get_temp_dir() . '/bmx-path-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		return $dir;
	}

	/**
	 * Compare paths with forward slashes.
	 *
	 * @param string $path Filesystem path.
	 * @return string
	 */
	private function normal( $path ) {
		$normal = str_replace( '\\', '/', $path );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$normal = strtolower( $normal );
		}
		return rtrim( $normal, '/' );
	}

	/**
	 * Create a symlink without turning a missing privilege into a warning.
	 *
	 * @param string $target Existing target.
	 * @param string $link   New link path.
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
