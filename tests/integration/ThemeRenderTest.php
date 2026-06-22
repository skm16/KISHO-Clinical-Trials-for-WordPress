<?php
/**
 * Integration tests: theme skin class + mode attribute on the rendered wrapper,
 * and conditional enqueue of per-theme token/font stylesheets.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Admin\Settings;
use SKMCTF\Frontend\List_Renderer;

final class ThemeRenderTest extends WP_UnitTestCase {

	/**
	 * Reset the global style registry before each test.
	 *
	 * $wp_styles is a process-global singleton that WP_UnitTestCase does not
	 * reset between tests, so an enqueue in one test would otherwise leak into
	 * the next. Each real front-end request starts with a fresh registry; this
	 * restores that invariant for the test process.
	 */
	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_styles'] = null;
	}

	private function set_theme( string $theme, string $mode ): void {
		update_option( Settings::OPTION, array( 'theme' => $theme, 'theme_mode' => $mode ) );
	}

	// ---- Task 3: wrapper class + mode attribute ----

	public function test_clinical_dark_wrapper_has_skin_and_mode(): void {
		$this->set_theme( 'clinical', 'dark' );
		$html = List_Renderer::render( array() );
		$this->assertStringContainsString( 'skmctf-skin--clinical', $html );
		$this->assertStringContainsString( 'data-skmctf-mode="dark"', $html );
	}

	public function test_skeleton_wrapper_has_no_skin_class(): void {
		$this->set_theme( 'skeleton', 'light' );
		$html = List_Renderer::render( array() );
		$this->assertStringNotContainsString( 'skmctf-skin--', $html );
		$this->assertStringContainsString( 'data-skmctf-mode="light"', $html );
		$this->assertStringContainsString( 'skmctf-trials-wrap', $html );
	}

	// ---- Task 4: conditional asset enqueue ----

	/**
	 * Register the plugin's assets the way a real front-end request would.
	 *
	 * do_register() is hooked to wp_enqueue_scripts, which does not fire during a
	 * plain WP_UnitTestCase render. Firing it here mirrors a real page load so the
	 * handles exist before enqueue() runs.
	 */
	private function register_assets(): void {
		\SKMCTF\Frontend\Assets::do_register();
	}

	public function test_clinical_theme_enqueues_theme_and_font_css(): void {
		$this->register_assets();
		$this->set_theme( 'clinical', 'light' );
		List_Renderer::render( array() );
		$this->assertTrue( wp_style_is( 'skmctf-theme-clinical', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'skmctf-fonts-clinical', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'skmctf-theme-warm', 'enqueued' ) );
	}

	public function test_skeleton_enqueues_no_theme_css(): void {
		$this->register_assets();
		$this->set_theme( 'skeleton', 'light' );
		List_Renderer::render( array() );
		$this->assertFalse( wp_style_is( 'skmctf-theme-clinical', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'skmctf-fonts-clinical', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'skmctf-frontend', 'enqueued' ) );
	}
}
