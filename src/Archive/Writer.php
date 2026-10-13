<?php
/**
 * Builds a .bmx archive.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Streams files into one or more .bmx parts.
 *
 * Payloads are copied in 512 KB buffers. A password encrypts the manifest
 * and database entries. File bytes stay plaintext unless encrypt_files is set.
 * The password is discarded after the key is derived.
 *
 * @since 1.0.0
 */
class Writer {

	/**
	 * Final path requested by the caller. Split parts are numbered beside it.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $destination;

	/**
	 * Directory that holds the part being built.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $work = '';

	/**
	 * Open payload handle for the current part.
	 *
	 * @since 1.0.0
	 *
	 * @var resource|null
	 */
	private $payload = null;

	/**
	 * Open index handle for the current part.
	 *
	 * @since 1.0.0
	 *
	 * @var resource|null
	 */
	private $index = null;

	/**
	 * Payload path.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $payload_path = '';

	/**
	 * Index path.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $index_path = '';

	/**
	 * Manifest path.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $manifest_path = '';

	/**
	 * Bytes written to the current payload, including entry headers.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $payload_size = 0;

	/**
	 * File entries in the current part.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $file_count = 0;

	/**
	 * Database entries in the current part.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $db_count = 0;

	/**
	 * Plaintext bytes in the current part.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $byte_count = 0;

	/**
	 * One-based part number currently being built.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $part_index = 1;

	/**
	 * Assembled part files still inside the work directory.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	private $sealed = array();

	/**
	 * SHA-256 of archive paths already added.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, bool>
	 */
	private $seen = array();

	/**
	 * Archive type: full, content, or themekit.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $type = 'full';

	/**
	 * Split after this many payload bytes. Zero disables splitting.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $split_bytes = 0;

	/**
	 * Encrypt file payloads as well as the manifest and database.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $encrypt_files = false;

	/**
	 * Derived key, or null when the archive has no password.
	 *
	 * @since 1.0.0
	 *
	 * @var string|null
	 */
	private $key = null;

	/**
	 * 16-byte salt. Empty when the archive has no password.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	private $salt = '';

	/**
	 * Unix time stored in every part.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	private $created = 0;

	/**
	 * Summary strings.
	 *
	 * @since 1.0.0
	 *
	 * @var array<string, string>
	 */
	private $meta = array();

	/**
	 * Whether finish() has published the parts.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $finished = false;

	/**
	 * The work directory was discarded after an error or an unfinished writer.
	 *
	 * @since 1.0.0
	 *
	 * @var bool
	 */
	private $aborted = false;

	/**
	 * Prepare a new archive at $destination.
	 *
	 * @since 1.0.0
	 *
	 * @param string $destination Local path ending in .bmx when parts may be split.
	 * @param array  $options     password, encrypt_files, split_bytes, type, and manifest strings.
	 */
	public function __construct( $destination, array $options = array() ) {
		$this->prepare_options( $destination, $options );
		$this->make_work();
		$this->open_part();
	}

	/**
	 * Delete an unfinished archive.
	 *
	 * @since 1.0.0
	 */
	public function __destruct() {
		$this->abort();
	}

	/**
	 * Add a normal file.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source       Readable file. Symlinks are rejected.
	 * @param string $archive_path Relative path inside the archive.
	 * @return void
	 */
	public function add_file( $source, $archive_path ) {
		$this->add_disk( $source, $archive_path, 'file' );
	}

	/**
	 * Add a database dump. It is encrypted when the archive has a password.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source       Readable file. Symlinks are rejected.
	 * @param string $archive_path Relative path inside the archive.
	 * @return void
	 */
	public function add_database_file( $source, $archive_path ) {
		$this->add_disk( $source, $archive_path, 'database' );
	}

