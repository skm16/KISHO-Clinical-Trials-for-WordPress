<?php
/**
 * Status badge partial.
 *
 * Expected variables (set by caller via extract or direct assignment):
 *   string $status_label  Human-readable status text (already escaped by caller).
 *   string $status_slug   CSS-safe slug (already esc_attr'd by caller).
 *
 * WCAG note: status conveyed by visible text, not colour alone.
 *
 * @package SKMCTF
 */

defined( 'ABSPATH' ) || exit;
?>
<span class="skmctf-badge skmctf-badge--<?php echo esc_attr( $status_slug ); ?>" aria-label="<?php echo esc_attr( $status_label ); ?>">
	<?php echo esc_html( $status_label ); ?>
</span>
