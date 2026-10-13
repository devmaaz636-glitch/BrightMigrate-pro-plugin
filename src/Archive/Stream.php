<?php
/**
 * Bounded stream helpers.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes archives in fixed buffers.
 *
 * @since 1.0.0
 */
class Stream {

	/**
	 * Open a file or throw.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Filesystem path.
	 * @param string $mode fopen mode.
	 * @return resource
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function open( $path, $mode ) {
		$handle = fopen( $path, $mode );
		if ( false === $handle ) {
			throw new ArchiveException( 'Could not open an archive file.' );
		}
		return $handle;
	}

	/**
	 * Read an exact number of bytes. Only for headers and frames, never a whole entry.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle Open handle.
	 * @param int      $bytes  Byte count, at most one buffer.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function read_exact( $handle, $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes < 1 || $bytes > Format::BUFFER_BYTES ) {
			throw new ArchiveException( 'Refusing to buffer that much archive data.' );
		}

		$data = '';
		$left = $bytes;
		while ( $left > 0 ) {
			$chunk = fread( $handle, $left );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				throw new ArchiveException( 'The archive ended before a header was complete.' );
			}
			$data .= $chunk;
			$left -= strlen( $chunk );
		}
		return $data;
	}

	/**
	 * Write every byte.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle Open handle.
	 * @param string   $data   Bytes.
	 * @return int
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function write_all( $handle, $data ) {
		$left    = strlen( $data );
		$offset  = 0;
		$written = 0;
		while ( $left > 0 ) {
			$chunk = fwrite( $handle, substr( $data, $offset, Format::BUFFER_BYTES ) );
			if ( false === $chunk || 0 === $chunk ) {
				throw new ArchiveException( 'Could not write the archive.' );
			}
			$offset  += $chunk;
			$left    -= $chunk;
			$written += $chunk;
		}
		return $written;
	}

	/**
	 * Copy a limited number of bytes, optionally hashing them.
	 *
	 * @since 1.0.0
	 *
	 * @param resource          $in    Source.
	 * @param resource          $out   Destination.
	 * @param int               $bytes Byte count.
	 * @param \HashContext|null $sha  SHA-256 context.
	 * @param \HashContext|null $crc  CRC32 context.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function copy_bytes( $in, $out, $bytes, $sha, $crc ) {
		Format::assert_offset( $bytes );
		$left = $bytes;
		while ( $left > 0 ) {
			$want  = $left > Format::BUFFER_BYTES ? Format::BUFFER_BYTES : $left;
			$chunk = fread( $in, $want );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				throw new ArchiveException( 'The archive ended in the middle of an entry.' );
			}
			if ( null !== $sha ) {
				hash_update( $sha, $chunk );
			}
			if ( null !== $crc ) {
				hash_update( $crc, $chunk );
			}
			self::write_all( $out, $chunk );
			$left -= strlen( $chunk );
		}
	}

	/**
	 * Copy until EOF, hashing and returning the checksum of the plaintext bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $in  Source.
	 * @param resource $out Destination.
	 * @return array{size: int, crc: string, sha: string}
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function copy_hashed( $in, $out ) {
		$checksum = new Checksum();
		$size     = 0;
		while ( ! feof( $in ) ) {
			$chunk = fread( $in, Format::BUFFER_BYTES );
			if ( false === $chunk ) {
				throw new ArchiveException( 'Could not read a file into the archive.' );
			}
			if ( '' === $chunk ) {
				break;
			}
			$checksum->update( $chunk );
			self::write_all( $out, $chunk );
			$size += strlen( $chunk );
			Format::assert_offset( $size );
		}
		$done = $checksum->finish();
		return array(
			'size' => $size,
			'crc'  => $done['crc'],
			'sha'  => $done['sha'],
		);
	}
}