	/**
	 * Add an open stream. The caller keeps ownership of the handle.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle       Open read handle, consumed until EOF.
	 * @param string   $archive_path Relative path inside the archive.
	 * @param string   $kind         file or database.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public function add_stream( $handle, $archive_path, $kind ) {
		try {
			$this->append_entry( $handle, $archive_path, $kind );
		} catch ( ArchiveException $exception ) {
			$this->abort();
			throw $exception;
		}
	}

	/**
	 * Write the last part and return every published path.
	 *
	 * A single part keeps $destination. Split parts use name.001.bmx and so on.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public function finish() {
		if ( $this->finished || $this->aborted ) {
			throw new ArchiveException( 'This archive is already closed.' );
		}
		try {
			$this->seal_part( true );
			$paths = $this->publish();
		} catch ( ArchiveException $exception ) {
			$this->abort();
			throw $exception;
		}
		$this->remove_work();
		$this->finished = true;
		return $paths;
	}

	/**
	 * Validate options and drop the password after key derivation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $destination Destination path.
	 * @param array  $options     Writer options.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function prepare_options( $destination, array $options ) {
		if ( ! is_string( $destination ) || '' === $destination || false !== strpos( $destination, '://' ) || false !== strpos( $destination, "\0" ) ) {
			throw new ArchiveException( 'The archive destination is not a local file.' );
		}
		$parent = dirname( $destination );
		if ( is_link( $parent ) || ! is_dir( $parent ) || is_file( $destination ) ) {
			throw new ArchiveException( 'The archive destination is not a local file.' );
		}
		$this->destination = $destination;

		$type = isset( $options['type'] ) ? (string) $options['type'] : 'full';
		if ( ! in_array( $type, array( 'full', 'content', 'themekit' ), true ) ) {
			throw new ArchiveException( 'The archive type is not supported.' );
		}
		$this->type = $type;

		$split = isset( $options['split_bytes'] ) ? (int) $options['split_bytes'] : 0;
		if ( $split < 0 ) {
			throw new ArchiveException( 'The split size is not usable.' );
		}
		$this->split_bytes   = $split;
		$this->encrypt_files = ! empty( $options['encrypt_files'] );

		$password = isset( $options['password'] ) ? (string) $options['password'] : '';
		if ( $this->encrypt_files && '' === $password ) {
			throw new ArchiveException( 'File encryption needs an archive password.' );
		}
		if ( '' !== $password ) {
			if ( ! Crypto::available() ) {
				throw new ArchiveException( 'Password protection needs the PHP openssl extension.' );
			}
			$this->salt = random_bytes( 16 );
			$this->key  = Crypto::key( $password, $this->salt, Format::KDF_ITERATIONS );
			$password   = '';
		} else {
			$this->key  = null;
			$this->salt = '';
		}

		$created = isset( $options['created'] ) ? (int) $options['created'] : time();
		if ( $created < 0 ) {
			$created = time();
		}
		$this->created = $created;

		$plugin = '1.0.0';
		if ( defined( 'BMX_VERSION' ) ) {
			$plugin = (string) BMX_VERSION;
		}
		$php_version = PHP_VERSION;
		if ( strlen( $php_version ) > 32 ) {
			$php_version = substr( $php_version, 0, 32 );
		}
		$this->meta = array(
			'source_url'     => $this->text_option( $options, 'source_url', '', 2000 ),
			'home_url'       => $this->text_option( $options, 'home_url', '', 2000 ),
			'wp_version'     => $this->text_option( $options, 'wp_version', '', 32 ),
			'php_version'    => $this->text_option( $options, 'php_version', $php_version, 32 ),
			'plugin_version' => $this->text_option( $options, 'plugin_version', $plugin, 32 ),
			'table_prefix'   => $this->text_option( $options, 'table_prefix', 'wp_', 64 ),
		);
	}

	/**
	 * Read a bounded manifest string.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $options  Writer options.
	 * @param string $key      Option name.
	 * @param string $fallback Value when the option is absent.
	 * @param int    $max      Maximum length.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function text_option( array $options, $key, $fallback, $max ) {
		$value = $fallback;
		if ( isset( $options[ $key ] ) && is_string( $options[ $key ] ) ) {
			$value = $options[ $key ];
		}
		if ( strlen( $value ) > $max || false !== strpos( $value, "\n" ) || false !== strpos( $value, "\r" ) ) {
			throw new ArchiveException( 'An archive detail is too long.' );
		}
		return $value;
	}

	/**
	 * Create the work directory beside the destination.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function make_work() {
		try {
			$suffix = bin2hex( random_bytes( 4 ) );
		} catch ( \Exception $exception ) {
			throw new ArchiveException( 'Could not prepare archive storage.' );
		}
		$this->work = $this->destination . '.work-' . $suffix;
		if ( is_dir( $this->work ) || ! mkdir( $this->work, 0700 ) ) {
			throw new ArchiveException( 'Could not prepare archive storage.' );
		}
	}

	/**
	 * Open empty payload and index files for a new part.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function open_part() {
		$this->payload_size = 0;
		$this->file_count   = 0;
		$this->db_count     = 0;
		$this->byte_count   = 0;
		$this->payload_path = $this->work . '/payload';
		$this->index_path   = $this->work . '/index';
		$this->payload      = Stream::open( $this->payload_path, 'wb' );
		$this->index        = Stream::open( $this->index_path, 'wb' );
	}

	/**
	 * Copy a local file into the archive.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source       Filesystem path.
	 * @param string $archive_path Archive path.
	 * @param string $kind         file or database.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function add_disk( $source, $archive_path, $kind ) {
		if ( ! is_string( $source ) || '' === $source || is_link( $source ) || ! is_file( $source ) ) {
			throw new ArchiveException( 'Cannot add that file to the archive.' );
		}
		$handle = Stream::open( $source, 'rb' );
		try {
			$this->add_stream( $handle, $archive_path, $kind );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Append one entry to the current part, rolling over when the split size is reached.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $handle       Source data.
	 * @param string   $archive_path Relative path.
	 * @param string   $kind         file or database.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function append_entry( $handle, $archive_path, $kind ) {
		if ( $this->finished || $this->aborted || ! is_resource( $handle ) || ! is_resource( $this->payload ) ) {
			throw new ArchiveException( 'This archive is already closed.' );
		}
		if ( 'file' !== $kind && 'database' !== $kind ) {
			throw new ArchiveException( 'Unknown archive entry type.' );
		}

		$path = PathGuard::archive_path( $archive_path );
		if ( 'content' === $this->type && ! PathGuard::allows_executable( $path, 'content' ) ) {
			throw new ArchiveException( 'A content pack cannot include that executable file.' );
		}
		$hash = hash( 'sha256', $path );
		if ( isset( $this->seen[ $hash ] ) ) {
			throw new ArchiveException( 'That path is already in the archive.' );
		}
		if ( $this->split_bytes > 0 && $this->payload_size >= $this->split_bytes ) {
			$this->seal_part( false );
		}

		$encrypt = ( null !== $this->key ) && ( 'database' === $kind || $this->encrypt_files );
		$flags   = 'database' === $kind ? Format::ENTRY_DATABASE : Format::ENTRY_FILE;
		if ( $encrypt ) {
			$flags |= Format::ENTRY_ENCRYPTED;
		}

		$header_len = Format::ENTRY_PREFIX_BYTES + strlen( $path );
		$relative   = $this->payload_size;
		$this->seek_payload( $relative );
		Stream::write_all( $this->payload, str_repeat( "\0", $header_len ) );
		$this->payload_size += $header_len;
		Format::assert_offset( $this->payload_size );

		$checksum = new Checksum();
		if ( $encrypt ) {
			$counts = Crypto::encrypt_handle( $handle, $this->payload, $this->key, $checksum );
			$plain  = $counts['plain'];
			$stored = $counts['stored'];
		} else {
			$plain  = $this->copy_plain( $handle, $checksum );
			$stored = $plain;
		}
		$done                = $checksum->finish();
		$this->payload_size += $stored;
		Format::assert_offset( $this->payload_size );

		$header = Format::pack_entry_header( $path, $flags, $plain, $stored, $done['crc'], $done['sha'] );
		if ( strlen( $header ) !== $header_len ) {
			throw new ArchiveException( 'An archive entry header is the wrong length.' );
		}
		$end = $this->payload_size;
		$this->seek_payload( $relative );
		Stream::write_all( $this->payload, $header );
		$this->seek_payload( $end );

		$record = array(
			'path'      => $path,
			'kind'      => $kind,
			'plain'     => $plain,
			'stored'    => $stored,
			'relative'  => $relative,
			'crc'       => $done['crc_hex'],
			'sha'       => $done['sha_hex'],
			'encrypted' => $encrypt,
		);
		Stream::write_all( $this->index, Manifest::encode( $record ) . "\n" );
		$this->seen[ $hash ] = true;
		if ( 'database' === $kind ) {
			++$this->db_count;
		} else {
			++$this->file_count;
		}
		$this->byte_count += $plain;
		Format::assert_offset( $this->byte_count );
	}

	/**
	 * Copy plaintext into the payload and hash it.
	 *
	 * @since 1.0.0
	 *
	 * @param resource $source   Source handle.
	 * @param Checksum $checksum Running plaintext checksum.
	 * @return int
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function copy_plain( $source, $checksum ) {
		$size = 0;
		while ( ! feof( $source ) ) {
			$chunk = fread( $source, Format::BUFFER_BYTES );
			if ( false === $chunk ) {
				throw new ArchiveException( 'Could not read a file into the archive.' );
			}
			if ( '' === $chunk ) {
				break;
			}
			$checksum->update( $chunk );
			Stream::write_all( $this->payload, $chunk );
			$size += strlen( $chunk );
			Format::assert_offset( $size );
		}
		return $size;
	}

	/**
	 * Seek within the payload.
	 *
	 * @since 1.0.0
	 *
	 * @param int $offset Byte offset.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function seek_payload( $offset ) {
		Format::assert_offset( $offset );
		if ( ! fflush( $this->payload ) || 0 !== fseek( $this->payload, $offset, SEEK_SET ) ) {
			throw new ArchiveException( 'Could not seek inside the archive.' );
		}
	}

	/**
	 * Finish the current part. A non-final part starts the next one.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_final Whether this is the last part.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function seal_part( $is_final ) {
		if ( ! $is_final && $this->part_index >= 999 ) {
			throw new ArchiveException( 'This archive has too many parts.' );
		}
		$this->close_handles();
		$layout = $this->write_manifest( $is_final );
		$this->assemble( $is_final, $layout );
		$this->delete_build_files();
		++$this->part_index;
		if ( ! $is_final ) {
			$this->open_part();
		}
	}

	/**
	 * Write the manifest twice so fixed-width sizes can be filled in.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_final Whether this is the last part.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function write_manifest( $is_final ) {
		$this->manifest_path = $this->work . '/manifest';
		$this->emit_manifest( 0, 0, $is_final );
		$plain = filesize( $this->manifest_path );
		if ( false === $plain ) {
			throw new ArchiveException( 'Could not write the archive manifest.' );
		}
		$plain = (int) $plain;
		Format::assert_offset( $plain );
		$stored  = null !== $this->key ? Crypto::stored_length( $plain ) : $plain;
		$entries = Format::HEADER_BYTES + $stored;
		Format::assert_offset( $entries );
		$trailer = $entries + $this->payload_size;
		Format::assert_offset( $trailer );
		$total = $trailer + Format::TRAILER_BYTES;
		Format::assert_offset( $total );
		$this->emit_manifest( $entries, $total, $is_final );
		$again = filesize( $this->manifest_path );
		if ( (int) $again !== $plain ) {
			throw new ArchiveException( 'The archive manifest length changed.' );
		}
		return array(
			'plain'   => $plain,
			'stored'  => $stored,
			'entries' => $entries,
			'trailer' => $trailer,
			'total'   => $total,
		);
	}

	/**
	 * Stream the summary line and one JSON line per entry.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $base_offset  Absolute offset added to each payload-relative offset.
	 * @param int  $archive_size Total part size, including the trailer.
	 * @param bool $is_final        Whether this is the last part.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function emit_manifest( $base_offset, $archive_size, $is_final ) {
		$out = Stream::open( $this->manifest_path, 'wb' );
		try {
			Stream::write_all( $out, Manifest::encode( $this->summary_values( $archive_size, $is_final ) ) . "\n" );
			$index = Stream::open( $this->index_path, 'rb' );
			try {
				while ( true ) {
					$line = fgets( $index, 16384 );
					if ( false === $line ) {
						break;
					}
					if ( "\n" !== substr( $line, -1 ) ) {
						throw new ArchiveException( 'Could not write the archive manifest.' );
					}
					$row      = Manifest::decode_line( rtrim( $line, "\r\n" ) );
					$absolute = $base_offset + (int) $row['relative'];
					Format::assert_offset( $absolute );
					$entry = array(
						'path'        => $row['path'],
						'kind'        => $row['kind'],
						'size'        => Format::digits( (int) $row['plain'] ),
						'stored_size' => Format::digits( (int) $row['stored'] ),
						'offset'      => Format::digits( $absolute ),
						'crc32'       => $row['crc'],
						'sha256'      => $row['sha'],
						'encrypted'   => (bool) $row['encrypted'],
					);
					Stream::write_all( $out, Manifest::encode( $entry ) . "\n" );
				}
			} finally {
				fclose( $index );
			}
		} finally {
			fclose( $out );
		}
	}

	/**
	 * Summary object stored on the first manifest line.
	 *
	 * @since 1.0.0
	 *
	 * @param int  $archive_size Total part size.
	 * @param bool $is_final        Whether this is the last part.
	 * @return array
	 */
	private function summary_values( $archive_size, $is_final ) {
		return array(
			'format'         => 'bmx',
			'version'        => Format::VERSION,
			'type'           => $this->type,
			'source_url'     => $this->meta['source_url'],
			'home_url'       => $this->meta['home_url'],
			'wp_version'     => $this->meta['wp_version'],
			'php_version'    => $this->meta['php_version'],
			'plugin_version' => $this->meta['plugin_version'],
			'created_at'     => gmdate( 'c', $this->created ),
			'archive_size'   => Format::digits( $archive_size ),
			'table_prefix'   => $this->meta['table_prefix'],
			'contents'       => array(
				'files'          => $this->file_count,
				'database_parts' => $this->db_count,
				'bytes'          => Format::digits( $this->byte_count ),
			),
			'part'           => array(
				'index' => $this->part_index,
				'final' => $is_final,
			),
			'encrypted'      => array(
				'manifest' => null !== $this->key,
				'database' => null !== $this->key,
				'files'    => null !== $this->key && $this->encrypt_files,
			),
		);
	}

