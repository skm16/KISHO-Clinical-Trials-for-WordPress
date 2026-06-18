<?php
/**
 * Eligibility criteria partial for the single trial view.
 *
 * Expected variables (set by caller):
 *   array $eligibility  Associative array with keys: sex, min_age, max_age, criteria.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

$elig_sex      = ! empty( $eligibility['sex'] )      ? $eligibility['sex']      : '';
$elig_min_age  = ! empty( $eligibility['min_age'] )  ? $eligibility['min_age']  : '';
$elig_max_age  = ! empty( $eligibility['max_age'] )  ? $eligibility['max_age']  : '';
$elig_criteria = ! empty( $eligibility['criteria'] ) ? $eligibility['criteria'] : '';

if ( ! $elig_sex && ! $elig_min_age && ! $elig_max_age && ! $elig_criteria ) {
	return;
}
?>
<section class="skmctf-trial__eligibility" aria-labelledby="skmctf-eligibility-heading">
	<h2 class="skmctf-trial__section-heading" id="skmctf-eligibility-heading">
		<?php esc_html_e( 'Eligibility', 'kisho-clinical-trials' ); ?>
	</h2>

	<?php if ( $elig_sex || $elig_min_age || $elig_max_age ) : ?>
	<dl class="skmctf-trial__eligibility-meta">
		<?php if ( $elig_sex ) : ?>
		<div class="skmctf-trial__eligibility-row">
			<dt class="skmctf-trial__eligibility-label"><?php esc_html_e( 'Sex', 'kisho-clinical-trials' ); ?></dt>
			<dd class="skmctf-trial__eligibility-value"><?php echo esc_html( $elig_sex ); ?></dd>
		</div>
		<?php endif; ?>

		<?php if ( $elig_min_age ) : ?>
		<div class="skmctf-trial__eligibility-row">
			<dt class="skmctf-trial__eligibility-label"><?php esc_html_e( 'Minimum age', 'kisho-clinical-trials' ); ?></dt>
			<dd class="skmctf-trial__eligibility-value"><?php echo esc_html( $elig_min_age ); ?></dd>
		</div>
		<?php endif; ?>

		<?php if ( $elig_max_age ) : ?>
		<div class="skmctf-trial__eligibility-row">
			<dt class="skmctf-trial__eligibility-label"><?php esc_html_e( 'Maximum age', 'kisho-clinical-trials' ); ?></dt>
			<dd class="skmctf-trial__eligibility-value"><?php echo esc_html( $elig_max_age ); ?></dd>
		</div>
		<?php endif; ?>
	</dl>
	<?php endif; ?>

	<?php if ( $elig_criteria ) : ?>
	<div class="skmctf-trial__eligibility-criteria">
		<h3 class="skmctf-trial__subsection-heading"><?php esc_html_e( 'Inclusion/Exclusion Criteria', 'kisho-clinical-trials' ); ?></h3>
		<div class="skmctf-trial__criteria-text">
			<?php echo nl2br( esc_html( $elig_criteria ) ); ?>
		</div>
	</div>
	<?php endif; ?>
</section>
