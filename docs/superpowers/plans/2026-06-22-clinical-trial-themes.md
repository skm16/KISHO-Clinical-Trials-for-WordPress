# Clinical Trial Themes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship two optional, admin-selectable front-end themes (Clinical, Patient-friendly) each in light & dark, layered as pure CSS skins over the plugin's unchanged markup, defaulting to the current look (Skeleton).

**Architecture:** `Settings::theme()`/`theme_mode()` feed a new `SKMCTF\Frontend\Theme` helper (single source of truth) that yields a skin class + a `data-skmctf-mode` attribute + the CSS/font handles to enqueue. The three front-end wrappers (block/shortcode `.skmctf-trials-wrap`, archive `<main>`, single `<main>`) get the class + attribute; `Assets` conditionally enqueues `themes/<theme>.css` and `fonts-<theme>.css`. `frontend.css` is refactored to read CSS custom properties, with neutral skeleton tokens (light + dark) as plugin-scoped defaults. No JS changes.

**Tech Stack:** PHP 7.4+ (dev on 8.3), WordPress 6.4+, native WP APIs (Settings API, `wp_enqueue_style`), CSS custom properties, bundled `.woff2` fonts (`@font-face`, no CDN), PHPUnit + Brain Monkey (unit) and `WP_UnitTestCase` (integration), PHPCS WordPress standard.

**Reference spec:** [`docs/superpowers/specs/2026-06-22-clinical-trial-themes-design.md`](../specs/2026-06-22-clinical-trial-themes-design.md)
**Verbatim design token blocks (authoritative for all hex values):** `c:\tmp\skmctf-design-tokens.md`

## Global Constraints

Every task's requirements implicitly include this section. Values verbatim from the spec / existing plugin conventions.

- **License:** GPLv2 or later. No CDN-loaded assets; no telemetry / phone-home. Fonts bundled locally.
- **Prefixes:** options/meta/CSS/JS = `skmctf_` / `.skmctf-`; PHP namespace root = `SKMCTF\`. Text domain on every user-facing string = `kisho-clinical-trials`.
- **Settings option:** single array `Settings::OPTION = 'skmctf_settings'`. New keys: `theme` (`skeleton|clinical|warm`, default `skeleton`), `theme_mode` (`light|dark`, default `light`).
- **CSS class names:** skin class is `''` for skeleton, `skmctf-skin--clinical` / `skmctf-skin--warm` otherwise. Mode attribute is `data-skmctf-mode="light|dark"`. Skin/dark selectors are ALWAYS scoped under the plugin wrappers (`.skmctf-archive-trials`, `.skmctf-single-trial`, `.skmctf-trials-wrap`); dark tokens NEVER on `:root`.
- **Security:** escape all output at point of output (`esc_attr` on the class string + mode attribute). No new save path — reuse the existing Settings API form, nonce, and `manage_options` check. Both new fields whitelisted via `in_array(..., true)` against constant arrays, default fallback on junk.
- **Indentation:** TABS (match existing files).
- **Templates are GLOBAL namespace** (no `use`): call plugin classes fully-qualified with a leading backslash, e.g. `\SKMCTF\Frontend\Theme::skin_class()`. **`List_Renderer` is in `namespace SKMCTF\Frontend`:** add a `use` and call `Theme::` unqualified.
- **Commit style:** conventional commits; trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Test runners:** unit `composer test:unit` (Brain Monkey, no DB); integration `composer test:integration` (needs WP test suite — LocalWP MySQL on port 10120 per project memory); standards `composer phpcs`.

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `includes/admin/class-settings.php` | option storage: constants, defaults, getters, sanitizer | Modify |
| `includes/frontend/class-theme.php` | resolve theme → skin class / mode / asset handles (single source of truth) | Create |
| `includes/frontend/class-list-renderer.php` | append skin class + mode attr to `.skmctf-trials-wrap` | Modify |
| `templates/archive-skmctf_trial.php` | append skin class + mode attr to archive `<main>` | Modify |
| `templates/single-skmctf_trial.php` | append skin class + mode attr to single `<main>` | Modify |
| `includes/frontend/class-assets.php` | register + conditionally enqueue theme/font CSS | Modify |
| `assets/css/frontend.css` | structural CSS reading `var(--…)` + neutral skeleton tokens (light+dark) | Modify |
| `assets/css/themes/clinical.css` | clinical light+dark token blocks | Create |
| `assets/css/themes/warm.css` | warm light+dark token blocks | Create |
| `assets/css/fonts-clinical.css` | `@font-face` Public Sans (local woff2) | Create |
| `assets/css/fonts-warm.css` | `@font-face` Newsreader + Mulish (local woff2) | Create |
| `assets/fonts/*.woff2` | bundled font files | Create |
| `includes/admin/views/settings-page.php` | two new `<select>` controls (Theme & appearance section) | Modify |
| `kisho-clinical-trials.php`, `readme.txt` | version bump 1.2.0 + changelog | Modify |
| `tests/unit/ThemeTest.php`, `tests/unit/SettingsThemeTest.php` | unit coverage | Create |
| `tests/integration/ThemeRenderTest.php` | wrapper class + enqueue coverage | Create |

---

## Task 1: Settings — constants, defaults, getters, sanitizer

**Files:**
- Modify: `includes/admin/class-settings.php`
- Test: `tests/unit/SettingsThemeTest.php`

**Interfaces:**
- Consumes: existing `Settings::OPTION`, `Settings::all()`, `Settings::get()`, `Settings::sanitize()`.
- Produces: `Settings::VALID_THEMES = ['skeleton','clinical','warm']`; `Settings::VALID_THEME_MODES = ['light','dark']`; `Settings::theme(): string`; `Settings::theme_mode(): string`; sanitize handles `theme` + `theme_mode`.

- [ ] **Step 1: Write the failing test**

```php
// tests/unit/SettingsThemeTest.php
namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Admin\Settings;

final class SettingsThemeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( array(
			'sanitize_text_field' => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
		) );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_theme_getter_returns_default_when_unset(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		$this->assertSame( 'skeleton', Settings::theme() );
		$this->assertSame( 'light', Settings::theme_mode() );
	}
	public function test_theme_getter_rejects_unknown_value(): void {
		Functions\when( 'get_option' )->justReturn( array( 'theme' => 'rainbow', 'theme_mode' => 'sepia' ) );
		$this->assertSame( 'skeleton', Settings::theme() );
		$this->assertSame( 'light', Settings::theme_mode() );
	}
	public function test_theme_getter_accepts_valid_values(): void {
		Functions\when( 'get_option' )->justReturn( array( 'theme' => 'clinical', 'theme_mode' => 'dark' ) );
		$this->assertSame( 'clinical', Settings::theme() );
		$this->assertSame( 'dark', Settings::theme_mode() );
	}
	public function test_sanitize_whitelists_theme_and_mode(): void {
		$out = Settings::sanitize( array( 'theme' => 'warm', 'theme_mode' => 'dark' ), array() );
		$this->assertSame( 'warm', $out['theme'] );
		$this->assertSame( 'dark', $out['theme_mode'] );

		$out2 = Settings::sanitize( array( 'theme' => 'nope', 'theme_mode' => 'nope' ), array() );
		$this->assertSame( 'skeleton', $out2['theme'] );
		$this->assertSame( 'light', $out2['theme_mode'] );
	}
}
```

> **Note:** `Settings::sanitize()` likely touches other keys (statuses, provider, api_key) that call WP functions. If `sanitize()` errors on the bare `array()` inputs above due to a missing stub, add the minimal `Functions\stubs([...])` those branches need (e.g. `wp_unslash`, `absint`, `sanitize_textarea_field`, `esc_url_raw`) — mirror the existing `tests/unit/SettingsSanitizeTest.php` setUp, which already exercises `sanitize()`. Do not change production code to satisfy the test harness.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter SettingsThemeTest`
Expected: FAIL — `Settings::theme()` / `theme_mode()` undefined (and/or sanitize lacks the keys).

- [ ] **Step 3: Add the constants**

In `includes/admin/class-settings.php`, immediately after the `PROVIDERS` constant (currently line 52):

```php
	public const PROVIDERS = array( 'anthropic', 'openai' );

	/**
	 * Supported front-end themes (skin keys). 'skeleton' = default, no skin class.
	 *
	 * @var string[]
	 */
	public const VALID_THEMES = array( 'skeleton', 'clinical', 'warm' );

	/**
	 * Supported appearance modes.
	 *
	 * @var string[]
	 */
	public const VALID_THEME_MODES = array( 'light', 'dark' );
