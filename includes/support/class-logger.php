<?php
/**
 * Concrete logger — bounded ring buffer stored in WordPress options.
 *
 * Implements Logger_Interface and adds three WordPress-specific helpers:
 *   record_sync()  — persist a sync summary and clear the last-error option
 *                    when no errors occurred.
 *   last_sync()    — retrieve the most-recent sync summary.
 *   last_error()   — retrieve the last error message (empty string if none).
 *   recent()       — retrieve the log ring buffer (newest-first, max 50 entries).
 *
 * All options are stored with autoload = false so they are not loaded on
 * every page request.
 *
 * @package SKMCTF\Support
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Support;

final class Logger implements Logger_Interface {

	/** @var string WordPress option key for the log ring buffer. */
	private const LOG_OPTION = 'skmctf_log';

	/** @var string WordPress option key for the last-sync summary. */
	private const SYNC_OPTION = 'skmctf_last_sync';

	/** @var string WordPress option key for the last error message. */
	private const ERROR_OPTION = 'skmctf_last_error';

	/** @var int Maximum log entries to keep (ring buffer cap). */
	private const MAX_ENTRIES = 50;

	// -------------------------------------------------------------------------
	// Logger_Interface implementation
	// -------------------------------------------------------------------------

	/**
	 * Log an informational message.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional contextual data.
	 * @return void
	 */
	public function info( string $message, array $context = [] ): void {
		$this->push( 'info', $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional contextual data.
	 * @return void
	 */
	public function warn( string $message, array $context = [] ): void {
		$this->push( 'warn', $message, $context );
	}

	/**
	 * Log an error message and update the last-error option.
	 *
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional contextual data.
	 * @return void
	 */
	public function error( string $message, array $context = [] ): void {
		$this->push( 'error', $message, $context );
		update_option( self::ERROR_OPTION, $message, false );
	}

	// -------------------------------------------------------------------------
	// Extended API
	// -------------------------------------------------------------------------

	/**
	 * Record a sync summary.
	 *
	 * Stores the summary (with a Unix timestamp added under key 't') and clears
	 * the last-error option when no errors occurred.
	 *
	 * @param mixed[] $summary Associative summary data from the sync runner.
	 * @return void
	 */
	public function record_sync( array $summary ): void {
		update_option( self::SYNC_OPTION, array_merge( [ 't' => time() ], $summary ), false );
		if ( empty( $summary['errors'] ) ) {
			update_option( self::ERROR_OPTION, '', false );
		}
	}

	/**
	 * Retrieve the most-recent sync summary.
	 *
	 * @return mixed[]
	 */
	public function last_sync(): array {
		$v = get_option( self::SYNC_OPTION, [] );
		return is_array( $v ) ? $v : [];
	}

	/**
	 * Retrieve the last error message.
	 *
	 * @return string Empty string when no error has been recorded.
	 */
	public function last_error(): string {
		return (string) get_option( self::ERROR_OPTION, '' );
	}

	/**
	 * Retrieve the log ring buffer (newest-first).
	 *
	 * @return mixed[]
	 */
	public function recent(): array {
		$v = get_option( self::LOG_OPTION, [] );
		return is_array( $v ) ? $v : [];
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Prepend a log entry and trim the buffer to MAX_ENTRIES.
	 *
	 * @param string  $level One of 'info', 'warn', 'error'.
	 * @param string  $message The log message.
	 * @param mixed[] $context Optional contextual data.
	 * @return void
	 */
	private function push( string $level, string $message, array $context ): void {
		$log = get_option( self::LOG_OPTION, [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		array_unshift(
			$log,
			[
				't'     => time(),
				'level' => $level,
				'msg'   => $message,
				'ctx'   => $context,
			]
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::MAX_ENTRIES ), false );
	}
}
