# List vs. Card-Grid View Toggle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-default + visitor-toggleable list/card-grid view for the trials listing, where "list" is a compact full-width-row restyle of the existing cards, remembered per browser.

**Architecture:** `Settings::default_view()` feeds `List_Renderer`, which puts a `--view-grid`/`--view-list` modifier + `data-skmctf-view` on the existing `.skmctf-list` `<ul>` and renders a two-button toggle above it. A tiny `view-toggle.js` swaps the class + persists the choice to localStorage. List view is a token-driven CSS restyle of the existing `.skmctf-card__*` markup — no markup/class changes, so it inherits every theme × mode for free.

**Tech Stack:** PHP 7.4+, WordPress 6.4+, native Settings API, CSS custom properties, vanilla JS IIFE (no deps), PHPUnit + Brain Monkey (unit), `WP_UnitTestCase` (integration), PHPCS WordPress standard.

**Reference spec:** [`docs/superpowers/specs/2026-06-22-list-grid-view-toggle-design.md`](../specs/2026-06-22-list-grid-view-toggle-design.md)

## Global Constraints

- **Option:** single array `Settings::OPTION = 'skmctf_settings'`. New key `default_view` (`grid`|`list`, default `grid`).
- **Prefixes:** CSS `.skmctf-`, JS handle `skmctf-`, text domain `kisho-clinical-trials` on every user-facing string.
- **View modifier classes:** `.skmctf-list--view-grid` / `.skmctf-list--view-list` on the `<ul>`; `data-skmctf-view="grid|list"` attribute for JS. When view is `list`, the `--cols-N` class is NOT emitted (list ignores columns).
- **Security:** escape all output at point of output; whitelist the new field via `in_array(..., true)`; no new save path (reuse the Settings API form).
- **Accessibility:** toggle = real `<button>`s with `aria-pressed`, keyboard operable, focus-visible (`outline: 3px solid var(--primary)`); view conveyed by text + `aria-pressed`, not color alone. Correct view renders server-side (no-JS safe).
- **CSS:** token-driven only (no literal theme colors); list view reuses `.skmctf-card__*`.
- **Integration tests:** `export WP_TESTS_DIR=/tmp/wordpress-tests-lib` before `composer test:integration`. Reset `$GLOBALS['wp_styles']`/`wp_scripts` in style/script-enqueue tests; call `Assets::do_register()` explicitly.
- **Indentation:** TABS. **Commits:** conventional, trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `includes/admin/class-settings.php` | `VALID_VIEWS`, default, `default_view()` getter, sanitize branch | Modify |
| `includes/frontend/class-list-renderer.php` | emit view modifier + `data-skmctf-view`; render toggle | Modify |
| `includes/frontend/class-assets.php` | register + enqueue `view-toggle.js` | Modify |
| `assets/js/view-toggle.js` | class swap + aria-pressed + localStorage persistence | Create |
| `assets/css/frontend.css` | `.skmctf-list--view-list` + `.skmctf-view-toggle` (token-driven) | Modify |
| `includes/admin/views/settings-page.php` | "Default view" select in Display section | Modify |
| `kisho-clinical-trials.php`, `readme.txt` | version bump 1.3.0 + changelog | Modify |
| `tests/unit/SettingsViewTest.php` | `default_view()` whitelist + sanitize | Create |
| `tests/integration/ViewRenderTest.php` | modifier class, columns interaction, toggle markup, JS enqueue | Create |

---

## Task 1: Settings — `default_view`

**Files:**
- Modify: `includes/admin/class-settings.php`
- Test: `tests/unit/SettingsViewTest.php`

**Interfaces:**
- Produces: `Settings::VALID_VIEWS = ['grid','list']`; default `'default_view' => 'grid'`; `Settings::default_view(): string`; sanitize handles `default_view`.

- [ ] **Step 1: Write the failing test**

