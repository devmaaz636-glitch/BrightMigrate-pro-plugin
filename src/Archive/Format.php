<?php
/**
 * Binary layout for a .bmx archive.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants and little-endian packing for format version 1.
 *
 * @since 1.0.0
 */
class Format {

	/**
	 * File magic, including the format version byte.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const MAGIC = "BMXARCH\x01";

	/**
	 * Trailer magic.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const TRAILER_MAGIC = "BMXEND\x01";

	/**
	 * Entry magic.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	const ENTRY_MAGIC = 'BMXE';

	/**
	 * Format version written by this build.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const VERSION = 1;

	/**
	 * Fixed header length.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const HEADER_BYTES = 256;

	/**
	 * Fixed trailer length.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const TRAILER_BYTES = 64;

	/**
	 * Fixed portion of an entry header, before the path bytes.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const ENTRY_PREFIX_BYTES = 62;

	/**
	 * Read and write buffer. 512 KB.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const BUFFER_BYTES = 524288;

	/**
	 * Largest offset a 32-bit PHP build can seek to.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const MAX_32BIT_OFFSET = 2147483647;

	/**
	 * PBKDF2 iterations for an archive password.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const KDF_ITERATIONS = 100000;

	/**
	 * Manifest or database payload is encrypted.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const FLAG_ENCRYPTED_MANIFEST = 1;

	/**
	 * At least one database entry is encrypted.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const FLAG_ENCRYPTED_DATABASE = 2;

	/**
	 * File payloads are encrypted as well as the manifest.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const FLAG_ENCRYPTED_FILES = 4;

	/**
	 * This file is one part of a split archive.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const FLAG_SPLIT = 8;

	/**
	 * Entry is a file.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const ENTRY_FILE = 1;

	/**
	 * Entry is a database dump part.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const ENTRY_DATABASE = 2;

	/**
	 * Entry payload is encrypted.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const ENTRY_ENCRYPTED = 4;

	/**
	 * Width of size fields inside the manifest JSON.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const DIGIT_WIDTH = 20;

	/**
	 * Whether this PHP build must refuse offsets above 2 GB.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_32_bit() {
		return 4 === PHP_INT_SIZE;
	}

	/**
	 * Refuse a size this PHP build cannot seek to.
	 *
	 * @since 1.0.0
	 *
	 * @param int $value Byte count or offset.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function assert_offset( $value ) {
		if ( ! is_int( $value ) || $value < 0 ) {
			throw new ArchiveException( 'Archive size is not a usable number.' );
		}

		if ( self::is_32_bit() && $value > self::MAX_32BIT_OFFSET ) {
			throw new ArchiveException( 'This server uses 32-bit PHP. Archives larger than 2 GB are not supported here.' );
		}
	}

	/**
	 * Pack an unsigned 64-bit little-endian integer.
	 *
	 * @since 1.0.0
	 *
	 * @param int $value Value that fits in this PHP build.
	 * @return string
	 */
	public static function pack_u64( $value ) {
		self::assert_offset( $value );

		if ( 8 === PHP_INT_SIZE ) {
			return pack( 'P', $value );
		}

		return pack( 'V2', $value, 0 );
	}

