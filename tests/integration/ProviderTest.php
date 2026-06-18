<?php
/**
 * Integration tests for the LLM provider classes.
 *
 * Uses WordPress's pre_http_request filter to mock HTTP calls — no real network
 * requests are made. These tests require the WordPress test suite (WP_TESTS_DIR)
 * and a running MySQL instance; they are deferred from the CLI (composer test:unit)
 * and run via composer test:integration inside LocalWP.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\LLM\Anthropic_Provider;
use SKMCTF\LLM\Openai_Provider;

final class ProviderTest extends WP_UnitTestCase {

	public function test_anthropic_parses_text_from_content(): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->assertStringContainsString( 'api.anthropic.com', $url );
				$this->assertSame( 'key-123', $args['headers']['x-api-key'] );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[ 'content' => [ [ 'type' => 'text', 'text' => 'Plain summary.' ] ] ]
					),
				];
			},
			10,
			3
		);
		$out = ( new Anthropic_Provider( 'key-123' ) )->generate_summary( 'sys', 'user' );
		$this->assertSame( 'Plain summary.', $out );
	}

	public function test_openai_parses_choices_message(): void {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->assertStringContainsString( 'api.openai.com', $url );
				$this->assertSame( 'Bearer key-xyz', $args['headers']['Authorization'] );
				return [
					'response' => [ 'code' => 200 ],
					'body'     => wp_json_encode(
						[ 'choices' => [ [ 'message' => [ 'content' => 'OAI summary.' ] ] ] ]
					),
				];
			},
			10,
			3
		);
		$out = ( new Openai_Provider( 'key-xyz' ) )->generate_summary( 'sys', 'user' );
		$this->assertSame( 'OAI summary.', $out );
	}

	public function test_http_error_returns_wp_error(): void {
		add_filter(
			'pre_http_request',
			fn() => [ 'response' => [ 'code' => 401 ], 'body' => 'bad key' ],
			10,
			3
		);
		$out = ( new Anthropic_Provider( 'key' ) )->generate_summary( 's', 'u' );
		$this->assertInstanceOf( \WP_Error::class, $out );
	}
}
