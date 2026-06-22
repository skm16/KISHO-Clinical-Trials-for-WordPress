<?php
/**
 * Unit tests for the Theme resolver — no WordPress required.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Frontend\Theme;

final class ThemeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'esc_attr' => static fn( $v ) => $v,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function with_settings( string $theme, string $mode ): void {
		Functions\when( 'get_option' )->justReturn( array( 'theme' => $theme, 'theme_mode' => $mode ) );
	}

	public function test_skeleton_has_no_skin_class(): void {
		$this->with_settings( 'skeleton', 'light' );
		$this->assertSame( '', Theme::skin_class() );
		$this->assertFalse( Theme::has_skin() );
	}

	public function test_clinical_skin_class(): void {
		$this->with_settings( 'clinical', 'dark' );
		$this->assertSame( 'skmctf-skin--clinical', Theme::skin_class() );
		$this->assertTrue( Theme::has_skin() );
	}

	public function test_warm_skin_class(): void {
		$this->with_settings( 'warm', 'light' );
		$this->assertSame( 'skmctf-skin--warm', Theme::skin_class() );
	}

	public function test_mode_attr_is_escaped_and_correct(): void {
		$this->with_settings( 'clinical', 'dark' );
		$this->assertSame( 'data-skmctf-mode="dark"', Theme::mode_attr() );
		$this->with_settings( 'skeleton', 'light' );
		$this->assertSame( 'data-skmctf-mode="light"', Theme::mode_attr() );
	}

	public function test_active_and_mode_passthrough(): void {
		$this->with_settings( 'warm', 'dark' );
		$this->assertSame( 'warm', Theme::active() );
		$this->assertSame( 'dark', Theme::mode() );
	}
}
