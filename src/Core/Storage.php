<?php
/**
 * Protected archive storage.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a private folder and rejects paths that could escape it.
 *
 * @since 1.0.0
 */
class Storage {

	/**
	 * How many directory entries a dashboard measurement may stat.
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	const USAGE_ENTRY_LIMIT = 100;

	/**
	 * String-only path check. It does not touch the filesystem.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path    Candidate path.
	 * @param array  $context abspath, content_dir, plugin_dir, themes_dir, mu_plugins_dir, uploads_dir, case_insensitive.
	 * @return array{valid: bool, path: string, code: string}
	 */
	public static function lexical_check( $path, array $context ) {
		if ( ! is_string( $path ) ) {
			return self::reject( 'empty' );
		}

		if ( '' === trim( $path ) || strlen( $path ) > 4096 ) {
			return self::reject( '' === trim( $path ) ? 'empty' : 'too_long' );
		}

		if ( false !== strpos( $path, "\0" ) ) {
			return self::reject( 'null_byte' );
		}

		if ( false !== strpos( $path, '://' ) ) {
			return self::reject( 'wrapper' );
		}

		if ( preg_match( '/[?*<>|"]/', $path ) ) {
			return self::reject( 'illegal_char' );
		}

		$slashed = str_replace( '\\', '/', $path );
		if ( preg_match( '#^//#', $slashed ) || preg_match( '#^\\\\\\\\#', $path ) ) {
			return self::reject( 'unc' );
		}

		foreach ( explode( '/', $slashed ) as $segment ) {
			if ( '..' === $segment ) {
				return self::reject( 'traversal' );
			}
		}

		$is_windows = (bool) preg_match( '/^[A-Za-z]:[\\\\\\/]/', $path );
		$is_unix    = ( '/' === substr( $slashed, 0, 1 ) );
		if ( ! $is_windows && ! $is_unix ) {
			return self::reject( 'relative' );
		}

		$normalized = self::normalize( $path );
		$case       = ! empty( $context['case_insensitive'] );
		$forbidden  = array(
			'plugin_dir'     => 'plugin_dir',
			'themes_dir'     => 'themes_dir',
			'mu_plugins_dir' => 'mu_plugins_dir',
			'uploads_dir'    => 'uploads_dir',
		);

		foreach ( $forbidden as $key => $code ) {
			if ( empty( $context[ $key ] ) || ! is_string( $context[ $key ] ) ) {
				continue;
			}
			if ( self::is_within( $normalized, self::normalize( $context[ $key ] ), $case ) ) {
				return self::reject( $code );
			}
		}

		$abspath = ( isset( $context['abspath'] ) && is_string( $context['abspath'] ) ) ? self::normalize( $context['abspath'] ) : '';
		$content = ( isset( $context['content_dir'] ) && is_string( $context['content_dir'] ) ) ? self::normalize( $context['content_dir'] ) : '';

		if ( '' !== $abspath && self::paths_equal( $normalized, $abspath, $case ) ) {
			return self::reject( 'abspath' );
		}

		if ( '' !== $content && self::paths_equal( $normalized, $content, $case ) ) {
			return self::reject( 'content_root' );
		}

		$inside_abs     = ( '' !== $abspath ) && self::is_within( $normalized, $abspath, $case );
		$inside_content = ( '' !== $content ) && self::is_within( $normalized, $content, $case );
		if ( $inside_abs && ! $inside_content ) {
			return self::reject( 'outside_content' );
		}

		return array(
			'valid' => true,
			'path'  => $normalized,
			'code'  => 'ok',
		);
	}

