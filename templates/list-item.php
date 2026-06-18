<?php
/**
 * Single trial card template.
 *
 * Expected variables injected by List_Renderer before include:
 *   WP_Post $post            The trial post.
 *   array   $meta            Associative array of meta values keyed by short name.
 *   array   $display_fields  Which fields to show (from Settings::display_fields()).
 *   bool    $single_pages    Whether single CPT pages are enabled.
 *   bool    $attribution     Whether to append the SKM Digital credit line.
 *
 * @package SKMCTF
 */

defined( 'ABSPATH' ) || exit;

// ---  Helpers ----------------------------------------------------------------
$show = static function ( string $field ) use ( $display_fields ): bool {
	return in_array( $field, $display_fields, true );
};

// --- Title / link ------------------------------------------------------------
$title   = get_the_title( $post );
$ct_url  = ! empty( $meta['ct_url'] ) ? $meta['ct_url'] : '';

if ( $single_pages ) {
	$title_link = get_permalink( $post );
} elseif ( $ct_url ) {
	$title_link = $ct_url;
} else {
	$title_link = '';
}

// --- Status badge ------------------------------------------------------------
$overall_status = ! empty( $meta['overall_status'] ) ? $meta['overall_status'] : '';
$status_label   = ucwords( strtolower( str_replace( '_', ' ', $overall_status ) ) );
$status_slug    = sanitize_html_class( strtolower( str_replace( '_', '-', $overall_status ) ) );

// --- Phase -------------------------------------------------------------------
$phase = ! empty( $meta['phase'] ) ? $meta['phase'] : '';

// --- Conditions --------------------------------------------------------------
$conditions = ! empty( $meta['conditions'] ) && is_array( $meta['conditions'] )
	? $meta['conditions']
	: [];

// --- Sponsor -----------------------------------------------------------------
$sponsor = ! empty( $meta['lead_sponsor'] ) ? $meta['lead_sponsor'] : '';

// --- Locations ---------------------------------------------------------------
$locations = ! empty( $meta['locations'] ) && is_array( $meta['locations'] ) ? $meta['locations'] : [];
$location_parts = [];
foreach ( $locations as $loc ) {
	$pieces = array_filter( [
		! empty( $loc['city'] )    ? $loc['city']    : '',
		! empty( $loc['state'] )   ? $loc['state']   : '',
		! empty( $loc['country'] ) ? $loc['country'] : '',
	] );
	if ( $pieces ) {
		$location_parts[] = implode( ', ', $pieces );
	}
}
$location_summary = implode( ' | ', array_slice( $location_parts, 0, 3 ) );
if ( count( $location_parts ) > 3 ) {
	/* translators: %d is the number of additional locations. */
	$location_summary .= ' ' . sprintf( esc_html__( '(+%d more)', 'kisho-clinical-trials' ), count( $location_parts ) - 3 );
}

// --- Summaries ---------------------------------------------------------------
$plain_summary = ! empty( $meta['plain_summary'] ) ? $meta['plain_summary'] : '';
$brief_summary = ! empty( $meta['brief_summary'] ) ? $meta['brief_summary'] : '';
?>
<li class="skmctf-card" id="skmctf-trial-<?php echo esc_attr( (string) $post->ID ); ?>">
	<article aria-labelledby="skmctf-title-<?php echo esc_attr( (string) $post->ID ); ?>">

		<header class="skmctf-card__header">
			<h2 class="skmctf-card__title" id="skmctf-title-<?php echo esc_attr( (string) $post->ID ); ?>">
				<?php if ( $title_link ) : ?>
					<a href="<?php echo esc_url( $title_link ); ?>"
					   <?php if ( ! $single_pages && $ct_url ) : ?>
					   target="_blank" rel="noopener noreferrer"
					   <?php endif; ?>>
						<?php echo esc_html( $title ); ?>
					</a>
				<?php else : ?>
					<?php echo esc_html( $title ); ?>
				<?php endif; ?>
			</h2>

			<?php if ( $show( 'status' ) && $overall_status ) : ?>
				<?php
				// Include badge partial.
				include \SKMCTF\Support\Template_Loader::locate( 'parts/badge.php' );
				?>
			<?php endif; ?>
		</header>

		<div class="skmctf-card__body">

			<?php if ( $show( 'phase' ) && $phase ) : ?>
				<p class="skmctf-card__phase">
					<span class="skmctf-card__label"><?php esc_html_e( 'Phase:', 'kisho-clinical-trials' ); ?></span>
					<?php echo esc_html( $phase ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $show( 'conditions' ) && $conditions ) : ?>
				<p class="skmctf-card__conditions">
					<span class="skmctf-card__label"><?php esc_html_e( 'Conditions:', 'kisho-clinical-trials' ); ?></span>
					<?php echo esc_html( implode( ', ', $conditions ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $show( 'sponsor' ) && $sponsor ) : ?>
				<p class="skmctf-card__sponsor">
					<span class="skmctf-card__label"><?php esc_html_e( 'Sponsor:', 'kisho-clinical-trials' ); ?></span>
					<?php echo esc_html( $sponsor ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $show( 'locations' ) && $location_summary ) : ?>
				<p class="skmctf-card__locations">
					<span class="skmctf-card__label"><?php esc_html_e( 'Locations:', 'kisho-clinical-trials' ); ?></span>
					<?php echo esc_html( $location_summary ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $show( 'summary' ) ) : ?>
				<?php include \SKMCTF\Support\Template_Loader::locate( 'parts/summary-disclaimer.php' ); ?>
			<?php else : ?>
				<?php // Always show the data credit even when summary field is hidden. ?>
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
			<?php endif; ?>

			<?php if ( $ct_url ) : ?>
				<a class="skmctf-card__ctgov-link"
				   href="<?php echo esc_url( $ct_url ); ?>"
				   target="_blank"
				   rel="noopener noreferrer">
					<?php esc_html_e( 'View on ClinicalTrials.gov', 'kisho-clinical-trials' ); ?>
					<span class="screen-reader-text"><?php echo esc_html( ' (' . get_the_title( $post ) . ')' ); ?></span>
				</a>
			<?php endif; ?>

		</div><!-- .skmctf-card__body -->

	</article>
</li><!-- .skmctf-card -->
