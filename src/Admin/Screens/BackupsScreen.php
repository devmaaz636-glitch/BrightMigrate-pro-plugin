<?php
/**
 * Backups screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin\Screens;

use BrightIdeas\Migrate\Admin\Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archive list. Empty until an export writes a backup row.
 *
 * @since 1.0.0
 */
class BackupsScreen extends Screen {

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Backups', 'brightmigrate-pro' );
	}

	/**
	 * Empty archive table.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		$columns = array(
			__( 'Name', 'brightmigrate-pro' ),
			__( 'Type', 'brightmigrate-pro' ),
			__( 'Size', 'brightmigrate-pro' ),
			__( 'Date', 'brightmigrate-pro' ),
			__( 'Source site', 'brightmigrate-pro' ),
			__( 'Actions', 'brightmigrate-pro' ),
		);

		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Stored archives', 'brightmigrate-pro' ) . '</caption>';
		echo '<thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody><tr><td colspan="6">' . esc_html__( 'No archives yet.', 'brightmigrate-pro' ) . '</td></tr></tbody></table>';
	}
}
