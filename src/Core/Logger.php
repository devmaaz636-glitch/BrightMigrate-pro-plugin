<?php
/**
 * Append-only diagnostic log.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes one JSON line per event. It never reads the log back into memory.
 *
 * @since 1.0.0
 */
class Logger {

	/**
	 * Context keys whose values are replaced before anything is written.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SECRET_KEYS = array(
		'password',
		'pass',
		'pwd',
		'archive_password',
		'secret',
		'token',
	);

	/**
	 * Append one event and return its reference ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level     info, warning, or error.
	 * @param string $message   Single-line message. Not shown to visitors.
	 * @param array  $context   Extra data. Secret keys are redacted.
	 * @param string $directory Directory that receives brightmigrate.log. Empty uses storage.
	 * @return string Reference ID, for example bmx-1a2b3c4d5e6f.
	 */
	public static function write( $level, $message, array $context = array(), $directory = '' ) {
		try {
			return self::write_line( $level, $message, $context, $directory );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return self::reference();
		}
	}

	/**
	 * Build a reference ID.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function reference() {
		try {
			$random = bin2hex( random_bytes( 6 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			$random = substr( hash( 'sha256', uniqid( 'bmx', true ) ), 0, 12 );
		}

		return 'bmx-' . $random;
	}

	/**
	 * Replace secret context values.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Raw context.
	 * @return array
	 */
	public static function redact( array $context ) {
		$clean = array();

		foreach ( $context as $key => $value ) {
			$key_name = strtolower( (string) $key );
			if ( in_array( $key_name, self::SECRET_KEYS, true ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
				continue;
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Write the line or fall back to a reference-only PHP log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level     Log level.
	 * @param string $message   Message.
	 * @param array  $context   Context.
	 * @param string $directory Target directory.
	 * @return string
	 */
	private static function write_line( $level, $message, array $context, $directory ) {
		$reference = self::reference();
		$allowed   = array( 'info', 'warning', 'error' );
		if ( ! in_array( $level, $allowed, true ) ) {
			$level = 'info';
		}

		$message = str_replace( array( "\r", "\n" ), ' ', (string) $message );
		$payload = array(
			'ref'     => $reference,
			'time'    => gmdate( 'c' ),
			'level'   => $level,
			'message' => $message,
			'context' => self::redact( $context ),
		);

		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $payload );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Logger tests run before WordPress is loaded.
			$encoded = json_encode( $payload );
		}
		if ( ! is_string( $encoded ) ) {
			$encoded = '{"ref":"' . $reference . '","level":"error","message":"log encoding failed"}';
		}

		$dir = '' !== $directory ? $directory : self::default_directory();
		if ( '' === $dir || ! is_dir( $dir ) || is_link( $dir ) ) {
			self::fallback( $reference );
			return $reference;
		}

		$file   = rtrim( str_replace( '\\', '/', $dir ), '/' ) . '/brightmigrate.log';
		$handle = fopen( $file, 'ab' );
		if ( false === $handle ) {
			self::fallback( $reference );
			return $reference;
		}

		if ( flock( $handle, LOCK_EX ) ) {
			fwrite( $handle, $encoded . "\n" );
			fflush( $handle );
			flock( $handle, LOCK_UN );
		}

		fclose( $handle );
		return $reference;
	}

	/**
	 * Storage logs directory when WordPress and storage are available.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function default_directory() {
		if ( ! class_exists( Storage::class ) ) {
			return '';
		}

		$path = Storage::active_path();
		if ( '' === $path ) {
			return '';
		}

		return rtrim( $path, '/\\' ) . '/logs';
	}

	/**
	 * Last-resort diagnostic that does not include the original message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reference Reference ID.
	 * @return void
	 */
	private static function fallback( $reference ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Used only when the protected log folder cannot be written.
		error_log( 'BrightMigrate [' . $reference . '] could not write the protected log.' );
	}
}
