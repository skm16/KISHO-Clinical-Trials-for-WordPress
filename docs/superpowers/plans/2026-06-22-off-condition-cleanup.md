# Off-Condition Trial Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an explicit, preview-gated admin action that permanently deletes trials no longer matching any currently configured condition.

**Architecture:** A new `Condition_Cleanup` service computes the off-condition NCT set (`all_stored − (seen ∪ includes)`) from a live re-fetch of the configured conditions; a `Cleanup_Controller` wires two nonce-protected `admin_post` actions (Preview stores the set in a transient; Confirm deletes exactly that snapshot); the settings page gains a "Maintenance" section. The automatic sync and its no-wipe guards are untouched.

**Tech Stack:** PHP 7.4+, WordPress 6.4+, PHPUnit 9 + Brain Monkey (unit), WP integration test suite, WordPress Coding Standards (PHPCS).

## Global Constraints

- WordPress Coding Standards (WordPress-Extra): tabs for indent, Yoda conditions, `( $x )` spacing, long-array `array()` syntax, full docblocks on every class/method. Verify with `composer phpcs`.
- All user-facing strings wrapped in i18n functions with the `kisho-clinical-trials` text domain.
- Capability gate `manage_options` + `check_admin_referer` on every admin-post handler, before any work (mirror `Sync_Now_Controller`).
- NCT IDs are stored canonical uppercase (`^NCT\d{8}$`); normalise external/compared NCTs with `strtoupper( trim( ... ) )`.
- Deletion reuses the existing per-trial hard-delete path so post-deletion side effects (term-count decrement, `deleted_post` hooks, `states_by_country` cache flush) fire — do NOT delete rows directly.
- The off-condition set is exactly `all_stored − (seen ∪ include_ncts)`. The include list is always exempt.
- Confirm deletes the previewed transient snapshot, never a recomputed set.
- Transient key: `skmctf_cleanup_preview`, TTL `15 * MINUTE_IN_SECONDS`.
- No change to `Sync_Engine`, `Reconciler`, or `reconcile_mode`.

---

### Task 1: Add `delete_by_ncts()` to repo interface and repository

**Files:**
- Modify: `includes/data/class-repo-interface.php`
- Modify: `includes/data/class-trial-repository.php:231-236` (add new method after `delete_by_nct`)
- Modify: `tests/unit/ReconcilerTest.php` (FakeRepo must implement the new interface method)
- Test: `tests/unit/TrialRepositoryDeleteTest.php` (new)

**Interfaces:**
- Consumes: existing `Trial_Repository::delete_by_nct( string $nct ): void`.
- Produces: `Repo_Interface::delete_by_ncts( array $ncts ): int` and `Trial_Repository::delete_by_ncts( array $ncts ): int` — deletes each NCT via the existing single-delete path, returns the count actually deleted (an NCT with no matching post is skipped and not counted).

- [ ] **Step 1: Add the method to the interface**

In `includes/data/class-repo-interface.php`, add after the `delete_by_nct` declaration (before the closing `}`):

```php
	/**
	 * Permanently delete multiple trials by NCT ID.
	 *
	 * @param string[] $ncts NCT IDs to delete.
	 * @return int Count of trials actually deleted (unknown NCTs are skipped).
	 */
	public function delete_by_ncts( array $ncts ): int;
```

- [ ] **Step 2: Update FakeRepo so the unit suite still compiles**

In `tests/unit/ReconcilerTest.php`, inside `final class FakeRepo`, add this method (after `delete_by_nct`):

```php
	public function delete_by_ncts( array $ncts ): int {
		$count = 0;
		foreach ( $ncts as $nct ) {
			$this->deleted[] = $nct;
			++$count;
		}
		return $count;
	}
```

- [ ] **Step 3: Run the unit suite to confirm it still passes (interface change compiles)**

Run: `composer test:unit`
Expected: PASS — `OK (66 tests, ...)`. (FakeRepo now satisfies the extended interface.)

- [ ] **Step 4: Write the failing test for the real repository method**

Create `tests/unit/TrialRepositoryDeleteTest.php`. This is a Brain Monkey test: it stubs `wp_delete_post` and the `find_id_by_nct` lookup indirectly by stubbing the WordPress functions `Trial_Repository::delete_by_nct` calls. Because `delete_by_nct` calls `find_id_by_nct` (which queries WP), the simplest honest unit test asserts the *counting/delegation* behaviour by spying on `delete_by_nct` through a partial subclass.

