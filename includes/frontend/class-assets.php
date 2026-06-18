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

	/** Leaflet CSS handle (bundled). */
	public const LEAFLET_STYLE_HANDLE = 'skmctf-leaflet';

	/** Leaflet JS handle (bundled). */
	public const LEAFLET_SCRIPT_HANDLE = 'skmctf-leaflet-js';

	/** Map initialisation script handle. */
	public const MAP_SCRIPT_HANDLE = 'skmctf-map';

	/**
	 * Hook into wp_enqueue_scripts to register assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'do_register' ) );
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
			array(),
			SKMCTF_VERSION
		);

		wp_register_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/filters.js', SKMCTF_FILE ),
			array(),
			SKMCTF_VERSION,
			true // load in footer
		);

		// Bundled Leaflet 1.9.4 (no CDN).
		wp_register_style(
			self::LEAFLET_STYLE_HANDLE,
			plugins_url( 'assets/lib/leaflet/leaflet.css', SKMCTF_FILE ),
			array(),
			'1.9.4'
		);

		wp_register_script(
			self::LEAFLET_SCRIPT_HANDLE,
			plugins_url( 'assets/lib/leaflet/leaflet.js', SKMCTF_FILE ),
			array(),
			'1.9.4',
			true // load in footer
		);

		wp_register_script(
			self::MAP_SCRIPT_HANDLE,
			plugins_url( 'assets/js/map.js', SKMCTF_FILE ),
			array( self::LEAFLET_SCRIPT_HANDLE ),
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

	/**
	 * Enqueue the bundled Leaflet + map init scripts/styles.
	 *
	 * Called by List_Renderer when the map is enabled and coordinates exist.
	 * Leaflet assets are local — no CDN, no external JS/CSS at enqueue time.
	 *
	 * @return void
	 */
	public static function enqueue_map(): void {
		wp_enqueue_style( self::LEAFLET_STYLE_HANDLE );
		wp_enqueue_script( self::LEAFLET_SCRIPT_HANDLE );
		wp_enqueue_script( self::MAP_SCRIPT_HANDLE );
	}
}
