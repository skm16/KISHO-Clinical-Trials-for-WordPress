<?php
/**
 * Integration tests for Summary_Service.
 *
 * Requires a live WordPress test environment (WP_UnitTestCase).
 * Run inside LocalWP with: composer test:integration -- --filter SummaryServiceTest
 *
 * Verifies:
 *   - generates on new post → true, API call count = 1
 *   - caches when ct_last_update unchanged → false, no additional call
 *   - regenerates when date advances → true, API call count = 2
 *   - null provider is a no-op → false immediately
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\LLM\Summary_Service;
use SKMCTF\LLM\Llm_Provider;
use SKMCTF\Support\Logger;
use SKMCTF\Post_Types\Trial_Meta;

/**
 * Fake LLM provider that counts generate_summary() invocations.
 */
final class CountingProvider implements Llm_Provider {
	/** @var int */
	public $calls = 0;

	public function id(): string {
		return 'fake';
	}

	/**
	 * @param string $s System prompt.
	 * @param string $u User prompt.
	 * @param array  $o Options.
	 * @return string
	 */
	public function generate_summary( string $s, string $u, array $o = [] ) {
		$this->calls++;
		return 'Generated text.';
	}
}

final class SummaryServiceTest extends WP_UnitTestCase {

	/**
	 * Create a trial post with a given ct_last_update date.
	 *
	 * @param string $date YYYY-MM-DD date string.
	 * @return int Post ID.
	 */
	private function trial( string $date ): int {
		$id = self::factory()->post->create( [ 'post_type' => 'skmctf_trial' ] );
		update_post_meta( $id, Trial_Meta::KEYS['ct_last_update'], $date );
		return $id;
	}

	public function test_generates_on_new_then_caches_until_date_changes(): void {
		$p   = new CountingProvider();
		$svc = new Summary_Service( $p, new Logger() );
		$meta = [
			'ct_last_update' => '2026-03-10',
			'brief_title'    => 'T',
			'conditions'     => [],
			'eligibility'    => [],
		];
		$id = $this->trial( '2026-03-10' );

		// New post — no existing summary → should generate.
		$this->assertTrue( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 1, $p->calls );
		$this->assertStringContainsString(
			'Generated text.',
			get_post_meta( $id, Trial_Meta::KEYS['plain_summary'], true )
		);

		// Same date — cache valid → should skip.
		$this->assertFalse( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 1, $p->calls );

		// Date advanced → cache stale → should regenerate.
		$meta['ct_last_update'] = '2026-04-01';
		$this->assertTrue( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 2, $p->calls );
	}

	public function test_null_provider_is_noop(): void {
		$svc = new Summary_Service( null, new Logger() );
		$id  = $this->trial( '2026-03-10' );
		$this->assertFalse( $svc->maybe_generate( $id, [ 'ct_last_update' => '2026-03-10' ] ) );
	}
}
