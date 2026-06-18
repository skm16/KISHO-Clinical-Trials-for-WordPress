<?php
/**
 * Summary + attribution / disclaimer footer for a trial card.
 *
 * Expected variables (set by List_Renderer before include):
 *   string  $plain_summary      Raw HTML (wp_kses_post–sanitised by meta system).
 *   string  $brief_summary      Plain-text fallback.
 *   bool    $attribution        Whether to show "Trial display by SKM Digital".
 *
 * @package SKMCTF
 */

defined( 'ABSPATH' ) || exit;
?>

<?php if ( ! empty( $plain_summary ) ) : ?>
	<div class="skmctf-card__summary skmctf-card__summary--plain">
		<?php echo wp_kses_post( $plain_summary ); ?>
	</div>
<?php elseif ( ! empty( $brief_summary ) ) : ?>
	<div class="skmctf-card__summary skmctf-card__summary--brief">
		<?php echo esc_html( wp_trim_words( $brief_summary, 30, '&hellip;' ) ); ?>
	</div>
<?php endif; ?>

<footer class="skmctf-card__footer">
	<p class="skmctf-card__data-credit">
		<?php
		printf(
			'%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>.',
			esc_html__( 'Data from', 'kisho-clinical-trials' ),
			esc_url( 'https://clinicaltrials.gov' ),
			esc_html__( 'ClinicalTrials.gov', 'kisho-clinical-trials' )
		);
		?>
	</p>
	<?php if ( ! empty( $attribution ) ) : ?>
	<p class="skmctf-card__attribution">
		<?php esc_html_e( 'Trial display by SKM Digital.', 'kisho-clinical-trials' ); ?>
	</p>
	<?php endif; ?>
</footer>
