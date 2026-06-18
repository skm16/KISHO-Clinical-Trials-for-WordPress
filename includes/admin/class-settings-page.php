<?php
/**
 * Settings Page — registers the WordPress Settings API option group and
 * renders the admin settings page for the Clinical Trials Feed plugin.
 *
 * Security: every output is escaped at point of use. The API key is
 * write-only (value="" always). The option is stored with autoload=no.
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Admin;

use SKMCTF\Support\Logger;

final class Settings_Page {

	/** @var string Admin menu slug. */
	public const MENU_SLUG = 'skmctf-settings';

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Hook the menu and settings registration into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the settings page under Settings > Clinical Trials.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'Clinical Trials Feed', 'kisho-clinical-trials' ),
			__( 'Clinical Trials', 'kisho-clinical-trials' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the skmctf_settings option with the sanitizer.
	 *
	 * The option is seeded with autoload=no on first access so that the
	 * stored API key is never loaded on ordinary page requests.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		// Ensure the option row exists with autoload disabled before
		// WordPress's Settings API touches it.
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, array(), '', 'no' );
		}

		register_setting(
			'skmctf_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize callback for the Settings API.
	 *
	 * Wraps Settings::sanitize() so that the existing stored value is merged
	 * correctly (the Settings API only passes new input, not existing data).
	 *
	 * @param mixed $input Raw $_POST input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (array) get_option( Settings::OPTION, array() );
		}
		$existing = (array) get_option( Settings::OPTION, array() );
		return Settings::sanitize( is_array( $input ) ? $input : array(), $existing );
	}

	// -------------------------------------------------------------------------
	// Render
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s       = Settings::all();
		$key_set = '' !== Settings::get_api_key();
		$log     = new Logger();
		$last    = $log->last_sync();
		$error   = $log->last_error();

		require SKMCTF_PATH . 'includes/admin/views/settings-page.php';
	}
}
