# Task 7 Report — Block Attributes + PHP Mapping

## Files Changed

- `blocks/trials/block.json` — added 5 new attributes: `country` (string), `defaultLat` (string), `defaultLng` (string), `defaultZoom` (string), `enableGeolocation` (boolean).
- `includes/frontend/class-block.php` — extended `render()` to map the 5 new camelCase block attributes to their snake_case equivalents passed to `List_Renderer::render()`.

## Verification

- **block.json JSON validity:** `php -r "json_decode(...)"` → `JSON_ERROR_NONE` (valid).
- **PHPCS:** `composer phpcs -- includes/frontend/class-block.php` → 0 errors, 0 warnings.
