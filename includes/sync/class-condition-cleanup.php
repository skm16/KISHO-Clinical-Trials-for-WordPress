<?php
/**
 * Condition Cleanup — computes the set of stored trials that no longer match
 * any configured condition, and deletes a given snapshot of them.
 *
 * The off-condition set is `all_stored − (seen ∪ includes)`, where `seen` is
 * the set of NCT IDs returned by a live re-fetch of the configured conditions
 * and `includes` is the always-include NCT list (exempt by design).
 *
 * Set math is a pure static helper (unit-tested). preview() performs the live
 * fetch via Ctgov_Client and is covered by integration tests. The automatic
 * sync and its no-wipe guards are untouched by this class.
 *
 * @package SKMCTF\Sync
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Sync;

use SKMCTF\Data\Repo_Interface;
use SKMCTF\Support\Logger_Interface;

/**
 * Computes and deletes trials that no longer match any configured condition.
 */
final class Condition_Cleanup {

	/**
	 * HTTP client for ClinicalTrials.gov.
	 *
	 * @var Ctgov_Client
	 */
	private $client;

	/**
	 * Trial data repository.
	 *
	 * @var Repo_Interface
	 */
	private $repo;

	/**
	 * Logger.
	 *
	 * @var Logger_Interface
	 */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Ctgov_Client     $client Client for the live condition re-fetch.
	 * @param Repo_Interface   $repo   Trial repository.
	 * @param Logger_Interface $log    Logger.
	 */
	public function __construct( Ctgov_Client $client, Repo_Interface $repo, Logger_Interface $log ) {
		$this->client = $client;
		$this->repo   = $repo;
		$this->log    = $log;
	}

	/**
	 * Compute the off-condition NCT set: stored − (seen ∪ includes).
	 *
	 * All inputs are compared as canonical uppercase, trimmed. The result is
	 * de-duplicated and sorted for stable output.
	 *
	 * @param string[] $stored   All stored NCT IDs.
	 * @param string[] $seen     NCT IDs seen in the live fetch.
	 * @param string[] $includes Always-include NCT IDs (exempt).
	 * @return string[] Off-condition NCT IDs (sorted, unique, canonical).
	 */
	public static function compute_off_condition( array $stored, array $seen, array $includes ): array {
		$norm = static function ( array $items ): array {
			$out = array();
			foreach ( $items as $nct ) {
				$nct = strtoupper( trim( (string) $nct ) );
				if ( '' !== $nct ) {
					$out[ $nct ] = true;
				}
			}
			return $out;
		};

		$stored_set    = $norm( $stored );
		$protected_set = $norm( array_merge( $seen, $includes ) );

		$off = array();
		foreach ( array_keys( $stored_set ) as $nct ) {
			if ( ! isset( $protected_set[ $nct ] ) ) {
				$off[] = $nct;
			}
		}
		sort( $off );
		return $off;
	}

	/**
	 * Delete the given NCT snapshot.
	 *
	 * @param string[] $ncts NCT IDs to delete (typically a previewed snapshot).
	 * @return int Count of trials actually deleted.
	 */
	public function delete( array $ncts ): int {
		$deleted = $this->repo->delete_by_ncts( $ncts );
		$this->log->info(
			'Off-condition cleanup deleted trials.',
			array( 'deleted' => $deleted )
		);
		return $deleted;
	}
}
