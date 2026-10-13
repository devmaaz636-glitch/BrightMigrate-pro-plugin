<?php
/**
 * Newline-delimited archive manifest.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes and reads a manifest one JSON value at a time.
 *
 * The first line is the summary. Each following line is one entry.
 * Callers never decode the whole manifest as a single document.
 *
 * @since 1.0.0
 */
class Manifest {

	/**
	 * Encode one manifest value.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data JSON object.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function encode( array $data ) {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data, $flags );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Archive tests run before WordPress is loaded.
			$encoded = json_encode( $data, $flags );
		}
		if ( ! is_string( $encoded ) ) {
			throw new ArchiveException( 'Could not write the archive manifest.' );
		}
		return $encoded;
	}

	/**
	 * Decode one manifest line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $line One JSON object, without the trailing newline.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function decode_line( $line ) {
		$decoded = json_decode( $line, true );
		if ( ! is_array( $decoded ) ) {
			throw new ArchiveException( 'The archive manifest is unreadable.' );
		}
		return $decoded;
	}

	/**
	 * Read only the summary line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Plaintext manifest path.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function read_summary( $path ) {
		$handle = Stream::open( $path, 'rb' );
		try {
			$line = fgets( $handle, 65536 );
		} finally {
			fclose( $handle );
		}
		if ( ! is_string( $line ) || "\n" !== substr( $line, -1 ) ) {
			throw new ArchiveException( 'The archive manifest preamble is unreadable.' );
		}
		return self::decode_line( rtrim( $line, "\r\n" ) );
	}

	/**
	 * Call $callback once per entry, then discard that entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $path     Plaintext manifest path.
	 * @param callable $callback Receives one entry array.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function each( $path, $callback ) {
		if ( ! is_callable( $callback ) ) {
			throw new ArchiveException( 'Cannot list archive entries.' );
		}

		$handle = Stream::open( $path, 'rb' );
		try {
			$preamble = fgets( $handle, 65536 );
			if ( ! is_string( $preamble ) || "\n" !== substr( $preamble, -1 ) ) {
				throw new ArchiveException( 'The archive manifest preamble is unreadable.' );
			}
			while ( true ) {
				$line = fgets( $handle, 16384 );
				if ( false === $line ) {
					break;
				}
				if ( "\n" !== substr( $line, -1 ) ) {
					throw new ArchiveException( 'An archive manifest entry is unreadable.' );
				}
				$line = rtrim( $line, "\r\n" );
				if ( '' === $line ) {
					continue;
				}
				call_user_func( $callback, self::decode_line( $line ) );
			}
		} finally {
			fclose( $handle );
		}
	}
}
