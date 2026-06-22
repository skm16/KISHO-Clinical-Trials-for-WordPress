<?php
/**
 * Unit tests for Prompt_Builder.
 *
 * Pure PHP — no WordPress, no DB required.
 * Uses Brain Monkey to stub the `__` translation function.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\LLM\Prompt_Builder;

final class PromptBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [ '__' => static fn( $v ) => $v ] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_enforce_disclaimer_appends_when_missing(): void {
		$out = Prompt_Builder::enforce_disclaimer( 'A summary.' );
		$this->assertStringContainsString( 'talk to your doctor', strtolower( $out ) );
	}

	public function test_enforce_disclaimer_does_not_duplicate(): void {
		$with = 'A summary. ' . Prompt_Builder::disclaimer();
		$out  = Prompt_Builder::enforce_disclaimer( $with );
		$this->assertSame( 1, substr_count( strtolower( $out ), 'talk to your doctor' ) );
	}

	public function test_user_prompt_includes_trial_facts(): void {
		$u = Prompt_Builder::user( [
			'brief_title'    => 'A Trial',
			'overall_status' => 'RECRUITING',
			'conditions'     => [ 'Pompe Disease' ],
			'phase'          => 'Phase 2',
			'brief_summary'  => 'Studying X.',
			'eligibility'    => [
				'sex'      => 'ALL',
				'min_age'  => '18 Years',
				'max_age'  => '',
				'criteria' => '',
			],
			'lead_sponsor'   => 'Acme',
		] );
		$this->assertStringContainsString( 'A Trial', $u );
		$this->assertStringContainsString( 'Pompe Disease', $u );
		$this->assertStringContainsString( 'Studying X.', $u );
	}

	public function test_enhanced_system_forbids_advice_and_requires_json(): void {
		$s = strtolower( Prompt_Builder::enhanced_system() );
		$this->assertStringContainsString( 'json', $s );
		$this->assertStringContainsString( 'medical advice', $s );
		$this->assertStringContainsString( 'study_purpose', $s );
		$this->assertStringContainsString( 'who_can_join', $s );
		$this->assertStringContainsString( 'doctor_questions', $s );
	}

	public function test_enhanced_user_includes_criteria(): void {
		$u = Prompt_Builder::enhanced_user( array(
			'brief_title'   => 'A Trial',
			'conditions'    => array( 'Pompe Disease' ),
			'brief_summary' => 'Studying X.',
			'eligibility'   => array( 'sex' => 'ALL', 'min_age' => '18 Years', 'max_age' => '', 'criteria' => 'Inclusion: adults.' ),
		) );
		$this->assertStringContainsString( 'Inclusion: adults.', $u );
		$this->assertStringContainsString( 'Pompe Disease', $u );
		$this->assertStringContainsString( 'Studying X.', $u );
	}

	public function test_parse_enhanced_reads_valid_json(): void {
		$raw = '{"study_purpose":"Tests a registry.","who_can_join":["Adults 18+","Has Pompe disease"],"doctor_questions":["Am I eligible?","What is involved?"]}';
		$out = Prompt_Builder::parse_enhanced( $raw );
		$this->assertSame( 'Tests a registry.', $out['study_purpose'] );
		$this->assertStringContainsString( 'Adults 18+', $out['who_can_join'] );
		$this->assertSame( array( 'Am I eligible?', 'What is involved?' ), $out['doctor_questions'] );
	}

	public function test_parse_enhanced_handles_garbage(): void {
		$out = Prompt_Builder::parse_enhanced( 'not json at all' );
		$this->assertSame( '', $out['study_purpose'] );
		$this->assertSame( '', $out['who_can_join'] );
		$this->assertSame( array(), $out['doctor_questions'] );
	}

	public function test_parse_enhanced_tolerates_code_fence(): void {
		$raw = "```json\n{\"study_purpose\":\"P\",\"who_can_join\":[\"A\"],\"doctor_questions\":[\"Q\"]}\n```";
		$out = Prompt_Builder::parse_enhanced( $raw );
		$this->assertSame( 'P', $out['study_purpose'] );
		$this->assertStringContainsString( 'A', $out['who_can_join'] );
		$this->assertSame( array( 'Q' ), $out['doctor_questions'] );
	}
}
