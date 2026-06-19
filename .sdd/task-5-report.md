# Task 5 Report — List_Renderer: country filter, map view payload, geolocation button

**Status:** DONE

## Files Changed

- `includes/frontend/class-list-renderer.php`

## Changes Made

### `render()` method
- Added `'country' => ''`, `'default_lat' => ''`, `'default_lng' => ''`, `'default_zoom' => ''`, `'geolocation' => false` to the `array_merge` defaults.
- Added `$get_country` GET read: `sanitize_text_field( wp_unslash( $_GET['skmctf_country'] ) )` with `phpcs:ignore WordPress.Security.NonceVerification.Recommended`.
- Resolved map-view precedence (att → global setting → default ''): `$default_lat`, `$default_lng`, `$default_zoom` via `Settings::sanitize_lat/lng/zoom()` and `Settings::default_lat/lng/zoom()`.
- Resolved geolocation flag: `$geo = ! empty( $atts['geolocation'] ) || Settings::geolocation_enabled()`.
- Added `'country' => $get_country ? $get_country : $atts['country']` to `$filters`.
- Extended `wp_localize_script( 'skmctfMap', … )` payload with `'view' => array( 'lat', 'lng', 'zoom' )` and `'geolocation' => (bool) $geo`.
- When `$show_map && $geo`: outputs `.skmctf-geo` wrapper with `[data-skmctf-geo]` button + `[data-skmctf-geo-status]` span above the map div.
- Added `'skmctf_country' => $filters['country']` to pagination `add_args`.

### `render_filter_form()` method
- Added `get_terms()` call for `Trial_Taxonomies::COUNTRY` (hide_empty, orderby name).
- Added `is_wp_error` guard; result stored in `$countries`.
- Added country `<select>` (id `skmctf-filter-country`, name `skmctf_country`) with "All countries" default option + per-term options using `selected()`, `esc_attr()`, `esc_html()`.
- Added `$current['country']` to the reset-link condition check.
- Added `'skmctf_country'` to the `remove_query_arg()` array in the reset link.

## Test / PHPCS Output

`composer phpcs -- includes/frontend/class-list-renderer.php` → **0 errors, 0 warnings** (1 file checked, clean).
