<?php
/**
 * Diagnostic log.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Core\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Appends one log line and redacts secrets.
 *
 * @covers \BrightIdeas\Migrate\Core\Logger
 */
class LoggerTest extends TestCase {

	/**
	 * One event is one JSON line, and secrets are not written.
	 */
	public function test_appends_one_line_and_redacts_secrets() {
		$directory = sys_get_temp_dir() . '/bmx-log-' . bin2hex( random_bytes( 4 ) );
		mkdir( $directory );

		$reference = Logger::write(
			'error',
			"first line\nsecond",
			array(
				'password' => 'secret-value',
				'nested'   => array( 'token' => 'abc' ),
				'job'      => 7,
			),
			$directory
		);

		$this->assertMatchesRegularExpression( '/^bmx-[a-f0-9]{12}$/', $reference );

		$lines = file( $directory . '/brightmigrate.log', FILE_IGNORE_NEW_LINES );
		$this->assertCount( 1, $lines );

		$decoded = json_decode( $lines[0], true );
		$this->assertSame( $reference, $decoded['ref'] );
		$this->assertSame( 'first line second', $decoded['message'] );
		$this->assertSame( '[redacted]', $decoded['context']['password'] );
		$this->assertSame( '[redacted]', $decoded['context']['nested']['token'] );
		$this->assertSame( 7, $decoded['context']['job'] );
		$this->assertStringNotContainsString( 'secret-value', $lines[0] );

		unlink( $directory . '/brightmigrate.log' );
		rmdir( $directory );
	}
}
