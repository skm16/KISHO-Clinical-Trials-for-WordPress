# Clinical Trial Themes — Design Spec

**Date:** 2026-06-22
**Status:** Approved (brainstorming complete)
**Plugin:** kisho-clinical-trials (Clinical Trials Feed), currently v1.1.3
**Design source:** Claude Design project `2099f5cb-3182-4c38-a436-cf2c8ee560ae` — `Clinical Trial Themes.dc.html`

## 1. Goal

Ship two distinct, out-of-the-box visual **themes** for the plugin's front end — **Clinical** (cool,
sans-serif, tight radii) and **Patient-friendly / Warm** (cream, green, serif headings, rounded) — each
available in **light** and **dark** modes. An admin optionally enables a theme on the settings page;
the default is the existing look (**Skeleton**), now token-driven and offered in light + dark too.

The entire feature is a **CSS-skin layer over unchanged markup**. The design was authored directly on
the plugin's real HTML output (the design project's `uploads/listings.html` and `uploads/single.html`
are live dumps from `plugin-sandbox.local`), so every class the design styles
(`.skmctf-card`, `.skmctf-trial__purpose`, `.skmctf-badge--recruiting`, …) already matches what
`List_Renderer`, `templates/archive-skmctf_trial.php`, and `templates/single-skmctf_trial.php` emit.
**No PHP template or renderer changes its HTML structure.**

## 2. Decisions (locked during brainstorming)

| # | Decision | Choice |
|---|---|---|
| 1 | Theme scope | **Plugin markup only** — skin class + mode attr on the plugin's own wrappers; never `<body>`. Won't fight the active WordPress theme. |
| 2 | Light/dark | **Admin picks a fixed mode.** Every visitor sees it. (`prefers-color-scheme` "Auto" is a possible later follow-on — the token architecture makes it cheap, but it is out of scope now.) |
| 3 | Web fonts | **Bundle locally** in `assets/fonts/` and `@font-face` from our own CSS. Zero external requests; GDPR / wordpress.org compliant. Enqueued per active theme. |
| 4 | CSS structure | **Shared structure + per-theme token files.** One structural `frontend.css` reading `var(--…)`, loaded always; each theme is a tokens-only file enqueued only when active. |
| 5 | Skeleton look | **Adopt the design's refined structure as the new baseline for all themes.** Skeleton = neutral token set on that structure. Existing sites' trials pages visibly upgrade on update (CSS-only; markup/classes unchanged). |
| 6 | Skeleton dark | **Skeleton supports light + dark too** (neutral slate/gray dark block). The Appearance toggle always applies, for all three themes. |

## 3. Architecture

`Settings::theme()` / `Settings::theme_mode()` →  `Frontend\Theme` helper (single source of truth) →
produces (a) a skin **class** string, (b) a `data-skmctf-mode` **attribute**, (c) the set of CSS/font
**handles** to enqueue. Renderers/templates append the class + attribute to their wrappers; `Assets`
conditionally enqueues the active theme's token CSS and font CSS when the renderer runs.

```
[skmctf_settings option]
        │  theme, theme_mode
        ▼
  Frontend\Theme  ──► skin_class()   "" | skmctf-skin--clinical | skmctf-skin--warm
        │           ─► mode()        light | dark
        │           ─► wrapper_attrs()  data-skmctf-mode="dark"
        │           ─► css/font handles to enqueue
        ├────────────────────────────┬───────────────────────────┐
        ▼                            ▼                           ▼
  List_Renderer            archive/single templates          Assets
  (.skmctf-trials-wrap)    (<main> wrappers)            (enqueue themes/<t>.css
   append class+attr        append class+attr            + fonts-<t>.css)
```

**Compliance (unchanged invariants):** GPLv2-or-later; no CDN assets; no phone-home/telemetry; all
output escaped at point of output; settings save path, nonce, and `manage_options` check are reused as-is
(no new write path). WCAG 2.1 AA preserved — token contrast pairs are designed; trial status is still
conveyed by text + dot icon, not color alone.

## 4. Components & files

