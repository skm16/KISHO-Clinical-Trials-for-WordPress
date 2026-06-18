<?php
/**
 * Unit tests for Reconciler — the three no-wipe guards.
 *
 * Pure PHP unit test — no WordPress, no DB required.
 * Uses FakeRepo and NullLog test doubles.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Reconciler;
use SKMCTF\Data\Repo_Interface;
use SKMCTF\Support\Logger_Interface;

final class FakeRepo implements Repo_Interface {
	public array $existing;
	public array $closed  = [];
	public array $deleted = [];

	public function __construct( array $existing ) {
		$this->existing = $existing;
	}

	public function all_nct_ids(): array {
		return $this->existing;
	}

	public function mark_closed( string $nct ): void {
		$this->closed[] = $nct;
	}

	public function delete_by_nct( string $nct ): void {
		$this->deleted[] = $nct;
	}
}

final class NullLog implements Logger_Interface {
	public function info( string $m, array $c = [] ): void {}
	public function warn( string $m, array $c = [] ): void {}
	public function error( string $m, array $c = [] ): void {}
}

final class ReconcilerTest extends TestCase {

	public function test_skips_on_error(): void {
		$repo = new FakeRepo( [ 'NCT00000001' ] );
		$r    = new Reconciler( $repo, new NullLog() );
		$this->assertSame( 'skipped_error', $r->reconcile( [], true, 'mark_closed' ) );
		$this->assertSame( [], $repo->closed );
	}

	public function test_skips_on_empty_seen(): void {
		$repo = new FakeRepo( [ 'NCT00000001' ] );
		$r    = new Reconciler( $repo, new NullLog() );
		$this->assertSame( 'skipped_empty', $r->reconcile( [], false, 'mark_closed' ) );
		// Guard must not touch the repo on an empty result set (protects against empty API responses).
		$this->assertSame( [], $repo->closed );
		$this->assertSame( [], $repo->deleted );
	}

	public function test_skips_when_drop_ratio_exceeded(): void {
		$repo = new FakeRepo( [ 'NCT01', 'NCT02', 'NCT03', 'NCT04' ] );
		$r    = new Reconciler( $repo, new NullLog() );
		// seen only 1 of 4 -> would drop 3/4 = 0.75 > 0.5
		$this->assertSame( 'skipped_ratio', $r->reconcile( [ 'NCT01' ], false, 'remove' ) );
		$this->assertSame( [], $repo->deleted );
	}

	public function test_marks_closed_dropped_trials(): void {
		$repo = new FakeRepo( [ 'NCT01', 'NCT02' ] );
		$r    = new Reconciler( $repo, new NullLog() );
		$this->assertSame( 'done', $r->reconcile( [ 'NCT01' ], false, 'mark_closed' ) );
		$this->assertSame( [ 'NCT02' ], $repo->closed );
	}

	public function test_remove_mode_deletes(): void {
		$repo = new FakeRepo( [ 'NCT01', 'NCT02' ] );
		$r    = new Reconciler( $repo, new NullLog() );
		$this->assertSame( 'done', $r->reconcile( [ 'NCT01' ], false, 'remove' ) );
		$this->assertSame( [ 'NCT02' ], $repo->deleted );
	}
}
