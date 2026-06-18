# Clinical Trials Feed — Implementation Design (v1)

**Plugin:** Clinical Trials Feed by SKM Digital
**Folder / .org slug:** `kisho-clinical-trials`
**Internal prefix:** `skmctf_` (functions, hooks, options, meta), `Skmctf_` / `SKMCTF\` (PHP class namespace)
**Text domain:** `kisho-clinical-trials`
**License:** GPLv2 or later
**Target:** wordpress.org plugin directory — fully compliant
**Date:** 2026-06-18
**Source of truth for product requirements:** [`docs/PROJECT-SCOPE-6-18-26.md`](../../PROJECT-SCOPE-6-18-26.md)

This document is the *implementation* design. The product scope (the "what" and "why") lives in the scope doc; this is the "how" — architecture, module boundaries, interfaces, data flow, error handling, and testing.

---

## 0. Decisions locked (deltas from scope doc)

The scope doc left several decisions flippable. These are now final for v1:

| Decision | Choice | Note |
| --- | --- | --- |
| Sync mechanism | **Action Scheduler** (bundled library) | Not native wp-cron. Guaranteed timing + self-healing recurring action. |
| Single trial pages | **Registered, `noindex`-until-summarized** | Override setting provided. |
| LLM providers | **Anthropic + OpenAI** behind one interface | Provider dropdown. |
| Map view | **Leaflet + OpenStreetMap, bundled locally, off by default** | No CDN, no Google Maps. |
| AI key storage | **BYO-key in `wp_options` (autoload off) behind `skmctf_llm_api_key` filter seam** | Future-proof for a core/global key without rewrite. |
| Slug / prefix | **`kisho-clinical-trials`** folder, **`skmctf_`** prefix | Permanent. |

---

## 1. Architecture overview

Sync-to-CPT. A scheduled background job pulls condition-relevant trials from ClinicalTrials.gov API v2, upserts them into a `skmctf_trial` custom post type, optionally generates BYO-key plain-language summaries on change, reconciles dropped trials safely, and the front end renders the locally-stored data (block + shortcode) with client-readable filters.

```
                         ┌──────────────────────────────────────────────┐
   Action Scheduler ───► │  Sync_Engine::run()                          │
   (daily recurring)     │   1. read settings                            │
   + "Sync now" button   │   2. CtGov_Client::fetch_studies() (paginated)│
                         │   3. Field_Mapper::map() per study            │
                         │   4. Trial_Repository::upsert() (keyed nctId) │
                         │   5. Summary_Service::maybe_generate()  ──────┼──► LLM (Anthropic|OpenAI)
                         │   6. Reconciler::reconcile() (no-wipe guard)  │      via Llm_Provider
                         │   7. record last_sync / last_error            │
                         └───────────────────┬──────────────────────────┘
                                             │ writes
                                             ▼
                              ┌──────────────────────────┐
                              │  CPT: skmctf_trial        │
                              │  + post meta (registered) │
                              │  + tax: trial_status,     │
                              │         trial_phase       │
                              └───────────┬───────────────┘
                                          │ reads
            ┌─────────────────────────────┼─────────────────────────────┐
            ▼                             ▼                             ▼
   Gutenberg block             [skmctf_trials] shortcode        Single trial template
   (server render_callback)    (same renderer)                  (+ conditional noindex)
            │                             │
            └──────────► Trials_Query + List_Renderer + Filters_UI ◄─────┘
                                  │
                                  ▼ (optional, off by default)
                          Leaflet map (bundled JS/CSS, local geoPoints)
