=== Clinical Trials Feed ===
Contributors: skmdigital
Tags: clinical trials, rare disease, clinicaltrials.gov, patient advocacy, health
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pulls condition-relevant clinical trials from ClinicalTrials.gov and keeps them fresh on your site automatically.

== Description ==

**Clinical Trials Feed** connects your WordPress site to the [ClinicalTrials.gov API v2](https://clinicaltrials.gov/data-api/api), automatically syncing relevant studies to a native post type and displaying them with a block or shortcode. No CDN dependencies. No telemetry.

= Features =

* **Auto-sync** — A daily scheduled job (powered by Action Scheduler) fetches and upserts trials from ClinicalTrials.gov. A "Sync now" button is available in the admin for on-demand updates.
* **Gutenberg block + `[skmctf_trials]` shortcode** — Drop trials anywhere on your site with full server-side rendering.
* **Filter bar** — Visitors can filter by status, phase, and state. Works without JavaScript (progressive enhancement).
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

= What external services does it use? =

Two possible services:

1. **ClinicalTrials.gov** — Always used for trial data. Public API, no auth, no personal data sent.
2. **Your chosen LLM (Anthropic or OpenAI)** — Only if you configure an API key. Never called otherwise.

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

= 1.0.0 =
Initial release. No upgrade steps required.
