<?php
/**
 * Main plugin class (singleton).
 *
 * @package SKMCTF
 */

namespace SKMCTF;

/**
 * Main plugin singleton — boots all subsystems via WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Return the single Plugin instance, creating it if needed.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up all hooks. Each subsystem registers itself here.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( \SKMCTF\Post_Types\Trial_Post_Type::class, 'register' ) );
		add_action( 'init', array( \SKMCTF\Post_Types\Trial_Taxonomies::class, 'register' ) );
		add_action( 'init', array( \SKMCTF\Post_Types\Trial_Meta::class, 'register' ) );
		( new \SKMCTF\Sync\Scheduler() )->register();

		// Front-end: shortcode + assets (registered early; assets enqueued lazily by renderer).
		add_action( 'init', array( \SKMCTF\Frontend\Shortcode::class, 'register' ) );
		\SKMCTF\Frontend\Assets::register();

		// Single trial SEO (noindex via wp_robots filter) + template routing.
		( new \SKMCTF\Frontend\Seo() )->register();
		( new \SKMCTF\Frontend\Template_Router() )->register();

		// Gutenberg block (server-rendered via render_callback → List_Renderer).
		( new \SKMCTF\Frontend\Block() )->register();

		if ( is_admin() ) {
			( new \SKMCTF\Admin\Settings_Page() )->register();
			( new \SKMCTF\Admin\Sync_Now_Controller() )->register();
			( new \SKMCTF\Admin\Admin_Notices() )->register();
		}
	}

	/**
	 * Load the plugin text domain for i18n.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( SKMCTF_TEXT_DOMAIN, false, dirname( plugin_basename( SKMCTF_FILE ) ) . '/languages' );
	}
}