```

### Why these boundaries

Each unit has one purpose and a narrow interface, so it can be unit-tested in isolation and reasoned about without reading the others:

- **`CtGov_Client`** knows HTTP + CT.gov endpoint shape. Knows nothing about WordPress posts.
- **`Field_Mapper`** is a pure transformer: CT.gov study array in → normalized meta array out. No I/O. Trivially testable.
- **`Trial_Repository`** is the only code that touches `wp_insert_post` / meta for trials. Everything else goes through it.
- **`Llm_Provider`** interface hides Anthropic-vs-OpenAI differences. `Summary_Service` depends on the interface, never a concrete provider.
- **`Reconciler`** owns the dangerous delete/close logic and the no-wipe guard, isolated so the guard is auditable in one place.

---

## 2. File / directory layout

```
kisho-clinical-trials/
├── kisho-clinical-trials.php        # Main plugin file: header, constants, autoloader bootstrap, activation/deactivation
├── uninstall.php                    # Clean removal (options, scheduled actions; posts optional per setting)
├── readme.txt                       # wordpress.org readme (stable tag, tested up to, FAQ, changelog)
├── readme.md                        # GitHub-facing mirror
├── LICENSE                          # GPLv2 text
├── composer.json                    # Dev-only: PHPCS (WordPress standard), PHPUnit. No runtime deps.
├── package.json                     # Dev-only: @wordpress/scripts for block build
├── .gitignore
├── .distignore                      # Excludes dev files from the .org build
├── languages/
│   └── kisho-clinical-trials.pot    # Generated translation template
├── includes/
│   ├── class-plugin.php             # SKMCTF\Plugin — bootstraps everything, registers hooks
│   ├── class-autoloader.php         # PSR-4-ish autoloader (no Composer runtime dep)
│   ├── post-types/
│   │   ├── class-trial-post-type.php    # registers skmctf_trial CPT
│   │   ├── class-trial-taxonomies.php   # trial_status, trial_phase
│   │   └── class-trial-meta.php         # register_post_meta + sanitize callbacks (single source of truth for meta keys)
│   ├── data/
│   │   └── class-trial-repository.php   # upsert(), get_by_nct(), all_nct_ids(), mark_closed(), delete()
│   ├── sync/
│   │   ├── class-ctgov-client.php       # CT.gov v2 HTTP client (wp_remote_get, pagination, retry)
│   │   ├── class-field-mapper.php       # pure CT.gov study -> meta transformer
│   │   ├── class-sync-engine.php        # orchestrates a sync run
│   │   ├── class-reconciler.php         # close/remove dropped trials + NO-WIPE GUARD
│   │   └── class-scheduler.php          # Action Scheduler registration + self-heal hook
│   ├── llm/
│   │   ├── interface-llm-provider.php   # Llm_Provider: generate_summary( $prompt, $opts ): string
│   │   ├── class-anthropic-provider.php
│   │   ├── class-openai-provider.php
│   │   ├── class-provider-factory.php   # name -> provider, reads filtered key
│   │   └── class-summary-service.php    # maybe_generate(): change-detection, caching, prompt, fallback
│   ├── admin/
│   │   ├── class-settings-page.php      # Settings API: register, render, sanitize
│   │   ├── class-settings.php           # typed getter facade over options (default-aware)
│   │   ├── class-sync-now-controller.php# admin-post handler: nonce + cap + trigger sync
│   │   └── class-admin-notices.php      # last-error / invalid-key notices
│   ├── frontend/
│   │   ├── class-trials-query.php       # WP_Query builder from filter args (status/phase/state)
│   │   ├── class-list-renderer.php      # renders list markup (shared by block + shortcode)
│   │   ├── class-single-renderer.php    # single trial detail markup
│   │   ├── class-shortcode.php          # [skmctf_trials]
│   │   ├── class-block.php              # registers block, server render_callback
│   │   ├── class-assets.php             # enqueue front CSS/JS + Leaflet (conditional)
│   │   └── class-seo.php                # conditional noindex on single trial pages
│   └── support/
│       ├── class-logger.php             # bounded option-based log ring (last_error, recent events)
│       └── class-template-loader.php    # theme-overridable templates
├── templates/
│   ├── archive-skmctf_trial.php
│   ├── single-skmctf_trial.php
│   ├── list-item.php
│   └── parts/ (badge, eligibility, locations, summary-disclaimer)
├── blocks/
│   └── trials/
│       ├── block.json               # apiVersion 3, render via render.php-style callback
│       ├── index.js                 # editor: InspectorControls for filters/options
│       ├── edit.js
│       ├── editor.css
│       └── style.css
├── assets/
│   ├── css/frontend.css
│   ├── js/filters.js                # progressive-enhancement filtering (works without JS)
│   ├── js/map.js                    # Leaflet init (only enqueued when map enabled)
│   └── lib/leaflet/                 # bundled Leaflet 1.9.x JS+CSS+images (GPL-compatible BSD-2)
├── vendor-lib/
│   └── action-scheduler/            # bundled Action Scheduler (GPLv3) — loaded via its functions.php
└── tests/
    ├── bootstrap.php
    ├── unit/                        # Field_Mapper, Reconciler guard, prompt builder, settings sanitize — no WP needed (Brain Monkey)
    └── integration/                 # CtGov_Client (mocked HTTP), Repository upsert, Sync_Engine (WP test suite)
