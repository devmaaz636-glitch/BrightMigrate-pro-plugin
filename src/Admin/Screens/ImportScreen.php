<?php
/**
 * Import screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin\Screens;

use BrightIdeas\Migrate\Admin\Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import sources. No archive is selected until the engine can read one.
 *
 * @since 1.0.0
 */
class ImportScreen extends Screen {

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Import', 'brightmigrate-pro' );
	}

	/**
	 * Empty import state.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		echo '<p>' . esc_html__( 'No archive is selected.', 'brightmigrate-pro' ) . '</p>';
		echo '<section aria-labelledby="bmx-import-sources-heading">';
		echo '<h2 id="bmx-import-sources-heading">' . esc_html__( 'Where an archive can come from', 'brightmigrate-pro' ) . '</h2>';
		echo '<ul>';
		echo '<li>' . esc_html__( 'A chunked upload from this browser.', 'brightmigrate-pro' ) . '</li>';
		echo '<li>' . esc_html__( 'A file already in the protected storage folder.', 'brightmigrate-pro' ) . '</li>';
		echo '<li>' . esc_html__( 'A remote URL, downloaded in parts.', 'brightmigrate-pro' ) . '</li>';
		echo '</ul>';
		echo '</section>';
	}
}
