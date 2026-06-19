# Country Filter, Configurable Map View & Geolocation — Design Spec

**Date:** 2026-06-18
**Version target:** 1.1.0 (minor feature release on top of 1.0.1)
**Status:** Approved (design sections confirmed by user)

## Goal

Add three user-facing capabilities to the Clinical Trials Feed plugin:

1. **Country filter** — visitors can filter the trial list by country (in addition to the existing status / phase / state filters).
2. **Configurable initial map view** — site admins can set a fixed center + zoom for the map instead of always auto-fitting to all markers.
3. **Geolocation** — an optional "Find trials near me" button that re-centers the map on the visitor's location (opt-in, client-side, nothing stored).

## Global Constraints

Copied from the v1 spec; every task inherits these:

- **GPLv2-or-later**, wordpress.org-distributable. No CDN assets, no telemetry, no phone-home.
- Internal prefix `skmctf_`, namespace `SKMCTF\`, text domain `kisho-clinical-trials`.
- PHP 7.4 floor; WordPress 6.4+. PHPCS clean against WordPress + WordPress-Extra + WordPress-Docs + PHPCompatibilityWP.
- Full input sanitization + output escaping; nonces + capability checks on admin writes.
- Two-tier tests: pure-PHP **unit** tests are the in-shell gate; **integration** tests (WP_UnitTestCase) are written but run only in LocalWP.
- No new required external service. Geolocation uses only the browser's `navigator.geolocation`; the visitor's coordinates never leave the browser (no AJAX, cookie, meta write, or log).

## Architecture Overview

```
sync ──> Trial_Repository::upsert()  ──assigns──>  trial_country terms (NEW)
                                                          │
visitor request ──> Trials_Query::args()  ──reads──> tax_query[country] (NEW)
                                                          │
List_Renderer::render() ──> country <select> (NEW) + map default-view + geolocation button (NEW)
                                                          │
