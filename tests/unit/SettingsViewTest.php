<?php
/**
 * Unit tests for the default_view setting — no WordPress required.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Admin\Settings;

final class SettingsViewTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'sanitize_text_field'     => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
				'sanitize_textarea_field' => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
				'wp_unslash'              => static fn( $v ) => $v,
				'absint'                  => static fn( $v ) => abs( (int) $v ),
				'__'                      => static fn( $v ) => $v,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_is_grid(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'grid', Settings::default_view() );
	}

	public function test_accepts_list(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default_view' => 'list' ) );
		$this->assertSame( 'list', Settings::default_view() );
	}

	public function test_rejects_junk(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default_view' => 'carousel' ) );
		$this->assertSame( 'grid', Settings::default_view() );
	}

	public function test_sanitize_whitelists_view(): void {
		$this->assertSame( 'list', Settings::sanitize( array( 'default_view' => 'list' ), array() )['default_view'] );
		$this->assertSame( 'grid', Settings::sanitize( array( 'default_view' => 'nope' ), array() )['default_view'] );
	}
}