### New
- `includes/frontend/class-theme.php` — `SKMCTF\Frontend\Theme`. The resolver. Consumers call this; they
  never read theme settings directly.
  - `const SKINS = ['skeleton','clinical','warm']`, `const MODES = ['light','dark']`
  - `active_theme(): string` (from `Settings::theme()`, validated)
  - `mode(): string` (from `Settings::theme_mode()`, validated)
  - `skin_class(): string` — `''` for skeleton, else `skmctf-skin--clinical` / `skmctf-skin--warm`
  - `wrapper_attrs(): string` — escaped `data-skmctf-mode="…"` (and any future data attrs, e.g. primary color for the map marker)
  - `primary_color(): string` — active theme+mode primary hex, for the map marker (replaces the design's hardcoded per-theme value)
- `assets/css/themes/clinical.css` — `.skmctf-skin--clinical { … light tokens … }` + `.skmctf-skin--clinical[data-skmctf-mode="dark"] { … dark tokens … }`
- `assets/css/themes/warm.css` — same shape for `.skmctf-skin--warm`
- `assets/css/fonts-clinical.css` — `@font-face` for Public Sans (local woff2)
- `assets/css/fonts-warm.css` — `@font-face` for Newsreader + Mulish (local woff2)
- `assets/fonts/*.woff2` — bundled font files (the weights the design actually uses)

### Changed
- `assets/css/frontend.css` — **largest piece.** Refactor to the design's structural CSS, referencing
  `var(--…)` for everything theme-variable. Declare **neutral skeleton tokens** (light) as the
  plugin-scope defaults, plus a **neutral dark** block scoped under the plugin wrappers.
- `includes/frontend/class-assets.php` — when the renderer enqueues, also enqueue the active theme's
  `themes/<theme>.css` + `fonts-<theme>.css` (skeleton: neither — structure already carries neutral
  tokens). Map dark-tile CSS lives in `frontend.css` under `[data-skmctf-mode="dark"]`.
- `includes/frontend/class-list-renderer.php` — append `Theme::skin_class()` + `Theme::wrapper_attrs()`
  to the `.skmctf-trials-wrap` element.
- `templates/archive-skmctf_trial.php` — append skin class + mode attr to `<main class="site-main skmctf-archive-trials">`.
- `templates/single-skmctf_trial.php` — append skin class + mode attr to `<main class="site-main skmctf-single-trial">`.
- `assets/js/map.js` — read the active theme's primary color from a `data-` attribute (emitted by the
  renderer via `Theme::primary_color()`) for the marker fill, instead of a hardcoded color. Minimal,
  additive; falls back to current default if absent.
- `includes/admin/class-settings.php` — add `theme` / `theme_mode` to defaults, constants
  (`VALID_THEMES`, `VALID_THEME_MODES`), getters (`theme()`, `theme_mode()`), and sanitize branches
  (whitelist via `in_array(..., true)`, mirroring `provider`).
- `includes/admin/class-settings-page.php` + `includes/admin/views/settings-page.php` — new
  "Theme & appearance" section with two `<select>` controls (existing `selected()`-loop render pattern).
- `kisho-clinical-trials.php` header + `readme.txt` — version bump to **1.2.0** (new front-end feature),
  changelog entry, "Tested up to" review.

### Boundary check
A renderer asks `Theme` for a class string + an attribute — it knows nothing about settings or CSS files.
`Assets` asks `Theme` which handles to load — it knows nothing about markup. Changing a theme's palette =
editing only that theme's token file. That isolation is what makes the change safe and reviewable.

## 5. Token system

One structural stylesheet consumes a fixed token vocabulary; each theme supplies values. Token names are
taken **verbatim from the design** so the skin blocks drop in unchanged.

| Group | Tokens |
|---|---|
| Surfaces | `--bg --surface --surface-2 --inset` |
| Ink/text | `--ink --ink-2 --muted --faint` |
| Lines | `--border --border-strong` |
| Brand | `--primary --primary-2 --on-primary --primary-soft --primary-line --link` |
| Status | `--rec --rec-bg --rec-line` (recruiting) · `--enr --enr-bg --enr-line` (enrolling-by-invitation) |
| Shape | `--radius --radius-sm --radius-lg --pill-radius` |
| Type | `--font-head --font-body --head-ls --head-wt` |
| Effects/layout | `--shadow --shadow-hover --map-h --chev` |

