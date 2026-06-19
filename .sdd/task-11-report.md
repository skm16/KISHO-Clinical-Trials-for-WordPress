# Task 11 Report — Docs + Version Bump

## Files Changed

1. `kisho-clinical-trials.php`
   - Plugin header `Version:` bumped from `1.0.1` to `1.1.0`
   - `SKMCTF_VERSION` constant bumped from `'1.0.1'` to `'1.1.0'`

2. `readme.txt`
   - `Stable tag:` bumped from `1.0.1` to `1.1.0`
   - Features bullet updated to include "country" in the filter bar description
   - New External services entry **3. Browser Geolocation API** added (client-side only, never stored/transmitted, HTTPS caveat)
   - New FAQ entry "Does the geolocation feature store or share my location?" added
   - Changelog `= 1.1.0 =` added: country filter, configurable map view, optional client-side geolocation, backfill note, JS-strings-English-only note
   - Upgrade Notice `= 1.1.0 =` added: mentions country terms backfill instruction and HTTPS requirement

## Test / PHPCS Output

No PHPCS run required for this task (changes are limited to the plugin header PHP file and readme.txt — no PHP logic was modified). Manual verification confirms both version strings read `1.1.0` and `readme.txt` Stable tag matches.
