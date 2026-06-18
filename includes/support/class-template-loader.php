<?php
/**
 * Theme-overridable template locator.
 *
 * Checks the active theme's kisho-clinical-trials/ subfolder first,
 * then falls back to the plugin's templates/ directory.
 *
 * @package SKMCTF\Support
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Support;

/**
 * Locates templates, preferring active-theme overrides over the plugin bundle.
 */
final class Template_Loader {

	/**
	 * Locate a named template, preferring a theme override.
	 *
	 * @param string $name Template name relative to the templates root,
	 *                     e.g. 'list-item.php' or 'parts/badge.php'.
	 * @return string Absolute path to the template file.
	 * @throws \RuntimeException When the template cannot be found.
	 */
	public static function locate( string $name ): string {
		// 1. Active theme override.
		$theme_path = get_stylesheet_directory() . '/kisho-clinical-trials/' . $name;
		if ( file_exists( $theme_path ) ) {
			return $theme_path;
		}

		// 2. Plugin bundled template.
		$plugin_path = SKMCTF_PATH . 'templates/' . $name;
		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		throw new \RuntimeException(
			sprintf( 'kisho-clinical-trials: template not found: %s', esc_html( $name ) )
		);
	}
}
