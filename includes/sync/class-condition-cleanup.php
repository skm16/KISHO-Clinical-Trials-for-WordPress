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

use SKMCTF\Admin\Settings;
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
	 * Build a preview of trials that no longer match any configured condition.
	 *
	 * Performs a live re-fetch of every configured condition. If ANY condition
	 * fetch errors, had_error is true and the off_condition list should not be
	 * acted upon (the controller suppresses the confirm step).
	 *
	 * @return array{off_condition:string[],had_error:bool,seen_count:int,stored_count:int}
	 */
	public function preview(): array {
		$conditions = array_values(
			array_filter(
				array_map( static fn( $c ) => trim( (string) $c ), Settings::conditions() ),
				static fn( $c ) => '' !== $c
			)
		);
		$statuses   = Settings::statuses();
		$includes   = Settings::include_ncts();

		// Hard guard: with no configured conditions the off-condition set math
		// (stored − seen, where seen would be empty) resolves to (nearly) every
		// stored trial. Refuse outright so neither a direct admin-post request
		// nor a future caller can snapshot the whole database for deletion.
		if ( empty( $conditions ) ) {
			$this->log->warn( 'Cleanup preview refused: no conditions configured.' );
			return array(
				'off_condition' => array(),
				'had_error'     => true,
				'seen_count'    => 0,
				'stored_count'  => count( $this->repo->all_nct_ids() ),
			);
		}

		$seen      = array();
		$had_error = false;

		foreach ( $conditions as $condition ) {
			$result = $this->client->fetch_all_for_condition( $condition, $statuses );

			if ( null !== $result['error'] ) {
				$had_error = true;
				$this->log->warn(
					'Cleanup preview fetch failed for "' . $condition . '": ' . $result['error']->get_error_message()
				);
				continue;
			}

			foreach ( $result['studies'] as $study ) {
				$meta = Field_Mapper::map( $study );
				if ( '' !== $meta['nct_id'] ) {
					$seen[] = $meta['nct_id'];
				}
			}
		}

		$stored = $this->repo->all_nct_ids();
		$off    = self::compute_off_condition( $stored, $seen, $includes );

		return array(
			'off_condition' => $off,
			'had_error'     => $had_error,
			'seen_count'    => count( array_unique( $seen ) ),
			'stored_count'  => count( $stored ),
		);
	}

	/**
	 * Delete the given NCT snapshot, honouring the current always-include list.
	 *
	 * The include list is re-read and subtracted here — not only at preview — so
	 * that an NCT added to "always include" between preview and confirm is never
	 * deleted, even though the frozen snapshot still lists it. The include list is
	 * the final authority at delete time.
	 *
	 * @param string[] $ncts NCT IDs to delete (typically a previewed snapshot).
	 * @return int Count of trials actually deleted.
	 */
	public function delete( array $ncts ): int {
		$includes = array();
		foreach ( Settings::include_ncts() as $nct ) {
			$nct = strtoupper( trim( (string) $nct ) );
			if ( '' !== $nct ) {
				$includes[ $nct ] = true;
			}
		}

		$to_delete = array();
		foreach ( $ncts as $nct ) {
			$canon = strtoupper( trim( (string) $nct ) );
			if ( '' !== $canon && ! isset( $includes[ $canon ] ) ) {
				$to_delete[] = $canon;
			}
		}

		$deleted = $this->repo->delete_by_ncts( $to_delete );
		$this->log->info(
			'Off-condition cleanup deleted trials.',
			array(
				'deleted'  => $deleted,
				'exempted' => count( $ncts ) - count( $to_delete ),
			)
		);
		return $deleted;
	}
}
