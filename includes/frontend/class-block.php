<?php
/**
 * Registers the skmctf/trials Gutenberg block (server-rendered).
 *
 * The block's editorScript (compiled JS) powers the Inspector Controls.
 * Actual HTML is always produced by the PHP render_callback so the front
 * end always shows live, indexable data — no stale serialised output in
 * the post content.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

/**
 * Handles block registration and server-side rendering.
 */
final class Block {

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_block' ] );
	}

	/**
	 * Register the block type from the block.json directory.
	 *
	 * `render_callback` ensures WordPress always calls our PHP method to
	 * render output rather than using any saved markup stored in the DB.
	 *
	 * @return void
	 */
	public function register_block(): void {
		$block_dir = SKMCTF_PATH . 'blocks/trials';

		// Point WordPress at the compiled build directory for assets while
		// still using the block.json in the source directory for metadata.
		// We override editorScript to the compiled asset.
		register_block_type(
			$block_dir,
			[
				'render_callback' => [ $this, 'render' ],
			]
		);

		// Register translations for the editor script if the compiled asset
		// was registered by register_block_type (handle: skmctf-trials-editor-script).
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'skmctf-trials-editor-script',
				'kisho-clinical-trials',
				SKMCTF_PATH . 'languages'
			);
		}
	}

	/**
	 * Render the block on the front end (and in the editor preview).
	 *
	 * Maps camelCase Gutenberg attributes to the snake_case keys that
	 * List_Renderer::render() expects.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 * @return string Escaped HTML.
	 */
	public function render( array $attributes ): string {
		return List_Renderer::render(
			[
				'status'   => $attributes['status']  ?? '',
				'phase'    => $attributes['phase']   ?? '',
				'state'    => $attributes['state']   ?? '',
				'per_page' => $attributes['perPage'] ?? 20,
				'map'      => ! empty( $attributes['showMap'] ),
				'columns'  => $attributes['columns'] ?? 1,
			]
		);
	}
}
