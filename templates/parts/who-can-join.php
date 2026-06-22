<?php
/**
 * "Who can join" plain-language partial.
 *
 * Expected: string $who_can_join (small HTML <ul> built server-side, kses-safe).
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variable injected by caller.
defined( 'ABSPATH' ) || exit;

if ( empty( $who_can_join ) ) {
	return;
}
?>
<section class="skmctf-trial__who-can-join" aria-labelledby="skmctf-who-heading">
	<h2 class="skmctf-trial__section-heading" id="skmctf-who-heading">
		<?php esc_html_e( 'Who can join', 'kisho-clinical-trials' ); ?>
	</h2>
	<div class="skmctf-trial__who-content">
		<?php echo wp_kses_post( $who_can_join ); ?>
	</div>
	<p class="skmctf-trial__who-note">
		<?php esc_html_e( 'This is a simplified summary of the official criteria — confirm with the study team before making decisions.', 'kisho-clinical-trials' ); ?>
	</p>
</section>