	/**
	 * Lexical check plus realpath, so an existing symlink cannot escape the rules.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path    Candidate path.
	 * @param array  $context Optional context. Empty uses the current WordPress directories.
	 * @return array{valid: bool, path: string, code: string}
	 */
	public static function validate_location( $path, array $context = array() ) {
		if ( array() === $context ) {
			$context = self::environment_context();
		}

		$lexical = self::lexical_check( $path, $context );
		if ( ! $lexical['valid'] ) {
			return $lexical;
		}

		$normalized = $lexical['path'];
		if ( file_exists( $normalized ) ) {
			if ( is_link( $normalized ) ) {
				return self::reject( 'symlink' );
			}
			$real = realpath( $normalized );
			if ( false === $real ) {
				return self::reject( 'unresolvable' );
			}
			return self::lexical_check( $real, $context );
		}

		$parent = dirname( $normalized );
		if ( ! is_dir( $parent ) || is_link( $parent ) ) {
			return self::reject( 'parent_missing' );
		}

		$real_parent = realpath( $parent );
		if ( false === $real_parent ) {
			return self::reject( 'unresolvable' );
		}

		$suffix   = substr( $normalized, strlen( self::normalize( $parent ) ) );
		$resolved = self::normalize( $real_parent . $suffix );
		return self::lexical_check( $resolved, $context );
	}