```

---

## 3. Data model

### 3.1 CPT: `skmctf_trial`

```php
register_post_type( 'skmctf_trial', [
    'public'              => true,           // individual pages + SEO
    'has_archive'         => true,           // the rankable listing asset
    'show_in_rest'        => true,           // block editor + meta via REST
    'supports'            => [ 'title', 'editor', 'custom-fields' ],
    'rewrite'             => [ 'slug' => 'clinical-trials' ], // filterable: skmctf_trial_rewrite_slug
    'menu_icon'           => 'dashicons-clipboard',
    'labels'              => [ /* i18n labels */ ],
    'capability_type'     => 'post',
    // Trials are machine-managed: hide "Add New" affordance via cap map / admin tweaks,
    // but keep editable for manual corrections.
] );
```

`post_title` = `brief_title` (or `official_title` fallback). `post_name` (slug) = lowercased `nct_id` for stable, readable URLs (`/clinical-trials/nct01234567/`). `post_content` left minimal; rendering is template-driven from meta.

### 3.2 Registered meta (single source of truth: `class-trial-meta.php`)

All via `register_post_meta( 'skmctf_trial', $key, [...] )` with `'single' => true`, `'show_in_rest' => true|schema`, and an explicit `'sanitize_callback'`. Meta keys are prefixed `skmctf_`.

| Meta key | Type | Sanitize | CT.gov v2 source |
| --- | --- | --- | --- |
| `skmctf_nct_id` | string | `[strtoupper + regex ^NCT\d{8}$]` | `protocolSection.identificationModule.nctId` |
| `skmctf_official_title` | string | `sanitize_text_field` | `…identificationModule.officialTitle` |
| `skmctf_brief_title` | string | `sanitize_text_field` | `…identificationModule.briefTitle` |
| `skmctf_overall_status` | string | enum-whitelist | `…statusModule.overallStatus` |
| `skmctf_phase` | string | enum-whitelist (joined) | `…designModule.phases` |
| `skmctf_study_type` | string | enum-whitelist | `…designModule.studyType` |
| `skmctf_conditions` | array<string> | map `sanitize_text_field` | `…conditionsModule.conditions` |
| `skmctf_lead_sponsor` | string | `sanitize_text_field` | `…sponsorCollaboratorsModule.leadSponsor.name` |
| `skmctf_locations` | array<object> | per-field sanitize (see below) | `…contactsLocationsModule.locations` |
| `skmctf_eligibility` | object | per-field sanitize | `…eligibilityModule.{sex,minimumAge,maximumAge,eligibilityCriteria}` |
| `skmctf_brief_summary` | string (multiline) | `sanitize_textarea_field` | `…descriptionModule.briefSummary` |
| `skmctf_plain_summary` | string (multiline) | `wp_kses_post` (we author it) | generated |
| `skmctf_plain_summary_source_date` | string (date) | date-format check | snapshot of `ct_last_update` when summary written |
| `skmctf_ct_last_update` | string (date) | date-format check | `…statusModule.lastUpdatePostDateStruct.date` |
| `skmctf_last_synced` | int (timestamp) | `absint` | runtime |
| `skmctf_ct_url` | string (url) | `esc_url_raw` | `https://clinicaltrials.gov/study/{nctId}` |

`skmctf_locations[]` item shape: `{ facility, city, state, country, status, lat, lng }` — each scalar sanitized; lat/lng via `floatval` and range-validated.

> **Array/object meta + REST:** `show_in_rest` for array/object meta requires a `schema` under `show_in_rest['schema']`, else core rejects it. Provide schemas for `skmctf_conditions`, `skmctf_locations`, `skmctf_eligibility`.

### 3.3 Taxonomies (for archive filtering)

