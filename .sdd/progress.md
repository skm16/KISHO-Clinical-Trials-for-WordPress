# v1.1.0 feature build — progress ledger

Plan: docs/superpowers/plans/2026-06-18-country-filter-map-view-geolocation.md
Spec: docs/superpowers/specs/2026-06-18-country-filter-map-view-geolocation-design.md

## Tasks (via Workflow wf_35b7eafe-15e, then controller)
- Task 1 (taxonomy): complete — trial_country registered
- Task 2 (repo-terms): complete — country terms on upsert + backfill_country_terms()
- Task 3 (query): complete — country tax_query clause + unit tests
- Task 4 (settings): complete — default_lat/lng/zoom + enable_geolocation, pure sanitizers + unit tests
- Task 5 (renderer): complete — country select, map view payload, geo button (is_ssl gated)
- Task 6 (shortcode): complete — new atts
- Task 7 (block-php): complete — block.json attrs + Block::render mapping
- Task 8 (map-js): complete — view precedence chain + geolocation handler, NO network calls (verified)
- Task 9 (css): complete — geo button + you-are-here marker styles
- Task 10 (block rebuild): complete — controller ran npm run build; build/index.js regenerated (5263 bytes)
- Task 11 (docs-version): complete — 1.1.0 bump, readme changelog + OSM/geo external services + privacy notes

## Review (4-dimension adversarial, parallel)
- 21 findings total, 12 critical/important — all 12 fixed in consolidated pass
- Notable fixes: geo button gated on is_ssl(); OSM tile server disclosed as 4th external service;
  settings-page.php Map View Configuration fieldset added; map.js world-view fallback; Yoda fix

## Verification (controller, independent)
- composer test:unit: OK 59/59 (159 assertions)
- composer phpcs: 0 errors / 0 warnings (43 files)
- map.js privacy: 0 fetch/XHR/sendBeacon/ajax/localStorage/cookie occurrences (coordinates never leave browser)
- block bundle contains new attributes (enableGeolocation/defaultZoom/Find trials near me)

## Status: COMPLETE — working tree, not committed. Still v1.0.x on git master.
## Pending: one-time country backfill on existing trials (Sync now OR backfill_country_terms()).
