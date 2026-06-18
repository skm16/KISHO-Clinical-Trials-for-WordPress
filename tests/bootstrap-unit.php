<?php
/**
 * Bootstrap for unit tests (no WordPress required).
 *
 * @package SKMCTF\Tests
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-autoloader.php';
( new SKMCTF\Autoloader( dirname( __DIR__ ) . '/includes' ) )->register();
