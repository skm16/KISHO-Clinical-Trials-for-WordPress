# Single Clinical Trial Page — Design

Date: 2026-06-22
Status: Approved (pending spec review)
Plugin: Clinical Trials Feed (`kisho-clinical-trials`)

## Problem & Goal

The single clinical-trial page (e.g. `/clinical-trials/nct00231400/`) currently renders
almost nothing — only the post title and a "Data from ClinicalTrials.gov" credit — even
though the trial has rich data (status, conditions, sponsor, 270+ locations, a generated
plain-language summary).

**Root cause:** `templates/single-skmctf_trial.php` is loaded directly by WordPress via the
`single_template` filter (`Template_Router::single_template()`), but its markup depends on
variables (`$official_title`, `$phase`, `$conditions`, `$plain_summary`, `$eligibility`,
`$locations`, etc.) that are only defined by `Single_Renderer::render()` via `extract()`.
**`Single_Renderer::render()` is never called anywhere** — it is dead code. On the actual
render path those variables are undefined, so every guarded section is skipped and only the
unconditional title + data-credit footer appear.

**Goal:** (1) Fix the wiring so the rich detail page renders, (2) structure it for the
primary audience — **patients and caregivers** — with progressive disclosure, and (3) add a
small set of patient-friendly, LLM-generated fields using the plugin's existing,
proven sync-time generation pattern.

## Audience & Principles

- **Primary audience:** patients and caregivers (non-experts). Optimize for plain language,
  an at-a-glance "could this apply to me/my loved one?", nearby locations, and a clear next
  step. Full clinical detail remains available below (progressive disclosure).
- **Safety posture (inherited from the existing summary feature):** factual and extractive
  only; only use information provided; no medical advice; no speculation; ~8th-grade reading
  level; a medical disclaimer is always present. New LLM fields must *restructure existing
  data into a friendlier form*, never *add medical interpretation*.
- **No per-pageview LLM cost:** all LLM content is generated at sync time and cached as post
  meta, exactly like the current `plain_summary`.
- **Graceful degradation:** with the LLM disabled or on error, the page renders cleanly from
  raw data; each section self-guards and renders nothing when its data is absent.

## Section 1 — Fix the wiring

Make the template self-sufficient so the `single_template` filter path works.

- In `templates/single-skmctf_trial.php`, after `the_post()`, populate the template's
  display variables from the current post via `Single_Renderer` (reusing its existing
  `get_meta()` + derived-value logic) instead of relying on an external caller's `extract()`.
- This makes `Single_Renderer` live code and keeps the existing template markup and its
  per-section `if ( $x )` guards intact.

**Rejected alternative:** hooking `Single_Renderer::render()` into the `the_content` filter —
it fights themes that render their own title/loop and risks double output. The
template-self-populates approach is the smallest change consistent with how the file is
already written (it already calls `get_header()` and runs its own loop).

## Section 2 — Page structure (patient/caregiver-first)

Progressive disclosure, top to bottom:

1. **Header** — title, official title (if different), status badge. *(existing)*
2. **What this study is testing** — one-line LLM hook under the header. *(new — §3)*
3. **At-a-glance** — status · phase · conditions · sponsor as a compact, scannable row
   (restructures the existing stacked paragraphs). *(restructure of existing fields)*
4. **Plain-Language Summary** — existing generated summary, prominent, with its disclaimer.
   *(existing)*
5. **Who can join** — new plain-language eligibility list *(new — §3)*, followed by the
   full eligibility detail (sex / ages / inclusion-exclusion criteria) as rendered today.
   *(existing `parts/eligibility.php`)*
6. **Locations** (+ map) — always show the map (all sites); render the text list collapsed
   to the first ~10 with a "Show all N locations" toggle. *(light enhancement of existing
   `parts/locations.php`)*
7. **Questions to ask your doctor** — new prompt list. *(new — §3)*
8. **Next step** — existing "View full record on ClinicalTrials.gov" link + data credit.
   *(existing)*

**Component boundaries:** each section is a separate template part under `templates/parts/`,
matching the current pattern. New parts: `parts/study-purpose.php`, `parts/who-can-join.php`,
`parts/questions.php`. Each self-guards (renders nothing when its meta is empty) so files
stay small and independently testable.

### Locations behavior (large trials)