```

- [ ] **Step 4: Add the defaults**

In the `defaults()` array (currently lines 64-83), add two entries after `'attribution' => false,`:

```php
			'display_fields'         => array( 'status', 'phase', 'conditions', 'sponsor', 'locations', 'summary' ),
			'attribution'            => false,
			'theme'                  => 'skeleton',
			'theme_mode'             => 'light',
		);
```

- [ ] **Step 5: Add the getters**

After the `provider()` getter (the enum-getter model), add:

```php
	/**
	 * Return the active front-end theme key ('skeleton' | 'clinical' | 'warm').
	 *
	 * @return string
	 */
	public static function theme(): string {
		$t = (string) self::get( 'theme', 'skeleton' );
		return in_array( $t, self::VALID_THEMES, true ) ? $t : 'skeleton';
	}

	/**
	 * Return the active appearance mode ('light' | 'dark').
	 *
	 * @return string
	 */
	public static function theme_mode(): string {
		$m = (string) self::get( 'theme_mode', 'light' );
		return in_array( $m, self::VALID_THEME_MODES, true ) ? $m : 'light';
	}
```

- [ ] **Step 6: Add the sanitize branches**

In `sanitize()`, immediately after the provider whitelist branch (the lines that set `$out['provider']`), add:

```php
		// Theme (whitelisted).
		$theme         = sanitize_text_field( $input['theme'] ?? 'skeleton' );
		$out['theme']  = in_array( $theme, self::VALID_THEMES, true ) ? $theme : 'skeleton';

		// Appearance mode (whitelisted).
		$mode              = sanitize_text_field( $input['theme_mode'] ?? 'light' );
		$out['theme_mode'] = in_array( $mode, self::VALID_THEME_MODES, true ) ? $mode : 'light';
```

- [ ] **Step 7: Run test to verify it passes**

Run: `composer test:unit -- --filter SettingsThemeTest`
Expected: PASS (4 tests).

- [ ] **Step 8: Commit**

```bash
git add includes/admin/class-settings.php tests/unit/SettingsThemeTest.php
git commit -m "feat(settings): theme + theme_mode option (whitelisted getters/sanitizer)"
```

---

## Task 2: The `Theme` helper (single source of truth)

**Files:**
- Create: `includes/frontend/class-theme.php`
- Test: `tests/unit/ThemeTest.php`

**Interfaces:**
- Consumes: `Settings::theme()`, `Settings::theme_mode()`.
- Produces: `SKMCTF\Frontend\Theme::active(): string`; `Theme::mode(): string`; `Theme::skin_class(): string` (`''` for skeleton, else `skmctf-skin--clinical`/`skmctf-skin--warm`); `Theme::mode_attr(): string` (escaped `data-skmctf-mode="…"`); `Theme::body_class_suffix(): string` (the class to append to a wrapper, e.g. `'skmctf-skin--clinical'` or `''`); `Theme::has_skin(): bool`.

- [ ] **Step 1: Write the failing test**

```php
// tests/unit/ThemeTest.php
namespace SKMCTF\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Frontend\Theme;

