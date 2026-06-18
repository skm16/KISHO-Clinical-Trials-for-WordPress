<?php
/**
 * Bootstrap for unit tests (no WordPress required).
 *
 * Load order matters for Brain Monkey / Patchwork compatibility:
 *   1. Composer autoloader (registers PSR-4 autoloading, including Brain Monkey).
 *   2. Patchwork — must be active before any user-land functions are defined
 *      so that Brain Monkey can re-route them inside individual tests.
 *   3. WordPress function stubs — loaded THROUGH Patchwork's stream wrapper
 *      (required from a separate file, after Patchwork is running) so that
 *      Functions\stubs() can override them in SettingsSanitizeTest etc.
 *   4. Plugin autoloader — registers the SKMCTF PSR-4 namespace.
 *
 * @package SKMCTF\Tests
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

// Boot Patchwork before defining WP stubs so Brain Monkey can intercept them.
$_patchwork = dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';
if ( file_exists( $_patchwork ) && ! function_exists( 'Patchwork\redefine' ) ) {
	require_once $_patchwork;
}
unset( $_patchwork );

// WP function stubs — in a separate file loaded after Patchwork is active.
require __DIR__ . '/stubs-wp-functions.php';

require dirname( __DIR__ ) . '/includes/class-autoloader.php';
( new SKMCTF\Autoloader( dirname( __DIR__ ) . '/includes' ) )->register();
