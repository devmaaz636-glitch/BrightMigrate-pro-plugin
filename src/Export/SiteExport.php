<?php
/**
 * Chunked full-site export.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Export;

use BrightIdeas\Migrate\Archive\ArchiveException;
use BrightIdeas\Migrate\Archive\Format;
use BrightIdeas\Migrate\Archive\Reader;
use BrightIdeas\Migrate\Archive\Stream;
use BrightIdeas\Migrate\Archive\Writer;
use BrightIdeas\Migrate\Core\Format as SizeFormat;
use BrightIdeas\Migrate\Core\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a .bmx of this site's database and wp-content across short requests.
 *
 * There is no size cap. Each request stops after about eight seconds.
 *
 * @since 1.0.0
 */
class SiteExport {

	/**
	 * Excludes the export screen is allowed to send.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const EXCLUDES = array(
		'media',
		'themes',
		'inactive_themes',
		'plugins',
		'inactive_plugins',
		'mu_plugins',
		'cache',
		'spam',
		'revisions',
		'database',
	);

	/**
	 * Directory names that are never archived.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const SKIP_DIRS = array(
		'node_modules',
		'.git',
		'ai1wm-backups',
		'updraft',
		'upgrade',
		'backups',
		'backup',
	);

	/**
	 * Directory names skipped when the cache exclude is on.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const CACHE_DIRS = array(
		'cache',
		'litespeed',
	);

	/**
	 * Create a job and return its id.
	 *
	 * @since 1.0.0
	 *
	 * @param array $excludes Allow-listed exclude keys.
	 * @param array $pairs    Find/replace pairs.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function start( array $excludes, array $pairs ) {
		self::ready();
		try {
			$id = bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			throw new ArchiveException( 'Could not start the export.' );
		}

		$dir   = self::job_dir( $id, true );
		$now   = gmdate( 'Ymd-His' );
		$state = array(
			'id'            => $id,
			'status'        => 'running',
			'stage'         => 'scan',
			'excludes'      => array_values( $excludes ),
			'pairs'         => self::pairs( $pairs ),
			'root'          => WP_CONTENT_DIR,
			'stack'         => array( WP_CONTENT_DIR ),
			'current_dir'   => '',
			'skip'          => 0,
			'files_total'   => 0,
			'files_done'    => 0,
			'list_offset'   => 0,
			'tables'        => array(),
			'table_index'   => 0,
			'pk'            => '',
			'pk_value'      => '',
			'offset'        => 0,
			'table_started' => false,
			'writer_work'   => '',
			'sql_packed'    => false,
			'destination'   => Storage::active_path() . '/brightmigrate-full-' . $now . '-' . $id . '.bmx',
			'sql'           => $dir . '/database.sql',
			'list'          => $dir . '/files.txt',
			'bytes_done'    => 0,
			'file_name'     => '',
			'context'       => self::context(),
			'started_at'    => microtime( true ),
			'message'       => __( 'Listing files…', 'brightmigrate-pro' ),
			'progress'      => 1,
		);
		self::write_state( $dir, $state );
		return $id;
	}

	/**
	 * Run one slice of a job.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id       Job id.
	 * @param string $password Archive password for this request only.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function tick( $id, $password ) {
		$dir  = self::job_dir( $id, false );
		$lock = self::lock( $dir );
		if ( null === $lock ) {
			return self::view( self::read_state( $dir ) );
		}

		$state = self::read_state( $dir );
		try {
			if ( 'running' === $state['status'] ) {
				try {
					self::advance( $state, microtime( true ) + 8, $password );
				} catch ( ArchiveException $exception ) {
					$state['status']  = 'error';
					$state['message'] = $exception->getMessage();
				}
				self::mark_progress( $state );
				self::write_state( $dir, $state );
			}
			$view = self::view( $state );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}

		if ( 'done' === $state['status'] || 'error' === $state['status'] ) {
			if ( 'error' === $state['status'] && is_string( $state['writer_work'] ) ) {
				self::remove_tree( $state['writer_work'] );
			}
			self::remove_tree( $dir );
		}
		return $view;
	}

	/**
	 * Stop a job and delete its temporary files.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function cancel( $id ) {
		$dir = self::job_dir( $id, false );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$state = self::read_state( $dir );
		if ( '' !== $state['writer_work'] ) {
			self::remove_tree( $state['writer_work'] );
		}
		self::remove_tree( $dir );
	}

	/**
	 * Archives sitting in the storage folder.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function archives() {
		$dir  = Storage::active_path();
		$rows = array();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return $rows;
		}
		$handle = opendir( $dir );
		if ( false === $handle ) {
			return $rows;
		}
		$name = readdir( $handle );
		while ( false !== $name ) {
			if ( 1 === preg_match( '/^[A-Za-z0-9._-]+\.bmx$/', $name ) ) {
				$path = $dir . '/' . $name;
				if ( is_file( $path ) && ! is_link( $path ) ) {
					$size   = filesize( $path );
					$mtime  = filemtime( $path );
					$rows[] = array(
						'name' => $name,
						'size' => false === $size ? 0 : $size,
						'date' => false === $mtime ? 0 : $mtime,
						'url'  => self::download_url( $name ),
					);
				}
			}
			$name = readdir( $handle );
		}
		closedir( $handle );
		usort(
			$rows,
			static function ( $left, $right ) {
				if ( $left['date'] === $right['date'] ) {
					return 0;
				}
				return ( $left['date'] < $right['date'] ) ? 1 : -1;
			}
		);
		return $rows;
	}

	/**
	 * Send one archive from storage. The caller has already checked permission.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name, not a path.
	 * @return void
	 */
	public static function stream( $name ) {
		$name = basename( (string) $name );
		$path = self::archive_path( $name );
		if ( '' === $path ) {
			wp_die(
				esc_html__( 'That archive is not available.', 'brightmigrate-pro' ),
				esc_html__( 'Archive', 'brightmigrate-pro' ),
				array( 'response' => 404 )
			);
		}

		$handle = Stream::open( $path, 'rb' );
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		$size = filesize( $path );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, Format::BUFFER_BYTES );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				break;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary archive bytes, not HTML.
			echo $chunk;
		}
		fclose( $handle );
		exit;
	}

	/**
	 * Append one uploaded chunk. The last chunk is opened and summarized.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id       Upload id, or empty to begin.
	 * @param int    $index    Zero-based chunk index.
	 * @param int    $total    Chunk count.
	 * @param string $tmp      Uploaded temp file.
	 * @param string $password Password for an encrypted archive.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function accept_chunk( $id, $index, $total, $tmp, $password ) {
		self::ready();
		if ( $index < 0 || $total < 1 || $index >= $total ) {
			throw new ArchiveException( 'That upload chunk is not valid.' );
		}
		if ( ! is_string( $tmp ) || ! is_uploaded_file( $tmp ) ) {
			throw new ArchiveException( 'The upload did not arrive.' );
		}
		if ( '' === $id ) {
			if ( 0 !== $index ) {
				throw new ArchiveException( 'That upload chunk is not valid.' );
			}
			try {
				$id = bin2hex( random_bytes( 8 ) );
			} catch ( \Exception $exception ) {
				unset( $exception );
				throw new ArchiveException( 'Could not store the upload.' );
			}
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
			throw new ArchiveException( 'That upload chunk is not valid.' );
		}

		$dir  = self::incoming_dir( $id );
		$meta = $dir . '/meta.json';
		$next = 0;
		if ( is_file( $meta ) ) {
			$decoded = json_decode( (string) file_get_contents( $meta ), true );
			$next    = ( is_array( $decoded ) && isset( $decoded['next'] ) ) ? (int) $decoded['next'] : 0;
		}
		if ( $index !== $next ) {
			throw new ArchiveException( 'That upload chunk is out of order.' );
		}

		$part   = $dir . '/upload.part';
		$source = Stream::open( $tmp, 'rb' );
		$out    = Stream::open( $part, 'ab' );
		try {
			while ( ! feof( $source ) ) {
				$chunk = fread( $source, Format::BUFFER_BYTES );
				if ( ! is_string( $chunk ) || '' === $chunk ) {
					break;
				}
				Stream::write_all( $out, $chunk );
			}
		} finally {
			fclose( $source );
			fclose( $out );
		}

		$encoded = wp_json_encode( array( 'next' => $index + 1 ) );
		$meta_h  = Stream::open( $meta, 'wb' );
		try {
			Stream::write_all( $meta_h, is_string( $encoded ) ? $encoded : '{}' );
		} finally {
			fclose( $meta_h );
		}

		if ( ( $index + 1 ) < $total ) {
			return array(
				'upload_id' => $id,
				'status'    => 'uploading',
				'progress'  => (int) floor( ( ( $index + 1 ) / $total ) * 100 ),
				'message'   => __( 'Uploading…', 'brightmigrate-pro' ),
			);
		}

		$name = 'brightmigrate-import-' . gmdate( 'Ymd-His' ) . '-' . $id . '.bmx';
		$dest = Storage::active_path() . '/' . $name;
		if ( ! rename( $part, $dest ) ) {
			throw new ArchiveException( 'Could not store the upload.' );
		}
		self::remove_tree( $dir );
		$reader = new Reader( $dest, $password );
		try {
			$summary = $reader->summary();
		} finally {
			$reader->close();
		}

		return array(
			'upload_id'      => $id,
			'status'         => 'ready',
			'progress'       => 100,
			'message'        => __( 'The archive is stored. Replacing this site is the next step.', 'brightmigrate-pro' ),
			'file'           => $name,
			'type'           => isset( $summary['type'] ) ? (string) $summary['type'] : '',
			'source_url'     => isset( $summary['source_url'] ) ? (string) $summary['source_url'] : '',
			'files'          => isset( $summary['contents']['files'] ) ? (int) $summary['contents']['files'] : 0,
			'database_parts' => isset( $summary['contents']['database_parts'] ) ? (int) $summary['contents']['database_parts'] : 0,
			'created_at'     => isset( $summary['created_at'] ) ? (string) $summary['created_at'] : '',
		);
	}

	/**
	 * Move the job forward until the time budget runs out.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $state    Job state.
	 * @param float  $deadline Microtime deadline.
	 * @param string $password Archive password.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function advance( array &$state, $deadline, $password ) {
		if ( 'scan' === $state['stage'] ) {
			self::scan( $state, $deadline );
			if ( '' === $state['current_dir'] && array() === $state['stack'] ) {
				$state['stage'] = in_array( 'database', $state['excludes'], true ) ? 'pack' : 'database';
			}
		}
		if ( 'database' === $state['stage'] && microtime( true ) < $deadline ) {
			self::dump( $state, $deadline );
		}
		if ( 'pack' === $state['stage'] && microtime( true ) < $deadline ) {
			self::pack( $state, $deadline, $password );
		}
	}

	/**
	 * List wp-content files into the job list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $state    Job state.
	 * @param float $deadline Microtime deadline.
	 * @return void
	 */
	private static function scan( array &$state, $deadline ) {
		$list = Stream::open( $state['list'], 'ab' );
		try {
			while ( microtime( true ) < $deadline ) {
				if ( '' === $state['current_dir'] ) {
					if ( array() === $state['stack'] ) {
						break;
					}
					$state['current_dir'] = (string) array_pop( $state['stack'] );
					$state['skip']        = 0;
				}
				self::scan_directory( $state, $deadline, $list );
			}
		} finally {
			fclose( $list );
		}
	}

	/**
	 * Read one directory, saving the position when time runs out.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $state    Job state.
	 * @param float    $deadline Microtime deadline.
	 * @param resource $list_handle Open list handle.
	 * @return void
	 */
	private static function scan_directory( array &$state, $deadline, $list_handle ) {
		$dir    = $state['current_dir'];
		$handle = opendir( $dir );
		if ( false === $handle ) {
			$state['current_dir'] = '';
			$state['skip']        = 0;
			return;
		}
		$index = 0;
		$stop  = false;
		$name  = readdir( $handle );
		while ( false !== $name ) {
			++$index;
			if ( $index <= (int) $state['skip'] || '.' === $name || '..' === $name ) {
				$name = readdir( $handle );
				continue;
			}
			if ( microtime( true ) >= $deadline ) {
				$state['skip'] = $index - 1;
				$stop          = true;
				break;
			}
			$full    = $dir . '/' . $name;
			$blocked = array_merge( self::SKIP_DIRS, self::CACHE_DIRS );
			$unsafe  = ( false !== strpos( $name, "\n" ) || false !== strpos( $name, "\r" ) || false !== strpos( $name, "\0" ) );
			if ( ! $unsafe && ! is_link( $full ) && ! in_array( $name, $blocked, true ) ) {
				$relative = self::relative( $state['root'], $full );
				if ( '' !== $relative && ! self::excluded( $relative, $state['excludes'], $state['context'] ) ) {
					if ( is_dir( $full ) ) {
						$state['stack'][] = $full;
					} elseif ( is_file( $full ) ) {
						Stream::write_all( $list_handle, $relative . "\n" );
						++$state['files_total'];
					}
				}
			}
			$name = readdir( $handle );
		}
		closedir( $handle );
		if ( ! $stop ) {
			$state['current_dir'] = '';
			$state['skip']        = 0;
		}
	}

	/**
	 * Write the next database rows.
	 *
	 * @since 1.0.0
	 *
	 * @param array $state    Job state.
	 * @param float $deadline Microtime deadline.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function dump( array &$state, $deadline ) {
		global $wpdb;
		if ( array() === $state['tables'] && 0 === (int) $state['table_index'] && ! $state['table_started'] ) {
			$state['tables'] = self::tables();
		}
		if ( ! is_file( $state['sql'] ) ) {
			$handle = Stream::open( $state['sql'], 'wb' );
			Stream::write_all( $handle, "-- BrightMigrate database dump\n" );
			fclose( $handle );
		}

		$table_count = count( $state['tables'] );
		while ( microtime( true ) < $deadline && (int) $state['table_index'] < $table_count ) {
			$table = (string) $state['tables'][ (int) $state['table_index'] ];
			if ( ! $state['table_started'] ) {
				self::write_table_header( $state, $table );
				$state['table_started'] = true;
				$state['pk']            = self::primary_key( $table );
				$state['pk_value']      = '';
				$state['offset']        = 0;
			}
			$rows = self::fetch_rows( $table, $state );
			if ( array() === $rows ) {
				++$state['table_index'];
				$state['table_started'] = false;
				continue;
			}
			self::write_rows( $state, $table, $rows );
			$last = $rows[ count( $rows ) - 1 ];
			if ( '' !== $state['pk'] && isset( $last[ $state['pk'] ] ) ) {
				$state['pk_value'] = (string) $last[ $state['pk'] ];
			} else {
				$state['offset'] = (int) $state['offset'] + count( $rows );
			}
		}

		if ( (int) $state['table_index'] >= count( $state['tables'] ) ) {
			$state['stage'] = 'pack';
		}
	}

	/**
	 * Add listed files to the archive until time runs out.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $state    Job state.
	 * @param float  $deadline Microtime deadline.
	 * @param string $password Archive password.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function pack( array &$state, $deadline, $password ) {
		global $wpdb, $wp_version;
		if ( '' === $state['writer_work'] ) {
			$writer = new Writer(
				$state['destination'],
				array(
					'type'           => 'full',
					'password'       => $password,
					'source_url'     => (string) get_option( 'siteurl', '' ),
					'home_url'       => (string) get_option( 'home', '' ),
					'wp_version'     => is_string( $wp_version ) ? $wp_version : '',
					'php_version'    => PHP_VERSION,
					'table_prefix'   => $wpdb->prefix,
					'plugin_version' => defined( 'BMX_VERSION' ) ? BMX_VERSION : '1.0.0',
				)
			);
		} else {
			$writer = Writer::resume( $state['writer_work'], $password );
		}

		try {
			if ( ! $state['sql_packed'] && is_file( $state['sql'] ) && ! in_array( 'database', $state['excludes'], true ) ) {
				$writer->add_database_file( $state['sql'], 'database/site.sql' );
				$state['sql_packed'] = true;
			}
			$list = Stream::open( $state['list'], 'rb' );
			try {
				if ( 0 !== fseek( $list, (int) $state['list_offset'], SEEK_SET ) ) {
					throw new ArchiveException( 'Could not read the file list.' );
				}
				while ( microtime( true ) < $deadline ) {
					$line = fgets( $list, 8192 );
					if ( false === $line ) {
						$paths                = $writer->finish();
						$state['writer_work'] = '';
						$state['status']      = 'done';
						$state['stage']       = 'done';
						$state['file_name']   = basename( (string) $paths[0] );
						$state['list_offset'] = (int) ftell( $list );
						return;
					}
					if ( "\n" !== substr( $line, -1 ) ) {
						throw new ArchiveException( 'A file path in the export is too long.' );
					}
					$relative = rtrim( $line, "\r\n" );
					if ( 0 !== strpos( $relative, 'wp-content/' ) ) {
						$state['list_offset'] = (int) ftell( $list );
						++$state['files_done'];
						continue;
					}
					$source               = rtrim( (string) $state['root'], '/\\' ) . '/' . substr( $relative, strlen( 'wp-content/' ) );
					$state['list_offset'] = (int) ftell( $list );
					if ( is_file( $source ) && ! is_link( $source ) ) {
						$writer->add_file( $source, $relative );
						$size = filesize( $source );
						if ( false !== $size ) {
							$state['bytes_done'] = (float) $state['bytes_done'] + (float) $size;
						}
					}
					++$state['files_done'];
				}
			} finally {
				fclose( $list );
			}
			$state['writer_work'] = $writer->suspend();
		} catch ( ArchiveException $exception ) {
			unset( $writer );
			throw $exception;
		}
	}

	/**
	 * Site-prefix tables, without BrightMigrate's own tables.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private static function tables() {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Export must read every site table, not the object cache.
		$names  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		$tables = array();
		$skip   = $wpdb->prefix . 'brightmigrate_';
		if ( ! is_array( $names ) ) {
			return $tables;
		}
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( 1 === preg_match( '/^[A-Za-z0-9_]+$/', $name ) && 0 !== strpos( $name, $skip ) ) {
				$tables[] = $name;
			}
		}
		return $tables;
	}

	/**
	 * Write DROP and CREATE for one table.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $state Job state.
	 * @param string $table Table name.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function write_table_header( array $state, $table ) {
		global $wpdb;
		$ident = self::ident( $table );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier matched [A-Za-z0-9_]+ before quoting.
		$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $ident, ARRAY_N );
		if ( ! is_array( $create ) || ! isset( $create[1] ) ) {
			throw new ArchiveException( 'Could not read a database table.' );
		}
		$sql    = 'DROP TABLE IF EXISTS ' . $ident . ";\n" . $create[1] . ";\n";
		$handle = Stream::open( $state['sql'], 'ab' );
		try {
			Stream::write_all( $handle, $sql );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Next page of rows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table Table name.
	 * @param array  $state Job state.
	 * @return array
	 */
	private static function fetch_rows( $table, array $state ) {
		global $wpdb;
		$where = array( '1=1' );
		if ( '' !== $state['pk'] && '' !== (string) $state['pk_value'] ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Column name matched [A-Za-z0-9_]+ and the value uses a placeholder.
			$where[] = $wpdb->prepare( self::ident( $state['pk'] ) . ' > %s', (string) $state['pk_value'] );
		}
		$where[] = self::row_filter( $table, $state['excludes'] );
		$sql     = 'SELECT * FROM ' . self::ident( $table ) . ' WHERE ' . implode( ' AND ', $where );
		if ( '' !== $state['pk'] ) {
			$sql .= ' ORDER BY ' . self::ident( $state['pk'] ) . ' ASC LIMIT 100';
		} else {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', 100, (int) $state['offset'] );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and column names are allow-listed; values use placeholders.
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Extra WHERE fragment for revisions and spam.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table    Table name.
	 * @param array  $excludes Exclude keys.
	 * @return string
	 */
	private static function row_filter( $table, array $excludes ) {
		global $wpdb;
		if ( $table === $wpdb->prefix . 'posts' && in_array( 'revisions', $excludes, true ) ) {
			return "post_type <> 'revision'";
		}
		if ( $table === $wpdb->prefix . 'comments' && in_array( 'spam', $excludes, true ) ) {
			return "comment_approved <> 'spam'";
		}
		return '1=1';
	}

	/**
	 * Append INSERT statements.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $state Job state.
	 * @param string $table Table name.
	 * @param array  $rows  Rows.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function write_rows( array $state, $table, array $rows ) {
		$first = $rows[0];
		$cols  = array();
		foreach ( array_keys( $first ) as $column ) {
			$cols[] = self::ident( (string) $column );
		}
		$lines = array();
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $value ) {
				if ( is_string( $value ) ) {
					$value = SearchReplace::apply( $value, $state['pairs'] );
				}
				$values[] = self::sql_value( $value );
			}
			$lines[] = '(' . implode( ',', $values ) . ')';
		}
		$sql    = 'INSERT INTO ' . self::ident( $table ) . ' (' . implode( ',', $cols ) . ') VALUES ' . implode( ",\n", $lines ) . ";\n";
		$handle = Stream::open( $state['sql'], 'ab' );
		try {
			Stream::write_all( $handle, $sql );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Single-column integer primary key, or an empty string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	private static function primary_key( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier matched [A-Za-z0-9_]+ before quoting.
		$keys = $wpdb->get_results( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'", ARRAY_A );
		if ( ! is_array( $keys ) || 1 !== count( $keys ) || empty( $keys[0]['Column_name'] ) ) {
			return '';
		}
		$column = (string) $keys[0]['Column_name'];
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $column ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Identifier matched [A-Za-z0-9_]+ before quoting.
		$columns = $wpdb->get_results( 'SHOW COLUMNS FROM ' . self::ident( $table ), ARRAY_A );
		if ( ! is_array( $columns ) ) {
			return '';
		}
		foreach ( $columns as $info ) {
			if ( isset( $info['Field'], $info['Type'] ) && $info['Field'] === $column && 1 === preg_match( '/^(tiny|small|medium|big)?int/', (string) $info['Type'] ) ) {
				return $column;
			}
		}
		return '';
	}

	/**
	 * Quote an identifier that has already been allow-listed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Table or column name.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function ident( $name ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			throw new ArchiveException( 'Could not read a database table.' );
		}
		return '`' . $name . '`';
	}

	/**
	 * SQL literal.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	private static function sql_value( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		return "'" . esc_sql( (string) $value ) . "'";
	}

	/**
	 * Archive path under wp-content, or empty when the file is outside it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $root Filesystem root.
	 * @param string $full Filesystem path.
	 * @return string
	 */
	private static function relative( $root, $full ) {
		$root_norm = rtrim( str_replace( '\\', '/', (string) $root ), '/' );
		$full_norm = str_replace( '\\', '/', (string) $full );
		$left      = $root_norm;
		$right     = $full_norm;
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$left  = strtolower( $left );
			$right = strtolower( $right );
		}
		if ( 0 !== strpos( $right, $left . '/' ) ) {
			return '';
		}
		return 'wp-content/' . substr( $full_norm, strlen( $root_norm ) + 1 );
	}

	/**
	 * Whether a relative archive path should be left out.
	 *
	 * @since 1.0.0
	 *
	 * @param string $relative Archive path.
	 * @param array  $excludes Exclude keys.
	 * @param array  $context  Active themes, plugins, and storage.
	 * @return bool
	 */
	private static function excluded( $relative, array $excludes, array $context ) {
		$path = str_replace( '\\', '/', $relative );
		if ( isset( $context['storage'] ) && '' !== $context['storage'] ) {
			$storage_rel = self::relative( WP_CONTENT_DIR, (string) $context['storage'] );
			if ( '' !== $storage_rel && ( $path === $storage_rel || 0 === strpos( $path, $storage_rel . '/' ) ) ) {
				return true;
			}
		}
		if ( in_array( 'media', $excludes, true ) && 0 === strpos( $path, 'wp-content/uploads/' ) ) {
			return true;
		}
		if ( 0 === strpos( $path, 'wp-content/themes/' ) ) {
			if ( in_array( 'themes', $excludes, true ) ) {
				return true;
			}
			if ( in_array( 'inactive_themes', $excludes, true ) ) {
				$top = strtok( substr( $path, strlen( 'wp-content/themes/' ) ), '/' );
				return is_string( $top ) && '' !== $top && ! in_array( $top, (array) $context['themes'], true );
			}
		}
		if ( 0 === strpos( $path, 'wp-content/plugins/' ) ) {
			if ( in_array( 'plugins', $excludes, true ) ) {
				return true;
			}
			if ( in_array( 'inactive_plugins', $excludes, true ) ) {
				$top = strtok( substr( $path, strlen( 'wp-content/plugins/' ) ), '/' );
				return is_string( $top ) && '' !== $top && ! in_array( $top, (array) $context['plugins'], true );
			}
		}
		if ( in_array( 'mu_plugins', $excludes, true ) && 0 === strpos( $path, 'wp-content/mu-plugins/' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Active theme directories and plugin directories.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function context() {
		$themes = array();
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme    = wp_get_theme();
			$themes[] = (string) $theme->get_stylesheet();
			$themes[] = (string) $theme->get_template();
		}
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			$plugin    = str_replace( '\\', '/', (string) $plugin );
			$dir       = dirname( $plugin );
			$plugins[] = ( '.' === $dir ) ? $plugin : $dir;
		}
		return array(
			'themes'  => $themes,
			'plugins' => $plugins,
			'storage' => Storage::active_path(),
		);
	}

	/**
	 * Keep only usable find/replace pairs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $pairs Raw pairs.
	 * @return array
	 */
	private static function pairs( array $pairs ) {
		$clean = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$from = isset( $pair['from'] ) ? (string) $pair['from'] : '';
			$to   = isset( $pair['to'] ) ? (string) $pair['to'] : '';
			if ( '' === $from || strlen( $from ) > 2000 || strlen( $to ) > 2000 ) {
				continue;
			}
			if ( false !== strpos( $from, "\n" ) || false !== strpos( $to, "\n" ) ) {
				continue;
			}
			$clean[] = array(
				'from' => $from,
				'to'   => $to,
			);
			if ( count( $clean ) >= 10 ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Fill the progress fields.
	 *
	 * @since 1.0.0
	 *
	 * @param array $state Job state.
	 * @return void
	 */
	private static function mark_progress( array &$state ) {
		if ( 'done' === $state['status'] ) {
			$state['progress'] = 100;
			$state['message']  = __( 'Your archive is ready.', 'brightmigrate-pro' );
			return;
		}
		if ( 'error' === $state['status'] ) {
			return;
		}
		if ( 'scan' === $state['stage'] ) {
			$state['progress'] = 10;
			$state['message']  = __( 'Listing files…', 'brightmigrate-pro' );
			return;
		}
		if ( 'database' === $state['stage'] ) {
			$total             = max( 1, count( $state['tables'] ) );
			$state['progress'] = 15 + (int) floor( 25 * ( (int) $state['table_index'] / $total ) );
			$state['message']  = __( 'Exporting the database…', 'brightmigrate-pro' );
			return;
		}
		$total             = max( 1, (int) $state['files_total'] );
		$done              = (int) $state['files_done'];
		$state['progress'] = min( 99, 40 + (int) floor( 55 * ( $done / $total ) ) );
		$remain            = self::remaining_text( $state, $done );
		$state['message']  = trim(
			sprintf(
				/* translators: 1: files archived so far, 2: total files, 3: size archived so far. */
				__( 'Archiving files… %1$d of %2$d (%3$s)', 'brightmigrate-pro' ),
				$done,
				(int) $state['files_total'],
				SizeFormat::bytes( $state['bytes_done'] )
			) . ' ' . $remain
		);
	}

	/**
	 * Rough time left once file packing has a pace.
	 *
	 * @since 1.0.0
	 *
	 * @param array $state Job state.
	 * @param int   $done  Files archived.
	 * @return string
	 */
	private static function remaining_text( array $state, $done ) {
		$total   = (int) $state['files_total'];
		$started = isset( $state['started_at'] ) ? (float) $state['started_at'] : 0.0;
		if ( $done < 1 || $done >= $total || $started <= 0 ) {
			return '';
		}
		$elapsed = microtime( true ) - $started;
		$seconds = (int) round( $elapsed * ( $total - $done ) / $done );
		if ( $seconds >= 60 ) {
			return sprintf(
				/* translators: %d: approximate minutes remaining. */
				__( 'About %d minutes left.', 'brightmigrate-pro' ),
				(int) max( 1, (int) round( $seconds / 60 ) )
			);
		}
		if ( $seconds > 5 ) {
			return __( 'Less than a minute left.', 'brightmigrate-pro' );
		}
		return '';
	}

	/**
	 * JSON for the browser.
	 *
	 * @since 1.0.0
	 *
	 * @param array $state Job state.
	 * @return array
	 */
	private static function view( array $state ) {
		$download = '';
		if ( ! empty( $state['file_name'] ) ) {
			$download = self::download_url( (string) $state['file_name'] );
		}
		return array(
			'status'   => (string) $state['status'],
			'progress' => (int) $state['progress'],
			'message'  => (string) $state['message'],
			'download' => $download,
			'file'     => isset( $state['file_name'] ) ? (string) $state['file_name'] : '',
		);
	}

	/**
	 * Admin download URL for one stored archive.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string
	 */
	public static function download_url( $name ) {
		$base = is_network_admin() ? network_admin_url( 'admin-post.php' ) : admin_url( 'admin-post.php' );
		$url  = add_query_arg(
			array(
				'action' => 'brightmigrate_download',
				'file'   => $name,
			),
			$base
		);
		return wp_nonce_url( $url, 'brightmigrate_download_' . $name );
	}

	/**
	 * Delete one archive from storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name, not a path.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function delete_archive( $name ) {
		$path = self::archive_path( basename( (string) $name ) );
		if ( '' === $path || ! unlink( $path ) ) {
			throw new ArchiveException( 'That archive is not available.' );
		}
	}

	/**
	 * Read an archive summary without changing this site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name     File name, not a path.
	 * @param string $password Password for an encrypted archive.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function inspect_archive( $name, $password ) {
		$path = self::archive_path( basename( (string) $name ) );
		if ( '' === $path ) {
			throw new ArchiveException( 'That archive is not available.' );
		}
		$reader = new Reader( $path, $password );
		try {
			$reader->verify();
			$summary = $reader->summary();
		} finally {
			$reader->close();
		}
		return array(
			'status'   => 'ready',
			'progress' => 100,
			'message'  => __( 'The backup is valid. Replacing this live site is the next step, so nothing was changed.', 'brightmigrate-pro' ),
			'file'     => basename( $path ),
			'files'    => isset( $summary['contents']['files'] ) ? (int) $summary['contents']['files'] : 0,
			'type'     => isset( $summary['type'] ) ? (string) $summary['type'] : '',
		);
	}

	/**
	 * Resolved archive path, or empty when the name is not safe.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function archive_path( $name ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+\.bmx$/', $name ) ) {
			return '';
		}
		$path = Storage::active_path() . '/' . $name;
		if ( ! is_file( $path ) || is_link( $path ) ) {
			return '';
		}
		return $path;
	}

	/**
	 * Make sure storage exists.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function ready() {
		$error = Storage::ensure_ready();
		if ( is_string( $error ) && '' !== $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Storage::ensure_ready() already returns an administrator-safe message.
			throw new ArchiveException( $error );
		}
	}

	/**
	 * Job directory inside storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     Job id.
	 * @param bool   $create Create it when missing.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function job_dir( $id, $create ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', (string) $id ) ) {
			throw new ArchiveException( 'That export could not be found.' );
		}
		$dir = Storage::active_path() . '/exports/' . $id;
		if ( $create && ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new ArchiveException( 'Could not start the export.' );
		}
		if ( ! is_dir( $dir ) ) {
			throw new ArchiveException( 'That export could not be found.' );
		}
		return $dir;
	}

	/**
	 * Incoming upload directory.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Upload id.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function incoming_dir( $id ) {
		$dir = Storage::active_path() . '/incoming/' . $id;
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new ArchiveException( 'Could not store the upload.' );
		}
		return $dir;
	}

	/**
	 * Exclusive lock, or null when another request holds it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Job directory.
	 * @return resource|null
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function lock( $dir ) {
		$handle = fopen( $dir . '/lock', 'c' );
		if ( false === $handle ) {
			throw new ArchiveException( 'Could not continue the export.' );
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) {
			fclose( $handle );
			return null;
		}
		return $handle;
	}

	/**
	 * Read job state.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Job directory.
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function read_state( $dir ) {
		$path = $dir . '/state.json';
		if ( ! is_file( $path ) ) {
			throw new ArchiveException( 'That export could not be found.' );
		}
		$raw   = file_get_contents( $path );
		$state = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $state ) || ! isset( $state['id'], $state['status'], $state['stage'] ) ) {
			throw new ArchiveException( 'That export could not be found.' );
		}
		return $state;
	}

	/**
	 * Write job state.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir   Job directory.
	 * @param array  $state Job state.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function write_state( $dir, array $state ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$encoded = wp_json_encode( $state );
		if ( ! is_string( $encoded ) ) {
			throw new ArchiveException( 'Could not continue the export.' );
		}
		$handle = Stream::open( $dir . '/state.json', 'wb' );
		try {
			Stream::write_all( $handle, $encoded );
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Delete a directory tree inside storage.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function remove_tree( $dir ) {
		$storage = Storage::active_path();
		$normal  = str_replace( '\\', '/', (string) $dir );
		$root    = rtrim( str_replace( '\\', '/', (string) $storage ), '/' );
		$left    = $normal;
		$right   = $root;
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$left  = strtolower( $left );
			$right = strtolower( $right );
		}
		if ( '' === $root || 0 !== strpos( $left, $right . '/' ) || is_link( $dir ) || ! is_dir( $dir ) ) {
			return;
		}
		$handle = opendir( $dir );
		if ( false === $handle ) {
			return;
		}
		$name = readdir( $handle );
		while ( false !== $name ) {
			if ( '.' !== $name && '..' !== $name ) {
				$path = $dir . '/' . $name;
				if ( is_dir( $path ) && ! is_link( $path ) ) {
					self::remove_tree( $path );
				} elseif ( is_file( $path ) || is_link( $path ) ) {
					unlink( $path );
				}
			}
			$name = readdir( $handle );
		}
		closedir( $handle );
		rmdir( $dir );
	}
}