- `trial_status` (non-hierarchical, not public-queryable as primary nav but `show_in_rest`) — terms = human-readable status labels.
- `trial_phase` — terms = phase labels.

Taxonomies are **derived** from meta during upsert (set terms from mapped status/phase). They exist to make archive filtering and faceting cheap (`tax_query`) rather than meta queries.

---

## 4. CT.gov v2 client

Endpoint: `GET https://clinicaltrials.gov/api/v2/studies` · `format=json` · no auth.

### 4.1 Request

Per the API: build the request with the convenience params the scope specifies, requesting a **minimal field set** to keep payloads small:

- `query.cond` = configured condition (one request per condition; union results) — or `query.term` with an `AREA[ConditionSearch]` expression for include/exclude precision.
- `filter.overallStatus` = pipe/comma list of configured statuses (default includes `RECRUITING`).
- `fields` = explicit comma-list of only the modules/leaf fields mapped in §3.2 (e.g. `NCTId,BriefTitle,OfficialTitle,OverallStatus,LastUpdatePostDate,BriefSummary,Conditions,Phase,StudyType,LeadSponsorName,Sex,MinimumAge,MaximumAge,EligibilityCriteria,LocationFacility,LocationCity,LocationState,LocationCountry,LocationStatus,LocationGeoPoint`). Field names confirmed against the v2 field index at build time.
- `pageSize` = 100 (max sane), `countTotal=true` on first request (response returns `totalCount`).
- Pagination via response `nextPageToken` → next request's `pageToken` until absent.

> **⚠️ Request/response naming asymmetry (verified against the live API 2026-06-18).** The `fields` *request* parameter takes **piece names** (`NCTId`, `BriefTitle`, `OverallStatus`, `LastUpdatePostDate`, `Phase`, `StudyType`, `LeadSponsorName`, `Conditions`, `Sex`, `MinimumAge`, `MaximumAge`, `EligibilityCriteria`, `LocationFacility`, `LocationCity`, `LocationState`, `LocationCountry`, `LocationStatus`, `LocationGeoPoint`, `BriefSummary`, `OfficialTitle`). The JSON *response* nests these under module paths (`protocolSection.identificationModule.nctId`, `…statusModule.lastUpdatePostDateStruct.date`, etc.). `Field_Mapper` therefore **reads nested response paths**, never the flat piece names. Empty modules come back as `{}` (e.g. `designModule:{}` when no phase) — the mapper must default-coalesce. Confirmed live: a "Pompe disease / RECRUITING" query returned `totalCount:23` with this exact shape.

### 4.2 Interface

```php
interface-ish CtGov_Client {
    /** @return array{ studies: array[], error: ?WP_Error } */
    public function fetch_all_for_condition( string $condition, array $statuses ): array;
}
```

Internals: `wp_remote_get()` with `timeout` (15s), a descriptive `User-Agent` (`KishoClinicalTrials/1.0 (+https://skm.digital)`), one retry with backoff on transient failures, hard cap on total pages (safety). Returns a structured result; **never throws into the cron loop**.

### 4.3 Failure contract (critical)

`fetch_all_for_condition` returns `error` set on any non-200, JSON parse failure, or `is_wp_error`. The Sync_Engine treats an errored or **empty-but-errored** result as "do not reconcile" — the no-wipe guard (§6.3) keys off this.

---

## 5. Field mapper (pure)

`Field_Mapper::map( array $study ): array` — takes one raw CT.gov study object, returns the normalized `skmctf_*` meta array. Pure function: no DB, no network, no globals. This is the single most heavily unit-tested unit because it's where CT.gov's shape (deeply nested, often-missing keys) meets our flat meta.

- Defensive nested access (`$study['protocolSection']['identificationModule']['nctId'] ?? ''`).
- Phase array → display string. Status → both raw enum (meta) and label (taxonomy term).
- Locations → trimmed array of normalized objects; drop entries lacking a usable place; parse `geoPoint.lat/lon`.
- Returns `null`/throws-free; a study with no NCT id is skipped upstream.

---

## 6. Sync engine

### 6.1 Orchestration (`Sync_Engine::run( string $trigger )`)

