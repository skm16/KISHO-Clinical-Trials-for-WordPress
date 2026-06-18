<?php
/**
 * Archive template for the skmctf_trial post type.
 *
 * This page is the PAG's "[disease] clinical trials" ranking asset.
 * It is ALWAYS indexable — the Seo filter only touches is_singular(skmctf_trial),
 * so this archive is never touched by the noindex logic.
 *
 * Uses List_Renderer for the loop (same component as the shortcode/block),
 * reading filter values from GET params so the filter form works with no JS.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<main id="main" class="site-main skmctf-archive-trials" role="main">

	<header class="skmctf-archive-trials__header page-header">
		<h1 class="skmctf-archive-trials__title page-title">
			<?php
			$archive_title = post_type_archive_title( '', false );
			echo esc_html( $archive_title ?: __( 'Clinical Trials', 'kisho-clinical-trials' ) );
			?>
		</h1>
		<?php
		$archive_description = get_the_archive_description();
		if ( $archive_description ) :
		?>
		<div class="skmctf-archive-trials__description archive-description">
			<?php echo wp_kses_post( $archive_description ); ?>
		</div>
		<?php endif; ?>
	</header>

	<div class="skmctf-archive-trials__content">
		<?php
		// Delegate entirely to List_Renderer — it handles pagination, filters,
		// query, templates, and optional map from GET params.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo \SKMCTF\Frontend\List_Renderer::render( [] );
		?>
	</div>

</main>

<?php get_footer(); ?>
