<?php
/**
 * Unit tests for Trials_Query::args() — pure arg builder, no WP required.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Frontend\Trials_Query;

final class TrialsQueryArgsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'sanitize_text_field' => fn( $v ) => is_string( $v ) ? trim( $v ) : '',
			'absint'              => fn( $v ) => abs( (int) $v ),
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_status_and_phase_become_tax_query(): void {
		$args = Trials_Query::args( [ 'status' => 'RECRUITING', 'phase' => 'Phase 2', 'per_page' => 10 ] );
		$this->assertSame( 'skmctf_trial', $args['post_type'] );
		$this->assertSame( 10, $args['posts_per_page'] );
		$this->assertSame( 'AND', $args['tax_query']['relation'] );
		$slugs = array_column( $args['tax_query'], 'taxonomy' );
		$this->assertContains( 'trial_status', $slugs );
		$this->assertContains( 'trial_phase', $slugs );
	}

	public function test_state_filter_becomes_meta_query(): void {
		$args = Trials_Query::args( [ 'state' => 'MA' ] );
		$this->assertSame( 'skmctf_locations', $args['meta_query'][0]['key'] );
		$this->assertSame( 'LIKE', $args['meta_query'][0]['compare'] );
	}

	public function test_empty_filters_have_no_tax_query(): void {
		$args = Trials_Query::args( [] );
		$this->assertArrayNotHasKey( 'tax_query', $args );
	}
}
