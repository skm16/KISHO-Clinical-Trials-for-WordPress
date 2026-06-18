<?php
/**
 * Sync-Now Controller — handles the manual sync admin-post action.
 *
 * Security: capability check + nonce verification before any work runs.
 * Redirects to the settings page with a result query arg after completion.
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Admin;

use SKMCTF\Sync\Sync_Engine;

final class Sync_Now_Controller {

	/** @var string The admin-post action name. */
	public const ACTION = 'skmctf_sync_now';

	/**
	 * Register the admin-post handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle the manual sync request.
	 *
	 * Verifies capability and nonce, runs the sync engine, then redirects
	 * back to the settings page with a success/failure indicator.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do this.', 'kisho-clinical-trials' ),
				esc_html__( 'Forbidden', 'kisho-clinical-trials' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION );

		$summary = Sync_Engine::build()->run( 'manual' );

		$success = empty( $summary['errors'] ) ? '1' : '0';

		$redirect = add_query_arg(
			array(
				'page'          => Settings_Page::MENU_SLUG,
				'skmctf_synced' => $success,
			),
			admin_url( 'options-general.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}
}
