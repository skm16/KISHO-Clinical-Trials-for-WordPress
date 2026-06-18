<?php
/**
 * Unit tests for Settings::sanitize() — no WordPress required.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Admin\Settings;

final class SettingsSanitizeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( [
			'sanitize_text_field'     => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
			'sanitize_textarea_field' => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
			'wp_unslash'              => static fn( $v ) => $v,
			'absint'                  => static fn( $v ) => abs( (int) $v ),
			'__'                      => static fn( $v ) => $v,
		] );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_filters_invalid_ncts_and_uppercases(): void {
		$out = Settings::sanitize( [ 'include_ncts' => "nct01234567\nbad\nNCT99999999" ], [] );
		$this->assertSame( [ 'NCT01234567', 'NCT99999999' ], $out['include_ncts'] );
	}

	public function test_status_whitelist(): void {
		$out = Settings::sanitize( [ 'statuses' => [ 'RECRUITING', 'HACKERMAN' ] ], [] );
		$this->assertSame( [ 'RECRUITING' ], $out['statuses'] );
	}

	public function test_reconcile_mode_defaults_to_mark_closed_on_garbage(): void {
		$out = Settings::sanitize( [ 'reconcile_mode' => 'nuke' ], [] );
		$this->assertSame( 'mark_closed', $out['reconcile_mode'] );
	}

	public function test_blank_api_key_preserves_existing(): void {
		$out = Settings::sanitize( [ 'api_key' => '' ], [ 'api_key' => 'sk-secret' ] );
		$this->assertSame( 'sk-secret', $out['api_key'] );
	}

	public function test_new_api_key_replaces_and_clear_flag_wipes(): void {
		$this->assertSame( 'sk-new', Settings::sanitize( [ 'api_key' => 'sk-new' ], [ 'api_key' => 'old' ] )['api_key'] );
		$this->assertSame( '', Settings::sanitize( [ 'api_key' => 'old', 'clear_api_key' => '1' ], [ 'api_key' => 'old' ] )['api_key'] );
	}
}
