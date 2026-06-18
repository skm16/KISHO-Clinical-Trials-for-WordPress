<?php
/**
 * Bootstrap for unit tests (no WordPress required).
 *
 * @package SKMCTF\Tests
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-autoloader.php';
( new SKMCTF\Autoloader( dirname( __DIR__ ) . '/includes' ) )->register();

/*
 * Minimal stubs for WordPress sanitization/escaping functions used as
 * sanitize_callback values. These are checked via assertIsCallable() in
 * unit tests, so the functions must exist. Brain Monkey provides absint
 * already; we add the rest here.
 */
if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( (string) $str );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url, $protocols = null ) {
		return filter_var( (string) $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return (string) $data;
	}
}
