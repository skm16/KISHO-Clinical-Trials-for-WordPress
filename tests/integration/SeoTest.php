<?php
/**
 * Integration tests for Seo::maybe_noindex() via wp_robots filter.
 *
 * Requires the WordPress test suite (run via composer test:integration).
 * Deferred: WP test suite not available in the build environment.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Frontend\Seo;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;

final class SeoTest extends WP_UnitTestCase {

	/** @var Seo */
	private $seo;

	public function set_up(): void {
		parent::set_up();
		$this->seo = new Seo();
		$this->seo->register();
	}

	public function tear_down(): void {
		remove_filter( 'wp_robots', [ $this->seo, 'maybe_noindex' ] );
		parent::tear_down();
	}

	/**
	 * Create a trial post, optionally with a plain_summary.
	 *
	 * @param bool $with_summary Whether to add a plain_summary meta value.
	 * @return int WP post ID.
	 */
	private function make_trial( bool $with_summary ): int {
		$repo = new Trial_Repository();
		$id   = $repo->upsert( [
			'nct_id'         => 'NCT00000010',
			'brief_title'    => 'T',
			'official_title' => 'O',
			'overall_status' => 'RECRUITING',
			'phase'          => '',
			'study_type'     => '',
			'conditions'     => [],
			'lead_sponsor'   => '',
			'locations'      => [],
			'eligibility'    => [ 'sex' => '', 'min_age' => '', 'max_age' => '', 'criteria' => '' ],
			'brief_summary'  => 'b',
			'ct_last_update' => '2026-03-10',
			'ct_url'         => 'https://clinicaltrials.gov/study/NCT00000010',
		] );

		if ( $with_summary ) {
			update_post_meta( $id, Trial_Meta::KEYS['plain_summary'], 'Plain summary text.' );
		}

		return $id;
	}

	/**
	 * No plain_summary + no override → noindex must be set.
	 */
	public function test_noindex_when_no_summary_and_no_override(): void {
		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'index_singles_override' => false ] ) );
		$id = $this->make_trial( false );
		$this->go_to( get_permalink( $id ) );
		$robots = apply_filters( 'wp_robots', [] );
		$this->assertArrayHasKey( 'noindex', $robots );
	}

	/**
	 * plain_summary present → page must be indexable (no noindex key).
	 */
	public function test_indexable_when_summary_present(): void {
		$id = $this->make_trial( true );
		$this->go_to( get_permalink( $id ) );
		$robots = apply_filters( 'wp_robots', [] );
		$this->assertArrayNotHasKey( 'noindex', $robots );
	}

	/**
	 * Override on → always indexable even without summary.
	 */
	public function test_override_forces_index(): void {
		update_option( Settings::OPTION, array_merge( Settings::all(), [ 'index_singles_override' => true ] ) );
		$id = $this->make_trial( false );
		$this->go_to( get_permalink( $id ) );
		$robots = apply_filters( 'wp_robots', [] );
		$this->assertArrayNotHasKey( 'noindex', $robots );
	}
}
