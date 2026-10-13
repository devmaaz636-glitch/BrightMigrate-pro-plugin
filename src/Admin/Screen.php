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

		$this->render_progress();
		echo '</div>';
	}

	/**
	 * Shared progress window for export, import, and download.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_progress() {
		echo '<div class="bmx-modal" hidden data-bmx-modal role="dialog" aria-modal="true" aria-labelledby="bmx-modal-title">';
		echo '<div class="bmx-modal-card">';
		echo '<h2 id="bmx-modal-title" data-bmx-modal-title>' . esc_html__( 'Working', 'brightmigrate-pro' ) . '</h2>';
		echo '<p class="bmx-percent" data-bmx-percent>0%</p>';
		echo '<div class="bmx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-bmx-progress><span data-bmx-progress-bar></span></div>';
		echo '<p class="bmx-modal-status" data-bmx-modal-message></p>';
		echo '<p class="bmx-actions">';
		echo '<button type="button" class="button" data-bmx-stop>' . esc_html__( 'Stop', 'brightmigrate-pro' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-bmx-save hidden>' . esc_html__( 'Download', 'brightmigrate-pro' ) . '</button>';
		echo '<button type="button" class="button" data-bmx-copy hidden>' . esc_html__( 'Copy link', 'brightmigrate-pro' ) . '</button>';
		echo '<button type="button" class="button" data-bmx-close hidden>' . esc_html__( 'Close', 'brightmigrate-pro' ) . '</button>';
		echo '</p></div></div>';
	}
}
