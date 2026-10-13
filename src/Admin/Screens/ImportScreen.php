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
 * Drag-and-drop upload that reads an archive summary before any restore.
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
	 * Upload area and the summary filled in by the browser.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		echo '<div class="bmx-panel bmx-drop" data-bmx-drop>';
		echo '<p class="bmx-drop-title" id="bmx-drop-label">' . esc_html__( 'Drag and drop a .wpress file to import it', 'brightmigrate-pro' ) . '</p>';
		echo '<div class="bmx-launch">';
		echo '<button type="button" class="bmx-split" data-bmx-pick>';
		echo '<span class="bmx-split-label">' . esc_html__( 'Import from', 'brightmigrate-pro' ) . '</span>';
		echo '<span class="bmx-split-target">' . esc_html__( 'File', 'brightmigrate-pro' ) . '</span>';
		echo '</button></div>';
		echo '<input class="screen-reader-text" type="file" accept=".wpress,.bmx,application/octet-stream" data-bmx-file aria-labelledby="bmx-drop-label" />';
		echo '<p class="bmx-password"><label>' . esc_html__( 'Password, if the archive is encrypted', 'brightmigrate-pro' );
		echo '<input type="password" data-bmx-upload-password autocomplete="current-password" /></label></p>';
		echo '</div>';

		echo '<section class="bmx-summary" hidden data-bmx-summary aria-labelledby="bmx-summary-heading">';
		echo '<h2 id="bmx-summary-heading">' . esc_html__( 'Archive summary', 'brightmigrate-pro' ) . '</h2>';
		echo '<dl>';
		echo '<dt>' . esc_html__( 'File', 'brightmigrate-pro' ) . '</dt><dd data-bmx-sum="file"></dd>';
		echo '<dt>' . esc_html__( 'Type', 'brightmigrate-pro' ) . '</dt><dd data-bmx-sum="type"></dd>';
		echo '<dt>' . esc_html__( 'Source site', 'brightmigrate-pro' ) . '</dt><dd data-bmx-sum="source_url"></dd>';
		echo '<dt>' . esc_html__( 'Files', 'brightmigrate-pro' ) . '</dt><dd data-bmx-sum="files"></dd>';
		echo '<dt>' . esc_html__( 'Database parts', 'brightmigrate-pro' ) . '</dt><dd data-bmx-sum="database_parts"></dd>';
		echo '<dt>' . esc_html__( 'Created', 'brightmigrate-pro' ) . '</dt><dd data-bmx-sum="created_at"></dd>';
		echo '</dl>';
		echo '<p>' . esc_html__( 'The archive is stored and listed under Backups. Replacing this site comes next and is not done from this screen yet.', 'brightmigrate-pro' ) . '</p>';
		echo '</section>';
	}
}