```php
// tests/unit/SettingsViewTest.php
namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Admin\Settings;

final class SettingsViewTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs(
			array(
				'sanitize_text_field'     => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
				'sanitize_textarea_field' => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
				'wp_unslash'              => static fn( $v ) => $v,
				'absint'                  => static fn( $v ) => abs( (int) $v ),
				'__'                      => static fn( $v ) => $v,
			)
		);
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_is_grid(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'grid', Settings::default_view() );
	}
	public function test_accepts_list(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default_view' => 'list' ) );
		$this->assertSame( 'list', Settings::default_view() );
	}
	public function test_rejects_junk(): void {
		Functions\when( 'get_option' )->justReturn( array( 'default_view' => 'carousel' ) );
		$this->assertSame( 'grid', Settings::default_view() );
	}
	public function test_sanitize_whitelists_view(): void {
		$this->assertSame( 'list', Settings::sanitize( array( 'default_view' => 'list' ), array() )['default_view'] );
		$this->assertSame( 'grid', Settings::sanitize( array( 'default_view' => 'nope' ), array() )['default_view'] );
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:unit -- --filter SettingsViewTest`
Expected: FAIL — `Settings::default_view()` undefined / key missing.

- [ ] **Step 3: Add constant, default, getter, sanitize branch**

In `includes/admin/class-settings.php`, after the `VALID_THEME_MODES` constant:

```php
	/**
	 * Supported listing view modes.
	 *
	 * @var string[]
	 */
	public const VALID_VIEWS = array( 'grid', 'list' );
```

In `defaults()`, after `'theme_mode' => 'light',`:

```php
			'theme'                  => 'skeleton',
			'theme_mode'             => 'light',
			'default_view'           => 'grid',
		);
```

After the `theme_mode()` getter:

```php
	/**
	 * Return the default listing view ('grid' | 'list').
	 *
	 * @return string
	 */
	public static function default_view(): string {
		$v = (string) self::get( 'default_view', 'grid' );
		return in_array( $v, self::VALID_VIEWS, true ) ? $v : 'grid';
	}
```

In `sanitize()`, after the `theme_mode` branch:

```php
		// Default listing view (whitelisted).
		$view                = sanitize_text_field( $input['default_view'] ?? 'grid' );
		$out['default_view'] = in_array( $view, self::VALID_VIEWS, true ) ? $view : 'grid';
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:unit -- --filter SettingsViewTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/admin/class-settings.php tests/unit/SettingsViewTest.php
git commit -m "feat(settings): default_view option (grid|list, whitelisted)"
```

---

## Task 2: Renderer — view modifier class, data attribute, and toggle UI

**Files:**
- Modify: `includes/frontend/class-list-renderer.php`
- Test: `tests/integration/ViewRenderTest.php`

**Interfaces:**
- Consumes: `Settings::default_view()`.
- Produces: the `.skmctf-list` `<ul>` carries `skmctf-list--view-grid|--view-list` + `data-skmctf-view`; `--cols-N` only in grid view; a `.skmctf-view-toggle` control with two `<button>`s (correct `aria-pressed`) precedes the `<ul>`.

- [ ] **Step 1: Write the failing integration test**

```php
// tests/integration/ViewRenderTest.php
namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Admin\Settings;
use SKMCTF\Frontend\List_Renderer;
use SKMCTF\Post_Types\Trial_Post_Type;

final class ViewRenderTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['wp_scripts'] = null;
		// One published trial so the list (and toggle) actually render.
		$this->factory->post->create(
			array( 'post_type' => Trial_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'T' )
		);
	}

	private function set_view( string $view ): void {
		update_option( Settings::OPTION, array( 'default_view' => $view ) );
	}

	public function test_list_view_emits_modifier_and_no_cols(): void {
		$this->set_view( 'list' );
		$html = List_Renderer::render( array( 'columns' => 3 ) );
		$this->assertStringContainsString( 'skmctf-list--view-list', $html );
		$this->assertStringContainsString( 'data-skmctf-view="list"', $html );
		$this->assertStringNotContainsString( 'skmctf-list--cols-', $html );
	}

	public function test_grid_view_keeps_columns(): void {
		$this->set_view( 'grid' );
		$html = List_Renderer::render( array( 'columns' => 2 ) );
		$this->assertStringContainsString( 'skmctf-list--view-grid', $html );
		$this->assertStringContainsString( 'data-skmctf-view="grid"', $html );
		$this->assertStringContainsString( 'skmctf-list--cols-2', $html );
	}

	public function test_toggle_markup_present_with_aria_pressed(): void {
		$this->set_view( 'list' );
		$html = List_Renderer::render( array() );
		$this->assertStringContainsString( 'skmctf-view-toggle', $html );
		// list is the active default → its button is pressed
		$this->assertMatchesRegularExpression(
			'/data-skmctf-view-btn="list"[^>]*aria-pressed="true"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/data-skmctf-view-btn="grid"[^>]*aria-pressed="false"/',
			$html
		);
	}
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration -- --filter ViewRenderTest`
Expected: FAIL — no modifier class / toggle yet.

