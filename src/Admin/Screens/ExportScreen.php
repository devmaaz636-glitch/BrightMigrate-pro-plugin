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
		echo '<p>' . esc_html( $this->description( $tab ) ) . '</p>';
		echo '</section>';
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
