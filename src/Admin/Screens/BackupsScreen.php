<?php
/**
 * Backups screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin\Screens;

use BrightIdeas\Migrate\Admin\Screen;
use BrightIdeas\Migrate\Core\Format;
use BrightIdeas\Migrate\Export\SiteExport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Archives saved in the protected storage folder.
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
	 * Table of .bmx files with a download link for each.
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
		$rows    = SiteExport::archives();

		echo '<div class="bmx-panel bmx-backups">';
		echo '<table class="widefat striped">';
		echo '<caption class="screen-reader-text">' . esc_html__( 'Stored archives', 'brightmigrate-pro' ) . '</caption>';
		echo '<thead><tr>';
		foreach ( $columns as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No archives yet.', 'brightmigrate-pro' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			$name = (string) $row['name'];
			echo '<tr>';
			echo '<th scope="row">' . esc_html( $name ) . '</th>';
			echo '<td>' . esc_html( $this->archive_type( $name ) ) . '</td>';
			echo '<td>' . esc_html( Format::bytes( $row['size'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->archive_date( (int) $row['date'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->archive_source( $name ) ) . '</td>';
			printf(
				'<td><button type="button" class="button bmx-file-download" data-bmx-file-url="%1$s" data-bmx-file-name="%2$s">%3$s</button></td>',
				esc_url( (string) $row['url'] ),
				esc_attr( $name ),
				esc_html__( 'Download', 'brightmigrate-pro' )
			);
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Label from the file name this plugin writes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function archive_type( $name ) {
		if ( false !== strpos( $name, '-full-' ) ) {
			return __( 'Full site', 'brightmigrate-pro' );
		}
		if ( false !== strpos( $name, '-import-' ) ) {
			return __( 'Uploaded archive', 'brightmigrate-pro' );
		}
		return __( 'Archive', 'brightmigrate-pro' );
	}

	/**
	 * Where the file came from, as far as the name shows.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private function archive_source( $name ) {
		if ( false !== strpos( $name, '-import-' ) ) {
			return __( 'Uploaded to this site', 'brightmigrate-pro' );
		}
		return __( 'This site', 'brightmigrate-pro' );
	}

	/**
	 * Local date for a file modification time.
	 *
	 * @since 1.0.0
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private function archive_date( $timestamp ) {
		if ( $timestamp < 1 ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}
}