	/**
	 * Read an unsigned 64-bit little-endian integer.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Eight bytes.
	 * @return int
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function unpack_u64( $bytes ) {
		if ( ! is_string( $bytes ) || 8 !== strlen( $bytes ) ) {
			throw new ArchiveException( 'The archive offset is incomplete.' );
		}

		if ( 8 === PHP_INT_SIZE ) {
			$unpacked = unpack( 'Pval', $bytes );
			return (int) $unpacked['val'];
		}

		$unpacked = unpack( 'Vlo/Vhi', $bytes );
		if ( (int) $unpacked['hi'] > 0 ) {
			throw new ArchiveException( 'This server uses 32-bit PHP. Archives larger than 2 GB are not supported here.' );
		}

		return (int) $unpacked['lo'];
	}

	/**
	 * Fixed-width decimal so the manifest length does not change when a number grows.
	 *
	 * @since 1.0.0
	 *
	 * @param int $value Non-negative integer.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function digits( $value ) {
		self::assert_offset( $value );
		$digits = sprintf( '%0' . self::DIGIT_WIDTH . 'd', $value );
		if ( self::DIGIT_WIDTH !== strlen( $digits ) ) {
			throw new ArchiveException( 'Archive size is too large to record.' );
		}
		return $digits;
	}

	/**
	 * Parse a fixed-width size from the manifest.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value JSON value.
	 * @return int
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function parse_digits( $value ) {
		$digits = is_string( $value ) ? $value : '';
		if ( 1 !== preg_match( '/^[0-9]{1,20}$/', $digits ) ) {
			throw new ArchiveException( 'The archive manifest has a bad size.' );
		}
		$trimmed = ltrim( $digits, '0' );
		if ( '' === $trimmed ) {
			$trimmed = '0';
		}
		if ( self::is_32_bit() && ( strlen( $trimmed ) > 10 || ( 10 === strlen( $trimmed ) && strcmp( $trimmed, '2147483647' ) > 0 ) ) ) {
			throw new ArchiveException( 'This server uses 32-bit PHP. Archives larger than 2 GB are not supported here.' );
		}
		$number = (int) $digits;
		self::assert_offset( $number );
		return $number;
	}

	/**
	 * Build the 256-byte header.
	 *
	 * @since 1.0.0
	 *
	 * @param array $fields Header fields.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function pack_header( array $fields ) {
		$bytes = str_repeat( "\0", self::HEADER_BYTES );
		self::place( $bytes, 0, self::MAGIC );
		self::place( $bytes, 8, pack( 'v', self::VERSION ) );
		self::place( $bytes, 10, pack( 'v', self::HEADER_BYTES ) );
		self::place( $bytes, 12, pack( 'V', (int) $fields['flags'] ) );
		self::place( $bytes, 16, self::pack_u64( (int) $fields['created'] ) );
		self::place( $bytes, 24, pack( 'v', (int) $fields['part_index'] ) );
		self::place( $bytes, 26, pack( 'v', ! empty( $fields['final'] ) ? 1 : 0 ) );
		self::place( $bytes, 28, pack( 'V', (int) $fields['iterations'] ) );
		$salt = isset( $fields['salt'] ) ? (string) $fields['salt'] : '';
		if ( '' !== $salt && 16 !== strlen( $salt ) ) {
			throw new ArchiveException( 'Archive salt is the wrong length.' );
		}
		self::place( $bytes, 32, str_pad( $salt, 16, "\0" ) );
		self::place( $bytes, 48, self::pack_u64( (int) $fields['manifest_offset'] ) );
		self::place( $bytes, 56, self::pack_u64( (int) $fields['manifest_stored'] ) );
		self::place( $bytes, 64, self::pack_u64( (int) $fields['manifest_plain'] ) );
		self::place( $bytes, 72, self::pack_u64( (int) $fields['entries_offset'] ) );
		self::place( $bytes, 80, self::pack_u64( (int) $fields['trailer_offset'] ) );
		self::place( $bytes, 88, self::pack_u64( (int) $fields['payload_length'] ) );
		return $bytes;
	}

	/**
	 * Read a 256-byte header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Header bytes.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function unpack_header( $bytes ) {
		if ( ! is_string( $bytes ) || self::HEADER_BYTES !== strlen( $bytes ) ) {
			throw new ArchiveException( 'The archive header is incomplete.' );
		}
		if ( self::MAGIC !== substr( $bytes, 0, 8 ) ) {
			throw new ArchiveException( 'This file is not a BrightMigrate archive.' );
		}

		$version = unpack( 'vval', substr( $bytes, 8, 2 ) );
		if ( self::VERSION !== (int) $version['val'] ) {
			throw new ArchiveException( 'This archive was made by a newer BrightMigrate format.' );
		}

		$flags      = unpack( 'Vval', substr( $bytes, 12, 4 ) );
		$part       = unpack( 'vval', substr( $bytes, 24, 2 ) );
		$final      = unpack( 'vval', substr( $bytes, 26, 2 ) );
		$iterations = unpack( 'Vval', substr( $bytes, 28, 4 ) );

		return array(
			'flags'           => (int) $flags['val'],
			'created'         => self::unpack_u64( substr( $bytes, 16, 8 ) ),
			'part_index'      => (int) $part['val'],
			'final'           => 1 === (int) $final['val'],
			'iterations'      => (int) $iterations['val'],
			'salt'            => substr( $bytes, 32, 16 ),
			'manifest_offset' => self::unpack_u64( substr( $bytes, 48, 8 ) ),
			'manifest_stored' => self::unpack_u64( substr( $bytes, 56, 8 ) ),
			'manifest_plain'  => self::unpack_u64( substr( $bytes, 64, 8 ) ),
			'entries_offset'  => self::unpack_u64( substr( $bytes, 72, 8 ) ),
			'trailer_offset'  => self::unpack_u64( substr( $bytes, 80, 8 ) ),
			'payload_length'  => self::unpack_u64( substr( $bytes, 88, 8 ) ),
		);
	}

	/**
	 * Build the 64-byte trailer.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $total_size  Total file size, including the trailer.
	 * @param string $crc_raw     Raw CRC32 (4 bytes) of everything before the trailer.
	 * @param string $sha_raw     Raw SHA-256 (32 bytes) of everything before the trailer.
	 * @param int    $part_index  One-based part number.
	 * @param bool   $is_final    Whether this is the last part.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function pack_trailer( $total_size, $crc_raw, $sha_raw, $part_index, $is_final ) {
		if ( 4 !== strlen( $crc_raw ) || 32 !== strlen( $sha_raw ) ) {
			throw new ArchiveException( 'The archive trailer checksum is the wrong length.' );
		}
		$bytes = str_repeat( "\0", self::TRAILER_BYTES );
		self::place( $bytes, 0, self::TRAILER_MAGIC );
		self::place( $bytes, 8, self::pack_u64( $total_size ) );
		self::place( $bytes, 16, $crc_raw );
		self::place( $bytes, 20, $sha_raw );
		self::place( $bytes, 52, pack( 'v', $part_index ) );
		self::place( $bytes, 54, pack( 'v', $is_final ? 1 : 0 ) );
		return $bytes;
	}

	/**
	 * Read a trailer.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Trailer bytes.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function unpack_trailer( $bytes ) {
		if ( ! is_string( $bytes ) || self::TRAILER_BYTES !== strlen( $bytes ) ) {
			throw new ArchiveException( 'The archive trailer is incomplete.' );
		}
		if ( self::TRAILER_MAGIC !== substr( $bytes, 0, strlen( self::TRAILER_MAGIC ) ) || "\0" !== substr( $bytes, 7, 1 ) ) {
			throw new ArchiveException( 'The archive trailer is missing.' );
		}
		$part  = unpack( 'vval', substr( $bytes, 52, 2 ) );
		$final = unpack( 'vval', substr( $bytes, 54, 2 ) );
		return array(
			'total_size' => self::unpack_u64( substr( $bytes, 8, 8 ) ),
			'crc32'      => bin2hex( substr( $bytes, 16, 4 ) ),
			'sha256'     => bin2hex( substr( $bytes, 20, 32 ) ),
			'part_index' => (int) $part['val'],
			'final'      => 1 === (int) $final['val'],
		);
	}

	/**
	 * Pack one entry header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path        Archive-relative path.
	 * @param int    $flags       Entry flags.
	 * @param int    $plain_size  Plaintext size.
	 * @param int    $stored_size Stored payload size.
	 * @param string $crc_raw     Raw CRC32 of the plaintext.
	 * @param string $sha_raw     Raw SHA-256 of the plaintext.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function pack_entry_header( $path, $flags, $plain_size, $stored_size, $crc_raw, $sha_raw ) {
		if ( 4 !== strlen( $crc_raw ) || 32 !== strlen( $sha_raw ) ) {
			throw new ArchiveException( 'An entry checksum is the wrong length.' );
		}
		$length = strlen( $path );
		if ( $length < 1 || $length > 4096 ) {
			throw new ArchiveException( 'An archive path is too long.' );
		}
		return self::ENTRY_MAGIC
			. pack( 'v', self::VERSION )
			. pack( 'v', $flags )
			. self::pack_u64( $plain_size )
			. self::pack_u64( $stored_size )
			. $crc_raw
			. $sha_raw
			. pack( 'v', $length )
			. $path;
	}

	/**
	 * Read one entry header, including its path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes Header bytes including the path.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function unpack_entry_header( $bytes ) {
		if ( ! is_string( $bytes ) || strlen( $bytes ) < self::ENTRY_PREFIX_BYTES ) {
			throw new ArchiveException( 'An archive entry header is incomplete.' );
		}
		if ( self::ENTRY_MAGIC !== substr( $bytes, 0, 4 ) ) {
			throw new ArchiveException( 'An archive entry header is invalid.' );
		}

		$version  = unpack( 'vval', substr( $bytes, 4, 2 ) );
		$flags    = unpack( 'vval', substr( $bytes, 6, 2 ) );
		$path_len = unpack( 'vval', substr( $bytes, 60, 2 ) );
		$length   = (int) $path_len['val'];
		if ( self::VERSION !== (int) $version['val'] || strlen( $bytes ) !== self::ENTRY_PREFIX_BYTES + $length ) {
			throw new ArchiveException( 'An archive entry header is incomplete.' );
		}

		return array(
			'flags'       => (int) $flags['val'],
			'plain_size'  => self::unpack_u64( substr( $bytes, 8, 8 ) ),
			'stored_size' => self::unpack_u64( substr( $bytes, 16, 8 ) ),
			'crc'         => substr( $bytes, 24, 4 ),
			'sha'         => substr( $bytes, 28, 32 ),
			'path'        => substr( $bytes, self::ENTRY_PREFIX_BYTES ),
		);
	}

	/**
	 * Whether an entry flag word says the payload is encrypted.
	 *
	 * @since 1.0.0
	 *
	 * @param int $flags Entry flags.
	 * @return bool
	 */
	public static function entry_is_encrypted( $flags ) {
		return self::ENTRY_ENCRYPTED === ( (int) $flags & self::ENTRY_ENCRYPTED );
	}

	/**
	 * Entry kind name from its flag word.
	 *
	 * @since 1.0.0
	 *
	 * @param int $flags Entry flags.
	 * @return string file or database.
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function entry_kind( $flags ) {
		$flags    = (int) $flags;
		$database = self::ENTRY_DATABASE === ( $flags & self::ENTRY_DATABASE );
		$file     = self::ENTRY_FILE === ( $flags & self::ENTRY_FILE );
		if ( $database === $file ) {
			throw new ArchiveException( 'An archive entry type is invalid.' );
		}
		return $database ? 'database' : 'file';
	}

	/**
	 * Overwrite a slice of a binary string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $bytes    Target string.
	 * @param int    $offset   Start offset.
	 * @param string $fragment Replacement bytes.
	 * @return void
	 */
	private static function place( &$bytes, $offset, $fragment ) {
		$len   = strlen( $fragment );
		$bytes = substr( $bytes, 0, $offset ) . $fragment . substr( $bytes, $offset + $len );
	}
}
