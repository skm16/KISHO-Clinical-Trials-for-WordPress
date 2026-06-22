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

/**
 * Registers and conditionally enqueues frontend CSS and JavaScript assets.
 */
final class Assets {

	public const STYLE_HANDLE  = 'skmctf-frontend';
	public const SCRIPT_HANDLE = 'skmctf-filters';

	/** Grid/list view toggle script handle. */
	public const VIEW_TOGGLE_HANDLE = 'skmctf-view-toggle';

	/** Leaflet CSS handle (bundled). */
	public const LEAFLET_STYLE_HANDLE = 'skmctf-leaflet';

	/** Leaflet JS handle (bundled). */
	public const LEAFLET_SCRIPT_HANDLE = 'skmctf-leaflet-js';

	/** Map initialisation script handle. */
	public const MAP_SCRIPT_HANDLE = 'skmctf-map';

	/** Per-theme token stylesheet handle prefix (suffix = theme key). */
	public const THEME_STYLE_PREFIX = 'skmctf-theme-';

	/** Per-theme font stylesheet handle prefix (suffix = theme key). */
	public const FONT_STYLE_PREFIX = 'skmctf-fonts-';

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
			true // Load in footer.
		);

		wp_register_script(
			self::VIEW_TOGGLE_HANDLE,
			plugins_url( 'assets/js/view-toggle.js', SKMCTF_FILE ),
			array(),
			SKMCTF_VERSION,
			true // Load in footer.
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
			true // Load in footer.
		);

		wp_register_script(
			self::MAP_SCRIPT_HANDLE,
			plugins_url( 'assets/js/map.js', SKMCTF_FILE ),
			array( self::LEAFLET_SCRIPT_HANDLE ),
			SKMCTF_VERSION,
			true // Load in footer.
		);

		// Per-theme token + font stylesheets (registered always, enqueued on demand).
		$themes = array(
			'clinical' => array(
				'tokens' => 'assets/css/themes/clinical.css',
				'fonts'  => 'assets/css/fonts-clinical.css',
			),
			'warm'     => array(
				'tokens' => 'assets/css/themes/warm.css',
				'fonts'  => 'assets/css/fonts-warm.css',
			),
		);
		foreach ( $themes as $key => $paths ) {
			wp_register_style(
				self::FONT_STYLE_PREFIX . $key,
				plugins_url( $paths['fonts'], SKMCTF_FILE ),
				array(),
				SKMCTF_VERSION
			);
			wp_register_style(
				self::THEME_STYLE_PREFIX . $key,
				plugins_url( $paths['tokens'], SKMCTF_FILE ),
				array( self::STYLE_HANDLE, self::FONT_STYLE_PREFIX . $key ),
				SKMCTF_VERSION
			);
		}
	}

	/**
	 * Enqueue both assets (called by List_Renderer when it renders).
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
		wp_enqueue_script( self::VIEW_TOGGLE_HANDLE );
		self::enqueue_theme();
	}

	/**
	 * Enqueue the active theme's token + font stylesheets, if any.
	 *
	 * Skeleton enqueues nothing — the base frontend.css already carries the
	 * neutral default tokens.
	 *
	 * @return void
	 */
	public static function enqueue_theme(): void {
		$theme = Theme::active();
		if ( 'skeleton' === $theme ) {
			return;
		}
		wp_enqueue_style( self::FONT_STYLE_PREFIX . $theme );
		wp_enqueue_style( self::THEME_STYLE_PREFIX . $theme );
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
