# Off-Condition Trial Cleanup — Design

Date: 2026-06-22
Status: Approved (pending spec review)
Plugin: Clinical Trials Feed (`kisho-clinical-trials`)

## Problem & Goal

When an admin changes the "Conditions to search" setting, trials imported under
the *previous* condition are not removed. The automatic sync's `Reconciler`
*would* close or delete trials that drop from the feed, but its **50% drop-ratio
guard** (`Reconciler::MAX_DROP_RATIO = 0.5`) intentionally aborts when more than
half of stored trials would be dropped — which is exactly what a condition change
looks like. The guard cannot distinguish a deliberate condition switch from a
ClinicalTrials.gov outage, so it suppresses the cleanup and the old trials
linger.

Worse, the default `reconcile_mode` is `mark_closed`, which only sets the
`overall_status` meta + status term to `CLOSED` while leaving the post
`publish`ed. The front-end query filters by `post_status = 'publish'`, **not** by
`overall_status`, so even if the guard did not block it, an off-condition trial
would remain visible (badged "Closed") rather than removed — the wrong semantic
for "no longer in scope."

**Goal:** Give admins an explicit, safe, preview-gated way to permanently delete
trials that no longer match any currently configured condition, without weakening
the automatic sync's no-wipe guarantees.

## Decisions (from brainstorming)

- **Outcome:** off-condition trials are **deleted** (hard delete), so the catalog
  only ever holds trials relevant to the current condition(s).
- **Trigger:** an **explicit admin action** with preview + confirmation. The
  automatic sync **never** deletes off-condition trials; this is the only path.
- **Match logic:** **live re-fetch** of the currently configured conditions via
  the existing `Ctgov_Client` — a stored trial is off-condition if it is not in
  the fresh result set. "Matches the condition" therefore means exactly what it
  means in the normal sync (no fragile text matching against stored meta).
- **Preview first, always:** a two-step flow. The reviewed list is frozen in a
  transient and the confirm step deletes *exactly that snapshot* — never a
  recomputed set.
- **Include list is exempt:** trials in the "Always include" NCT list are never
  off-condition and never deleted, consistent with how the sync protects them.

## Architecture

A new **"Clean up off-condition trials"** maintenance section on the existing
settings page, implemented as a two-step, transient-backed admin action. The
automatic sync (`Sync_Engine`, `Reconciler`) is **untouched**.

```
Step 1 — Preview (admin_post, nonce + manage_options)
  Condition_Cleanup::preview()
    for each Settings::conditions(): Ctgov_Client::fetch_all_for_condition()
    seen      = ∪ fetched NCTs
    protected = seen ∪ Settings::include_ncts()
    off       = Trial_Repository::all_nct_ids() − protected
  set_transient('skmctf_cleanup_preview', off, 15 min)   [only if no fetch error]
  redirect ?skmctf_cleanup=preview
  → page shows count + sample titles + [Delete N trials]   (or fetch-error warning)

Step 2 — Confirm (admin_post, nonce + manage_options)
  ncts = get_transient('skmctf_cleanup_preview')
  Condition_Cleanup::delete(ncts) → Trial_Repository::delete_by_ncts(ncts)
  delete_transient
  redirect ?skmctf_cleanup=done&n=N  → "Deleted N trials."
```

The off-condition formula is `all_stored − (seen ∪ includes)`.

**Why snapshot-in-transient:** "preview then confirm" only protects the admin if
the confirm operates on the *reviewed* data. Re-fetching and recomputing at
confirm time could yield a different set (CT.gov returns differently between the
two clicks), so the admin would delete something never seen. Freezing the
reviewed NCT list and deleting exactly that makes the human approval apply to a
concrete list, and renders the "conditions changed between clicks" case harmless.

## Components

### 1. `Trial_Repository::delete_by_ncts( array $ncts ): int` (new method)
Bulk-deletes trials by NCT, returning the count actually deleted. Delegates to
the existing per-trial hard delete (`delete_by_nct()` → `wp_delete_post($id,
true)`). Unknown/absent NCTs are skipped and not counted.
- Consumes: `string[]` NCT IDs. Produces: `int` deleted count.

