<?php
/**
 * Request-size planning with no archive size cap.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chooses how big one HTTP step may be. The finished archive has no size cap.
 *
 * @since 1.0.0
 */
class Limits {

	/**
	 * Largest single upload step. This is not an archive limit.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const MAX_CHUNK = 10485760;

	/**
	 * Smallest step when the server does not force a lower value.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const FLOOR_CHUNK = 262144;

	/**
	 * Bytes reserved for the rest of the POST body.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const HEADROOM = 262144;

	/**
	 * Automatic step size when the server allows it.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const AUTO_CHUNK = 8388608;

	/**
	 * There is no maximum archive size. Zero means unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function archive_cap() {
		return 0;
	}

	/**
	 * Parse a php.ini size such as 8M, 512K, or 2G. Zero means unlimited.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw ini value.
	 * @return int
	 */
	public static function ini_bytes( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value || '0' === $value || '-1' === $value ) {
			return 0;
		}
		$unit   = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		if ( 'g' === $unit ) {
			return (int) ( $number * 1073741824 );
		}
		if ( 'm' === $unit ) {
			return (int) ( $number * 1048576 );
		}
		if ( 'k' === $unit ) {
			return (int) ( $number * 1024 );
		}
		return (int) $number;
	}

	/**
	 * Bytes for one upload step. The result always fits the server when a limit exists.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $setting_mb Saved setting. Zero means automatic.
	 * @param string $post_max   post_max_size.
	 * @param string $upload_max upload_max_filesize.
	 * @return int
	 */
	public static function chunk_bytes( $setting_mb, $post_max, $upload_max ) {
		$limits = array();
		$post   = self::ini_bytes( $post_max );
		$upload = self::ini_bytes( $upload_max );
		if ( $post > 0 ) {
			$limits[] = $post;
		}
		if ( $upload > 0 ) {
			$limits[] = $upload;
		}

		$room = 0;
		if ( array() !== $limits ) {
			$room = min( $limits );
			if ( $room > self::HEADROOM ) {
				$room -= self::HEADROOM;
			}
		}

		$desired = ( (int) $setting_mb > 0 ) ? (int) $setting_mb * 1048576 : self::AUTO_CHUNK;
		if ( $room > 0 && $desired > $room ) {
			$desired = $room;
		}
		if ( $desired > self::MAX_CHUNK ) {
			$desired = self::MAX_CHUNK;
		}
		if ( $desired < self::FLOOR_CHUNK ) {
			$desired = self::FLOOR_CHUNK;
		}
		return $desired;
	}
}