- [ ] **Step 3: Add a private toggle renderer**

In `includes/frontend/class-list-renderer.php`, add a method (near `render_filter_form`):

```php
	/**
	 * Render the grid/list view toggle. Real buttons; the active view is
	 * pre-pressed server-side so the correct view shows without JS.
	 *
	 * @param string $active 'grid' | 'list'.
	 * @return void
	 */
	private static function render_view_toggle( string $active ): void {
		$buttons = array(
			'grid' => __( 'Grid view', 'kisho-clinical-trials' ),
			'list' => __( 'List view', 'kisho-clinical-trials' ),
		);
		echo '<div class="skmctf-view-toggle" role="group" aria-label="'
			. esc_attr__( 'Choose how trials are displayed', 'kisho-clinical-trials' ) . '">';
		foreach ( $buttons as $view => $label ) {
			$is = $view === $active;
			printf(
				'<button type="button" class="skmctf-view-toggle__btn%1$s" data-skmctf-view-btn="%2$s" aria-pressed="%3$s">'
					. '<span class="skmctf-view-toggle__icon skmctf-view-toggle__icon--%2$s" aria-hidden="true"></span>'
					. '<span class="skmctf-view-toggle__label">%4$s</span>'
					. '</button>',
				$is ? ' is-active' : '',
				esc_attr( $view ),
				$is ? 'true' : 'false',
				esc_html( $label )
			);
		}
		echo '</div>';
	}
```

- [ ] **Step 4: Wire view into the list output**

Replace the results `else` block (currently lines ~302-307) so it reads the view, renders the toggle, and builds the `<ul>` class + data attribute:

```php
		} else {
			$view      = Settings::default_view();
			$col_class = ( 'grid' === $view && absint( $atts['columns'] ) > 1 )
				? ' skmctf-list--cols-' . absint( $atts['columns'] )
				: '';

			self::render_view_toggle( $view );

			echo '<ul class="skmctf-list skmctf-list--view-' . esc_attr( $view ) . esc_attr( $col_class )
				. '" data-skmctf-view="' . esc_attr( $view ) . '">';
```

> `Settings` is already imported in this file (`use SKMCTF\Admin\Settings;`). The rest of the loop and the closing `</ul>` are unchanged.

- [ ] **Step 5: Run to verify it passes**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration -- --filter ViewRenderTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/frontend/class-list-renderer.php tests/integration/ViewRenderTest.php
git commit -m "feat(frontend): view modifier class + grid/list toggle control"
```

---

## Task 3: `view-toggle.js` + enqueue

**Files:**
- Create: `assets/js/view-toggle.js`
- Modify: `includes/frontend/class-assets.php`
- Test: extend `tests/integration/ViewRenderTest.php`

**Interfaces:**
- Produces: handle `skmctf-view-toggle` registered + enqueued in `Assets::enqueue()`.

- [ ] **Step 1: Add the enqueue assertion (append to ViewRenderTest)**

```php
	public function test_view_toggle_script_enqueued(): void {
		\SKMCTF\Frontend\Assets::do_register();
		$this->set_view( 'grid' );
		List_Renderer::render( array() );
		$this->assertTrue( wp_script_is( 'skmctf-view-toggle', 'enqueued' ) );
	}
```

- [ ] **Step 2: Run to verify it fails**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration -- --filter ViewRenderTest`
Expected: FAIL — handle not registered.

- [ ] **Step 3: Create the script**

