<?php
/**
 * Cleanup Controller — handles the two-step off-condition cleanup admin action.
 *
 * Step 1 (preview): live re-fetch of configured conditions, compute the
 * off-condition NCT set, store it in a short-lived transient, redirect back.
 * Step 2 (confirm): delete exactly the previewed snapshot, clear the transient.
 *
 * Security: manage_options + nonce on both handlers, before any work. The
 * automatic sync and its no-wipe guards are not involved.
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Admin;

use SKMCTF\Sync\Condition_Cleanup;
use SKMCTF\Sync\Ctgov_Client;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;

/**
 * Handles the preview and confirm admin-post actions for off-condition cleanup.
 */
final class Cleanup_Controller {

	/** Preview admin-post action name. */
	public const ACTION_PREVIEW = 'skmctf_cleanup_preview';

	/** Confirm admin-post action name. */
	public const ACTION_CONFIRM = 'skmctf_cleanup_confirm';

	/** Transient key holding the previewed off-condition NCT snapshot. */
	public const TRANSIENT = 'skmctf_cleanup_preview';

	/**
	 * Register both admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_CONFIRM, array( $this, 'handle_confirm' ) );
	}

	/**
	 * Build the cleanup service with production dependencies.
	 *
	 * @return Condition_Cleanup
	 */
	private function service(): Condition_Cleanup {
		return new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
	}

	/**
	 * Guard: capability + nonce, or die.
	 *
	 * @param string $action The action name to verify the nonce against.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do this.', 'kisho-clinical-trials' ),
				esc_html__( 'Forbidden', 'kisho-clinical-trials' ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the settings page with a cleanup status arg.
	 *
	 * @param array<string,string|int> $args Extra query args.
	 * @return void
	 */
	private function redirect( array $args ): void {
		$redirect = add_query_arg(
			array_merge( array( 'page' => Settings_Page::MENU_SLUG ), $args ),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the preview request: compute and snapshot the off-condition set.
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		$this->guard( self::ACTION_PREVIEW );

		$preview = $this->service()->preview();

		if ( $preview['had_error'] ) {
			delete_transient( self::TRANSIENT );
			$this->redirect( array( 'skmctf_cleanup' => 'err' ) );
		}

		set_transient( self::TRANSIENT, $preview['off_condition'], 15 * MINUTE_IN_SECONDS );
		$this->redirect(
			array(
				'skmctf_cleanup' => 'preview',
				'n'              => count( $preview['off_condition'] ),
			)
		);
	}

	/**
	 * Handle the confirm request: delete exactly the previewed snapshot.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		$this->guard( self::ACTION_CONFIRM );

		$snapshot = get_transient( self::TRANSIENT );
		if ( ! is_array( $snapshot ) ) {
			$this->redirect( array( 'skmctf_cleanup' => 'expired' ) );
		}

		$deleted = $this->service()->delete( $snapshot );
		delete_transient( self::TRANSIENT );

		$this->redirect(
			array(
				'skmctf_cleanup' => 'done',
				'n'              => (int) $deleted,
			)
		);
	}
}