- Map shows all valid coordinates (existing behavior).
- Text list is collapsed to ~10 entries with a "Show all N locations" toggle (progressive
  disclosure for 270-site registries). Toggle is CSS/JS-light and degrades to showing the
  full list when JS is unavailable.

## Section 3 — New sync-time LLM fields

Three new fields, all following the existing `Summary_Service` pattern (generate at sync,
store as meta, change-detect via `ct_last_update`, disclaimer/safety-bound, skip on
disabled/error):

| Field | Meta key | Content |
|-------|----------|---------|
| What this study is testing | `study_purpose` | One plain sentence on the study's purpose. |
| Who can join | `who_can_join` | Short plain-language list distilled from eligibility criteria. |
| Questions to ask your doctor | `doctor_questions` | 3–4 generic, non-advisory prompts about this trial. |

- Each new meta key is added to `Trial_Meta::KEYS`, each with a matching `*_source_date`
  key for cache invalidation, mirroring `plain_summary` / `plain_summary_source_date`.
- **One combined LLM call per trial** returns a small structured block (purpose /
  who-can-join / questions); the response is parsed and each value stored to its meta. This
  keeps cost and latency close to today's single summary call rather than tripling calls.
- Change-detection means generation runs only when a trial actually changes, identical to
  the current summary cache logic.

**Safety specifics:**
- System prompt retains current rules (8th-grade, only-provided-info, no advice, no
  speculation).
- "Who can join" is explicitly framed as *a simplification of the official criteria* with a
  "confirm with the study team" line.
- "Questions to ask your doctor" are generic prompts, never advice.
- Each rendered new section carries a short disclaimer; the page-level medical disclaimer
  remains.

**Settings:** a single toggle gates all LLM enhancements (reuse/extend the existing
summaries-enabled setting). A site without an API key automatically gets the clean,
non-LLM page.

## Section 4 — SEO, data flow, testing

### SEO / noindex
Unchanged logic in `class-seo.php`: a single page is `noindex` unless it has a
`plain_summary` (or the admin override is on). The new fields are generated alongside the
summary, so an LLM-enabled trial gets them together. The wiring fix means indexable pages
finally carry real content instead of a near-empty page; thin/no-summary pages stay
noindexed.

### Data flow
- **Sync time:** `Sync_Engine → Field_Mapper → Trial_Repository::upsert()` (unchanged) →
  `Summary_Service::maybe_generate()` extended to also produce `study_purpose`,
  `who_can_join`, `doctor_questions` in one combined call, each cached as meta with a
  `*_source_date`.
- **Page load:** `single_template` filter → `single-skmctf_trial.php` self-populates via
  `Single_Renderer` → renders guarded sections; new parts render only when their meta
  exists. Zero LLM calls at render.

### Backfill
Existing trials gain the new fields on their next sync. As with the country/state work, a
one-time **"Sync now"** populates them immediately. No dedicated backfill method is needed —
the summary regeneration path already covers it. Note this in the readme upgrade notes.

### Testing
- **Unit (Brain Monkey):** the prompt builder for the combined fields, and the parser that
  splits the combined LLM response into the three meta values (including a malformed-response
  fallback that stores nothing rather than garbage).
- **Integration (LocalWP):** `Summary_Service` writes all fields + source dates;
  change-detection skips when `ct_last_update` is unchanged.
- **Manual / browser:** the single page renders all sections for a rich trial (e.g.
  NCT00231400) and degrades cleanly when the LLM is off or fields are empty.

## Out of scope
- No redesign of the archive/list view.
- No new LLM calls at page-render time.
- No change to the noindex policy.
- No per-field settings toggles (single gate reused).

## Files (anticipated)
- `templates/single-skmctf_trial.php` — self-populate via `Single_Renderer`; insert new
  sections in the §2 order.
- `templates/parts/study-purpose.php`, `parts/who-can-join.php`, `parts/questions.php` — new,
  self-guarding parts.
- `templates/parts/locations.php` — collapse-to-N + "show all" toggle.
- `includes/frontend/class-single-renderer.php` — becomes the template's data source (live).
- `includes/post-types/class-trial-meta.php` — new meta keys + `*_source_date` + sanitizers.
- `includes/llm/class-prompt-builder.php` — combined-fields prompt + response parser.
- `includes/llm/class-summary-service.php` — generate/cache the new fields in one pass.
- `readme.txt` — changelog + "Sync now to populate new fields" upgrade note.
- Tests: unit for prompt/parser; integration for service caching.
