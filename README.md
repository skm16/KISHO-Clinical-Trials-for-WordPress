# Clinical Trials Feed

A WordPress plugin that pulls condition-relevant studies from [ClinicalTrials.gov](https://clinicaltrials.gov) and displays them on your site, kept fresh automatically, with optional plain-language summaries generated through your own LLM API key.

No CDN dependencies. No telemetry. All trial data is stored locally in your WordPress database.

> The canonical, WordPress.org-formatted readme is [`readme.txt`](readme.txt). This file is the GitHub-facing overview.

## Features

- **Auto-sync** — A daily scheduled job (powered by Action Scheduler) fetches and upserts trials from the ClinicalTrials.gov API v2. A **Sync now** button is available in the admin for on-demand updates.
- **Gutenberg block + `[skmctf_trials]` shortcode** — Drop trials anywhere with full server-side rendering.
- **Filter bar** — Visitors filter by status, phase, state/province, and country. Works without JavaScript (progressive enhancement).
- **Grid or list view** — Show trials as a responsive card grid or a compact list. The admin sets the default under **Settings → Display → Default view**; visitors get a grid/list toggle and their choice is remembered per browser. The correct view renders server-side, so it works without JavaScript.
- **Optional themes (light & dark)** — Two bundled out-of-the-box front-end skins, **Clinical** (cool, clinical) and **Patient-friendly** (warm), each available in light and dark, alongside the default **Skeleton**. Enable under **Settings → Theme & appearance**. Themes are pure CSS over the existing markup, scoped to the plugin's own listing and single-trial pages (never the surrounding site); web fonts (Public Sans, Newsreader, Mulish) are bundled locally under the SIL Open Font License — no external requests.
- **Single trial pages** — A patient/caregiver-first detail page: status, conditions, sponsor, plain-language summary, eligibility, and a locations map with a collapsible list.
- **Optional plain-language fields (bring your own key)** — With an Anthropic or OpenAI API key, the plugin generates, at sync time, a plain-language summary plus three patient-friendly fields per trial: *What this study is testing*, *Who can join*, and *Questions to ask your doctor*. Generated content is cached as post meta — there are **no LLM calls at page-render time**. You are billed directly by the LLM provider; your key never leaves your site.
- **Optional map view** — Leaflet + OpenStreetMap, bundled locally (no CDN, no Google Maps). Off by default.
- **Safe reconciliation** — A drop-ratio guard prevents accidental bulk removal of trial posts if the upstream feed returns unexpectedly few results.

## Requirements

- WordPress 6.4+
- PHP 7.4+

## Installation

1. Copy this directory into `wp-content/plugins/` (or install the packaged zip).
2. Activate **Clinical Trials Feed** in **Plugins**.
3. Go to **Settings → Clinical Trials Feed**, set your condition(s) and display options, and click **Sync now**.
4. (Optional) Add an Anthropic or OpenAI API key to enable plain-language summaries and the patient-friendly fields, then **Sync now** again to populate them.

## Data source

All clinical trial data is retrieved from **ClinicalTrials.gov**, a service of the U.S. National Library of Medicine. A "Data from ClinicalTrials.gov" credit is always displayed on the front end.

## Privacy

The plugin does not phone home or collect usage data. The only outbound requests are:

- **ClinicalTrials.gov API** — on each sync, from your server, containing only a condition search query.
- **Your chosen LLM provider** (only if you supply an API key) — trial fields are sent to generate summaries; your key is stored in your site and never transmitted to the plugin author.
- **OpenStreetMap tiles** (only if the map view is enabled) — loaded in the visitor's browser to render the map.

## Development

Install dev dependencies and run the checks:

```bash
composer install

composer test:unit      # Brain Monkey unit tests (no WordPress required)
composer phpcs          # WordPress Coding Standards
composer phpcbf         # Auto-fix coding-standard violations
```

### Integration tests

Integration tests run against a real WordPress test database. One-time setup installs the WP test suite and a throwaway database:

```bash
# Standard (requires Subversion):
bash bin/install-wp-tests.sh wordpress_test <db-user> <db-pass> <db-host> latest

# Subversion-free alternative (e.g. Git for Windows / LocalWP):
bash bin/install-wp-tests-git.sh wordpress_test <db-user> <db-pass> <db-host> <wp-version>

export WP_TESTS_DIR=/tmp/wordpress-tests-lib
composer test:integration
```

## License

GPL-2.0-or-later. See [`readme.txt`](readme.txt) for the full plugin header and changelog.
