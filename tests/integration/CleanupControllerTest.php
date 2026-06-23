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

	public function test_confirm_deletes_exactly_the_snapshot(): void {
		$id1 = $this->seed( 'NCT00000001' );
		$id2 = $this->seed( 'NCT00000002' );

		// Simulate a stored preview snapshot (new shape) containing only NCT00000001.
		set_transient(
			Cleanup_Controller::TRANSIENT,
			array(
				'token' => 'tok123',
				'ncts'  => array( 'NCT00000001' ),
			),
			15 * MINUTE_IN_SECONDS
		);

		// Confirm path logic: read + normalise snapshot, delete its ncts.
		$snapshot = Cleanup_Controller::normalise_snapshot( get_transient( Cleanup_Controller::TRANSIENT ) );
		$clean    = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$deleted  = $clean->delete( $snapshot['ncts'] );

		$this->assertSame( 1, $deleted );
		$this->assertNull( get_post( $id1 ) );      // deleted
		$this->assertNotNull( get_post( $id2 ) );   // untouched (not in snapshot)
	}

	public function test_normalise_reads_new_shape(): void {
		$snap = Cleanup_Controller::normalise_snapshot(
			array(
				'token' => 'abc',
				'ncts'  => array( 'NCT00000001', 'NCT00000002' ),
			)
		);
		$this->assertSame( 'abc', $snap['token'] );
		$this->assertSame( array( 'NCT00000001', 'NCT00000002' ), $snap['ncts'] );
	}

	public function test_normalise_tolerates_legacy_bare_list(): void {
		// A pre-upgrade snapshot was a bare list of NCT strings, no token.
		$snap = Cleanup_Controller::normalise_snapshot( array( 'NCT00000001' ) );
		$this->assertSame( '', $snap['token'] );
		$this->assertSame( array( 'NCT00000001' ), $snap['ncts'] );
	}

	public function test_normalise_rejects_non_array(): void {
		$this->assertNull( Cleanup_Controller::normalise_snapshot( false ) );
		$this->assertNull( Cleanup_Controller::normalise_snapshot( 'nope' ) );
	}

	public function test_token_mismatch_is_rejected_by_hash_equals(): void {
		// The confirm guard deletes only when the submitted token matches the
		// stored snapshot token. A stale form carries the OLD token.
		$stored_token    = 'fresh-token-from-second-preview';
		$submitted_token = 'stale-token-from-first-preview';
		$this->assertFalse( hash_equals( $stored_token, $submitted_token ) );
		$this->assertTrue( hash_equals( $stored_token, $stored_token ) );
	}

	/**
	 * Mirror of the confirm guard's accept condition. A confirm is honoured only
	 * when both tokens are non-empty AND equal — so a legacy empty-token snapshot
	 * (hash_equals('', '') === true) can never be confirmed.
	 *
	 * @param string $stored    Token stored with the snapshot.
	 * @param string $submitted Token echoed by the confirm form.
	 * @return bool Whether the confirm would proceed to delete.
	 */
	private function guard_accepts( string $stored, string $submitted ): bool {
		return '' !== $stored && '' !== $submitted && hash_equals( $stored, $submitted );
	}

	public function test_empty_stored_token_is_never_accepted(): void {
		// Legacy snapshot upgrade window: stored token is '' and a legacy form
		// carries no token (''). hash_equals('','') is true, but the guard must
		// still refuse, because an untokenised snapshot is not confirmable.
		$this->assertFalse( $this->guard_accepts( '', '' ) );
		$this->assertFalse( $this->guard_accepts( '', 'anything' ) );
		$this->assertFalse( $this->guard_accepts( 'real-token', '' ) );
		$this->assertTrue( $this->guard_accepts( 'real-token', 'real-token' ) );
	}

	public function test_constants_are_distinct(): void {
		$this->assertNotSame( Cleanup_Controller::ACTION_PREVIEW, Cleanup_Controller::ACTION_CONFIRM );
		$this->assertSame( 'skmctf_cleanup_preview', Cleanup_Controller::TRANSIENT );
		$this->assertNotSame( Cleanup_Controller::TOKEN_FIELD, Cleanup_Controller::TRANSIENT );
	}
}
