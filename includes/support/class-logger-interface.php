<?php
/**
 * Logger interface — minimal contract so Reconciler and other classes
 * can write structured log messages without depending on a concrete logger.
 *
 * Implementations must be provided at construction time; no WP functions
 * are required, making consumers unit-testable without WordPress.
 *
 * @package SKMCTF\Support
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Support;

interface Logger_Interface {

	/**
	 * Log an informational message.
	 *
	 * @param string $message The log message.
	 * @param array  $context Optional contextual data.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Log a warning message.
	 *
	 * @param string $message The log message.
	 * @param array  $context Optional contextual data.
	 * @return void
	 */
	public function warn( string $message, array $context = array() ): void;

	/**
	 * Log an error message.
	 *
	 * @param string $message The log message.
	 * @param array  $context Optional contextual data.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void;
}
