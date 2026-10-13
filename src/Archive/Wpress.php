<?php
/**
 * All-in-One style .wpress archive.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes a .wpress file without loading it into memory.
 *
 * Each entry is a 4377-byte header and then the raw file bytes. The header is
 * the file name (255), the size as ASCII (14), the modification time as ASCII
 * (12), and the directory (4096). Newer files store an 8-character CRC in the
 * last bytes of that directory field. An empty name, or the end of the file,
 * ends the archive.
 *
 * @since 1.0.0
 */
class Wpress {

	/**
	 * Header length in bytes.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const HEADER_BYTES = 4377;

	/**
	 * File name field length.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const NAME_BYTES = 255;

	/**
	 * Size field length.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const SIZE_BYTES = 14;

	/**
	 * Modification time field length.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const MTIME_BYTES = 12;

	/**
	 * Directory field length, including the optional checksum bytes.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const PATH_BYTES = 4096;

	/**
	 * Build one header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $archive_path Path inside the archive.
	 * @param int    $size         File size in bytes.
	 * @param int    $mtime        Unix modification time.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function header( $archive_path, $size, $mtime ) {
		list( $name, $prefix ) = self::split_path( $archive_path );
		$size_text             = (string) (int) $size;
		$mtime_text            = (string) (int) $mtime;
		if ( strlen( $size_text ) > self::SIZE_BYTES || strlen( $mtime_text ) > self::MTIME_BYTES ) {
			throw new ArchiveException( 'Could not write the archive.' );
		}
		$bytes = pack( 'a255a14a12a4096', $name, $size_text, $mtime_text, $prefix );
		if ( self::HEADER_BYTES !== strlen( $bytes ) ) {
			throw new ArchiveException( 'Could not write the archive.' );
		}
		return $bytes;
	}

	/**
	 * Read one header block.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Header bytes.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function parse_header( $bytes ) {
		if ( ! is_string( $bytes ) || self::HEADER_BYTES !== strlen( $bytes ) ) {
			throw new ArchiveException( 'This file is not a .wpress archive.' );
		}
		$name = self::cstring( substr( $bytes, 0, self::NAME_BYTES ) );
		if ( '' === $name ) {
			return array(
				'eof'    => true,
				'name'   => '',
				'prefix' => '',
				'size'   => 0,
				'mtime'  => 0,
				'crc'    => '',
			);
		}
		if ( false !== strpos( $name, '/' ) || false !== strpos( $name, '\\' ) ) {
			throw new ArchiveException( 'This file is not a .wpress archive.' );
		}

		$size_text  = trim( self::cstring( substr( $bytes, self::NAME_BYTES, self::SIZE_BYTES ) ) );
		$mtime_text = trim( self::cstring( substr( $bytes, self::NAME_BYTES + self::SIZE_BYTES, self::MTIME_BYTES ) ) );
		if ( 1 !== preg_match( '/^[0-9]+$/', $size_text ) ) {
			throw new ArchiveException( 'This file is not a .wpress archive.' );
		}
		$size = self::size_value( $size_text );

		$crc    = '';
		$prefix = self::cstring( substr( $bytes, 281, self::PATH_BYTES ) );
		$tail   = substr( $bytes, 4369, 8 );
		if ( "\0" === $bytes[4368] && 1 === preg_match( '/^[0-9a-fA-F]{8}$/', $tail ) ) {
			$crc    = strtolower( $tail );
			$prefix = self::cstring( substr( $bytes, 281, 4088 ) );
		}

		return array(
			'eof'    => false,
			'name'   => $name,
			'prefix' => $prefix,
			'size'   => $size,
			'mtime'  => 1 === preg_match( '/^[0-9]+$/', $mtime_text ) ? (int) $mtime_text : 0,
			'crc'    => $crc,
		);
	}

	/**
	 * Append a small complete file, such as package.json.
	 *
	 * @since 1.0.0
	 *
	 * @param string $partial      Partial archive path.
	 * @param string $archive_path Path inside the archive.
	 * @param string $contents     File bytes.
	 * @param int    $mtime        Unix modification time.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function append_bytes( $partial, $archive_path, $contents, $mtime ) {
		if ( ! is_string( $contents ) || strlen( $contents ) > 1048576 ) {
			throw new ArchiveException( 'Could not write the archive.' );
		}
		$handle = Stream::open( $partial, 'ab' );
		try {
			Stream::write_all( $handle, self::header( $archive_path, strlen( $contents ), $mtime ) );
			if ( '' !== $contents ) {
				Stream::write_all( $handle, $contents );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Copy more of one file into the partial archive.
	 *
	 * @since 1.0.0
	 *
	 * @param string $partial      Partial archive path.
	 * @param string $source       Local file to copy.
	 * @param string $archive_path Path inside the archive.
	 * @param int    $body_written Body bytes already stored.
	 * @param bool   $header_done  Whether the header is already stored.
	 * @param int    $body_size    Size recorded in the header, or 0 before it is written.
	 * @param float  $deadline     Microtime when this call should stop.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function append_file( $partial, $source, $archive_path, $body_written, $header_done, $body_size, $deadline ) {
		if ( ! is_file( $source ) || is_link( $source ) ) {
			throw new ArchiveException( 'Could not read a file for the archive.' );
		}
		$body_written = (int) $body_written;
		$body_size    = (int) $body_size;
		$out          = Stream::open( $partial, 'ab' );
		$in           = Stream::open( $source, 'rb' );
		try {
			if ( ! $header_done ) {
				$stat = filesize( $source );
				$when = filemtime( $source );
				if ( false === $stat || false === $when ) {
					throw new ArchiveException( 'Could not read a file for the archive.' );
				}
				$body_size = (int) $stat;
				Stream::write_all( $out, self::header( $archive_path, $body_size, (int) $when ) );
				$header_done = true;
			}
			if ( $body_written > 0 && 0 !== fseek( $in, $body_written, SEEK_SET ) ) {
				throw new ArchiveException( 'Could not read a file for the archive.' );
			}
			while ( $body_written < $body_size && microtime( true ) < $deadline ) {
				$step  = min( Format::BUFFER_BYTES, $body_size - $body_written );
				$chunk = fread( $in, $step );
				if ( ! is_string( $chunk ) || '' === $chunk ) {
					throw new ArchiveException( 'Could not read a file for the archive.' );
				}
				Stream::write_all( $out, $chunk );
				$body_written += strlen( $chunk );
			}
		} finally {
			fclose( $in );
			fclose( $out );
		}
		return array(
			'done'         => $body_written >= $body_size,
			'body_written' => $body_written,
			'header_done'  => $header_done,
			'body_size'    => $body_size,
		);
	}

	/**
	 * Write the end block and publish the partial file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $partial     Partial archive path.
	 * @param string $destination Finished archive path.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function finish( $partial, $destination ) {
		$handle = Stream::open( $partial, 'ab' );
		try {
			Stream::write_all( $handle, str_repeat( "\0", self::HEADER_BYTES ) );
		} finally {
			fclose( $handle );
		}
		if ( is_file( $destination ) && ! unlink( $destination ) ) {
			throw new ArchiveException( 'Could not store the archive.' );
		}
		if ( ! rename( $partial, $destination ) ) {
			throw new ArchiveException( 'Could not store the archive.' );
		}
	}

	/**
	 * Count entries and read package.json when it is small.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Archive path.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function summary( $path ) {
		if ( ! is_string( $path ) || ! is_file( $path ) || is_link( $path ) ) {
			throw new ArchiveException( 'This file is not a .wpress archive.' );
		}
		$handle    = Stream::open( $path, 'rb' );
		$files     = 0;
		$databases = 0;
		$source    = '';
		try {
			while ( true ) {
				$block = self::read_header_block( $handle );
				if ( null === $block ) {
					break;
				}
				$header = self::parse_header( $block );
				if ( ! empty( $header['eof'] ) ) {
					break;
				}
				++$files;
				$archive_path = self::join_path( $header['prefix'], $header['name'] );
				$size         = (int) $header['size'];
				if ( 'package.json' === $archive_path && $size <= 1048576 ) {
					$decoded = json_decode( self::read_length( $handle, $size ), true );
					if ( is_array( $decoded ) && isset( $decoded['SiteURL'] ) && is_string( $decoded['SiteURL'] ) ) {
						$source = $decoded['SiteURL'];
					}
				} else {
					self::skip( $handle, $size );
				}
				if ( 'database.sql' === $archive_path ) {
					$databases = 1;
				}
			}
		} finally {
			fclose( $handle );
		}
		if ( $files < 1 ) {
			throw new ArchiveException( 'This file is not a .wpress archive.' );
		}
		$mtime = filemtime( $path );
		return array(
			'format'     => 'wpress',
			'type'       => $databases > 0 ? 'full' : 'archive',
			'source_url' => $source,
			'created_at' => false === $mtime ? '' : gmdate( 'c', $mtime ),
			'contents'   => array(
				'files'          => $files,
				'database_parts' => $databases,
			),
		);
	}

	/**
	 * Split an archive path into a file name and a directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $archive_path Path inside the archive.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function split_path( $archive_path ) {
		$archive_path = str_replace( '\\', '/', (string) $archive_path );
		if ( '' === $archive_path || false !== strpos( $archive_path, "\0" ) || self::has_dot_segment( $archive_path ) ) {
			throw new ArchiveException( 'Could not write the archive.' );
		}
		$name   = basename( $archive_path );
		$prefix = dirname( $archive_path );
		if ( '.' === $prefix ) {
			$prefix = '';
		}
		if ( '' === $name || strlen( $name ) > self::NAME_BYTES || strlen( $prefix ) > 4095 ) {
			throw new ArchiveException( 'Could not write the archive.' );
		}
		return array( $name, $prefix );
	}

	/**
	 * Join a directory and a file name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix Directory from the header.
	 * @param string $name   File name from the header.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function join_path( $prefix, $name ) {
		$prefix = str_replace( '\\', '/', (string) $prefix );
		$name   = str_replace( '\\', '/', (string) $name );
		if ( '' === $name || false !== strpos( $name, '/' ) || self::has_dot_segment( $prefix . '/' . $name ) ) {
			throw new ArchiveException( 'This file is not a .wpress archive.' );
		}
		if ( '' === $prefix || '.' === $prefix ) {
			return $name;
		}
		return rtrim( $prefix, '/' ) . '/' . $name;
	}

	/**
	 * Whether a path contains a parent-directory segment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Archive path.
	 * @return bool
	 */
	private static function has_dot_segment( $path ) {
		$parts = explode( '/', str_replace( '\\', '/', $path ) );
		return in_array( '..', $parts, true );
	}

