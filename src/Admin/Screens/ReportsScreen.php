<?php
/**
 * Reports and logs screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin\Screens;

use BrightIdeas\Migrate\Admin\Screen;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job history and SEO Integrity Reports. Both are empty until a job runs.
 *
 * @since 1.0.0
 */
class ReportsScreen extends Screen {

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Reports & Logs', 'brightmigrate-pro' );
	}

	/**
	 * Empty report tables.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		$this->table(
			'bmx-job-history-heading',
			__( 'Job history', 'brightmigrate-pro' ),
			__( 'Job history', 'brightmigrate-pro' ),
			array( __( 'Date', 'brightmigrate-pro' ), __( 'Event', 'brightmigrate-pro' ), __( 'Reference', 'brightmigrate-pro' ) ),
			__( 'No log entries yet.', 'brightmigrate-pro' )
		);

		$this->table(
			'bmx-seo-reports-heading',
			__( 'SEO Integrity Reports', 'brightmigrate-pro' ),
			__( 'SEO Integrity Reports', 'brightmigrate-pro' ),
			array( __( 'Name', 'brightmigrate-pro' ), __( 'Date', 'brightmigrate-pro' ), __( 'Job', 'brightmigrate-pro' ) ),
			__( 'No reports yet.', 'brightmigrate-pro' )
		);
	}

	/**
	 * One empty table.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $heading_id Heading element id.
	 * @param string   $heading    Visible heading.
	 * @param string   $caption    Screen-reader caption.
	 * @param string[] $columns    Column labels.
	 * @param string   $empty_label Empty-state sentence.
	 * @return void
	 */
	private function table( $heading_id, $heading, $caption, array $columns, $empty_label ) {
		echo '<section aria-labelledby="' . esc_attr( $heading_id ) . '">';
		echo '<h2 id="' . esc_attr( $heading_id ) . '">' . esc_html( $heading ) . '</h2>';
		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html( $caption ) . '</caption>';
		echo '<thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody><tr><td colspan="' . esc_attr( (string) count( $columns ) ) . '">' . esc_html( $empty_label ) . '</td></tr></tbody></table>';
		echo '</section>';
	}
}