	/**
	 * Normalize separators and drop empty or "." segments. ".." is not resolved.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path  = str_replace( '\\', '/', (string) $path );
		$drive = '';

		if ( preg_match( '/^[A-Za-z]:/', $path ) ) {
			$drive = strtoupper( substr( $path, 0, 2 ) );
			$path  = substr( $path, 2 );
		}

		$clean = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			$clean[] = $segment;
		}

		$body = implode( '/', $clean );
		if ( '' !== $drive ) {
			return $drive . '/' . $body;
		}

		return '/' . $body;
	}

	/**
	 * Whether a path is a drive root or "/".
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public static function is_filesystem_root( $path ) {
		$normalized = self::normalize( $path );
		if ( '/' === $normalized ) {
			return true;
		}

		return (bool) preg_match( '/^[A-Z]:\/$/', $normalized ) && ! preg_match( '/^[A-Z]:\/.+/', $normalized );
	}

	/**
	 * Delete a directory tree without reading file contents or following links.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Directory to remove.
	 * @return bool
	 */
	public static function remove_storage_directory( $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) ) {
			return false;
		}

		foreach ( explode( '/', str_replace( '\\', '/', $path ) ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		$normalized = self::normalize( $path );
		if ( self::is_filesystem_root( $normalized ) || ! is_dir( $normalized ) || is_link( $normalized ) ) {
			return false;
		}

		return self::remove_node( $normalized, 0 );
	}

	/**
	 * Measure a small storage folder without walking an unbounded tree.
	 *
	 * @since 1.0.0
	 *
	 * @param string $directory Directory.
	 * @param int    $limit     Maximum entries to stat.
	 * @return array{bytes: float, truncated: bool}
	 */
	public static function bounded_usage( $directory, $limit = self::USAGE_ENTRY_LIMIT ) {
		$total     = 0.0;
		$seen      = 0;
		$truncated = false;
		$limit     = max( 1, (int) $limit );
		$queue     = array( $directory );
		$depth     = array( $directory => 0 );

		while ( $queue ) {
			$current = array_shift( $queue );
			if ( ! is_string( $current ) || ! is_dir( $current ) || is_link( $current ) ) {
				continue;
			}

			$handle = opendir( $current );
			if ( false === $handle ) {
				continue;
			}

			$stop  = false;
			$entry = readdir( $handle );
			while ( false !== $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					++$seen;
					if ( $seen > $limit ) {
						$truncated = true;
						$stop      = true;
						break;
					}

					$path = $current . '/' . $entry;
					if ( is_link( $path ) ) {
						$entry = readdir( $handle );
						continue;
					}

					if ( is_file( $path ) ) {
						$size = filesize( $path );
						if ( false !== $size ) {
							$total += (float) $size;
						}
					} else {
						$current_depth = isset( $depth[ $current ] ) ? (int) $depth[ $current ] : 0;
						if ( is_dir( $path ) && $current_depth < 2 ) {
							$queue[]        = $path;
							$depth[ $path ] = $current_depth + 1;
						}
					}
				}

				$entry = readdir( $handle );
			}

			closedir( $handle );
			if ( $stop ) {
				break;
			}
		}

		return array(
			'bytes'     => $total,
			'truncated' => $truncated,
		);
	}

	/**
	 * Directories WordPress is using right now.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function environment_context() {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		$basedir = ( is_array( $uploads ) && isset( $uploads['basedir'] ) ) ? (string) $uploads['basedir'] : '';

		return array(
			'abspath'          => defined( 'ABSPATH' ) ? ABSPATH : '',
			'content_dir'      => defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '',
			'plugin_dir'       => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'themes_dir'       => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
			'mu_plugins_dir'   => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'uploads_dir'      => $basedir,
			'case_insensitive' => '\\' === DIRECTORY_SEPARATOR,
		);
	}

	/**
	 * Default private folder for this site.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function default_path() {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}

		$suffix = get_option( Options::STORAGE_SUFFIX, '' );
		if ( ! is_string( $suffix ) || ! preg_match( '/^[a-f0-9]{16}$/', $suffix ) ) {
			$suffix = self::new_suffix();
			update_option( Options::STORAGE_SUFFIX, $suffix, true );
		}

		return self::normalize( WP_CONTENT_DIR . '/brightmigrate-' . $suffix );
	}

	/**
	 * Active folder: a valid custom location, otherwise the default.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function active_path() {
		$settings = get_option( Options::SETTINGS, array() );
		$custom   = ( is_array( $settings ) && isset( $settings['storage_location'] ) ) ? (string) $settings['storage_location'] : '';

		if ( '' !== $custom ) {
			$checked = self::validate_location( $custom );
			if ( $checked['valid'] ) {
				return $checked['path'];
			}
		}

		return self::default_path();
	}

	/**
	 * Create the active folder and its guard files.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty on success, otherwise a message safe to show an administrator.
	 */
	public static function ensure_ready() {
		$path = self::active_path();
		if ( '' === $path ) {
			return __( 'BrightMigrate could not choose a storage folder.', 'brightmigrate-pro' );
		}

		if ( ! self::ensure_directory( $path ) || ! self::write_guards( $path ) ) {
			$reference = Logger::write( 'error', 'Could not prepare storage guards.', array( 'path' => $path ) );
			return sprintf(
				/* translators: %s: log reference ID. */
				__( 'BrightMigrate could not protect its storage folder. Reference: %s', 'brightmigrate-pro' ),
				$reference
			);
		}

		$logs = $path . '/logs';
		if ( ! self::ensure_directory( $logs ) || ! self::write_guards( $logs ) ) {
			$reference = Logger::write( 'error', 'Could not prepare the log folder.', array( 'path' => $logs ), $path );
			return sprintf(
				/* translators: %s: log reference ID. */
				__( 'BrightMigrate could not create its log folder. Reference: %s', 'brightmigrate-pro' ),
				$reference
			);
		}

		return '';
	}

	/**
	 * Alias used during installation.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function provision() {
		return self::ensure_ready();
	}

	/**
	 * Guard file contents.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	public static function guard_files() {
		return array(
			'index.php'  => "<?php\nif ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n",
			'.htaccess'  => self::htaccess(),
			'web.config' => self::web_config(),
		);
	}

	/**
	 * Apache denial rules.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function htaccess() {
		return "# BrightMigrate Pro storage. Do not serve these files.\n"
			. "Options -Indexes\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
	}

	/**
	 * IIS denial rules.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function web_config() {
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
			. "<configuration>\n"
			. "\t<system.webServer>\n"
			. "\t\t<authorization>\n"
			. "\t\t\t<deny users=\"*\" />\n"
			. "\t\t</authorization>\n"
			. "\t\t<directoryBrowse enabled=\"false\" />\n"
			. "\t</system.webServer>\n"
			. "</configuration>\n";
	}

	/**
	 * Create a directory without following a symlink.
	 *
	 * @since 1.0.0
	 *
	 * @param string $directory Directory.
	 * @return bool
	 */
	private static function ensure_directory( $directory ) {
		if ( is_link( $directory ) ) {
			return false;
		}
		if ( is_dir( $directory ) ) {
			return true;
		}
		if ( function_exists( 'wp_mkdir_p' ) ) {
			return (bool) wp_mkdir_p( $directory );
		}
		return mkdir( $directory, 0755, true );
	}

	/**
	 * Write guard files with streams. Skip a file when its contents already match.
	 *
	 * @since 1.0.0
	 *
	 * @param string $directory Directory.
	 * @return bool
	 */
	private static function write_guards( $directory ) {
		foreach ( self::guard_files() as $name => $contents ) {
			$file = rtrim( $directory, '/\\' ) . '/' . $name;
			if ( is_link( $file ) ) {
				return false;
			}
			if ( is_file( $file ) ) {
				$existing = filesize( $file );
				if ( false !== $existing && strlen( $contents ) === (int) $existing ) {
					$handle  = fopen( $file, 'rb' );
					$current = false !== $handle ? fread( $handle, strlen( $contents ) ) : false;
					if ( false !== $handle ) {
						fclose( $handle );
					}
					if ( $current === $contents ) {
						continue;
					}
				}
			}

			$handle = fopen( $file, 'wb' );
			if ( false === $handle ) {
				return false;
			}
			$written = fwrite( $handle, $contents );
			fflush( $handle );
			fclose( $handle );
			if ( false === $written ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 16 hex characters from a CSPRNG.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function new_suffix() {
		try {
			return bin2hex( random_bytes( 8 ) );
		} catch ( \Exception $exception ) {
			unset( $exception );
			return substr( hash( 'sha256', uniqid( 'bmx', true ) ), 0, 16 );
		}
	}

	/**
	 * Failed check result.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Machine code.
	 * @return array{valid: bool, path: string, code: string}
	 */
	private static function reject( $code ) {
		return array(
			'valid' => false,
			'path'  => '',
			'code'  => $code,
		);
	}

	/**
	 * Case-aware equality.
	 *
	 * @since 1.0.0
	 *
	 * @param string $left            Left path.
	 * @param string $right           Right path.
	 * @param bool   $case_insensitive Compare without case.
	 * @return bool
	 */
	private static function paths_equal( $left, $right, $case_insensitive ) {
		if ( $case_insensitive ) {
			$left  = strtolower( $left );
			$right = strtolower( $right );
		}
		return rtrim( $left, '/' ) === rtrim( $right, '/' );
	}

	/**
	 * Whether $path is the directory or a descendant. A sibling prefix does not match.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path             Candidate.
	 * @param string $directory        Directory.
	 * @param bool   $case_insensitive Compare without case.
	 * @return bool
	 */
	private static function is_within( $path, $directory, $case_insensitive ) {
		$path      = rtrim( $path, '/' );
		$directory = rtrim( $directory, '/' );
		if ( '' === $directory ) {
			return false;
		}
		if ( $case_insensitive ) {
			$path      = strtolower( $path );
			$directory = strtolower( $directory );
		}
		return $path === $directory || 0 === strpos( $path, $directory . '/' );
	}

	/**
	 * Remove a file or directory. Symlinks are unlinked, not followed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path  Path.
	 * @param int    $depth Current depth.
	 * @return bool
	 */
	private static function remove_node( $path, $depth ) {
		if ( $depth > 40 ) {
			return false;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			return unlink( $path );
		}
		if ( ! is_dir( $path ) ) {
			return false;
		}

		$handle = opendir( $path );
		if ( false === $handle ) {
			return false;
		}

		$ok    = true;
		$entry = readdir( $handle );
		while ( false !== $entry ) {
			if ( '.' !== $entry && '..' !== $entry && ! self::remove_node( $path . '/' . $entry, $depth + 1 ) ) {
				$ok = false;
			}
			$entry = readdir( $handle );
		}
		closedir( $handle );

		if ( ! $ok ) {
			return false;
		}

		return rmdir( $path );
	}
}
