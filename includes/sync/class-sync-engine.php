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

/**
 * Composition root that orchestrates a full feed sync across all configured conditions.
 */
final class Sync_Engine {

	/**
	 * HTTP client for ClinicalTrials.gov.
	 *
	 * @var Ctgov_Client
	 */
	private $client;

	/**
	 * Trial data repository.
	 *
	 * @var Trial_Repository
	 */
	private $repo;

	/**
	 * Handles trials that drop from the feed.
	 *
	 * @var Reconciler
	 */
	private $reconciler;

	/**
	 * LLM summary generator.
	 *
	 * @var Summary_Service
	 */
	private $summaries;

	/**
	 * Logger.
	 *
	 * @var Logger_Interface
	 */
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
	public function run( string $trigger = 'manual' ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $trigger reserved for future audit logging; part of public API contract
		$summary = array(
			'inserted'       => 0,
			'updated'        => 0,
			'summarized'     => 0,
			'dropped_result' => '',
			'errors'         => array(),
		);

		$conditions = Settings::conditions();
		$include    = Settings::include_ncts();

		// Nothing to sync unless there is at least one condition OR one manually
		// included NCT. An include-only configuration is a valid use case: a PAG
		// that wants to track a fixed set of trials without a condition search.
		if ( empty( $conditions ) && empty( $include ) ) {
			$this->log->warn( 'Sync skipped: no conditions or included NCTs configured.' );
			$summary['errors'][] = 'no_conditions';
			$this->log->record_sync( $summary );
			return $summary;
		}

		$statuses  = Settings::statuses();
		$exclude   = array_flip( Settings::exclude_ncts() );
		$had_error = false;
		$seen      = array();

		foreach ( $conditions as $condition ) {
			$result = $this->client->fetch_all_for_condition( $condition, $statuses );

			if ( null !== $result['error'] ) {
				$had_error           = true;
				$msg                 = 'Fetch failed for "' . $condition . '": ' . $result['error']->get_error_message();
				$summary['errors'][] = $msg;
				$this->log->error( $msg );
				// Per-condition error: continue to next condition; do NOT abort the run.
				continue;
			}

			foreach ( $result['studies'] as $study ) {
				$this->process_study( $study, $exclude, $summary, $seen );
			}
		}

		// Manual include list: fetch specific NCTs and upsert them.
		if ( ! empty( $include ) ) {
			$inc_result = $this->client->fetch_by_nct_ids( $include );
			if ( null !== $inc_result['error'] ) {
				$summary['errors'][] = $inc_result['error']->get_error_message();
				$this->log->warn( $inc_result['error']->get_error_message() );
				// NOTE: do NOT set $had_error here — a partial include-fetch failure must
				// not block reconciliation of the (successful) condition results. Only
				// condition-fetch errors set $had_error. Included NCTs that did fetch are
				// already in $seen, so they won't be reconciled away.
			}
			foreach ( $inc_result['studies'] as $study ) {
				$this->process_study( $study, $exclude, $summary, $seen );
			}
		}

		// Reconciliation removes trials no longer in the result set. It is only
		// meaningful when at least one CONDITION search ran successfully — that is
		// what defines the "full" expected set. In an include-only configuration
		// (no conditions), $seen holds only the manually included NCTs, which is
		// NOT the full set, so reconciling against it would wrongly close every
		// other stored trial. Skip reconciliation entirely in that case.
		// The Reconciler's own guards (had_error, empty-set, drop-ratio) provide
		// additional no-wipe safety on top of this.
		if ( ! empty( $conditions ) ) {
			$summary['dropped_result'] = $this->reconciler->reconcile(
				array_values( array_unique( $seen ) ),
				$had_error,
				Settings::reconcile_mode()
			);
		} else {
			$summary['dropped_result'] = 'skipped_include_only';
		}

		$this->log->record_sync( $summary );
		return $summary;
	}

	/**
	 * Process one raw study: map, upsert, summarize, and update the run summary.
	 *
	 * @param array             $study   Raw CT.gov study object.
	 * @param array<string,int> $exclude NCT-keyed exclusion lookup.
	 * @param array             $summary Run summary (by reference).
	 * @param string[]          $seen    Seen-NCT list (by reference).
	 * @return void
	 */
	private function process_study( array $study, array $exclude, array &$summary, array &$seen ): void {
		$meta = Field_Mapper::map( $study );
		if ( '' === $meta['nct_id'] || isset( $exclude[ $meta['nct_id'] ] ) ) {
			return;
		}
		$existing = $this->repo->find_id_by_nct( $meta['nct_id'] );
		$post_id  = $this->repo->upsert( $meta );
		if ( ! $post_id ) {
			return;
		}
		if ( $existing ) {
			++$summary['updated'];
		} else {
			++$summary['inserted'];
		}
		$seen[] = $meta['nct_id'];
		if ( $this->summaries->maybe_generate( $post_id, $meta ) ) {
			++$summary['summarized'];
		}
	}
}