`--chev` (the select dropdown chevron SVG, colored per mode) is defined as a **CSS token** in each
light/dark block — the design injects it via JS; we make it pure CSS to avoid adding script.

### The looks
- **Skeleton light** — neutral slate/gray tokens declared at plugin-scope default in `frontend.css`. No skin class.
- **Skeleton dark** — neutral dark block under `[data-skmctf-mode="dark"]`, scoped to plugin wrappers.
- **Clinical light / dark** — `.skmctf-skin--clinical` blocks: teal `#0e6e8c`→`#3fa9c9`, Public Sans, 8px radii. Verbatim from design.
- **Warm light / dark** — `.skmctf-skin--warm` blocks: cream `#f8f2ea`, green `#2f7d6b`, Newsreader serif heads + Mulish body, 16–22px / pill radii. Verbatim from design.

### Scoping rule (safety mechanism for Decision #1)
All skin and dark selectors are **scoped under the plugin's wrapper classes**
(`.skmctf-archive-trials`, `.skmctf-single-trial`, `.skmctf-trials-wrap`). Neither a skin nor the neutral
dark block can restyle the surrounding site header/footer/content. This is the concrete implementation of
"plugin markup only." Dark tokens are **never** declared on `:root`.

### Map dark mode
From the design: `[data-skmctf-mode="dark"] .skmctf-map .leaflet-tile { filter: invert(1) hue-rotate(180deg) brightness(.92) contrast(.92) saturate(.8); }` plus themed zoom/attribution controls. Marker
fill = `Theme::primary_color()` passed to `map.js` via a data attribute.

## 6. Admin UX, defaults, error handling

- **New "Theme & appearance" section** on the settings page: **Theme** select (Skeleton / Clinical /
  Patient-friendly) + **Appearance** select (Light / Dark). Saved through the existing Settings API form
  + nonce — no new save path.
- **Defaults:** `theme='skeleton'`, `theme_mode='light'`. Existing sites get the new baseline structure
  with a neutral look; no skin/font file loaded unless opted in.
- **Sanitizer:** both fields whitelisted against constant arrays with `in_array(..., true)`, default
  fallback on any unexpected value (identical to the `provider` branch). Invalid/legacy values can never
  reach CSS.
- **Graceful degradation:** themes are purely additive. If a theme CSS/font fails to load, the page still
  renders from `frontend.css` (structure + neutral tokens).

## 7. Testing & verification

- **Unit (Brain Monkey, no DB):**
  - `Theme::skin_class()` / `mode()` for all valid + invalid inputs (junk → skeleton/light).
  - Settings sanitizer accepts the 3 themes / 2 modes, rejects junk (mirrors `SettingsSanitizeTest`).
- **Integration (`WP_UnitTestCase`):**
  - `theme=clinical, mode=dark` → `List_Renderer::render()` output wrapper contains `skmctf-skin--clinical`
    and `data-skmctf-mode="dark"`.
  - `theme=skeleton` → output contains no skin class and `data-skmctf-mode="light"` (or no non-light mode).
  - `Assets`: correct `themes/<t>.css` + `fonts-<t>.css` handles enqueued per theme; **not** enqueued for skeleton.
- **Standards:** `composer phpcs` clean (WordPress standard); all output escaped.
- **Manual visual (LocalWP sandbox, Playwright against `plugin-sandbox.local`):** trials archive + a single
  trial under all 6 combinations (3 themes × 2 modes). Confirm map dark tiles, fonts loading from local,
  badge contrast (AA), and that the surrounding site chrome is untouched.

## 8. Out of scope (YAGNI)

- `prefers-color-scheme` "Auto" mode (cheap later add; not now).
- Per-site custom color pickers / arbitrary theming.
- Theming the Gutenberg editor preview of the block (front end only for now).
- Any change to data sync, CPT, LLM summaries, or map data — visual layer only.
