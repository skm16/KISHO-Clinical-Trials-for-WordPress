# Country Filter, Configurable Map View & Geolocation — Implementation Plan

> **For agentic workers:** Implement task-by-task. Tasks are dependency-ordered and MUST be executed sequentially (many touch the same files). Each task ends with tests + PHPCS where applicable.

**Goal:** Add a country filter (taxonomy), a configurable initial map view, and an opt-in client-side geolocation button to the Clinical Trials Feed plugin (v1.1.0).

**Architecture:** Country = new `trial_country` taxonomy assigned on sync, filtered via `tax_query` (mirrors status/phase). Map view + geolocation = additive config flowing through the existing `wp_localize_script('skmctfMap', …)` channel and handled in `map.js`. Two control levels: global settings + block/shortcode attrs.

**Tech Stack:** WordPress CPT/taxonomy, Settings API, Gutenberg block (server-rendered), vanilla JS, Leaflet 1.9.4 (bundled), PHPUnit + Brain Monkey.

## Global Constraints

- GPLv2+, .org-distributable, no CDN, no telemetry. Prefix `skmctf_`, namespace `SKMCTF\`, text domain `kisho-clinical-trials`.
- PHP 7.4 floor; PHPCS clean (WordPress + Extra + Docs + PHPCompatibilityWP).
- Full sanitize/escape; nonces + caps on admin writes.
- Geolocation: client-side only, nothing stored or transmitted.
- Country filter semantics: trial matches country X if it has ≥1 location in X (multi-country terms allowed).
- Map view precedence: explicit block/shortcode att → global setting → built-in default (auto-fit).
- Reference existing patterns: status/phase taxonomy assignment in `Trial_Repository::upsert()`, the `state` filter in `Trials_Query`, the `show_map` plumbing through block→renderer→localize.

---

## Task 1: Register `trial_country` taxonomy

**Files:**
- Modify: `includes/post-types/class-trial-taxonomies.php`

**Steps:**
- [ ] Add `public const COUNTRY = 'trial_country';` next to `STATUS`/`PHASE`.
- [ ] Add it to the registration loop with label `__( 'Trial Country', 'kisho-clinical-trials' )`, identical args to status/phase (`public`, `hierarchical => false`, `show_admin_column`, `show_in_rest`, rewrite slug `trial-country`).
- [ ] PHPCS the file: `composer phpcs -- includes/post-types/class-trial-taxonomies.php` → 0 errors.

**Note:** This is registered on `init` already via the existing `Trial_Taxonomies::register` hook — no boot change needed.

---

## Task 2: Assign country terms on upsert + backfill method

**Files:**
- Modify: `includes/data/class-trial-repository.php`
- Test: `tests/integration/TrialRepositoryTest.php` (add cases)

**Steps:**
- [ ] In `upsert()`, after the existing `wp_set_object_terms( … PHASE )` block, add distinct-country assignment:
```php
$countries = array();
foreach ( (array) ( $meta['locations'] ?? array() ) as $loc ) {
    $c = is_array( $loc ) && isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
    if ( '' !== $c ) {
        $countries[ $c ] = true;
    }
}
wp_set_object_terms( $id, array_keys( $countries ), Trial_Taxonomies::COUNTRY );
```
  (Assigning an empty array clears stale terms — desired.)
- [ ] Add public method:
```php
public function backfill_country_terms(): int {
    $ids = get_posts( array(
        'post_type'      => Trial_Post_Type::POST_TYPE,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ) );
    $count = 0;
    foreach ( $ids as $id ) {
        $locations = get_post_meta( $id, Trial_Meta::KEYS['locations'], true );
        $countries = array();
        foreach ( (array) $locations as $loc ) {
            $c = is_array( $loc ) && isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
            if ( '' !== $c ) {
                $countries[ $c ] = true;
            }
        }
        wp_set_object_terms( (int) $id, array_keys( $countries ), Trial_Taxonomies::COUNTRY );
        ++$count;
    }
    return $count;
}
```
- [ ] Integration test: upsert a 2-country trial → assert both terms present; upsert a 0-location trial → assert no terms; backfill on a trial with locations meta but no terms → assert terms appear. (These run in LocalWP.)
- [ ] PHPCS the file → 0 errors.

---

## Task 3: Country filter in Trials_Query

**Files:**
- Modify: `includes/frontend/class-trials-query.php`
- Test: `tests/unit/TrialsQueryArgsTest.php` (add cases)

**Steps:**
- [ ] In `args()`, after the phase tax clause, add:
```php
if ( ! empty( $filters['country'] ) ) {
    $tax[] = array(
        'taxonomy' => Trial_Taxonomies::COUNTRY,
        'field'    => 'name',
        'terms'    => sanitize_text_field( $filters['country'] ),
    );
}
```
- [ ] Unit test (pure, no WP — follows existing TrialsQueryArgsTest doubles): assert that a `country` filter adds a tax_query entry with taxonomy `trial_country`, field `name`; assert no country clause when filter absent/empty.
- [ ] Run `composer test:unit` → all pass.
- [ ] PHPCS → 0 errors.

---

## Task 4: Settings — new keys, defaults, pure sanitizers

**Files:**
- Modify: `includes/admin/class-settings.php`
- Test: `tests/unit/SettingsSanitizeTest.php` (add cases) + a new pure helper test if helpers are static.

**Steps:**
- [ ] Add to `defaults()`: `'default_lat' => '', 'default_lng' => '', 'default_zoom' => '', 'enable_geolocation' => false`.
- [ ] Add typed getters: `default_lat(): string`, `default_lng(): string`, `default_zoom(): string`, `geolocation_enabled(): bool`.
- [ ] Add pure static sanitizers (no WordPress calls so they unit-test in-shell):
```php
public static function sanitize_lat( $v ): string {
    if ( '' === $v || ! is_numeric( $v ) ) { return ''; }
    $f = (float) $v;
    return ( $f >= -90.0 && $f <= 90.0 ) ? (string) $f : '';
}
public static function sanitize_lng( $v ): string {
    if ( '' === $v || ! is_numeric( $v ) ) { return ''; }
    $f = (float) $v;
    return ( $f >= -180.0 && $f <= 180.0 ) ? (string) $f : '';
}
public static function sanitize_zoom( $v ): string {
    if ( '' === $v || ! is_numeric( $v ) ) { return ''; }
    $i = (int) $v;
    return ( $i >= 1 && $i <= 19 ) ? (string) $i : '';
}
```
- [ ] In `sanitize()`, wire the three map-view keys through these helpers and `$out['enable_geolocation'] = ! empty( $input['enable_geolocation'] );`.
- [ ] Unit tests: zoom 5→'5', 0→'', 50→'', 'abc'→''; lat 42.3→'42.3', 91→'', -90→'-90', ''→''; lng -71→'-71', 181→'', 'x'→''.
- [ ] `composer test:unit` → pass. PHPCS → 0 errors.

---

## Task 5: List_Renderer — country dropdown, map view payload, geolocation button

**Files:**
- Modify: `includes/frontend/class-list-renderer.php`

**Steps:**
- [ ] Normalize new atts in `render()`: add `'country' => ''`, `'default_lat' => ''`, `'default_lng' => ''`, `'default_zoom' => ''`, `'geolocation' => false` to the `array_merge` defaults.
- [ ] Resolve precedence (att → global setting): e.g. `$default_lat = '' !== (string) $atts['default_lat'] ? Settings::sanitize_lat( $atts['default_lat'] ) : Settings::default_lat();` (same for lng/zoom). Geolocation: `$geo = ! empty( $atts['geolocation'] ) || Settings::geolocation_enabled();`.
- [ ] Read GET: `$get_country = isset( $_GET['skmctf_country'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_country'] ) ) : '';` (add the NonceVerification phpcs ignore comment like the others). Add `'country' => $get_country ? $get_country : $atts['country']` to `$filters`.
- [ ] Add the country `<select>` to `render_filter_form()`: load `get_terms( ['taxonomy' => Trial_Taxonomies::COUNTRY, 'hide_empty' => true, 'orderby' => 'name'] )`, guard `is_wp_error`, render an "All countries" default option + one per term, `selected()` against `$current['country']`. Name `skmctf_country`.
- [ ] Preserve country in pagination `add_args` and in the reset-filters `remove_query_arg` list.
- [ ] Extend the `wp_localize_script( …, 'skmctfMap', … )` payload with:
```php
'view'        => array(
    'lat'  => $default_lat,
    'lng'  => $default_lng,
    'zoom' => $default_zoom,
),
'geolocation' => (bool) $geo,
```
- [ ] When `$show_map && $geo`, output the geolocation button + status span ABOVE the `.skmctf-map` div:
```php
echo '<div class="skmctf-geo">';
echo '<button type="button" class="skmctf-geo-btn" data-skmctf-geo>'
    . esc_html__( 'Find trials near me', 'kisho-clinical-trials' ) . '</button>';
echo '<span class="skmctf-geo-status" data-skmctf-geo-status role="status" aria-live="polite"></span>';
echo '</div>';
```
- [ ] PHPCS → 0 errors. (No unit test here — covered by integration + manual; logic is wiring.)

---

## Task 6: Shortcode atts

**Files:**
- Modify: `includes/frontend/class-shortcode.php`

**Steps:**
- [ ] Add to `shortcode_atts` defaults: `'country' => ''`, `'default_lat' => ''`, `'default_lng' => ''`, `'default_zoom' => ''`, `'geolocation' => ''`.
- [ ] Pass them into `List_Renderer::render( $atts )` (already passes `$atts`; map `geolocation` truthiness through — the renderer treats non-empty as true).
- [ ] Update the usage docblock examples to include `country="United States"` and `geolocation="1"`.
- [ ] PHPCS → 0 errors.

---

## Task 7: Block PHP attribute mapping + block.json

**Files:**
- Modify: `includes/frontend/class-block.php`
- Modify: `blocks/trials/block.json`

**Steps:**
- [ ] In `block.json` `attributes`, add: `"country": {"type":"string","default":""}`, `"defaultLat": {"type":"string","default":""}`, `"defaultLng": {"type":"string","default":""}`, `"defaultZoom": {"type":"string","default":""}`, `"enableGeolocation": {"type":"boolean","default":false}`.
- [ ] In `Block::render()`, map them into the snake_case array passed to `List_Renderer::render()`:
```php
'country'      => $attributes['country'] ?? '',
'default_lat'  => $attributes['defaultLat'] ?? '',
'default_lng'  => $attributes['defaultLng'] ?? '',
'default_zoom' => $attributes['defaultZoom'] ?? '',
'geolocation'  => ! empty( $attributes['enableGeolocation'] ),
```
- [ ] PHPCS the PHP file → 0 errors. (block.json is JSON — validate it parses.)

---

## Task 8: map.js — initial-view precedence + geolocation handler

**Files:**
- Modify: `assets/js/map.js`

**Steps:**
- [ ] Read `var view = data.view || {};` and `var geoEnabled = !!data.geolocation;`.
- [ ] Replace the final `fitBounds`/`setView` block with the precedence chain:
```js
if ( view.lat !== '' && view.lng !== '' && view.lat != null && view.lng != null ) {
    var z = ( view.zoom !== '' && view.zoom != null ) ? parseInt( view.zoom, 10 ) : 8;
    map.setView( [ parseFloat( view.lat ), parseFloat( view.lng ) ], z );
} else if ( bounds.length === 1 ) {
    map.setView( bounds[ 0 ], 10 );
} else if ( bounds.length > 1 ) {
    map.fitBounds( L.latLngBounds( bounds ), { padding: [ 40, 40 ] } );
}
```
- [ ] Add geolocation wiring (only when `geoEnabled`): find `[data-skmctf-geo]` button + `[data-skmctf-geo-status]` span. On click:
  - if `!navigator.geolocation` → status "Location is not available in this browser." return.
  - else `navigator.geolocation.getCurrentPosition(onOk, onErr)`.
  - `onOk(pos)`: `map.setView([pos.coords.latitude, pos.coords.longitude], 9)`, add a visually-distinct "you are here" marker (e.g. `L.circleMarker([lat,lng], { radius: 8, className: 'skmctf-you-are-here' })` with a popup "You are here"), status "Centered on your location."
  - `onErr()`: status "Location unavailable — showing default view." (map unchanged).
  - All user-facing strings: keep them as plain JS strings (the plugin localizes PHP, not JS, in v1; acceptable for v1.1.0 — note in readme that JS strings are English). 
- [ ] Guard: button wiring must run even when there are points; it does not depend on bounds.

---

## Task 9: CSS — geolocation button + "you are here" marker

**Files:**
- Modify: `assets/css/frontend.css`

**Steps:**
- [ ] Add `.skmctf-geo` wrapper (margin), `.skmctf-geo-btn` (button styling consistent with `.skmctf-filters__submit`), `.skmctf-geo-status` (muted text, margin-left).
- [ ] Add `.skmctf-you-are-here` styling for the circle marker (e.g. fill + stroke color distinct from default blue markers).
- [ ] Keep within existing style conventions (logical properties, focus outlines).

---

## Task 10: Block editor controls + rebuild (CONDITIONAL on Node build env)

**Files:**
- Modify: `blocks/trials/edit.js`
- Rebuild: `blocks/trials/build/index.js` (via `npm run build`)

**Steps:**
- [ ] Add to `edit.js`: a country `TextControl` in Filter Settings; a new "Map Settings" `PanelBody` with `TextControl` for defaultLat/defaultLng (help: numeric), `RangeControl` for defaultZoom (min 1 max 19 — but allow empty by using a TextControl if RangeControl can't represent "unset"; use TextControl with numeric help to permit blank), and a `ToggleControl` for enableGeolocation. Destructure the new attrs from `attributes`.
- [ ] Run `npm install` (if needed) then `npm run build`. Verify `build/index.js` regenerated.
- [ ] **If Node build environment is unavailable:** STOP this task, leave `edit.js` updated, and record in the report that `build/index.js` needs a manual `npm run build`. The feature still works via global settings + shortcode; do NOT hand-edit the minified bundle.

---

## Task 11: Docs + version bump

**Files:**
- Modify: `kisho-clinical-trials.php` (header `Version` + `SKMCTF_VERSION` → 1.1.0)
- Modify: `readme.txt` (Stable tag → 1.1.0; changelog 1.1.0; upgrade notice; geolocation privacy note in External services / FAQ; backfill instruction; HTTPS caveat)

**Steps:**
- [ ] Bump both version locations to `1.1.0`.
- [ ] readme `Stable tag: 1.1.0`.
- [ ] Changelog `= 1.1.0 =`: country filter; configurable map view; optional client-side geolocation; one-time country backfill note.
- [ ] Add privacy sentence about geolocation (client-side, never stored/transmitted) and the HTTPS requirement.
- [ ] Add upgrade notice for 1.1.0 mentioning the one-time re-sync / backfill to populate country terms.

---

## Final Verification

- [ ] `composer test:unit` → all green (existing 25 + new query/sanitizer tests).
- [ ] `composer phpcs` → 0 errors / 0 warnings.
- [ ] Confirm `block.json` parses and lists new attributes.
- [ ] Confirm no new external/CDN resource and no coordinate is sent server-side (grep map.js for fetch/XHR → none).
- [ ] Report which tasks completed and whether the block rebuild (Task 10) ran or is deferred.
