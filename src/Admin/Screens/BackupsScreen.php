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
			echo '<td class="bmx-row-actions">';
			printf(
				'<button type="button" class="bmx-kebab" data-bmx-file-url="%1$s" data-bmx-file-name="%2$s" aria-haspopup="menu" aria-expanded="false" aria-label="%3$s"><svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="3.2" r="1.35"></circle><circle cx="8" cy="8" r="1.35"></circle><circle cx="8" cy="12.8" r="1.35"></circle></svg></button>',
				esc_url( (string) $row['url'] ),
				esc_attr( $name ),
				esc_attr(
					sprintf(
						/* translators: %s: archive file name. */
						__( 'Actions for %s', 'brightmigrate-pro' ),
						$name
					)
				)
			);
			echo '<div class="bmx-menu" hidden role="menu">';
			echo '<button type="button" role="menuitem" data-bmx-act="download">' . esc_html__( 'Download', 'brightmigrate-pro' ) . '</button>';
			echo '<button type="button" role="menuitem" data-bmx-act="restore">' . esc_html__( 'Restore', 'brightmigrate-pro' ) . '</button>';
			echo '<button type="button" role="menuitem" class="is-danger" data-bmx-act="delete">' . esc_html__( 'Delete', 'brightmigrate-pro' ) . '</button>';
			echo '</div></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
		echo '<div class="bmx-modal" hidden data-bmx-confirm role="dialog" aria-modal="true" aria-labelledby="bmx-confirm-title">';
		echo '<div class="bmx-modal-card">';
		echo '<h2 id="bmx-confirm-title" data-bmx-confirm-title></h2>';
		echo '<p class="bmx-confirm-file" data-bmx-confirm-file></p>';
		echo '<p class="bmx-modal-status" data-bmx-confirm-message></p>';
		echo '<p class="bmx-password" hidden data-bmx-confirm-password-wrap><label>' . esc_html__( 'Password, if the archive is encrypted', 'brightmigrate-pro' );
		echo '<input type="password" data-bmx-confirm-password autocomplete="current-password" /></label></p>';
		echo '<p class="bmx-actions">';
		echo '<button type="button" class="button" data-bmx-confirm-cancel>' . esc_html__( 'Cancel', 'brightmigrate-pro' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-bmx-confirm-ok></button>';
		echo '</p></div></div>';
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
