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
}
