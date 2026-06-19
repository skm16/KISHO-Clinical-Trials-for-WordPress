# Task 3 Report — Country tax_query clause + unit tests

## Files Changed

- `includes/frontend/class-trials-query.php` — Added `country` to the `@param` docblock filter list; added the `trial_country` tax_query clause after the existing `phase` clause (mirrors status/phase pattern exactly).
- `tests/unit/TrialsQueryArgsTest.php` — Added 4 new pure unit-test cases: country filter adds tax_query entry with taxonomy `trial_country` and `field => name`; absent country filter has no `trial_country` clause; empty string country filter has no `trial_country` clause; country + status together use `AND` relation.

## Test Output

`composer test:unit` — OK (29 tests, 129 assertions) — all green.

## PHPCS Output

`composer phpcs -- includes/frontend/class-trials-query.php` — 0 errors, 0 warnings.
(Test file is excluded from PHPCS scope per project phpcs.xml.dist — no output = pass.)
