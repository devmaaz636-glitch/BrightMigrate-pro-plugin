<?php
/**
 * Shared admin screen shell.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin;

use BrightIdeas\Migrate\Core\Capabilities;
use BrightIdeas\Migrate\Core\Logger;
use BrightIdeas\Migrate\Core\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the admin chrome and blocks unauthorized users.
 *
 * @since 1.0.0
 */
abstract class Screen {

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	abstract protected function title();

	/**
	 * Screen body.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	abstract protected function render_body();

	/**
	 * Output the screen for an authorized administrator.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render() {
		if ( ! Capabilities::current_user_can() ) {
			Capabilities::deny();
			return;
		}

		$storage_error = Storage::ensure_ready();

		echo '<div class="wrap bmx-screen">';
		echo '<h1>' . esc_html( $this->title() ) . '</h1>';
		echo '<hr class="wp-header-end" />';
		echo '<div id="bmx-live-region" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>';

		if ( is_string( $storage_error ) && '' !== $storage_error ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $storage_error )
			);
		}

		try {
			$this->render_body();
		} catch ( \Throwable $throwable ) {
			$reference = Logger::write(
				'error',
				$throwable->getMessage(),
				array(
					'type' => get_class( $throwable ),
				)
			);
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: log reference ID. */
						__( 'Something went wrong. The details were saved in the BrightMigrate log. Reference: %s', 'brightmigrate-pro' ),
						$reference
					)
				)
			);
		}

		echo '</div>';
	}
}
