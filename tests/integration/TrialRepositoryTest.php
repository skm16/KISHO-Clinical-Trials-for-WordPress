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
}
