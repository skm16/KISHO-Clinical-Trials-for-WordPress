=== Clinical Trials Feed ===
Contributors: skmdigital
Tags: clinical trials, rare disease, clinicaltrials.gov, patient advocacy, health
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pulls condition-relevant clinical trials from ClinicalTrials.gov and keeps them fresh on your site automatically.

== Description ==

**Clinical Trials Feed** connects your WordPress site to the [ClinicalTrials.gov API v2](https://clinicaltrials.gov/data-api/api), automatically syncing relevant studies to a native post type and displaying them with a block or shortcode. No CDN dependencies. No telemetry.

= Features =

* **Auto-sync** — A daily scheduled job (powered by Action Scheduler) fetches and upserts trials from ClinicalTrials.gov. A "Sync now" button is available in the admin for on-demand updates.
* **Gutenberg block + `[skmctf_trials]` shortcode** — Drop trials anywhere on your site with full server-side rendering.
* **Filter bar** — Visitors can filter by status, phase, state, and country. Works without JavaScript (progressive enhancement).
* **Optional plain-language summaries (BYO-key)** — Supply your own Anthropic or OpenAI API key and the plugin will generate accessible plain-language summaries for changed trials. You are billed directly by the LLM provider; SKM Digital never touches your key.
* **Optional map view** — Leaflet + OpenStreetMap, bundled locally (no CDN, no Google Maps). Off by default.
* **Safe reconciliation** — A drop-ratio guard prevents accidental bulk removal of trial posts if the upstream feed returns unexpectedly few results.

= Data source =

All clinical trial data is retrieved from **ClinicalTrials.gov**, a service of the U.S. National Library of Medicine. A "Data from ClinicalTrials.gov" credit is always displayed on the front end. Data is stored locally in your WordPress database and refreshed automatically.

= No telemetry =

This plugin does **not** phone home, collect usage data, or transmit any information to SKM Digital or any third party other than the external services explicitly listed below.

= External services =

This plugin makes requests to the following external services:

**1. ClinicalTrials.gov API (always, no authentication required)**

Every sync retrieves trial data from the public ClinicalTrials.gov REST API v2. This request is made from your server (not the visitor's browser) on a daily schedule and when you click "Sync now". No personal data is sent; the request contains only a condition search query.

* Endpoint: `https://clinicaltrials.gov/api/v2/studies`
* Service terms: [ClinicalTrials.gov terms of use](https://clinicaltrials.gov/ct2/about-site/terms-conditions)
* Privacy policy: [NLM/NIH privacy policy](https://www.nlm.nih.gov/web_policies.html)

**2. LLM API — Anthropic or OpenAI (only if you configure an API key)**

If you supply an API key under Settings → Clinical Trials Feed → AI Summary, the plugin will call the chosen provider's API to generate plain-language summaries for new or changed trials. No API key = no call is ever made. You retain full control of your key and are billed directly by the provider.

* Anthropic API terms: [https://www.anthropic.com/legal/aup](https://www.anthropic.com/legal/aup)
* Anthropic Privacy Policy: [https://www.anthropic.com/legal/privacy](https://www.anthropic.com/legal/privacy)
* OpenAI API terms: [https://openai.com/policies/usage-policies](https://openai.com/policies/usage-policies)
* OpenAI Privacy Policy: [https://openai.com/policies/privacy-policy](https://openai.com/policies/privacy-policy)

**3. OpenStreetMap tile server (only when the map view is enabled)**

When the optional Leaflet map is displayed, tiles are loaded in the visitor's browser directly from `https://{s}.tile.openstreetmap.org/`. The visitor's IP address is transmitted to OSMF servers as part of every tile request. No personal data beyond the IP is sent. This connection is made client-side and only occurs on pages where the map is rendered.

* Service terms: [https://wiki.osmfoundation.org/wiki/Terms_of_Use](https://wiki.osmfoundation.org/wiki/Terms_of_Use)
* Privacy policy: [https://wiki.osmfoundation.org/wiki/Privacy_Policy](https://wiki.osmfoundation.org/wiki/Privacy_Policy)

**4. Browser Geolocation API (only if the geolocation button is enabled)**

When the optional "Find trials near me" button is enabled, the visitor's browser may request their location using the standard `navigator.geolocation` API. This is entirely client-side: coordinates are used only to re-centre the Leaflet map in the visitor's browser and are **never sent to the server, stored, logged, or transmitted to any third party**. The feature requires HTTPS; the geolocation button is visible only when HTTPS is detected — on plain HTTP the button is not rendered.

= Developer notes =

The plugin exposes documented filter seams for customisation:

* `skmctf_llm_api_key` — Override the stored LLM API key at runtime (e.g. from a secrets manager).
* `skmctf_condition_query` — Modify the condition search string sent to ClinicalTrials.gov.
* `skmctf_max_drop_ratio` — Adjust the safety threshold (default 0.5) for the reconciliation guard.
* `skmctf_trial_rewrite_slug` — Change the URL slug for the `skmctf_trial` custom post type.

== Installation ==

1. Upload the `kisho-clinical-trials` folder to `/wp-content/plugins/` or install via **Plugins → Add New**.
2. Activate the plugin through the **Plugins** menu.
3. Go to **Settings → Clinical Trials Feed** and enter the medical condition you want to track (e.g. "Huntington Disease").
4. Click **Sync now** to run the first import, or wait for the daily scheduled sync.
5. Add the **Clinical Trials Feed** block via the block editor, or add `[skmctf_trials]` to any post or page.

Optional: supply an Anthropic or OpenAI API key to enable plain-language summaries.

== Frequently Asked Questions ==

= Do I need an API key? =

No. The plugin is fully functional without any API key. Trials are synced from the public ClinicalTrials.gov API at no cost. An API key is only required if you want the optional plain-language summary feature, which calls an LLM (Anthropic or OpenAI) to rewrite the technical brief summary into plain English.

= Where is my API key stored? =

Your API key is stored in your site's WordPress database (`wp_options`), with `autoload` disabled. The field is write-only in the admin UI — the key is never displayed after saving. SKM Digital does not have access to your key.

= Does it phone home? =

No. The plugin contains no telemetry, no usage tracking, and no requests to SKM Digital servers. The only outbound connections are to ClinicalTrials.gov (always) and, optionally, to the LLM provider you configure.

= Does the geolocation feature store or share my location? =

No. When enabled, the "Find trials near me" button uses the browser's built-in `navigator.geolocation` API to obtain your coordinates. Those coordinates are used exclusively to pan the map in your browser — they are never sent to the server, written to the database, or transmitted to any third party. The feature requires HTTPS; the button is not rendered on plain HTTP pages so it will never appear in a context where it cannot function.

= What external services does it use? =

Up to four possible services:

1. **ClinicalTrials.gov** — Always used for trial data. Public API, no auth, no personal data sent.
2. **Your chosen LLM (Anthropic or OpenAI)** — Only if you configure an API key. Never called otherwise.
3. **OpenStreetMap tile server** — Only when the map view is displayed. Tiles are fetched client-side by the visitor's browser; the visitor's IP is transmitted to OSMF servers as part of normal tile delivery.
4. **Browser Geolocation API** — Only when the geolocation button is enabled and the page is served over HTTPS. Entirely client-side; no coordinates leave the browser.

See the **External services** section in the Description for full details and links to terms/privacy policies.

= How does it find trials? =

You enter one or more medical conditions in the plugin settings. On each sync the plugin queries ClinicalTrials.gov for studies matching those conditions, maps the response to local post meta, and upserts them (insert or update) based on the NCT ID. A safety guard prevents bulk deletion if the upstream feed returns an unexpectedly small number of results.

= Can I change the sync frequency? =

By default the plugin syncs once daily via Action Scheduler. A "Sync now" button is available in the admin for immediate, on-demand syncs.

== Screenshots ==

1. Admin settings page — configure condition, API key, display options.
2. Front-end trial list with status/phase/state filters.
3. Optional map view showing trial locations.
4. Gutenberg block in the editor sidebar.

== Changelog ==

= 1.3.2 =
* Fixed: "Grid view" looked the same as "List view" — a single full-width column — unless the Columns setting was raised to 2 or 3. Grid view is now a responsive multi-column card grid by default (it fits as many columns as the screen allows); the Columns setting still pins an exact count when you want one.

= 1.3.1 =
* Fixed: The grid/list view choice a visitor made in their browser could permanently override the admin's "Default view" setting — once someone viewed the list, they kept getting the list even after the admin set the default back to grid. A remembered choice is now tied to the admin default and is reset whenever the admin changes it.

= 1.3.0 =
* New: Trials can now be shown as a compact **list** or the **card grid**. Pick the default under Settings → Clinical Trials → Display → Default view; visitors get a grid/list toggle and their choice is remembered in their browser.

= 1.2.0 =
* New: Two optional, admin-selectable front-end themes — **Clinical** and **Patient-friendly** — each available in light and dark. Choose them under Settings → Clinical Trials → Theme & appearance. The default (**Skeleton**) is unchanged in behavior and now also offers a dark mode.
* New: Themes are pure CSS skins over the existing markup, applied only to the plugin's own trials list and single-trial pages (never the surrounding site). Fonts (Public Sans, Newsreader, Mulish) are bundled locally — no external requests.
* Improved: The trials list and single-trial layout were refined as the shared baseline structure for all themes.

= 1.1.3 =
* Fixed: The patient-friendly fields ("What this study is testing", "Who can join", "Questions to ask your doctor") were not generated for trials that already had a plain-language summary, because the summary cache short-circuited before they ran. They are now generated independently of the summary cache, so existing trials get them on the next sync.
* Fixed: "Who can join" displayed as a run-on line instead of a bulleted list on trials imported before 1.1.2 (the list markup had been removed by an earlier version's sanitizer). These entries now repair themselves automatically on the next sync, and the field is regenerated whenever its stored value is missing its list markup.
* Developer: The integration test suite is now runnable from a standard checkout — added the `yoast/phpunit-polyfills` dev dependency, fixed the test bootstrap to load the WordPress test helpers before registering hooks, and added a Subversion-free, Windows-aware test installer (`bin/install-wp-tests-git.sh`).

= 1.1.2 =
* Fixed: Single trial pages now render the full detail (status, conditions, sponsor, summary, eligibility, locations, map) — previously they showed only the title due to a rendering wiring gap.
* New: Patient-friendly LLM fields on single trial pages — a one-line "What this study is testing", a plain-language "Who can join", and "Questions to ask your doctor". Generated during sync (cached); run "Sync now" to populate them for existing trials. Requires an LLM API key; pages without it show the standard detail.
* Improved: On trials with many sites, the locations list shows the first 10 with a "Show all" toggle; the single-page map now uses the same robust per-instance data transport as the archive.

= 1.1.1 =
* Fixed: State filter accuracy — state filtering is now exact via a dedicated `trial_state` taxonomy rather than a substring match. The State / Province filter is now a dropdown of the states/provinces actually present in your data (matching the Country filter), so abbreviations like "CT" no longer silently miss the full state name "Connecticut". Run "Sync now" or `Trial_Repository::backfill_state_terms()` to populate state terms for trials imported before this update — the dropdown stays hidden until at least one state term exists.
* New: The State / Province filter appears after Country and lists only the states/provinces within the selected country (e.g. choosing United States shows US states; choosing Canada shows Canadian provinces). With no country selected it lists all states/provinces. A state that does not belong to the selected country is ignored rather than returning an empty result.
* Fixed: Map pins now respect the active country/state filter — a multinational trial that matches the filter shows only its locations in the filtered country/state, rather than plotting all of its worldwide sites.
* Fixed: Clearing a default filter (status, phase, state, or country) now works — resetting a pre-configured filter no longer silently falls back to the default.
* Fixed: Multiple trial blocks/shortcodes on a single page now render correctly — each map carries its own data and filter-form element IDs are unique per instance instead of colliding. Map data is delivered in a per-instance JSON script element so location names containing quotation marks no longer break the map.
* Fixed: Multi-phase trials now appear under each of their phase filters (e.g. a "Phase 1/Phase 2" trial shows under both Phase 1 and Phase 2).
* Fixed: Stale phase and status terms are now cleared when a trial is updated, so a trial no longer keeps terms that no longer apply.
* Fixed: "Sync now" is guarded against concurrent runs to prevent overlapping syncs.
* Fixed: Trials in the "Always include" list are now protected from reconciliation removal when an upstream fetch fails.
* Fixed: Map/geolocation user-facing strings ("Find trials near me", "You are here", etc.) are now translatable.
* Fixed: The integration-test bootstrap now exits with a non-zero status when the WordPress test suite is missing, instead of reporting a false pass.

= 1.1.0 =
* Added: Country filter — a new `trial_country` taxonomy is assigned on sync; visitors can filter the trial list by country. A trial matches a country if it has at least one location in that country (multi-country trials appear under each country).
* Added: Configurable initial map view — set a default latitude, longitude, and zoom level globally (Settings → Clinical Trials Feed) or per block/shortcode (`default_lat`, `default_lng`, `default_zoom`). If unset, the map auto-fits to the displayed trial locations (existing behaviour).
* Added: Optional client-side geolocation button — enable "Find trials near me" globally or per block/shortcode (`geolocation="1"`). Clicking the button pans the map to the visitor's location using the browser's built-in `navigator.geolocation` API. **Coordinates are never sent to the server or stored.** Requires HTTPS.
* Note: After upgrading, run "Sync now" or use `Trial_Repository::backfill_country_terms()` (e.g. via WP-CLI) to populate the `trial_country` taxonomy for trials that were imported before this release. After updating to 1.1.1, also run "Sync now" (which re-upserts everything and now assigns state and split-phase terms) or use `Trial_Repository::backfill_state_terms()` to populate the new `trial_state` taxonomy for previously-imported trials.
* Note: The geolocation button user-facing strings ("Find trials near me", "Centered on your location", etc.) are English only in v1.1.0. Full i18n of JS strings is planned for a future release.

= 1.0.1 =
* Fixed: ClinicalTrials.gov sync returned no results because the `fields` request used an invalid piece-name ("Conditions" instead of "Condition"), which the API rejected with HTTP 400. Trials now sync correctly.
* Fixed: the optional map view rendered into a zero-height container and was invisible; the map container now has an explicit, themeable height.

= 1.0.0 =
* Initial release.
* Auto-sync from ClinicalTrials.gov via Action Scheduler.
* Gutenberg block and `[skmctf_trials]` shortcode with server-side rendering.
* Filter bar (status, phase, state) with no-JS progressive enhancement.
* Optional BYO-key plain-language summaries (Anthropic and OpenAI).
* Optional Leaflet map (bundled locally, off by default).
* Safe reconciliation with drop-ratio guard.
* Full i18n support (.pot included).

== Upgrade Notice ==

= 1.1.3 =
Bug-fix release: the patient-friendly fields now generate for trials that already had a summary, and "Who can join" entries that previously rendered as a run-on line now repair themselves. Run "Sync now" after upgrading to apply both fixes to existing trials.

= 1.1.2 =
Single trial pages now display the full detail plus three new patient-friendly fields: "What this study is testing", "Who can join", and "Questions to ask your doctor" (LLM-generated when an API key is configured). Locations with many sites now collapse to 10 visible items with a "Show all" toggle. Run "Sync now" after upgrading to populate the LLM fields for existing trials.

= 1.1.1 =
Bug-fix release: exact state filtering via the new `trial_state` taxonomy, fixes for clearing default filters and for multiple trial blocks on one page, multi-phase trials now appear under each phase, stale phase/status terms are cleared on update, "Sync now" is guarded against concurrent runs, and configured included trials are protected from reconciliation on fetch failure. After upgrading, run "Sync now" or `Trial_Repository::backfill_state_terms()` to populate state terms for trials imported before this update.

= 1.1.0 =
New: country filter, configurable map view, optional client-side geolocation. After upgrading, run "Sync now" (or trigger `Trial_Repository::backfill_country_terms()`) to populate country terms for trials imported before this release. The geolocation button requires HTTPS.

= 1.0.1 =
Fixes clinical trial syncing (an API field-name error blocked all results) and makes the optional map view visible. Recommended for all users.

= 1.0.0 =
Initial release. No upgrade steps required.
