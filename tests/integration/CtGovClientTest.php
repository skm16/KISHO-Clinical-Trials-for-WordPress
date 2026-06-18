<?php
/**
 * Integration tests for Ctgov_Client (requires WordPress test suite).
 *
 * Uses the `pre_http_request` filter to mock HTTP responses without
 * hitting the network. Run in a LocalWP terminal with WP_TESTS_DIR set.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Sync\Ctgov_Client;

final class CtGovClientTest extends WP_UnitTestCase {

	public function test_paginates_until_no_next_token(): void {
		$page = 0;
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( &$page ) {
				$page++;
				$body = ( 1 === $page )
					? [ 'studies' => [ [ 'protocolSection' => [ 'identificationModule' => [ 'nctId' => 'NCT00000001' ] ] ] ], 'nextPageToken' => 'TOK' ]
					: [ 'studies' => [ [ 'protocolSection' => [ 'identificationModule' => [ 'nctId' => 'NCT00000002' ] ] ] ] ];
				return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $body ) ];
			},
			10,
			3
		);

		$result = ( new Ctgov_Client() )->fetch_all_for_condition( 'Pompe disease', [ 'RECRUITING' ] );
		$this->assertNull( $result['error'] );
		$this->assertCount( 2, $result['studies'] );
		$this->assertSame( 2, $page );
	}

	public function test_non_200_returns_wp_error_and_no_studies(): void {
		add_filter( 'pre_http_request', fn() => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ], 10, 3 );
		$result = ( new Ctgov_Client() )->fetch_all_for_condition( 'X', [ 'RECRUITING' ] );
		$this->assertInstanceOf( \WP_Error::class, $result['error'] );
		$this->assertSame( [], $result['studies'] );
	}
}
