<?php
/**
 * Frontend asset registration.
 *
 * Registers (but does NOT enqueue) the frontend stylesheet and filter script.
 * List_Renderer calls ::enqueue() when it actually renders output so assets
 * are only loaded on pages that use the shortcode or block.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

final class Assets {

	public const STYLE_HANDLE  = 'skmctf-frontend';
	public const SCRIPT_HANDLE = 'skmctf-filters';

	/**
	 * Hook into wp_enqueue_scripts to register assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', [ self::class, 'do_register' ] );
	}

	/**
	 * Register assets with WordPress (called on wp_enqueue_scripts).
	 *
	 * @return void
	 */
	public static function do_register(): void {
		wp_register_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/frontend.css', SKMCTF_FILE ),
			[],
			SKMCTF_VERSION
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/filters.js', SKMCTF_FILE ),
			[],
			SKMCTF_VERSION,
			true // load in footer
		);
	}

	/**
	 * Enqueue both assets (called by List_Renderer when it renders).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}
}
