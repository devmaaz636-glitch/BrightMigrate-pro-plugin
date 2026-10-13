<?php
/**
 * Dashboard screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin\Screens;

use BrightIdeas\Migrate\Admin\Screen;
use BrightIdeas\Migrate\Admin\Urls;
use BrightIdeas\Migrate\Core\Format;
use BrightIdeas\Migrate\Core\Requirements;
use BrightIdeas\Migrate\Core\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quick actions, recent jobs, storage, and server requirements.
 *
 * @since 1.0.0
 */
class DashboardScreen extends Screen {

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'BrightMigrate', 'brightmigrate-pro' );
	}

	/**
	 * Dashboard body.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		$this->render_actions();
		$this->render_jobs();
		$this->render_storage();
		$this->render_requirements();
	}

	/**
	 * Links to the export and import screens.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_actions() {
		echo '<section aria-labelledby="bmx-quick-actions-heading">';
		echo '<h2 id="bmx-quick-actions-heading">' . esc_html__( 'Quick actions', 'brightmigrate-pro' ) . '</h2>';
		echo '<p class="bmx-actions">';

		$actions = array(
			array(
				'url'   => add_query_arg( 'tab', 'full', Urls::page( 'brightmigrate-export' ) ),
				'label' => __( 'Full export', 'brightmigrate-pro' ),
				'class' => 'button button-primary',
			),
			array(
				'url'   => add_query_arg( 'tab', 'content', Urls::page( 'brightmigrate-export' ) ),
				'label' => __( 'Content pack', 'brightmigrate-pro' ),
				'class' => 'button',
			),
			array(
				'url'   => add_query_arg( 'tab', 'themekit', Urls::page( 'brightmigrate-export' ) ),
				'label' => __( 'Theme kit', 'brightmigrate-pro' ),
				'class' => 'button',
			),
			array(
				'url'   => Urls::page( 'brightmigrate-import' ),
				'label' => __( 'Import', 'brightmigrate-pro' ),
				'class' => 'button',
			),
		);

		foreach ( $actions as $action ) {
			printf(
				'<a class="%1$s" href="%2$s">%3$s</a>',
				esc_attr( $action['class'] ),
				esc_url( $action['url'] ),
				esc_html( $action['label'] )
			);
		}

		echo '</p>';
		echo '<p>' . esc_html__( 'There is no size limit. A large site continues in short steps, and a failed step is tried again.', 'brightmigrate-pro' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Last jobs. Empty until the job runner exists.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_jobs() {
		echo '<section aria-labelledby="bmx-recent-jobs-heading">';
		echo '<h2 id="bmx-recent-jobs-heading">' . esc_html__( 'Recent jobs', 'brightmigrate-pro' ) . '</h2>';
		echo '<table class="widefat striped"><caption class="screen-reader-text">' . esc_html__( 'Last 5 jobs', 'brightmigrate-pro' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Type', 'brightmigrate-pro' ), __( 'Status', 'brightmigrate-pro' ), __( 'Stage', 'brightmigrate-pro' ), __( 'Progress', 'brightmigrate-pro' ), __( 'Started', 'brightmigrate-pro' ) ) as $column ) {
			echo '<th scope="col">' . esc_html( $column ) . '</th>';
		}
		echo '</tr></thead><tbody><tr><td colspan="5">' . esc_html__( 'No jobs yet.', 'brightmigrate-pro' ) . '</td></tr></tbody></table>';
		echo '</section>';
	}

	/**
	 * Active storage folder and a bounded size reading.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_storage() {
		$path  = Storage::active_path();
		$usage = Storage::bounded_usage( $path );

		echo '<section aria-labelledby="bmx-storage-heading">';
		echo '<h2 id="bmx-storage-heading">' . esc_html__( 'Storage', 'brightmigrate-pro' ) . '</h2>';
		echo '<p>' . esc_html__( 'Archives are stored in this protected folder:', 'brightmigrate-pro' ) . '</p>';
		echo '<p><code>' . esc_html( $path ) . '</code></p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: size such as 12 KB. */
				__( 'Storage used: %s', 'brightmigrate-pro' ),
				Format::bytes( $usage['bytes'], $usage['truncated'] )
			)
		) . '</p>';
		echo '</section>';
	}

	/**
	 * PHP, WordPress, and extension status in plain language.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_requirements() {
		$failures    = Requirements::current_failures();
		$recommended = Requirements::missing_recommended( get_loaded_extensions() );

		echo '<section aria-labelledby="bmx-requirements-heading">';
		echo '<h2 id="bmx-requirements-heading">' . esc_html__( 'Server requirements', 'brightmigrate-pro' ) . '</h2>';

		if ( array() === $failures ) {
			echo '<p>' . esc_html__( 'Server requirements are met.', 'brightmigrate-pro' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $failures as $code ) {
				echo '<li>' . esc_html( Requirements::message( $code ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<ul>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: PHP version. */
				__( 'PHP %s is installed.', 'brightmigrate-pro' ),
				PHP_VERSION
			)
		) . '</li>';
		echo '<li>' . esc_html(
			sprintf(
				/* translators: %s: WordPress version. */
				__( 'WordPress %s is installed.', 'brightmigrate-pro' ),
				Requirements::wp_version()
			)
		) . '</li>';
		echo '</ul>';

		if ( Requirements::is_32_bit( PHP_INT_SIZE ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This server uses 32-bit PHP. Archives larger than 2 GB are not supported here.', 'brightmigrate-pro' ) . '</p></div>';
		}

		if ( $recommended ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated PHP extension names. */
					__( 'These PHP extensions are recommended but not required: %s.', 'brightmigrate-pro' ),
					implode( ', ', $recommended )
				)
			) . '</p>';
		}

		echo '<p>' . esc_html__( 'Pre-flight has not been run. It runs before an export or import starts.', 'brightmigrate-pro' ) . '</p>';
		echo '</section>';
	}
}
