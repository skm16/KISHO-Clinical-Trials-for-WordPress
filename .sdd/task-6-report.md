# Task 6 Report — Shortcode Atts

## Files Changed

- `includes/frontend/class-shortcode.php`
  - Added five new `shortcode_atts` defaults: `country`, `default_lat`, `default_lng`, `default_zoom`, `geolocation` (all empty strings; renderer normalises truthiness for `geolocation`).
  - Expanded the file-level usage docblock with two new usage examples and a full attribute reference table.
  - `List_Renderer::render( $atts )` call is unchanged — it already receives the full `$atts` array, so the new keys flow through automatically.

## Test / PHPCS Output

`composer phpcs -- includes/frontend/class-shortcode.php` → 0 errors, 0 warnings (1 file, 1.73 s).
