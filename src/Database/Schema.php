<?php
/**
 * Custom table definitions.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema for jobs, logs, the backup index, and ID maps.
 *
 * @since 1.0.0
 */
class Schema {

	/**
	 * Table suffixes after the brightmigrate_ prefix.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SUFFIXES = array( 'jobs', 'job_log', 'backups', 'id_map' );

	/**
	 * Build dbDelta statements.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix           WordPress table prefix. Letters, numbers, and underscores only.
	 * @param string $charset_collate Charset clause from $wpdb->get_charset_collate().
	 * @return string[]
	 */
	public static function statements( $prefix, $charset_collate ) {
		if ( ! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]*$/', $prefix ) ) {
			return array();
		}

		$charset = '';
		if ( is_string( $charset_collate ) && preg_match( '/^[A-Za-z0-9_ ]*$/', $charset_collate ) ) {
			$charset = $charset_collate;
		}

		$jobs    = $prefix . 'brightmigrate_jobs';
		$logs    = $prefix . 'brightmigrate_job_log';
		$backups = $prefix . 'brightmigrate_backups';
		$maps    = $prefix . 'brightmigrate_id_map';

		return array(
			"CREATE TABLE {$jobs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	type varchar(32) NOT NULL DEFAULT '',
	status varchar(20) NOT NULL DEFAULT 'pending',
	stage varchar(64) NOT NULL DEFAULT '',
	position longtext NULL,
	progress decimal(5,2) NOT NULL DEFAULT 0.00,
	options longtext NULL,
	error text NULL,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status (status),
	KEY type (type)
) {$charset};",
			"CREATE TABLE {$logs} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	job_id bigint(20) unsigned NOT NULL DEFAULT 0,
	level varchar(16) NOT NULL DEFAULT 'info',
	message text NULL,
	context longtext NULL,
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY job_id (job_id)
) {$charset};",
			"CREATE TABLE {$backups} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	file_name varchar(255) NOT NULL DEFAULT '',
	archive_type varchar(32) NOT NULL DEFAULT '',
	file_size bigint(20) unsigned NOT NULL DEFAULT 0,
	source_url varchar(2048) NOT NULL DEFAULT '',
	relative_path varchar(1024) NOT NULL DEFAULT '',
	created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY archive_type (archive_type)
) {$charset};",
			"CREATE TABLE {$maps} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	job_id bigint(20) unsigned NOT NULL DEFAULT 0,
	object_type varchar(32) NOT NULL DEFAULT '',
	old_id bigint(20) unsigned NOT NULL DEFAULT 0,
	new_id bigint(20) unsigned NOT NULL DEFAULT 0,
	PRIMARY KEY  (id),
	UNIQUE KEY object_lookup (job_id, object_type, old_id),
	KEY job_id (job_id)
) {$charset};",
		);
	}

	/**
	 * Fully qualified table name for an allow-listed suffix.
	 *
	 * @since 1.0.0
	 *
	 * @param string $suffix jobs, job_log, backups, or id_map.
	 * @param string $prefix Optional prefix. Defaults to the current site prefix.
	 * @return string Empty when the suffix is not ours.
	 */
	public static function table( $suffix, $prefix = '' ) {
		if ( ! in_array( $suffix, self::SUFFIXES, true ) ) {
			return '';
		}

		if ( '' === $prefix ) {
			global $wpdb;
			$prefix = $wpdb->prefix;
		}

		if ( ! is_string( $prefix ) || ! preg_match( '/^[A-Za-z0-9_]*$/', $prefix ) ) {
			return '';
		}

		return $prefix . 'brightmigrate_' . $suffix;
	}

	/**
	 * Create or update tables for the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		foreach ( self::statements( $wpdb->prefix, $charset ) as $sql ) {
			dbDelta( $sql );
		}
	}
}
