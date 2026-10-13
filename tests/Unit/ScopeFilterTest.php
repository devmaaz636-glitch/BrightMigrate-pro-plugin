<?php
/**
 * Scope filters must keep a parent folder so its files can be archived.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Export\SiteExport;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Proves a plugins, theme, or media export can enter the matching folder.
 *
 * @covers \BrightIdeas\Migrate\Export\SiteExport
 */
class ScopeFilterTest extends TestCase {

	/**
	 * Ask the private path filter for a decision.
	 *
	 * @param string $path Archive path.
	 * @param string $only Required prefix.
	 * @return bool
	 */
	private function leaves_out( $path, $only ) {
		$method = new ReflectionMethod( SiteExport::class, 'excluded' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		return $method->invoke(
			null,
			$path,
			array(),
			array(
				'only'    => $only,
				'themes'  => array( 'child', 'parent' ),
				'plugins' => array( 'akismet' ),
				'storage' => '',
			)
		);
	}

	/**
	 * The folder itself is kept, and so are files inside it.
	 */
	public function test_scope_keeps_its_folder_and_files() {
		$this->assertFalse( $this->leaves_out( 'wp-content', 'wp-content/plugins/' ) );
		$this->assertFalse( $this->leaves_out( 'wp-content/plugins', 'wp-content/plugins/' ) );
		$this->assertFalse( $this->leaves_out( 'wp-content/plugins/akismet/akismet.php', 'wp-content/plugins/' ) );
		$this->assertTrue( $this->leaves_out( 'wp-content/themes/other/style.css', 'wp-content/plugins/' ) );
		$this->assertFalse( $this->leaves_out( 'wp-content/uploads', 'wp-content/uploads/' ) );
		$this->assertFalse( $this->leaves_out( 'wp-content/themes', 'wp-content/themes/' ) );
	}
}
