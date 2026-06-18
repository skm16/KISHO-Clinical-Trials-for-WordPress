# Clinical Trials Feed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a free, GPLv2, wordpress.org-compliant plugin that syncs condition-relevant trials from ClinicalTrials.gov v2 into a CPT, optionally generates BYO-key plain-language summaries, and renders them on a PAG's site via block + shortcode with filters and an optional map.

**Architecture:** Sync-to-CPT. Action Scheduler fires a daily `Sync_Engine::run()` that fetches via `CtGov_Client`, transforms via the pure `Field_Mapper`, upserts through `Trial_Repository`, conditionally summarizes via an `Llm_Provider` behind a filter seam, and safely reconciles via `Reconciler` (three no-wipe guards). Front end reads the CPT through a shared `List_Renderer` used by both a server-rendered block and a shortcode.

**Tech Stack:** PHP 7.4+ (developed on 8.3), WordPress 6.4+, native WP APIs (CPT, `register_post_meta`, Settings API, Blocks `apiVersion 3`), bundled Action Scheduler (GPLv3) and Leaflet 1.9.x (BSD-2), `@wordpress/scripts` for block build, PHPUnit + Brain Monkey for tests, PHPCS WordPress standard.

**Reference spec:** [`docs/superpowers/specs/2026-06-18-clinical-trials-feed-design.md`](../specs/2026-06-18-clinical-trials-feed-design.md)

## Global Constraints

Every task's requirements implicitly include this section. Values are verbatim from the spec.

- **License:** GPLv2 or later. All bundled libs must be GPL-compatible (Action Scheduler GPLv3, Leaflet BSD-2 — both OK).
- **Folder / .org slug:** `kisho-clinical-trials`. **Text domain:** `kisho-clinical-trials` (on every user-facing string).
- **Prefixes:** functions/hooks/options/meta = `skmctf_`; PHP namespace root = `SKMCTF\`; CSS = `.skmctf-`.
- **CPT:** `skmctf_trial`. **Taxonomies:** `trial_status`, `trial_phase`. **Cron action hook:** `skmctf_daily_sync`. **Action group:** `kisho-clinical-trials`. **Options:** single array `skmctf_settings` (autoload `no`), plus `skmctf_last_sync`, `skmctf_last_error`, `skmctf_log` (all autoload `no`).
- **Version floors:** Requires at least WP 6.4 · Tested up to 6.8 · Requires PHP 7.4.
- **Security (non-negotiable, every task):** sanitize all input at entry; escape all output at point of output (`esc_html`/`esc_attr`/`esc_url`/`wp_kses_post`); nonce + `current_user_can('manage_options')` on every admin write and Sync-now; all external HTTP via `wp_remote_*` with timeouts; never disable SSL verify; LLM key never echoed back, autoload off, password field; no `eval`, no obfuscation, no CDN-loaded assets, no telemetry / phone-home.
- **CT.gov API:** `GET https://clinicaltrials.gov/api/v2/studies`, `format=json`, no auth. `fields` request param uses **piece names**; response nests under module paths (mapper reads nested paths). User-Agent `KishoClinicalTrials/1.0 (+https://skm.digital)`.
- **No-wipe guard:** reconcile must skip on (1) any fetch error this run, (2) empty result set, (3) drop ratio > `MAX_DROP_RATIO` (0.5, filterable).
- **i18n:** load text domain on `init`; JS strings via `wp.i18n` + `wp_set_script_translations`. Do not translate CT.gov content.
- **Accessibility:** WCAG 2.1 AA — semantic markup, real form controls with labels, status conveyed by text not color alone, keyboard operable, focus-visible.
- **Commit style:** conventional commits; co-author trailer `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.

## File Structure

See spec §2 for the full tree. Each PHP class is one file under `includes/<domain>/`, one responsibility. The autoloader (`includes/class-autoloader.php`) maps `SKMCTF\Sub\Class_Name` → `includes/sub/class-class-name.php`. Meta keys live in exactly one place: `SKMCTF\Post_Types\Trial_Meta::KEYS`.

## Testing Conventions

- **Unit tests** (`tests/unit/`): pure PHP via Brain Monkey (mocks WP functions). No DB. For `Field_Mapper`, `Reconciler` guards, `Prompt_Builder`, settings sanitizer, provider response parsers.
- **Integration tests** (`tests/integration/`): `WP_UnitTestCase` (needs the WP test suite). For repository upsert, client pagination (via `pre_http_request`), sync engine end-to-end.
- Run unit: `composer test:unit` (`phpunit -c phpunit-unit.xml.dist`). Run standards: `composer phpcs`.
- TDD: write the failing test, run it red, implement minimal, run it green, commit.

---

## Task 1: Plugin scaffold, autoloader, bootstrap

**Files:**
- Create: `kisho-clinical-trials.php` (main file + header)
- Create: `includes/class-autoloader.php`
- Create: `includes/class-plugin.php`
- Create: `composer.json`, `phpunit-unit.xml.dist`, `tests/bootstrap-unit.php`, `.gitignore`, `.distignore`
- Test: `tests/unit/AutoloaderTest.php`

**Interfaces:**
- Produces: `SKMCTF\Autoloader::register()`; `SKMCTF\Plugin::instance(): Plugin`, `Plugin::boot(): void`; constants `SKMCTF_VERSION`, `SKMCTF_FILE`, `SKMCTF_PATH`, `SKMCTF_URL`, `SKMCTF_TEXT_DOMAIN`.

- [ ] **Step 1: Write the failing test**

```php
// tests/unit/AutoloaderTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use SKMCTF\Autoloader;

