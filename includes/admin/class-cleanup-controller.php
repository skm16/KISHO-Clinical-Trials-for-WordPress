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

	/** Confirm-form field carrying the snapshot token (snapshot identity guard). */
	public const TOKEN_FIELD = 'skmctf_cleanup_token';

	/**
	 * Normalise a stored snapshot into { token, ncts }.
	 *
	 * Tolerates a legacy bare-list snapshot (token '') written before this guard
	 * existed, so an in-flight preview from before an upgrade does not fatal.
	 * Shared by the confirm handler and the settings view so both read the
	 * snapshot shape through one definition.
	 *
	 * @param mixed $raw Raw transient value.
	 * @return array{token:string,ncts:string[]}|null Normalised snapshot, or null if unusable.
	 */
	public static function normalise_snapshot( $raw ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( isset( $raw['ncts'] ) && is_array( $raw['ncts'] ) ) {
			return array(
				'token' => isset( $raw['token'] ) ? (string) $raw['token'] : '',
				'ncts'  => array_values( array_map( 'strval', $raw['ncts'] ) ),
			);
		}
		// Legacy shape: a bare list of NCT strings.
		return array(
			'token' => '',
			'ncts'  => array_values( array_map( 'strval', $raw ) ),
		);
	}

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

		// Defense-in-depth: the settings UI hides the preview button when no
		// conditions are configured, but a direct admin-post request bypasses
		// the UI. Refuse here with a clear message; the service refuses too.
		$has_conditions = array_filter(
			array_map( 'trim', array_map( 'strval', Settings::conditions() ) ),
			static fn( $c ) => '' !== $c
		);
		if ( empty( $has_conditions ) ) {
			delete_transient( self::TRANSIENT );
			$this->redirect( array( 'skmctf_cleanup' => 'no_conditions' ) );
		}

		$preview = $this->service()->preview();

		if ( $preview['had_error'] ) {
			delete_transient( self::TRANSIENT );
			$this->redirect( array( 'skmctf_cleanup' => 'err' ) );
		}

		// Stamp the snapshot with a one-time token. The confirm form echoes this
		// token back; a confirm only deletes when the token still matches the
		// stored snapshot — so a second preview (other admin / other tab) that
		// overwrites the transient invalidates any earlier confirm button.
		$token = wp_generate_password( 20, false );
		set_transient(
			self::TRANSIENT,
			array(
				'token' => $token,
				'ncts'  => $preview['off_condition'],
			),
			15 * MINUTE_IN_SECONDS
		);
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

		$snapshot = self::normalise_snapshot( get_transient( self::TRANSIENT ) );
		if ( null === $snapshot ) {
			$this->redirect( array( 'skmctf_cleanup' => 'expired' ) );
		}

		// Snapshot identity guard: the confirm form must echo back the exact
		// token stored with the snapshot it was rendered from. A mismatch means
		// the snapshot was re-previewed (overwritten) in the meantime, so this
		// stale confirmation must not delete the newer set.
		//
		// An empty stored token means a legacy (pre-token) snapshot lingered in
		// cache across an upgrade. hash_equals('', '') is true, so we must reject
		// it explicitly rather than let an untokenised snapshot be confirmed.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in guard().
		$submitted = isset( $_POST[ self::TOKEN_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TOKEN_FIELD ] ) ) : '';
		if ( '' === $snapshot['token'] || '' === $submitted || ! hash_equals( $snapshot['token'], $submitted ) ) {
			// Refuse only — do NOT delete the transient here. A mismatch usually
			// means another admin re-previewed; deleting would wipe THEIR fresh,
			// valid snapshot. A legacy untokenised snapshot is simply left to its
			// 15-minute expiry (or to be overwritten by the next preview). The
			// transient is cleared only on a successful confirm.
			$this->redirect( array( 'skmctf_cleanup' => 'stale' ) );
		}

		$deleted = $this->service()->delete( $snapshot['ncts'] );
		delete_transient( self::TRANSIENT );

		$this->redirect(
			array(
				'skmctf_cleanup' => 'done',
				'n'              => (int) $deleted,
			)
		);
	}
}
