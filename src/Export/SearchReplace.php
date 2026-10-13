<?php
/**
 * Database find and replace that keeps serialized lengths valid.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Export;

use BrightIdeas\Migrate\Archive\ArchiveException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces text in a database value without breaking PHP serialized strings.
 *
 * @since 1.0.0
 */
class SearchReplace {

	/**
	 * Apply find/replace pairs to one value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Database value.
	 * @param array $pairs List of array{from: string, to: string}.
	 * @return mixed
	 */
	public static function apply( $value, array $pairs ) {
		if ( ! is_string( $value ) || array() === $pairs ) {
			return $value;
		}

		$usable = self::usable_pairs( $pairs );
		if ( array() === $usable ) {
			return $value;
		}

		if ( ! self::looks_serialized( $value ) ) {
			return self::plain( $value, $usable );
		}

		try {
			$index  = 0;
			$parsed = self::parse( $value, $index, $usable );
			if ( strlen( $value ) !== $index ) {
				return $value;
			}
			return $parsed;
		} catch ( ArchiveException $exception ) {
			unset( $exception );
			return $value;
		}
	}

	/**
	 * Drop empty or unchanged pairs.
	 *
	 * @since 1.0.0
	 *
	 * @param array $pairs Caller pairs.
	 * @return array
	 */
	private static function usable_pairs( array $pairs ) {
		$usable = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['from'], $pair['to'] ) ) {
				continue;
			}
			$from = (string) $pair['from'];
			$to   = (string) $pair['to'];
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$usable[] = array(
				'from' => $from,
				'to'   => $to,
			);
		}
		return $usable;
	}

	/**
	 * Whether the value uses PHP's serialize format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Database value.
	 * @return bool
	 */
	private static function looks_serialized( $value ) {
		if ( strlen( $value ) < 2 ) {
			return false;
		}
		if ( 'N;' === $value ) {
			return true;
		}
		return 1 === preg_match( '/^(a|O|s|b|i|d):/', $value );
	}

	/**
	 * Replace a value and its JSON-escaped slash form.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Text.
	 * @param array  $pairs Usable pairs.
	 * @return string
	 */
	private static function plain( $value, array $pairs ) {
		foreach ( $pairs as $pair ) {
			$value = str_replace( $pair['from'], $pair['to'], $value );
			$from  = str_replace( '/', '\\/', $pair['from'] );
			$to    = str_replace( '/', '\\/', $pair['to'] );
			if ( $from !== $pair['from'] ) {
				$value = str_replace( $from, $to, $value );
			}
		}
		return $value;
	}

	/**
	 * Read one serialized value and return it with replacements applied.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data  Serialized bytes.
	 * @param int    $index Cursor, advanced past the value.
	 * @param array  $pairs Usable pairs.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function parse( $data, &$index, array $pairs ) {
		$type = substr( $data, $index, 1 );
		if ( 'N' === $type ) {
			self::take( $data, $index, 'N;' );
			return 'N;';
		}
		if ( 'b' === $type || 'i' === $type || 'd' === $type ) {
			$pattern = 'b' === $type ? '/\Ab:[01];/' : ( 'i' === $type ? '/\Ai:-?\d+;/' : '/\Ad:[^;]+;/' );
			if ( 1 !== preg_match( $pattern, substr( $data, $index ), $match ) ) {
				throw new ArchiveException( 'Could not read a database value.' );
			}
			$index += strlen( $match[0] );
			return $match[0];
		}
		if ( 's' === $type ) {
			return self::parse_string( $data, $index, $pairs );
		}
		if ( 'a' === $type ) {
			return self::parse_array( $data, $index, $pairs );
		}
		if ( 'O' === $type ) {
			return self::parse_object( $data, $index, $pairs );
		}
		throw new ArchiveException( 'Could not read a database value.' );
	}

	/**
	 * Require an exact token.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data  Serialized bytes.
	 * @param int    $index Cursor.
	 * @param string $token Expected text.
	 * @return void
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function take( $data, &$index, $token ) {
		$length = strlen( $token );
		if ( substr( $data, $index, $length ) !== $token ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		$index += $length;
	}

	/**
	 * Replace inside a serialized string and correct its length.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data  Serialized bytes.
	 * @param int    $index Cursor.
	 * @param array  $pairs Usable pairs.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function parse_string( $data, &$index, array $pairs ) {
		if ( 1 !== preg_match( '/\As:(\d+):"/', substr( $data, $index ), $match ) ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		$length  = (int) $match[1];
		$content = $index + strlen( $match[0] );
		if ( ( $content + $length + 2 ) > strlen( $data ) ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		self::take( $data, $content, substr( $data, $content, $length ) );
		self::take( $data, $content, '";' );
		$index   = $content;
		$updated = self::plain( substr( $data, $content - $length - 2, $length ), $pairs );
		return 's:' . strlen( $updated ) . ':"' . $updated . '";';
	}

	/**
	 * Walk a serialized array.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data  Serialized bytes.
	 * @param int    $index Cursor.
	 * @param array  $pairs Usable pairs.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function parse_array( $data, &$index, array $pairs ) {
		if ( 1 !== preg_match( '/\Aa:(\d+):\{/', substr( $data, $index ), $match ) ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		$index += strlen( $match[0] );
		$count  = (int) $match[1];
		$out    = $match[0];
		for ( $item = 0; $item < $count; $item++ ) {
			$out .= self::parse( $data, $index, $pairs );
			$out .= self::parse( $data, $index, $pairs );
		}
		self::take( $data, $index, '}' );
		return $out . '}';
	}

	/**
	 * Walk a serialized object. The class name is left unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param string $data  Serialized bytes.
	 * @param int    $index Cursor.
	 * @param array  $pairs Usable pairs.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function parse_object( $data, &$index, array $pairs ) {
		if ( 1 !== preg_match( '/\AO:(\d+):"/', substr( $data, $index ), $match ) ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		$name_at = $index + strlen( $match[0] );
		$length  = (int) $match[1];
		if ( ( $name_at + $length + 2 ) > strlen( $data ) ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		$name  = substr( $data, $name_at, $length );
		$index = $name_at + $length;
		self::take( $data, $index, '":' );
		if ( 1 !== preg_match( '/\A(\d+):\{/', substr( $data, $index ), $count ) ) {
			throw new ArchiveException( 'Could not read a database value.' );
		}
		$index += strlen( $count[0] );
		$out    = 'O:' . strlen( $name ) . ':"' . $name . '":' . $count[1] . ':{';
		$total  = (int) $count[1];
		for ( $item = 0; $item < $total; $item++ ) {
			$out .= self::parse( $data, $index, $pairs );
			$out .= self::parse( $data, $index, $pairs );
		}
		self::take( $data, $index, '}' );
		return $out . '}';
	}
}
