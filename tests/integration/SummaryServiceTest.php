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
	 * Returns the plain summary text, or — when the enhanced JSON prompt is in
	 * use — a valid enhanced JSON block. Both paths contain 'Generated text.'
	 * so the plain-summary assertion still holds, while the enhanced parser gets
	 * non-empty values so its change-detection cache engages like the summary's.
	 *
	 * @param string $s System prompt.
	 * @param string $u User prompt.
	 * @param array  $o Options.
	 * @return string
	 */
	public function generate_summary( string $s, string $u, array $o = [] ) {
		$this->calls++;
		if ( false !== strpos( $s, 'JSON object' ) ) {
			return '{"study_purpose":"Generated text.","who_can_join":["Adults"],"doctor_questions":["Q1?"]}';
		}
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

		// New post — no existing summary → should generate. maybe_generate()
		// makes two provider calls per generation: one for the plain summary and
		// one combined call for the enhanced patient-facing fields (study
		// purpose / who-can-join / doctor questions).
		$this->assertTrue( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 2, $p->calls );
		$this->assertStringContainsString(
			'Generated text.',
			get_post_meta( $id, Trial_Meta::KEYS['plain_summary'], true )
		);

		// Same date — both caches valid → should skip, no further calls.
		$this->assertFalse( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 2, $p->calls );

		// Date advanced → both caches stale → regenerate (two more calls).
		$meta['ct_last_update'] = '2026-04-01';
		$this->assertTrue( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 4, $p->calls );
	}

	/**
	 * Regression: a trial whose plain summary is already current (so the summary
	 * cache is a hit) must STILL generate the enhanced patient-facing fields,
	 * which have their own independent cache. Previously the summary cache-hit
	 * early-return ran before enhanced generation, leaving older trials without
	 * study_purpose / who_can_join / doctor_questions forever.
	 */
	public function test_generates_enhanced_when_summary_already_current(): void {
		$p   = new CountingProvider();
		$svc = new Summary_Service( $p, new Logger() );
		$id  = $this->trial( '2026-03-10' );

		// Simulate a trial summarised by an earlier plugin version: a current
		// plain summary exists, but no enhanced fields were ever written.
		update_post_meta( $id, Trial_Meta::KEYS['plain_summary'], 'Old summary.' );
		update_post_meta( $id, Trial_Meta::KEYS['plain_summary_source_date'], '2026-03-10' );

		$meta = [
			'ct_last_update' => '2026-03-10',
			'brief_title'    => 'T',
			'conditions'     => [],
			'eligibility'    => [],
		];

		// Summary cache is a hit (no summary call), but enhanced fields are
		// missing → exactly one enhanced call, and maybe_generate reports true.
		$this->assertTrue( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 1, $p->calls );
		$this->assertNotEmpty( get_post_meta( $id, Trial_Meta::KEYS['study_purpose'], true ) );
		$this->assertSame( '2026-03-10', get_post_meta( $id, Trial_Meta::KEYS['study_purpose_source_date'], true ) );

		// Plain summary must be left untouched by the enhanced path.
		$this->assertSame( 'Old summary.', get_post_meta( $id, Trial_Meta::KEYS['plain_summary'], true ) );

		// Second call: both caches now valid → no further calls, returns false.
		$this->assertFalse( $svc->maybe_generate( $id, $meta ) );
		$this->assertSame( 1, $p->calls );
	}

	public function test_null_provider_is_noop(): void {
		$svc = new Summary_Service( null, new Logger() );
		$id  = $this->trial( '2026-03-10' );
		$this->assertFalse( $svc->maybe_generate( $id, array( 'ct_last_update' => '2026-03-10' ) ) );
	}

	public function test_maybe_generate_enhanced_writes_three_fields(): void {
		$post_id  = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
		$provider = new class() implements \SKMCTF\LLM\Llm_Provider {
			public function generate_summary( string $system, string $user, array $opts = array() ) {
				return '{"study_purpose":"Tests a registry.","who_can_join":["Adults 18+"],"doctor_questions":["Am I eligible?","What is involved?"]}';
			}
			public function id(): string { return 'fake'; }
		};
		$svc = new \SKMCTF\LLM\Summary_Service( $provider, new \SKMCTF\Support\Logger() );

		$meta  = array( 'ct_last_update' => '2026-03-10', 'brief_title' => 'T', 'conditions' => array(), 'eligibility' => array() );
		$wrote = $svc->maybe_generate_enhanced( $post_id, $meta );

		$this->assertTrue( $wrote );
		$this->assertSame( 'Tests a registry.', get_post_meta( $post_id, 'skmctf_study_purpose', true ) );
		$this->assertStringContainsString( '<li>Adults 18+</li>', get_post_meta( $post_id, 'skmctf_who_can_join', true ) );
		$this->assertSame( array( 'Am I eligible?', 'What is involved?' ), get_post_meta( $post_id, 'skmctf_doctor_questions', true ) );
		$this->assertSame( '2026-03-10', get_post_meta( $post_id, 'skmctf_study_purpose_source_date', true ) );
		$this->assertSame( '2026-03-10', get_post_meta( $post_id, 'skmctf_who_can_join_source_date', true ) );
		$this->assertSame( '2026-03-10', get_post_meta( $post_id, 'skmctf_doctor_questions_source_date', true ) );
	}

	public function test_maybe_generate_enhanced_is_cached(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
		update_post_meta( $post_id, 'skmctf_study_purpose', 'Existing.' );
		update_post_meta( $post_id, 'skmctf_study_purpose_source_date', '2026-03-10' );
		$provider = new class() implements \SKMCTF\LLM\Llm_Provider {
			public function generate_summary( string $system, string $user, array $opts = array() ) {
				throw new \RuntimeException( 'should not be called' );
			}
			public function id(): string { return 'fake'; }
		};
		$svc = new \SKMCTF\LLM\Summary_Service( $provider, new \SKMCTF\Support\Logger() );
		$this->assertFalse( $svc->maybe_generate_enhanced( $post_id, array( 'ct_last_update' => '2026-03-10' ) ) );
	}

	/**
	 * Self-heal regression: a trial whose source-date cache is current but whose
	 * who_can_join is legacy run-on text (no <li> markup, written by an older
	 * tag-stripping sanitizer) must STILL regenerate on the next sync, replacing
	 * the malformed value with proper list HTML. Without the self-heal guard the
	 * source-date cache would short-circuit and the corruption would persist.
	 */
	public function test_regenerates_when_who_can_join_lacks_list_markup(): void {
		$post_id = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );

		// Simulate the corrupted legacy state: cache key is current, but
		// who_can_join is run-on text with no <li> tags.
		update_post_meta( $post_id, 'skmctf_study_purpose', 'Existing purpose.' );
		update_post_meta( $post_id, 'skmctf_study_purpose_source_date', '2026-03-10' );
		update_post_meta( $post_id, 'skmctf_who_can_join', 'Adults 18 and olderHas the condition' );
		update_post_meta( $post_id, 'skmctf_who_can_join_source_date', '2026-03-10' );

		$provider = new class() implements \SKMCTF\LLM\Llm_Provider {
			public function generate_summary( string $system, string $user, array $opts = array() ) {
				return '{"study_purpose":"Healed.","who_can_join":["Adults 18+","Has the condition"],"doctor_questions":["Q?"]}';
			}
			public function id(): string {
				return 'fake'; }
		};
		$svc = new \SKMCTF\LLM\Summary_Service( $provider, new \SKMCTF\Support\Logger() );

		// Malformed who_can_join overrides the cache hit → regenerate.
		$this->assertTrue( $svc->maybe_generate_enhanced( $post_id, array( 'ct_last_update' => '2026-03-10' ) ) );
		$this->assertStringContainsString( '<li>Adults 18+</li>', get_post_meta( $post_id, 'skmctf_who_can_join', true ) );

		// Now that who_can_join is well-formed and the date matches, the cache
		// holds again — no further regeneration.
		$this->assertFalse( $svc->maybe_generate_enhanced( $post_id, array( 'ct_last_update' => '2026-03-10' ) ) );
	}
}
