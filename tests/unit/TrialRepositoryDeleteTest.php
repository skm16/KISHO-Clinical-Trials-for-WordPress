<?php
/**
 * Unit test for Trial_Repository::delete_by_ncts counting/delegation.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Data\Trial_Repository;

/**
 * Subclass that records delete_by_nct calls and simulates which NCTs exist.
 */
final class SpyDeleteRepo extends Trial_Repository {
	/** @var string[] */
	public array $deleted = array();
	/** @var string[] NCTs that "exist" and should count as deleted. */
	private array $existing;

	public function __construct( array $existing ) {
		$this->existing = $existing;
	}

	public function delete_by_nct( string $nct ): void {
		$this->deleted[] = $nct;
	}

	// Expose counting logic by overriding the existence check used in delete_by_ncts.
	protected function exists( string $nct ): bool {
		return in_array( $nct, $this->existing, true );
	}
}

final class TrialRepositoryDeleteTest extends TestCase {

	public function test_counts_only_existing_ncts(): void {
		$repo = new SpyDeleteRepo( array( 'NCT00000001', 'NCT00000002' ) );
		$deleted = $repo->delete_by_ncts( array( 'NCT00000001', 'NCT00000002', 'NCT00000009' ) );

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			array( 'NCT00000001', 'NCT00000002' ),
			$repo->deleted
		);
	}

	public function test_empty_input_deletes_nothing(): void {
		$repo = new SpyDeleteRepo( array() );
		$this->assertSame( 0, $repo->delete_by_ncts( array() ) );
		$this->assertSame( array(), $repo->deleted );
	}
}