```
1. settings = Settings::all()
2. if no conditions configured -> record notice, return
3. seen_nct = []
4. for each condition:
     result = CtGov_Client::fetch_all_for_condition(condition, statuses)
     if result.error: record per-condition error; set had_error = true; continue
     for each study in result.studies:
         meta = Field_Mapper::map(study)
         if !meta.nct_id: continue
         if meta.nct_id in exclude_list: continue
         post_id = Trial_Repository::upsert(meta)   # sets taxonomies too
         seen_nct[] = meta.nct_id
         Summary_Service::maybe_generate(post_id, meta)   # only if key + changed
5. process manual include_list (fetch single studies by NCT, upsert)
6. Reconciler::reconcile(seen_nct, had_error, settings.reconcile_mode)
7. Logger::record_sync(time, counts, errors); update last_sync option
```

`maybe_generate` is called inline but is internally guarded to be cheap (returns immediately if no key or unchanged). For large result sets it could be offloaded to per-trial Action Scheduler jobs; v1 keeps it inline since rare-disease sets are tiny (5–40), and documents the offload seam.

### 6.2 Upsert (`Trial_Repository::upsert( array $meta ): int`)

- Look up existing post by `skmctf_nct_id` meta (indexed lookup; maintain a `meta_key` query or a slug match on `post_name == lower(nct_id)` which is faster).
- If found: `wp_update_post` (title/slug only if changed) + update changed meta.
- If not: `wp_insert_post`.
- Set `trial_status` / `trial_phase` terms.
- Update `skmctf_last_synced`.
- Returns post ID. All writes wrapped so a single bad study can't abort the run.

### 6.3 Reconciler + **no-wipe guard** (the most important safety code)

```php
public function reconcile( array $seen_nct, bool $had_error, string $mode ): void {
    // GUARD 1: never reconcile after any fetch error this run.
    if ( $had_error ) { $this->log->warn('reconcile skipped: had_error'); return; }

    // GUARD 2: never reconcile on a suspicious empty result.
    if ( empty( $seen_nct ) ) { $this->log->warn('reconcile skipped: empty result set'); return; }

    $existing = $this->repo->all_nct_ids();           // currently stored
    $dropped  = array_diff( $existing, $seen_nct );

    // GUARD 3: sanity ratio — if we'd drop more than X% of content in one run,
    // skip and flag for admin review (protects against partial CT.gov outages
    // that return 200 with a truncated set).
    if ( count($existing) > 0 && (count($dropped)/count($existing)) > self::MAX_DROP_RATIO ) {
        $this->log->warn('reconcile skipped: drop ratio exceeded', ...);
        $this->notices->flag_review_needed();
        return;
    }

    foreach ( $dropped as $nct ) {
        if ( 'remove' === $mode ) { $this->repo->delete_by_nct($nct); }
        else                      { $this->repo->mark_closed($nct); }  // default
    }
}
```

`MAX_DROP_RATIO` (e.g. 0.5) is filterable. This three-guard design means a transient CT.gov failure, an empty response, **or** a partial-truncation 200 cannot silently erase a PAG's trials.

### 6.4 Scheduler (`Scheduler`)

- On activation: if `! as_has_scheduled_action('skmctf_daily_sync')`, `as_schedule_recurring_action( strtotime('tomorrow 3am'), DAY_IN_SECONDS, 'skmctf_daily_sync', [], 'kisho-clinical-trials' )`.
- Self-heal: on `init`, if `as_supports('ensure_recurring_actions_hook')`, hook `action_scheduler_ensure_recurring_actions` to re-register if missing.
- `add_action('skmctf_daily_sync', [Sync_Engine, 'run'])` with `'scheduled'` trigger.
- On deactivation: `as_unschedule_all_actions('skmctf_daily_sync', [], 'kisho-clinical-trials')`.
- Bundled Action Scheduler loaded via `require .../action-scheduler/action-scheduler.php` early (it self-guards against double-load when another plugin like WooCommerce also bundles it — AS picks the newest version across all loaders).

---

## 7. LLM summaries (BYO-key)

### 7.1 Provider interface + factory

```php
interface Llm_Provider {
    /** @return string|WP_Error  Plain-language summary text, or WP_Error on failure. */
    public function generate_summary( string $system, string $user, array $opts = [] );
    public function id(): string; // 'anthropic' | 'openai'
}
```

`Provider_Factory::make()` reads provider name from settings, instantiates the matching class, and injects the key via:

```php
$key = (string) apply_filters( 'skmctf_llm_api_key', Settings::get_api_key(), $provider_name );
```

This **filter seam** is the future-proofing: a core global-key vault (or the Kisho connector) can supply the key without the plugin storing one. If the filtered key is empty → provider not constructed → summaries off.

- **Anthropic:** `POST https://api.anthropic.com/v1/messages`, header `x-api-key`, `anthropic-version: 2023-06-01`, model default `claude-haiku-4-5-20251001` (cheap, fast, sufficient for 8th-grade rewrites; filterable). Body: system + user message. Parse `content[0].text`.
- **OpenAI:** `POST https://api.openai.com/v1/chat/completions` (or Responses API), header `Authorization: Bearer`, model default `gpt-4o-mini` (filterable). Parse `choices[0].message.content`.

Both via `wp_remote_post`, 30s timeout, single retry on 429/5xx with backoff, structured `WP_Error` on failure.

> **Model defaults are filterable** (`skmctf_llm_model`) so a PAG can pick a different tier. Haiku/4o-mini chosen as the responsible-steward default since we're spending the PAG's money.

### 7.2 Summary service (change detection + caching)

`Summary_Service::maybe_generate( int $post_id, array $meta ): void`:

```
1. if no provider/key -> return (summaries off)
2. existing_summary = get_post_meta(post_id, 'skmctf_plain_summary')
   source_date     = get_post_meta(post_id, 'skmctf_plain_summary_source_date')
3. if existing_summary AND source_date === meta.ct_last_update -> return (unchanged; cached)
4. prompt = Prompt_Builder::build(meta)   # constrained: plain language, 8th-grade,
                                          # factual, no medical advice, includes disclaimer instruction
5. text = provider.generate_summary(system, user)
6. if WP_Error: Logger::record_llm_error(); Admin_Notices::flag_llm(); return  # front end unaffected
7. text = enforce_disclaimer(text)        # guarantee the disclaimer is present even if model omits
8. update_post_meta(post_id, 'skmctf_plain_summary', wp_kses_post(text))
   update_post_meta(post_id, 'skmctf_plain_summary_source_date', meta.ct_last_update)
```

This is the "spend the PAG's money responsibly" core: a summary is (re)generated **only** when the trial is new or CT.gov's `lastUpdatePostDate` advanced past the cached snapshot. Disclaimer is appended deterministically, never trusted to the model.

### 7.3 Prompt constraints

System prompt fixes role + rules (plain language ~8th grade, factual, summarize only the provided fields, **no medical advice**, end with the fixed disclaimer line). User message carries the structured trial facts. `enforce_disclaimer()` guarantees the "*This is a summary — read the full record and talk to your doctor*" line regardless of model output. The disclaimer text is translatable.

---

## 8. Front end

### 8.1 Shared renderer

Block and shortcode both delegate to `List_Renderer::render( array $args )`, which:

1. builds args from block attributes / shortcode atts (status, phase, state, per_page, show_map, columns),
2. runs `Trials_Query` (a `WP_Query` wrapper, `tax_query` on status/phase, meta filter on state, paginated),
3. emits semantic, accessible markup (`<ul>` of `<li>` cards; each card from `templates/list-item.php`, theme-overridable),
4. emits the filter UI (a real `<form method="get">` so filtering works **without JavaScript**; `filters.js` progressively enhances to instant client-side filtering),
5. handles empty / error states with friendly, translatable messaging.

List item content (per scope): title (link to single page if enabled, else CT.gov), recruiting-status badge, phase, conditions, sponsor, location summary, `plain_summary` if present else `brief_summary` excerpt, "View on ClinicalTrials.gov" link (`target=_blank rel="noopener noreferrer"`).

### 8.2 Block

`block.json` apiVersion 3, `"render"` pointing to a PHP callback (`Block::render`) so data is always live (server-side render). Editor (`edit.js`) provides `InspectorControls`: status filter, phase filter, default state, items per page, show map toggle, columns. Built with `@wordpress/scripts` (`npm run build`) → `build/` enqueued; **source committed** (non-obfuscated requirement) and build output committed for .org.

### 8.3 Shortcode