```php
<?php
/**
 * Unit test for Trial_Repository::delete_by_ncts counting/delegation.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Data\Trial_Repository;

/**
 * Subclass that records delete_by_nct calls and simulates which NCTs exist.
 */
final class SpyDeleteRepo extends Trial_Repository {
	/** @var string[] */
	public array $deleted = array();
	/** @var string[] NCTs that "exist" and should count as deleted. */
	private array $existing;

	public function __construct( array $existing ) {
		$this->existing = $existing;
	}

	public function delete_by_nct( string $nct ): void {
		$this->deleted[] = $nct;
	}

	// Expose counting logic by overriding the existence check used in delete_by_ncts.
	protected function exists( string $nct ): bool {
		return in_array( $nct, $this->existing, true );
	}
}

final class TrialRepositoryDeleteTest extends TestCase {

	public function test_counts_only_existing_ncts(): void {
		$repo = new SpyDeleteRepo( array( 'NCT00000001', 'NCT00000002' ) );
		$deleted = $repo->delete_by_ncts( array( 'NCT00000001', 'NCT00000002', 'NCT00000009' ) );

		$this->assertSame( 2, $deleted );
		$this->assertSame(
			array( 'NCT00000001', 'NCT00000002' ),
			$repo->deleted
		);
	}

	public function test_empty_input_deletes_nothing(): void {
		$repo = new SpyDeleteRepo( array() );
		$this->assertSame( 0, $repo->delete_by_ncts( array() ) );
		$this->assertSame( array(), $repo->deleted );
	}
}
```

- [ ] **Step 5: Run the test to verify it fails**

Run: `composer test:unit -- --filter TrialRepositoryDeleteTest`
Expected: FAIL — `delete_by_ncts` and/or `exists` not defined.

- [ ] **Step 6: Implement `delete_by_ncts` (and the `exists` seam) in the repository**

In `includes/data/class-trial-repository.php`, immediately after `delete_by_nct` (line ~236), add:

```php
	/**
	 * Whether a trial with the given NCT ID exists.
	 *
	 * Extracted as a protected seam so delete_by_ncts() can be unit-tested
	 * without a live database.
	 *
	 * @param string $nct NCT ID.
	 * @return bool
	 */
	protected function exists( string $nct ): bool {
		return (bool) $this->find_id_by_nct( $nct );
	}

	/**
	 * Permanently delete multiple trials by NCT ID.
	 *
	 * @param string[] $ncts NCT IDs to delete.
	 * @return int Count of trials actually deleted (unknown NCTs are skipped).
	 */
	public function delete_by_ncts( array $ncts ): int {
		$count = 0;
		foreach ( $ncts as $nct ) {
			$nct = strtoupper( trim( (string) $nct ) );
			if ( '' === $nct || ! $this->exists( $nct ) ) {
				continue;
			}
			$this->delete_by_nct( $nct );
			++$count;
		}
		return $count;
	}
```

`Trial_Repository` already declares `final class Trial_Repository implements Repo_Interface` (it implements the interface for the Reconciler), so adding the method body above is the only change needed in this file — no class-declaration edit required.

- [ ] **Step 7: Run the new test to verify it passes**

Run: `composer test:unit -- --filter TrialRepositoryDeleteTest`
Expected: PASS — `OK (2 tests, ...)`.

- [ ] **Step 8: Run the full unit suite + PHPCS**

Run: `composer test:unit && composer phpcs -- includes/data/class-trial-repository.php includes/data/class-repo-interface.php tests/unit/TrialRepositoryDeleteTest.php tests/unit/ReconcilerTest.php`
Expected: unit PASS; PHPCS no output (clean).

- [ ] **Step 9: Commit**

```bash
git add includes/data/class-repo-interface.php includes/data/class-trial-repository.php tests/unit/TrialRepositoryDeleteTest.php tests/unit/ReconcilerTest.php
git commit -m "feat: add Trial_Repository::delete_by_ncts bulk delete with count"
```

---

### Task 2: `Condition_Cleanup` service — pure off-condition set math

**Files:**
- Create: `includes/sync/class-condition-cleanup.php`
- Test: `tests/unit/ConditionCleanupTest.php` (new)

**Interfaces:**
- Consumes: `Repo_Interface::all_nct_ids(): array`, `Repo_Interface::delete_by_ncts( array ): int`.
- Produces:
  - `Condition_Cleanup::compute_off_condition( array $stored, array $seen, array $includes ): array` — pure static helper returning the sorted, de-duplicated list `stored − (seen ∪ includes)`, all compared as canonical uppercase.
  - `Condition_Cleanup::delete( array $ncts ): int` — delegates to `Repo_Interface::delete_by_ncts`.
  - Constructor `__construct( Ctgov_Client $client, Repo_Interface $repo, Logger_Interface $log )`.
  - `Condition_Cleanup::preview(): array` — added in Task 3.

This task implements ONLY the pure set math + `delete()` (both unit-testable without WordPress or the network). `preview()` (which calls the `final` `Ctgov_Client`) is Task 3 and is covered by integration tests.

- [ ] **Step 1: Write the failing test for the pure set math**

Create `tests/unit/ConditionCleanupTest.php`:

