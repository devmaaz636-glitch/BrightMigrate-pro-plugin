<?php
/**
 * Settings values and sanitizing.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Allow-lists every setting this plugin stores.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Default values.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'storage_location' => '',
			'upload_chunk_mb'  => 0,
			'retention_count'  => 0,
			'notify_email'     => '',
			'delete_archives'  => '0',
		);
	}

	/**
	 * Sanitize a submitted settings array.
	 *
	 * The path validator receives the raw location and returns the same shape as
	 * Storage::lexical_check(). An empty location means "use the default folder".
	 *
	 * @since 1.0.0
	 *
	 * @param mixed    $input     Submitted values.
	 * @param array    $previous  Previously stored values.
	 * @param callable $validator Path validator.
	 * @return array{settings: array, errors: string[]}
	 */
	public static function sanitize_settings( $input, array $previous, $validator ) {
		$clean  = self::defaults();
		$errors = array();
		$stored = array_merge( self::defaults(), $previous );

		if ( ! is_callable( $validator ) ) {
			return array(
				'settings' => $stored,
				'errors'   => array( 'storage_rejected' ),
			);
		}

		if ( ! is_array( $input ) ) {
			return array(
				'settings' => $stored,
				'errors'   => array(),
			);
		}

		if ( array_key_exists( 'storage_location', $input ) ) {
			$location = trim( (string) $input['storage_location'] );
			if ( '' === $location ) {
				$clean['storage_location'] = '';
			} else {
				$checked = call_user_func( $validator, $location );
				if ( is_array( $checked ) && ! empty( $checked['valid'] ) && isset( $checked['path'] ) && is_string( $checked['path'] ) ) {
					$clean['storage_location'] = $checked['path'];
				} else {
					$clean['storage_location'] = (string) $stored['storage_location'];
					$errors[]                  = 'storage_rejected';
				}
			}
		} else {
			$clean['storage_location'] = (string) $stored['storage_location'];
		}

		if ( array_key_exists( 'upload_chunk_mb', $input ) && is_numeric( $input['upload_chunk_mb'] ) && (int) $input['upload_chunk_mb'] > 0 ) {
			$clean['upload_chunk_mb'] = max( 2, min( 10, (int) $input['upload_chunk_mb'] ) );
		} elseif ( array_key_exists( 'upload_chunk_mb', $input ) ) {
			$clean['upload_chunk_mb'] = 0;
		} else {
			$clean['upload_chunk_mb'] = (int) $stored['upload_chunk_mb'];
		}

		if ( array_key_exists( 'retention_count', $input ) && is_numeric( $input['retention_count'] ) ) {
			$clean['retention_count'] = max( 0, min( 10000, (int) $input['retention_count'] ) );
		} else {
			$clean['retention_count'] = (int) $stored['retention_count'];
		}

		if ( array_key_exists( 'notify_email', $input ) ) {
			$email = trim( (string) $input['notify_email'] );
			if ( '' === $email ) {
				$clean['notify_email'] = '';
			} elseif ( false !== strpos( $email, "\n" ) || false !== strpos( $email, "\r" ) || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$clean['notify_email'] = (string) $stored['notify_email'];
				$errors[]              = 'email_invalid';
			} else {
				$clean['notify_email'] = strtolower( $email );
			}
		} else {
			$clean['notify_email'] = (string) $stored['notify_email'];
		}

		if ( array_key_exists( 'delete_archives', $input ) ) {
			$clean['delete_archives'] = ( '1' === (string) $input['delete_archives'] ) ? '1' : '0';
		} else {
			$clean['delete_archives'] = ( '1' === (string) $stored['delete_archives'] ) ? '1' : '0';
		}

		return array(
			'settings' => $clean,
			'errors'   => $errors,
		);
	}

	/**
	 * Settings API callback.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$previous = get_option( Options::SETTINGS, array() );
		if ( ! is_array( $previous ) ) {
			$previous = array();
		}

		$result = self::sanitize_settings( $input, $previous, array( Storage::class, 'validate_location' ) );
		foreach ( $result['errors'] as $code ) {
			add_settings_error( Options::SETTINGS, $code, self::error_message( $code ) );
		}

		return $result['settings'];
	}

	/**
	 * Plain-language settings error.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Error code.
	 * @return string
	 */
	public static function error_message( $code ) {
		if ( 'email_invalid' === $code ) {
			return __( 'Enter a valid email address, or leave the field blank.', 'brightmigrate-pro' );
		}

		return __( 'That storage folder cannot be used. Choose an absolute path outside uploads, plugins, and themes, or leave the field blank to keep the protected folder BrightMigrate created.', 'brightmigrate-pro' );
	}
}