`[skmctf_trials status="recruiting" phase="" state="" per_page="20" map="false"]` → same `List_Renderer`. Attributes sanitized via `shortcode_atts` + per-attr cleaning.

### 8.4 Map (optional, off by default)

When `show_map` is on and at least one trial has coordinates: enqueue **bundled** Leaflet (`assets/lib/leaflet/`, local URLs only) + `map.js`. Markers from stored `lat/lng`. No external tile key needed (OSM default tiles). Tile attribution rendered (OSM requires it). Off by default; degrades to list-only if no coordinates.

### 8.5 SEO (`Seo`)

- Archive (`archive-skmctf_trial.php`): always indexable.
- Single trial: on `wp_head`, if current post is `skmctf_trial` AND `skmctf_plain_summary` is empty AND override setting is off → output `<meta name="robots" content="noindex,follow">`. With a summary, or override on → indexable.
- Plays nice with SEO plugins: use the `wp_robots` filter (modern core API) rather than echoing raw tags, so Yoast/RankMath can still manage other directives.

### 8.6 Assets & accessibility

- `frontend.css` scoped under a `.skmctf-` namespace; respects theme; honors `prefers-reduced-motion`.
- WCAG 2.1 AA: semantic landmarks, real form controls with `<label>`, status badges carry text (not color alone), focus-visible states, ARIA only where semantics fall short, keyboard-operable filters and map.
- Assets enqueued **conditionally** (only when block/shortcode present, via `has_block` / shortcode detection or block-aware enqueue) — no global front-end bloat.

---

## 9. Settings (admin)

Settings API, single options array `skmctf_settings` (registered with one `sanitize_callback` that validates the whole structure). Page under Settings → "Clinical Trials Feed". Capability: `manage_options`. Every form has a nonce; "Sync now" is a separate `admin-post.php` action with its own nonce + cap check.

Fields (from scope §11):
- **Conditions** (repeatable text rows).
- **Include NCT list** / **Exclude NCT list** (textarea, one NCT per line, validated `^NCT\d{8}$`).
- **Status filter** (checkboxes of CT.gov `overallStatus` values; `RECRUITING` default-on).
- **Reconcile mode** (`mark_closed` default | `remove`).
- **Summaries:** enable toggle, provider dropdown (Anthropic | OpenAI), API key (**password field**, write-only — never re-echoed; shows "•••• saved" when set), model override (optional).
- **Display:** fields to show (checkboxes), map on/off, single-pages on/off, single-page indexing override.
- **Attribution:** front-end "Trial display by SKM Digital" toggle (off by default).
- **Status panel:** last sync time, last error, "Sync now" button.

**Key handling:** stored in the `skmctf_settings` option with `autoload` forced off (the whole option is registered `autoload = no`). On render, the key field value is **never** populated from the stored value; a boolean "is set" drives the placeholder. Submitting empty leaves the existing key untouched; submitting a value replaces it; a "clear key" checkbox removes it. `Settings::get_api_key()` is the only reader, and it feeds the `skmctf_llm_api_key` filter.

### 9.1 SKM discovery hooks (tasteful, repo-safe)

- Settings sidebar card: "Built by SKM Digital — custom rare disease tools, Salesforce integrations, and headless builds for PAGs." + contact link. Rendered only on this plugin's settings page. No dashboard nags, no network calls.
- Header/readme author = SKM Digital, URI skm.digital.
- CT.gov data credit always shown on front end; SKM front-end attribution off by default.
- **No telemetry.** Zero phone-home.

---

## 10. Error handling & logging

`Logger` writes to a bounded option (`skmctf_log`, autoload off, ring buffer of last N events) plus dedicated `skmctf_last_sync` / `skmctf_last_error` options for the settings panel. No PII. Categories: sync start/end + counts, per-condition fetch errors, LLM errors, reconcile-skip reasons. Admin notices are gentle, dismissible, and only shown to capable users on relevant screens. **The front end never surfaces internal errors** — worst case it shows a friendly empty/error state.

---

## 11. Internationalization

- Text domain `kisho-clinical-trials` on every user-facing string.
- `load_plugin_textdomain` on `init` (still called for back-compat though core auto-loads .org translations).
- `.pot` generated via `wp i18n make-pot`.
- JS strings via `wp.i18n` + `wp_set_script_translations`.
- CT.gov **content** (titles, criteria) is not translated (out of scope) — only UI chrome.