	/**
	 * Bytes before the first null.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Field bytes.
	 * @return string
	 */
	private static function cstring( $bytes ) {
		$end = strpos( $bytes, "\0" );
		if ( false === $end ) {
			return $bytes;
		}
		return substr( $bytes, 0, $end );
	}

	/**
	 * Size field as an integer this PHP build can seek with.
	 *
	 * @since 1.0.0
	 *
	 * @param string $size_text Digits from the header.
	 * @return int
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function size_value( $size_text ) {
		$trimmed = ltrim( $size_text, '0' );
		if ( '' === $trimmed ) {
			$trimmed = '0';
		}
		if ( PHP_INT_SIZE < 8 && strlen( $trimmed ) > 9 ) {
			throw new ArchiveException( 'This PHP build cannot read an archive file that large.' );
		}
		$size = (int) $trimmed;
		if ( (string) $size !== $trimmed ) {
			throw new ArchiveException( 'This PHP build cannot read an archive file that large.' );
		}
		return $size;
	}

	/**
	 * Next header, or null when the file ends cleanly.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle Open archive.
	 * @return string|null
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function read_header_block( $handle ) {
		$data = '';
		$have = 0;
		$need = self::HEADER_BYTES;
		while ( $have < $need ) {
			$chunk = fread( $handle, $need - $have );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				if ( 0 === $have ) {
					return null;
				}
				throw new ArchiveException( 'This file is not a .wpress archive.' );
			}
			$data .= $chunk;
			$have += strlen( $chunk );
		}
		return $data;
	}

	/**
	 * Read a small entry body.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle Open archive.
	 * @param int      $size   Byte count.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function read_length( $handle, $size ) {
		$data = '';
		$left = (int) $size;
		while ( $left > 0 ) {
			$chunk = fread( $handle, min( Format::BUFFER_BYTES, $left ) );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				throw new ArchiveException( 'This file is not a .wpress archive.' );
			}
			$data .= $chunk;
			$left -= strlen( $chunk );
		}
		return $data;
	}

	/**
	 * Move past an entry body.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle Open archive.
	 * @param int      $size   Byte count.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function skip( $handle, $size ) {
		$left = (int) $size;
		if ( $left < 1 ) {
			return;
		}
		$before = ftell( $handle );
		if ( false !== $before && 0 === fseek( $handle, $left, SEEK_CUR ) ) {
			$after = ftell( $handle );
			if ( false !== $after && ( $after - $before ) === $left ) {
				return;
			}
		}
		if ( false !== $before ) {
			fseek( $handle, $before, SEEK_SET );
		}
		while ( $left > 0 ) {
			$chunk = fread( $handle, min( Format::BUFFER_BYTES, $left ) );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				throw new ArchiveException( 'This file is not a .wpress archive.' );
			}
			$left -= strlen( $chunk );
		}
	}
}
