<?php
/**
 * Main plugin class (singleton).
 *
 * @package SKMCTF
 */

namespace SKMCTF;

final class Plugin {
	private static ?Plugin $instance = null;

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
		add_action( 'init', [ $this, 'load_textdomain' ] );
		// Subsystems are registered here as later tasks add them.
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( SKMCTF_TEXT_DOMAIN, false, dirname( plugin_basename( SKMCTF_FILE ) ) . '/languages' );
	}
}
