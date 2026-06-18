# Clinical Trials Feed — Product Requirements (v1)

**Built by SKM Digital · Free WordPress plugin for rare disease PAGs**

---

## 1. Goal & positioning

A free, GPL, wordpress.org-distributed plugin that pulls condition-relevant trials from ClinicalTrials.gov and displays them on a PAG's WordPress site, kept fresh automatically, with optional patient-friendly summaries generated via the PAG's _own_ LLM API key.

**Strategic role.** Top-of-funnel for SKM Digital's bespoke work — headless builds, Salesforce/CRM integration, custom registries. The plugin is the proof-of-competence and the relationship opener; revenue comes from the custom engagements it surfaces. Every feature in the plugin is free.

**Touch of Kisho.** The architecture leaves a clean enrichment seam (MONDO normalization, relevance ranking, disease-page cross-linking) as a documented future extension. It is **not** built in v1.

---

## 2. Operating principles (the constraints that shaped this)

- **Free to the user _and_ free to operate for SKM.** Every feature runs on the PAG's own WordPress install. No SKM-hosted services, no telemetry, no recurring infra. BYO-key summaries are what make this hold.
- **wordpress.org repo compliant.** GPLv2+, no required external paid service, full input sanitization / output escaping, nonces + capability checks, no phone-home, readable (non-obfuscated) source.
- **Zero hard dependencies.** Native WordPress only. **No ACF Pro** — a free .org plugin can't require it. Native blocks, Settings API, `register_post_meta`.
- **Accessible.** WCAG 2.1 AA markup, keyboard navigation, ARIA, semantic structure.
- **i18n-ready.** Text domain on every string; translation-ready out of the box.

---

## 3. Architecture: sync-to-CPT (not live-fetch)

**Data flow**

1. Admin configures condition(s) + optional NCT include/exclude + optional LLM key + display options.
2. A daily cron event fires the sync.
3. Sync queries the CT.gov v2 `/studies` endpoint with the configured condition + status filter, requesting only the fields needed, paginating as required.
4. Each returned study is **upserted** into the `skmctf_trial` CPT, keyed on NCT ID.
5. If an LLM key is present, any trial that is new or whose CT.gov last-update date changed gets a freshly generated plain-language summary (cached in meta). Otherwise skipped.
6. Reconcile: trials that dropped out of the result set are marked closed (default) or removed, per setting.
7. Front end queries the CPT and renders the list/detail with filters.

**Why CPT over live-fetch.** SEO is the core PAG value — a PAG ranking for "_[disease] clinical trials_" only happens if the trials are indexable content in their database, not an API call at render time. Local storage also gives fast front-end filtering and survives CT.gov downtime. The matching set for a rare disease is tiny (often 5–40 trials), so storage and sync cost are negligible.

---

## 4. Data source: ClinicalTrials.gov API v2

- Endpoint: `https://clinicaltrials.gov/api/v2/studies` · `format=json` · **no auth required**.
- Key query params: `query.cond`, `filter.overallStatus`, `fields` (request a minimal field set), `pageSize`, `pageToken`, `countTotal=true`.
- Request only the submodules needed (below) to keep payloads small.

**Field mapping (CT.gov v2 → CPT meta)**

| CPT meta                          | CT.gov v2 path                                                                                           |
| --------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `nct_id` _(upsert key)_           | `protocolSection.identificationModule.nctId`                                                             |
| `official_title`                  | `protocolSection.identificationModule.officialTitle`                                                     |
| `brief_title`                     | `protocolSection.identificationModule.briefTitle`                                                        |
| `overall_status`                  | `protocolSection.statusModule.overallStatus`                                                             |
| `ct_last_update`                  | `protocolSection.statusModule.lastUpdatePostDateStruct.date`                                             |
| `brief_summary`                   | `protocolSection.descriptionModule.briefSummary`                                                         |
| `conditions[]`                    | `protocolSection.conditionsModule.conditions`                                                            |
| `phase`                           | `protocolSection.designModule.phases`                                                                    |
| `study_type`                      | `protocolSection.designModule.studyType`                                                                 |
| `lead_sponsor`                    | `protocolSection.sponsorCollaboratorsModule.leadSponsor.name`                                            |
| `eligibility.sex`                 | `protocolSection.eligibilityModule.sex`                                                                  |
| `eligibility.min_age` / `max_age` | `protocolSection.eligibilityModule.minimumAge` / `maximumAge`                                            |
| `eligibility.criteria`            | `protocolSection.eligibilityModule.eligibilityCriteria`                                                  |
| `locations[]`                     | `protocolSection.contactsLocationsModule.locations` _(facility, city, state, country, status, geoPoint)_ |
| `ct_url`                          | `https://clinicaltrials.gov/study/{nctId}`                                                               |

---

## 5. Data model: `skmctf_trial` CPT

- `public: true` (enables individual pages / SEO) with controllable templates.
- Meta fields: `nct_id`, `official_title`, `brief_title`, `overall_status`, `phase`, `study_type`, `conditions[]`, `lead_sponsor`, `locations[]`, `eligibility{sex,min_age,max_age,criteria}`, `brief_summary`, `plain_summary`, `ct_last_update`, `last_synced`, `ct_url`.
- Optional taxonomies for filtering/archives: `trial_status`, `trial_phase`.
- All meta registered via `register_post_meta` with sanitize callbacks.

---

## 6. Sync engine

- **Mechanism:** native WP-Cron daily event (`skmctf_daily_sync`).
  - **[DECISION — flippable] Native wp-cron** is recommended for a lean, zero-dependency v1. A few hours of staleness is harmless for this dataset, and the "only fires on traffic" caveat is a non-issue for any site with modest traffic. _Flip to Action Scheduler or a real system cron if a PAG needs guaranteed timing._