```javascript
// assets/js/view-toggle.js
( function () {
	'use strict';

	var KEY    = 'skmctf_view';
	var VALID  = [ 'grid', 'list' ];

	function read() {
		try {
			var v = window.localStorage.getItem( KEY );
			return VALID.indexOf( v ) !== -1 ? v : null;
		} catch ( e ) {
			return null;
		}
	}

	function write( v ) {
		try {
			window.localStorage.setItem( KEY, v );
		} catch ( e ) {
			/* storage unavailable — ignore, view still switches for this view. */
		}
	}

	function apply( toggle, list, view ) {
		list.classList.remove( 'skmctf-list--view-grid', 'skmctf-list--view-list' );
		list.classList.add( 'skmctf-list--view-' + view );
		list.setAttribute( 'data-skmctf-view', view );
		var btns = toggle.querySelectorAll( '[data-skmctf-view-btn]' );
		for ( var i = 0; i < btns.length; i++ ) {
			var on = btns[ i ].getAttribute( 'data-skmctf-view-btn' ) === view;
			btns[ i ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			btns[ i ].classList.toggle( 'is-active', on );
		}
	}

	function init( toggle ) {
		// The list is the next .skmctf-list sibling after the toggle.
		var list = toggle.parentNode.querySelector( '.skmctf-list' );
		if ( ! list ) {
			return;
		}
		var stored = read();
		if ( stored && stored !== list.getAttribute( 'data-skmctf-view' ) ) {
			apply( toggle, list, stored );
		}
		toggle.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-skmctf-view-btn]' ) : null;
			if ( ! btn ) {
				return;
			}
			var view = btn.getAttribute( 'data-skmctf-view-btn' );
			if ( VALID.indexOf( view ) === -1 ) {
				return;
			}
			apply( toggle, list, view );
			write( view );
		} );
	}

	function boot() {
		var toggles = document.querySelectorAll( '.skmctf-view-toggle' );
		for ( var i = 0; i < toggles.length; i++ ) {
			init( toggles[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
```

> The toggle and `<ul>` are siblings inside the same parent (`.skmctf-trials-wrap` / archive content), so `toggle.parentNode.querySelector('.skmctf-list')` pairs them. This matches the markup emitted in Task 2 (toggle then `<ul>`).

- [ ] **Step 4: Register + enqueue the handle**

In `includes/frontend/class-assets.php`, add a constant near `SCRIPT_HANDLE`:

```php
	public const SCRIPT_HANDLE = 'skmctf-filters';

	/** Grid/list view toggle script handle. */
	public const VIEW_TOGGLE_HANDLE = 'skmctf-view-toggle';
```

In `do_register()`, after the filters script registration:

```php
		wp_register_script(
			self::VIEW_TOGGLE_HANDLE,
			plugins_url( 'assets/js/view-toggle.js', SKMCTF_FILE ),
			array(),
			SKMCTF_VERSION,
			true // Load in footer.
		);
```

In `enqueue()`, after `wp_enqueue_script( self::SCRIPT_HANDLE );`:

```php
		wp_enqueue_script( self::VIEW_TOGGLE_HANDLE );
```

- [ ] **Step 5: Run to verify it passes**

Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration -- --filter ViewRenderTest`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add assets/js/view-toggle.js includes/frontend/class-assets.php tests/integration/ViewRenderTest.php
git commit -m "feat(frontend): view-toggle.js (class swap + localStorage), enqueue"
```

---

## Task 4: List-view CSS + toggle CSS (token-driven)

**Files:**
- Modify: `assets/css/frontend.css`

**Interfaces:** none (CSS). Adds `.skmctf-list--view-list` row layout + `.skmctf-view-toggle` control styling, tokens only.

> No automated test (presentational); verified live in Task 6.

- [ ] **Step 1: Add the toggle control styles**

Append to `frontend.css` (after the TRIAL LIST grid section):

```css
/* ==========================================================================
   VIEW TOGGLE (grid / list)
   ========================================================================== */

.skmctf-view-toggle {
	display: inline-flex;
	gap: 2px;
	margin-block-end: 1rem;
	padding: 3px;
	background: var(--surface-2);
	border: 1px solid var(--border);
	border-radius: var(--radius-sm);
}

.skmctf-view-toggle__btn {
	display: inline-flex;
	align-items: center;
	gap: 0.4rem;
	border: 0;
	background: transparent;
	color: var(--muted);
	font: inherit;
	font-size: 0.85rem;
	font-weight: 600;
	padding: 0.35em 0.8em;
	border-radius: calc(var(--radius-sm) - 2px);
	cursor: pointer;
	transition: background-color 0.15s ease, color 0.15s ease;
}

.skmctf-view-toggle__btn:hover {
	color: var(--ink);
}

.skmctf-view-toggle__btn.is-active {
	background: var(--primary);
	color: var(--on-primary);
}

.skmctf-view-toggle__btn:focus-visible {
	outline: 3px solid var(--primary);
	outline-offset: 2px;
}

/* Simple CSS icons (no image requests). */
.skmctf-view-toggle__icon {
	width: 14px;
	height: 14px;
	flex: none;
}
.skmctf-view-toggle__icon--grid {
	background:
		linear-gradient(currentColor 0 0) 0 0 / 5px 5px no-repeat,
		linear-gradient(currentColor 0 0) 9px 0 / 5px 5px no-repeat,
		linear-gradient(currentColor 0 0) 0 9px / 5px 5px no-repeat,
		linear-gradient(currentColor 0 0) 9px 9px / 5px 5px no-repeat;
}
.skmctf-view-toggle__icon--list {
	background:
		linear-gradient(currentColor 0 0) 0 1px / 14px 2px no-repeat,
		linear-gradient(currentColor 0 0) 0 6px / 14px 2px no-repeat,
		linear-gradient(currentColor 0 0) 0 11px / 14px 2px no-repeat;
}

@media (prefers-reduced-motion: reduce) {
	.skmctf-view-toggle__btn {
		transition: none;
	}
}
```

