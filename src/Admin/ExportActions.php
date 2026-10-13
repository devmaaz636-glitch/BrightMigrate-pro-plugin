<?php
/**
 * Export, upload, and download requests.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin;

use BrightIdeas\Migrate\Archive\ArchiveException;
use BrightIdeas\Migrate\Core\Capabilities;
use BrightIdeas\Migrate\Core\Logger;
use BrightIdeas\Migrate\Export\SiteExport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administrator-only actions for a full-site file export.
 *
 * @since 1.0.0
 */
class ExportActions {

	/**
	 * Register Ajax and the download endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'wp_ajax_brightmigrate_export', array( self::class, 'handle' ) );
		add_action( 'admin_post_brightmigrate_download', array( self::class, 'download' ) );
	}

	/**
	 * Run one export or upload step.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! Capabilities::current_user_can() ) {
			wp_send_json_error(
				array(
					'message' => __( 'You do not have permission to use BrightMigrate.', 'brightmigrate-pro' ),
				),
				403
			);
		}

		check_ajax_referer( 'brightmigrate_export', 'nonce' );

		$step = '';
		if ( isset( $_POST['step'] ) ) {
			$raw = wp_unslash( $_POST['step'] );
			if ( is_string( $raw ) ) {
				$step = sanitize_key( $raw );
			}
		}

		try {
			if ( 'start' === $step ) {
				$id = SiteExport::start( self::excludes(), self::pairs() );
				wp_send_json_success(
					array(
						'id'       => $id,
						'status'   => 'running',
						'progress' => 1,
						'message'  => __( 'Listing files…', 'brightmigrate-pro' ),
					)
				);
			}

			if ( 'tick' === $step ) {
				wp_send_json_success( SiteExport::tick( self::job_id( true ), self::password() ) );
			}

			if ( 'cancel' === $step ) {
				SiteExport::cancel( self::job_id( true ) );
				wp_send_json_success(
					array(
						'status'  => 'cancelled',
						'message' => __( 'Export stopped.', 'brightmigrate-pro' ),
					)
				);
			}

			if ( 'upload' === $step ) {
				wp_send_json_success( self::upload() );
			}

			if ( 'delete' === $step ) {
				SiteExport::delete_archive( self::archive_name() );
				wp_send_json_success(
					array(
						'status'  => 'deleted',
						'message' => __( 'Backup deleted.', 'brightmigrate-pro' ),
					)
				);
			}

			if ( 'restore' === $step ) {
				wp_send_json_success( SiteExport::inspect_archive( self::archive_name(), self::password() ) );
			}
		} catch ( ArchiveException $exception ) {
			wp_send_json_error(
				array(
					'message' => $exception->getMessage(),
				),
				400
			);
		} catch ( \Throwable $throwable ) {
			$reference = Logger::write(
				'error',
				$throwable->getMessage(),
				array(
					'type' => get_class( $throwable ),
				)
			);
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: log reference ID. */
						__( 'Something went wrong. The details were saved in the BrightMigrate log. Reference: %s', 'brightmigrate-pro' ),
						$reference
					),
				),
				500
			);
		}

		wp_send_json_error(
			array(
				'message' => __( 'That request was not recognized.', 'brightmigrate-pro' ),
			),
			400
		);
	}

	/**
	 * Stream one stored archive.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function download() {
		if ( ! Capabilities::current_user_can() ) {
			Capabilities::deny();
		}

		$name = '';
		if ( isset( $_REQUEST['file'] ) ) {
			$raw = wp_unslash( $_REQUEST['file'] );
			if ( is_string( $raw ) ) {
				$name = basename( $raw );
			}
		}

		check_admin_referer( 'brightmigrate_download_' . $name );
		SiteExport::stream( $name );
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle() calls check_ajax_referer() before these readers.

	/**
	 * Allow-listed exclude keys from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	private static function excludes() {
		if ( ! isset( $_POST['excludes'] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST['excludes'] );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$clean = array();
		foreach ( $raw as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$key = sanitize_key( $key );
			if ( in_array( $key, SiteExport::EXCLUDES, true ) ) {
				$clean[] = $key;
			}
		}
		return $clean;
	}

	/**
	 * Find and replace rows from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function pairs() {
		if ( ! isset( $_POST['pairs'] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST['pairs'] );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$pairs = array();
		foreach ( $raw as $pair ) {
			if ( ! is_array( $pair ) ) {
				continue;
			}
			$pairs[] = array(
				'from' => isset( $pair['from'] ) && is_string( $pair['from'] ) ? $pair['from'] : '',
				'to'   => isset( $pair['to'] ) && is_string( $pair['to'] ) ? $pair['to'] : '',
			);
		}
		return $pairs;
	}

	/**
	 * Archive file name from the request.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function archive_name() {
		$name = '';
		if ( isset( $_POST['file'] ) ) {
			$raw = wp_unslash( $_POST['file'] );
			if ( is_string( $raw ) ) {
				$name = basename( $raw );
			}
		}
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]+\.bmx$/', $name ) ) {
			throw new ArchiveException( 'That archive is not available.' );
		}
		return $name;
	}

	/**
	 * Job or upload id. Empty is allowed only when a new upload starts.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $required Whether an id must already exist.
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function job_id( $required ) {
		$id = '';
		if ( isset( $_POST['id'] ) ) {
			$raw = wp_unslash( $_POST['id'] );
			if ( is_string( $raw ) ) {
				$id = strtolower( $raw );
			}
		}
		if ( '' === $id && ! $required ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-f0-9]{16}$/', $id ) ) {
			throw new ArchiveException( 'That export could not be found.' );
		}
		return $id;
	}

	/**
	 * Password for this request. It is not stored.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function password() {
		if ( ! isset( $_POST['password'] ) ) {
			return '';
		}
		$password = wp_unslash( $_POST['password'] );
		if ( ! is_string( $password ) ) {
			return '';
		}
		if ( strlen( $password ) > 1024 ) {
			throw new ArchiveException( 'That password is too long.' );
		}
		return $password;
	}

	/**
	 * Store one uploaded chunk.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 * @throws ArchiveException When this step cannot finish safely.
	 */
	private static function upload() {
		$index = isset( $_POST['index'] ) ? (int) $_POST['index'] : -1;
		$total = isset( $_POST['total'] ) ? (int) $_POST['total'] : 0;
		if ( ! isset( $_FILES['chunk'] ) || ! is_array( $_FILES['chunk'] ) ) {
			throw new ArchiveException( 'The upload did not arrive.' );
		}
		$file = $_FILES['chunk'];
		$tmp  = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $code || '' === $tmp ) {
			throw new ArchiveException( 'The upload did not arrive.' );
		}

		return SiteExport::accept_chunk( self::job_id( false ), $index, $total, $tmp, self::password() );
	}

	// phpcs:enable WordPress.Security.NonceVerification.Missing
}
