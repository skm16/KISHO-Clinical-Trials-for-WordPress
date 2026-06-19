# Task 9 Report — CSS: geolocation button + "you are here" marker

## Files Changed

- `assets/css/frontend.css` — added two new sections between the Map section and the Empty/error states section.

## What Was Added

### `.skmctf-geo` (wrapper div)
- `display: flex; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-block-end: 0.75rem`
- Logical property (`margin-block-end`) consistent with existing conventions.

### `.skmctf-geo-btn` (button)
- Outlined/ghost style (transparent background, `#005fcc` border/text) to visually differentiate it from the filled `.skmctf-filters__submit`.
- `transition: background-color 0.2s ease, color 0.2s ease` with `prefers-reduced-motion: reduce` override (matches existing submit button pattern).
- `:hover`/`:focus` state: inverted fill + `outline: 3px solid #005fcc; outline-offset: 2px` (identical focus outline convention used throughout the file).

### `.skmctf-geo-status` (live region span)
- `font-size: 0.875rem; color: #555` — muted secondary text, consistent with `.skmctf-filters__reset`.

### `.skmctf-you-are-here path` (Leaflet circleMarker SVG)
- `fill: #c0392b; stroke: #7f1d1d; stroke-width: 2` — a distinct red colour (vs default Leaflet blue) to make the "you are here" marker visually distinct from trial markers.

## Test / PHPCS Output

CSS is not checked by PHPCS (PHP-only linter). No PHP files were modified in this task. No PHPCS run required.