```php
<?php
/**
 * Unit tests for Condition_Cleanup pure set math and delete delegation.
 *
 * Pure PHP — no WordPress, no network. Reuses FakeRepo from ReconcilerTest.
 *
 * @package SKMCTF\Tests\Unit
 */

namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Condition_Cleanup;

final class ConditionCleanupTest extends TestCase {

	public function test_off_condition_is_stored_minus_seen_and_includes(): void {
		$stored   = array( 'NCT00000001', 'NCT00000002', 'NCT00000003', 'NCT00000004' );
		$seen     = array( 'NCT00000002' );
		$includes = array( 'NCT00000003' );

		$off = Condition_Cleanup::compute_off_condition( $stored, $seen, $includes );

		// 1 and 4 are neither seen nor included.
		$this->assertSame( array( 'NCT00000001', 'NCT00000004' ), $off );
	}

	public function test_includes_are_exempt_even_when_not_seen(): void {
		$stored   = array( 'NCT00000001', 'NCT00000005' );
		$seen     = array();
		$includes = array( 'NCT00000005' );

		$off = Condition_Cleanup::compute_off_condition( $stored, $seen, $includes );

		$this->assertSame( array( 'NCT00000001' ), $off );
	}

	public function test_comparison_is_case_insensitive_canonical(): void {
		$stored   = array( 'NCT00000001', 'NCT00000002' );
		$seen     = array( 'nct00000002' ); // lower-case from a different source
		$includes = array();

		$off = Condition_Cleanup::compute_off_condition( $stored, $seen, $includes );

		$this->assertSame( array( 'NCT00000001' ), $off );
	}

	public function test_empty_when_all_stored_seen(): void {
		$off = Condition_Cleanup::compute_off_condition(
			array( 'NCT00000001' ),
			array( 'NCT00000001' ),
			array()
		);
		$this->assertSame( array(), $off );
	}

	public function test_delete_delegates_to_repo_and_returns_count(): void {
		$repo  = new FakeRepo( array( 'NCT00000001', 'NCT00000002' ) );
		$clean = new Condition_Cleanup( new \SKMCTF\Sync\Ctgov_Client(), $repo, new NullLog() );

		$deleted = $clean->delete( array( 'NCT00000001', 'NCT00000002' ) );

		$this->assertSame( 2, $deleted );
		$this->assertSame( array( 'NCT00000001', 'NCT00000002' ), $repo->deleted );
	}
}
```

