<?php
/**
 * Human readable sizes.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formats byte counts without casting them to platform integers.
 *
 * @since 1.0.0
 */
class Format {

	/**
	 * Format a byte count as a short label such as "5 GB".
	 *
	 * Units are international abbreviations and are not translated.
	 * Values stay floats so a 32-bit build can still display sizes above 2 GB.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $bytes     Byte count.
	 * @param bool  $truncated Whether the measurement stopped early.
	 * @return string
	 */
	public static function bytes( $bytes, $truncated = false ) {
		$bytes = is_numeric( $bytes ) ? (float) $bytes : 0.0;
		if ( $bytes < 0 || $bytes > 1.0e18 ) {
			$bytes = ( $bytes < 0 ) ? 0.0 : 1.0e18;
		}

		$units = array( 'B', 'KB', 'MB', 'GB', 'TB', 'PB' );
		$unit  = 0;
		$last  = count( $units ) - 1;

		while ( $bytes >= 1024 && $unit < $last ) {
			$bytes /= 1024;
			++$unit;
		}

		if ( 0 === $unit ) {
			$number = (string) (int) round( $bytes );
		} else {
			$number = (string) round( $bytes, 1 );
		}

		$label = $number . ' ' . $units[ $unit ];
		if ( $truncated ) {
			$label .= '+';
		}

		return $label;
	}
}
