<?php
/**
 * SEO: conditional noindex via wp_robots filter.
 *
 * Single trial pages are noindex UNLESS they have a plain-language summary
 * OR the admin override (index_singles_override) is on.
 * The archive is always untouched (always indexable).
 *
 * Using wp_robots (not echoing a raw <meta>) lets SEO plugins like Yoast/
 * RankMath coexist — they both filter the same array.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;

final class Seo {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'wp_robots', [ $this, 'maybe_noindex' ] );
	}

	/**
	 * Conditionally add noindex to single trial pages.
	 *
	 * Logic:
	 *   - Not a single skmctf_trial  → return unchanged.
	 *   - Override on               → return unchanged (always indexable).
	 *   - Has plain_summary         → return unchanged (indexable).
	 *   - No plain_summary          → set noindex=true, follow=true.
	 *
	 * @param array<string,mixed> $robots Robots directives array.
	 * @return array<string,mixed>
	 */
	public function maybe_noindex( array $robots ): array {
		if ( ! is_singular( Trial_Post_Type::POST_TYPE ) ) {
			return $robots;
		}

		if ( Settings::index_singles_override() ) {
			return $robots;
		}

		$has_summary = '' !== (string) get_post_meta(
			get_queried_object_id(),
			Trial_Meta::KEYS['plain_summary'],
			true
		);

		if ( ! $has_summary ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}
}