	/**
	 * Assemble header, manifest, payload, and trailer.
	 *
	 * @since 1.0.0
	 *
	 * @param bool  $is_final  Whether this is the last part.
	 * @param array $layout Sizes from write_manifest().
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function assemble( $is_final, array $layout ) {
		$part_path = $this->work . '/part-' . $this->part_index;
		$out       = Stream::open( $part_path, 'wb' );
		$checksum  = new Checksum();
		try {
			$header = Format::pack_header(
				array(
					'flags'           => $this->header_flags( $is_final ),
					'created'         => $this->created,
					'part_index'      => $this->part_index,
					'final'           => $is_final,
					'iterations'      => null !== $this->key ? Format::KDF_ITERATIONS : 0,
					'salt'            => $this->salt,
					'manifest_offset' => Format::HEADER_BYTES,
					'manifest_stored' => $layout['stored'],
					'manifest_plain'  => $layout['plain'],
					'entries_offset'  => $layout['entries'],
					'trailer_offset'  => $layout['trailer'],
					'payload_length'  => $this->payload_size,
				)
			);
			Stream::write_all( $out, $header );
			$checksum->update( $header );
			if ( null !== $this->key ) {
				$encrypted = $this->work . '/manifest.enc';
				$written   = Crypto::encrypt_file( $this->manifest_path, $encrypted, $this->key );
				if ( $written !== $layout['stored'] ) {
					throw new ArchiveException( 'Could not encrypt the archive manifest.' );
				}
				$this->append_file( $encrypted, $out, $layout['stored'], $checksum );
				unlink( $encrypted );
			} else {
				$this->append_file( $this->manifest_path, $out, $layout['plain'], $checksum );
			}
			if ( $this->payload_size > 0 ) {
				$this->append_file( $this->payload_path, $out, $this->payload_size, $checksum );
			}
			$done    = $checksum->finish();
			$trailer = Format::pack_trailer( $layout['total'], $done['crc'], $done['sha'], $this->part_index, $is_final );
			Stream::write_all( $out, $trailer );
		} finally {
			fclose( $out );
		}
		$on_disk = filesize( $part_path );
		if ( (int) $on_disk !== $layout['total'] ) {
			throw new ArchiveException( 'The archive part is the wrong size.' );
		}
		$this->sealed[] = $part_path;
	}

	/**
	 * Header flags for this part.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_final Whether this is the last part.
	 * @return int
	 */
	private function header_flags( $is_final ) {
		$flags = 0;
		if ( null !== $this->key ) {
			$flags |= Format::FLAG_ENCRYPTED_MANIFEST;
			$flags |= Format::FLAG_ENCRYPTED_DATABASE;
			if ( $this->encrypt_files ) {
				$flags |= Format::FLAG_ENCRYPTED_FILES;
			}
		}
		if ( ! $is_final || $this->part_index > 1 ) {
			$flags |= Format::FLAG_SPLIT;
		}
		return $flags;
	}