- **Upsert** keyed on NCT ID (update existing, insert new).
- **Reconciliation:** trials no longer returned → `mark closed` (default) | `remove`, per setting.
- **Failure safety (critical):** never wipe existing trials on an empty or errored API response. Guard the reconcile step so a transient CT.gov failure can't delete the PAG's content.
- **Manual "Sync now"** button on the settings page; display last-sync time and last error.

---

## 7. Plain-language summaries (BYO-key)

- **Off unless** the admin enters their own LLM API key. The plugin is fully functional without it (raw CT.gov display).
- **Provider abstraction:** support Anthropic + OpenAI behind a single interface with a provider dropdown. The call shape difference is trivial, and a PAG may already have either key — shipping both removes a "_I only have an OpenAI key_" barrier.
- **Generation timing:** at sync time, only for trials that are new or whose `ct_last_update` changed since `plain_summary` was written. Cache aggressively — you're spending the PAG's money, so be a good steward of their key.
- **Prompt constraints:** plain language (~8th-grade reading level), factual, no medical advice, and a built-in "_this is a summary — read the full record and talk to your doctor_" disclaimer in the output.
- **Failure handling:** invalid key / API error → log, skip, fall back to raw display, surface a gentle admin notice. Never break the front end.
- **Key storage:** `wp_options` with `autoload = no`, capability-gated, rendered as a password field, never echoed back in plaintext. _Honest limitation: WordPress has no native secrets vault; this is the realistic security bar._

---

## 8. Front-end display

- **Native Gutenberg block** (`block.json` + server-side `render_callback` so data stays live) **+ `[skmctf_trials]` shortcode** fallback.
- **List item:** title, recruiting-status badge, phase, conditions, sponsor, location summary, plain-language summary (if present) else a `brief_summary` excerpt, link to the full CT.gov record (`rel="noopener"`, new tab).
- **Filters:** status, phase, state/location.
- **States:** empty, loading, error — all handled gracefully.
- **Optional map view:** Leaflet + OpenStreetMap (free). **Not** Google Maps (it bills you). Display option, off by default.
- **Single trial template** (when individual pages enabled): full detail + plain-language summary + eligibility + locations + CT.gov link.

---

## 9. SEO handling **[DECISION — flippable]**

- **Archive/listing page:** always indexable — this is the PAG's "_[disease] clinical trials_" ranking asset.
- **Single trial pages:** registered and available, but default **`noindex` unless a `plain_summary` exists** — i.e., the page is only indexed when it carries unique, non-duplicate value. Setting to override.
  - _Rationale:_ avoids thin/duplicate-content penalties for PAGs without a key, and rewards those who add the summary layer. _Flip to listing-only if you'd rather not own single pages at all._

---

## 10. SKM discovery hooks (tasteful, repo-safe)

- Plugin header + `readme.txt`: author **SKM Digital**, URI **skm.digital**.
- Settings-page sidebar card: "_Built by SKM Digital — custom rare disease tools, Salesforce integrations, and headless builds for PAGs._" + contact link. No nag screens, no dashboard takeovers.
- Front-end attribution: **off by default**, optional tasteful "_Trial display by SKM Digital_" toggle. (A ClinicalTrials.gov data credit is appropriate regardless.)
- **No telemetry.** Any future install analytics must be opt-in and disclosed.

---

## 11. Settings (admin UX)

- Conditions (repeatable), manual NCT **include** list, manual NCT **exclude** list.
- Status filter (which `overallStatus` values to pull; default includes `RECRUITING`).
- Reconciliation behavior (`mark closed` | `remove`).
- Summaries: provider, API key (password field), enable toggle.
- Display: fields to show, map view on/off, single-pages on/off, single-page indexing override.
- "Sync now" button + last-sync status + last-error display.

---

## 12. Out of scope for v1 (deliberate)

- **Email alerts** → Phase 2. Decentralized (PAG's own wp-cron + their SMTP), but it involves subscriber PII, unsubscribe flows, and CAN-SPAM — it deserves its own focused pass rather than bloating v1.
- **Salesforce/CRM sync** → a bespoke SKM engagement, not a free-plugin feature. (Also the most natural paid conversation.)
- **Kisho enrichment layer** → seam only (documented extension point), not built.
- **User accounts, saved trials, matching profiles.**
- **Multisite-specific features**; translation of CT.gov _content_ (UI i18n yes, trial-data translation no).

---

## 13. Phase 2 / roadmap (not now)

- Decentralized email alerts (new-trial notifications via the PAG's own infrastructure).
- Map clustering, distance-from-visitor.
- **Kisho connector** — MONDO-normalized matching, relevance ranking, disease-page cross-links. Turns condition-name matching from "best effort" into "catch every synonym," which is the single biggest data-quality lever and the natural place the Kisho touch grows up.

---

## 14. Build sequence (for Claude Code)

1. Plugin scaffold; `skmctf_trial` CPT + meta + optional taxonomies; i18n setup.
2. CT.gov v2 client + field mapper (with the no-wipe-on-failure guard).
3. Sync engine + cron + reconciliation + manual sync.
4. Settings page (Settings API, nonces, capability checks, password key field).
5. BYO-key summary service (provider abstraction, cache-on-change, failure fallback).
6. Block + shortcode + templates + filters + states.
7. SEO/indexing logic (conditional `noindex`).
8. SKM hooks, `readme.txt`, accessibility pass, escaping/sanitization audit.

---

## Two decisions I made for you — sanity-check these

1. **Sync = native wp-cron** (lean, zero-dep). Flip to Action Scheduler if guaranteed timing matters.
2. **Single trial pages exist but are `noindex`-until-summarized.** Flip to listing-only if you don't want to own single pages.
