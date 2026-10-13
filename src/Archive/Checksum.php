<?php
/**
 * Running CRC32 and SHA-256.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates checksums from 512 KB buffers. The context is finished once.
 *
 * @since 1.0.0
 */
class Checksum {

	/**
	 * SHA-256 context.
	 *
	 * @since 1.0.0
	 *
	 * @var \HashContext
	 */
	private $sha;

	/**
	 * CRC32b context.
	 *
	 * @since 1.0.0
	 *
	 * @var \HashContext
	 */
	private $crc;

	/**
	 * Start both hashes.
	 *
	 * @since 1.0.0
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public function __construct() {
		$sha = hash_init( 'sha256' );
		$crc = hash_init( 'crc32b' );
		if ( false === $sha || false === $crc ) {
			throw new ArchiveException( 'This server cannot calculate archive checksums.' );
		}
		$this->sha = $sha;
		$this->crc = $crc;
	}

	/**
	 * Add one buffer.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data Bytes.
	 * @return void
	 */
	public function update( $data ) {
		hash_update( $this->sha, $data );
		hash_update( $this->crc, $data );
	}

	/**
	 * Finish and return raw digests.
	 *
	 * @since 1.0.0
	 *
	 * @return array{crc: string, sha: string, crc_hex: string, sha_hex: string}
	 */
	public function finish() {
		$sha = hash_final( $this->sha, true );
		$crc = hash_final( $this->crc, true );
		return array(
			'crc'     => $crc,
			'sha'     => $sha,
			'crc_hex' => bin2hex( $crc ),
			'sha_hex' => bin2hex( $sha ),
		);
	}
}
