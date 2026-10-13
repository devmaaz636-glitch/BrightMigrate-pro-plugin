<?php
/**
 * Reads a .bmx archive.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opens one .bmx part, lists its manifest, and checks or extracts entries.
 *
 * The summary is the first manifest line. Entries are read one line at a time.
 * A wrong password fails with a generic message and is never written to disk.
 *
 * @since 1.0.0
 */
class Reader {

	/**
	 * Archive path.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Open archive handle.
	 *
	 * @since 1.0.0
	 *
	 * @var resource|null
	 */
	private $handle = null;

	/**
	 * Decoded header.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $header = array();

	/**
	 * Derived key, or null when the part is not encrypted.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private $key;

	/**
	 * Temporary directory for the plaintext manifest.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $work = '';

	/**
	 * Plaintext manifest path.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $manifest_path = '';

	/**
	 * Summary line. Entries are not included.
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	private $summary = array();

	/**
	 * Whether close() has already run.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $closed = false;

	/**
	 * Open one archive part.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path     Local .bmx path. Symlinks are rejected.
	 * @param string $password Password, or an empty string when the part is not protected.
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public function __construct( $path, $password = '' ) {
		if ( ! is_string( $path ) || is_link( $path ) || ! is_file( $path ) ) {
			throw new ArchiveException( 'Could not open an archive file.' );
		}
		$this->path   = $path;
		$this->handle = Stream::open( $path, 'rb' );
		try {
			$this->header = Format::unpack_header( Stream::read_exact( $this->handle, Format::HEADER_BYTES ) );
			$this->prepare_key( is_string( $password ) ? $password : '' );
			$password = '';
			$this->assert_layout();
			$this->open_work();
			$this->extract_manifest();
			$this->summary = Manifest::read_summary( $this->manifest_path );
			$this->accept_summary();
		} catch ( ArchiveException $exception ) {
			$this->close();
			throw $exception;
		}
	}

	/**
	 * Close the archive and delete the temporary manifest.
	 *
	 * @since 1.0.0
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Manifest summary. Entry bodies are not loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function summary() {
		$this->assert_open();
		return $this->summary;
	}

	/**
	 * Visit each entry, one at a time.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $callback Receives one manifest entry.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public function each( $callback ) {
		$this->assert_open();
		if ( ! is_callable( $callback ) ) {
			throw new ArchiveException( 'Cannot list archive entries.' );
		}
		Manifest::each(
			$this->manifest_path,
			function ( $entry ) use ( $callback ) {
				call_user_func( $callback, $this->normalize_entry( $entry ) );
			}
		);
	}

	/**
	 * Check the archive checksum and every entry checksum.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function verify() {
		$this->assert_open();
		$this->verify_container();
		$this->count_entries();
		return true;
	}

	/**
	 * Extract into an existing directory after the archive checksum matches.
	 *
	 * $policy `full` allows PHP anywhere. `content` allows it only under themes and plugins.
	 * A checksum failure deletes the partial file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root   Existing destination directory.
	 * @param string $policy full or content.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public function extract( $root, $policy = 'full' ) {
		$this->assert_open();
		if ( ! is_string( $root ) || is_link( $root ) || ! is_dir( $root ) ) {
			throw new ArchiveException( 'The extract folder is not a real directory.' );
		}
		$this->verify_container();
		$this->each(
			function ( $entry ) use ( $root, $policy ) {
				if ( ! PathGuard::allows_executable( $entry['path'], $policy ) ) {
					throw new ArchiveException( 'Refusing to extract an executable file outside themes and plugins.' );
				}
				$this->extract_entry( $entry, $root );
			}
		);
	}

	/**
	 * Close handles and remove the temporary manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->closed ) {
			return;
		}
		$this->closed = true;
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
		$this->handle = null;
		if ( '' !== $this->manifest_path && is_file( $this->manifest_path ) ) {
			unlink( $this->manifest_path );
		}
		if ( '' !== $this->work && is_dir( $this->work ) ) {
			rmdir( $this->work );
		}
	}

	/**
	 * Check one manifest entry and return it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $entry Raw manifest entry.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function normalize_entry( array $entry ) {
		$required = array( 'path', 'kind', 'size', 'stored_size', 'offset', 'crc32', 'sha256', 'encrypted' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $entry ) ) {
				throw new ArchiveException( 'An archive manifest entry is unreadable.' );
			}
		}
		$path = PathGuard::archive_path( $entry['path'] );
		if ( $path !== $entry['path'] ) {
			throw new ArchiveException( 'An archive path is not safe.' );
		}
		if ( 'file' !== $entry['kind'] && 'database' !== $entry['kind'] ) {
			throw new ArchiveException( 'An archive entry type is invalid.' );
		}
		if ( 1 !== preg_match( '/^[0-9a-f]{8}$/', (string) $entry['crc32'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/', (string) $entry['sha256'] ) ) {
			throw new ArchiveException( 'An archive entry checksum is unreadable.' );
		}
		$offset = Format::parse_digits( $entry['offset'] );
		if ( $offset < $this->header['entries_offset'] || $offset >= $this->header['trailer_offset'] ) {
			throw new ArchiveException( 'An archive entry points outside the payload.' );
		}
		Format::parse_digits( $entry['size'] );
		Format::parse_digits( $entry['stored_size'] );
		return $entry;
	}

	/**
	 * Refuse use after close().
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function assert_open() {
		if ( $this->closed || ! is_resource( $this->handle ) ) {
			throw new ArchiveException( 'This archive is already closed.' );
		}
	}

	/**
	 * Derive the key when the manifest is encrypted.
	 *
	 * @since 1.0.0
	 *
	 * @param string $password Caller password. Not stored.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function prepare_key( $password ) {
		$protected = Format::FLAG_ENCRYPTED_MANIFEST === ( $this->header['flags'] & Format::FLAG_ENCRYPTED_MANIFEST );
		if ( ! $protected ) {
			$this->key = null;
			return;
		}
		if ( '' === $password ) {
			throw new ArchiveException( 'Could not decrypt this archive. Check the password.' );
		}
		$this->key = Crypto::key( $password, $this->header['salt'], $this->header['iterations'] );
	}

	/**
	 * Check that the header offsets describe this file.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function assert_layout() {
		$header = $this->header;
		if ( Format::HEADER_BYTES !== $header['manifest_offset'] ) {
			throw new ArchiveException( 'The archive layout is not supported.' );
		}
		$entries = $header['manifest_offset'] + $header['manifest_stored'];
		Format::assert_offset( $entries );
		$trailer = $entries + $header['payload_length'];
		Format::assert_offset( $trailer );
		$total = $trailer + Format::TRAILER_BYTES;
		Format::assert_offset( $total );
		if ( $entries !== $header['entries_offset'] || $trailer !== $header['trailer_offset'] || $total !== $this->file_size() ) {
			throw new ArchiveException( 'The archive layout does not match the file.' );
		}
	}

	/**
	 * Byte length of the open archive.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function file_size() {
		$stat = fstat( $this->handle );
		if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
			throw new ArchiveException( 'Could not read the archive size.' );
		}
		$size = (int) $stat['size'];
		Format::assert_offset( $size );
		return $size;
	}

	/**
	 * Create a private folder for the plaintext manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function open_work() {
		try {
			$suffix = bin2hex( random_bytes( 4 ) );
		} catch ( \Exception $exception ) {
			throw new ArchiveException( 'Could not prepare archive storage.' );
		}
		$this->work          = sys_get_temp_dir() . '/bmx-read-' . $suffix;
		$this->manifest_path = $this->work . '/manifest';
		if ( ! mkdir( $this->work, 0700 ) ) {
			throw new ArchiveException( 'Could not prepare archive storage.' );
		}
	}

	/**
	 * Copy or decrypt the manifest into the work file.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function extract_manifest() {
		$this->seek( $this->header['manifest_offset'] );
		$out = Stream::open( $this->manifest_path, 'wb' );
		try {
			if ( null !== $this->key ) {
				Crypto::decrypt_handle( $this->handle, $out, $this->key, $this->header['manifest_plain'], null );
			} else {
				if ( $this->header['manifest_plain'] !== $this->header['manifest_stored'] ) {
					throw new ArchiveException( 'The archive manifest length is inconsistent.' );
				}
				Stream::copy_bytes( $this->handle, $out, $this->header['manifest_stored'], null, null );
			}
		} finally {
			fclose( $out );
		}
		$position = ftell( $this->handle );
		$expected = $this->header['manifest_offset'] + $this->header['manifest_stored'];
		$plain    = filesize( $this->manifest_path );
		if ( (int) $position !== $expected || (int) $plain !== $this->header['manifest_plain'] ) {
			throw new ArchiveException( 'Could not decrypt this archive. Check the password.' );
		}
	}

	/**
	 * Check the summary against the header.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function accept_summary() {
		$summary  = $this->summary;
		$required = array( 'format', 'version', 'type', 'archive_size', 'contents', 'part', 'encrypted', 'created_at', 'plugin_version' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $summary ) ) {
				throw new ArchiveException( 'The archive manifest is missing a field.' );
			}
		}
		if ( 'bmx' !== $summary['format'] || Format::VERSION !== (int) $summary['version'] ) {
			throw new ArchiveException( 'This archive manifest is not a supported BrightMigrate manifest.' );
		}
		if ( ! in_array( $summary['type'], array( 'full', 'content', 'themekit' ), true ) ) {
			throw new ArchiveException( 'The archive type is not supported.' );
		}
		if ( ! is_array( $summary['contents'] ) || ! is_array( $summary['part'] ) || ! is_array( $summary['encrypted'] ) ) {
			throw new ArchiveException( 'The archive manifest is missing a field.' );
		}
		Format::parse_digits( $summary['archive_size'] );
		Format::parse_digits( $summary['contents']['bytes'] );
		if ( (int) $summary['part']['index'] !== $this->header['part_index'] || (bool) $summary['part']['final'] !== $this->header['final'] ) {
			throw new ArchiveException( 'The archive part header does not match its manifest.' );
		}
		$flags    = $this->header['flags'];
		$manifest = Format::FLAG_ENCRYPTED_MANIFEST === ( $flags & Format::FLAG_ENCRYPTED_MANIFEST );
		$files    = Format::FLAG_ENCRYPTED_FILES === ( $flags & Format::FLAG_ENCRYPTED_FILES );
		if ( $manifest !== (bool) $summary['encrypted']['manifest'] || $files !== (bool) $summary['encrypted']['files'] ) {
			throw new ArchiveException( 'The archive encryption flags do not match its manifest.' );
		}
	}

	/**
	 * Hash everything before the trailer and compare it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function verify_container() {
		$this->seek( 0 );
		$checksum = new Checksum();
		$left     = $this->header['trailer_offset'];
		while ( $left > 0 ) {
			$want  = $left > Format::BUFFER_BYTES ? Format::BUFFER_BYTES : $left;
			$chunk = fread( $this->handle, $want );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				throw new ArchiveException( 'The archive ended before its trailer.' );
			}
			$checksum->update( $chunk );
			$left -= strlen( $chunk );
		}
		$done    = $checksum->finish();
		$trailer = Format::unpack_trailer( Stream::read_exact( $this->handle, Format::TRAILER_BYTES ) );
		if ( ! hash_equals( $trailer['sha256'], $done['sha_hex'] ) || ! hash_equals( $trailer['crc32'], $done['crc_hex'] ) ) {
			throw new ArchiveException( 'The archive checksum does not match.' );
		}
		if ( $trailer['total_size'] !== $this->file_size() || $trailer['part_index'] !== $this->header['part_index'] || $trailer['final'] !== $this->header['final'] ) {
			throw new ArchiveException( 'The archive trailer does not match its header.' );
		}
		$declared = Format::parse_digits( $this->summary['archive_size'] );
		if ( $declared !== $this->file_size() ) {
			throw new ArchiveException( 'The archive manifest size does not match the file.' );
		}
	}

	/**
	 * Check entry counts and checksums.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function count_entries() {
		$files = 0;
		$dbs   = 0;
		$bytes = 0;
		$this->each(
			function ( $entry ) use ( &$files, &$dbs, &$bytes ) {
				$this->read_entry_plaintext( $entry, null );
				if ( 'database' === $entry['kind'] ) {
					$dbs++;
				} else {
					$files++;
				}
				$bytes += Format::parse_digits( $entry['size'] );
				Format::assert_offset( $bytes );
			}
		);
		$contents       = $this->summary['contents'];
		$declared_files = (int) $contents['files'];
		$declared_dbs   = (int) $contents['database_parts'];
		$declared_bytes = Format::parse_digits( $contents['bytes'] );
		if ( $declared_files !== $files || $declared_dbs !== $dbs || $declared_bytes !== $bytes ) {
			throw new ArchiveException( 'The archive manifest does not match its entries.' );
		}
	}

	/**
	 * Write one entry under $root.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $entry Manifest entry.
	 * @param string $root  Destination directory.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function extract_entry( array $entry, $root ) {
		$dest = PathGuard::destination( $entry['path'], $root );
		$dir  = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			$created = mkdir( $dir, 0755, true );
			if ( ! $created && ! is_dir( $dir ) ) {
				throw new ArchiveException( 'Could not create a folder for an archive entry.' );
			}
		}
		$complete = false;
		$out      = Stream::open( $dest, 'wb' );
		try {
			$this->read_entry_plaintext( $entry, $out );
			$complete = true;
		} finally {
			fclose( $out );
			if ( ! $complete && is_file( $dest ) ) {
				unlink( $dest );
			}
		}
	}

	/**
	 * Read one entry, check its plaintext checksum, and optionally write it.
	 *
	 * @since 1.0.0
	 *
	 * @param array         $entry Manifest entry.
	 * @param resource|null $out   Destination, or null to discard plaintext after hashing.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function read_entry_plaintext( array $entry, $out ) {
		$offset = Format::parse_digits( $entry['offset'] );
		$plain  = Format::parse_digits( $entry['size'] );
		$stored = Format::parse_digits( $entry['stored_size'] );
		$this->seek( $offset );
		$header = $this->read_entry_header();
		if ( $header['path'] !== $entry['path'] || $header['plain_size'] !== $plain || $header['stored_size'] !== $stored ) {
			throw new ArchiveException( 'An archive entry does not match its manifest.' );
		}
		if ( Format::entry_kind( $header['flags'] ) !== $entry['kind'] || Format::entry_is_encrypted( $header['flags'] ) !== (bool) $entry['encrypted'] ) {
			throw new ArchiveException( 'An archive entry does not match its manifest.' );
		}
		if ( ! hash_equals( bin2hex( $header['crc'] ), $entry['crc32'] ) || ! hash_equals( bin2hex( $header['sha'] ), $entry['sha256'] ) ) {
			throw new ArchiveException( 'An archive entry does not match its manifest.' );
		}

		$checksum  = new Checksum();
		$encrypted = Format::entry_is_encrypted( $header['flags'] );
		if ( $encrypted ) {
			if ( null === $this->key ) {
				throw new ArchiveException( 'Could not decrypt this archive. Check the password.' );
			}
			Crypto::decrypt_handle( $this->handle, $out, $this->key, $plain, $checksum );
		} else {
			$left = $stored;
			while ( $left > 0 ) {
				$want  = $left > Format::BUFFER_BYTES ? Format::BUFFER_BYTES : $left;
				$chunk = fread( $this->handle, $want );
				if ( ! is_string( $chunk ) || '' === $chunk ) {
					throw new ArchiveException( 'The archive ended in the middle of an entry.' );
				}
				$checksum->update( $chunk );
				if ( null !== $out ) {
					Stream::write_all( $out, $chunk );
				}
				$left -= strlen( $chunk );
			}
		}
		$done = $checksum->finish();
		if ( ! hash_equals( $entry['sha256'], $done['sha_hex'] ) || ! hash_equals( $entry['crc32'], $done['crc_hex'] ) ) {
			throw new ArchiveException( 'An archive entry failed its checksum.' );
		}
	}

	/**
	 * Read the entry header at the current position.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function read_entry_header() {
		$prefix = Stream::read_exact( $this->handle, Format::ENTRY_PREFIX_BYTES );
		$parsed = unpack( 'vlen', substr( $prefix, 60, 2 ) );
		$length = (int) $parsed['len'];
		if ( $length < 1 || $length > 4096 ) {
			throw new ArchiveException( 'An archive entry header is invalid.' );
		}
		return Format::unpack_entry_header( $prefix . Stream::read_exact( $this->handle, $length ) );
	}

	/**
	 * Seek to an absolute offset.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Byte offset.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function seek( $offset ) {
		Format::assert_offset( $offset );
		if ( 0 !== fseek( $this->handle, $offset, SEEK_SET ) ) {
			throw new ArchiveException( 'Could not seek inside the archive.' );
		}
	}
}
