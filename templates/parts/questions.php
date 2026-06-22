<?php
/**
 * "Questions to ask your doctor" partial.
 *
 * Expected: array $doctor_questions (list of plain strings).
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variable injected by caller.
defined( 'ABSPATH' ) || exit;

if ( empty( $doctor_questions ) || ! is_array( $doctor_questions ) ) {
	return;
}
?>
<section class="skmctf-trial__questions" aria-labelledby="skmctf-questions-heading">
	<h2 class="skmctf-trial__section-heading" id="skmctf-questions-heading">
		<?php esc_html_e( 'Questions to ask your doctor', 'kisho-clinical-trials' ); ?>
	</h2>
	<ul class="skmctf-trial__questions-list">
		<?php foreach ( $doctor_questions as $question ) : ?>
			<li><?php echo esc_html( $question ); ?></li>
		<?php endforeach; ?>
	</ul>
</section>
