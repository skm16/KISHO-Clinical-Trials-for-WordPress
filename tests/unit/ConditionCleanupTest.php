<?php
/**
 * Unit tests for Condition_Cleanup pure set math and delete delegation.
 *
 * Pure PHP — no WordPress, no network. Reuses FakeRepo from ReconcilerTest.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Condition_Cleanup;

final class ConditionCleanupTest extends TestCase {

	public function test_off_condition_is_stored_minus_seen_and_includes(): void {
		$stored   = array( 'NCT00000001', 'NCT00000002', 'NCT00000003', 'NCT00000004' );
		$seen     = array( 'NCT00000002' );
		$includes = array( 'NCT00000003' );

		$off = Condition_Cleanup::compute_off_condition( $stored, $seen, $includes );

		// 1 and 4 are neither seen nor included.
		$this->assertSame( array( 'NCT00000001', 'NCT00000004' ), $off );
	}

	public function test_includes_are_exempt_even_when_not_seen(): void {
		$stored   = array( 'NCT00000001', 'NCT00000005' );
		$seen     = array();
		$includes = array( 'NCT00000005' );

		$off = Condition_Cleanup::compute_off_condition( $stored, $seen, $includes );

		$this->assertSame( array( 'NCT00000001' ), $off );
	}

	public function test_comparison_is_case_insensitive_canonical(): void {
		$stored   = array( 'NCT00000001', 'NCT00000002' );
		$seen     = array( 'nct00000002' ); // lower-case from a different source
		$includes = array();

		$off = Condition_Cleanup::compute_off_condition( $stored, $seen, $includes );

		$this->assertSame( array( 'NCT00000001' ), $off );
	}

	public function test_empty_when_all_stored_seen(): void {
		$off = Condition_Cleanup::compute_off_condition(
			array( 'NCT00000001' ),
			array( 'NCT00000001' ),
			array()
		);
		$this->assertSame( array(), $off );
	}

	public function test_delete_delegates_to_repo_and_returns_count(): void {
		$repo  = new FakeRepo( array( 'NCT00000001', 'NCT00000002' ) );
		$clean = new Condition_Cleanup( new \SKMCTF\Sync\Ctgov_Client(), $repo, new NullLog() );

		$deleted = $clean->delete( array( 'NCT00000001', 'NCT00000002' ) );

		$this->assertSame( 2, $deleted );
		$this->assertSame( array( 'NCT00000001', 'NCT00000002' ), $repo->deleted );
	}
}
