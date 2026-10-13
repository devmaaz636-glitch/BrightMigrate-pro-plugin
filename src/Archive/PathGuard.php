<?php
/**
 * Safe paths inside an archive and on extract.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Archive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blocks Zip Slip paths and executable files outside themes and plugins.
 *
 * @since 1.0.0
 */
class PathGuard {

	/**
	 * Extensions a Content Pack may not write outside themes and plugins.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const EXECUTABLES = array(
		'php',
		'phtml',
		'pht',
		'phar',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phps',
		'cgi',
		'pl',
		'exe',
	);

	/**
	 * Prefixes where a full-site or theme archive may contain PHP.
	 *
	 * @since 1.0.0
	 *
	 * @var string[]
	 */
	const CODE_PREFIXES = array(
		'wp-content/themes/',
		'wp-content/plugins/',
		'wp-content/mu-plugins/',
	);

	/**
	 * Normalise an archive-relative path or reject it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path from the caller or from an archive entry.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function archive_path( $path ) {
		if ( ! is_string( $path ) || '' === $path || strlen( $path ) > 4096 ) {
			throw new ArchiveException( 'An archive path is empty or too long.' );
		}
		if ( false !== strpos( $path, "\0" ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			throw new ArchiveException( 'An archive path contains a control character.' );
		}
		if ( false !== strpos( $path, ':' ) || false !== strpos( $path, '://' ) ) {
			throw new ArchiveException( 'An archive path must be relative.' );
		}

		$slash = str_replace( '\\', '/', $path );
		if ( '/' === substr( $slash, 0, 1 ) ) {
			throw new ArchiveException( 'An archive path must be relative.' );
		}

		$parts = array();
		foreach ( explode( '/', $slash ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part ) {
				throw new ArchiveException( 'An archive path cannot climb out of the archive.' );
			}
			$parts[] = $part;
		}

		return implode( '/', $parts );
	}

	/**
	 * Whether this relative path may be written for the given policy.
	 *
	 * `full` allows PHP anywhere in the archive. `content` allows it only under themes and plugins.
	 *
	 * @since 1.0.0
	 *
	 * @param string $archive_path Relative path.
	 * @param string $policy       full or content.
	 * @return bool
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function allows_executable( $archive_path, $policy ) {
		$path = self::archive_path( $archive_path );
		if ( 'full' === $policy ) {
			return true;
		}
		if ( 'content' !== $policy ) {
			throw new ArchiveException( 'Unknown extract policy.' );
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, self::EXECUTABLES, true ) ) {
			return true;
		}

		foreach ( self::CODE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Absolute destination inside $root. Existing symlinks are rejected.
	 *
	 * @since 1.0.0
	 *
	 * @param string $archive_path Relative path.
	 * @param string $root         Existing directory.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	public static function destination( $archive_path, $root ) {
		$relative = self::archive_path( $archive_path );
		if ( ! is_string( $root ) || '' === $root || is_link( $root ) || ! is_dir( $root ) ) {
			throw new ArchiveException( 'The extract folder is not a real directory.' );
		}

		$real = realpath( $root );
		if ( false === $real ) {
			throw new ArchiveException( 'The extract folder is not a real directory.' );
		}

		$root_norm = rtrim( str_replace( '\\', '/', $real ), '/' );
		$full      = $root_norm . '/' . $relative;
		$cursor    = $root_norm;
		foreach ( explode( '/', $relative ) as $part ) {
			$cursor .= '/' . $part;
			if ( is_link( $cursor ) ) {
				throw new ArchiveException( 'Refusing to follow a symlink while extracting.' );
			}
		}

		$compare_root = $root_norm;
		$compare_full = $full;
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$compare_root = strtolower( $compare_root );
			$compare_full = strtolower( $compare_full );
		}
		if ( 0 !== strpos( $compare_full, $compare_root . '/' ) ) {
			throw new ArchiveException( 'An archive path escapes the extract folder.' );
		}

		return $full;
	}
}