map.js ──> initial-view precedence (NEW) + geolocation handler (NEW)
```

The country filter follows the **exact pattern** already used by status/phase (a non-hierarchical taxonomy with `tax_query`). The map changes are additive config flowing through the existing `wp_localize_script( 'skmctfMap', … )` channel.

## Feature 1 — Country Filter (taxonomy)

### Data model

New non-hierarchical taxonomy `trial_country` registered identically to `trial_status` / `trial_phase`:
- `public => true`, `hierarchical => false`, `show_in_rest => true`, `show_admin_column => true`.
- rewrite slug `trial-country`.
- Constant: `Trial_Taxonomies::COUNTRY = 'trial_country'`.

### Term assignment (on sync)

In `Trial_Repository::upsert()`, after the existing status/phase term assignment, extract the **distinct** country names from `$meta['locations']` and assign them:

```php
$countries = array();
foreach ( (array) ( $meta['locations'] ?? array() ) as $loc ) {
    $c = isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
    if ( '' !== $c ) {
        $countries[ $c ] = true; // dedupe by name
    }
}
if ( $countries ) {
    wp_set_object_terms( $id, array_keys( $countries ), Trial_Taxonomies::COUNTRY );
} else {
    wp_set_object_terms( $id, array(), Trial_Taxonomies::COUNTRY ); // clear stale terms
}
```

Semantics: a trial is matched by country X if it has **at least one** location in X (a trial may carry several country terms).

### Query

In `Trials_Query::args()`, add a `country` filter that appends a `tax_query` clause (term name match), mirroring the status/phase clauses:

```php
if ( ! empty( $filters['country'] ) ) {
    $tax[] = array(
        'taxonomy' => Trial_Taxonomies::COUNTRY,
        'field'    => 'name',
        'terms'    => sanitize_text_field( $filters['country'] ),
    );
}
```

### Filter form

In `List_Renderer::render_filter_form()`, add a country `<select>` built from `get_terms( ['taxonomy' => trial_country, 'hide_empty' => true] )` — so only countries that actually have trials appear. Read the submitted value from `$_GET['skmctf_country']` (sanitized) alongside the existing GET params. The country value is preserved in pagination `add_args` like the others.

### Backfill (one-time migration)

Existing trials synced before this release have no country terms. Provide a no-network backfill so admins don't depend on a fresh fetch:

- A WP-CLI-runnable / admin-action method `Trial_Repository::backfill_country_terms(): int` that loops all published `skmctf_trial` posts, reads stored `skmctf_locations` meta, and assigns country terms. Returns count processed.
- Documented in readme: either click **Sync now** (re-upserts everything) or run the backfill once.

## Feature 2 — Configurable Initial Map View

### New options (global setting + block attribute + shortcode att)

| Stored key (snake) | Block attr (camel) | Shortcode att | Type | Valid range |
|---|---|---|---|---|
| `default_lat` | `defaultLat` | `default_lat` | float\|'' | -90 .. 90 |
| `default_lng` | `defaultLng` | `default_lng` | float\|'' | -180 .. 180 |
| `default_zoom` | `defaultZoom` | `default_zoom` | int | 1 .. 19 |

All optional; empty/invalid → unset → existing auto-fit behavior.

### Sanitization (Settings::sanitize)

Add a pure helper `Settings::sanitize_lat( $v ): string` / `sanitize_lng` / `sanitize_zoom` semantics:
- lat/lng: if numeric and in range, store as a normalized string (e.g. `(string) (float) $v`); else store `''` (unset).
- zoom: clamp to [1,19] when a positive int is supplied; else `''` (unset → auto).
- These are **pure** (no WordPress) and unit-tested in the in-shell gate.

### Map data plumbing

`List_Renderer::render()` adds these to the `wp_localize_script( 'skmctfMap', … )` payload:

```php
'view' => array(
    'lat'  => $default_lat,   // float or '' (already sanitized)
    'lng'  => $default_lng,
    'zoom' => $default_zoom,  // int or ''
),
```

### Initial-view precedence (map.js)

Replace the unconditional `fitBounds` with:

```
if (view.lat !== '' && view.lng !== '') {
    map.setView([view.lat, view.lng], view.zoom !== '' ? view.zoom : 8);
} else if (bounds.length === 1) {
    map.setView(bounds[0], 10);
} else if (bounds.length > 1) {
    map.fitBounds(bounds, { padding: [40,40] });
}
```

Auto-fit remains the fallback whenever no default center is configured.

## Feature 3 — Geolocation ("Find trials near me")

### Option

| Stored key | Block attr | Shortcode att | Type |
|---|---|---|---|
| `enable_geolocation` | `enableGeolocation` | `geolocation` ("1"/"true") | bool |

Off by default.

### Render

When geolocation is enabled **and** the map is shown, `List_Renderer::render()` outputs a button above the map container:

```html
<button type="button" class="skmctf-geo-btn" data-skmctf-geo>Find trials near me</button>
<span class="skmctf-geo-status" data-skmctf-geo-status role="status" aria-live="polite"></span>
```

The flag is passed in the localized payload (`'geolocation' => true`).

### Behavior (map.js)

On button click:
1. Guard: if `!navigator.geolocation`, set status text "Location is not available in this browser." and return.
2. Call `navigator.geolocation.getCurrentPosition(success, error)`. (Browser shows its own native permission prompt.)
3. **success**: `map.setView([coords.latitude, coords.longitude], 9)`; drop a distinct "You are here" marker (different from trial markers — use a circle marker or a CSS-classed marker so it's visually separable); set status text "Centered on your location."
4. **error / denied / timeout**: set status text "Location unavailable — showing default view." Map stays where it was. Never breaks.

### Privacy / security

- 100% client-side and ephemeral. No coordinate is sent to the server or stored anywhere.
- Opt-in twice: admin enables the button; visitor approves the browser prompt.
- **Secure-origin requirement:** `navigator.geolocation` only works on HTTPS / localhost. Documented in readme + plan test step (LocalWP plain-HTTP `.local` may block it; production HTTPS works).
- Readme privacy note added: "If you enable the optional 'Find trials near me' button, the visitor's browser location is used only in their browser to re-center the map and is never transmitted or stored."

## Control Surface

All three features are controllable at **two levels**, mirroring `show_map`:
- **Global settings** (Settings → Clinical Trials → new "Map defaults" fieldset) — site-wide defaults.
- **Block attributes** — per-instance override (block value wins when set).
- **Shortcode attributes** — per-instance for shortcode users.

Precedence in `List_Renderer`: explicit block/shortcode att (if provided) → else global setting → else built-in default.

## Components Touched

| File | Change |
|---|---|
| `includes/post-types/class-trial-taxonomies.php` | Add `COUNTRY` const + register `trial_country` |
| `includes/data/class-trial-repository.php` | Assign country terms in `upsert()`; add `backfill_country_terms()` |
| `includes/frontend/class-trials-query.php` | Add `country` → `tax_query` clause |
| `includes/frontend/class-list-renderer.php` | Country `<select>`; read `skmctf_country` GET; map default-view payload; geolocation button + flag |
| `includes/admin/class-settings.php` | Defaults + sanitizers for `default_lat/lng/zoom`, `enable_geolocation`; new pure sanitize helpers |
| `includes/admin/views/settings-page.php` | New "Map defaults" fieldset (lat/lng/zoom inputs + geolocation toggle) |
| `includes/frontend/class-shortcode.php` | New atts: `country`, `default_lat`, `default_lng`, `default_zoom`, `geolocation` |
| `includes/frontend/class-block.php` | Map new camelCase attrs → snake_case in `render()` |
| `blocks/trials/block.json` | Declare new attributes |
| `blocks/trials/edit.js` + rebuild `build/index.js` | Inspector controls for new attrs (SEPARATE task; needs `npm run build`) |
| `assets/js/map.js` | Initial-view precedence chain + geolocation handler + "you are here" marker |
| `assets/css/frontend.css` | Styles for `.skmctf-geo-btn`, `.skmctf-geo-status`, "you are here" marker |
| `readme.txt` | Changelog 1.1.0; geolocation privacy note; backfill instruction; HTTPS caveat |
| `kisho-clinical-trials.php` | `SKMCTF_VERSION` → 1.1.0; header Version → 1.1.0 |
| `languages/*.pot` | Regenerated strings (or new strings appended) |

## Testing Strategy

**Unit (in-shell gate — pure PHP, no WordPress):**
- `Trials_Query::args()` produces a correct `country` tax_query clause when `country` filter is set, and none when absent.
- Settings sanitizers: zoom clamps to [1,19]; out-of-range/zero/non-numeric zoom → `''`. lat in/out of [-90,90]; lng in/out of [-180,180]; non-numeric → `''`. Valid values round-trip as normalized strings.

**Integration (LocalWP — WP_UnitTestCase):**
- `upsert()` assigns the expected distinct country terms (multi-country trial gets all; no-location trial gets none).
- Country filter query returns only trials with a matching country term.
- `backfill_country_terms()` assigns terms to a trial that has `locations` meta but no terms.

**Manual (documented, not automated):**
- Geolocation button on an HTTPS origin: allow → map centers on you + "you are here" marker; deny → status message, map unchanged.
- Block Inspector controls show and persist the new attributes (after rebuild).

## Out of Scope (YAGNI)

- Distance sorting / "X km away" cards (considered, rejected — pagination complexity).
- Filter-aware auto-fit (rejected — fixed default view chosen instead).
- Promoting `state` to a taxonomy (left as-is; only country is promoted this release).
- Marker clustering, list/map split view.

## Build / Rebuild Constraint

`blocks/trials/build/index.js` is webpack-minified output of `@wordpress/scripts`. The new block Inspector controls require `npm run build`. This is isolated to one task; if a Node build environment is unavailable, the core features still function via global settings + shortcode atts + server-side rendering, and the block-control task is deferred with a documented manual `npm run build` step. The non-block paths must not depend on the rebuild.