final class ThemeTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubs( array(
			'esc_attr' => static fn( $v ) => $v,
		) );
	}
	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function withSettings( string $theme, string $mode ): void {
		Functions\when( 'get_option' )->justReturn( array( 'theme' => $theme, 'theme_mode' => $mode ) );
	}

	public function test_skeleton_has_no_skin_class(): void {
		$this->withSettings( 'skeleton', 'light' );
		$this->assertSame( '', Theme::skin_class() );
		$this->assertFalse( Theme::has_skin() );
	}
	public function test_clinical_skin_class(): void {
		$this->withSettings( 'clinical', 'dark' );
		$this->assertSame( 'skmctf-skin--clinical', Theme::skin_class() );
		$this->assertTrue( Theme::has_skin() );
	}
	public function test_warm_skin_class(): void {
		$this->withSettings( 'warm', 'light' );
		$this->assertSame( 'skmctf-skin--warm', Theme::skin_class() );
	}
	public function test_mode_attr_is_escaped_and_correct(): void {
		$this->withSettings( 'clinical', 'dark' );
		$this->assertSame( 'data-skmctf-mode="dark"', Theme::mode_attr() );
		$this->withSettings( 'skeleton', 'light' );
		$this->assertSame( 'data-skmctf-mode="light"', Theme::mode_attr() );
	}
	public function test_active_and_mode_passthrough(): void {
		$this->withSettings( 'warm', 'dark' );
		$this->assertSame( 'warm', Theme::active() );
		$this->assertSame( 'dark', Theme::mode() );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter ThemeTest`
Expected: FAIL — class `SKMCTF\Frontend\Theme` not found.

- [ ] **Step 3: Write the implementation**

```php
// includes/frontend/class-theme.php
<?php
/**
 * Front-end theme resolver.
 *
 * Single source of truth that turns the stored theme/theme_mode settings into
 * (a) a CSS skin class, (b) a data-skmctf-mode attribute, and (c) the set of
 * CSS/font asset handles to enqueue. Renderers and templates consult this class;
 * they never read theme settings directly.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Admin\Settings;

/**
 * Resolves the active theme into presentation primitives.
 */
final class Theme {

	/**
	 * Map of skin key => skin CSS class. 'skeleton' intentionally maps to ''.
	 *
	 * @var array<string,string>
	 */
	private const SKIN_CLASSES = array(
		'skeleton' => '',
		'clinical' => 'skmctf-skin--clinical',
		'warm'     => 'skmctf-skin--warm',
	);

	/**
	 * Active theme key.
	 *
	 * @return string 'skeleton' | 'clinical' | 'warm'
	 */
	public static function active(): string {
		return Settings::theme();
	}

	/**
	 * Active appearance mode.
	 *
	 * @return string 'light' | 'dark'
	 */
	public static function mode(): string {
		return Settings::theme_mode();
	}

	/**
	 * CSS skin class for the active theme ('' for skeleton).
	 *
	 * @return string
	 */
	public static function skin_class(): string {
		$key = self::active();
		return self::SKIN_CLASSES[ $key ] ?? '';
	}

	/**
	 * Whether a non-skeleton skin is active.
	 *
	 * @return bool
	 */
	public static function has_skin(): bool {
		return '' !== self::skin_class();
	}

	/**
	 * Escaped data-skmctf-mode attribute, e.g. data-skmctf-mode="dark".
	 *
	 * @return string
	 */
	public static function mode_attr(): string {
		return 'data-skmctf-mode="' . esc_attr( self::mode() ) . '"';
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter ThemeTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add includes/frontend/class-theme.php tests/unit/ThemeTest.php
git commit -m "feat(frontend): Theme helper resolving skin class + mode attribute"
```

---

## Task 3: Wire the skin class + mode attribute onto the three wrappers

**Files:**
- Modify: `includes/frontend/class-list-renderer.php` (the `.skmctf-trials-wrap` open tag)
- Modify: `templates/archive-skmctf_trial.php` (the archive `<main>`)
- Modify: `templates/single-skmctf_trial.php` (the single `<main>`)
- Test: `tests/integration/ThemeRenderTest.php`

**Interfaces:**
- Consumes: `Theme::skin_class()`, `Theme::mode_attr()`.
- Produces: each wrapper carries the skin class (when non-skeleton) and `data-skmctf-mode`.

- [ ] **Step 1: Write the failing integration test**

```php
// tests/integration/ThemeRenderTest.php
namespace SKMCTF\Tests\Integration;

use WP_UnitTestCase;
use SKMCTF\Admin\Settings;
use SKMCTF\Frontend\List_Renderer;

final class ThemeRenderTest extends WP_UnitTestCase {
	private function set_theme( string $theme, string $mode ): void {
		update_option( Settings::OPTION, array( 'theme' => $theme, 'theme_mode' => $mode ) );
	}

	public function test_clinical_dark_wrapper_has_skin_and_mode(): void {
		$this->set_theme( 'clinical', 'dark' );
		$html = List_Renderer::render( array() );
		$this->assertStringContainsString( 'skmctf-skin--clinical', $html );
		$this->assertStringContainsString( 'data-skmctf-mode="dark"', $html );
	}
	public function test_skeleton_wrapper_has_no_skin_class(): void {
		$this->set_theme( 'skeleton', 'light' );
		$html = List_Renderer::render( array() );
		$this->assertStringNotContainsString( 'skmctf-skin--', $html );
		$this->assertStringContainsString( 'data-skmctf-mode="light"', $html );
		$this->assertStringContainsString( 'skmctf-trials-wrap', $html );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:integration -- --filter ThemeRenderTest`
Expected: FAIL — wrapper has no skin class / mode attribute yet.

- [ ] **Step 3: Edit `List_Renderer`**

Add the `use` import within the existing use block (after `use SKMCTF\Support\Template_Loader;`):

```php
use SKMCTF\Support\Template_Loader;
use SKMCTF\Frontend\Theme;
```

> `Theme` is in the same `SKMCTF\Frontend` namespace, so the `use` is optional, but keep it explicit for clarity and to match the file's import style.

Replace the wrapper-open echo (currently line 264):

```php
		echo '<div class="skmctf-trials-wrap">';
```

with:

```php
		$skmctf_skin = Theme::skin_class();
		echo '<div class="skmctf-trials-wrap'
			. ( '' !== $skmctf_skin ? ' ' . esc_attr( $skmctf_skin ) : '' )
			. '" ' . Theme::mode_attr() . '>';
```

> `Theme::mode_attr()` already returns an escaped, complete attribute string. The closing `</div><!-- .skmctf-trials-wrap -->` (line 403) needs no change.

- [ ] **Step 4: Edit the archive template**

In `templates/archive-skmctf_trial.php`, replace the `<main>` open (line 23):

```php
<main id="main" class="site-main skmctf-archive-trials" role="main">
```

with (note: global namespace → leading-backslash FQN, inline PHP echoes, escape):

```php
<main id="main" class="site-main skmctf-archive-trials<?php echo \SKMCTF\Frontend\Theme::has_skin() ? ' ' . esc_attr( \SKMCTF\Frontend\Theme::skin_class() ) : ''; ?>" role="main" <?php echo \SKMCTF\Frontend\Theme::mode_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mode_attr() returns a pre-escaped attribute. ?>>
```

- [ ] **Step 5: Edit the single template**

In `templates/single-skmctf_trial.php`, replace the `<main>` open (line 32) the same way:

```php
<main id="main" class="site-main skmctf-single-trial" role="main">
```

with:

```php
<main id="main" class="site-main skmctf-single-trial<?php echo \SKMCTF\Frontend\Theme::has_skin() ? ' ' . esc_attr( \SKMCTF\Frontend\Theme::skin_class() ) : ''; ?>" role="main" <?php echo \SKMCTF\Frontend\Theme::mode_attr(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- mode_attr() returns a pre-escaped attribute. ?>>
```

- [ ] **Step 6: Run test to verify it passes**

Run: `composer test:integration -- --filter ThemeRenderTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add includes/frontend/class-list-renderer.php templates/archive-skmctf_trial.php templates/single-skmctf_trial.php tests/integration/ThemeRenderTest.php
git commit -m "feat(frontend): apply skin class + data-skmctf-mode to the three wrappers"
```

---

## Task 4: `Assets` — register + conditionally enqueue theme & font CSS

**Files:**
- Modify: `includes/frontend/class-assets.php`
- Test: extend `tests/integration/ThemeRenderTest.php`

**Interfaces:**
- Consumes: `Theme::active()`.
- Produces: handles `skmctf-theme-clinical`, `skmctf-theme-warm`, `skmctf-fonts-clinical`, `skmctf-fonts-warm`; `Assets::enqueue_theme(): void` called from within `Assets::enqueue()`. Skeleton enqueues neither.

- [ ] **Step 1: Write the failing test (append to ThemeRenderTest)**

```php
	public function test_clinical_theme_enqueues_theme_and_font_css(): void {
		update_option( Settings::OPTION, array( 'theme' => 'clinical', 'theme_mode' => 'light' ) );
		List_Renderer::render( array() );
		$this->assertTrue( wp_style_is( 'skmctf-theme-clinical', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'skmctf-fonts-clinical', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'skmctf-theme-warm', 'enqueued' ) );
	}
	public function test_skeleton_enqueues_no_theme_css(): void {
		update_option( Settings::OPTION, array( 'theme' => 'skeleton', 'theme_mode' => 'light' ) );
		List_Renderer::render( array() );
		$this->assertFalse( wp_style_is( 'skmctf-theme-clinical', 'enqueued' ) );
		$this->assertFalse( wp_style_is( 'skmctf-fonts-clinical', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'skmctf-frontend', 'enqueued' ) ); // base always loads
	}
```

> Add `use SKMCTF\Frontend\List_Renderer;` is already present from Task 3. These assertions rely on `wp_enqueue_scripts` having fired so `do_register()` ran; `WP_UnitTestCase` fires it during setup. If a handle is "registered but not enqueued", `wp_style_is($h,'enqueued')` is the correct check.

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:integration -- --filter ThemeRenderTest`
Expected: FAIL — theme/font handles not registered.

- [ ] **Step 3: Add handle constants**

In `includes/frontend/class-assets.php`, after `MAP_SCRIPT_HANDLE` (line 30):

```php
	/** Map initialisation script handle. */
	public const MAP_SCRIPT_HANDLE = 'skmctf-map';

	/** Per-theme token stylesheet handle prefix (suffix = theme key). */
	public const THEME_STYLE_PREFIX = 'skmctf-theme-';

	/** Per-theme font stylesheet handle prefix (suffix = theme key). */
	public const FONT_STYLE_PREFIX = 'skmctf-fonts-';
```

- [ ] **Step 4: Register the theme/font stylesheets**

In `do_register()`, after the existing `MAP_SCRIPT_HANDLE` registration block (line 84), add:

```php
		// Per-theme token + font stylesheets (registered always, enqueued on demand).
		$themes = array(
			'clinical' => array( 'tokens' => 'assets/css/themes/clinical.css', 'fonts' => 'assets/css/fonts-clinical.css' ),
			'warm'     => array( 'tokens' => 'assets/css/themes/warm.css',     'fonts' => 'assets/css/fonts-warm.css' ),
		);
		foreach ( $themes as $key => $paths ) {
			wp_register_style(
				self::FONT_STYLE_PREFIX . $key,
				plugins_url( $paths['fonts'], SKMCTF_FILE ),
				array(),
				SKMCTF_VERSION
			);
			wp_register_style(
				self::THEME_STYLE_PREFIX . $key,
				plugins_url( $paths['tokens'], SKMCTF_FILE ),
				array( self::STYLE_HANDLE, self::FONT_STYLE_PREFIX . $key ),
				SKMCTF_VERSION
			);
		}
```

> The theme token sheet depends on the base `skmctf-frontend` (so structure loads first) and on its font sheet (so `@font-face` is available). This guarantees load order without inline hacks.

- [ ] **Step 5: Add `enqueue_theme()` and call it from `enqueue()`**

Add a new method after `enqueue()`:

```php
	/**
	 * Enqueue the active theme's token + font stylesheets, if any.
	 *
	 * Skeleton enqueues nothing — the base frontend.css already carries the
	 * neutral default tokens.
	 *
	 * @return void
	 */
	public static function enqueue_theme(): void {
		$theme = \SKMCTF\Frontend\Theme::active();
		if ( 'skeleton' === $theme ) {
			return;
		}
		wp_enqueue_style( self::FONT_STYLE_PREFIX . $theme );
		wp_enqueue_style( self::THEME_STYLE_PREFIX . $theme );
	}
```

Then in the existing `enqueue()` method, add a call so themed assets load on every render:

```php
	public static function enqueue(): void {
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
		self::enqueue_theme();
	}
```

> `Assets` is in `SKMCTF\Frontend`, same as `Theme`; the FQN `\SKMCTF\Frontend\Theme::active()` is used for explicitness (a bare `Theme::active()` also resolves). Because `List_Renderer::render()` calls `Assets::enqueue()` unconditionally (line 109), the active theme's CSS now loads wherever trials render — block, shortcode, and (via the templates, which also call the renderer) archive/single.

- [ ] **Step 6: Run test to verify it passes**

Run: `composer test:integration -- --filter ThemeRenderTest`
Expected: PASS (the new theme/font enqueue tests; the Task-3 wrapper tests still pass).

> The CSS files don't exist yet (Tasks 5-7), but registration/enqueue only references URLs — WordPress doesn't check file existence at enqueue time, so these tests pass now. The files are created next.

- [ ] **Step 7: Commit**

```bash
git add includes/frontend/class-assets.php tests/integration/ThemeRenderTest.php
git commit -m "feat(assets): register + conditionally enqueue per-theme token & font CSS"
```

---

## Task 5: Refactor `frontend.css` to tokens + neutral skeleton (light & dark)

**Files:**
- Modify: `assets/css/frontend.css`

**Interfaces:**
- Consumes: nothing (CSS). Produces: structural CSS reading the 34-token vocabulary; neutral skeleton tokens (light) scoped to the three wrappers; neutral dark block under `[data-skmctf-mode="dark"]`; the map dark-tile filter.

> **No automated test** — this is presentational. Verified manually in Task 9. Keep this task focused: do not change any class names or selectors that the markup emits; only (a) replace hardcoded values with `var(--token, fallback)`, (b) add the token-definition blocks, (c) add the map dark rule.

- [ ] **Step 1: Replace the design's structural CSS as the baseline**

The design file (`Clinical Trial Themes.dc.html`, captured in the main task context) contains the full structural CSS for `.skmctf-stage`, cards, single-trial layout, badges, filters, map, pagination — all written against `var(--…)`. Port that structural CSS into `frontend.css`, **rewriting its top-level scoping selector** from the design's `.skmctf-stage` to the plugin's three real wrappers. Concretely:
- Wherever the design scopes a rule under `.skmctf-stage`, scope it under `.skmctf-archive-trials, .skmctf-single-trial, .skmctf-trials-wrap` (the real wrappers).
- Keep every component selector (`.skmctf-card`, `.skmctf-trial__purpose`, `.skmctf-badge--recruiting`, `.skmctf-filters__select`, `.skmctf-map`, `.skmctf-pagination`, etc.) exactly as in the design — they already match the plugin's output.
- Do NOT port the design's `.skmctf-studio` / `.skmctf-toolbar` / `.skmctf-seg` chrome — that's the demo harness, not the deliverable (the design file marks it "STUDIO CHROME (not part of the deliverable)").
- The select chevron: the design sets `--chev` via JS. Instead, declare `--chev` as a token in each light/dark block (Steps 2-3 below and the theme files) and keep the `background-image: var(--chev)` usage in `.skmctf-filters__select`.

- [ ] **Step 2: Add the neutral skeleton LIGHT token block**

At the top of `frontend.css` (after any reset, before component rules), add — values verbatim from `c:\tmp\skmctf-design-tokens.md` "neutral light":

```css
/* ===== Skeleton (default) tokens — neutral, scoped to plugin wrappers ===== */
.skmctf-archive-trials,
.skmctf-single-trial,
.skmctf-trials-wrap {
	--font-head: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
	--font-body: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
	--head-ls: -.01em; --head-wt: 700;
	--bg: #f4f5f7; --surface: #ffffff; --surface-2: #f7f8fa; --inset: #eef0f3;
	--ink: #1c2126; --ink-2: #3d454d; --muted: #5f6a73; --faint: #8b949c;
	--border: #e0e3e8; --border-strong: #cbd0d6;
	--primary: #2c5f7c; --primary-2: #234c63; --on-primary: #ffffff; --primary-soft: #e7eef3; --primary-line: #2c5f7c;
	--link: #2c5f7c;
	--rec: #1f7a4d; --rec-bg: #e6f3ec; --rec-line: #52a878;
	--enr: #8a6010; --enr-bg: #f6eed8; --enr-line: #c6a54a;
	--radius: 6px; --radius-sm: 4px; --radius-lg: 8px; --pill-radius: 4px;
	--shadow: 0 1px 2px rgba(20,28,36,.06), 0 1px 3px rgba(20,28,36,.04);
	--shadow-hover: 0 6px 18px -8px rgba(20,28,36,.25);
	--map-h: 330px;
	--chev: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235f6a73' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
```

- [ ] **Step 3: Add the neutral skeleton DARK token block**

Immediately after, add — values verbatim from the token reference "neutral dark":

```css
/* ===== Skeleton dark tokens (scoped; never on :root) ===== */
.skmctf-archive-trials[data-skmctf-mode="dark"],
.skmctf-single-trial[data-skmctf-mode="dark"],
.skmctf-trials-wrap[data-skmctf-mode="dark"] {
	--bg: #10151a; --surface: #1a2128; --surface-2: #20272f; --inset: #151b21;
	--ink: #e8edf2; --ink-2: #bcc6cf; --muted: #8c97a1; --faint: #647079;
	--border: #2a333c; --border-strong: #39444e;
	--primary: #5aa6c9; --primary-2: #74b8d6; --on-primary: #08202b; --primary-soft: #152a35; --primary-line: #5aa6c9;
	--link: #74b8d6;
	--rec: #56cc97; --rec-bg: #10301f; --rec-line: #2f8f64;
	--enr: #e0b25a; --enr-bg: #2f2510; --enr-line: #8a6f2e;
	--shadow: 0 1px 2px rgba(0,0,0,.4);
	--shadow-hover: 0 8px 24px -10px rgba(0,0,0,.65);
	--chev: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238c97a1' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
```

- [ ] **Step 4: Add the map dark-tile rule**

From the design, add (works for all themes in dark because it keys only on the mode attribute under a plugin wrapper):

```css
/* Dark mode: tone down OSM tiles + theme the Leaflet controls. */
[data-skmctf-mode="dark"] .skmctf-map .leaflet-tile {
	filter: invert(1) hue-rotate(180deg) brightness(.92) contrast(.92) saturate(.8);
}
[data-skmctf-mode="dark"] .skmctf-map .leaflet-control-zoom a {
	background: var(--surface); color: var(--ink); border-color: var(--border) !important;
}
[data-skmctf-mode="dark"] .skmctf-map .leaflet-control-attribution {
	background: color-mix(in srgb, var(--surface) 82%, transparent); color: var(--muted);
}
[data-skmctf-mode="dark"] .skmctf-map .leaflet-control-attribution a { color: var(--link); }
```

- [ ] **Step 5: Sanity-check no hardcoded colors remain in component rules**

Run: `rg -n "#[0-9a-fA-F]{3,6}" assets/css/frontend.css`
Expected: hex values appear ONLY inside the three token-definition blocks (skeleton light, skeleton dark) and the embedded `--chev` SVG strokes. Component rules (`.skmctf-card`, etc.) reference `var(--…)` only. Fix any stragglers by replacing the literal with the matching `var(--token)`.

- [ ] **Step 6: Commit**

```bash
git add assets/css/frontend.css
git commit -m "refactor(css): token-driven frontend.css with neutral skeleton (light+dark)"
```

---

## Task 6: Theme token files — `clinical.css` and `warm.css`

**Files:**
- Create: `assets/css/themes/clinical.css`
- Create: `assets/css/themes/warm.css`

**Interfaces:** none (CSS). Each defines the light block + the `[data-skmctf-mode="dark"]` block for its skin. Values verbatim from `c:\tmp\skmctf-design-tokens.md`.

- [ ] **Step 1: Create `assets/css/themes/clinical.css`**

Paste the clinical light + dark blocks from the token reference. Skeleton structure:

```css
/* Clinical theme tokens. Consumed by the token-driven frontend.css. */
.skmctf-skin--clinical {
	--font-head: 'Public Sans', system-ui, sans-serif;
	--font-body: 'Public Sans', system-ui, sans-serif;
	--head-ls: -.02em; --head-wt: 700;
	--bg: #eef2f6; --surface: #ffffff; --surface-2: #f4f7fa; --inset: #eaf0f5;
	--ink: #16222e; --ink-2: #3a4b59; --muted: #5d6e7c; --faint: #8395a3;
	--border: #d8e0e8; --border-strong: #c3cfda;
	--primary: #0e6e8c; --primary-2: #0a566e; --on-primary: #ffffff; --primary-soft: #e2eff4; --primary-line: #0e6e8c;
	--link: #0c6480;
	--rec: #16794f; --rec-bg: #e2f2ea; --rec-line: #3aa776;
	--enr: #9a6512; --enr-bg: #f8eed7; --enr-line: #caa54a;
	--radius: 8px; --radius-sm: 6px; --radius-lg: 10px; --pill-radius: 5px;
	--shadow: 0 1px 2px rgba(16,34,48,.06), 0 1px 3px rgba(16,34,48,.05);
	--shadow-hover: 0 8px 24px -10px rgba(14,110,140,.35);
	--map-h: 330px;
	--chev: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235d6e7c' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
.skmctf-skin--clinical[data-skmctf-mode="dark"] {
	--bg: #0b1219; --surface: #121d27; --surface-2: #172430; --inset: #0f1922;
	--ink: #e9f1f7; --ink-2: #b8c8d4; --muted: #8ea1b0; --faint: #65798a;
	--border: #243441; --border-strong: #2e4150;
	--primary: #3fa9c9; --primary-2: #5cbcd8; --on-primary: #05202a; --primary-soft: #16323f; --primary-line: #3fa9c9;
	--link: #5cbcd8;
	--rec: #56cc97; --rec-bg: #103025; --rec-line: #2f8f64;
	--enr: #e0b25a; --enr-bg: #33270f; --enr-line: #9a7a32;
	--shadow: 0 1px 2px rgba(0,0,0,.4);
	--shadow-hover: 0 10px 30px -12px rgba(0,0,0,.7);
	--chev: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238ea1b0' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
```

- [ ] **Step 2: Create `assets/css/themes/warm.css`**

```css
/* Patient-friendly (warm) theme tokens. Consumed by the token-driven frontend.css. */
.skmctf-skin--warm {
	--font-head: 'Newsreader', Georgia, serif;
	--font-body: 'Mulish', system-ui, sans-serif;
	--head-ls: -.01em; --head-wt: 560;
	--bg: #f8f2ea; --surface: #ffffff; --surface-2: #fbf5ee; --inset: #f3ebe0;
	--ink: #2c2622; --ink-2: #54493f; --muted: #7a6d60; --faint: #9c8e7f;
	--border: #ece0d2; --border-strong: #ddccb9;
	--primary: #2f7d6b; --primary-2: #266659; --on-primary: #ffffff; --primary-soft: #e6f0ea; --primary-line: #2f7d6b;
	--link: #2c6f5f;
	--rec: #3a875c; --rec-bg: #e7f1e7; --rec-line: #5aab78;
	--enr: #b9682f; --enr-bg: #f8e7d8; --enr-line: #d68a52;
	--radius: 16px; --radius-sm: 11px; --radius-lg: 22px; --pill-radius: 999px;
	--shadow: 0 2px 4px rgba(120,90,60,.06), 0 6px 18px -10px rgba(120,90,60,.18);
	--shadow-hover: 0 16px 38px -16px rgba(47,125,107,.4);
	--map-h: 360px;
	--chev: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237a6d60' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
.skmctf-skin--warm[data-skmctf-mode="dark"] {
	--bg: #1d1815; --surface: #29221d; --surface-2: #312822; --inset: #221c18;
	--ink: #f3ebe1; --ink-2: #cfc1b2; --muted: #a89a8a; --faint: #7e6f5f;
	--border: #3c322b; --border-strong: #4a3d33;
	--primary: #62b39c; --primary-2: #78c4ae; --on-primary: #06231b; --primary-soft: #1d352e; --primary-line: #62b39c;
	--link: #78c4ae;
	--rec: #6cc28e; --rec-bg: #16301f; --rec-line: #3f8f5e;
	--enr: #e0935f; --enr-bg: #3a2719; --enr-line: #a56838;
	--shadow: 0 2px 4px rgba(0,0,0,.35);
	--shadow-hover: 0 18px 44px -18px rgba(0,0,0,.7);
	--chev: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23a89a8a' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
}
```

- [ ] **Step 3: Commit**

```bash
git add assets/css/themes/clinical.css assets/css/themes/warm.css
git commit -m "feat(css): clinical and warm theme token files (light + dark)"
```

---

## Task 7: Bundle fonts locally + `@font-face` sheets

**Files:**
- Create: `assets/fonts/*.woff2`
- Create: `assets/css/fonts-clinical.css`
- Create: `assets/css/fonts-warm.css`

**Interfaces:** none. `fonts-clinical.css` declares Public Sans; `fonts-warm.css` declares Newsreader (serif) + Mulish (sans). Weights to bundle: the design uses Public Sans 400/500/600/700/800 + italic 400; Mulish 400/500/600/700/800 + italic 400; Newsreader 400/500/600 + italic 400. To keep the bundle lean, ship **400/600/700** for each family (covers body, semibold, headings) plus Newsreader 500 (warm `--head-wt:560` rounds to 500/600). Italics only if a component uses them (the single-trial "official title" and who-note use italic in warm → include Newsreader italic 400 and Mulish italic 400).

- [ ] **Step 1: Obtain the woff2 files (GPL-compatible licenses)**

All three families are **SIL Open Font License 1.1** (GPL-compatible). Acquire the `.woff2` files WITHOUT a runtime CDN call (download at build time, commit the files):

```bash
# From the plugin root. Uses google-webfonts-helper (download only; files are committed, not fetched at runtime).
mkdir -p assets/fonts
# Public Sans 400,600,700 + italic 400
# Mulish 400,600,700 + italic 400
# Newsreader 400,500,600 + italic 400
```

Place files as (subset latin; keep names stable for the @font-face src):
```
assets/fonts/public-sans-400.woff2
assets/fonts/public-sans-600.woff2
assets/fonts/public-sans-700.woff2
assets/fonts/public-sans-italic-400.woff2
assets/fonts/mulish-400.woff2
assets/fonts/mulish-600.woff2
assets/fonts/mulish-700.woff2
assets/fonts/mulish-italic-400.woff2
assets/fonts/newsreader-400.woff2
assets/fonts/newsreader-500.woff2
assets/fonts/newsreader-600.woff2
assets/fonts/newsreader-italic-400.woff2
```

> If the implementing agent cannot download fonts in its environment, STOP and flag it — this is the one step that needs the actual binary assets. Do NOT hot-link Google Fonts as a fallback (violates the no-CDN constraint). Record the source + license in a short `assets/fonts/README.md`.

- [ ] **Step 2: Create `assets/css/fonts-clinical.css`**

```css
/* Public Sans — SIL OFL 1.1. Bundled locally; no external requests. */
@font-face { font-family: 'Public Sans'; font-style: normal; font-weight: 400; font-display: swap; src: url('../fonts/public-sans-400.woff2') format('woff2'); }
@font-face { font-family: 'Public Sans'; font-style: normal; font-weight: 600; font-display: swap; src: url('../fonts/public-sans-600.woff2') format('woff2'); }
@font-face { font-family: 'Public Sans'; font-style: normal; font-weight: 700; font-display: swap; src: url('../fonts/public-sans-700.woff2') format('woff2'); }
@font-face { font-family: 'Public Sans'; font-style: italic; font-weight: 400; font-display: swap; src: url('../fonts/public-sans-italic-400.woff2') format('woff2'); }
```

- [ ] **Step 3: Create `assets/css/fonts-warm.css`**

```css
/* Newsreader + Mulish — SIL OFL 1.1. Bundled locally; no external requests. */
@font-face { font-family: 'Newsreader'; font-style: normal; font-weight: 400; font-display: swap; src: url('../fonts/newsreader-400.woff2') format('woff2'); }
@font-face { font-family: 'Newsreader'; font-style: normal; font-weight: 500; font-display: swap; src: url('../fonts/newsreader-500.woff2') format('woff2'); }
@font-face { font-family: 'Newsreader'; font-style: normal; font-weight: 600; font-display: swap; src: url('../fonts/newsreader-600.woff2') format('woff2'); }
@font-face { font-family: 'Newsreader'; font-style: italic; font-weight: 400; font-display: swap; src: url('../fonts/newsreader-italic-400.woff2') format('woff2'); }
@font-face { font-family: 'Mulish'; font-style: normal; font-weight: 400; font-display: swap; src: url('../fonts/mulish-400.woff2') format('woff2'); }
@font-face { font-family: 'Mulish'; font-style: normal; font-weight: 600; font-display: swap; src: url('../fonts/mulish-600.woff2') format('woff2'); }
@font-face { font-family: 'Mulish'; font-style: normal; font-weight: 700; font-display: swap; src: url('../fonts/mulish-700.woff2') format('woff2'); }
@font-face { font-family: 'Mulish'; font-style: italic; font-weight: 400; font-display: swap; src: url('../fonts/mulish-italic-400.woff2') format('woff2'); }
```

- [ ] **Step 4: Confirm `.distignore` keeps `assets/`**

Run: `rg -n "assets|fonts" .distignore`
Expected: `assets/` is NOT excluded (the original `.distignore` keeps `assets/`). If `assets/fonts` is somehow excluded, fix it so fonts ship in the .org build.

- [ ] **Step 5: Commit**

```bash
git add assets/fonts assets/css/fonts-clinical.css assets/css/fonts-warm.css
git commit -m "feat(fonts): bundle Public Sans, Newsreader, Mulish locally (SIL OFL, no CDN)"
```

---

## Task 8: Admin UI — "Theme & appearance" section

**Files:**
- Modify: `includes/admin/views/settings-page.php`

**Interfaces:**
- Consumes: `$s` (settings array, in scope), `Settings::OPTION`, the new `theme`/`theme_mode` keys.
- Produces: two `<select>` controls that persist through the existing form.

> **No new test** — persistence is covered by Task 1's sanitizer test; rendering is verified visually in Task 9. The save path is the existing Settings API form (nonce + `manage_options` already enforced).

- [ ] **Step 1: Add a label map near the top of the view**

In `includes/admin/views/settings-page.php`, after the `$all_display_fields = array( … );` block (~line 43), add:

```php
$all_themes = array(
	'skeleton' => __( 'Skeleton (default)', 'kisho-clinical-trials' ),
	'clinical' => __( 'Clinical', 'kisho-clinical-trials' ),
	'warm'     => __( 'Patient-friendly', 'kisho-clinical-trials' ),
);
$all_modes = array(
	'light' => __( 'Light', 'kisho-clinical-trials' ),
	'dark'  => __( 'Dark', 'kisho-clinical-trials' ),
);
```

- [ ] **Step 2: Add the section markup**

Insert immediately AFTER the Display table's closing `</table>` (line 240) and BEFORE the `<!-- Map View Configuration -->` comment (line 242):

```php
				<!-- ── Theme & appearance ──────────────────────────────── -->
				<h2><?php esc_html_e( 'Theme &amp; appearance', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">
							<label for="skmctf_theme"><?php esc_html_e( 'Theme', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_theme" name="<?php echo esc_attr( Settings::OPTION ); ?>[theme]">
								<?php foreach ( $all_themes as $theme_key => $theme_label ) : ?>
									<option
										value="<?php echo esc_attr( $theme_key ); ?>"
										<?php selected( $s['theme'], $theme_key ); ?>
									>
										<?php echo esc_html( $theme_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Out-of-the-box look for the trials list and single-trial pages. Skeleton keeps the default styling.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_theme_mode"><?php esc_html_e( 'Appearance', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_theme_mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[theme_mode]">
								<?php foreach ( $all_modes as $mode_key => $mode_label ) : ?>
									<option
										value="<?php echo esc_attr( $mode_key ); ?>"
										<?php selected( $s['theme_mode'], $mode_key ); ?>
									>
										<?php echo esc_html( $mode_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Light or dark appearance. Applies to all themes, including Skeleton.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>

				</table>
```

> Mirrors the provider-select pattern exactly: `name="<?php echo esc_attr( Settings::OPTION ); ?>[KEY]"`, `selected( $s['KEY'], $value )`, `Settings` referenced unqualified (the view imports it via `use SKMCTF\Admin\Settings;`). Labels are translatable (not `ucfirst`).

- [ ] **Step 3: Verify it renders + saves (manual quick check)**

Run the app (LocalWP). Open Settings → Clinical Trials. Confirm the new "Theme & appearance" section shows two dropdowns, change Theme to Clinical + Appearance to Dark, Save, and confirm the selects retain the saved values on reload.

- [ ] **Step 4: Commit**

```bash
git add includes/admin/views/settings-page.php
git commit -m "feat(admin): Theme & appearance settings section (two selects)"
```

---

## Task 9: Version bump, changelog, and full verification

**Files:**
- Modify: `kisho-clinical-trials.php` (header `Version`), the `SKMCTF_VERSION` define, `readme.txt` (`Stable tag` + changelog)

- [ ] **Step 1: Bump version to 1.2.0**

- In `kisho-clinical-trials.php`: change the header `* Version:           1.1.3` → `1.2.0`, and the `define( 'SKMCTF_VERSION', '1.1.3' );` → `'1.2.0'`.
- In `readme.txt`: change `Stable tag: 1.1.3` → `1.2.0`.

- [ ] **Step 2: Add the changelog entry**

At the top of the `== Changelog ==` section in `readme.txt`:

```
= 1.2.0 =
* New: Two optional, admin-selectable front-end themes — **Clinical** and **Patient-friendly** — each available in light and dark. Choose them under Settings → Clinical Trials → Theme & appearance. The default (**Skeleton**) is unchanged in behavior and now also offers a dark mode.
* New: Themes are pure CSS skins over the existing markup; fonts (Public Sans, Newsreader, Mulish) are bundled locally — no external requests.
* Improved: The trials list and single-trial layout were refined as the shared baseline structure for all themes.
```

- [ ] **Step 3: Run the full unit + integration suites**

Run: `composer test:unit`
Expected: PASS (existing + `ThemeTest`, `SettingsThemeTest`).

Run: `composer test:integration -- --filter ThemeRenderTest`
Expected: PASS.

> Per project memory: integration tests need the LocalWP MySQL (port 10120) and a fresh install via the git-based WP test installer; restart OPcache before verifying if results look stale.

- [ ] **Step 4: Run PHPCS**

Run: `composer phpcs`
Expected: no new errors in the modified/created PHP files. Fix any escaping/spacing nits with `composer phpcbf` then re-run.

- [ ] **Step 5: Manual visual matrix (Playwright against plugin-sandbox.local)**

For each of the 6 combinations (theme ∈ {skeleton, clinical, warm} × mode ∈ {light, dark}), set the two settings, then load:
- a trials archive/listing page (block or shortcode), and
- one single-trial page.

Confirm for each: correct palette + fonts (loaded from `assets/fonts/`, not Google), card/single layout matches the design, badges have AA contrast, the map renders (dark tiles toned down in dark mode), and the **surrounding site header/footer are visually untouched** (scope = plugin markup only). Capture one screenshot per combination.

- [ ] **Step 6: Commit**

```bash
git add kisho-clinical-trials.php readme.txt
git commit -m "chore: release 1.2.0 — clinical-trial themes (clinical/warm, light/dark)"
```

---

## Self-Review

**Spec coverage:**
- §2 Decisions 1-6 → Tasks 1 (settings), 3 (plugin-markup scope via wrapper selectors), 5-6 (token files, fixed mode), 7 (local fonts), 5 (refined baseline), 5+6 (skeleton+all themes light/dark). ✓
- §3 architecture (`Theme` resolver) → Task 2. ✓
- §4 components → every file in the table maps to a task. ✓ (`map.js` deliberately unchanged per spec correction.)
- §5 token system + scoping rule → Tasks 5-6 (scoped selectors; dark never on `:root`). ✓
- §6 admin UX/defaults/sanitizer → Tasks 1 + 8. ✓
- §7 testing → Tasks 1, 2, 3, 4 (unit + integration), Task 9 (phpcs + visual). ✓
- §8 out-of-scope (no Auto, no custom colors, front-end only) → honored; not implemented. ✓

**Placeholder scan:** No TBD/TODO in steps. The one external dependency (downloading woff2 files, Task 7 Step 1) is explicitly flagged with a STOP-and-ask if the environment can't fetch them. CSS hex values are concrete (from the token reference), not placeholders.

**Type consistency:** `Theme::skin_class()`, `Theme::mode_attr()`, `Theme::has_skin()`, `Theme::active()`, `Theme::mode()` are defined in Task 2 and used identically in Tasks 3-4. `Assets::THEME_STYLE_PREFIX`/`FONT_STYLE_PREFIX` defined and used in Task 4; the resulting handles (`skmctf-theme-clinical`, `skmctf-fonts-clinical`) match Task 4's tests. `Settings::VALID_THEMES`/`VALID_THEME_MODES`/`theme()`/`theme_mode()` defined in Task 1, used in Task 2. Consistent. ✓