---

## 12. Security checklist (enforced throughout, audited at the end)

- **Input:** every `$_POST/$_GET/$_REQUEST` sanitized at entry; settings via one structured sanitizer; NCT/enum whitelists.
- **Output:** every echo escaped at the point of output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for our authored summary). Templates audited.
- **Nonces + caps:** settings save, "Sync now", any admin-post / AJAX. `current_user_can('manage_options')`.
- **SQL:** no raw queries except via `$wpdb->prepare`; prefer `WP_Query`/meta APIs.
- **HTTP:** all external calls via `wp_remote_*`, timeouts, no SSL-verify disabling.
- **Secrets:** key never echoed, autoload off, password field, write-only UX.
- **No eval / no obfuscation / no remote code / no CDN assets / no telemetry.**

---

## 13. Testing strategy

Test-driven where it pays: pure logic first (red→green), I/O units with mocks.

- **Unit (no WP, Brain Monkey / plain PHPUnit):**
  - `Field_Mapper`: golden CT.gov fixtures (full, sparse, missing-modules, malformed) → expected meta. Highest coverage.
  - `Reconciler` guards: had_error, empty set, drop-ratio breach, normal close, remove mode.
  - `Prompt_Builder` + `enforce_disclaimer`: disclaimer always present.
  - `Settings` sanitizer: NCT validation, enum whitelists, key write-only behavior.
- **Integration (WP test suite, `WP_UnitTestCase`):**
  - `CtGov_Client` with `pre_http_request` mock: pagination, error contract, retry.
  - `Trial_Repository::upsert`: insert then update path, taxonomy terms, meta round-trip.
  - `Sync_Engine::run` end-to-end with mocked client + provider: counts, no-wipe on error.
  - `Llm_Provider`s with mocked HTTP: success parse, error mapping.
- **Standards:** PHPCS with `WordPress` + `WordPress-Extra` rulesets must pass clean. PHPCompatibility for PHP 7.4+ (declared minimum).
- **Manual smoke (LocalWP):** activate, configure a real rare-disease condition, run "Sync now", verify CPT populated, block renders, single page noindex flips with a summary, map toggles.

---

## 14. Compatibility & headers

- **Requires at least:** WP 6.4 · **Tested up to:** 6.8 · **Requires PHP:** 7.4.
- `readme.txt` with proper stable tag, short description, FAQ, screenshots section, changelog, "Upgrade Notice".
- `.distignore` excludes `tests/`, `node_modules/`, `vendor/` (dev), `.git`, source maps as appropriate — but **keeps** built block assets and bundled libs.

---

## 15. Build sequence (maps to scope §14)

1. Scaffold: main file, autoloader, `Plugin` bootstrap, constants, activation/deactivation, i18n, bundled Action Scheduler load.
2. CPT + taxonomies + registered meta (with REST schemas).
3. CT.gov client + field mapper (+ fixtures + unit tests) with the failure contract.
4. Repository + Sync engine + Reconciler (no-wipe guards) + Scheduler.
5. Settings page (Settings API, nonces, caps, write-only key) + Sync-now + notices + logger.
6. LLM provider interface + Anthropic + OpenAI + factory (filter seam) + Summary service (change-detect/cache/disclaimer/fallback).
7. Front end: shared renderer, query, shortcode, block (server render), templates, filters, states, conditional assets, Leaflet map.
8. SEO conditional noindex.
9. SKM discovery hooks, readme.txt, accessibility pass, full escaping/sanitization audit, PHPCS clean, `.pot`, `.distignore`.

---

## 16. Documented future seams (Touch of Kisho — not built in v1)

- `skmctf_llm_api_key` filter → adopt a core/global key vault with no rewrite.
- `skmctf_condition_query` filter on the CT.gov query → MONDO-normalized / synonym-expanded matching (the Kisho connector).
- Per-trial summary generation offload to Action Scheduler jobs (for large sets).
- `skmctf_trial_meta_map` filter on the field mapper → enrichment fields.
- Template overrides via theme for full presentational control.

These are interface seams only — documented in code comments and `readme.txt`'s developer notes, not implemented.
```
