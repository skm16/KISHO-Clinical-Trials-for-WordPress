<?php
/**
 * Integration tests for Logger — requires a live WordPress environment.
 *
 * Run with: composer test:integration -- --filter LoggerTest
 *
 * NOTE: These tests require the WordPress test suite and a reachable MySQL
 * instance. In environments without a WP test suite installed (e.g. local
 * CLI without LocalWP MySQL access), defer these tests to CI / a full WP
 * dev environment.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Support\Logger;

final class LoggerTest extends WP_UnitTestCase {

	/**
	 * Ring buffer must cap at 50 entries and store newest-first.
	 */
	public function test_ring_buffer_caps_at_50(): void {
		$log = new Logger();
		for ( $i = 0; $i < 60; $i++ ) {
			$log->info( "m$i" );
		}
		$this->assertLessThanOrEqual( 50, count( $log->recent() ) );
		$this->assertSame( 'm59', $log->recent()[0]['msg'] );
	}

	/**
	 * Calling error() must also update the skmctf_last_error option.
	 */
	public function test_error_sets_last_error_option(): void {
		( new Logger() )->error( 'boom' );
		$this->assertSame( 'boom', ( new Logger() )->last_error() );
	}
}