Note: `FakeRepo` and `NullLog` already exist in `tests/unit/ReconcilerTest.php` in the same `SKMCTF\Tests\Unit` namespace; PHPUnit loads all test files, so they are available here. `FakeRepo::delete_by_ncts` was added in Task 1.

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test:unit -- --filter ConditionCleanupTest`
Expected: FAIL — class `Condition_Cleanup` not found.

- [ ] **Step 3: Implement the service (pure math + delete only)**

Create `includes/sync/class-condition-cleanup.php`:

```php
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
use SKMCTF\Admin\Settings;

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
		$norm = static function ( array $list ): array {
			$out = array();
			foreach ( $list as $nct ) {
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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test:unit -- --filter ConditionCleanupTest`
Expected: PASS — `OK (5 tests, ...)`.

- [ ] **Step 5: Run full unit suite + PHPCS**

Run: `composer test:unit && composer phpcs -- includes/sync/class-condition-cleanup.php tests/unit/ConditionCleanupTest.php`
Expected: unit PASS; PHPCS clean.

- [ ] **Step 6: Commit**

```bash
git add includes/sync/class-condition-cleanup.php tests/unit/ConditionCleanupTest.php
git commit -m "feat: Condition_Cleanup off-condition set math and delete delegation"
```

---

### Task 3: `Condition_Cleanup::preview()` — live re-fetch orchestration

**Files:**
- Modify: `includes/sync/class-condition-cleanup.php` (add `preview()`)
- Test: `tests/integration/ConditionCleanupPreviewTest.php` (new)

**Interfaces:**
- Consumes: `Ctgov_Client::fetch_all_for_condition( string $condition, array $statuses ): array` returning `array{ studies: array, error: \WP_Error|null }`; `Settings::conditions(): array`, `Settings::statuses(): array`, `Settings::include_ncts(): array`; `Field_Mapper::map( array $study ): array` (provides canonical `nct_id`); `Repo_Interface::all_nct_ids(): array`; `Condition_Cleanup::compute_off_condition()`.
- Produces: `Condition_Cleanup::preview(): array` returning
  `array{ off_condition: string[], had_error: bool, seen_count: int, stored_count: int }`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/integration/ConditionCleanupPreviewTest.php`. This runs in the WP test env so `Settings`, options, and the post store are real. It seeds trials, configures conditions, and filters the HTTP call so no real network request is made.

```php
<?php
/**
 * Integration test for Condition_Cleanup::preview().
 *
 * Uses the pre_http_request filter to stub the ClinicalTrials.gov response so
 * preview() runs end-to-end without a network call.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Sync\Condition_Cleanup;
use SKMCTF\Sync\Ctgov_Client;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;

final class ConditionCleanupPreviewTest extends WP_UnitTestCase {

	private function seed_trial( string $nct ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
		update_post_meta( $id, Trial_Meta::KEYS['nct_id'], $nct );
		return $id;
	}

	/**
	 * Stub the CT.gov HTTP response to return exactly the given NCT studies.
	 *
	 * @param string[] $ncts NCT IDs the fake feed should return.
	 */
	private function stub_feed( array $ncts ): void {
		$studies = array();
		foreach ( $ncts as $nct ) {
			$studies[] = array(
				'protocolSection' => array(
					'identificationModule' => array( 'nctId' => $nct ),
				),
			);
		}
		$body = wp_json_encode(
			array(
				'studies'        => $studies,
				'totalCount'     => count( $studies ),
				'nextPageToken'  => null,
			)
		);
		add_filter(
			'pre_http_request',
			static function () use ( $body ) {
				return array(
					'body'     => $body,
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	public function test_preview_returns_stored_minus_seen_and_includes(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION, array() ),
				array(
					'conditions'   => array( 'HHT' ),
					'statuses'     => array( 'RECRUITING' ),
					'include_ncts' => array( 'NCT00000003' ),
				)
			)
		);

		$this->seed_trial( 'NCT00000001' ); // off-condition (not seen, not included)
		$this->seed_trial( 'NCT00000002' ); // seen
		$this->seed_trial( 'NCT00000003' ); // included (exempt)

		// Feed returns only NCT00000002 for the HHT condition.
		$this->stub_feed( array( 'NCT00000002' ) );

		$clean   = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$preview = $clean->preview();

		$this->assertFalse( $preview['had_error'] );
		$this->assertSame( 3, $preview['stored_count'] );
		$this->assertSame( array( 'NCT00000001' ), $preview['off_condition'] );
	}

	public function test_preview_flags_had_error_on_fetch_failure(): void {
		update_option(
			Settings::OPTION,
			array_merge(
				(array) get_option( Settings::OPTION, array() ),
				array(
					'conditions'   => array( 'HHT' ),
					'statuses'     => array( 'RECRUITING' ),
					'include_ncts' => array(),
				)
			)
		);
		$this->seed_trial( 'NCT00000001' );

		// Force the HTTP layer to error.
		add_filter(
			'pre_http_request',
			static function () {
				return new \WP_Error( 'http_request_failed', 'boom' );
			},
			10,
			3
		);

		$clean   = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$preview = $clean->preview();

		$this->assertTrue( $preview['had_error'] );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `export WP_TESTS_DIR=/tmp/wordpress-tests-lib && composer test:integration -- --filter ConditionCleanupPreviewTest`
Expected: FAIL — `preview()` not defined (and/or assertion failures).

- [ ] **Step 3: Implement `preview()`**

`Field_Mapper` is in the same namespace as `Condition_Cleanup` (`SKMCTF\Sync`), so no `use` import is needed — reference it directly as `Field_Mapper::map()`. Add this method to the class (after `delete()`):

```php
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
		$conditions = Settings::conditions();
		$statuses   = Settings::statuses();
		$includes   = Settings::include_ncts();

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
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `export WP_TESTS_DIR=/tmp/wordpress-tests-lib && composer test:integration -- --filter ConditionCleanupPreviewTest`
Expected: PASS — `OK (2 tests, ...)`.

- [ ] **Step 5: Run full suites + PHPCS**

Run: `composer test:unit && export WP_TESTS_DIR=/tmp/wordpress-tests-lib && composer test:integration && composer phpcs -- includes/sync/class-condition-cleanup.php tests/integration/ConditionCleanupPreviewTest.php`
Expected: unit PASS; integration PASS; PHPCS clean.

- [ ] **Step 6: Commit**

```bash
git add includes/sync/class-condition-cleanup.php tests/integration/ConditionCleanupPreviewTest.php
git commit -m "feat: Condition_Cleanup::preview live re-fetch and off-condition computation"
```

---

### Task 4: `Cleanup_Controller` — two admin-post actions (preview + confirm)

**Files:**
- Create: `includes/admin/class-cleanup-controller.php`
- Modify: `includes/class-plugin.php:62` (register the controller next to `Sync_Now_Controller`)
- Test: `tests/integration/CleanupControllerTest.php` (new)

**Interfaces:**
- Consumes: `Condition_Cleanup::preview()`, `Condition_Cleanup::delete( array ): int`, `Ctgov_Client`, `Trial_Repository`, `Logger`, `Settings_Page::MENU_SLUG`.
- Produces:
  - `Cleanup_Controller::ACTION_PREVIEW = 'skmctf_cleanup_preview'`
  - `Cleanup_Controller::ACTION_CONFIRM = 'skmctf_cleanup_confirm'`
  - `Cleanup_Controller::TRANSIENT = 'skmctf_cleanup_preview'`
  - `Cleanup_Controller::register(): void`, `handle_preview(): void`, `handle_confirm(): void`.

- [ ] **Step 1: Write the failing integration test**

Create `tests/integration/CleanupControllerTest.php`. Because the handlers call `wp_safe_redirect`/`exit`, the test exercises the transient lifecycle directly via the controller's public methods using a guard that converts `exit` into a catchable state is overkill; instead test the two pieces that carry logic and are safe to call: the transient is set by a preview run and consumed by a confirm run. We test by invoking `Condition_Cleanup` + the controller's transient constants directly (the redirect/exit is thin WP glue covered manually).

```php
<?php
/**
 * Integration test for the cleanup transient snapshot lifecycle.
 *
 * Verifies that a previewed off-condition snapshot, when stored under the
 * controller's transient key, is the exact set deleted on confirm.
 *
 * @package SKMCTF\Tests\Integration
 */

namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Admin\Cleanup_Controller;
use SKMCTF\Sync\Condition_Cleanup;
use SKMCTF\Sync\Ctgov_Client;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;
use SKMCTF\Post_Types\Trial_Meta;

final class CleanupControllerTest extends WP_UnitTestCase {

	public function test_confirm_deletes_exactly_the_snapshot(): void {
		$id1 = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
		update_post_meta( $id1, Trial_Meta::KEYS['nct_id'], 'NCT00000001' );
		$id2 = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
		update_post_meta( $id2, Trial_Meta::KEYS['nct_id'], 'NCT00000002' );

		// Simulate a stored preview snapshot containing only NCT00000001.
		set_transient( Cleanup_Controller::TRANSIENT, array( 'NCT00000001' ), 15 * MINUTE_IN_SECONDS );

		// Confirm path logic: read snapshot, delete it.
		$snapshot = get_transient( Cleanup_Controller::TRANSIENT );
		$clean    = new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
		$deleted  = $clean->delete( (array) $snapshot );

		$this->assertSame( 1, $deleted );
		$this->assertNull( get_post( $id1 ) );      // deleted
		$this->assertNotNull( get_post( $id2 ) );   // untouched (not in snapshot)
	}

	public function test_constants_are_distinct(): void {
		$this->assertNotSame( Cleanup_Controller::ACTION_PREVIEW, Cleanup_Controller::ACTION_CONFIRM );
		$this->assertSame( 'skmctf_cleanup_preview', Cleanup_Controller::TRANSIENT );
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `export WP_TESTS_DIR=/tmp/wordpress-tests-lib && composer test:integration -- --filter CleanupControllerTest`
Expected: FAIL — class `Cleanup_Controller` not found.

- [ ] **Step 3: Implement the controller**

Create `includes/admin/class-cleanup-controller.php`:

```php
<?php
/**
 * Cleanup Controller — handles the two-step off-condition cleanup admin action.
 *
 * Step 1 (preview): live re-fetch of configured conditions, compute the
 * off-condition NCT set, store it in a short-lived transient, redirect back.
 * Step 2 (confirm): delete exactly the previewed snapshot, clear the transient.
 *
 * Security: manage_options + nonce on both handlers, before any work. The
 * automatic sync and its no-wipe guards are not involved.
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Admin;

use SKMCTF\Sync\Condition_Cleanup;
use SKMCTF\Sync\Ctgov_Client;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;

/**
 * Handles the preview and confirm admin-post actions for off-condition cleanup.
 */
final class Cleanup_Controller {

	/** Preview admin-post action name. */
	public const ACTION_PREVIEW = 'skmctf_cleanup_preview';

	/** Confirm admin-post action name. */
	public const ACTION_CONFIRM = 'skmctf_cleanup_confirm';

	/** Transient key holding the previewed off-condition NCT snapshot. */
	public const TRANSIENT = 'skmctf_cleanup_preview';

	/**
	 * Register both admin-post handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION_PREVIEW, array( $this, 'handle_preview' ) );
		add_action( 'admin_post_' . self::ACTION_CONFIRM, array( $this, 'handle_confirm' ) );
	}

	/**
	 * Build the cleanup service with production dependencies.
	 *
	 * @return Condition_Cleanup
	 */
	private function service(): Condition_Cleanup {
		return new Condition_Cleanup( new Ctgov_Client(), new Trial_Repository(), new Logger() );
	}

	/**
	 * Guard: capability + nonce, or die.
	 *
	 * @param string $action The action name to verify the nonce against.
	 * @return void
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to do this.', 'kisho-clinical-trials' ),
				esc_html__( 'Forbidden', 'kisho-clinical-trials' ),
				array( 'response' => 403 )
			);
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the settings page with a cleanup status arg.
	 *
	 * @param array<string,string|int> $args Extra query args.
	 * @return void
	 */
	private function redirect( array $args ): void {
		$redirect = add_query_arg(
			array_merge( array( 'page' => Settings_Page::MENU_SLUG ), $args ),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the preview request: compute and snapshot the off-condition set.
	 *
	 * @return void
	 */
	public function handle_preview(): void {
		$this->guard( self::ACTION_PREVIEW );

		$preview = $this->service()->preview();

		if ( $preview['had_error'] ) {
			delete_transient( self::TRANSIENT );
			$this->redirect( array( 'skmctf_cleanup' => 'err' ) );
		}

		set_transient( self::TRANSIENT, $preview['off_condition'], 15 * MINUTE_IN_SECONDS );
		$this->redirect(
			array(
				'skmctf_cleanup' => 'preview',
				'n'              => count( $preview['off_condition'] ),
			)
		);
	}

	/**
	 * Handle the confirm request: delete exactly the previewed snapshot.
	 *
	 * @return void
	 */
	public function handle_confirm(): void {
		$this->guard( self::ACTION_CONFIRM );

		$snapshot = get_transient( self::TRANSIENT );
		if ( ! is_array( $snapshot ) ) {
			$this->redirect( array( 'skmctf_cleanup' => 'expired' ) );
		}

		$deleted = $this->service()->delete( $snapshot );
		delete_transient( self::TRANSIENT );

		$this->redirect(
			array(
				'skmctf_cleanup' => 'done',
				'n'              => (int) $deleted,
			)
		);
	}
}
```

- [ ] **Step 4: Register the controller in the plugin bootstrap**

In `includes/class-plugin.php`, immediately after the `Sync_Now_Controller` registration (line 62), add:

```php
			( new \SKMCTF\Admin\Cleanup_Controller() )->register();
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `export WP_TESTS_DIR=/tmp/wordpress-tests-lib && composer test:integration -- --filter CleanupControllerTest`
Expected: PASS — `OK (2 tests, ...)`.

- [ ] **Step 6: Run full suites + PHPCS**

Run: `composer test:unit && export WP_TESTS_DIR=/tmp/wordpress-tests-lib && composer test:integration && composer phpcs -- includes/admin/class-cleanup-controller.php includes/class-plugin.php tests/integration/CleanupControllerTest.php`
Expected: unit PASS; integration PASS; PHPCS clean.

- [ ] **Step 7: Commit**

```bash
git add includes/admin/class-cleanup-controller.php includes/class-plugin.php tests/integration/CleanupControllerTest.php
git commit -m "feat: Cleanup_Controller preview/confirm admin-post actions"
```

---

### Task 5: Settings-page Maintenance section + notices

**Files:**
- Modify: `includes/admin/views/settings-page.php` (add Maintenance section + result notices)

**Interfaces:**
- Consumes: `Cleanup_Controller::ACTION_PREVIEW`, `ACTION_CONFIRM`, `TRANSIENT`; `Settings::conditions()`; the `?skmctf_cleanup=` query arg set by the controller.
- Produces: no new code interface — UI only.

- [ ] **Step 1: Add the `use` import for the controller at the top of the view**

In `includes/admin/views/settings-page.php`, near the other `use` statements (find `use SKMCTF\Admin\Sync_Now_Controller;` or similar), add:

```php
use SKMCTF\Admin\Cleanup_Controller;
```

If the view references classes by FQN instead of `use`, follow that convention and reference `\SKMCTF\Admin\Cleanup_Controller::` inline instead.

- [ ] **Step 2: Add the result notices near the top of the page render**

Find where the existing `?skmctf_synced` notice is rendered (search for `skmctf_synced`). Immediately after that block, add the cleanup notices:

```php
		<?php
		$skmctf_cleanup = isset( $_GET['skmctf_cleanup'] ) ? sanitize_key( wp_unslash( $_GET['skmctf_cleanup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skmctf_clean_n = isset( $_GET['n'] ) ? absint( wp_unslash( $_GET['n'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'done' === $skmctf_cleanup ) :
			?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				printf(
					/* translators: %d: number of trials deleted */
					esc_html( _n( 'Deleted %d off-condition trial.', 'Deleted %d off-condition trials.', $skmctf_clean_n, 'kisho-clinical-trials' ) ),
					(int) $skmctf_clean_n
				);
				?>
			</p></div>
		<?php elseif ( 'err' === $skmctf_cleanup ) : ?>
			<div class="notice notice-error is-dismissible"><p>
				<?php esc_html_e( 'Cleanup preview failed: a ClinicalTrials.gov fetch error occurred. Nothing was deleted. Please try again.', 'kisho-clinical-trials' ); ?>
			</p></div>
		<?php elseif ( 'expired' === $skmctf_cleanup ) : ?>
			<div class="notice notice-warning is-dismissible"><p>
				<?php esc_html_e( 'The cleanup preview expired. Please run the preview again before deleting.', 'kisho-clinical-trials' ); ?>
			</p></div>
		<?php endif; ?>
```

- [ ] **Step 3: Add the Maintenance section in the sidebar (after the Sync Status postbox)**

Find the closing `</form>` of the Sync-now form (around line 474-476) and its enclosing `.postbox` div. Immediately after that postbox `</div>`, add a new Maintenance postbox:

```php
				<!-- ── Maintenance ─────────────────────────────────────────── -->
				<div class="postbox" style="padding:12px 16px;margin-bottom:16px;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Maintenance', 'kisho-clinical-trials' ); ?></h3>

					<p style="margin:0 0 8px;">
						<?php esc_html_e( 'Remove trials that no longer match your current conditions. This re-checks ClinicalTrials.gov, shows you what would be removed, and only deletes after you confirm.', 'kisho-clinical-trials' ); ?>
					</p>

					<?php $skmctf_has_conditions = ! empty( Settings::conditions() ); ?>

					<?php if ( ! $skmctf_has_conditions ) : ?>
						<p style="color:#646970;margin:0;">
							<?php esc_html_e( 'Add at least one condition to use cleanup.', 'kisho-clinical-trials' ); ?>
						</p>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Cleanup_Controller::ACTION_PREVIEW ); ?>">
							<?php wp_nonce_field( Cleanup_Controller::ACTION_PREVIEW ); ?>
							<?php submit_button( __( 'Preview cleanup', 'kisho-clinical-trials' ), 'secondary', 'skmctf_cleanup_preview_btn', false ); ?>
						</form>

						<?php
						$skmctf_snapshot = get_transient( Cleanup_Controller::TRANSIENT );
						if ( 'preview' === $skmctf_cleanup && is_array( $skmctf_snapshot ) ) :
							$skmctf_count = count( $skmctf_snapshot );
							?>
							<?php if ( 0 === $skmctf_count ) : ?>
								<p style="margin:8px 0 0;"><?php esc_html_e( 'No off-condition trials found.', 'kisho-clinical-trials' ); ?></p>
							<?php else : ?>
								<p style="margin:8px 0 4px;"><strong>
									<?php
									printf(
										/* translators: %d: number of trials that would be deleted */
										esc_html( _n( '%d trial would be deleted:', '%d trials would be deleted:', $skmctf_count, 'kisho-clinical-trials' ) ),
										(int) $skmctf_count
									);
									?>
								</strong></p>
								<ul style="margin:0 0 8px;max-height:160px;overflow:auto;font-size:12px;">
									<?php foreach ( array_slice( $skmctf_snapshot, 0, 20 ) as $skmctf_nct ) : ?>
										<li><?php echo esc_html( $skmctf_nct ); ?></li>
									<?php endforeach; ?>
									<?php if ( $skmctf_count > 20 ) : ?>
										<li>
											<?php
											printf(
												/* translators: %d: number of additional trials not listed */
												esc_html__( '…and %d more', 'kisho-clinical-trials' ),
												(int) ( $skmctf_count - 20 )
											);
											?>
										</li>
									<?php endif; ?>
								</ul>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
									onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete these trials? This cannot be undone.', 'kisho-clinical-trials' ) ); ?>');">
									<input type="hidden" name="action" value="<?php echo esc_attr( Cleanup_Controller::ACTION_CONFIRM ); ?>">
									<?php wp_nonce_field( Cleanup_Controller::ACTION_CONFIRM ); ?>
									<?php
									submit_button(
										sprintf(
											/* translators: %d: number of trials to delete */
											_n( 'Delete %d trial', 'Delete %d trials', $skmctf_count, 'kisho-clinical-trials' ),
											(int) $skmctf_count
										),
										'delete',
										'skmctf_cleanup_confirm_btn',
										false
									);
									?>
								</form>
							<?php endif; ?>
						<?php endif; ?>
					<?php endif; ?>
				</div>
```

- [ ] **Step 4: Manually verify the section renders (no automated UI test)**

In LocalWP, restart the site (clear OPcache), open Settings → Clinical Trials Feed. Confirm:
- A "Maintenance" postbox appears with a "Preview cleanup" button.
- Click "Preview cleanup": the page reloads and shows either a count + list + "Delete N trials" button, or "No off-condition trials found."
- The "Delete N trials" button shows a JS confirm dialog before submitting.

- [ ] **Step 5: PHPCS the view**

Run: `composer phpcs -- includes/admin/views/settings-page.php`
Expected: clean (no output). If PHPCS flags the inline `$_GET` reads, confirm each carries the `phpcs:ignore WordPress.Security.NonceVerification.Recommended` comment shown above (these are display-only reads of a redirect flag, not form processing).

- [ ] **Step 6: Commit**

```bash
git add includes/admin/views/settings-page.php
git commit -m "feat: settings-page Maintenance section for off-condition cleanup"
```

---

### Task 6: Documentation — readme changelog + version bump

**Files:**
- Modify: `kisho-clinical-trials.php:6` (Version header) and `:21` (`SKMCTF_VERSION`)
- Modify: `readme.txt` (Stable tag, Changelog, Upgrade Notice, Description feature note)

**Interfaces:** none (docs only).

- [ ] **Step 1: Bump the version to 1.2.0 in the plugin file**

This adds a user-facing feature, so it is a minor bump. In `kisho-clinical-trials.php`:
- Line 6: change ` * Version:           1.1.3` to ` * Version:           1.2.0`
- Line 21: change `define( 'SKMCTF_VERSION', '1.1.3' );` to `define( 'SKMCTF_VERSION', '1.2.0' );`

- [ ] **Step 2: Update the Stable tag in readme.txt**

Change `Stable tag: 1.1.3` to `Stable tag: 1.2.0`.

- [ ] **Step 3: Add the changelog entry**

In `readme.txt`, directly under `== Changelog ==`, add above the `= 1.1.3 =` entry:

```
= 1.2.0 =
* New: "Clean up off-condition trials" maintenance action (Settings → Clinical Trials Feed). When you change your configured conditions, use it to remove trials that no longer match. It re-checks ClinicalTrials.gov, shows exactly what would be deleted, and only removes them after you confirm. Trials in the "Always include" list are never removed. The automatic daily sync is unchanged and still never bulk-deletes on a feed error.
```

- [ ] **Step 4: Add the upgrade notice**

In `readme.txt`, directly under `== Upgrade Notice ==`, add above the `= 1.1.3 =` entry:

```
= 1.2.0 =
New: an explicit, preview-and-confirm "Clean up off-condition trials" action to remove trials left over after a condition change. Find it in Settings → Clinical Trials Feed under Maintenance.
```

- [ ] **Step 5: Verify version consistency**

Run: `grep -rn "1\.2\.0" kisho-clinical-trials.php readme.txt`
Expected: matches in the Version header, `SKMCTF_VERSION`, Stable tag, the changelog heading, and the upgrade-notice heading.

- [ ] **Step 6: Commit**

```bash
git add kisho-clinical-trials.php readme.txt
git commit -m "docs: changelog + version 1.2.0 for off-condition cleanup"
```

---

## Self-Review

**1. Spec coverage:**
- Explicit admin action + preview + confirm → Tasks 4 & 5. ✓
- Live re-fetch defines off-condition → Task 3 (`preview()`). ✓
- Off-condition formula `all_stored − (seen ∪ includes)` → Task 2 (`compute_off_condition`), verified in Task 3. ✓
- Snapshot-in-transient; confirm deletes the snapshot → Task 4 (`handle_preview`/`handle_confirm`), Task 4 test `test_confirm_deletes_exactly_the_snapshot`. ✓
- Include list exempt → Task 2 test `test_includes_are_exempt_even_when_not_seen`, Task 3 test. ✓
- Fetch error suppresses confirm (no snapshot) → Task 4 `handle_preview` (`had_error` branch), Task 3 `test_preview_flags_had_error_on_fetch_failure`. ✓
- Transient expiry handled → Task 4 `handle_confirm` (`expired` branch). ✓
- No-conditions case disables preview → Task 5 (`$skmctf_has_conditions`). ✓
- Hard delete reusing existing path → Task 1 (`delete_by_ncts` delegates to `delete_by_nct`). ✓
- Automatic sync untouched → no task modifies `Sync_Engine`/`Reconciler`. ✓
- Capability + nonce on handlers → Task 4 `guard()`. ✓

**2. Placeholder scan:** No TBD/TODO; every code step shows complete code; no "similar to Task N". The two "verify the namespace/FQN convention" notes in Tasks 3 & 5 are conditional instructions with a concrete default action, not placeholders. ✓

**3. Type consistency:**
- `delete_by_ncts( array ): int` — identical in Repo_Interface (T1), Trial_Repository (T1), FakeRepo (T1), Condition_Cleanup::delete delegation (T2). ✓
- `compute_off_condition( array, array, array ): array` — defined T2, called T3. ✓
- `preview(): array{off_condition,had_error,seen_count,stored_count}` — defined T3, consumed T4. ✓
- Controller constants `ACTION_PREVIEW`/`ACTION_CONFIRM`/`TRANSIENT` — defined T4, consumed T4 test & T5 view. ✓
- `fetch_all_for_condition` return `array{studies,error}` — matches the real client signature. ✓

No gaps found.
