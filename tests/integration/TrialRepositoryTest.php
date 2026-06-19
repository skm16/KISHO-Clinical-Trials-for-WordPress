<?php
/**
 * Integration tests for Trial_Repository.
 *
 * Requires a real WordPress test database. Run via:
 *   composer test:integration -- --filter TrialRepositoryTest
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Post_Types\Trial_Taxonomies;

final class TrialRepositoryTest extends WP_UnitTestCase {

	private function meta( string $nct, string $status = 'RECRUITING' ): array {
		return [
			'nct_id'         => $nct,
			'brief_title'    => 'T ' . $nct,
			'official_title' => 'O',
			'overall_status' => $status,
			'phase'          => 'Phase 2',
			'study_type'     => 'INTERVENTIONAL',
			'conditions'     => [ 'Pompe Disease' ],
			'lead_sponsor'   => 'S',
			'locations'      => [],
			'eligibility'    => [ 'sex' => 'ALL', 'min_age' => '', 'max_age' => '', 'criteria' => '' ],
			'brief_summary'  => 'b',
			'ct_last_update' => '2026-03-10',
			'ct_url'         => 'https://clinicaltrials.gov/study/' . $nct,
		];
	}

	public function test_insert_then_update_keeps_single_post(): void {
		$repo = new Trial_Repository();
		$id1  = $repo->upsert( $this->meta( 'NCT00000001' ) );
		$id2  = $repo->upsert( $this->meta( 'NCT00000001', 'COMPLETED' ) );
		$this->assertSame( $id1, $id2 );
		$this->assertSame( 'COMPLETED', get_post_meta( $id1, Trial_Meta::KEYS['overall_status'], true ) );
		$this->assertSame( 'Pompe Disease', get_post_meta( $id1, Trial_Meta::KEYS['conditions'], true )[0] );
		$terms = wp_get_object_terms( $id1, 'trial_status', [ 'fields' => 'names' ] );
		$this->assertContains( 'COMPLETED', $terms );
	}

	public function test_all_nct_ids_and_mark_closed(): void {
		$repo = new Trial_Repository();
		$repo->upsert( $this->meta( 'NCT00000002' ) );
		$this->assertContains( 'NCT00000002', $repo->all_nct_ids() );
		$repo->mark_closed( 'NCT00000002' );
		$id = $repo->find_id_by_nct( 'NCT00000002' );
		$this->assertSame( 'CLOSED', get_post_meta( $id, Trial_Meta::KEYS['overall_status'], true ) );
	}

	/**
	 * Upsert a trial with two distinct-country locations → both country terms must be present.
	 */
	public function test_upsert_two_country_locations_assigns_both_terms(): void {
		$repo = new Trial_Repository();
		$m    = $this->meta( 'NCT00000010' );
		$m['locations'] = array(
			array(
				'facility' => 'Hospital A',
				'city'     => 'New York',
				'state'    => 'NY',
				'country'  => 'United States',
				'status'   => 'RECRUITING',
				'lat'      => 40.71,
				'lng'      => -74.01,
			),
			array(
				'facility' => 'Clinic B',
				'city'     => 'London',
				'state'    => '',
				'country'  => 'United Kingdom',
				'status'   => 'RECRUITING',
				'lat'      => 51.51,
				'lng'      => -0.13,
			),
		);
		$id    = $repo->upsert( $m );
		$terms = wp_get_object_terms( $id, Trial_Taxonomies::COUNTRY, array( 'fields' => 'names' ) );
		$this->assertContains( 'United States', $terms );
		$this->assertContains( 'United Kingdom', $terms );
		$this->assertCount( 2, $terms );
	}

	/**
	 * Upsert a trial with duplicate-country locations → only one distinct term must be assigned.
	 */
	public function test_upsert_duplicate_country_locations_assigns_one_term(): void {
		$repo = new Trial_Repository();
		$m    = $this->meta( 'NCT00000011' );
		$m['locations'] = array(
			array(
				'facility' => 'Site A',
				'city'     => 'Boston',
				'state'    => 'MA',
				'country'  => 'United States',
				'status'   => 'RECRUITING',
				'lat'      => 42.36,
				'lng'      => -71.06,
			),
			array(
				'facility' => 'Site B',
				'city'     => 'Chicago',
				'state'    => 'IL',
				'country'  => 'United States',
				'status'   => 'RECRUITING',
				'lat'      => 41.88,
				'lng'      => -87.63,
			),
		);
		$id    = $repo->upsert( $m );
		$terms = wp_get_object_terms( $id, Trial_Taxonomies::COUNTRY, array( 'fields' => 'names' ) );
		$this->assertContains( 'United States', $terms );
		$this->assertCount( 1, $terms );
	}

	/**
	 * Upsert a trial with no locations → no country terms assigned.
	 */
	public function test_upsert_zero_locations_assigns_no_country_terms(): void {
		$repo = new Trial_Repository();
		$m    = $this->meta( 'NCT00000012' );
		// locations defaults to [] in meta() helper.
		$id    = $repo->upsert( $m );
		$terms = wp_get_object_terms( $id, Trial_Taxonomies::COUNTRY, array( 'fields' => 'names' ) );
		$this->assertIsArray( $terms );
		$this->assertCount( 0, $terms );
	}

	/**
	 * backfill_country_terms() reads locations meta from existing posts and assigns terms.
	 *
	 * Simulates a post that was inserted before country-term support: we manually set the
	 * locations meta and clear any country terms, then verify backfill re-assigns them.
	 */
	public function test_backfill_country_terms_populates_terms_from_meta(): void {
		$repo = new Trial_Repository();
		$m    = $this->meta( 'NCT00000013' );
		$m['locations'] = array(
			array(
				'facility' => 'Centre X',
				'city'     => 'Paris',
				'state'    => '',
				'country'  => 'France',
				'status'   => 'RECRUITING',
				'lat'      => 48.85,
				'lng'      => 2.35,
			),
		);
		$id = $repo->upsert( $m );

		// Simulate pre-backfill state: remove the country terms that upsert just set.
		wp_set_object_terms( $id, array(), Trial_Taxonomies::COUNTRY );
		$terms_before = wp_get_object_terms( $id, Trial_Taxonomies::COUNTRY, array( 'fields' => 'names' ) );
		$this->assertCount( 0, $terms_before, 'Setup: country terms should be empty before backfill.' );

		// Run backfill and verify terms are re-applied from stored meta.
		$count = $repo->backfill_country_terms();
		$this->assertGreaterThanOrEqual( 1, $count );
		$terms_after = wp_get_object_terms( $id, Trial_Taxonomies::COUNTRY, array( 'fields' => 'names' ) );
		$this->assertContains( 'France', $terms_after );
	}

	/**
	 * backfill_country_terms() returns 0 and does not error when no trial posts exist.
	 */
	public function test_backfill_country_terms_returns_count_of_posts_processed(): void {
		// Create two trials without locations.
		$repo = new Trial_Repository();
		$repo->upsert( $this->meta( 'NCT00000014' ) );
		$repo->upsert( $this->meta( 'NCT00000015' ) );

		$count = $repo->backfill_country_terms();
		// At minimum the two just inserted are processed (other tests may have added more).
		$this->assertGreaterThanOrEqual( 2, $count );
	}
}
