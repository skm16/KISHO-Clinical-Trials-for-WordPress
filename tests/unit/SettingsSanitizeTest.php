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

	// -------------------------------------------------------------------------
	// sanitize_zoom()
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provider_zoom
	 *
	 * @param mixed  $input    Raw zoom value.
	 * @param string $expected Expected sanitized result.
	 */
	public function test_sanitize_zoom( $input, string $expected ): void {
		$this->assertSame( $expected, Settings::sanitize_zoom( $input ) );
	}

	/**
	 * Data provider for sanitize_zoom tests.
	 *
	 * @return array<string,array{mixed,string}>
	 */
	public function provider_zoom(): array {
		return array(
			'valid zoom 5'       => array( 5, '5' ),
			'valid zoom 1'       => array( 1, '1' ),
			'valid zoom 19'      => array( 19, '19' ),
			'zero is out range'  => array( 0, '' ),
			'50 is out of range' => array( 50, '' ),
			'string abc'         => array( 'abc', '' ),
			'empty string'       => array( '', '' ),
			'string 5'           => array( '5', '5' ),
		);
	}

	// -------------------------------------------------------------------------
	// sanitize_lat()
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provider_lat
	 *
	 * @param mixed  $input    Raw latitude value.
	 * @param string $expected Expected sanitized result.
	 */
	public function test_sanitize_lat( $input, string $expected ): void {
		$this->assertSame( $expected, Settings::sanitize_lat( $input ) );
	}

	/**
	 * Data provider for sanitize_lat tests.
	 *
	 * @return array<string,array{mixed,string}>
	 */
	public function provider_lat(): array {
		return array(
			'valid 42.3'         => array( 42.3, '42.3' ),
			'valid -90 boundary' => array( -90, '-90' ),
			'valid 90 boundary'  => array( 90, '90' ),
			'out of range 91'    => array( 91, '' ),
			'out of range -91'   => array( -91, '' ),
			'string x'           => array( 'x', '' ),
			'empty string'       => array( '', '' ),
		);
	}

	// -------------------------------------------------------------------------
	// sanitize_lng()
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provider_lng
	 *
	 * @param mixed  $input    Raw longitude value.
	 * @param string $expected Expected sanitized result.
	 */
	public function test_sanitize_lng( $input, string $expected ): void {
		$this->assertSame( $expected, Settings::sanitize_lng( $input ) );
	}

	/**
	 * Data provider for sanitize_lng tests.
	 *
	 * @return array<string,array{mixed,string}>
	 */
	public function provider_lng(): array {
		return array(
			'valid -71'            => array( -71, '-71' ),
			'valid 180 boundary'   => array( 180, '180' ),
			'valid -180 boundary'  => array( -180, '-180' ),
			'out of range 181'     => array( 181, '' ),
			'out of range -181'    => array( -181, '' ),
			'string x'             => array( 'x', '' ),
			'empty string'         => array( '', '' ),
		);
	}

	// -------------------------------------------------------------------------
	// sanitize() — new map/geo keys wired through
	// -------------------------------------------------------------------------

	public function test_sanitize_wires_default_lat_through_helper(): void {
		$out = Settings::sanitize( array( 'default_lat' => '42.3' ), array() );
		$this->assertSame( '42.3', $out['default_lat'] );
	}

	public function test_sanitize_rejects_out_of_range_lat(): void {
		$out = Settings::sanitize( array( 'default_lat' => '91' ), array() );
		$this->assertSame( '', $out['default_lat'] );
	}

	public function test_sanitize_wires_default_lng_through_helper(): void {
		$out = Settings::sanitize( array( 'default_lng' => '-71' ), array() );
		$this->assertSame( '-71', $out['default_lng'] );
	}

	public function test_sanitize_rejects_out_of_range_lng(): void {
		$out = Settings::sanitize( array( 'default_lng' => '181' ), array() );
		$this->assertSame( '', $out['default_lng'] );
	}

	public function test_sanitize_wires_default_zoom_through_helper(): void {
		$out = Settings::sanitize( array( 'default_zoom' => '8' ), array() );
		$this->assertSame( '8', $out['default_zoom'] );
	}

	public function test_sanitize_rejects_out_of_range_zoom(): void {
		$out = Settings::sanitize( array( 'default_zoom' => '50' ), array() );
		$this->assertSame( '', $out['default_zoom'] );
	}

	public function test_sanitize_enable_geolocation_true_when_set(): void {
		$out = Settings::sanitize( array( 'enable_geolocation' => '1' ), array() );
		$this->assertTrue( $out['enable_geolocation'] );
	}

	public function test_sanitize_enable_geolocation_false_when_absent(): void {
		$out = Settings::sanitize( array(), array() );
		$this->assertFalse( $out['enable_geolocation'] );
	}
}
