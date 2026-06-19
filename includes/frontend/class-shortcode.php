<?php
/**
 * Registers the [skmctf_trials] shortcode.
 *
 * Usage:
 *   [skmctf_trials]
 *   [skmctf_trials status="RECRUITING" phase="Phase 2" per_page="10"]
 *   [skmctf_trials state="MA" columns="2"]
 *   [skmctf_trials country="United States" geolocation="1"]
 *   [skmctf_trials default_lat="40.7128" default_lng="-74.0060" default_zoom="8"]
 *
 * Attributes:
 *   status        (string)  Filter by recruitment status term name. Default: '' (all).
 *   phase         (string)  Filter by phase term name. Default: '' (all).
 *   state         (string)  Filter by US state abbreviation. Default: '' (all).
 *   country       (string)  Filter by country term name (e.g. "United States"). Default: '' (all).
 *   per_page      (int)     Trials per page. Default: 20.
 *   columns       (int)     List column count. Default: 1.
 *   default_lat   (string)  Initial map latitude (numeric, -90 to 90). Default: '' (auto-fit).
 *   default_lng   (string)  Initial map longitude (numeric, -180 to 180). Default: '' (auto-fit).
 *   default_zoom  (string)  Initial map zoom level (1–19). Default: '' (auto-fit).
 *   geolocation   (string)  Enable the "Find trials near me" geolocation button. Pass "1" to enable.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

/**
 * Registers the [skmctf_trials] shortcode and delegates rendering to List_Renderer.
 */
final class Shortcode {

	/** Shortcode tag. */
	public const TAG = 'skmctf_trials';

	/**
	 * Register the shortcode with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'handle' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,mixed>|string $atts    Shortcode attributes.
	 * @param string|null                $content Inner content (unused).
	 * @param string                     $tag     Shortcode tag (for shortcode_atts).
	 * @return string Rendered HTML.
	 */
	public static function handle( $atts, ?string $content = null, string $tag = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $content and $tag required by WP shortcode callback signature
		$atts = shortcode_atts(
			array(
				'status'       => '',
				'phase'        => '',
				'state'        => '',
				'country'      => '',
				'per_page'     => 20,
				'columns'      => 1,
				'default_lat'  => '',
				'default_lng'  => '',
				'default_zoom' => '',
				'geolocation'  => '',
			),
			$atts,
			self::TAG
		);

		return List_Renderer::render( $atts );
	}
}