- [ ] **Step 2: Add the list-view row layout**

Append (reuses existing `.skmctf-card__*`; overrides grid):

```css
/* ==========================================================================
   LIST VIEW — compact full-width rows (restyle of the card markup)
   --------------------------------------------------------------------------
   Same .skmctf-card / .skmctf-card__* markup as the grid; this modifier turns
   each card into a horizontal row. Token-driven, so it themes automatically.
   ========================================================================== */

.skmctf-list--view-list {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.skmctf-list--view-list .skmctf-card {
	border-radius: var(--radius);
}

.skmctf-list--view-list .skmctf-card:hover {
	transform: none;
	box-shadow: var(--shadow);
	border-color: var(--border-strong);
	background: var(--surface-2);
}

/* Header + body sit in one horizontal flow; meta is inline and compact. */
.skmctf-list--view-list .skmctf-card > article {
	flex-direction: column;
	height: auto;
}

.skmctf-list--view-list .skmctf-card__header {
	flex-direction: row;
	align-items: baseline;
	justify-content: space-between;
	gap: 0.5rem 1rem;
	padding: 0.85rem 1.1rem 0;
}

.skmctf-list--view-list .skmctf-card__title {
	font-size: 1.02rem;
}

.skmctf-list--view-list .skmctf-card__body {
	flex-direction: row;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0.25rem 1.1rem;
	padding: 0.5rem 1.1rem 0.85rem;
}

/* Meta lines flow inline instead of stacking. */
.skmctf-list--view-list .skmctf-card__phase,
.skmctf-list--view-list .skmctf-card__conditions,
.skmctf-list--view-list .skmctf-card__sponsor,
.skmctf-list--view-list .skmctf-card__locations {
	margin: 0;
	font-size: 0.85rem;
}

/* Summary clamps tighter and spans the full row. */
.skmctf-list--view-list .skmctf-card__summary {
	flex: 1 0 100%;
	margin-block: 0.4rem 0;
	padding-block-start: 0.5rem;
	-webkit-line-clamp: 2;
}

/* Keep the CTA + footer compact and inline-friendly. */
.skmctf-list--view-list .skmctf-card__ctgov-link {
	margin-block-start: 0.5rem;
}

.skmctf-list--view-list .skmctf-card__footer {
	flex: 1 0 100%;
	margin-block-start: 0.6rem;
}

@media (max-width: 640px) {
	.skmctf-list--view-list .skmctf-card__header {
		flex-direction: column;
		align-items: flex-start;
	}
}
```

- [ ] **Step 3: Sanity-check no literal theme hex was introduced**

Run: `rg -n "#[0-9a-fA-F]{3,6}" assets/css/frontend.css | rg -v "stroke=|data:image"` — the new rules must add NO new hex (the only literals remain the badge/marker semantic palette from before). Fix any stray literal by swapping to the matching `var(--token)`.

- [ ] **Step 4: Commit**

```bash
git add assets/css/frontend.css
git commit -m "feat(css): list-view row layout + view toggle (token-driven)"
```

---

## Task 5: Admin "Default view" select

**Files:**
- Modify: `includes/admin/views/settings-page.php`

> No new test — persistence covered by Task 1's sanitizer test; rendering verified live in Task 6.

- [ ] **Step 1: Add a label map near the other view maps**

After the `$all_modes = array( … );` block (added by the theme feature):

```php
$all_views = array(
	'grid' => __( 'Card grid', 'kisho-clinical-trials' ),
	'list' => __( 'List', 'kisho-clinical-trials' ),
);
```

- [ ] **Step 2: Add the select inside the Display section**

