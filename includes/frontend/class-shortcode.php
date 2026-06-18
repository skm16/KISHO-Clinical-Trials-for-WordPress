<?php
/**
 * Registers the [skmctf_trials] shortcode.
 *
 * Usage:
 *   [skmctf_trials]
 *   [skmctf_trials status="RECRUITING" phase="Phase 2" per_page="10"]
 *   [skmctf_trials state="MA" columns="2"]
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

final class Shortcode {

	/** Shortcode tag. */
	public const TAG = 'skmctf_trials';

	/**
	 * Register the shortcode with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_shortcode( self::TAG, [ self::class, 'handle' ] );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,mixed>|string $atts    Shortcode attributes.
	 * @param string|null                $content Inner content (unused).
	 * @param string                     $tag     Shortcode tag (for shortcode_atts).
	 * @return string Rendered HTML.
	 */
	public static function handle( $atts, ?string $content = null, string $tag = '' ): string {
		$atts = shortcode_atts(
			[
				'status'   => '',
				'phase'    => '',
				'state'    => '',
				'per_page' => 20,
				'columns'  => 1,
			],
			$atts,
			self::TAG
		);

		return List_Renderer::render( $atts );
	}
}