	/**
	 * Copy a file into the archive while hashing the stored bytes.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $path     Source path.
	 * @param resource $out      Destination handle.
	 * @param int      $bytes    Exact byte count.
	 * @param Checksum $checksum Running archive checksum.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function append_file( $path, $out, $bytes, $checksum ) {
		$in = Stream::open( $path, 'rb' );
		try {
			$left = $bytes;
			Format::assert_offset( $left );
			while ( $left > 0 ) {
				$want  = $left > Format::BUFFER_BYTES ? Format::BUFFER_BYTES : $left;
				$chunk = fread( $in, $want );
				if ( ! is_string( $chunk ) || '' === $chunk ) {
					throw new ArchiveException( 'Could not copy an archive part.' );
				}
				$checksum->update( $chunk );
				Stream::write_all( $out, $chunk );
				$left -= strlen( $chunk );
			}
		} finally {
			fclose( $in );
		}
	}

	/**
	 * Move sealed parts to their public names.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private function publish() {
		$paths = array();
		if ( 1 === count( $this->sealed ) ) {
			$this->move_file( $this->sealed[0], $this->destination );
			$paths[] = $this->destination;
		} else {
			$index = 1;
			foreach ( $this->sealed as $temp ) {
				$dest = $this->numbered_path( $index );
				$this->move_file( $temp, $dest );
				$paths[] = $dest;
				++$index;
			}
		}
		$this->sealed = array();
		return $paths;
	}

	/**
	 * Part path name.001.bmx beside the requested destination.
	 *
	 * @since 1.0.0
	 *
	 * @param int $index One-based part number.
	 * @return string
	 */
	private function numbered_path( $index ) {
		$suffix = sprintf( '.%03d.bmx', $index );
		if ( '.bmx' === substr( $this->destination, -4 ) ) {
			return substr( $this->destination, 0, -4 ) . $suffix;
		}
		return $this->destination . $suffix;
	}

