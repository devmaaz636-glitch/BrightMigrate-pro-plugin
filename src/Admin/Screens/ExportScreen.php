<?php
/**
 * Export screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin\Screens;

use BrightIdeas\Migrate\Admin\Screen;
use BrightIdeas\Migrate\Admin\Urls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export tabs for a full site, a content pack, and a theme kit.
 *
 * @since 1.0.0
 */
class ExportScreen extends Screen {

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Export', 'brightmigrate-pro' );
	}

	/**
	 * Tab list and the selected panel.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		$tab  = $this->current_tab();
		$tabs = array(
			'full'     => __( 'Full Site', 'brightmigrate-pro' ),
			'content'  => __( 'Content Pack', 'brightmigrate-pro' ),
			'themekit' => __( 'Theme Kit', 'brightmigrate-pro' ),
		);

		echo '<nav class="nav-tab-wrapper wp-clearfix" aria-label="' . esc_attr__( 'Export type', 'brightmigrate-pro' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg( 'tab', $slug, Urls::page( 'brightmigrate-export' ) );
			$class = ( $slug === $tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			if ( $slug === $tab ) {
				printf(
					'<a href="%1$s" class="%2$s" aria-current="page">%3$s</a>',
					esc_url( $url ),
					esc_attr( $class ),
					esc_html( $label )
				);
			} else {
				printf(
					'<a href="%1$s" class="%2$s">%3$s</a>',
					esc_url( $url ),
					esc_attr( $class ),
					esc_html( $label )
				);
			}
		}
		echo '</nav>';

		echo '<section aria-labelledby="bmx-export-panel-heading">';
		echo '<h2 id="bmx-export-panel-heading">' . esc_html( $tabs[ $tab ] ) . '</h2>';
		if ( 'full' === $tab ) {
			$this->render_full();
		} else {
			echo '<p>' . esc_html( $this->description( $tab ) ) . '</p>';
			echo '<p>' . esc_html__( 'Use the Full Site tab to export this site to a file. Content packs and theme kits are not available yet.', 'brightmigrate-pro' ) . '</p>';
		}
		echo '</section>';
	}

	/**
	 * Full-site export form. The browser runs the export in short steps.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_full() {
		echo '<p>' . esc_html__( 'Export this site to one .bmx file. There is no size limit in the plugin. The work runs in short steps so a large site can finish.', 'brightmigrate-pro' ) . '</p>';
		echo '<div class="bmx-card" data-bmx-export>';
		echo '<h3>' . esc_html__( 'Find & Replace', 'brightmigrate-pro' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Optional. Applied to database values while the archive is built. Serialized values keep a valid length.', 'brightmigrate-pro' ) . '</p>';
		echo '<div data-bmx-pairs>';
		echo '<div class="bmx-replace-row">';
		echo '<label>' . esc_html__( 'Find', 'brightmigrate-pro' ) . '<input type="text" data-bmx-from autocomplete="off" /></label>';
		echo '<label>' . esc_html__( 'Replace with', 'brightmigrate-pro' ) . '<input type="text" data-bmx-to autocomplete="off" /></label>';
		echo '</div>';
		echo '</div>';
		echo '<p><button type="button" class="button" data-bmx-add-pair>' . esc_html__( 'Add', 'brightmigrate-pro' ) . '</button></p>';

		echo '<details class="bmx-advanced">';
		echo '<summary>' . esc_html__( 'Advanced options', 'brightmigrate-pro' ) . '</summary>';
		echo '<fieldset class="bmx-excludes"><legend>' . esc_html__( 'Leave out of this export', 'brightmigrate-pro' ) . '</legend>';
		foreach ( $this->exclude_choices() as $key => $label ) {
			printf(
				'<label><input type="checkbox" data-bmx-exclude value="%1$s"%2$s%3$s /> %4$s</label>',
				esc_attr( $key ),
				checked( 'cache', $key, false ),
				disabled( 'cache', $key, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p><label>' . esc_html__( 'Password', 'brightmigrate-pro' );
		echo '<input type="password" data-bmx-password autocomplete="new-password" />';
		echo '</label></p>';
		echo '<p class="description">' . esc_html__( 'Optional. Encrypts the database inside the archive. The password is sent with each step and is not saved.', 'brightmigrate-pro' ) . '</p>';
		echo '</details>';

		echo '<p class="bmx-export-action">';
		echo '<button type="button" class="bmx-export-button" data-bmx-export-start>';
		echo '<span class="bmx-export-button-label">' . esc_html__( 'Export to', 'brightmigrate-pro' ) . '</span>';
		echo '<span class="bmx-export-button-target">' . esc_html__( 'File', 'brightmigrate-pro' ) . '</span>';
		echo '</button></p>';
		echo '</div>';

		echo '<div class="bmx-modal" hidden data-bmx-modal role="dialog" aria-modal="true" aria-labelledby="bmx-modal-title">';
		echo '<div class="bmx-modal-card">';
		echo '<h2 id="bmx-modal-title">' . esc_html__( 'Exporting', 'brightmigrate-pro' ) . '</h2>';
		echo '<p data-bmx-modal-message>' . esc_html__( 'Listing files…', 'brightmigrate-pro' ) . '</p>';
		echo '<div class="bmx-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-bmx-progress><span data-bmx-progress-bar></span></div>';
		echo '<p class="bmx-actions">';
		echo '<button type="button" class="button" data-bmx-stop>' . esc_html__( 'Stop', 'brightmigrate-pro' ) . '</button>';
		echo '<a class="button button-primary" data-bmx-download hidden href="#">' . esc_html__( 'Download', 'brightmigrate-pro' ) . '</a>';
		echo '<button type="button" class="button" data-bmx-copy hidden>' . esc_html__( 'Copy link', 'brightmigrate-pro' ) . '</button>';
		echo '</p></div></div>';
	}

	/**
	 * Exclude checkboxes, in screen order.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string>
	 */
	private function exclude_choices() {
		return array(
			'media'            => __( 'Media library (uploads)', 'brightmigrate-pro' ),
			'themes'           => __( 'Themes', 'brightmigrate-pro' ),
			'inactive_themes'  => __( 'Inactive themes', 'brightmigrate-pro' ),
			'plugins'          => __( 'Plugins', 'brightmigrate-pro' ),
			'inactive_plugins' => __( 'Inactive plugins', 'brightmigrate-pro' ),
			'mu_plugins'       => __( 'Must-use plugins', 'brightmigrate-pro' ),
			'cache'            => __( 'Cache files (always left out)', 'brightmigrate-pro' ),
			'spam'             => __( 'Spam comments', 'brightmigrate-pro' ),
			'revisions'        => __( 'Post revisions', 'brightmigrate-pro' ),
			'database'         => __( 'Database', 'brightmigrate-pro' ),
		);
	}

	/**
	 * Allow-listed tab from the query string.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = 'full';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only allow-listed tab. The screen already requires an administrator.
		if ( isset( $_GET['tab'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Same read-only tab as above.
			$raw = wp_unslash( $_GET['tab'] );
			if ( is_string( $raw ) ) {
				$tab = sanitize_key( $raw );
			}
		}

		$allowed = array( 'full', 'content', 'themekit' );
		if ( ! in_array( $tab, $allowed, true ) ) {
			return 'full';
		}

		return $tab;
	}

	/**
	 * One-sentence description of an export type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private function description( $tab ) {
		if ( 'content' === $tab ) {
			return __( 'A content pack moves pages, posts, the media they use, and SEO data. The active theme, menus, widgets, and site settings stay as they are.', 'brightmigrate-pro' );
		}

		if ( 'themekit' === $tab ) {
			return __( 'A theme kit moves the design layer: the theme, selected design pages, templates, and Elementor kit data. Other pages and posts are not included.', 'brightmigrate-pro' );
		}

		return __( 'A full site export packages the database tables for this site and wp-content into one .bmx archive.', 'brightmigrate-pro' );
	}
}
