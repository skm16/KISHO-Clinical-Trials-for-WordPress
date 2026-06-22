<?php
/**
 * Unit tests for the theme / theme_mode settings — no WordPress required.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Admin\Settings;

final class SettingsThemeTest extends TestCase {
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

	public function test_theme_getter_returns_default_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'skeleton', Settings::theme() );
		$this->assertSame( 'light', Settings::theme_mode() );
	}

	public function test_theme_getter_rejects_unknown_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'theme' => 'rainbow', 'theme_mode' => 'sepia' ) );
		$this->assertSame( 'skeleton', Settings::theme() );
		$this->assertSame( 'light', Settings::theme_mode() );
	}

	public function test_theme_getter_accepts_valid_values(): void {
		Functions\when( 'get_option' )->justReturn( array( 'theme' => 'clinical', 'theme_mode' => 'dark' ) );
		$this->assertSame( 'clinical', Settings::theme() );
		$this->assertSame( 'dark', Settings::theme_mode() );
	}

	public function test_sanitize_whitelists_theme_and_mode(): void {
		$out = Settings::sanitize( array( 'theme' => 'warm', 'theme_mode' => 'dark' ), array() );
		$this->assertSame( 'warm', $out['theme'] );
		$this->assertSame( 'dark', $out['theme_mode'] );

		$out2 = Settings::sanitize( array( 'theme' => 'nope', 'theme_mode' => 'nope' ), array() );
		$this->assertSame( 'skeleton', $out2['theme'] );
		$this->assertSame( 'light', $out2['theme_mode'] );
	}
}
