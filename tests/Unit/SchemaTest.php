<?php
/**
 * Schema statements.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Tests\Unit;

use BrightIdeas\Migrate\Database\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Builds allow-listed dbDelta statements.
 *
 * @covers \BrightIdeas\Migrate\Database\Schema
 */
class SchemaTest extends TestCase {

	/**
	 * Statements include the four tables, 64-bit sizes, and dbDelta's primary key spacing.
	 */
	public function test_statements_describe_plugin_tables() {
		$sql = implode( "\n", Schema::statements( 'wp_', 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci' ) );

		$this->assertStringContainsString( 'wp_brightmigrate_jobs', $sql );
		$this->assertStringContainsString( 'wp_brightmigrate_job_log', $sql );
		$this->assertStringContainsString( 'wp_brightmigrate_backups', $sql );
		$this->assertStringContainsString( 'wp_brightmigrate_id_map', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (id)', $sql );
		$this->assertStringContainsString( 'file_size bigint(20) unsigned', $sql );
		$this->assertStringContainsString( 'position longtext NULL', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY object_lookup', $sql );
		$this->assertStringNotContainsString( 'LIMIT', $sql );
	}

	/**
	 * A prefix or charset that could change the SQL is rejected.
	 */
	public function test_statements_reject_unsafe_identifiers() {
		$this->assertSame( array(), Schema::statements( 'wp_; DROP TABLE wp_users; --', '' ) );

		$sql = implode( "\n", Schema::statements( 'wp_', 'utf8mb4; DROP TABLE wp_users' ) );
		$this->assertStringNotContainsString( 'DROP TABLE wp_users', $sql );
		$this->assertStringContainsString( 'wp_brightmigrate_jobs', $sql );
	}

	/**
	 * Table names come from an allow-list.
	 */
	public function test_table_allow_list() {
		$this->assertSame( 'wp_brightmigrate_jobs', Schema::table( 'jobs', 'wp_' ) );
		$this->assertSame( '', Schema::table( 'users', 'wp_' ) );
		$this->assertSame( '', Schema::table( 'jobs', 'wp_`' ) );
	}
}
