<?php
/**
 * Scheduler — registers and manages the daily Action Scheduler recurring job.
 *
 * Responsible for:
 *   - Binding the skmctf_daily_sync action hook to run_sync().
 *   - Scheduling the first recurring action on activation.
 *   - Ensuring the recurring action exists on every 'init' (self-heal).
 *   - Opting into Action Scheduler's built-in ensure_recurring_actions hook
 *     when the function is available (guarded to stay PHP 7.4 safe).
 *   - Unscheduling all pending actions on deactivation.
 *
 * All function_exists() guards keep this class usable in unit tests where
 * the Action Scheduler functions are not loaded.
 *
 * @package SKMCTF\Sync
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Sync;

final class Scheduler {

	/** @var string Action Scheduler hook name. */
	public const ACTION = 'skmctf_daily_sync';

	/** @var string Action Scheduler group name. */
	public const GROUP = 'kisho-clinical-trials';

	/**
	 * Register all hooks.
	 *
	 * Called from Plugin::boot() on plugins_loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		// Wire the AS action hook to our sync runner.
		add_action( self::ACTION, array( $this, 'run_sync' ) );

		// Ensure the recurring action exists on every init (simple self-heal).
		add_action( 'init', array( $this, 'ensure_scheduled' ) );

		// Opt into Action Scheduler's dedicated recurring-action ensure hook when available.
		if ( function_exists( 'as_supports' ) && as_supports( 'ensure_recurring_actions_hook' ) ) {
			add_action( 'action_scheduler_ensure_recurring_actions', array( $this, 'ensure_scheduled' ) );
		}
	}

	/**
	 * Run the sync engine. Called by Action Scheduler when the job fires.
	 *
	 * @return void
	 */
	public function run_sync(): void {
		Sync_Engine::build()->run( 'scheduled' );
	}

	/**
	 * Ensure the recurring action is scheduled.
	 *
	 * Safe to call multiple times; only schedules when not already queued.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( self::ACTION, array(), self::GROUP ) ) {
			as_schedule_recurring_action(
				strtotime( 'tomorrow 3:00am' ),
				DAY_IN_SECONDS,
				self::ACTION,
				array(),
				self::GROUP
			);
		}
	}

	/**
	 * Called on plugin activation: schedule the first occurrence.
	 *
	 * @return void
	 */
	public function activate(): void {
		$this->ensure_scheduled();
	}

	/**
	 * Called on plugin deactivation: remove all pending scheduled actions.
	 *
	 * @return void
	 */
	public function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array(), self::GROUP );
		}
	}
}
