<?php
/**
 * Integration test for Trial_Repository::delete_by_ncts against a real WP DB.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Post_Types\Trial_Meta;

final class TrialRepositoryDeleteTest extends WP_UnitTestCase {

	private function seed( string $nct ): int {
		$id = self::factory()->post->create(
			array(
				'post_type' => 'skmctf_trial',
				'post_name' => strtolower( $nct ),
			)
		);
		update_post_meta( $id, Trial_Meta::KEYS['nct_id'], $nct );
		return $id;
	}

	public function test_deletes_only_known_ncts_and_returns_count(): void {
		$id1 = $this->seed( 'NCT00000001' );
		$id2 = $this->seed( 'NCT00000002' );

		$repo    = new Trial_Repository();
		$deleted = $repo->delete_by_ncts( array( 'NCT00000001', 'NCT00000002', 'NCT00000009' ) );

		$this->assertSame( 2, $deleted );           // NCT00000009 does not exist → not counted
		$this->assertNull( get_post( $id1 ) );      // deleted
		$this->assertNull( get_post( $id2 ) );      // deleted
	}

	public function test_empty_and_unknown_input_deletes_nothing(): void {
		$id = $this->seed( 'NCT00000001' );
		$repo = new Trial_Repository();

		$this->assertSame( 0, $repo->delete_by_ncts( array() ) );
		$this->assertSame( 0, $repo->delete_by_ncts( array( 'NCT09999999', '' ) ) );
		$this->assertNotNull( get_post( $id ) );    // untouched
	}

	public function test_input_is_normalised_to_canonical_uppercase(): void {
		$id = $this->seed( 'NCT00000001' );
		$repo = new Trial_Repository();

		// lower-case + whitespace must still match the canonical stored NCT
		$deleted = $repo->delete_by_ncts( array( '  nct00000001  ' ) );

		$this->assertSame( 1, $deleted );
		$this->assertNull( get_post( $id ) );
	}
}