### 2. `Condition_Cleanup` (new, `includes/sync/class-condition-cleanup.php`)
The cleanup logic, dependency-injected and WP-light for unit testing (mirrors
`Reconciler`).
```php
public function preview(): array
  // returns array{ off_condition: string[], had_error: bool,
  //                 seen_count: int, stored_count: int }
  // - fetches each Settings::conditions() via Ctgov_Client::fetch_all_for_condition()
  // - had_error = true if ANY condition fetch returns a non-null error
  // - seen      = unique fetched NCTs (canonical uppercase)
  // - off_condition = Trial_Repository::all_nct_ids() − (seen ∪ Settings::include_ncts())
  //   (include NCTs normalised with strtoupper/trim to match canonical storage)

public function delete( array $ncts ): int
  // delegates to Trial_Repository::delete_by_ncts(); returns deleted count.
```
Constructor takes `Ctgov_Client`, `Trial_Repository`, and a `Logger_Interface`,
matching the `Reconciler`/`Sync_Engine` injection style. It does **not** touch
transients, nonces, or redirects — that is the controller's responsibility.

### 3. `Cleanup_Controller` (new, `includes/admin/class-cleanup-controller.php`)
WordPress plumbing, mirroring `Sync_Now_Controller`:
- Registers two `admin_post_{action}` handlers.
- Each handler: `current_user_can('manage_options')` then
  `check_admin_referer({action})`.
- `handle_preview()` → `Condition_Cleanup::preview()`; if `had_error` is false,
  `set_transient('skmctf_cleanup_preview', off_condition, 15 * MINUTE_IN_SECONDS)`;
  `wp_safe_redirect` back with `?skmctf_cleanup=preview` (and `&err=1` when
  `had_error`).
- `handle_confirm()` → read transient; if absent, redirect
  `?skmctf_cleanup=expired`; else `Condition_Cleanup::delete()`,
  `delete_transient`, redirect `?skmctf_cleanup=done&n=N`.

### 4. Settings-page section + notices (edit existing admin views)
A "Maintenance" section in `includes/admin/views/settings-page.php` (and its
controller that registers `Cleanup_Controller`):
- "Preview cleanup" submit button (POST to `admin-post.php`, nonce field).
- When the preview transient exists: the count, a sample of trial titles/NCTs
  (cap the rendered sample, e.g. first 20), and a **"Delete N trials"** confirm
  button.
- When the last preview had a fetch error: a warning notice and **no** confirm
  button.
- Result notices driven by the `?skmctf_cleanup=` query flag
  (`preview` / `done&n=N` / `expired` / `err`).
- When no conditions are configured (include-only setup): the Preview button is
  disabled with an explanatory note (there is no condition set to define
  "off-condition").

## Edge cases

| Case | Behavior |
|------|----------|
| A condition fetch errors | Preview shows a warning; no snapshot stored; confirm button suppressed. |
| Preview computes 0 off-condition | "No off-condition trials found." No confirm button. |
| Include-list trial absent from fetch | Excluded from off-condition set; never previewed or deleted. |
| Transient expired (>15 min) between clicks | Confirm finds no snapshot → "Preview expired, please run it again." Deletes nothing. |
| No conditions configured (include-only) | Preview disabled with a note. |
| Conditions changed since preview | Harmless — confirm deletes the snapshot NCTs, not a recompute. |
| Sync running concurrently | No conflict — deletion is by explicit NCT list; a just-synced trial in the snapshot being deleted is the intended outcome. |

## Testing

- **Unit (Brain Monkey) — `Condition_Cleanup`** with a fake `Ctgov_Client` + fake
  repo:
  - computes `stored − (seen ∪ includes)` correctly;
  - include-list NCTs excluded even when absent from the fetch;
  - `had_error` true when any condition fetch returns an error;
  - empty off-condition set when all stored trials are seen.
- **Unit — `Trial_Repository::delete_by_ncts()`**: accurate deleted count;
  ignores unknown NCTs.
- **Integration (LocalWP)**: seed trials; run preview against a stubbed condition
  fetch; confirm deletion removes exactly the snapshot set and leaves
  in-condition and include-list trials intact.
- **Manual**: change condition → preview (see the old-disease count) → confirm →
  verify the list now shows only current-condition trials.

## Out of scope

- No change to the automatic sync or its no-wipe guards (`had_error`, empty-set,
  drop-ratio).
- No change to `reconcile_mode` (mark_closed / remove still governs normal
  drop handling).
- No scheduled or automatic off-condition deletion.
- No undo (deletion is a hard delete, by decision); the preview gate is the
  safety mechanism.

## Files (anticipated)

- `includes/data/class-trial-repository.php` — add `delete_by_ncts()`.
- `includes/sync/class-condition-cleanup.php` — new service.
- `includes/admin/class-cleanup-controller.php` — new admin-post controller.
- `includes/admin/views/settings-page.php` — Maintenance section + notices.
- `includes/class-plugin.php` (or the admin bootstrap) — register
  `Cleanup_Controller`.
- `readme.txt` — changelog + a note describing the new maintenance action.
- Tests: unit for `Condition_Cleanup` and `delete_by_ncts`; integration for the
  end-to-end preview → confirm flow.
