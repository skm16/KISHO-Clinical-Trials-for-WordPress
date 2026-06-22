<?php
/**
 * "What this study is testing" partial.
 *
 * Expected: string $study_purpose
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variable injected by caller.
defined( 'ABSPATH' ) || exit;

if ( empty( $study_purpose ) ) {
	return;
}
?>
<p class="skmctf-trial__purpose">
	<span class="skmctf-trial__label"><?php esc_html_e( 'What this study is testing:', 'kisho-clinical-trials' ); ?></span>
	<?php echo esc_html( $study_purpose ); ?>
</p>