	/**
	 * Rename a finished part into place.
	 *
	 * @since 1.0.0
	 *
	 * @param string $from Work path.
	 * @param string $to   Public path.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private function move_file( $from, $to ) {
		if ( is_file( $to ) ) {
			throw new ArchiveException( 'The archive destination already exists.' );
		}
		if ( ! rename( $from, $to ) ) {
			throw new ArchiveException( 'Could not write the archive.' );
		}
	}

	/**
	 * Remove payload, index, and manifest files for the part just sealed.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function delete_build_files() {
		foreach ( array( $this->payload_path, $this->index_path, $this->manifest_path ) as $path ) {
			if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
				unlink( $path );
			}
		}
	}

	/**
	 * Close payload and index handles.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function close_handles() {
		if ( is_resource( $this->payload ) ) {
			fclose( $this->payload );
		}
		if ( is_resource( $this->index ) ) {
			fclose( $this->index );
		}
		$this->payload = null;
		$this->index   = null;
	}

	/**
	 * Drop the work directory unless finish() already published the archive.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function abort() {
		if ( $this->finished || $this->aborted ) {
			return;
		}
		$this->aborted = true;
		$this->close_handles();
		$this->remove_work();
		$this->sealed = array();
	}

	/**
	 * Delete the work directory.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function remove_work() {
		if ( ! is_string( $this->work ) || ! is_dir( $this->work ) ) {
			return;
		}
		$handle = opendir( $this->work );
		if ( false === $handle ) {
			return;
		}
		$name = readdir( $handle );
		while ( false !== $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $this->work . '/' . $name;
				if ( is_file( $path ) || is_link( $path ) ) {
					unlink( $path );
				}
			}
			$name = readdir( $handle );
		}
		closedir( $handle );
		rmdir( $this->work );
	}
}
