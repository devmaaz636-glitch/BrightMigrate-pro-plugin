<?php
/**
 * Settings screen.
 *
 * @package BrightMigrate
 */

namespace BrightIdeas\Migrate\Admin;

use BrightIdeas\Migrate\Core\Capabilities;
use BrightIdeas\Migrate\Core\Options;
use BrightIdeas\Migrate\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings API form for storage, chunks, retention, email, and uninstall.
 *
 * @since 1.0.0
 */
class SettingsScreen extends Screen {

	/**
	 * Hook registration and the network-admin save handler.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'network_admin_edit_brightmigrate-settings', array( self::class, 'save_network' ) );
	}

	/**
	 * Register the setting, section, and fields.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'brightmigrate_settings',
			Options::SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'brightmigrate_main',
			__( 'Storage and processing', 'brightmigrate-pro' ),
			array( self::class, 'render_section' ),
			'brightmigrate-settings'
		);

		self::add_field( 'bmx-storage-location', __( 'Storage location', 'brightmigrate-pro' ), 'render_storage_location' );
		self::add_field( 'bmx-upload-chunk', __( 'Upload chunk size (MB)', 'brightmigrate-pro' ), 'render_upload_chunk' );
		self::add_field( 'bmx-retention', __( 'Archives to keep', 'brightmigrate-pro' ), 'render_retention' );
		self::add_field( 'bmx-notify-email', __( 'Notification email', 'brightmigrate-pro' ), 'render_email' );
		self::add_field( 'bmx-delete-archives', __( 'Uninstall', 'brightmigrate-pro' ), 'render_delete_archives' );
	}

	/**
	 * Section description.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_section() {
		echo '<p>' . esc_html__( 'Storage, upload chunk size, how many archives to keep, notices, and uninstall cleanup.', 'brightmigrate-pro' ) . '</p>';
	}

	/**
	 * Storage location field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_storage_location() {
		printf(
			'<input type="text" class="large-text code" id="bmx-storage-location" name="%1$s" value="%2$s" spellcheck="false" autocomplete="off" aria-describedby="bmx-storage-location-desc" />',
			esc_attr( Options::SETTINGS . '[storage_location]' ),
			esc_attr( (string) self::value( 'storage_location' ) )
		);
		echo '<p class="description" id="bmx-storage-location-desc">' . esc_html__( 'Leave this blank to use the private folder BrightMigrate created. An absolute path is allowed outside the public uploads, plugins, and themes folders. The parent folder must already exist.', 'brightmigrate-pro' ) . '</p>';
	}

	/**
	 * Upload chunk size field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_upload_chunk() {
		printf(
			'<input type="number" class="small-text" id="bmx-upload-chunk" name="%1$s" value="%2$s" min="0" max="10" step="1" inputmode="numeric" aria-describedby="bmx-upload-chunk-desc" />',
			esc_attr( Options::SETTINGS . '[upload_chunk_mb]' ),
			esc_attr( (string) self::value( 'upload_chunk_mb' ) )
		);
		echo '<p class="description" id="bmx-upload-chunk-desc">' . esc_html__( 'Use 0 to detect a chunk size from the server. Otherwise choose 2 to 10 MB. This does not limit how large a site can be.', 'brightmigrate-pro' ) . '</p>';
	}

	/**
	 * Retention field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_retention() {
		printf(
			'<input type="number" class="small-text" id="bmx-retention" name="%1$s" value="%2$s" min="0" max="10000" step="1" inputmode="numeric" aria-describedby="bmx-retention-desc" />',
			esc_attr( Options::SETTINGS . '[retention_count]' ),
			esc_attr( (string) self::value( 'retention_count' ) )
		);
		echo '<p class="description" id="bmx-retention-desc">' . esc_html__( 'How many archives to keep. 0 keeps every archive until you delete it.', 'brightmigrate-pro' ) . '</p>';
	}

	/**
	 * Notification email field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_email() {
		printf(
			'<input type="email" class="regular-text" id="bmx-notify-email" name="%1$s" value="%2$s" autocomplete="email" aria-describedby="bmx-notify-email-desc" />',
			esc_attr( Options::SETTINGS . '[notify_email]' ),
			esc_attr( (string) self::value( 'notify_email' ) )
		);
		echo '<p class="description" id="bmx-notify-email-desc">' . esc_html__( 'Address stored for backup notices. Leave blank to skip email.', 'brightmigrate-pro' ) . '</p>';
	}

	/**
	 * Delete-on-uninstall checkbox.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_delete_archives() {
		echo '<input type="hidden" name="' . esc_attr( Options::SETTINGS . '[delete_archives]' ) . '" value="0" />';
		printf(
			'<label for="bmx-delete-archives"><input type="checkbox" id="bmx-delete-archives" name="%1$s" value="1" %2$s /> %3$s</label>',
			esc_attr( Options::SETTINGS . '[delete_archives]' ),
			checked( '1', (string) self::value( 'delete_archives' ), false ),
			esc_html__( 'Delete stored archives when this plugin is uninstalled', 'brightmigrate-pro' )
		);
		echo '<p class="description" id="bmx-delete-archives-desc">' . esc_html__( 'Off by default. Tables and settings are always removed on uninstall. Archive files are removed only when this is on.', 'brightmigrate-pro' ) . '</p>';
	}

	/**
	 * Save handler for network admin, which cannot post to options.php.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function save_network() {
		if ( ! Capabilities::current_user_can() ) {
			Capabilities::deny();
		}

		check_admin_referer( 'brightmigrate-settings' );

		$input = array();
		if ( isset( $_POST[ Options::SETTINGS ] ) ) {
			$raw = wp_unslash( $_POST[ Options::SETTINGS ] );
			if ( is_array( $raw ) ) {
				$input = $raw;
			}
		}

		$clean = Settings::sanitize( $input );
		update_option( Options::SETTINGS, $clean, false );
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( Options::SETTINGS, false );
		}

		set_transient( 'settings_errors', get_settings_errors(), 30 );
		wp_safe_redirect( add_query_arg( 'settings-updated', '1', Urls::page( 'brightmigrate-settings' ) ) );
		exit;
	}

	/**
	 * Screen title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function title() {
		return __( 'Settings', 'brightmigrate-pro' );
	}

	/**
	 * Settings form.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	protected function render_body() {
		settings_errors( Options::SETTINGS );

		if ( is_network_admin() ) {
			echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=brightmigrate-settings' ) ) . '">';
			wp_nonce_field( 'brightmigrate-settings' );
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
			settings_fields( 'brightmigrate_settings' );
		}

		do_settings_sections( 'brightmigrate-settings' );
		submit_button( __( 'Save settings', 'brightmigrate-pro' ) );
		echo '</form>';
	}

	/**
	 * Register one labeled field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id       Input id and label target.
	 * @param string $label    Field label.
	 * @param string $callback Renderer on this class.
	 * @return void
	 */
	private static function add_field( $id, $label, $callback ) {
		add_settings_field(
			$id,
			$label,
			array( self::class, $callback ),
			'brightmigrate-settings',
			'brightmigrate_main',
			array( 'label_for' => $id )
		);
	}

	/**
	 * Stored value or the default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	private static function value( $key ) {
		$settings = get_option( Options::SETTINGS, array() );
		$defaults = Settings::defaults();
		if ( ! is_array( $settings ) || ! array_key_exists( $key, $settings ) ) {
			return $defaults[ $key ];
		}
		return $settings[ $key ];
	}
}
