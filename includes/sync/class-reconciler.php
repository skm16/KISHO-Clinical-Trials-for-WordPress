<?php
/**
 * Reconciler — compares the set of NCT IDs seen in the latest feed fetch
 * against those stored in the database, and closes or removes any that
 * have dropped out, subject to three mandatory no-wipe safety guards.
 *
 * Guard order (each returns before any DB mutation):
 *   1. had_error  — a fetch error occurred this run; data is incomplete.
 *   2. seen empty — the feed returned nothing; looks like an API failure.
 *   3. drop ratio — would remove more than MAX_DROP_RATIO of existing posts.
 *
 * MAX_DROP_RATIO defaults to 0.5 and is filterable via
 * `skmctf_max_drop_ratio` when WordPress is loaded.  The
 * `function_exists` guard keeps the class unit-testable without WP.
 *
 * @package SKMCTF\Sync
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Sync;

use SKMCTF\Data\Repo_Interface;
use SKMCTF\Support\Logger_Interface;

final class Reconciler {

	/** Maximum fraction of existing trials that may be dropped in one run. */
	public const MAX_DROP_RATIO = 0.5;

	/** @var Repo_Interface */
	private $repo;

	/** @var Logger_Interface */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Repo_Interface   $repo Repository for reading and mutating trials.
	 * @param Logger_Interface $log  Logger for audit messages.
	 */
	public function __construct( Repo_Interface $repo, Logger_Interface $log ) {
		$this->repo = $repo;
		$this->log  = $log;
	}

	/**
	 * Run reconciliation.
	 *
	 * @param array  $seen_nct  NCT IDs observed in the latest feed fetch.
	 * @param bool   $had_error Whether any fetch error occurred this run.
	 * @param string $mode      'mark_closed' to soft-close, 'remove' to delete.
	 * @return string One of 'skipped_error'|'skipped_empty'|'skipped_ratio'|'done'.
	 */
	public function reconcile( array $seen_nct, bool $had_error, string $mode ): string {

		// Guard 1: abort if a fetch error was flagged — the data is incomplete.
		if ( $had_error ) {
			$this->log->warn( 'Reconcile skipped: a fetch error occurred this run.' );
			return 'skipped_error';
		}

		// Guard 2: abort if no NCT IDs were seen — the feed may have failed silently.
		if ( empty( $seen_nct ) ) {
			$this->log->warn( 'Reconcile skipped: empty result set.' );
			return 'skipped_empty';
		}

		$existing = $this->repo->all_nct_ids();
		$dropped  = array_values( array_diff( $existing, $seen_nct ) );

		// Resolve the maximum allowed drop ratio — filterable when WP is loaded.
		$max_ratio = function_exists( 'apply_filters' )
			? (float) apply_filters( 'skmctf_max_drop_ratio', self::MAX_DROP_RATIO )
			: self::MAX_DROP_RATIO;

		// Guard 3: abort if too many existing trials would be dropped.
		if ( count( $existing ) > 0 && ( count( $dropped ) / count( $existing ) ) > $max_ratio ) {
			$this->log->warn(
				'Reconcile skipped: drop ratio exceeded.',
				array(
					'dropped'  => count( $dropped ),
					'existing' => count( $existing ),
				)
			);
			return 'skipped_ratio';
		}

		// All guards passed — mutate dropped trials.
		foreach ( $dropped as $nct ) {
			if ( 'remove' === $mode ) {
				$this->repo->delete_by_nct( $nct );
			} else {
				$this->repo->mark_closed( $nct );
			}
		}

		$this->log->info(
			'Reconcile complete.',
			array(
				'dropped' => count( $dropped ),
				'mode'    => $mode,
			)
		);
		return 'done';
	}
}
