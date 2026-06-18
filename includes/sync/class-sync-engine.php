<?php
/**
 * Sync Engine — composition root that orchestrates a full feed sync.
 *
 * Iterates over all configured conditions, fetches trials from ClinicalTrials.gov,
 * maps and upserts each trial, generates LLM summaries where appropriate, then
 * runs the reconciler to handle trials that have dropped from the feed.
 *
 * Safety guarantee: a fetch error sets $had_error which causes Reconciler to
 * skip reconciliation, preserving all existing trials.
 *
 * @package SKMCTF\Sync
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Sync;

use SKMCTF\Data\Trial_Repository;
use SKMCTF\LLM\Provider_Factory;
use SKMCTF\LLM\Summary_Service;
use SKMCTF\Admin\Settings;
use SKMCTF\Support\Logger;
use SKMCTF\Support\Logger_Interface;

final class Sync_Engine {

	/** @var Ctgov_Client */
	private $client;

	/** @var Trial_Repository */
	private $repo;

	/** @var Reconciler */
	private $reconciler;

	/** @var Summary_Service */
	private $summaries;

	/** @var Logger_Interface */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Ctgov_Client     $client     HTTP client for ClinicalTrials.gov.
	 * @param Trial_Repository $repo       Trial data repository.
	 * @param Reconciler       $reconciler Reconciler for dropped trials.
	 * @param Summary_Service  $summaries  LLM summary generator.
	 * @param Logger_Interface $log        Logger.
	 */
	public function __construct(
		Ctgov_Client $client,
		Trial_Repository $repo,
		Reconciler $reconciler,
		Summary_Service $summaries,
		Logger_Interface $log
	) {
		$this->client     = $client;
		$this->repo       = $repo;
		$this->reconciler = $reconciler;
		$this->summaries  = $summaries;
		$this->log        = $log;
	}

	/**
	 * Composition root: wires real production dependencies.
	 *
	 * @return self
	 */
	public static function build(): self {
		$repo = new Trial_Repository();
		$log  = new Logger();
		return new self(
			new Ctgov_Client(),
			$repo,
			new Reconciler( $repo, $log ),
			new Summary_Service( Provider_Factory::make(), $log ),
			$log
		);
	}

	/**
	 * Run a full sync.
	 *
	 * @param string $trigger Who triggered this run: 'scheduled', 'manual', 'test', etc.
	 * @return array{inserted:int,updated:int,summarized:int,dropped_result:string,errors:array}
	 */
	public function run( string $trigger = 'manual' ): array {
		$summary = [
			'inserted'       => 0,
			'updated'        => 0,
			'summarized'     => 0,
			'dropped_result' => '',
			'errors'         => [],
		];

		$conditions = Settings::conditions();

		if ( empty( $conditions ) ) {
			$this->log->warn( 'Sync skipped: no conditions configured.' );
			$summary['errors'][] = 'no_conditions';
			$this->log->record_sync( $summary );
			return $summary;
		}

		$statuses  = Settings::statuses();
		$exclude   = array_flip( Settings::exclude_ncts() );
		$had_error = false;
		$seen      = [];

		foreach ( $conditions as $condition ) {
			$result = $this->client->fetch_all_for_condition( $condition, $statuses );

			if ( null !== $result['error'] ) {
				$had_error         = true;
				$msg               = 'Fetch failed for "' . $condition . '": ' . $result['error']->get_error_message();
				$summary['errors'][] = $msg;
				$this->log->error( $msg );
				// Per-condition error: continue to next condition; do NOT abort the run.
				continue;
			}

			foreach ( $result['studies'] as $study ) {
				$meta = Field_Mapper::map( $study );

				// Skip unmappable studies or excluded NCT IDs.
				if ( '' === $meta['nct_id'] || isset( $exclude[ $meta['nct_id'] ] ) ) {
					continue;
				}

				// Determine insert vs update BEFORE the upsert.
				$existing = $this->repo->find_id_by_nct( $meta['nct_id'] );
				$post_id  = $this->repo->upsert( $meta );

				if ( ! $post_id ) {
					continue;
				}

				if ( $existing ) {
					$summary['updated']++;
				} else {
					$summary['inserted']++;
				}

				$seen[] = $meta['nct_id'];

				if ( $this->summaries->maybe_generate( $post_id, $meta ) ) {
					$summary['summarized']++;
				}
			}
		}

		// Reconciler skips automatically when $had_error is true (no-wipe safety).
		$summary['dropped_result'] = $this->reconciler->reconcile(
			array_values( array_unique( $seen ) ),
			$had_error,
			Settings::reconcile_mode()
		);

		$this->log->record_sync( $summary );
		return $summary;
	}
}