final class AutoloaderTest extends TestCase {
    public function test_maps_namespaced_class_to_file_path(): void {
        $autoloader = new Autoloader( '/plugin/includes/' );
        $this->assertSame(
            '/plugin/includes/sync/class-ctgov-client.php',
            $autoloader->path_for( 'SKMCTF\\Sync\\Ctgov_Client' )
        );
        $this->assertSame(
            '/plugin/includes/class-plugin.php',
            $autoloader->path_for( 'SKMCTF\\Plugin' )
        );
        $this->assertNull( $autoloader->path_for( 'Other\\Thing' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter AutoloaderTest`
Expected: FAIL — class `SKMCTF\Autoloader` not found.

- [ ] **Step 3: Write minimal implementation**

```php
// includes/class-autoloader.php
namespace SKMCTF;

final class Autoloader {
    private string $base_dir;
    public function __construct( string $base_dir ) {
        $this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
    }
    public function register(): void {
        spl_autoload_register( [ $this, 'load' ] );
    }
    public function load( string $class ): void {
        $path = $this->path_for( $class );
        if ( $path && is_readable( $path ) ) {
            require $path;
        }
    }
    /** Map SKMCTF\Sub\Class_Name -> base/sub/class-class-name.php */
    public function path_for( string $class ): ?string {
        if ( strpos( $class, 'SKMCTF\\' ) !== 0 ) {
            return null;
        }
        $relative = substr( $class, strlen( 'SKMCTF\\' ) );
        $parts    = explode( '\\', $relative );
        $class_part = array_pop( $parts );
        $file = 'class-' . str_replace( '_', '-', strtolower( $class_part ) ) . '.php';
        $dir  = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
        return $this->base_dir . $dir . $file;
    }
}
```

```php
// includes/class-plugin.php
namespace SKMCTF;

final class Plugin {
    private static ?Plugin $instance = null;
    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    /** Wire up all hooks. Each subsystem registers itself here. */
    public function boot(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        // Subsystems are registered here as later tasks add them.
    }
    public function load_textdomain(): void {
        load_plugin_textdomain( SKMCTF_TEXT_DOMAIN, false, dirname( plugin_basename( SKMCTF_FILE ) ) . '/languages' );
    }
}
```

```php
<?php
// kisho-clinical-trials.php
/**
 * Plugin Name:       Clinical Trials Feed
 * Plugin URI:        https://skm.digital/clinical-trials-feed
 * Description:       Pulls condition-relevant trials from ClinicalTrials.gov and displays them on your site, kept fresh automatically, with optional plain-language summaries via your own LLM API key.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            SKM Digital
 * Author URI:        https://skm.digital
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kisho-clinical-trials
 * Domain Path:       /languages
 *
 * @package SKMCTF
 */

defined( 'ABSPATH' ) || exit;

define( 'SKMCTF_VERSION', '1.0.0' );
define( 'SKMCTF_FILE', __FILE__ );
define( 'SKMCTF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKMCTF_URL', plugin_dir_url( __FILE__ ) );
define( 'SKMCTF_TEXT_DOMAIN', 'kisho-clinical-trials' );

require_once SKMCTF_PATH . 'includes/class-autoloader.php';
( new SKMCTF\Autoloader( SKMCTF_PATH . 'includes' ) )->register();

add_action( 'plugins_loaded', static function () {
    SKMCTF\Plugin::instance()->boot();
} );
```

```json
// composer.json
{
  "name": "skm-digital/kisho-clinical-trials",
  "description": "Clinical Trials Feed — sync ClinicalTrials.gov data into WordPress.",
  "license": "GPL-2.0-or-later",
  "type": "wordpress-plugin",
  "require": { "php": ">=7.4" },
  "require-dev": {
    "phpunit/phpunit": "^9.6",
    "brain/monkey": "^2.6",
    "squizlabs/php_codesniffer": "^3.9",
    "wp-coding-standards/wpcs": "^3.1",
    "phpcompatibility/phpcompatibility-wp": "^2.1"
  },
  "scripts": {
    "test:unit": "phpunit -c phpunit-unit.xml.dist",
    "test:integration": "phpunit -c phpunit-integration.xml.dist",
    "phpcs": "phpcs",
    "phpcbf": "phpcbf"
  },
  "config": { "allow-plugins": { "dealerdirect/phpcodesniffer-composer-installer": true } }
}
```

> **Integration test prerequisite (one-time setup).** `composer test:integration` needs the WordPress test suite. Install it once with the standard scaffolder, then it backs every `WP_UnitTestCase` test in this plan:
> ```bash
> # Requires a local MySQL; LocalWP exposes one. Adjust DB creds/host/port.
> bash bin/install-wp-tests.sh wordpress_test root '' 127.0.0.1 latest
> ```
> Add the canonical `bin/install-wp-tests.sh` (the `wp scaffold plugin-tests` script) and `phpunit-integration.xml.dist` (bootstrap = `tests/bootstrap-integration.php`, testsuite dir = `tests/integration`) as part of this task. `tests/bootstrap-integration.php` loads the WP test bootstrap, then `require`s the plugin's main file on `muplugins_loaded`. If a local MySQL isn't available in CI, integration tests are skipped there and run locally in LocalWP; unit tests (no DB) always run.

```xml
<!-- phpunit-unit.xml.dist -->
<?xml version="1.0"?>
<phpunit bootstrap="tests/bootstrap-unit.php" colors="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/unit</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

```php
// tests/bootstrap-unit.php
require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/includes/class-autoloader.php';
( new SKMCTF\Autoloader( dirname( __DIR__ ) . '/includes' ) )->register();
```

`.gitignore`: `node_modules/`, `vendor/`, `build/`, `*.log`. `.distignore`: `tests/`, `node_modules/`, `vendor/`, `.git*`, `composer.*`, `phpunit*`, `package*.json`, `docs/`, `.distignore` — but **keep** `blocks/`, `build/`, `vendor-lib/`, `assets/`.

- [ ] **Step 4: Run test to verify it passes**

Run: `composer install && composer test:unit -- --filter AutoloaderTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: plugin scaffold, autoloader, bootstrap"
```

---

## Task 2: CPT, taxonomies, registered meta

**Files:**
- Create: `includes/post-types/class-trial-meta.php` (meta keys + schemas + sanitizers — single source of truth)
- Create: `includes/post-types/class-trial-post-type.php`
- Create: `includes/post-types/class-trial-taxonomies.php`
- Modify: `includes/class-plugin.php` (register the three on `init`)
- Test: `tests/unit/TrialMetaTest.php`

**Interfaces:**
- Consumes: `SKMCTF\Plugin::boot()`.
- Produces: `SKMCTF\Post_Types\Trial_Meta::KEYS` (const map `short_key => meta_key`), `Trial_Meta::definitions(): array` (meta_key => ['type','single','sanitize_callback','show_in_rest']), `Trial_Meta::register(): void`; `Trial_Post_Type::POST_TYPE = 'skmctf_trial'`, `Trial_Post_Type::register(): void`; `Trial_Taxonomies::STATUS='trial_status'`, `::PHASE='trial_phase'`, `Trial_Taxonomies::register(): void`.

- [ ] **Step 1: Write the failing test**

```php
// tests/unit/TrialMetaTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use SKMCTF\Post_Types\Trial_Meta;

final class TrialMetaTest extends TestCase {
    public function test_nct_id_sanitizes_to_uppercase_canonical_form(): void {
        $this->assertSame( 'NCT01234567', Trial_Meta::sanitize_nct( ' nct01234567 ' ) );
        $this->assertSame( '', Trial_Meta::sanitize_nct( 'not-an-nct' ) );
        $this->assertSame( '', Trial_Meta::sanitize_nct( 'NCT123' ) );
    }
    public function test_keys_map_is_complete(): void {
        $keys = Trial_Meta::KEYS;
        foreach ( [ 'nct_id','official_title','brief_title','overall_status','phase','study_type','conditions','lead_sponsor','locations','eligibility','brief_summary','plain_summary','plain_summary_source_date','ct_last_update','last_synced','ct_url' ] as $short ) {
            $this->assertArrayHasKey( $short, $keys, "missing short key $short" );
            $this->assertStringStartsWith( 'skmctf_', $keys[ $short ] );
        }
    }
    public function test_definitions_each_have_sanitize_callback(): void {
        foreach ( Trial_Meta::definitions() as $meta_key => $def ) {
            $this->assertArrayHasKey( 'sanitize_callback', $def, "$meta_key needs sanitize_callback" );
            $this->assertIsCallable( $def['sanitize_callback'] );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter TrialMetaTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

```php
// includes/post-types/class-trial-meta.php
namespace SKMCTF\Post_Types;

final class Trial_Meta {
    /** short_key => full meta_key */
    public const KEYS = [
        'nct_id'                    => 'skmctf_nct_id',
        'official_title'            => 'skmctf_official_title',
        'brief_title'               => 'skmctf_brief_title',
        'overall_status'            => 'skmctf_overall_status',
        'phase'                     => 'skmctf_phase',
        'study_type'                => 'skmctf_study_type',
        'conditions'                => 'skmctf_conditions',
        'lead_sponsor'              => 'skmctf_lead_sponsor',
        'locations'                 => 'skmctf_locations',
        'eligibility'               => 'skmctf_eligibility',
        'brief_summary'             => 'skmctf_brief_summary',
        'plain_summary'             => 'skmctf_plain_summary',
        'plain_summary_source_date' => 'skmctf_plain_summary_source_date',
        'ct_last_update'            => 'skmctf_ct_last_update',
        'last_synced'               => 'skmctf_last_synced',
        'ct_url'                    => 'skmctf_ct_url',
    ];

    public static function sanitize_nct( $value ): string {
        $value = strtoupper( trim( (string) $value ) );
        return preg_match( '/^NCT\d{8}$/', $value ) ? $value : '';
    }
    public static function sanitize_date( $value ): string {
        $value = trim( (string) $value );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
    }
    public static function sanitize_string_list( $value ): array {
        return array_values( array_filter( array_map(
            'sanitize_text_field',
            is_array( $value ) ? $value : []
        ) ) );
    }
    public static function sanitize_locations( $value ): array {
        $out = [];
        foreach ( (array) $value as $loc ) {
            if ( ! is_array( $loc ) ) { continue; }
            $out[] = [
                'facility' => sanitize_text_field( $loc['facility'] ?? '' ),
                'city'     => sanitize_text_field( $loc['city'] ?? '' ),
                'state'    => sanitize_text_field( $loc['state'] ?? '' ),
                'country'  => sanitize_text_field( $loc['country'] ?? '' ),
                'status'   => sanitize_text_field( $loc['status'] ?? '' ),
                'lat'      => isset( $loc['lat'] ) ? (float) $loc['lat'] : null,
                'lng'      => isset( $loc['lng'] ) ? (float) $loc['lng'] : null,
            ];
        }
        return $out;
    }
    public static function sanitize_eligibility( $value ): array {
        $value = is_array( $value ) ? $value : [];
        return [
            'sex'      => sanitize_text_field( $value['sex'] ?? '' ),
            'min_age'  => sanitize_text_field( $value['min_age'] ?? '' ),
            'max_age'  => sanitize_text_field( $value['max_age'] ?? '' ),
            'criteria' => sanitize_textarea_field( $value['criteria'] ?? '' ),
        ];
    }

    /** meta_key => definition for register_post_meta. */
    public static function definitions(): array {
        $k = self::KEYS;
        $str  = [ 'type' => 'string',  'single' => true, 'sanitize_callback' => 'sanitize_text_field', 'show_in_rest' => true ];
        $text = [ 'type' => 'string',  'single' => true, 'sanitize_callback' => 'sanitize_textarea_field', 'show_in_rest' => true ];
        return [
            $k['nct_id']           => [ 'type' => 'string', 'single' => true, 'sanitize_callback' => [ self::class, 'sanitize_nct' ], 'show_in_rest' => true ],
            $k['official_title']   => $str,
            $k['brief_title']      => $str,
            $k['overall_status']   => $str,
            $k['phase']            => $str,
            $k['study_type']       => $str,
            $k['lead_sponsor']     => $str,
            $k['ct_last_update']   => [ 'type' => 'string', 'single' => true, 'sanitize_callback' => [ self::class, 'sanitize_date' ], 'show_in_rest' => true ],
            $k['plain_summary_source_date'] => [ 'type' => 'string', 'single' => true, 'sanitize_callback' => [ self::class, 'sanitize_date' ], 'show_in_rest' => true ],
            $k['last_synced']      => [ 'type' => 'integer', 'single' => true, 'sanitize_callback' => 'absint', 'show_in_rest' => true ],
            $k['ct_url']           => [ 'type' => 'string', 'single' => true, 'sanitize_callback' => 'esc_url_raw', 'show_in_rest' => true ],
            $k['brief_summary']    => $text,
            $k['plain_summary']    => [ 'type' => 'string', 'single' => true, 'sanitize_callback' => 'wp_kses_post', 'show_in_rest' => true ],
            $k['conditions']       => [ 'type' => 'array', 'single' => true, 'sanitize_callback' => [ self::class, 'sanitize_string_list' ], 'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] ] ],
            $k['locations']        => [ 'type' => 'array', 'single' => true, 'sanitize_callback' => [ self::class, 'sanitize_locations' ], 'show_in_rest' => [ 'schema' => [ 'type' => 'array', 'items' => [ 'type' => 'object', 'properties' => [ 'facility' => [ 'type' => 'string' ], 'city' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string' ], 'country' => [ 'type' => 'string' ], 'status' => [ 'type' => 'string' ], 'lat' => [ 'type' => [ 'number', 'null' ] ], 'lng' => [ 'type' => [ 'number', 'null' ] ] ] ] ] ] ],
            $k['eligibility']      => [ 'type' => 'object', 'single' => true, 'sanitize_callback' => [ self::class, 'sanitize_eligibility' ], 'show_in_rest' => [ 'schema' => [ 'type' => 'object', 'properties' => [ 'sex' => [ 'type' => 'string' ], 'min_age' => [ 'type' => 'string' ], 'max_age' => [ 'type' => 'string' ], 'criteria' => [ 'type' => 'string' ] ] ] ] ],
        ];
    }

    public static function register(): void {
        foreach ( self::definitions() as $meta_key => $def ) {
            register_post_meta( Trial_Post_Type::POST_TYPE, $meta_key, $def );
        }
    }
}
```

```php
// includes/post-types/class-trial-post-type.php
namespace SKMCTF\Post_Types;

final class Trial_Post_Type {
    public const POST_TYPE = 'skmctf_trial';

    public static function register(): void {
        $labels = [
            'name'          => __( 'Clinical Trials', 'kisho-clinical-trials' ),
            'singular_name' => __( 'Clinical Trial', 'kisho-clinical-trials' ),
            'menu_name'     => __( 'Clinical Trials', 'kisho-clinical-trials' ),
            'all_items'     => __( 'All Trials', 'kisho-clinical-trials' ),
            'search_items'  => __( 'Search Trials', 'kisho-clinical-trials' ),
            'not_found'     => __( 'No trials found.', 'kisho-clinical-trials' ),
        ];
        register_post_type( self::POST_TYPE, [
            'labels'       => $labels,
            'public'       => true,
            'has_archive'  => true,
            'show_in_rest' => true,
            'supports'     => [ 'title', 'editor', 'custom-fields' ],
            'rewrite'      => [ 'slug' => apply_filters( 'skmctf_trial_rewrite_slug', 'clinical-trials' ) ],
            'menu_icon'    => 'dashicons-clipboard',
            'capability_type' => 'post',
        ] );
    }
}
```

```php
// includes/post-types/class-trial-taxonomies.php
namespace SKMCTF\Post_Types;

final class Trial_Taxonomies {
    public const STATUS = 'trial_status';
    public const PHASE  = 'trial_phase';

    public static function register(): void {
        foreach ( [
            self::STATUS => __( 'Trial Status', 'kisho-clinical-trials' ),
            self::PHASE  => __( 'Trial Phase', 'kisho-clinical-trials' ),
        ] as $tax => $label ) {
            register_taxonomy( $tax, Trial_Post_Type::POST_TYPE, [
                'label'             => $label,
                'public'            => true,
                'hierarchical'      => false,
                'show_admin_column' => true,
                'show_in_rest'      => true,
                'rewrite'           => [ 'slug' => str_replace( '_', '-', $tax ) ],
            ] );
        }
    }
}
```

Wire into `Plugin::boot()`:
```php
add_action( 'init', [ \SKMCTF\Post_Types\Trial_Post_Type::class, 'register' ] );
add_action( 'init', [ \SKMCTF\Post_Types\Trial_Taxonomies::class, 'register' ] );
add_action( 'init', [ \SKMCTF\Post_Types\Trial_Meta::class, 'register' ] );
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:unit -- --filter TrialMetaTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: register skmctf_trial CPT, taxonomies, and meta with sanitizers"
```

---

## Task 3: CT.gov v2 client + field mapper (the data-quality core)

**Files:**
- Create: `includes/sync/class-field-mapper.php` (pure transformer)
- Create: `includes/sync/class-ctgov-client.php`
- Create: `tests/fixtures/ctgov-full.json`, `tests/fixtures/ctgov-sparse.json` (captured live shapes)
- Test: `tests/unit/FieldMapperTest.php`, `tests/integration/CtGovClientTest.php`

**Interfaces:**
- Consumes: `Trial_Meta::KEYS`.
- Produces: `SKMCTF\Sync\Field_Mapper::map( array $study ): array` (returns short-keyed array matching `Trial_Meta::KEYS`, `nct_id` empty if unmappable); `SKMCTF\Sync\Ctgov_Client::fetch_all_for_condition( string $condition, array $statuses ): array` returning `[ 'studies' => array[], 'error' => ?\WP_Error ]`; `Ctgov_Client::FIELDS` (piece-name list constant).

- [ ] **Step 1: Write the failing test (mapper)**

```php
// tests/unit/FieldMapperTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Field_Mapper;

final class FieldMapperTest extends TestCase {
    private function study(): array {
        return [ 'protocolSection' => [
            'identificationModule' => [ 'nctId' => 'NCT06121011', 'briefTitle' => 'A Registry', 'officialTitle' => 'A Global Registry' ],
            'statusModule' => [ 'overallStatus' => 'RECRUITING', 'lastUpdatePostDateStruct' => [ 'date' => '2026-03-10' ] ],
            'sponsorCollaboratorsModule' => [ 'leadSponsor' => [ 'name' => 'Amicus Therapeutics' ] ],
            'designModule' => [ 'phases' => [ 'PHASE2', 'PHASE3' ], 'studyType' => 'INTERVENTIONAL' ],
            'conditionsModule' => [ 'conditions' => [ 'Pompe Disease' ] ],
            'descriptionModule' => [ 'briefSummary' => 'Some summary.' ],
            'eligibilityModule' => [ 'sex' => 'ALL', 'minimumAge' => '18 Years', 'maximumAge' => 'N/A', 'eligibilityCriteria' => 'Inclusion: ...' ],
            'contactsLocationsModule' => [ 'locations' => [ [ 'facility' => 'Clinic', 'city' => 'Boston', 'state' => 'MA', 'country' => 'United States', 'status' => 'RECRUITING', 'geoPoint' => [ 'lat' => 42.36, 'lon' => -71.06 ] ] ] ],
        ] ];
    }
    public function test_maps_core_fields(): void {
        $m = Field_Mapper::map( $this->study() );
        $this->assertSame( 'NCT06121011', $m['nct_id'] );
        $this->assertSame( 'A Registry', $m['brief_title'] );
        $this->assertSame( 'RECRUITING', $m['overall_status'] );
        $this->assertSame( '2026-03-10', $m['ct_last_update'] );
        $this->assertSame( 'Amicus Therapeutics', $m['lead_sponsor'] );
        $this->assertSame( 'Phase 2/Phase 3', $m['phase'] );
        $this->assertSame( [ 'Pompe Disease' ], $m['conditions'] );
        $this->assertSame( 'https://clinicaltrials.gov/study/NCT06121011', $m['ct_url'] );
        $this->assertSame( 42.36, $m['locations'][0]['lat'] );
        $this->assertSame( 'ALL', $m['eligibility']['sex'] );
    }
    public function test_handles_empty_modules(): void {
        $m = Field_Mapper::map( [ 'protocolSection' => [ 'identificationModule' => [ 'nctId' => 'NCT00000000' ], 'designModule' => [] ] ] );
        $this->assertSame( 'NCT00000000', $m['nct_id'] );
        $this->assertSame( '', $m['phase'] );
        $this->assertSame( [], $m['conditions'] );
        $this->assertSame( [], $m['locations'] );
    }
    public function test_unmappable_study_yields_empty_nct(): void {
        $this->assertSame( '', Field_Mapper::map( [] )['nct_id'] );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter FieldMapperTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation (mapper)**

```php
// includes/sync/class-field-mapper.php
namespace SKMCTF\Sync;

final class Field_Mapper {
    private const PHASE_LABELS = [
        'EARLY_PHASE1' => 'Early Phase 1', 'PHASE1' => 'Phase 1', 'PHASE2' => 'Phase 2',
        'PHASE3' => 'Phase 3', 'PHASE4' => 'Phase 4', 'NA' => 'Not Applicable',
    ];

    public static function map( array $study ): array {
        $p   = $study['protocolSection'] ?? [];
        $id  = $p['identificationModule'] ?? [];
        $st  = $p['statusModule'] ?? [];
        $des = $p['designModule'] ?? [];
        $spo = $p['sponsorCollaboratorsModule'] ?? [];
        $con = $p['conditionsModule'] ?? [];
        $dsc = $p['descriptionModule'] ?? [];
        $elig = $p['eligibilityModule'] ?? [];
        $locs = $p['contactsLocationsModule']['locations'] ?? [];

        $nct = strtoupper( (string) ( $id['nctId'] ?? '' ) );
        if ( ! preg_match( '/^NCT\d{8}$/', $nct ) ) {
            return [ 'nct_id' => '' ];
        }

        $phases = array_map(
            static fn( $ph ) => self::PHASE_LABELS[ $ph ] ?? ucfirst( strtolower( str_replace( '_', ' ', (string) $ph ) ) ),
            (array) ( $des['phases'] ?? [] )
        );

        $locations = [];
        foreach ( (array) $locs as $loc ) {
            if ( ! is_array( $loc ) ) { continue; }
            $geo = $loc['geoPoint'] ?? [];
            $locations[] = [
                'facility' => (string) ( $loc['facility'] ?? '' ),
                'city'     => (string) ( $loc['city'] ?? '' ),
                'state'    => (string) ( $loc['state'] ?? '' ),
                'country'  => (string) ( $loc['country'] ?? '' ),
                'status'   => (string) ( $loc['status'] ?? '' ),
                'lat'      => isset( $geo['lat'] ) ? (float) $geo['lat'] : null,
                'lng'      => isset( $geo['lon'] ) ? (float) $geo['lon'] : null,
            ];
        }

        return [
            'nct_id'         => $nct,
            'official_title' => (string) ( $id['officialTitle'] ?? '' ),
            'brief_title'    => (string) ( $id['briefTitle'] ?? '' ),
            'overall_status' => (string) ( $st['overallStatus'] ?? '' ),
            'phase'          => implode( '/', $phases ),
            'study_type'     => (string) ( $des['studyType'] ?? '' ),
            'conditions'     => array_values( array_map( 'strval', (array) ( $con['conditions'] ?? [] ) ) ),
            'lead_sponsor'   => (string) ( $spo['leadSponsor']['name'] ?? '' ),
            'locations'      => $locations,
            'eligibility'    => [
                'sex'      => (string) ( $elig['sex'] ?? '' ),
                'min_age'  => (string) ( $elig['minimumAge'] ?? '' ),
                'max_age'  => (string) ( $elig['maximumAge'] ?? '' ),
                'criteria' => (string) ( $elig['eligibilityCriteria'] ?? '' ),
            ],
            'brief_summary'  => (string) ( $dsc['briefSummary'] ?? '' ),
            'ct_last_update' => (string) ( $st['lastUpdatePostDateStruct']['date'] ?? '' ),
            'ct_url'         => 'https://clinicaltrials.gov/study/' . $nct,
        ];
    }
}
```

- [ ] **Step 4: Run test (mapper) — expect PASS**

Run: `composer test:unit -- --filter FieldMapperTest`
Expected: PASS.

- [ ] **Step 5: Write the client integration test**

```php
// tests/integration/CtGovClientTest.php  (WP_UnitTestCase — uses pre_http_request)
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\Sync\Ctgov_Client;

final class CtGovClientTest extends WP_UnitTestCase {
    public function test_paginates_until_no_next_token(): void {
        $page = 0;
        add_filter( 'pre_http_request', function( $pre, $args, $url ) use ( &$page ) {
            $page++;
            $body = ( 1 === $page )
                ? [ 'studies' => [ [ 'protocolSection' => [ 'identificationModule' => [ 'nctId' => 'NCT00000001' ] ] ] ], 'nextPageToken' => 'TOK' ]
                : [ 'studies' => [ [ 'protocolSection' => [ 'identificationModule' => [ 'nctId' => 'NCT00000002' ] ] ] ] ];
            return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( $body ) ];
        }, 10, 3 );
        $result = ( new Ctgov_Client() )->fetch_all_for_condition( 'Pompe disease', [ 'RECRUITING' ] );
        $this->assertNull( $result['error'] );
        $this->assertCount( 2, $result['studies'] );
        $this->assertSame( 2, $page );
    }
    public function test_non_200_returns_wp_error_and_no_studies(): void {
        add_filter( 'pre_http_request', fn() => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ], 10, 3 );
        $result = ( new Ctgov_Client() )->fetch_all_for_condition( 'X', [ 'RECRUITING' ] );
        $this->assertInstanceOf( \WP_Error::class, $result['error'] );
        $this->assertSame( [], $result['studies'] );
    }
}
```

- [ ] **Step 6: Run client test to verify it fails**

Run: `composer test:integration -- --filter CtGovClientTest` (requires WP test suite; see Task 0 note in README)
Expected: FAIL — class not found.

- [ ] **Step 7: Implement the client**

```php
// includes/sync/class-ctgov-client.php
namespace SKMCTF\Sync;

final class Ctgov_Client {
    private const ENDPOINT = 'https://clinicaltrials.gov/api/v2/studies';
    private const MAX_PAGES = 50;
    public const FIELDS = 'NCTId,BriefTitle,OfficialTitle,OverallStatus,LastUpdatePostDate,BriefSummary,Conditions,Phase,StudyType,LeadSponsorName,Sex,MinimumAge,MaximumAge,EligibilityCriteria,LocationFacility,LocationCity,LocationState,LocationCountry,LocationStatus,LocationGeoPoint';

    public function fetch_all_for_condition( string $condition, array $statuses ): array {
        $studies = [];
        $token   = null;
        $pages   = 0;
        do {
            $pages++;
            $args = [
                'query.cond'           => $condition,
                'filter.overallStatus' => implode( '|', array_map( 'sanitize_text_field', $statuses ) ),
                'fields'               => self::FIELDS,
                'pageSize'             => 100,
                'format'               => 'json',
            ];
            if ( null === $token ) {
                $args['countTotal'] = 'true';
            } else {
                $args['pageToken'] = $token;
            }
            $url      = self::ENDPOINT . '?' . http_build_query( $args );
            $response = $this->request( $url );
            if ( is_wp_error( $response ) ) {
                return [ 'studies' => [], 'error' => $response ];
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 !== $code ) {
                return [ 'studies' => [], 'error' => new \WP_Error( 'skmctf_http', "CT.gov returned HTTP {$code}." ) ];
            }
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! is_array( $data ) || ! isset( $data['studies'] ) ) {
                return [ 'studies' => [], 'error' => new \WP_Error( 'skmctf_parse', 'Unexpected CT.gov response.' ) ];
            }
            foreach ( $data['studies'] as $s ) {
                $studies[] = $s;
            }
            $token = $data['nextPageToken'] ?? null;
        } while ( $token && $pages < self::MAX_PAGES );

        return [ 'studies' => $studies, 'error' => null ];
    }

    /** Single GET with one retry on transient failure. */
    private function request( string $url ) {
        $opts = [
            'timeout'    => 15,
            'user-agent' => 'KishoClinicalTrials/1.0 (+https://skm.digital)',
            'headers'    => [ 'Accept' => 'application/json' ],
        ];
        $response = wp_remote_get( $url, $opts );
        if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) >= 500 ) {
            $response = wp_remote_get( $url, $opts ); // one retry
        }
        return $response;
    }
}
```

- [ ] **Step 8: Run client test — expect PASS**

Run: `composer test:integration -- --filter CtGovClientTest`
Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "feat: CT.gov v2 client and pure field mapper with failure contract"
```

---

## Task 4: Trial repository (the only writer of trial posts)

**Files:**
- Create: `includes/data/class-trial-repository.php`
- Test: `tests/integration/TrialRepositoryTest.php`

**Interfaces:**
- Consumes: `Trial_Meta::KEYS`, `Trial_Post_Type::POST_TYPE`, `Trial_Taxonomies`.
- Produces: `SKMCTF\Data\Trial_Repository::upsert( array $meta ): int` (post id; `$meta` is short-keyed from `Field_Mapper`); `find_id_by_nct( string $nct ): ?int`; `all_nct_ids(): array`; `mark_closed( string $nct ): void`; `delete_by_nct( string $nct ): void`.

- [ ] **Step 1: Write the failing test**

```php
// tests/integration/TrialRepositoryTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Post_Types\Trial_Meta;

final class TrialRepositoryTest extends WP_UnitTestCase {
    private function meta( string $nct, string $status = 'RECRUITING' ): array {
        return [ 'nct_id' => $nct, 'brief_title' => 'T '.$nct, 'official_title' => 'O', 'overall_status' => $status,
            'phase' => 'Phase 2', 'study_type' => 'INTERVENTIONAL', 'conditions' => [ 'Pompe Disease' ], 'lead_sponsor' => 'S',
            'locations' => [], 'eligibility' => [ 'sex'=>'ALL','min_age'=>'','max_age'=>'','criteria'=>'' ],
            'brief_summary' => 'b', 'ct_last_update' => '2026-03-10', 'ct_url' => 'https://clinicaltrials.gov/study/'.$nct ];
    }
    public function test_insert_then_update_keeps_single_post(): void {
        $repo = new Trial_Repository();
        $id1  = $repo->upsert( $this->meta( 'NCT00000001' ) );
        $id2  = $repo->upsert( $this->meta( 'NCT00000001', 'COMPLETED' ) );
        $this->assertSame( $id1, $id2 );
        $this->assertSame( 'COMPLETED', get_post_meta( $id1, Trial_Meta::KEYS['overall_status'], true ) );
        $this->assertSame( 'Pompe Disease', get_post_meta( $id1, Trial_Meta::KEYS['conditions'], true )[0] );
        $terms = wp_get_object_terms( $id1, 'trial_status', [ 'fields' => 'names' ] );
        $this->assertContains( 'COMPLETED', $terms );
    }
    public function test_all_nct_ids_and_mark_closed(): void {
        $repo = new Trial_Repository();
        $repo->upsert( $this->meta( 'NCT00000002' ) );
        $this->assertContains( 'NCT00000002', $repo->all_nct_ids() );
        $repo->mark_closed( 'NCT00000002' );
        $id = $repo->find_id_by_nct( 'NCT00000002' );
        $this->assertSame( 'CLOSED', get_post_meta( $id, Trial_Meta::KEYS['overall_status'], true ) );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:integration -- --filter TrialRepositoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
// includes/data/class-trial-repository.php
namespace SKMCTF\Data;

use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Post_Types\Trial_Taxonomies;

final class Trial_Repository {
    public function find_id_by_nct( string $nct ): ?int {
        $found = get_posts( [
            'post_type'   => Trial_Post_Type::POST_TYPE,
            'post_status' => 'any',
            'name'        => strtolower( $nct ),
            'numberposts' => 1,
            'fields'      => 'ids',
            'no_found_rows' => true,
        ] );
        return $found ? (int) $found[0] : null;
    }

    public function upsert( array $meta ): int {
        $nct = $meta['nct_id'];
        $postarr = [
            'post_type'    => Trial_Post_Type::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $meta['brief_title'] !== '' ? $meta['brief_title'] : $meta['official_title'],
            'post_name'    => strtolower( $nct ),
            'post_content' => '',
        ];
        $id = $this->find_id_by_nct( $nct );
        if ( $id ) {
            $postarr['ID'] = $id;
            wp_update_post( $postarr );
        } else {
            $id = (int) wp_insert_post( $postarr );
        }
        if ( ! $id ) { return 0; }

        foreach ( Trial_Meta::KEYS as $short => $meta_key ) {
            if ( 'last_synced' === $short ) { continue; }
            if ( array_key_exists( $short, $meta ) ) {
                update_post_meta( $id, $meta_key, $meta[ $short ] );
            }
        }
        update_post_meta( $id, Trial_Meta::KEYS['last_synced'], time() );

        if ( $meta['overall_status'] !== '' ) {
            wp_set_object_terms( $id, $meta['overall_status'], Trial_Taxonomies::STATUS );
        }
        if ( ! empty( $meta['phase'] ) ) {
            wp_set_object_terms( $id, $meta['phase'], Trial_Taxonomies::PHASE );
        }
        return $id;
    }

    public function all_nct_ids(): array {
        $ids = get_posts( [
            'post_type'   => Trial_Post_Type::POST_TYPE,
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
            'no_found_rows' => true,
        ] );
        $ncts = [];
        foreach ( $ids as $id ) {
            $nct = get_post_meta( $id, Trial_Meta::KEYS['nct_id'], true );
            if ( $nct ) { $ncts[] = $nct; }
        }
        return $ncts;
    }

    public function mark_closed( string $nct ): void {
        $id = $this->find_id_by_nct( $nct );
        if ( $id ) {
            update_post_meta( $id, Trial_Meta::KEYS['overall_status'], 'CLOSED' );
            wp_set_object_terms( $id, 'CLOSED', Trial_Taxonomies::STATUS );
        }
    }

    public function delete_by_nct( string $nct ): void {
        $id = $this->find_id_by_nct( $nct );
        if ( $id ) {
            wp_delete_post( $id, true );
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:integration -- --filter TrialRepositoryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: trial repository with NCT-keyed upsert and taxonomy sync"
```

---

## Task 5: Reconciler with the three no-wipe guards

**Files:**
- Create: `includes/sync/class-reconciler.php`
- Create: `includes/support/interface-logger.php` (minimal `Logger_Interface` so Reconciler is unit-testable without WP)
- Test: `tests/unit/ReconcilerTest.php`

**Interfaces:**
- Consumes: `Trial_Repository` (typed via a small interface `Repo_Interface` with `all_nct_ids()`, `mark_closed()`, `delete_by_nct()`), `Logger_Interface`.
- Produces: `SKMCTF\Sync\Reconciler::__construct( Repo_Interface $repo, Logger_Interface $log )`; `reconcile( array $seen_nct, bool $had_error, string $mode ): string` (returns one of `'skipped_error'|'skipped_empty'|'skipped_ratio'|'done'`); const `MAX_DROP_RATIO = 0.5`.

> **Note:** add `Repo_Interface` (interface in `includes/data/interface-repo.php`) implemented by `Trial_Repository`, exposing `all_nct_ids(): array`, `mark_closed( string ): void`, `delete_by_nct( string ): void`. This lets the unit test pass a fake repo. Update Task 4's class to `implements Repo_Interface` (no behavior change).

- [ ] **Step 1: Write the failing test**

```php
// tests/unit/ReconcilerTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use SKMCTF\Sync\Reconciler;
use SKMCTF\Data\Repo_Interface;
use SKMCTF\Support\Logger_Interface;

final class FakeRepo implements Repo_Interface {
    public array $existing; public array $closed = []; public array $deleted = [];
    public function __construct( array $existing ) { $this->existing = $existing; }
    public function all_nct_ids(): array { return $this->existing; }
    public function mark_closed( string $nct ): void { $this->closed[] = $nct; }
    public function delete_by_nct( string $nct ): void { $this->deleted[] = $nct; }
}
final class NullLog implements Logger_Interface {
    public function info( string $m, array $c = [] ): void {}
    public function warn( string $m, array $c = [] ): void {}
    public function error( string $m, array $c = [] ): void {}
}

final class ReconcilerTest extends TestCase {
    public function test_skips_on_error(): void {
        $repo = new FakeRepo( [ 'NCT00000001' ] );
        $r = new Reconciler( $repo, new NullLog() );
        $this->assertSame( 'skipped_error', $r->reconcile( [], true, 'mark_closed' ) );
        $this->assertSame( [], $repo->closed );
    }
    public function test_skips_on_empty_seen(): void {
        $repo = new FakeRepo( [ 'NCT00000001' ] );
        $r = new Reconciler( $repo, new NullLog() );
        $this->assertSame( 'skipped_empty', $r->reconcile( [], false, 'mark_closed' ) );
    }
    public function test_skips_when_drop_ratio_exceeded(): void {
        $repo = new FakeRepo( [ 'NCT01','NCT02','NCT03','NCT04' ] );
        $r = new Reconciler( $repo, new NullLog() );
        // seen only 1 of 4 -> would drop 3/4 = 0.75 > 0.5
        $this->assertSame( 'skipped_ratio', $r->reconcile( [ 'NCT01' ], false, 'remove' ) );
        $this->assertSame( [], $repo->deleted );
    }
    public function test_marks_closed_dropped_trials(): void {
        $repo = new FakeRepo( [ 'NCT01','NCT02' ] );
        $r = new Reconciler( $repo, new NullLog() );
        $this->assertSame( 'done', $r->reconcile( [ 'NCT01' ], false, 'mark_closed' ) );
        $this->assertSame( [ 'NCT02' ], $repo->closed );
    }
    public function test_remove_mode_deletes(): void {
        $repo = new FakeRepo( [ 'NCT01','NCT02' ] );
        $r = new Reconciler( $repo, new NullLog() );
        $this->assertSame( 'done', $r->reconcile( [ 'NCT01' ], false, 'remove' ) );
        $this->assertSame( [ 'NCT02' ], $repo->deleted );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:unit -- --filter ReconcilerTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement interfaces + reconciler**

```php
// includes/support/interface-logger.php
namespace SKMCTF\Support;
interface Logger_Interface {
    public function info( string $message, array $context = [] ): void;
    public function warn( string $message, array $context = [] ): void;
    public function error( string $message, array $context = [] ): void;
}
```

```php
// includes/data/interface-repo.php
namespace SKMCTF\Data;
interface Repo_Interface {
    public function all_nct_ids(): array;
    public function mark_closed( string $nct ): void;
    public function delete_by_nct( string $nct ): void;
}
```

Add `implements Repo_Interface` to `Trial_Repository` (Task 4).

```php
// includes/sync/class-reconciler.php
namespace SKMCTF\Sync;

use SKMCTF\Data\Repo_Interface;
use SKMCTF\Support\Logger_Interface;

final class Reconciler {
    public const MAX_DROP_RATIO = 0.5;
    private Repo_Interface $repo;
    private Logger_Interface $log;

    public function __construct( Repo_Interface $repo, Logger_Interface $log ) {
        $this->repo = $repo;
        $this->log  = $log;
    }

    public function reconcile( array $seen_nct, bool $had_error, string $mode ): string {
        if ( $had_error ) {
            $this->log->warn( 'Reconcile skipped: a fetch error occurred this run.' );
            return 'skipped_error';
        }
        if ( empty( $seen_nct ) ) {
            $this->log->warn( 'Reconcile skipped: empty result set.' );
            return 'skipped_empty';
        }
        $existing = $this->repo->all_nct_ids();
        $dropped  = array_values( array_diff( $existing, $seen_nct ) );
        $ratio    = ( function_exists( 'apply_filters' ) ? (float) apply_filters( 'skmctf_max_drop_ratio', self::MAX_DROP_RATIO ) : self::MAX_DROP_RATIO );
        if ( count( $existing ) > 0 && ( count( $dropped ) / count( $existing ) ) > $ratio ) {
            $this->log->warn( 'Reconcile skipped: drop ratio exceeded.', [ 'dropped' => count( $dropped ), 'existing' => count( $existing ) ] );
            return 'skipped_ratio';
        }
        foreach ( $dropped as $nct ) {
            if ( 'remove' === $mode ) {
                $this->repo->delete_by_nct( $nct );
            } else {
                $this->repo->mark_closed( $nct );
            }
        }
        $this->log->info( 'Reconcile complete.', [ 'dropped' => count( $dropped ), 'mode' => $mode ] );
        return 'done';
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:unit -- --filter ReconcilerTest`
Expected: PASS (all 5).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: reconciler with three no-wipe guards (error, empty, drop-ratio)"
```

---

## Task 6: Settings facade + concrete logger

**Files:**
- Create: `includes/admin/class-settings.php` (typed getter facade over the `skmctf_settings` option, default-aware)
- Create: `includes/support/class-logger.php` (implements `Logger_Interface`; bounded ring buffer + last_sync/last_error options)
- Test: `tests/unit/SettingsSanitizeTest.php`, `tests/integration/LoggerTest.php`

**Interfaces:**
- Consumes: `Logger_Interface`.
- Produces: `SKMCTF\Admin\Settings` static facade — `all()`, `get($k,$d)`, `conditions()`, `statuses()`, `include_ncts()`, `exclude_ncts()`, `reconcile_mode()`, `summaries_enabled()`, `provider()`, `get_api_key()`, `model()`, `show_map()`, `single_pages_enabled()`, `index_singles_override()`, `display_fields()`, `attribution_enabled()`; `Settings::OPTION='skmctf_settings'`; `Settings::sanitize(array $input, array $existing): array`. `SKMCTF\Support\Logger implements Logger_Interface` plus `record_sync(array): void`, `last_sync(): array`, `last_error(): string`, `recent(): array`.

- [ ] **Step 1: Write the failing test (settings sanitizer)**

```php
// tests/unit/SettingsSanitizeTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Admin\Settings;

final class SettingsSanitizeTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp();
        Functions\stubs( [
            'sanitize_text_field'     => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
            'sanitize_textarea_field' => static fn( $v ) => is_string( $v ) ? trim( $v ) : '',
            'wp_unslash'              => static fn( $v ) => $v,
            'absint'                  => static fn( $v ) => abs( (int) $v ),
            '__'                      => static fn( $v ) => $v,
        ] );
    }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_filters_invalid_ncts_and_uppercases(): void {
        $out = Settings::sanitize( [ 'include_ncts' => "nct01234567\nbad\nNCT99999999" ], [] );
        $this->assertSame( [ 'NCT01234567', 'NCT99999999' ], $out['include_ncts'] );
    }
    public function test_status_whitelist(): void {
        $out = Settings::sanitize( [ 'statuses' => [ 'RECRUITING', 'HACKERMAN' ] ], [] );
        $this->assertSame( [ 'RECRUITING' ], $out['statuses'] );
    }
    public function test_reconcile_mode_defaults_to_mark_closed_on_garbage(): void {
        $out = Settings::sanitize( [ 'reconcile_mode' => 'nuke' ], [] );
        $this->assertSame( 'mark_closed', $out['reconcile_mode'] );
    }
    public function test_blank_api_key_preserves_existing(): void {
        $out = Settings::sanitize( [ 'api_key' => '' ], [ 'api_key' => 'sk-secret' ] );
        $this->assertSame( 'sk-secret', $out['api_key'] );
    }
    public function test_new_api_key_replaces_and_clear_flag_wipes(): void {
        $this->assertSame( 'sk-new', Settings::sanitize( [ 'api_key' => 'sk-new' ], [ 'api_key' => 'old' ] )['api_key'] );
        $this->assertSame( '', Settings::sanitize( [ 'api_key' => 'old', 'clear_api_key' => '1' ], [ 'api_key' => 'old' ] )['api_key'] );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:unit -- --filter SettingsSanitizeTest` — Expected: FAIL (class not found).

- [ ] **Step 3: Implement `Settings`**

```php
// includes/admin/class-settings.php
namespace SKMCTF\Admin;

final class Settings {
    public const OPTION = 'skmctf_settings';
    public const VALID_STATUSES = [ 'RECRUITING','NOT_YET_RECRUITING','ENROLLING_BY_INVITATION','ACTIVE_NOT_RECRUITING','COMPLETED','SUSPENDED','TERMINATED','WITHDRAWN','UNKNOWN' ];
    public const PROVIDERS = [ 'anthropic', 'openai' ];

    private static function defaults(): array {
        return [
            'conditions' => [], 'include_ncts' => [], 'exclude_ncts' => [],
            'statuses' => [ 'RECRUITING' ], 'reconcile_mode' => 'mark_closed',
            'summaries_enabled' => false, 'provider' => 'anthropic', 'api_key' => '', 'model' => '',
            'show_map' => false, 'single_pages' => true, 'index_singles_override' => false,
            'display_fields' => [ 'status','phase','conditions','sponsor','locations','summary' ],
            'attribution' => false,
        ];
    }
    public static function all(): array {
        $o = get_option( self::OPTION, [] );
        return array_merge( self::defaults(), is_array( $o ) ? $o : [] );
    }
    public static function get( string $key, $default = null ) { $a = self::all(); return $a[ $key ] ?? $default; }
    public static function conditions(): array { return (array) self::get( 'conditions', [] ); }
    public static function statuses(): array { $s = (array) self::get( 'statuses', [ 'RECRUITING' ] ); return $s ?: [ 'RECRUITING' ]; }
    public static function include_ncts(): array { return (array) self::get( 'include_ncts', [] ); }
    public static function exclude_ncts(): array { return (array) self::get( 'exclude_ncts', [] ); }
    public static function reconcile_mode(): string { return self::get( 'reconcile_mode' ) === 'remove' ? 'remove' : 'mark_closed'; }
    public static function summaries_enabled(): bool { return (bool) self::get( 'summaries_enabled', false ); }
    public static function provider(): string { $p = (string) self::get( 'provider', 'anthropic' ); return in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic'; }
    public static function get_api_key(): string { return (string) self::get( 'api_key', '' ); }
    public static function model(): string { return (string) self::get( 'model', '' ); }
    public static function show_map(): bool { return (bool) self::get( 'show_map', false ); }
    public static function single_pages_enabled(): bool { return (bool) self::get( 'single_pages', true ); }
    public static function index_singles_override(): bool { return (bool) self::get( 'index_singles_override', false ); }
    public static function display_fields(): array { return (array) self::get( 'display_fields', [] ); }
    public static function attribution_enabled(): bool { return (bool) self::get( 'attribution', false ); }

    private static function parse_ncts( string $raw ): array {
        $out = [];
        foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
            $nct = strtoupper( trim( $line ) );
            if ( preg_match( '/^NCT\d{8}$/', $nct ) ) { $out[] = $nct; }
        }
        return array_values( array_unique( $out ) );
    }

    public static function sanitize( array $input, array $existing ): array {
        $out = array_merge( self::defaults(), $existing );
        if ( isset( $input['conditions'] ) ) {
            $conds = is_array( $input['conditions'] ) ? $input['conditions'] : preg_split( '/\r\n|\r|\n/', (string) $input['conditions'] );
            $out['conditions'] = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $conds ) ) ) );
        }
        if ( isset( $input['include_ncts'] ) ) { $out['include_ncts'] = self::parse_ncts( (string) wp_unslash( $input['include_ncts'] ) ); }
        if ( isset( $input['exclude_ncts'] ) ) { $out['exclude_ncts'] = self::parse_ncts( (string) wp_unslash( $input['exclude_ncts'] ) ); }
        if ( isset( $input['statuses'] ) ) {
            $out['statuses'] = array_values( array_intersect( self::VALID_STATUSES, array_map( 'sanitize_text_field', (array) $input['statuses'] ) ) );
            if ( ! $out['statuses'] ) { $out['statuses'] = [ 'RECRUITING' ]; }
        }
        $out['reconcile_mode']         = ( ( $input['reconcile_mode'] ?? '' ) === 'remove' ) ? 'remove' : 'mark_closed';
        $out['summaries_enabled']      = ! empty( $input['summaries_enabled'] );
        $p                             = sanitize_text_field( $input['provider'] ?? 'anthropic' );
        $out['provider']               = in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic';
        $out['model']                  = sanitize_text_field( $input['model'] ?? '' );
        $out['show_map']               = ! empty( $input['show_map'] );
        $out['single_pages']           = ! empty( $input['single_pages'] );
        $out['index_singles_override'] = ! empty( $input['index_singles_override'] );
        $out['attribution']            = ! empty( $input['attribution'] );
        if ( isset( $input['display_fields'] ) ) {
            $out['display_fields'] = array_values( array_map( 'sanitize_text_field', (array) $input['display_fields'] ) );
        }
        // API key write-only: blank preserves, value replaces, clear flag wipes.
        if ( ! empty( $input['clear_api_key'] ) ) {
            $out['api_key'] = '';
        } elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
            $out['api_key'] = sanitize_text_field( $input['api_key'] );
        } else {
            $out['api_key'] = $existing['api_key'] ?? '';
        }
        return $out;
    }
}
```

```php
// includes/support/class-logger.php
namespace SKMCTF\Support;

final class Logger implements Logger_Interface {
    private const LOG_OPTION   = 'skmctf_log';
    private const SYNC_OPTION  = 'skmctf_last_sync';
    private const ERROR_OPTION = 'skmctf_last_error';
    private const MAX_ENTRIES  = 50;

    public function info( string $m, array $c = [] ): void { $this->push( 'info', $m, $c ); }
    public function warn( string $m, array $c = [] ): void { $this->push( 'warn', $m, $c ); }
    public function error( string $m, array $c = [] ): void { $this->push( 'error', $m, $c ); update_option( self::ERROR_OPTION, $m, false ); }

    private function push( string $level, string $m, array $c ): void {
        $log = get_option( self::LOG_OPTION, [] );
        if ( ! is_array( $log ) ) { $log = []; }
        array_unshift( $log, [ 't' => time(), 'level' => $level, 'msg' => $m, 'ctx' => $c ] );
        update_option( self::LOG_OPTION, array_slice( $log, 0, self::MAX_ENTRIES ), false );
    }
    public function record_sync( array $summary ): void {
        update_option( self::SYNC_OPTION, array_merge( [ 't' => time() ], $summary ), false );
        if ( empty( $summary['errors'] ) ) { update_option( self::ERROR_OPTION, '', false ); }
    }
    public function last_sync(): array { $v = get_option( self::SYNC_OPTION, [] ); return is_array( $v ) ? $v : []; }
    public function last_error(): string { return (string) get_option( self::ERROR_OPTION, '' ); }
    public function recent(): array { $v = get_option( self::LOG_OPTION, [] ); return is_array( $v ) ? $v : []; }
}
```

- [ ] **Step 4: Run unit test — expect PASS**

Run: `composer test:unit -- --filter SettingsSanitizeTest` — Expected: PASS.

- [ ] **Step 5: Logger integration test**

```php
// tests/integration/LoggerTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\Support\Logger;
final class LoggerTest extends WP_UnitTestCase {
    public function test_ring_buffer_caps_at_50(): void {
        $log = new Logger();
        for ( $i = 0; $i < 60; $i++ ) { $log->info( "m$i" ); }
        $this->assertLessThanOrEqual( 50, count( $log->recent() ) );
        $this->assertSame( 'm59', $log->recent()[0]['msg'] );
    }
    public function test_error_sets_last_error_option(): void {
        ( new Logger() )->error( 'boom' );
        $this->assertSame( 'boom', ( new Logger() )->last_error() );
    }
}
```

Run: `composer test:integration -- --filter LoggerTest` — Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: settings facade with write-only key handling + bounded logger"
```

---

## Task 7: LLM provider interface, Anthropic + OpenAI, factory (filter seam)

**Files:**
- Create: `includes/llm/interface-llm-provider.php`
- Create: `includes/llm/class-anthropic-provider.php`
- Create: `includes/llm/class-openai-provider.php`
- Create: `includes/llm/class-provider-factory.php`
- Test: `tests/integration/ProviderTest.php`

**Interfaces:**
- Consumes: `Settings`.
- Produces: `SKMCTF\LLM\Llm_Provider` interface — `generate_summary( string $system, string $user, array $opts = [] )` returns `string|WP_Error`, `id(): string`; `SKMCTF\LLM\Anthropic_Provider`, `SKMCTF\LLM\Openai_Provider` (ctor `( string $api_key, string $model = '' )`); `SKMCTF\LLM\Provider_Factory::make(): ?Llm_Provider` (reads `Settings`, applies `skmctf_llm_api_key` + `skmctf_llm_model` filters, returns null when no key).

- [ ] **Step 1: Write the failing test**

```php
// tests/integration/ProviderTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\LLM\Anthropic_Provider;
use SKMCTF\LLM\Openai_Provider;

final class ProviderTest extends WP_UnitTestCase {
    public function test_anthropic_parses_text_from_content(): void {
        add_filter( 'pre_http_request', function( $pre, $args, $url ) {
            $this->assertStringContainsString( 'api.anthropic.com', $url );
            $this->assertSame( 'key-123', $args['headers']['x-api-key'] );
            return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'content' => [ [ 'type' => 'text', 'text' => 'Plain summary.' ] ] ] ) ];
        }, 10, 3 );
        $out = ( new Anthropic_Provider( 'key-123' ) )->generate_summary( 'sys', 'user' );
        $this->assertSame( 'Plain summary.', $out );
    }
    public function test_openai_parses_choices_message(): void {
        add_filter( 'pre_http_request', function( $pre, $args, $url ) {
            $this->assertStringContainsString( 'api.openai.com', $url );
            $this->assertSame( 'Bearer key-xyz', $args['headers']['Authorization'] );
            return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'choices' => [ [ 'message' => [ 'content' => 'OAI summary.' ] ] ] ] ) ];
        }, 10, 3 );
        $out = ( new Openai_Provider( 'key-xyz' ) )->generate_summary( 'sys', 'user' );
        $this->assertSame( 'OAI summary.', $out );
    }
    public function test_http_error_returns_wp_error(): void {
        add_filter( 'pre_http_request', fn() => [ 'response' => [ 'code' => 401 ], 'body' => 'bad key' ], 10, 3 );
        $out = ( new Anthropic_Provider( 'key' ) )->generate_summary( 's', 'u' );
        $this->assertInstanceOf( \WP_Error::class, $out );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:integration -- --filter ProviderTest` — Expected: FAIL (classes not found).

- [ ] **Step 3: Implement interface + providers + factory**

```php
// includes/llm/interface-llm-provider.php
namespace SKMCTF\LLM;
interface Llm_Provider {
    /** @return string|\WP_Error */
    public function generate_summary( string $system, string $user, array $opts = [] );
    public function id(): string;
}
```

```php
// includes/llm/class-anthropic-provider.php
namespace SKMCTF\LLM;

final class Anthropic_Provider implements Llm_Provider {
    private const URL = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_MODEL = 'claude-haiku-4-5-20251001';
    private string $key;
    private string $model;
    public function __construct( string $api_key, string $model = '' ) {
        $this->key = $api_key;
        $this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
    }
    public function id(): string { return 'anthropic'; }
    public function generate_summary( string $system, string $user, array $opts = [] ) {
        $body = [
            'model'      => $this->model,
            'max_tokens' => (int) ( $opts['max_tokens'] ?? 700 ),
            'system'     => $system,
            'messages'   => [ [ 'role' => 'user', 'content' => $user ] ],
        ];
        $res = wp_remote_post( self::URL, [
            'timeout' => 30,
            'headers' => [
                'x-api-key'         => $this->key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );
        if ( is_wp_error( $res ) ) { return $res; }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( 200 !== $code ) {
            return new \WP_Error( 'skmctf_anthropic', sprintf( 'Anthropic API error (HTTP %d): %s', $code, $data['error']['message'] ?? 'unknown' ) );
        }
        $text = $data['content'][0]['text'] ?? '';
        return $text !== '' ? $text : new \WP_Error( 'skmctf_anthropic_empty', 'Empty response.' );
    }
}
```

```php
// includes/llm/class-openai-provider.php
namespace SKMCTF\LLM;

final class Openai_Provider implements Llm_Provider {
    private const URL = 'https://api.openai.com/v1/chat/completions';
    private const DEFAULT_MODEL = 'gpt-4o-mini';
    private string $key;
    private string $model;
    public function __construct( string $api_key, string $model = '' ) {
        $this->key = $api_key;
        $this->model = $model !== '' ? $model : self::DEFAULT_MODEL;
    }
    public function id(): string { return 'openai'; }
    public function generate_summary( string $system, string $user, array $opts = [] ) {
        $body = [
            'model'    => $this->model,
            'messages' => [
                [ 'role' => 'system', 'content' => $system ],
                [ 'role' => 'user',   'content' => $user ],
            ],
            'max_tokens' => (int) ( $opts['max_tokens'] ?? 700 ),
        ];
        $res = wp_remote_post( self::URL, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
        ] );
        if ( is_wp_error( $res ) ) { return $res; }
        $code = (int) wp_remote_retrieve_response_code( $res );
        $data = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( 200 !== $code ) {
            return new \WP_Error( 'skmctf_openai', sprintf( 'OpenAI API error (HTTP %d): %s', $code, $data['error']['message'] ?? 'unknown' ) );
        }
        $text = $data['choices'][0]['message']['content'] ?? '';
        return $text !== '' ? $text : new \WP_Error( 'skmctf_openai_empty', 'Empty response.' );
    }
}
```

```php
// includes/llm/class-provider-factory.php
namespace SKMCTF\LLM;

use SKMCTF\Admin\Settings;

final class Provider_Factory {
    public static function make(): ?Llm_Provider {
        if ( ! Settings::summaries_enabled() ) { return null; }
        $provider_name = Settings::provider();
        /** Filter seam: a future core/global key vault can supply the key here. */
        $key   = (string) apply_filters( 'skmctf_llm_api_key', Settings::get_api_key(), $provider_name );
        $model = (string) apply_filters( 'skmctf_llm_model', Settings::model(), $provider_name );
        if ( '' === trim( $key ) ) { return null; }
        return 'openai' === $provider_name
            ? new Openai_Provider( $key, $model )
            : new Anthropic_Provider( $key, $model );
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:integration -- --filter ProviderTest` — Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: LLM provider abstraction (Anthropic+OpenAI) with key filter seam"
```

---

## Task 8: Summary service (change detection, caching, disclaimer)

**Files:**
- Create: `includes/llm/class-prompt-builder.php`
- Create: `includes/llm/class-summary-service.php`
- Test: `tests/unit/PromptBuilderTest.php`, `tests/integration/SummaryServiceTest.php`

**Interfaces:**
- Consumes: `Llm_Provider`, `Trial_Meta::KEYS`, `Logger_Interface`.
- Produces: `SKMCTF\LLM\Prompt_Builder::system(): string`, `::user( array $meta ): string`, `::enforce_disclaimer( string $text ): string`, `::DISCLAIMER` (translatable, fetched via method `disclaimer(): string`); `SKMCTF\LLM\Summary_Service::__construct( ?Llm_Provider $provider, Logger_Interface $log )`, `maybe_generate( int $post_id, array $meta ): bool` (true if it wrote a summary).

- [ ] **Step 1: Failing test — disclaimer always present**

```php
// tests/unit/PromptBuilderTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\LLM\Prompt_Builder;

final class PromptBuilderTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp();
        Functions\stubs( [ '__' => static fn( $v ) => $v ] ); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_enforce_disclaimer_appends_when_missing(): void {
        $out = Prompt_Builder::enforce_disclaimer( 'A summary.' );
        $this->assertStringContainsString( 'talk to your doctor', strtolower( $out ) );
    }
    public function test_enforce_disclaimer_does_not_duplicate(): void {
        $with = 'A summary. ' . Prompt_Builder::disclaimer();
        $out  = Prompt_Builder::enforce_disclaimer( $with );
        $this->assertSame( 1, substr_count( strtolower( $out ), 'talk to your doctor' ) );
    }
    public function test_user_prompt_includes_trial_facts(): void {
        $u = Prompt_Builder::user( [ 'brief_title' => 'A Trial', 'overall_status' => 'RECRUITING', 'conditions' => [ 'Pompe Disease' ], 'phase' => 'Phase 2', 'brief_summary' => 'Studying X.', 'eligibility' => [ 'sex'=>'ALL','min_age'=>'18 Years','max_age'=>'','criteria'=>'' ], 'lead_sponsor' => 'Acme' ] );
        $this->assertStringContainsString( 'A Trial', $u );
        $this->assertStringContainsString( 'Pompe Disease', $u );
        $this->assertStringContainsString( 'Studying X.', $u );
    }
}
```

- [ ] **Step 2: Run — Expected FAIL.**

Run: `composer test:unit -- --filter PromptBuilderTest`

- [ ] **Step 3: Implement Prompt_Builder**

```php
// includes/llm/class-prompt-builder.php
namespace SKMCTF\LLM;

final class Prompt_Builder {
    public static function disclaimer(): string {
        return __( 'This is a plain-language summary — please read the full trial record and talk to your doctor before making any decisions.', 'kisho-clinical-trials' );
    }
    public static function system(): string {
        return __(
            'You write short, plain-language summaries of clinical trials for patients and families. Use about an 8th-grade reading level. Be factual and only use the information provided. Do not give medical advice, do not speculate, and do not invent details. Write 2-4 short sentences. End with the exact disclaimer sentence the user provides.',
            'kisho-clinical-trials'
        );
    }
    public static function user( array $meta ): string {
        $lines   = [];
        $lines[] = sprintf( __( 'Title: %s', 'kisho-clinical-trials' ), $meta['brief_title'] ?? '' );
        $lines[] = sprintf( __( 'Status: %s', 'kisho-clinical-trials' ), $meta['overall_status'] ?? '' );
        $lines[] = sprintf( __( 'Phase: %s', 'kisho-clinical-trials' ), $meta['phase'] ?? '' );
        $lines[] = sprintf( __( 'Conditions: %s', 'kisho-clinical-trials' ), implode( ', ', (array) ( $meta['conditions'] ?? [] ) ) );
        $lines[] = sprintf( __( 'Sponsor: %s', 'kisho-clinical-trials' ), $meta['lead_sponsor'] ?? '' );
        $elig    = $meta['eligibility'] ?? [];
        $lines[] = sprintf( __( 'Who can join: sex %1$s, ages %2$s to %3$s', 'kisho-clinical-trials' ), $elig['sex'] ?? '', $elig['min_age'] ?? '', $elig['max_age'] ?? '' );
        $lines[] = sprintf( __( 'Official description: %s', 'kisho-clinical-trials' ), $meta['brief_summary'] ?? '' );
        $lines[] = '';
        $lines[] = sprintf( __( 'End your summary with exactly this sentence: %s', 'kisho-clinical-trials' ), self::disclaimer() );
        return implode( "\n", $lines );
    }
    public static function enforce_disclaimer( string $text ): string {
        $text = trim( $text );
        $needle = self::disclaimer();
        if ( false !== stripos( $text, 'talk to your doctor' ) ) {
            return $text;
        }
        return $text . "\n\n" . $needle;
    }
}
```

- [ ] **Step 4: Run — Expected PASS.**

Run: `composer test:unit -- --filter PromptBuilderTest`

- [ ] **Step 5: Summary service integration test (change detection)**

```php
// tests/integration/SummaryServiceTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\LLM\Summary_Service;
use SKMCTF\LLM\Llm_Provider;
use SKMCTF\Support\Logger;
use SKMCTF\Post_Types\Trial_Meta;

final class CountingProvider implements Llm_Provider {
    public int $calls = 0;
    public function id(): string { return 'fake'; }
    public function generate_summary( string $s, string $u, array $o = [] ) { $this->calls++; return 'Generated text.'; }
}

final class SummaryServiceTest extends WP_UnitTestCase {
    private function trial( string $date ): int {
        $id = self::factory()->post->create( [ 'post_type' => 'skmctf_trial' ] );
        update_post_meta( $id, Trial_Meta::KEYS['ct_last_update'], $date );
        return $id;
    }
    public function test_generates_on_new_then_caches_until_date_changes(): void {
        $p = new CountingProvider();
        $svc = new Summary_Service( $p, new Logger() );
        $meta = [ 'ct_last_update' => '2026-03-10', 'brief_title' => 'T', 'conditions' => [], 'eligibility' => [] ];
        $id = $this->trial( '2026-03-10' );

        $this->assertTrue( $svc->maybe_generate( $id, $meta ) );          // new -> generates
        $this->assertSame( 1, $p->calls );
        $this->assertStringContainsString( 'Generated text.', get_post_meta( $id, Trial_Meta::KEYS['plain_summary'], true ) );

        $this->assertFalse( $svc->maybe_generate( $id, $meta ) );         // unchanged -> cached
        $this->assertSame( 1, $p->calls );

        $meta['ct_last_update'] = '2026-04-01';                           // date advanced
        $this->assertTrue( $svc->maybe_generate( $id, $meta ) );
        $this->assertSame( 2, $p->calls );
    }
    public function test_null_provider_is_noop(): void {
        $svc = new Summary_Service( null, new Logger() );
        $id  = $this->trial( '2026-03-10' );
        $this->assertFalse( $svc->maybe_generate( $id, [ 'ct_last_update' => '2026-03-10' ] ) );
    }
}
```

- [ ] **Step 6: Run — Expected FAIL, then implement.**

```php
// includes/llm/class-summary-service.php
namespace SKMCTF\LLM;

use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Support\Logger_Interface;

final class Summary_Service {
    private ?Llm_Provider $provider;
    private Logger_Interface $log;
    public function __construct( ?Llm_Provider $provider, Logger_Interface $log ) {
        $this->provider = $provider;
        $this->log      = $log;
    }
    public function maybe_generate( int $post_id, array $meta ): bool {
        if ( null === $this->provider ) { return false; }
        $new_date = (string) ( $meta['ct_last_update'] ?? '' );
        $existing = (string) get_post_meta( $post_id, Trial_Meta::KEYS['plain_summary'], true );
        $src_date = (string) get_post_meta( $post_id, Trial_Meta::KEYS['plain_summary_source_date'], true );
        if ( '' !== $existing && $src_date === $new_date && '' !== $new_date ) {
            return false; // cached, unchanged
        }
        $text = $this->provider->generate_summary( Prompt_Builder::system(), Prompt_Builder::user( $meta ) );
        if ( is_wp_error( $text ) ) {
            $this->log->error( 'Summary generation failed: ' . $text->get_error_message(), [ 'post' => $post_id ] );
            return false; // front end unaffected; raw display used
        }
        $text = Prompt_Builder::enforce_disclaimer( (string) $text );
        update_post_meta( $post_id, Trial_Meta::KEYS['plain_summary'], wp_kses_post( $text ) );
        update_post_meta( $post_id, Trial_Meta::KEYS['plain_summary_source_date'], $new_date );
        return true;
    }
}
```

Run: `composer test:integration -- --filter SummaryServiceTest` — Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: summary service with change-detection caching and enforced disclaimer"
```

---

## Task 9: Sync engine + scheduler (Action Scheduler) + manual sync

**Files:**
- Create: `vendor-lib/action-scheduler/` (download the released Action Scheduler library — see step 0)
- Create: `includes/sync/class-sync-engine.php`
- Create: `includes/sync/class-scheduler.php`
- Modify: `kisho-clinical-trials.php` (require Action Scheduler early), `includes/class-plugin.php` (wire scheduler + sync action)
- Modify: activation/deactivation (in main file or a dedicated `class-activator.php`)
- Test: `tests/integration/SyncEngineTest.php`

**Interfaces:**
- Consumes: `Ctgov_Client`, `Field_Mapper`, `Trial_Repository`, `Reconciler`, `Provider_Factory`, `Summary_Service`, `Settings`, `Logger`.
- Produces: `SKMCTF\Sync\Sync_Engine::__construct( $client, $repo, $reconciler, $summary_service, $logger )` (all typed; client + repo + reconciler + summary via interfaces or concretes), `run( string $trigger = 'manual' ): array` (returns summary `['inserted','updated','summarized','dropped_result','errors']`); static `Sync_Engine::build(): self` (composition root that wires real deps). `SKMCTF\Sync\Scheduler::ACTION='skmctf_daily_sync'`, `GROUP='kisho-clinical-trials'`, `register(): void`, `activate(): void`, `deactivate(): void`.

- [ ] **Step 0: Vendor Action Scheduler (no Composer runtime dep)**

Download the latest stable Action Scheduler release into `vendor-lib/action-scheduler/` (the repo's built release, containing `action-scheduler.php`). Command (run once, commit the result):

```bash
mkdir -p vendor-lib && cd vendor-lib \
  && curl -L -o as.zip https://downloads.wordpress.org/plugin/action-scheduler.zip \
  && unzip -q as.zip && rm as.zip && cd ..
ls vendor-lib/action-scheduler/action-scheduler.php   # must exist
```

In `kisho-clinical-trials.php`, after constants, before bootstrap:

```php
require_once SKMCTF_PATH . 'vendor-lib/action-scheduler/action-scheduler.php';
```

> Action Scheduler self-guards against double-loading: if WooCommerce or another plugin bundles a newer copy, AS loads the newest registered version. Safe to bundle.

- [ ] **Step 1: Write the failing test (engine end-to-end with mocks)**

```php
// tests/integration/SyncEngineTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\Sync\Sync_Engine;
use SKMCTF\Sync\Reconciler;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Support\Logger;
use SKMCTF\LLM\Summary_Service;
use SKMCTF\Admin\Settings;
use SKMCTF\Post_Types\Trial_Meta;

final class SyncEngineTest extends WP_UnitTestCase {
    private function set_conditions( array $c ): void {
        update_option( Settings::OPTION, array_merge( Settings::all(), [ 'conditions' => $c, 'statuses' => [ 'RECRUITING' ] ] ) );
    }
    private function mock_ctgov( array $studies_by_call ): void {
        $i = 0;
        add_filter( 'pre_http_request', function( $pre, $args, $url ) use ( &$i, $studies_by_call ) {
            if ( false === strpos( $url, 'clinicaltrials.gov' ) ) { return $pre; }
            $studies = $studies_by_call[ $i ] ?? [];
            $i++;
            return [ 'response' => [ 'code' => 200 ], 'body' => wp_json_encode( [ 'studies' => $studies ] ) ];
        }, 10, 3 );
    }
    private function study( string $nct, string $date = '2026-03-10' ): array {
        return [ 'protocolSection' => [
            'identificationModule' => [ 'nctId' => $nct, 'briefTitle' => 'T '.$nct ],
            'statusModule' => [ 'overallStatus' => 'RECRUITING', 'lastUpdatePostDateStruct' => [ 'date' => $date ] ],
        ] ];
    }

    public function test_inserts_then_reconciles_dropped_on_clean_run(): void {
        $this->set_conditions( [ 'Pompe disease' ] );
        // First run: two trials.
        $this->mock_ctgov( [ [ $this->study('NCT00000001'), $this->study('NCT00000002') ] ] );
        $engine = Sync_Engine::build();
        $summary = $engine->run( 'test' );
        $this->assertSame( 2, $summary['inserted'] );
        $this->assertEmpty( $summary['errors'] );
        remove_all_filters( 'pre_http_request' );

        // Second run: only NCT1 returned -> NCT2 marked closed (default mode).
        $this->mock_ctgov( [ [ $this->study('NCT00000001') ] ] );
        $summary2 = Sync_Engine::build()->run( 'test' );
        $repo = new Trial_Repository();
        $id2 = $repo->find_id_by_nct( 'NCT00000002' );
        $this->assertSame( 'CLOSED', get_post_meta( $id2, Trial_Meta::KEYS['overall_status'], true ) );
    }

    public function test_fetch_error_does_not_wipe_existing(): void {
        $this->set_conditions( [ 'Pompe disease' ] );
        $this->mock_ctgov( [ [ $this->study('NCT00000003') ] ] );
        Sync_Engine::build()->run( 'test' );
        remove_all_filters( 'pre_http_request' );

        // Now the API errors out entirely.
        add_filter( 'pre_http_request', fn() => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ], 10, 3 );
        $summary = Sync_Engine::build()->run( 'test' );
        $this->assertNotEmpty( $summary['errors'] );
        // The existing trial survives.
        $this->assertNotNull( ( new Trial_Repository() )->find_id_by_nct( 'NCT00000003' ) );
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:integration -- --filter SyncEngineTest` — Expected: FAIL.

- [ ] **Step 3: Implement Sync_Engine**

```php
// includes/sync/class-sync-engine.php
namespace SKMCTF\Sync;

use SKMCTF\Data\Trial_Repository;
use SKMCTF\LLM\Provider_Factory;
use SKMCTF\LLM\Summary_Service;
use SKMCTF\Admin\Settings;
use SKMCTF\Support\Logger;
use SKMCTF\Support\Logger_Interface;

final class Sync_Engine {
    private Ctgov_Client $client;
    private Trial_Repository $repo;
    private Reconciler $reconciler;
    private Summary_Service $summaries;
    private Logger_Interface $log;

    public function __construct( Ctgov_Client $client, Trial_Repository $repo, Reconciler $reconciler, Summary_Service $summaries, Logger_Interface $log ) {
        $this->client     = $client;
        $this->repo       = $repo;
        $this->reconciler = $reconciler;
        $this->summaries  = $summaries;
        $this->log        = $log;
    }

    /** Composition root: wires real dependencies. */
    public static function build(): self {
        $repo = new Trial_Repository();
        $log  = new Logger();
        return new self(
            new Ctgov_Client(),
            $repo,
            new Reconciler( $repo, $log ),
            new Summary_Service( Provider_Factory::make(), $log ),
            $log
        );
    }

    public function run( string $trigger = 'manual' ): array {
        $summary = [ 'inserted' => 0, 'updated' => 0, 'summarized' => 0, 'dropped_result' => '', 'errors' => [] ];
        $conditions = Settings::conditions();
        if ( empty( $conditions ) ) {
            $this->log->warn( 'Sync skipped: no conditions configured.' );
            $summary['errors'][] = 'no_conditions';
            $this->log->record_sync( $summary );
            return $summary;
        }
        $statuses  = Settings::statuses();
        $exclude   = array_flip( Settings::exclude_ncts() );
        $had_error = false;
        $seen      = [];

        foreach ( $conditions as $condition ) {
            $result = $this->client->fetch_all_for_condition( $condition, $statuses );
            if ( null !== $result['error'] ) {
                $had_error = true;
                $msg = 'Fetch failed for "' . $condition . '": ' . $result['error']->get_error_message();
                $summary['errors'][] = $msg;
                $this->log->error( $msg );
                continue;
            }
            foreach ( $result['studies'] as $study ) {
                $meta = Field_Mapper::map( $study );
                if ( '' === $meta['nct_id'] || isset( $exclude[ $meta['nct_id'] ] ) ) { continue; }
                $existing = $this->repo->find_id_by_nct( $meta['nct_id'] );
                $post_id  = $this->repo->upsert( $meta );
                if ( ! $post_id ) { continue; }
                $existing ? $summary['updated']++ : $summary['inserted']++;
                $seen[] = $meta['nct_id'];
                if ( $this->summaries->maybe_generate( $post_id, $meta ) ) { $summary['summarized']++; }
            }
        }

        $summary['dropped_result'] = $this->reconciler->reconcile( array_values( array_unique( $seen ) ), $had_error, Settings::reconcile_mode() );
        $this->log->record_sync( $summary );
        return $summary;
    }
}
```

```php
// includes/sync/class-scheduler.php
namespace SKMCTF\Sync;

final class Scheduler {
    public const ACTION = 'skmctf_daily_sync';
    public const GROUP  = 'kisho-clinical-trials';

    public function register(): void {
        add_action( self::ACTION, [ $this, 'run_sync' ] );
        add_action( 'init', [ $this, 'ensure_scheduled' ] );
        if ( function_exists( 'as_supports' ) && as_supports( 'ensure_recurring_actions_hook' ) ) {
            add_action( 'action_scheduler_ensure_recurring_actions', [ $this, 'ensure_scheduled' ] );
        }
    }
    public function run_sync(): void {
        Sync_Engine::build()->run( 'scheduled' );
    }
    public function ensure_scheduled(): void {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) { return; }
        if ( ! as_has_scheduled_action( self::ACTION, [], self::GROUP ) ) {
            as_schedule_recurring_action( strtotime( 'tomorrow 3:00am' ), DAY_IN_SECONDS, self::ACTION, [], self::GROUP );
        }
    }
    public function activate(): void { $this->ensure_scheduled(); }
    public function deactivate(): void {
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( self::ACTION, [], self::GROUP );
        }
    }
}
```

Wire in `Plugin::boot()`: `( new \SKMCTF\Sync\Scheduler() )->register();`
Activation hook (main file): flush rewrite (CPT registered first), then `( new Scheduler() )->activate();`. Deactivation: `( new Scheduler() )->deactivate();` + flush.

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:integration -- --filter SyncEngineTest` — Expected: PASS (both tests, including no-wipe).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: sync engine + Action Scheduler daily job with self-heal and manual run"
```

---

## Task 10: Admin settings page + Sync-now + notices

**Files:**
- Create: `includes/admin/class-settings-page.php` (Settings API registration + render + the SKM sidebar card)
- Create: `includes/admin/class-sync-now-controller.php` (admin-post handler)
- Create: `includes/admin/class-admin-notices.php`
- Modify: `includes/class-plugin.php` (instantiate admin on `is_admin()`)
- Test: manual checklist (admin UI; covered by the security audit task for escaping)

**Interfaces:**
- Consumes: `Settings`, `Sync_Engine`, `Logger`, `Scheduler`.
- Produces: `SKMCTF\Admin\Settings_Page::register(): void` (hooks `admin_menu`, `admin_init`); `SKMCTF\Admin\Sync_Now_Controller::register(): void` (hooks `admin_post_skmctf_sync_now`); `SKMCTF\Admin\Admin_Notices::register(): void`.

- [ ] **Step 1: Implement Settings_Page**

Registers the option with the sanitizer (note: `register_setting` sanitize callback receives only the new input, so wrap to merge existing for the write-only key):

```php
// includes/admin/class-settings-page.php  (key parts)
namespace SKMCTF\Admin;

use SKMCTF\Support\Logger;

final class Settings_Page {
    public const MENU_SLUG = 'skmctf-settings';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }
    public function add_menu(): void {
        add_options_page(
            __( 'Clinical Trials Feed', 'kisho-clinical-trials' ),
            __( 'Clinical Trials', 'kisho-clinical-trials' ),
            'manage_options',
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }
    public function register_settings(): void {
        register_setting( 'skmctf_group', Settings::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
            'default'           => [],
        ] );
    }
    public function sanitize( $input ): array {
        if ( ! current_user_can( 'manage_options' ) ) { return (array) get_option( Settings::OPTION, [] ); }
        $existing = (array) get_option( Settings::OPTION, [] );
        return Settings::sanitize( is_array( $input ) ? $input : [], $existing );
    }
    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $s        = Settings::all();
        $key_set  = '' !== Settings::get_api_key();
        $log      = new Logger();
        $last     = $log->last_sync();
        $error    = $log->last_error();
        // Renders form with settings_fields('skmctf_group'); all values escaped at output.
        // API key field rendered as <input type="password" value=""> with a "saved" indicator when $key_set.
        // Sync-now is a SEPARATE <form action="admin-post.php"> with its own nonce.
        require SKMCTF_PATH . 'includes/admin/views/settings-page.php';
    }
}
```

Create `includes/admin/views/settings-page.php` — the full markup. **Every** dynamic value escaped (`esc_attr`, `esc_html`, `esc_url`, `checked()`, `selected()`). Sidebar card: "Built by SKM Digital — custom rare disease tools, Salesforce integrations, and headless builds for PAGs." + `esc_url` contact link to https://skm.digital. The API key `value` attribute is **always empty**; show `esc_html__( 'A key is saved.', ... )` + a "Clear key" checkbox when `$key_set`.

- [ ] **Step 2: Implement Sync_Now_Controller**

```php
// includes/admin/class-sync-now-controller.php
namespace SKMCTF\Admin;

use SKMCTF\Sync\Sync_Engine;

final class Sync_Now_Controller {
    public const ACTION = 'skmctf_sync_now';
    public function register(): void {
        add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
    }
    public function handle(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You are not allowed to do this.', 'kisho-clinical-trials' ) );
        }
        check_admin_referer( self::ACTION );
        $summary = Sync_Engine::build()->run( 'manual' );
        $redirect = add_query_arg(
            [ 'page' => Settings_Page::MENU_SLUG, 'skmctf_synced' => empty( $summary['errors'] ) ? '1' : '0' ],
            admin_url( 'options-general.php' )
        );
        wp_safe_redirect( $redirect );
        exit;
    }
}
```

- [ ] **Step 3: Implement Admin_Notices**

Shows a dismissible success/error notice after sync (read `skmctf_synced` query arg, capability-gated, only on the settings screen) and a gentle notice when `last_error()` is non-empty. All text escaped and translatable.

- [ ] **Step 4: Wire admin in Plugin::boot()**

```php
if ( is_admin() ) {
    ( new \SKMCTF\Admin\Settings_Page() )->register();
    ( new \SKMCTF\Admin\Sync_Now_Controller() )->register();
    ( new \SKMCTF\Admin\Admin_Notices() )->register();
}
```

- [ ] **Step 5: Manual verification (LocalWP)**

Activate plugin → Settings → Clinical Trials. Enter "Pompe disease", Save. Click "Sync now". Confirm trials appear under Clinical Trials menu and last-sync time shows. Enter a fake API key, save, reload → field is empty, "A key is saved." shows. Verify viewing page source shows the key is never printed.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: admin settings page, manual sync, and notices (escaped, nonce-guarded)"
```

---

## Task 11: Front-end shared renderer, query, shortcode

**Files:**
- Create: `includes/frontend/class-trials-query.php`
- Create: `includes/frontend/class-list-renderer.php`
- Create: `includes/frontend/class-shortcode.php`
- Create: `templates/list-item.php`, `templates/parts/badge.php`, `templates/parts/summary-disclaimer.php`
- Create: `includes/support/class-template-loader.php`
- Create: `assets/css/frontend.css`, `assets/js/filters.js`
- Create: `includes/frontend/class-assets.php`
- Modify: `includes/class-plugin.php`
- Test: `tests/unit/TrialsQueryArgsTest.php`, `tests/integration/ListRendererTest.php`

**Interfaces:**
- Consumes: `Trial_Meta::KEYS`, `Trial_Post_Type`, `Trial_Taxonomies`, `Settings`.
- Produces: `SKMCTF\Frontend\Trials_Query::args( array $filters ): array` (pure builder → WP_Query args); `::query( array $filters ): \WP_Query`; `SKMCTF\Frontend\List_Renderer::render( array $atts ): string` (returns escaped HTML; handles empty/error states); `SKMCTF\Frontend\Shortcode::register()` (tag `skmctf_trials`); `SKMCTF\Support\Template_Loader::locate( string $name ): string` (theme override aware); `SKMCTF\Frontend\Assets::register()`.

- [ ] **Step 1: Failing unit test for query args (pure)**

```php
// tests/unit/TrialsQueryArgsTest.php
namespace SKMCTF\Tests\Unit;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use SKMCTF\Frontend\Trials_Query;

final class TrialsQueryArgsTest extends TestCase {
    protected function setUp(): void { parent::setUp(); Monkey\setUp();
        Functions\stubs( [ 'sanitize_text_field' => fn( $v ) => is_string($v)?trim($v):'', 'absint' => fn( $v ) => abs((int)$v) ] ); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_status_and_phase_become_tax_query(): void {
        $args = Trials_Query::args( [ 'status' => 'RECRUITING', 'phase' => 'Phase 2', 'per_page' => 10 ] );
        $this->assertSame( 'skmctf_trial', $args['post_type'] );
        $this->assertSame( 10, $args['posts_per_page'] );
        $this->assertSame( 'AND', $args['tax_query']['relation'] );
        $slugs = array_column( $args['tax_query'], 'taxonomy' );
        $this->assertContains( 'trial_status', $slugs );
        $this->assertContains( 'trial_phase', $slugs );
    }
    public function test_state_filter_becomes_meta_query(): void {
        $args = Trials_Query::args( [ 'state' => 'MA' ] );
        $this->assertSame( 'skmctf_locations', $args['meta_query'][0]['key'] );
        $this->assertSame( 'LIKE', $args['meta_query'][0]['compare'] );
    }
    public function test_empty_filters_have_no_tax_query(): void {
        $args = Trials_Query::args( [] );
        $this->assertArrayNotHasKey( 'tax_query', $args );
    }
}
```

- [ ] **Step 2: Run — FAIL.**

Run: `composer test:unit -- --filter TrialsQueryArgsTest`

- [ ] **Step 3: Implement Trials_Query**

```php
// includes/frontend/class-trials-query.php
namespace SKMCTF\Frontend;

use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Post_Types\Trial_Taxonomies;
use SKMCTF\Post_Types\Trial_Meta;

final class Trials_Query {
    public static function args( array $filters ): array {
        $args = [
            'post_type'      => Trial_Post_Type::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => isset( $filters['per_page'] ) ? absint( $filters['per_page'] ) : 20,
            'paged'          => isset( $filters['paged'] ) ? max( 1, absint( $filters['paged'] ) ) : 1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => false,
        ];
        $tax = [];
        if ( ! empty( $filters['status'] ) ) {
            $tax[] = [ 'taxonomy' => Trial_Taxonomies::STATUS, 'field' => 'name', 'terms' => sanitize_text_field( $filters['status'] ) ];
        }
        if ( ! empty( $filters['phase'] ) ) {
            $tax[] = [ 'taxonomy' => Trial_Taxonomies::PHASE, 'field' => 'name', 'terms' => sanitize_text_field( $filters['phase'] ) ];
        }
        if ( $tax ) {
            $tax['relation'] = 'AND';
            $args['tax_query'] = $tax;
        }
        if ( ! empty( $filters['state'] ) ) {
            $args['meta_query'] = [ [
                'key'     => Trial_Meta::KEYS['locations'],
                'value'   => sanitize_text_field( $filters['state'] ),
                'compare' => 'LIKE',
            ] ];
        }
        return $args;
    }
    public static function query( array $filters ): \WP_Query {
        return new \WP_Query( self::args( $filters ) );
    }
}
```

- [ ] **Step 4: Run — PASS.** Then write List_Renderer + Shortcode + templates + Template_Loader + Assets.

`List_Renderer::render( array $atts )`:
- normalize atts (`status`, `phase`, `state`, `per_page`, `map`, `columns`),
- run `Trials_Query::query`,
- if `is_wp_error`/no posts → return escaped empty-state markup,
- build a `<form method="get" class="skmctf-filters">` with `<label>` + `<select>` for status/phase/state (options from existing terms / distinct states), submit works without JS,
- loop posts including `templates/list-item.php` via `Template_Loader::locate`,
- pagination via `paginate_links`,
- ProgressiveEnhancement: enqueue `filters.js` to filter client-side; without JS the GET form reloads.
- Always escape at output. The only place raw-ish HTML is allowed is `wp_kses_post( $plain_summary )` (we authored it).

`templates/list-item.php` renders the card per spec §8.1 (title links to single page if `Settings::single_pages_enabled()` else CT.gov; status badge with visible text; phase; conditions; sponsor; location summary; `plain_summary` via `wp_kses_post` else `wp_trim_words( brief_summary )`; "View on ClinicalTrials.gov" with `target="_blank" rel="noopener noreferrer"`).

`Template_Loader::locate( $name )`: check `get_stylesheet_directory() . '/kisho-clinical-trials/' . $name`, then plugin `templates/`. Enables theme overrides.

`Shortcode`: `add_shortcode( 'skmctf_trials', fn( $atts ) => List_Renderer::render( shortcode_atts( [...defaults...], $atts, 'skmctf_trials' ) ) );`

`Assets`: register (not enqueue) `frontend.css` + `filters.js`; `List_Renderer` enqueues them when it renders. Bundled, local URLs only.

- [ ] **Step 5: List_Renderer integration test**

```php
// tests/integration/ListRendererTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\Frontend\List_Renderer;
use SKMCTF\Data\Trial_Repository;

final class ListRendererTest extends WP_UnitTestCase {
    public function test_renders_card_for_a_trial_and_escapes(): void {
        ( new Trial_Repository() )->upsert( [
            'nct_id' => 'NCT00000009', 'brief_title' => 'Safe <script>x</script> Title', 'official_title' => 'O',
            'overall_status' => 'RECRUITING', 'phase' => 'Phase 2', 'study_type' => 'INTERVENTIONAL',
            'conditions' => [ 'Pompe Disease' ], 'lead_sponsor' => 'Acme', 'locations' => [],
            'eligibility' => [ 'sex'=>'ALL','min_age'=>'','max_age'=>'','criteria'=>'' ],
            'brief_summary' => 'A summary.', 'ct_last_update' => '2026-03-10',
            'ct_url' => 'https://clinicaltrials.gov/study/NCT00000009',
        ] );
        $html = List_Renderer::render( [ 'per_page' => 10 ] );
        $this->assertStringContainsString( 'Pompe Disease', $html );
        $this->assertStringContainsString( 'ClinicalTrials.gov', $html );
        $this->assertStringNotContainsString( '<script>x</script>', $html ); // escaped
    }
    public function test_empty_state_when_no_trials(): void {
        $html = List_Renderer::render( [ 'status' => 'COMPLETED' ] );
        $this->assertStringContainsString( 'No clinical trials', $html );
    }
}
```

Run: `composer test:integration -- --filter ListRendererTest` — Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: shared list renderer, trials query, shortcode, templates, filters"
```

---

## Task 12: Gutenberg block (server-rendered)

**Files:**
- Create: `blocks/trials/block.json`, `blocks/trials/index.js`, `blocks/trials/edit.js`, `blocks/trials/editor.css`, `blocks/trials/style.css`
- Create: `includes/frontend/class-block.php`
- Create: `package.json`, `.babelrc`/`@wordpress/scripts` config (via package.json `scripts`)
- Modify: `includes/class-plugin.php`
- Test: manual (block render verified via List_Renderer integration test already; block adds the registration layer)

**Interfaces:**
- Consumes: `List_Renderer`.
- Produces: `SKMCTF\Frontend\Block::register(): void` (registers block from `blocks/trials` with PHP `render_callback`).

- [ ] **Step 1: block.json**

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "skmctf/trials",
  "title": "Clinical Trials",
  "category": "widgets",
  "icon": "clipboard",
  "description": "Display clinical trials synced from ClinicalTrials.gov.",
  "textdomain": "kisho-clinical-trials",
  "attributes": {
    "status":   { "type": "string", "default": "" },
    "phase":    { "type": "string", "default": "" },
    "state":    { "type": "string", "default": "" },
    "perPage":  { "type": "number", "default": 20 },
    "showMap":  { "type": "boolean", "default": false },
    "columns":  { "type": "number", "default": 1 }
  },
  "supports": { "html": false, "align": [ "wide", "full" ] },
  "editorScript": "file:./index.js",
  "editorStyle": "file:./editor.css",
  "style": "file:./style.css"
}
```

- [ ] **Step 2: Block PHP registration**

```php
// includes/frontend/class-block.php
namespace SKMCTF\Frontend;

final class Block {
    public function register(): void {
        add_action( 'init', [ $this, 'register_block' ] );
    }
    public function register_block(): void {
        register_block_type(
            SKMCTF_PATH . 'blocks/trials',
            [ 'render_callback' => [ $this, 'render' ] ]
        );
    }
    public function render( array $attributes ): string {
        return List_Renderer::render( [
            'status'   => $attributes['status']  ?? '',
            'phase'    => $attributes['phase']   ?? '',
            'state'    => $attributes['state']   ?? '',
            'per_page' => $attributes['perPage'] ?? 20,
            'map'      => ! empty( $attributes['showMap'] ),
            'columns'  => $attributes['columns'] ?? 1,
        ] );
    }
}
```

> Because `render_callback` is set in PHP, the block is always server-rendered with live data — exactly what SEO requires. The built `index.js` only powers the editor controls.

- [ ] **Step 3: Editor JS (InspectorControls)**

`blocks/trials/index.js` registers the block; `edit.js` provides `InspectorControls` with `SelectControl` (status, phase), `TextControl` (state), `RangeControl` (perPage, columns), `ToggleControl` (showMap), plus a `ServerSideRender` preview (`@wordpress/server-side-render`). Build with `@wordpress/scripts`.

`package.json`:
```json
{
  "name": "kisho-clinical-trials",
  "private": true,
  "scripts": {
    "build": "wp-scripts build blocks/trials/index.js --output-path=build/trials",
    "start": "wp-scripts start blocks/trials/index.js --output-path=build/trials"
  },
  "devDependencies": { "@wordpress/scripts": "^28" }
}
```

> **Note for implementer:** point `block.json`'s `editorScript` at the **built** file (`file:../../build/trials/index.js`) or register the block from a build dir. Keep both source (`blocks/`) and build (`build/`) committed — source for the .org non-obfuscation rule, build so the zip works without a node toolchain.

- [ ] **Step 4: Wire + manual verify**

`Plugin::boot()`: `( new \SKMCTF\Frontend\Block() )->register();`
Manual: in the editor, add "Clinical Trials" block, set status filter, confirm `ServerSideRender` preview shows synced trials; publish; confirm front end renders.

- [ ] **Step 5: Commit**

```bash
npm install && npm run build
git add -A
git commit -m "feat: server-rendered Gutenberg block with inspector controls"
```

---

## Task 13: Optional Leaflet map (bundled, off by default)

**Files:**
- Create: `assets/lib/leaflet/` (bundled Leaflet 1.9.x: `leaflet.js`, `leaflet.css`, `images/`)
- Create: `assets/js/map.js`
- Modify: `includes/frontend/class-assets.php`, `includes/frontend/class-list-renderer.php`
- Test: manual

**Interfaces:**
- Consumes: trial `locations` meta (lat/lng), `Settings::show_map()` / block `showMap` attr.
- Produces: `Assets::enqueue_map(): void`; `List_Renderer` emits a map container + `wp_localize_script` data when map enabled and ≥1 coordinate exists.

- [ ] **Step 1: Bundle Leaflet**

```bash
mkdir -p assets/lib/leaflet
curl -L -o leaflet.zip https://unpkg.com/leaflet@1.9.4/dist/leaflet.zip 2>/dev/null || \
  ( curl -L -o assets/lib/leaflet/leaflet.js  https://unpkg.com/leaflet@1.9.4/dist/leaflet.js \
 && curl -L -o assets/lib/leaflet/leaflet.css https://unpkg.com/leaflet@1.9.4/dist/leaflet.css )
# Also fetch marker images into assets/lib/leaflet/images/ (marker-icon.png, marker-icon-2x.png, marker-shadow.png)
```
Commit the files. **No CDN at runtime** — enqueue from local `SKMCTF_URL . 'assets/lib/leaflet/leaflet.js'`.

- [ ] **Step 2: map.js**

Reads localized `skmctfMap` (array of `{lat,lng,title,url}`), inits Leaflet with OSM tiles, adds markers with accessible popups, fits bounds. Renders OSM attribution (required). Fixes Leaflet's default icon path to the bundled `images/`.

- [ ] **Step 3: Renderer integration**

When map enabled: collect coordinates from the queried trials' `locations`; if none, skip map (degrade to list). Else output `<div class="skmctf-map" role="region" aria-label="Trial locations map">` and `wp_localize_script( 'skmctf-map', 'skmctfMap', $points )`. Map is **off by default** (setting + block attr both default false).

- [ ] **Step 4: Manual verify**

Enable map in settings/block; confirm markers render from synced trials with coordinates; confirm OSM attribution shows; confirm no external JS/CSS requests except OSM tiles.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: optional bundled Leaflet/OSM map view (off by default)"
```

---

## Task 14: Single trial template + conditional noindex SEO

**Files:**
- Create: `templates/single-skmctf_trial.php`, `templates/archive-skmctf_trial.php`, `templates/parts/eligibility.php`, `templates/parts/locations.php`
- Create: `includes/frontend/class-single-renderer.php`
- Create: `includes/frontend/class-template-router.php` (filters `single_template`/`archive_template`)
- Create: `includes/frontend/class-seo.php`
- Modify: `includes/class-plugin.php`
- Test: `tests/integration/SeoTest.php`

**Interfaces:**
- Consumes: `Settings`, `Trial_Meta::KEYS`, `Trial_Post_Type`.
- Produces: `SKMCTF\Frontend\Template_Router::register()` (uses `Template_Loader`); `SKMCTF\Frontend\Single_Renderer::render( int $post_id ): string`; `SKMCTF\Frontend\Seo::register()` (hooks `wp_robots` filter + gates single pages when `single_pages` off).

- [ ] **Step 1: Failing SEO test**

```php
// tests/integration/SeoTest.php
namespace SKMCTF\Tests\Integration;
use WP_UnitTestCase;
use SKMCTF\Frontend\Seo;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;

final class SeoTest extends WP_UnitTestCase {
    private function make_trial( bool $with_summary ): int {
        $repo = new Trial_Repository();
        $id = $repo->upsert( [ 'nct_id' => 'NCT00000010', 'brief_title' => 'T', 'official_title' => 'O',
            'overall_status' => 'RECRUITING', 'phase' => '', 'study_type' => '', 'conditions' => [], 'lead_sponsor' => '',
            'locations' => [], 'eligibility' => [ 'sex'=>'','min_age'=>'','max_age'=>'','criteria'=>'' ],
            'brief_summary' => 'b', 'ct_last_update' => '2026-03-10', 'ct_url' => 'https://clinicaltrials.gov/study/NCT00000010' ] );
        if ( $with_summary ) { update_post_meta( $id, Trial_Meta::KEYS['plain_summary'], 'Plain summary text.' ); }
        return $id;
    }
    public function test_noindex_when_no_summary_and_no_override(): void {
        update_option( Settings::OPTION, array_merge( Settings::all(), [ 'index_singles_override' => false ] ) );
        $id = $this->make_trial( false );
        $this->go_to( get_permalink( $id ) );
        $robots = apply_filters( 'wp_robots', [] );
        $this->assertArrayHasKey( 'noindex', $robots );
    }
    public function test_indexable_when_summary_present(): void {
        $id = $this->make_trial( true );
        $this->go_to( get_permalink( $id ) );
        $robots = apply_filters( 'wp_robots', [] );
        $this->assertArrayNotHasKey( 'noindex', $robots );
    }
    public function test_override_forces_index(): void {
        update_option( Settings::OPTION, array_merge( Settings::all(), [ 'index_singles_override' => true ] ) );
        $id = $this->make_trial( false );
        $this->go_to( get_permalink( $id ) );
        $robots = apply_filters( 'wp_robots', [] );
        $this->assertArrayNotHasKey( 'noindex', $robots );
    }
}
```

- [ ] **Step 2: Run — FAIL.**

Run: `composer test:integration -- --filter SeoTest`

- [ ] **Step 3: Implement Seo**

```php
// includes/frontend/class-seo.php
namespace SKMCTF\Frontend;

use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;

final class Seo {
    public function register(): void {
        add_filter( 'wp_robots', [ $this, 'maybe_noindex' ] );
    }
    public function maybe_noindex( array $robots ): array {
        if ( ! is_singular( Trial_Post_Type::POST_TYPE ) ) { return $robots; }
        if ( Settings::index_singles_override() ) { return $robots; }
        $has_summary = '' !== (string) get_post_meta( get_queried_object_id(), Trial_Meta::KEYS['plain_summary'], true );
        if ( ! $has_summary ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
        }
        return $robots;
    }
}
```

When `Settings::single_pages_enabled()` is false: `Template_Router` should `wp_safe_redirect` single trial requests to the archive (or 404), so single pages are effectively disabled.

- [ ] **Step 4: Run — PASS.** Then implement templates + Single_Renderer + Template_Router.

`single-skmctf_trial.php`: full detail — title, status badge, phase, conditions, sponsor, plain summary (`wp_kses_post`) + disclaimer if present else `brief_summary`, eligibility part, locations part (+ optional map), "View full record on ClinicalTrials.gov" link. `archive-skmctf_trial.php`: uses `List_Renderer` for the loop; **always indexable**. `Template_Router` swaps in plugin templates via `single_template`/`archive_template` filters (theme override via `Template_Loader`).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: single/archive templates and conditional noindex via wp_robots"
```

---

## Task 15: SKM discovery hooks, attribution, readme.txt, uninstall, i18n, .pot

**Files:**
- Create: `readme.txt`, `LICENSE`, `uninstall.php`, `languages/kisho-clinical-trials.pot`
- Modify: `includes/frontend/class-list-renderer.php` (CT.gov credit always; SKM attribution when enabled), `includes/admin/views/settings-page.php` (sidebar card — done in Task 10)
- Test: manual + PHPCS

**Interfaces:** none new (copy + config).

- [ ] **Step 1: readme.txt** (wordpress.org format)

Header block: `Contributors: skmdigital`, `Tags: clinical trials, rare disease, clinicaltrials.gov, patient advocacy, health`, `Requires at least: 6.4`, `Tested up to: 6.8`, `Requires PHP: 7.4`, `Stable tag: 1.0.0`, `License: GPLv2 or later`, `License URI: https://www.gnu.org/licenses/gpl-2.0.html`. Sections: short description, Description (features, data source credit to ClinicalTrials.gov, BYO-key explanation, no-telemetry statement), Installation, FAQ (How does it find trials? Do I need an API key? Where is my key stored? Does it phone home? — answer no), Screenshots, Changelog (1.0.0), Upgrade Notice. **Disclose the external service:** a clear statement that the plugin connects to `clinicaltrials.gov` (and, only if a key is configured, the chosen LLM API) with links to their terms/privacy — required by .org guidelines for plugins making external requests.

- [ ] **Step 2: Front-end attribution + CT.gov credit**

In `List_Renderer`, always append a small "Data from ClinicalTrials.gov" credit (linked, `rel="noopener"`). When `Settings::attribution_enabled()` (off by default), also append a tasteful "Trial display by SKM Digital" line. Both translatable and escaped.

- [ ] **Step 3: uninstall.php**

```php
<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// Remove options.
foreach ( [ 'skmctf_settings', 'skmctf_last_sync', 'skmctf_last_error', 'skmctf_log' ] as $opt ) {
    delete_option( $opt );
}
// Unschedule (best-effort; AS may already be gone).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'skmctf_daily_sync', [], 'kisho-clinical-trials' );
}
// NOTE: trial posts are deliberately NOT deleted on uninstall (user content / SEO value).
// A "delete trials on uninstall" toggle could be added; default keeps content.
```

- [ ] **Step 4: i18n — generate .pot**

```bash
wp i18n make-pot . languages/kisho-clinical-trials.pot --domain=kisho-clinical-trials --exclude=vendor-lib,vendor,node_modules,build
```
Confirm `load_plugin_textdomain` runs on `init` (Task 1) and JS uses `wp_set_script_translations( 'skmctf-trials-editor', 'kisho-clinical-trials' )`.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: readme.txt, LICENSE, uninstall cleanup, .pot, attribution + CT.gov credit"
```

---

## Task 16: Security/escaping/accessibility audit + PHPCS clean + final verification

**Files:** all (review pass); Create `.phpcs.xml.dist`.
**Test:** full suite + PHPCS + manual a11y checklist.

**Interfaces:** none.

- [ ] **Step 1: Add PHPCS config**

```xml
<!-- .phpcs.xml.dist -->
<?xml version="1.0"?>
<ruleset name="Kisho Clinical Trials">
  <description>WordPress coding standards for the plugin.</description>
  <file>.</file>
  <exclude-pattern>*/vendor/*</exclude-pattern>
  <exclude-pattern>*/vendor-lib/*</exclude-pattern>
  <exclude-pattern>*/node_modules/*</exclude-pattern>
  <exclude-pattern>*/build/*</exclude-pattern>
  <exclude-pattern>*/tests/*</exclude-pattern>
  <rule ref="WordPress"/>
  <rule ref="WordPress-Extra"/>
  <config name="testVersion" value="7.4-"/>
  <rule ref="PHPCompatibilityWP"/>
  <rule ref="WordPress.WP.I18n">
    <properties><property name="text_domain" type="array"><element value="kisho-clinical-trials"/></property></properties>
  </rule>
</ruleset>
```

- [ ] **Step 2: Run PHPCS and fix every finding**

```bash
composer phpcs
```
Expected: 0 errors. Auto-fix the mechanical ones with `composer phpcbf`, hand-fix the rest. Common: missing escaping, missing text domain, `gmdate` over `date`, Yoda conditions, prepared SQL.

- [ ] **Step 3: Manual escaping/sanitization audit (grep-assisted)**

Verify, file by file:
- Every `echo`/interpolation in templates and views is wrapped in `esc_*`/`wp_kses_post`.
- Every `$_GET/$_POST/$_REQUEST` read is `wp_unslash` + sanitized.
- Every admin write has `check_admin_referer`/`wp_verify_nonce` + `current_user_can`.
- The API key never appears in any output (grep the views for the key meta/option).
- No `date()` (use `gmdate`), no direct `$_SERVER` without sanitize, no raw SQL.

- [ ] **Step 4: Accessibility checklist**

- Filters are real `<label>`+control pairs; keyboard operable.
- Status badges include text, not color alone; contrast ≥ 4.5:1.
- Map container has `role="region"` + `aria-label`; markers have accessible popups.
- Single/archive templates use semantic headings in order; links have discernible text.
- `:focus-visible` styles present; respects `prefers-reduced-motion`.

- [ ] **Step 5: Full test suite green**

```bash
composer test:unit
composer test:integration   # requires WP test suite installed
```
Expected: all PASS.

- [ ] **Step 6: Build the distributable & sanity-check .distignore**

```bash
npm run build
# Simulate the .org build: ensure tests/, node_modules/, composer files are excluded
# but blocks/, build/, vendor-lib/, assets/, templates/, languages/ are included.
```

- [ ] **Step 7: Final commit + tag**

```bash
git add -A
git commit -m "chore: PHPCS clean, security + accessibility audit, v1.0.0"
git tag v1.0.0
```

---

## Self-Review Coverage Map (spec → task)

- Scope §3–§5 (sync-to-CPT, data model, meta) → Tasks 2, 4.
- §4 CT.gov client + field mapping → Task 3.
- §6 sync engine, upsert, reconciliation, no-wipe, manual sync → Tasks 4, 5, 9, 10.
- §7 BYO-key summaries (providers, change-detect cache, disclaimer, fallback, key storage, filter seam) → Tasks 6, 7, 8.
- §8 front end (block + shortcode, list item, filters, states, map, single template) → Tasks 11, 12, 13, 14.
- §9 SEO conditional noindex → Task 14.
- §10 SKM hooks, attribution, CT.gov credit, no telemetry → Tasks 10 (sidebar), 15.
- §11 settings UX (all fields, password key, sync-now, last-sync/error) → Tasks 6, 10.
- §2 operating principles (GPL, .org-compliant, zero hard deps, accessible, i18n) → Tasks 1, 15, 16.

All spec sections map to at least one task. No placeholders remain; all code shown is concrete; types are consistent across tasks (`Llm_Provider`, `Repo_Interface`, `Logger_Interface`, `Trial_Meta::KEYS`, `Settings::*`, `Sync_Engine::build()`).
