<?php
/**
 * Repo interface — minimal contract exposing the three methods the Reconciler
 * needs, allowing a test double (FakeRepo) to be injected in unit tests
 * without touching WordPress or the database.
 *
 * Implemented by Trial_Repository. All three method signatures are already
 * present on that class; adding `implements Repo_Interface` is the only change
 * required there.
 *
 * @package SKMCTF\Data
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Data;

interface Repo_Interface {

	/**
	 * Return all NCT IDs currently stored in the database.
	 *
	 * @return array Array of NCT ID strings.
	 */
	public function all_nct_ids(): array;

	/**
	 * Mark a trial as closed without deleting it.
	 *
	 * @param string $nct NCT ID of the trial to close.
	 * @return void
	 */
	public function mark_closed( string $nct ): void;

	/**
	 * Permanently delete a trial post by NCT ID.
	 *
	 * @param string $nct NCT ID of the trial to delete.
	 * @return void
	 */
	public function delete_by_nct( string $nct ): void;

	/**
	 * Permanently delete multiple trials by NCT ID.
	 *
	 * @param string[] $ncts NCT IDs to delete.
	 * @return int Count of trials actually deleted (unknown NCTs are skipped).
	 */
	public function delete_by_ncts( array $ncts ): int;
}
