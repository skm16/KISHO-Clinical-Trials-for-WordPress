<?php
/**
 * Admin Notices — displays sync results and last-error notices on the
 * Clinical Trials settings page.
 *
 * Security: all dynamic content is escaped at output. The query arg
 * is verified but only used for a safe integer comparison — no reflection.
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Admin;

use SKMCTF\Support\Logger;

/**
 * Displays sync-result and last-error admin notices on the settings screen.
 */
final class Admin_Notices {

	/**
	 * Register the admin_notices hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render notices for the settings screen only.
	 *
	 * Shows:
	 * 1. A dismissible success/error notice after a manual sync
	 *    (driven by the `skmctf_synced` query arg).
	 * 2. A gentle warning when Logger::last_error() is non-empty.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only show on our settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_' . Settings_Page::MENU_SLUG !== $screen->id ) {
			return;
		}

		// ── 1. Manual sync result notice ────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['skmctf_synced'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$synced = sanitize_text_field( wp_unslash( $_GET['skmctf_synced'] ) );

			if ( '1' === $synced ) {
				echo '<div class="notice notice-success is-dismissible"><p>';
				echo esc_html__( 'Clinical Trials feed synced successfully.', 'kisho-clinical-trials' );
				echo '</p></div>';
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>';
				echo esc_html__( 'Sync completed with errors. Check the log for details.', 'kisho-clinical-trials' );
				echo '</p></div>';
			}
		}

		// ── 2. Cleanup result notices ────────────────────────────────────────
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skmctf_cleanup = isset( $_GET['skmctf_cleanup'] ) ? sanitize_key( wp_unslash( $_GET['skmctf_cleanup'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skmctf_clean_n = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0;
		if ( 'done' === $skmctf_cleanup ) {
			echo '<div class="notice notice-success is-dismissible"><p>';
			printf(
				/* translators: %d: number of trials deleted */
				esc_html( _n( 'Deleted %d off-condition trial.', 'Deleted %d off-condition trials.', $skmctf_clean_n, 'kisho-clinical-trials' ) ),
				(int) $skmctf_clean_n
			);
			echo '</p></div>';
		} elseif ( 'err' === $skmctf_cleanup ) {
			echo '<div class="notice notice-error is-dismissible"><p>';
			echo esc_html__( 'Cleanup preview failed: a ClinicalTrials.gov fetch error occurred. Nothing was deleted. Please try again.', 'kisho-clinical-trials' );
			echo '</p></div>';
		} elseif ( 'expired' === $skmctf_cleanup ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'The cleanup preview expired. Please run the preview again before deleting.', 'kisho-clinical-trials' );
			echo '</p></div>';
		}

		// ── 3. Persistent last-error warning ────────────────────────────────
		$log        = new Logger();
		$last_error = $log->last_error();

		if ( '' !== $last_error ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo wp_kses(
				sprintf(
					/* translators: %s: the last recorded error message */
					esc_html__( 'Last sync error: %s', 'kisho-clinical-trials' ),
					'<strong>' . esc_html( $last_error ) . '</strong>'
				),
				array( 'strong' => array() )
			);
			echo '</p></div>';
		}
	}
}
