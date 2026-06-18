<?php
/**
 * Template router: swaps in plugin-bundled (or theme-overridden) templates
 * for single skmctf_trial and the archive via WP's template filters.
 *
 * When single pages are disabled: incoming requests for a single trial are
 * redirected (302) to the post-type archive to prevent 404s while keeping
 * the archive as the authoritative ranking page.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Admin\Settings;
use SKMCTF\Support\Template_Loader;

final class Template_Router {

	/**
	 * Register template filters and, when singles are disabled, the redirect hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'single_template', array( $this, 'single_template' ) );
		add_filter( 'archive_template', array( $this, 'archive_template' ) );

		// When single pages are disabled, redirect to the archive early.
		if ( ! Settings::single_pages_enabled() ) {
			add_action( 'template_redirect', array( $this, 'redirect_singles_to_archive' ) );
		}
	}

	/**
	 * Return the plugin single template for skmctf_trial (theme-override aware).
	 *
	 * @param string $template Default template path from WP.
	 * @return string
	 */
	public function single_template( string $template ): string {
		if ( ! is_singular( Trial_Post_Type::POST_TYPE ) ) {
			return $template;
		}

		try {
			return Template_Loader::locate( 'single-skmctf_trial.php' );
		} catch ( \RuntimeException $e ) {
			return $template;
		}
	}

	/**
	 * Return the plugin archive template for skmctf_trial (theme-override aware).
	 *
	 * @param string $template Default template path from WP.
	 * @return string
	 */
	public function archive_template( string $template ): string {
		if ( ! is_post_type_archive( Trial_Post_Type::POST_TYPE ) ) {
			return $template;
		}

		try {
			return Template_Loader::locate( 'archive-skmctf_trial.php' );
		} catch ( \RuntimeException $e ) {
			return $template;
		}
	}

	/**
	 * Redirect single trial requests to the archive when single pages are off.
	 *
	 * Guards against redirect loops by checking we are NOT already on the archive.
	 *
	 * @return void
	 */
	public function redirect_singles_to_archive(): void {
		if ( ! is_singular( Trial_Post_Type::POST_TYPE ) ) {
			return;
		}

		$archive_url = get_post_type_archive_link( Trial_Post_Type::POST_TYPE );

		// Guard: if for any reason the archive URL cannot be resolved, bail.
		if ( ! $archive_url ) {
			return;
		}

		wp_safe_redirect( $archive_url, 302 );
		exit;
	}
}