Inside the Display `<table class="form-table">` (after the "Fields to display" row, before its `</table>` at the end of the Display section), add:

```php
					<tr>
						<th scope="row">
							<label for="skmctf_default_view"><?php esc_html_e( 'Default view', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_default_view" name="<?php echo esc_attr( Settings::OPTION ); ?>[default_view]">
								<?php foreach ( $all_views as $view_key => $view_label ) : ?>
									<option
										value="<?php echo esc_attr( $view_key ); ?>"
										<?php selected( $s['default_view'], $view_key ); ?>
									>
										<?php echo esc_html( $view_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Initial layout for the trials list. Visitors can switch between card grid and list, and their choice is remembered in their browser.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>
```

- [ ] **Step 3: Lint + quick manual check**

Run: `php -l includes/admin/views/settings-page.php` → "No syntax errors".
Then open Settings → Clinical Trials, confirm the "Default view" dropdown appears in Display, change it to List, Save, and confirm it sticks on reload.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/views/settings-page.php
git commit -m "feat(admin): Default view (grid|list) setting in Display section"
```

---

## Task 6: Version bump, changelog, full verification

**Files:**
- Modify: `kisho-clinical-trials.php`, `readme.txt`

- [ ] **Step 1: Bump to 1.3.0**

- `kisho-clinical-trials.php`: header `* Version: 1.2.0` → `1.3.0`; `define( 'SKMCTF_VERSION', '1.2.0' )` → `'1.3.0'`.
- `readme.txt`: `Stable tag: 1.2.0` → `1.3.0`.

- [ ] **Step 2: Changelog entry**

At the top of `== Changelog ==`:

```
= 1.3.0 =
* New: Trials can now be shown as a compact **list** or the **card grid**. Pick the default under Settings → Clinical Trials → Display → Default view; visitors get a grid/list toggle and their choice is remembered in their browser.
```

- [ ] **Step 3: Full unit + integration suites**

Run: `composer test:unit`  → PASS (existing + `SettingsViewTest`).
Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib composer test:integration` → PASS (existing + `ViewRenderTest`).

- [ ] **Step 4: PHPCS**

Run: `composer phpcs -- includes/admin/class-settings.php includes/frontend/class-list-renderer.php includes/frontend/class-assets.php includes/admin/views/settings-page.php tests/unit/SettingsViewTest.php tests/integration/ViewRenderTest.php`
Expected: clean (fix with `composer phpcbf` if needed). Note: `assets/js/view-toggle.js` is not PHPCS-scoped; keep it consistent with `map.js` style.

- [ ] **Step 5: Live visual check (Playwright on plugin-sandbox.local)**

Load the trials archive in one theme, light + dark:
- Confirm the toggle renders; default view matches the setting.
- Click **List** → layout switches to compact rows; click **Grid** → back to cards.
- Reload → the last choice persists (localStorage).
- Confirm `aria-pressed` flips and focus outline is visible.
- Confirm list view themes correctly (uses surface/border/ink tokens) in dark mode.

- [ ] **Step 6: Commit**

```bash
git add kisho-clinical-trials.php readme.txt
git commit -m "chore: release 1.3.0 — list/grid view toggle"
```

---

## Self-Review

**Spec coverage:** Decision 1 (admin default + visitor toggle) → Tasks 1,2,3. Decision 2 (compact rows) → Task 4. Decision 3 (list ignores columns) → Task 2 Step 4 + test. Decision 4 (no block control) → honored (not implemented). §5 toggle/a11y → Task 2 (buttons, aria-pressed, server-rendered) + Task 4 (focus). §6 JS → Task 3. §7 token-driven CSS → Task 4 (+ Step 3 hex guard). §8 testing → Tasks 1,2,3 + Task 6. ✓

**Placeholder scan:** No TBD/TODO; all code blocks concrete.

**Type consistency:** `Settings::default_view()` / `VALID_VIEWS` defined in Task 1, used in Task 2. `VIEW_TOGGLE_HANDLE` defined + used in Task 3; the test asserts the same handle string `skmctf-view-toggle`. Modifier classes `skmctf-list--view-grid|--view-list` and `data-skmctf-view` consistent across Tasks 2 (emit), 3 (JS swaps the same names), 4 (CSS targets the same). Button attribute `data-skmctf-view-btn` consistent between Task 2 markup, Task 2 test regex, and Task 3 JS. ✓
