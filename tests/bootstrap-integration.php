<?php
/**
 * Bootstrap for integration tests (requires WordPress test suite).
 *
 * Set WP_TESTS_DIR to the path of the WordPress test library before running.
 * Example (LocalWP terminal):
 *   export WP_TESTS_DIR=/tmp/wordpress-tests-lib
 *   composer test:integration
 *
 * @package SKMCTF\Tests
 */

$_wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_wp_tests_dir . '/includes/bootstrap.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found at {$_wp_tests_dir}.\nRun bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

// Hook our plugin into the WP test environment before it boots.
$GLOBALS['wp_tests_options'] = [
	'active_plugins' => [ 'kisho-clinical-trials/kisho-clinical-trials.php' ],
];

// Load the plugin autoloader when mu-plugins are loaded.
tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/includes/class-autoloader.php';
		( new SKMCTF\Autoloader( dirname( __DIR__ ) . '/includes' ) )->register();
		// Load the plugin main file if it exists.
		$main = dirname( __DIR__ ) . '/kisho-clinical-trials.php';
		if ( file_exists( $main ) ) {
			require $main;
		}
	}
);

require $_wp_tests_dir . '/includes/bootstrap.php';
