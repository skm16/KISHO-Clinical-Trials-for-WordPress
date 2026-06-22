<?php
/**
 * Integration tests: list/grid view modifier class, columns interaction,
 * toggle markup, and the view-toggle script enqueue.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Admin\Settings;
use SKMCTF\Frontend\List_Renderer;
use SKMCTF\Post_Types\Trial_Post_Type;

final class ViewRenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['wp_scripts'] = null;
		// One published trial so the list (and toggle) actually render.
		$this->factory->post->create(
			array(
				'post_type'   => Trial_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'T',
			)
		);
	}

	private function set_view( string $view ): void {
		update_option( Settings::OPTION, array( 'default_view' => $view ) );
	}

	public function test_list_view_emits_modifier_and_no_cols(): void {
		$this->set_view( 'list' );
		$html = List_Renderer::render( array( 'columns' => 3 ) );
		$this->assertStringContainsString( 'skmctf-list--view-list', $html );
		$this->assertStringContainsString( 'data-skmctf-view="list"', $html );
		$this->assertStringNotContainsString( 'skmctf-list--cols-', $html );
	}

	public function test_grid_view_keeps_columns(): void {
		$this->set_view( 'grid' );
		$html = List_Renderer::render( array( 'columns' => 2 ) );
		$this->assertStringContainsString( 'skmctf-list--view-grid', $html );
		$this->assertStringContainsString( 'data-skmctf-view="grid"', $html );
		$this->assertStringContainsString( 'skmctf-list--cols-2', $html );
	}

	public function test_toggle_markup_present_with_aria_pressed(): void {
		$this->set_view( 'list' );
		$html = List_Renderer::render( array() );
		$this->assertStringContainsString( 'skmctf-view-toggle', $html );
		$this->assertMatchesRegularExpression(
			'/data-skmctf-view-btn="list"[^>]*aria-pressed="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/data-skmctf-view-btn="grid"[^>]*aria-pressed="false"/',
			$html
		);
	}

	public function test_view_toggle_script_enqueued(): void {
		\SKMCTF\Frontend\Assets::do_register();
		$this->set_view( 'grid' );
		List_Renderer::render( array() );
		$this->assertTrue( wp_script_is( 'skmctf-view-toggle', 'enqueued' ) );
	}

	/**
	 * The toggle must expose the admin default so the JS can invalidate a stored
	 * visitor preference when the admin changes the default.
	 */
	public function test_toggle_exposes_admin_default_view(): void {
		$this->set_view( 'grid' );
		$html = List_Renderer::render( array() );
		$this->assertMatchesRegularExpression(
			'/class="skmctf-view-toggle"[^>]*data-skmctf-default-view="grid"/',
			$html
		);

		$this->set_view( 'list' );
		$html = List_Renderer::render( array() );
		$this->assertMatchesRegularExpression(
			'/class="skmctf-view-toggle"[^>]*data-skmctf-default-view="list"/',
			$html
		);
	}
}
