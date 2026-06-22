<?php
/**
 * Integration test for the cleanup transient snapshot lifecycle.
 *
 * Verifies that a previewed off-condition snapshot, when stored under the
 * controller's transient key, is the exact set deleted on confirm.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Admin\Cleanup_Controller;
use SKMCTF\Sync\Condition_Cleanup;
use SKMCTF\Sync\Ctgov_Client;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;
use SKMCTF\Post_Types\Trial_Meta;

final class CleanupControllerTest extends WP_UnitTestCase {

	public function test_confirm_deletes_exactly_the_snapshot(): void {
		$id1 = self::factory()->post->create(
			array(
				'post_type' => 'skmctf_trial',
				'post_name' => 'nct00000001',
			)
		);
		update_post_meta( $id1, Trial_Meta::KEYS['nct_id'], 'NCT00000001' );
		$id2 = self::factory()->post->create(
			array(
				'post_type' => 'skmctf_trial',
				'post_name' => 'nct00000002',
			)
		);
		update_post_meta( $id2, Trial_Meta::KEYS['nct_id'], 'NCT00000002' );

		// Simulate a stored preview snapshot containing only NCT00000001.
		set_transient( Cleanup_Controller::TRANSIENT, array( 'NCT00000001' ), 15 * MINUTE_IN_SECONDS );

		// Confirm path logic: read snapshot, delete it.
		$snapshot = get_transient( Cleanup_Controller::TRANSIENT );
		$clean    = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$deleted  = $clean->delete( (array) $snapshot );

		$this->assertSame( 1, $deleted );
		$this->assertNull( get_post( $id1 ) );      // deleted
		$this->assertNotNull( get_post( $id2 ) );   // untouched (not in snapshot)
	}

	public function test_constants_are_distinct(): void {
		$this->assertNotSame( Cleanup_Controller::ACTION_PREVIEW, Cleanup_Controller::ACTION_CONFIRM );
		$this->assertSame( 'skmctf_cleanup_preview', Cleanup_Controller::TRANSIENT );
	}
}
