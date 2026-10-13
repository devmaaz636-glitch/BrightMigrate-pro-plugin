<?php
/**
 * AES-256-GCM for manifest and database bytes.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts a stream in 512 KB chunks. The password is not written anywhere.
 *
 * @since 1.0.0
 */
class Crypto {

	/**
	 * Bytes of framing around each ciphertext chunk.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const FRAME_BYTES = 36;

	/**
	 * Whether OpenSSL can do AES-256-GCM.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive a 32-byte key. The password is not retained by this method.
	 *
	 * @since 1.0.0
	 *
	 * @param string $password   Caller-supplied password.
	 * @param string $salt       16-byte salt from the header.
	 * @param int    $iterations PBKDF2 iterations from the header.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function key( $password, $salt, $iterations ) {
		if ( ! self::available() ) {
			throw new ArchiveException( 'Password protection needs the PHP openssl extension.' );
		}
		if ( ! is_string( $password ) || '' === $password || strlen( $password ) > 1024 ) {
			throw new ArchiveException( 'The archive password is missing.' );
		}
		if ( 16 !== strlen( $salt ) ) {
			throw new ArchiveException( 'The archive salt is unusable.' );
		}
		if ( $iterations < 100000 || $iterations > 1000000 ) {
			throw new ArchiveException( 'The archive key settings are not supported.' );
		}

		$key = hash_pbkdf2( 'sha256', $password, $salt, $iterations, 32, true );
		if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
			throw new ArchiveException( 'The archive key could not be derived.' );
		}
		return $key;
	}

	/**
	 * Ciphertext length for a known plaintext length.
	 *
	 * @since 1.0.0
	 *
	 * @param int $plain_length Plaintext bytes.
	 * @return int
	 */
	public static function stored_length( $plain_length ) {
		Format::assert_offset( $plain_length );
		if ( 0 === $plain_length ) {
			return 0;
		}
		$chunks = (int) ( ( $plain_length + Format::BUFFER_BYTES - 1 ) / Format::BUFFER_BYTES );
		$stored = $plain_length + ( self::FRAME_BYTES * $chunks );
		Format::assert_offset( $stored );
		return $stored;
	}

	/**
	 * Encrypt one file to another.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @param string $key    32-byte key.
	 * @return int Stored bytes written.
	 */
	public static function encrypt_file( $source, $dest, $key ) {
		$in  = Stream::open( $source, 'rb' );
		$out = Stream::open( $dest, 'wb' );
		try {
			$counts = self::encrypt_handle( $in, $out, $key, null );
			return $counts['stored'];
		} finally {
			fclose( $in );
			fclose( $out );
		}
	}

	/**
	 * Decrypt one file to another.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @param string $key    32-byte key.
	 * @param int    $plain_length Expected plaintext length.
	 * @return void
	 */
	public static function decrypt_file( $source, $dest, $key, $plain_length ) {
		$in  = Stream::open( $source, 'rb' );
		$out = Stream::open( $dest, 'wb' );
		try {
			self::decrypt_handle( $in, $out, $key, $plain_length, null );
		} finally {
			fclose( $in );
			fclose( $out );
		}
	}

	/**
	 * Encrypt from the current position until EOF.
	 *
	 * @since 1.0.0
	 *
	 * @param resource      $in       Source handle.
	 * @param resource      $out      Destination handle.
	 * @param string        $key      32-byte key.
	 * @param Checksum|null $checksum Updated with plaintext when provided.
	 * @return array{plain: int, stored: int}
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function encrypt_handle( $in, $out, $key, $checksum = null ) {
		$plain_size = 0;
		$stored     = 0;
		while ( ! feof( $in ) ) {
			$plain = fread( $in, Format::BUFFER_BYTES );
			if ( false === $plain ) {
				throw new ArchiveException( 'Could not read data to encrypt.' );
			}
			if ( '' === $plain ) {
				break;
			}
			if ( null !== $checksum ) {
				$checksum->update( $plain );
			}
			$plain_size += strlen( $plain );
			$stored     += Stream::write_all( $out, self::encrypt_chunk( $plain, $key ) );
			Format::assert_offset( $plain_size );
			Format::assert_offset( $stored );
		}
		return array(
			'plain'  => $plain_size,
			'stored' => $stored,
		);
	}

	/**
	 * Decrypt a known number of plaintext bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param resource      $in           Source handle.
	 * @param resource|null $out          Destination handle, or null to discard plaintext after hashing.
	 * @param string        $key          32-byte key.
	 * @param int           $plain_length Expected plaintext bytes.
	 * @param Checksum|null $checksum     Updated with plaintext when provided.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function decrypt_handle( $in, $out, $key, $plain_length, $checksum ) {
		$left = $plain_length;
		Format::assert_offset( $left );
		while ( $left > 0 ) {
			$lengths = Stream::read_exact( $in, 8 );
			$parsed  = unpack( 'Vplain/Vstored', $lengths );
			$plain_n = (int) $parsed['plain'];
			$stored  = (int) $parsed['stored'];
			if ( $plain_n < 1 || $plain_n > Format::BUFFER_BYTES || $stored !== $plain_n || $plain_n > $left ) {
				throw new ArchiveException( 'Could not decrypt this archive. Check the password.' );
			}
			$nonce  = Stream::read_exact( $in, 12 );
			$tag    = Stream::read_exact( $in, 16 );
			$cipher = Stream::read_exact( $in, $stored );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag );
			if ( ! is_string( $plain ) || strlen( $plain ) !== $plain_n ) {
				throw new ArchiveException( 'Could not decrypt this archive. Check the password.' );
			}
			if ( null !== $checksum ) {
				$checksum->update( $plain );
			}
			if ( null !== $out ) {
				Stream::write_all( $out, $plain );
			}
			$left -= $plain_n;
		}
	}

	/**
	 * Encrypt one chunk and return its frame.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plain Plaintext.
	 * @param string $key   32-byte key.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function encrypt_chunk( $plain, $key ) {
		$nonce  = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( ! is_string( $cipher ) || ! is_string( $tag ) || 16 !== strlen( $tag ) ) {
			throw new ArchiveException( 'Could not encrypt this archive.' );
		}
		return pack( 'V2', strlen( $plain ), strlen( $cipher ) ) . $nonce . $tag . $cipher;
	}
}
