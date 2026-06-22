# List vs. Card-Grid View Toggle — Design Spec

**Date:** 2026-06-22
**Status:** Approved (brainstorming complete)
**Plugin:** kisho-clinical-trials, currently v1.2.0

## 1. Goal

Let the trials listing render either as the current **card grid** or as a compact **list** of
full-width rows. An admin picks the default; a front-end toggle lets visitors switch, and their choice
is remembered per browser. Purely additive — no markup or class changes to the existing cards; list view
is a CSS restyle keyed off a wrapper modifier, so it inherits all themes × light/dark for free.

## 2. Decisions (locked during brainstorming)

| # | Decision | Choice |
|---|---|---|
| 1 | Control model | **Admin default + visitor toggle**, remembered in `localStorage`. |
| 2 | List layout | **Compact horizontal rows** — title + status on one line, inline phase·conditions·sponsor·locations meta, summary clamped to ~2 lines. Same data + `.skmctf-card__*` classes, restyled. |
| 3 | Columns interaction | **List ignores `columns`; grid keeps it.** `columns` (1–3) remains a grid-only refinement. |
| 4 | Block per-instance control | **Out of scope for now.** The block's `columns` attribute is untouched; no new per-block "view" attribute this round. |

## 3. Architecture

Mirrors the theme feature's patterns (single source of truth in `Settings`, a wrapper modifier class,
token-driven CSS, conditional asset enqueue, progressive-enhancement JS).

```
[skmctf_settings: default_view]  ──► Settings::default_view()  ('grid'|'list')
        │
        ▼
  List_Renderer::render()
        ├─ emits view modifier on the <ul>: .skmctf-list--view-grid | --view-list
        │   + data-skmctf-view="grid|list"
        │   (grid also emits .skmctf-list--cols-N as today; list omits --cols-N)
        ├─ renders the toggle control (.skmctf-view-toggle) above the list
        └─ Assets::enqueue() also enqueues view-toggle.js
                                   │
                                   ▼
                    view-toggle.js (no deps, ~30 lines)
                    - on click: swap --view-* class + data-skmctf-view,
                      update aria-pressed, persist localStorage 'skmctf_view'
                    - on load: apply stored preference if present
```

## 4. Components & files

### Modify
- `includes/admin/class-settings.php` — add `VALID_VIEWS = ['grid','list']`; default `'default_view' => 'grid'`;
  getter `default_view(): string` (whitelist via `in_array`, mirrors `theme()`); sanitize branch.
- `includes/admin/views/settings-page.php` — add a **"Default view"** `<select>` (Grid / List) in the
  **Display** section (it's a display concern; keeps Theme & appearance focused on theming), same
  `selected()`-loop pattern.
- `includes/frontend/class-list-renderer.php` —
  - read `Settings::default_view()`; build the view modifier class for the `.skmctf-list` `<ul>` and a
    `data-skmctf-view` attribute; when view is `list`, do **not** append `--cols-N`.
  - render the toggle control (`render_view_toggle()`) just above the `<ul>` (near the geo/results area).
- `includes/frontend/class-assets.php` — register + enqueue `assets/js/view-toggle.js` (handle
  `skmctf-view-toggle`) inside `enqueue()`, alongside the existing filter script. Pass an i18n'd label set
  via `wp_localize_script` if needed (button labels are server-rendered, so likely not required).
- `assets/css/frontend.css` — add the `.skmctf-list--view-list` block (token-driven) and
  `.skmctf-view-toggle` styling. List rows reuse `.skmctf-card` + `.skmctf-card__*`, restyled to a
  horizontal flex row: tightened radius/padding, summary clamped to 2 lines, meta inline, softened hover.

### Create
- `assets/js/view-toggle.js` — progressive-enhancement toggle (class swap + `aria-pressed` + localStorage).
- Tests: extend `tests/unit/SettingsThemeTest.php` (or a new `SettingsViewTest.php`) for
  `default_view()` whitelist + sanitize; extend `tests/integration/ThemeRenderTest.php`
  (or new `ViewRenderTest.php`) for the emitted modifier class + columns interaction.

## 5. Toggle UI & accessibility

- A `.skmctf-view-toggle` group of two real `<button type="button">` controls ("Grid view" / "List view"),
  each with an icon + visually-hidden or visible text label and `aria-pressed="true|false"`. Keyboard
  operable, focus-visible (reuse the existing `outline: 3px solid var(--primary)` focus style).
- Server renders the buttons with the admin default pre-pressed, so the correct view shows with **no JS**
  (the `<ul>` already has the right `--view-*` class server-side). JS only adds interactivity + persistence.
- Status/view is conveyed by text + `aria-pressed`, not color alone (WCAG AA, consistent with the plugin).

## 6. JS behavior (`view-toggle.js`)

- Self-contained IIFE, `'use strict'`, no dependencies (consistent with `map.js`/`filters.js`).
- On `DOMContentLoaded`: for each `.skmctf-view-toggle`, read `localStorage['skmctf_view']`; if it's a
  valid view and differs from the current `data-skmctf-view` on the associated `.skmctf-list`, apply it
  (swap `--view-grid`/`--view-list`, set `data-skmctf-view`, update both buttons' `aria-pressed`).
- On button click: apply the chosen view to the `.skmctf-list`, update `aria-pressed`, write
  `localStorage['skmctf_view']`. Wrapped in try/catch so a disabled/again localStorage never breaks the page.
- Multiple lists on one page each pair with their own toggle (scope by a shared wrapper or `data-` id),
  consistent with how `map.js` scopes per-instance.

## 7. CSS (token-driven, theme-inheriting)

- `.skmctf-list--view-list` overrides the grid: `display: flex; flex-direction: column; gap`. Its
  `.skmctf-card` children become horizontal rows (`flex-direction: row` on the inner `> article`, or a
  row layout of header/body), with reduced radius/shadow, a 2-line clamped `.skmctf-card__summary`, and
  an inline meta line for phase/conditions/sponsor/locations. Hover becomes a subtle background/border
  change rather than a lift.
- All colors/radii via existing tokens (`--surface --border --ink --ink-2 --radius* --primary*`), so the
  list view automatically themes for skeleton/clinical/warm × light/dark with no per-theme additions.
- `.skmctf-view-toggle` styled with tokens; active button uses `--primary`/`--on-primary`.

## 8. Testing

- **Unit:** `Settings::default_view()` returns `grid` by default, accepts `grid`/`list`, rejects junk;
  sanitizer whitelists the field (mirrors the theme sanitizer test).
- **Integration:** with `default_view=list`, `List_Renderer::render()` output contains
  `skmctf-list--view-list`, `data-skmctf-view="list"`, and NO `skmctf-list--cols-`; with `default_view=grid`
  and `columns=2`, output contains `skmctf-list--view-grid` and `skmctf-list--cols-2`; the toggle markup
  (two buttons, correct `aria-pressed`) is present; the view-toggle JS handle enqueues.
- **Standards:** `composer phpcs` clean; all output escaped.
- **Manual (Playwright on plugin-sandbox.local):** load the trials archive; confirm grid and list both
  render correctly in one theme (light + dark); click the toggle and confirm the layout switches and the
  choice persists across reload (localStorage); confirm no-JS fallback shows the admin default.

## 9. Out of scope (YAGNI)

- Per-block "view" attribute / block sidebar control (the block keeps only its `columns` attribute).
- A third "minimal rows" density; sortable columns; saving the preference server-side per user.
- Any change to single-trial pages, filters, map, or data sync.
