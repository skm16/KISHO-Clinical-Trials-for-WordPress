<?php
/**
 * Single clinical trial template.
 *
 * Variables injected by Single_Renderer::render() before include:
 *   int    $post_id        WP post ID.
 *   array  $meta           All trial meta (short-keyed).
 *   string $overall_status Raw status string.
 *   string $status_label   Human-readable status.
 *   string $status_slug    CSS-safe status slug.
 *   string $phase          Phase string.
 *   array  $conditions     Array of condition strings.
 *   string $sponsor        Lead sponsor name.
 *   string $plain_summary  Plain-language summary HTML (wp_kses_post-safe).
 *   string $brief_summary  Brief summary plain text.
 *   array  $eligibility    Eligibility data (sex, min_age, max_age, criteria).
 *   array  $locations      Array of location objects.
 *   string $ct_url         ClinicalTrials.gov URL.
 *   bool   $show_map       Whether to show the map.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables injected by Single_Renderer; not global assignments.
// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase -- WP template hierarchy requires single-{post_type}.php naming; post type slug contains underscore (skmctf_trial).
defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="main" class="site-main skmctf-single-trial<?php echo \SKMCTF\Frontend\Theme::has_skin() ? ' ' . esc_attr( \SKMCTF\Frontend\Theme::skin_class() ) : ''; ?>" role="main" <?php echo \SKMCTF\Frontend\Theme::mode_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mode_attr() returns a pre-escaped attribute. ?>>
	<?php
	while ( have_posts() ) :
		the_post();

		// Populate view variables directly from the post so the template works
		// on WordPress's single_template path (no external renderer call).
		// phpcs:disable WordPress.PHP.DontExtract.extract_extract
		extract( \SKMCTF\Frontend\Single_Renderer::view_data( get_the_ID() ) );
		// phpcs:enable
		?>

	<article id="skmctf-trial-<?php echo esc_attr( (string) $post_id ); ?>"
		class="skmctf-trial"
		aria-labelledby="skmctf-trial-title">

		<header class="skmctf-trial__header">
			<h1 class="skmctf-trial__title" id="skmctf-trial-title">
				<?php echo esc_html( get_the_title() ); ?>
			</h1>

			<?php if ( ! empty( $official_title ) && get_the_title() !== $official_title ) : ?>
			<p class="skmctf-trial__official-title">
				<span class="skmctf-trial__label"><?php esc_html_e( 'Official title:', 'kisho-clinical-trials' ); ?></span>
				<?php echo esc_html( $official_title ); ?>
			</p>
			<?php endif; ?>

			<?php if ( $overall_status ) : ?>
				<?php
				// Status badge partial.
				try {
					include \SKMCTF\Support\Template_Loader::locate( 'parts/badge.php' );
				} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- badge template missing; skip gracefully
					// Badge template missing — skip gracefully.
				}
				?>
			<?php endif; ?>
		</header>

		<div class="skmctf-trial__body">

			<?php
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/study-purpose.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- part missing; skip gracefully
				// Part missing — skip gracefully.
			}
			?>

			<?php if ( $phase ) : ?>
			<p class="skmctf-trial__phase">
				<span class="skmctf-trial__label"><?php esc_html_e( 'Phase:', 'kisho-clinical-trials' ); ?></span>
				<?php echo esc_html( $phase ); ?>
			</p>
			<?php endif; ?>

			<?php if ( $conditions ) : ?>
			<p class="skmctf-trial__conditions">
				<span class="skmctf-trial__label"><?php esc_html_e( 'Conditions:', 'kisho-clinical-trials' ); ?></span>
				<?php echo esc_html( implode( ', ', $conditions ) ); ?>
			</p>
			<?php endif; ?>

			<?php if ( $sponsor ) : ?>
			<p class="skmctf-trial__sponsor">
				<span class="skmctf-trial__label"><?php esc_html_e( 'Sponsor:', 'kisho-clinical-trials' ); ?></span>
				<?php echo esc_html( $sponsor ); ?>
			</p>
			<?php endif; ?>

			<?php if ( ! empty( $plain_summary ) ) : ?>
			<section class="skmctf-trial__summary skmctf-trial__summary--plain"
				aria-labelledby="skmctf-summary-heading">
				<h2 class="skmctf-trial__section-heading" id="skmctf-summary-heading">
					<?php esc_html_e( 'Plain-Language Summary', 'kisho-clinical-trials' ); ?>
				</h2>
				<div class="skmctf-trial__summary-content">
					<?php echo wp_kses_post( $plain_summary ); ?>
				</div>
			</section>
			<?php elseif ( ! empty( $brief_summary ) ) : ?>
			<section class="skmctf-trial__summary skmctf-trial__summary--brief"
				aria-labelledby="skmctf-summary-heading">
				<h2 class="skmctf-trial__section-heading" id="skmctf-summary-heading">
					<?php esc_html_e( 'Summary', 'kisho-clinical-trials' ); ?>
				</h2>
				<p class="skmctf-trial__summary-content">
					<?php echo esc_html( $brief_summary ); ?>
				</p>
			</section>
			<?php endif; ?>

			<?php
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/who-can-join.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- part missing; skip gracefully
				// Part missing — skip gracefully.
			}
			?>

			<?php
			// Eligibility partial.
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/eligibility.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- eligibility template missing; skip gracefully
				// Eligibility template missing — skip gracefully.
			}
			?>

			<?php
			// Locations partial (includes optional map).
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/locations.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- locations template missing; skip gracefully
				// Locations template missing — skip gracefully.
			}
			?>

			<?php
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/questions.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- part missing; skip gracefully
				// Part missing — skip gracefully.
			}
			?>

			<?php if ( $ct_url ) : ?>
			<p class="skmctf-trial__ctgov-link-wrap">
				<a class="skmctf-trial__ctgov-link"
					href="<?php echo esc_url( $ct_url ); ?>"
					target="_blank"
					rel="noopener noreferrer">
					<?php esc_html_e( 'View full record on ClinicalTrials.gov', 'kisho-clinical-trials' ); ?>
					<span class="screen-reader-text">
						<?php
						/* translators: %s: trial title. */
						printf( esc_html__( '(opens %s on ClinicalTrials.gov in a new tab)', 'kisho-clinical-trials' ), esc_html( get_the_title() ) );
						?>
					</span>
				</a>
			</p>
			<?php endif; ?>

			<footer class="skmctf-trial__footer">
				<p class="skmctf-trial__data-credit">
					<?php
					printf(
						'%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a>.',
						esc_html__( 'Data from', 'kisho-clinical-trials' ),
						esc_url( 'https://clinicaltrials.gov' ),
						esc_html__( 'ClinicalTrials.gov', 'kisho-clinical-trials' )
					);
					?>
				</p>
			</footer>

		</div><!-- .skmctf-trial__body -->

	</article>

	<?php endwhile; ?>
</main>

<?php get_footer(); ?>
