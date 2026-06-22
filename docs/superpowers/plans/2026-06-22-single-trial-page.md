# Single Clinical Trial Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the single clinical-trial page render the full patient-focused detail it was designed for, and add three sync-time LLM fields (study purpose, plain "who can join", and questions to ask your doctor).

**Architecture:** Fix the render wiring so `templates/single-skmctf_trial.php` populates its own variables via `Single_Renderer` (currently dead code). Add three new cached post-meta fields generated during sync by `Summary_Service` in one combined LLM call, parsed by `Prompt_Builder`. Render new self-guarding template parts in a patient-first order. All LLM content is generated at sync time and cached — never at page load.

**Tech Stack:** PHP 8.3 / WordPress, PHPUnit 9 + Brain Monkey (unit), WordPress-Extra PHPCS ruleset, Composer scripts (`composer test:unit`, `composer phpcs`, `composer phpcbf`).

## Global Constraints

- WordPress Coding Standards (PHPCS WordPress-Extra). Tabs for indent, Yoda conditions, `( $x )` paren spacing, aligned `=>`, full docblocks, long `array()` syntax. Run `composer phpcs` (and `composer phpcbf` for auto-fixes) before each commit.
- Text domain for all i18n: `kisho-clinical-trials`.
- All output escaped; only authored-HTML meta uses `wp_kses_post`.
- LLM safety posture (inherited): factual/extractive only, only-use-provided-info, no medical advice, no speculation, ~8th-grade reading level, medical disclaimer always present.
- No LLM calls at page-render time. New LLM fields generate at sync, cache as meta, change-detect via `ct_last_update` (mirror `plain_summary`).
- New meta short-keys must be added to `SKMCTF\Post_Types\Trial_Meta::KEYS` and registered in `definitions()`.
- `Llm_Provider::generate_summary( string $system, string $user, array $opts = array() ): string|\WP_Error` is the only model entry point.
- Unit tests run without WordPress (Brain Monkey). Repository/template/service behavior that needs WP is covered by integration tests (LocalWP) and manual browser checks.

---

### Task 1: Add new meta keys + sanitizers

**Files:**
- Modify: `includes/post-types/class-trial-meta.php`
- Test: `tests/unit/TrialMetaTest.php`

**Interfaces:**
- Consumes: existing `Trial_Meta::KEYS` array and `definitions()` method.
- Produces: three new short keys in `Trial_Meta::KEYS` — `study_purpose` => `skmctf_study_purpose`, `who_can_join` => `skmctf_who_can_join`, `doctor_questions` => `skmctf_doctor_questions`; plus `study_purpose_source_date` => `skmctf_study_purpose_source_date`, `who_can_join_source_date` => `skmctf_who_can_join_source_date`, `doctor_questions_source_date` => `skmctf_doctor_questions_source_date`. `study_purpose` and `who_can_join` register with `wp_kses_post` sanitize; `doctor_questions` stored as an array of strings (sanitized list). The three `*_source_date` keys register with the existing `sanitize_date` callback.

- [ ] **Step 1: Write the failing test**

Add to `tests/unit/TrialMetaTest.php` (inside the existing test class):

```php
public function test_new_llm_meta_keys_exist(): void {
	$keys = \SKMCTF\Post_Types\Trial_Meta::KEYS;
	$this->assertSame( 'skmctf_study_purpose', $keys['study_purpose'] );
	$this->assertSame( 'skmctf_who_can_join', $keys['who_can_join'] );
	$this->assertSame( 'skmctf_doctor_questions', $keys['doctor_questions'] );
	$this->assertSame( 'skmctf_study_purpose_source_date', $keys['study_purpose_source_date'] );
	$this->assertSame( 'skmctf_who_can_join_source_date', $keys['who_can_join_source_date'] );
	$this->assertSame( 'skmctf_doctor_questions_source_date', $keys['doctor_questions_source_date'] );
}

public function test_sanitize_string_list_filters_and_trims(): void {
	$out = \SKMCTF\Post_Types\Trial_Meta::sanitize_string_list( array( ' Ask about risks ', '', 'Eligibility?' ) );
	$this->assertSame( array( 'Ask about risks', 'Eligibility?' ), $out );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:unit -- --filter TrialMetaTest`
Expected: FAIL — undefined array key `study_purpose` (and the list test may already pass; the key test must fail).

- [ ] **Step 3: Add the keys to `Trial_Meta::KEYS`**

In `includes/post-types/class-trial-meta.php`, inside the `KEYS` const, after the `'plain_summary_source_date'` line add:

```php
		'study_purpose'             => 'skmctf_study_purpose',
		'study_purpose_source_date' => 'skmctf_study_purpose_source_date',
		'who_can_join'              => 'skmctf_who_can_join',
		'who_can_join_source_date'  => 'skmctf_who_can_join_source_date',
		'doctor_questions'          => 'skmctf_doctor_questions',
		'doctor_questions_source_date' => 'skmctf_doctor_questions_source_date',
```

- [ ] **Step 4: Register the meta in `definitions()`**

In `definitions()`, add entries (reuse the existing `$text` array for the two string fields; the existing `sanitize_string_list` + `sanitize_date` callbacks already exist):

```php
			$k['study_purpose']                => $text,
			$k['who_can_join']                 => $text,
			$k['study_purpose_source_date']    => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'show_in_rest'      => true,
			),
			$k['who_can_join_source_date']     => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'show_in_rest'      => true,
			),
			$k['doctor_questions_source_date'] => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'show_in_rest'      => true,
			),
			$k['doctor_questions']             => array(
				'type'              => 'array',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_string_list' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			),
```

- [ ] **Step 5: Run tests + PHPCS**

Run: `composer test:unit -- --filter TrialMetaTest`
Expected: PASS
Run: `vendor/bin/phpcs includes/post-types/class-trial-meta.php` (then `vendor/bin/phpcbf` if it flags alignment)
Expected: clean

- [ ] **Step 6: Commit**

```bash
git add includes/post-types/class-trial-meta.php tests/unit/TrialMetaTest.php
git commit -m "feat: add study_purpose, who_can_join, doctor_questions trial meta"
```

---

### Task 2: Combined-fields prompt + response parser in Prompt_Builder

**Files:**
- Modify: `includes/llm/class-prompt-builder.php`
- Test: `tests/unit/PromptBuilderTest.php`

**Interfaces:**
- Consumes: existing `Prompt_Builder::user()` / `disclaimer()` and `$meta` shape (`brief_title`, `overall_status`, `phase`, `conditions[]`, `lead_sponsor`, `eligibility{sex,min_age,max_age,criteria}`, `brief_summary`).
- Produces:
  - `Prompt_Builder::enhanced_system(): string` — instruction prompt for the combined fields, demanding a strict JSON object.
  - `Prompt_Builder::enhanced_user( array $meta ): string` — content prompt built from trial facts.
  - `Prompt_Builder::parse_enhanced( string $raw ): array` — returns `array{ study_purpose:string, who_can_join:string, doctor_questions:string[] }`; on any parse failure returns all-empty (`''`, `''`, `array()`). `study_purpose` and `who_can_join` are returned as HTML-safe strings (who_can_join may contain `<ul><li>` items); `doctor_questions` is a plain string array.

- [ ] **Step 1: Write the failing tests**

Add to `tests/unit/PromptBuilderTest.php`:

```php
public function test_enhanced_system_forbids_advice_and_requires_json(): void {
	$s = strtolower( Prompt_Builder::enhanced_system() );
	$this->assertStringContainsString( 'json', $s );
	$this->assertStringContainsString( 'medical advice', $s );
}

public function test_enhanced_user_includes_criteria(): void {
	$u = Prompt_Builder::enhanced_user( array(
		'brief_title'   => 'A Trial',
		'conditions'    => array( 'Pompe Disease' ),
		'brief_summary' => 'Studying X.',
		'eligibility'   => array( 'sex' => 'ALL', 'min_age' => '18 Years', 'max_age' => '', 'criteria' => 'Inclusion: adults.' ),
	) );
	$this->assertStringContainsString( 'Inclusion: adults.', $u );
	$this->assertStringContainsString( 'Pompe Disease', $u );
}

public function test_parse_enhanced_reads_valid_json(): void {
	$raw = '{"study_purpose":"Tests a registry.","who_can_join":["Adults 18+","Has Pompe disease"],"doctor_questions":["Am I eligible?","What is involved?"]}';
	$out = Prompt_Builder::parse_enhanced( $raw );
	$this->assertSame( 'Tests a registry.', $out['study_purpose'] );
	$this->assertStringContainsString( 'Adults 18+', $out['who_can_join'] );
	$this->assertSame( array( 'Am I eligible?', 'What is involved?' ), $out['doctor_questions'] );
}

public function test_parse_enhanced_handles_garbage(): void {
	$out = Prompt_Builder::parse_enhanced( 'not json at all' );
	$this->assertSame( '', $out['study_purpose'] );
	$this->assertSame( '', $out['who_can_join'] );
	$this->assertSame( array(), $out['doctor_questions'] );
}

public function test_parse_enhanced_tolerates_code_fence(): void {
	$raw = "```json\n{\"study_purpose\":\"P\",\"who_can_join\":[\"A\"],\"doctor_questions\":[\"Q\"]}\n```";
	$out = Prompt_Builder::parse_enhanced( $raw );
	$this->assertSame( 'P', $out['study_purpose'] );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test:unit -- --filter PromptBuilderTest`
Expected: FAIL — `enhanced_system` / `enhanced_user` / `parse_enhanced` not defined.

- [ ] **Step 3: Implement the three methods**

Add to `includes/llm/class-prompt-builder.php`:

```php
	/**
	 * System prompt for the combined patient-facing enhancement fields.
	 *
	 * @return string
	 */
	public static function enhanced_system(): string {
		return __(
			'You help patients and families understand a clinical trial. Use about an 8th-grade reading level. Be factual and use ONLY the information provided. Do not give medical advice, do not speculate, and do not invent details. Respond with ONLY a JSON object with exactly these keys: "study_purpose" (one plain sentence describing what the study is testing), "who_can_join" (an array of short plain-language bullet strings summarising who is eligible, simplified from the criteria), and "doctor_questions" (an array of 3 to 4 short, general, non-advisory questions a person could ask their own doctor about this trial). Output JSON only, no prose, no code fences.',
			'kisho-clinical-trials'
		);
	}

	/**
	 * Build the content prompt for the combined enhancement fields.
	 *
	 * @param array $meta Associative array of trial meta values.
	 * @return string
	 */
	public static function enhanced_user( array $meta ): string {
		$elig  = is_array( $meta['eligibility'] ?? null ) ? $meta['eligibility'] : array();
		$lines = array();
		/* translators: %s: the trial's brief title. */
		$lines[] = sprintf( __( 'Title: %s', 'kisho-clinical-trials' ), $meta['brief_title'] ?? '' );
		$lines[] = sprintf(
			/* translators: %s: comma-separated list of conditions. */
			__( 'Conditions: %s', 'kisho-clinical-trials' ),
			implode( ', ', (array) ( $meta['conditions'] ?? array() ) )
		);
		$lines[] = sprintf(
			/* translators: 1: eligible sex, 2: minimum age, 3: maximum age. */
			__( 'Who can join: sex %1$s, ages %2$s to %3$s', 'kisho-clinical-trials' ),
			$elig['sex'] ?? '',
			$elig['min_age'] ?? '',
			$elig['max_age'] ?? ''
		);
		$lines[] = sprintf(
			/* translators: %s: the official inclusion/exclusion criteria text. */
			__( 'Eligibility criteria: %s', 'kisho-clinical-trials' ),
			$elig['criteria'] ?? ''
		);
		$lines[] = sprintf(
			/* translators: %s: the official brief summary text. */
			__( 'Official description: %s', 'kisho-clinical-trials' ),
			$meta['brief_summary'] ?? ''
		);
		return implode( "\n", $lines );
	}

	/**
	 * Parse the combined-fields LLM response into the three stored values.
	 *
	 * Tolerant of surrounding prose or code fences: extracts the first {...}
	 * block and JSON-decodes it. On any failure every value is empty so the
	 * caller stores nothing and the template sections self-hide.
	 *
	 * @param string $raw Raw LLM output.
	 * @return array{study_purpose:string,who_can_join:string,doctor_questions:string[]}
	 */
	public static function parse_enhanced( string $raw ): array {
		$empty = array(
			'study_purpose'    => '',
			'who_can_join'     => '',
			'doctor_questions' => array(),
		);

		$start = strpos( $raw, '{' );
		$end   = strrpos( $raw, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return $empty;
		}
		$json = substr( $raw, $start, $end - $start + 1 );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return $empty;
		}

		$purpose = isset( $data['study_purpose'] ) ? trim( (string) $data['study_purpose'] ) : '';

		$join_items = array();
		foreach ( (array) ( $data['who_can_join'] ?? array() ) as $item ) {
			$item = trim( (string) $item );
			if ( '' !== $item ) {
				$join_items[] = $item;
			}
		}
		// Store who_can_join as a small HTML list so the template can render it
		// directly through wp_kses_post; items themselves are plain text.
		$join_html = '';
		if ( $join_items ) {
			$join_html = '<ul>';
			foreach ( $join_items as $li ) {
				$join_html .= '<li>' . $li . '</li>';
			}
			$join_html .= '</ul>';
		}

		$questions = array();
		foreach ( (array) ( $data['doctor_questions'] ?? array() ) as $q ) {
			$q = trim( (string) $q );
			if ( '' !== $q ) {
				$questions[] = $q;
			}
		}

		return array(
			'study_purpose'    => $purpose,
			'who_can_join'     => $join_html,
			'doctor_questions' => $questions,
		);
	}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test:unit -- --filter PromptBuilderTest`
Expected: PASS (all, including pre-existing tests)

- [ ] **Step 5: PHPCS**

Run: `vendor/bin/phpcs includes/llm/class-prompt-builder.php` (then `vendor/bin/phpcbf` if needed)
Expected: clean

- [ ] **Step 6: Commit**

```bash
git add includes/llm/class-prompt-builder.php tests/unit/PromptBuilderTest.php
git commit -m "feat: combined patient-fields prompt + JSON parser in Prompt_Builder"
```

---

### Task 3: Generate + cache the new fields in Summary_Service

**Files:**
- Modify: `includes/llm/class-summary-service.php`
- Test: `tests/integration/SummaryServiceTest.php` (integration — runs in LocalWP)

**Interfaces:**
- Consumes: `Prompt_Builder::enhanced_system()`, `Prompt_Builder::enhanced_user()`, `Prompt_Builder::parse_enhanced()`; `Llm_Provider::generate_summary()`; `Trial_Meta::KEYS` (new keys from Task 1).
- Produces: `Summary_Service::maybe_generate_enhanced( int $post_id, array $meta ): bool` — generates the combined fields in ONE provider call when stale, stores each non-empty value to its meta + `*_source_date`, returns true when written. Called from `maybe_generate()`'s success path so one sync pass produces summary + enhancements.

- [ ] **Step 1: Write the failing integration test**

Add to `tests/integration/SummaryServiceTest.php` a test using the existing fake-provider pattern in that file (mirror how the summary test injects a provider returning a fixed string). The fake provider returns valid JSON; assert the three metas are written:

```php
public function test_maybe_generate_enhanced_writes_three_fields(): void {
	$post_id = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
	$provider = new class() implements \SKMCTF\LLM\Llm_Provider {
		public function generate_summary( string $system, string $user, array $opts = array() ) {
			return '{"study_purpose":"Tests a registry.","who_can_join":["Adults 18+"],"doctor_questions":["Am I eligible?","What is involved?"]}';
		}
		public function id(): string { return 'fake'; }
	};
	$svc = new \SKMCTF\LLM\Summary_Service( $provider, new \SKMCTF\Support\Logger() );

	$meta = array( 'ct_last_update' => '2026-03-10', 'brief_title' => 'T', 'conditions' => array(), 'eligibility' => array() );
	$wrote = $svc->maybe_generate_enhanced( $post_id, $meta );

	$this->assertTrue( $wrote );
	$this->assertSame( 'Tests a registry.', get_post_meta( $post_id, 'skmctf_study_purpose', true ) );
	$this->assertStringContainsString( 'Adults 18+', get_post_meta( $post_id, 'skmctf_who_can_join', true ) );
	$this->assertSame( array( 'Am I eligible?', 'What is involved?' ), get_post_meta( $post_id, 'skmctf_doctor_questions', true ) );
	$this->assertSame( '2026-03-10', get_post_meta( $post_id, 'skmctf_study_purpose_source_date', true ) );
}

public function test_maybe_generate_enhanced_is_cached(): void {
	$post_id = self::factory()->post->create( array( 'post_type' => 'skmctf_trial' ) );
	update_post_meta( $post_id, 'skmctf_study_purpose', 'Existing.' );
	update_post_meta( $post_id, 'skmctf_study_purpose_source_date', '2026-03-10' );
	$provider = new class() implements \SKMCTF\LLM\Llm_Provider {
		public function generate_summary( string $system, string $user, array $opts = array() ) {
			throw new \RuntimeException( 'should not be called' );
		}
		public function id(): string { return 'fake'; }
	};
	$svc = new \SKMCTF\LLM\Summary_Service( $provider, new \SKMCTF\Support\Logger() );
	$this->assertFalse( $svc->maybe_generate_enhanced( $post_id, array( 'ct_last_update' => '2026-03-10' ) ) );
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `composer test:integration -- --filter SummaryServiceTest` (in LocalWP shell with `WP_TESTS_DIR` set)
Expected: FAIL — `maybe_generate_enhanced` not defined. (If the WP test suite is not installed, this errors out — install it first; do NOT treat a missing suite as a pass.)

- [ ] **Step 3: Implement `maybe_generate_enhanced` and call it**

Add the method to `includes/llm/class-summary-service.php`:

```php
	/**
	 * Maybe generate + cache the combined patient-facing fields (study purpose,
	 * who-can-join, doctor questions) in one provider call.
	 *
	 * Cache key is study_purpose_source_date vs ct_last_update, mirroring the
	 * summary cache. Each non-empty parsed value is stored to its meta.
	 *
	 * @param int   $post_id WordPress post ID.
	 * @param array $meta    Trial meta array (must include 'ct_last_update').
	 * @return bool True when fields were (re)written.
	 */
	public function maybe_generate_enhanced( int $post_id, array $meta ): bool {
		if ( null === $this->provider ) {
			return false;
		}

		$new_date = (string) ( $meta['ct_last_update'] ?? '' );
		$existing = (string) get_post_meta( $post_id, Trial_Meta::KEYS['study_purpose'], true );
		$src_date = (string) get_post_meta( $post_id, Trial_Meta::KEYS['study_purpose_source_date'], true );

		if ( '' !== $existing && '' !== $new_date && $src_date === $new_date ) {
			return false;
		}

		$raw = $this->provider->generate_summary(
			Prompt_Builder::enhanced_system(),
			Prompt_Builder::enhanced_user( $meta )
		);

		if ( is_wp_error( $raw ) ) {
			$this->log->error(
				'Enhanced field generation failed: ' . $raw->get_error_message(),
				array( 'post' => $post_id )
			);
			return false;
		}

		$parsed = Prompt_Builder::parse_enhanced( (string) $raw );

		update_post_meta( $post_id, Trial_Meta::KEYS['study_purpose'], wp_kses_post( $parsed['study_purpose'] ) );
		update_post_meta( $post_id, Trial_Meta::KEYS['who_can_join'], wp_kses_post( $parsed['who_can_join'] ) );
		update_post_meta( $post_id, Trial_Meta::KEYS['doctor_questions'], $parsed['doctor_questions'] );

		update_post_meta( $post_id, Trial_Meta::KEYS['study_purpose_source_date'], $new_date );
		update_post_meta( $post_id, Trial_Meta::KEYS['who_can_join_source_date'], $new_date );
		update_post_meta( $post_id, Trial_Meta::KEYS['doctor_questions_source_date'], $new_date );

		return true;
	}
```

Then call it from the existing `maybe_generate()` just before `return true;` (so a single sync produces both summary and enhancements):

```php
		$this->maybe_generate_enhanced( $post_id, $meta );

		return true;
```

- [ ] **Step 4: Run to verify it passes**

Run: `composer test:integration -- --filter SummaryServiceTest`
Expected: PASS
Run: `composer test:unit` (no unit regressions) — Expected: PASS

- [ ] **Step 5: PHPCS**

Run: `vendor/bin/phpcs includes/llm/class-summary-service.php` (then `phpcbf` if needed)
Expected: clean

- [ ] **Step 6: Commit**

```bash
git add includes/llm/class-summary-service.php tests/integration/SummaryServiceTest.php
git commit -m "feat: generate and cache patient-facing LLM fields during sync"
```

---

### Task 4: Fix single-page wiring (Single_Renderer becomes live) + load new meta

**Files:**
- Modify: `includes/frontend/class-single-renderer.php`
- Modify: `templates/single-skmctf_trial.php`
- Test: manual browser check (template path needs WP + theme; no unit harness for template include)

**Interfaces:**
- Consumes: `Single_Renderer::get_meta()` (extend to include the three new keys), `Trial_Meta::KEYS`.
- Produces: `Single_Renderer::view_data( int $post_id ): array` — returns the full associative array the template needs (existing derived vars + `study_purpose`, `who_can_join`, `doctor_questions`). The template calls this and `extract()`s it, so it is self-sufficient on the `single_template` filter path.

- [ ] **Step 1: Add `view_data()` and extend `get_meta()`**

In `includes/frontend/class-single-renderer.php`, add to the `get_meta()` return array:

```php
			'study_purpose'    => get_post_meta( $post_id, $keys['study_purpose'], true ),
			'who_can_join'     => get_post_meta( $post_id, $keys['who_can_join'], true ),
			'doctor_questions' => get_post_meta( $post_id, $keys['doctor_questions'], true ),
```

Add a new public method that returns the derived view variables (refactor the derivation currently inside `render()` into here so both `render()` and the template can use it):

```php
	/**
	 * Build the full set of view variables for the single-trial template.
	 *
	 * @param int $post_id WP post ID.
	 * @return array<string,mixed>
	 */
	public static function view_data( int $post_id ): array {
		$meta = self::get_meta( $post_id );

		$overall_status = ! empty( $meta['overall_status'] ) ? $meta['overall_status'] : '';

		return array(
			'post_id'          => $post_id,
			'meta'             => $meta,
			'overall_status'   => $overall_status,
			'status_label'     => ucwords( strtolower( str_replace( '_', ' ', $overall_status ) ) ),
			'status_slug'      => sanitize_html_class( strtolower( str_replace( '_', '-', $overall_status ) ) ),
			'official_title'   => ! empty( $meta['official_title'] ) ? $meta['official_title'] : '',
			'phase'            => ! empty( $meta['phase'] ) ? $meta['phase'] : '',
			'conditions'       => ! empty( $meta['conditions'] ) && is_array( $meta['conditions'] ) ? $meta['conditions'] : array(),
			'sponsor'          => ! empty( $meta['lead_sponsor'] ) ? $meta['lead_sponsor'] : '',
			'plain_summary'    => ! empty( $meta['plain_summary'] ) ? $meta['plain_summary'] : '',
			'brief_summary'    => ! empty( $meta['brief_summary'] ) ? $meta['brief_summary'] : '',
			'eligibility'      => ! empty( $meta['eligibility'] ) && is_array( $meta['eligibility'] ) ? $meta['eligibility'] : array(),
			'locations'        => ! empty( $meta['locations'] ) && is_array( $meta['locations'] ) ? $meta['locations'] : array(),
			'ct_url'           => ! empty( $meta['ct_url'] ) ? $meta['ct_url'] : '',
			'show_map'         => Settings::show_map(),
			'study_purpose'    => ! empty( $meta['study_purpose'] ) ? $meta['study_purpose'] : '',
			'who_can_join'     => ! empty( $meta['who_can_join'] ) ? $meta['who_can_join'] : '',
			'doctor_questions' => ! empty( $meta['doctor_questions'] ) && is_array( $meta['doctor_questions'] ) ? $meta['doctor_questions'] : array(),
		);
	}
```

Update the existing `render()` to use `view_data()` (replace its inline derivation + `compact()` with `extract( self::view_data( $post_id ) )`) so there is one source of truth.

- [ ] **Step 2: Make the template self-populate**

In `templates/single-skmctf_trial.php`, immediately after `the_post();` (inside the loop, before the `<article>`), add:

```php
			// Populate view variables directly from the post so the template works
			// on WordPress's single_template path (no external renderer call).
			// phpcs:disable WordPress.PHP.DontExtract.extract_extract
			extract( \SKMCTF\Frontend\Single_Renderer::view_data( get_the_ID() ) );
			// phpcs:enable
```

- [ ] **Step 3: Manual browser verification**

Run the app / open `https://plugin-sandbox.local/clinical-trials/nct00231400/` (cache-bust with `?v=N`).
Expected: page now shows status badge, conditions, sponsor, plain-language summary, eligibility, and locations — not just the title.

- [ ] **Step 4: PHPCS**

Run: `vendor/bin/phpcs includes/frontend/class-single-renderer.php templates/single-skmctf_trial.php` (then `phpcbf` if needed)
Expected: clean
Run: `composer test:unit` — Expected: PASS (no regressions)

- [ ] **Step 5: Commit**

```bash
git add includes/frontend/class-single-renderer.php templates/single-skmctf_trial.php
git commit -m "fix: render single trial page via self-populating template (Single_Renderer live)"
```

---

### Task 5: New template parts — study purpose, who-can-join, questions

**Files:**
- Create: `templates/parts/study-purpose.php`
- Create: `templates/parts/who-can-join.php`
- Create: `templates/parts/questions.php`
- Modify: `templates/single-skmctf_trial.php` (include the parts in patient-first order)
- Test: manual browser check

**Interfaces:**
- Consumes: template-scope vars from Task 4 (`$study_purpose` string, `$who_can_join` HTML string, `$doctor_questions` string[]).
- Produces: three self-guarding partials that render nothing when their variable is empty.

- [ ] **Step 1: Create `templates/parts/study-purpose.php`**

```php
<?php
/**
 * "What this study is testing" partial.
 *
 * Expected: string $study_purpose
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variable injected by caller.
defined( 'ABSPATH' ) || exit;

if ( empty( $study_purpose ) ) {
	return;
}
?>
<p class="skmctf-trial__purpose">
	<span class="skmctf-trial__label"><?php esc_html_e( 'What this study is testing:', 'kisho-clinical-trials' ); ?></span>
	<?php echo esc_html( $study_purpose ); ?>
</p>
```

- [ ] **Step 2: Create `templates/parts/who-can-join.php`**

```php
<?php
/**
 * "Who can join" plain-language partial.
 *
 * Expected: string $who_can_join (small HTML <ul> built server-side, kses-safe).
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variable injected by caller.
defined( 'ABSPATH' ) || exit;

if ( empty( $who_can_join ) ) {
	return;
}
?>
<section class="skmctf-trial__who-can-join" aria-labelledby="skmctf-who-heading">
	<h2 class="skmctf-trial__section-heading" id="skmctf-who-heading">
		<?php esc_html_e( 'Who can join', 'kisho-clinical-trials' ); ?>
	</h2>
	<div class="skmctf-trial__who-content">
		<?php echo wp_kses_post( $who_can_join ); ?>
	</div>
	<p class="skmctf-trial__who-note">
		<?php esc_html_e( 'This is a simplified summary of the official criteria — confirm with the study team before making decisions.', 'kisho-clinical-trials' ); ?>
	</p>
</section>
```

- [ ] **Step 3: Create `templates/parts/questions.php`**

```php
<?php
/**
 * "Questions to ask your doctor" partial.
 *
 * Expected: array $doctor_questions (list of plain strings).
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variable injected by caller.
defined( 'ABSPATH' ) || exit;

if ( empty( $doctor_questions ) || ! is_array( $doctor_questions ) ) {
	return;
}
?>
<section class="skmctf-trial__questions" aria-labelledby="skmctf-questions-heading">
	<h2 class="skmctf-trial__section-heading" id="skmctf-questions-heading">
		<?php esc_html_e( 'Questions to ask your doctor', 'kisho-clinical-trials' ); ?>
	</h2>
	<ul class="skmctf-trial__questions-list">
		<?php foreach ( $doctor_questions as $question ) : ?>
			<li><?php echo esc_html( $question ); ?></li>
		<?php endforeach; ?>
	</ul>
</section>
```

- [ ] **Step 4: Wire the parts into the template in patient-first order**

In `templates/single-skmctf_trial.php`:

(a) After the `</header>` and before the existing `$phase` paragraph, include the purpose part:

```php
			<?php
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/study-purpose.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- part missing; skip gracefully
				// Part missing — skip gracefully.
			}
			?>
```

(b) After the summary section block (the `endif;` that closes plain/brief summary) and BEFORE the eligibility include, add the who-can-join part:

```php
			<?php
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/who-can-join.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- part missing; skip gracefully
				// Part missing — skip gracefully.
			}
			?>
```

(c) After the locations include and BEFORE the `$ct_url` block, add the questions part:

```php
			<?php
			try {
				include \SKMCTF\Support\Template_Loader::locate( 'parts/questions.php' );
			} catch ( \RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- part missing; skip gracefully
				// Part missing — skip gracefully.
			}
			?>
```

- [ ] **Step 5: Manual browser verification**

Open a trial with the new meta populated (after a sync with the LLM enabled, or set the meta manually for testing). Expected: purpose line under the header, "Who can join" section after the summary, "Questions to ask your doctor" after locations. With LLM disabled / empty meta, none of the three sections render.

- [ ] **Step 6: PHPCS**

Run: `vendor/bin/phpcs templates/parts/study-purpose.php templates/parts/who-can-join.php templates/parts/questions.php templates/single-skmctf_trial.php` (then `phpcbf` if needed)
Expected: clean

- [ ] **Step 7: Commit**

```bash
git add templates/parts/study-purpose.php templates/parts/who-can-join.php templates/parts/questions.php templates/single-skmctf_trial.php
git commit -m "feat: patient-facing single-trial sections (purpose, who-can-join, questions)"
```

---

### Task 6: Locations — collapse-to-N + "show all", and fix map transport

**Files:**
- Modify: `templates/parts/locations.php`
- Modify: `assets/css/frontend.css` (collapse styling)
- Test: manual browser check

**Interfaces:**
- Consumes: `$locations` (array), `$show_map` (bool); `Assets::enqueue_map()`, `Assets::MAP_SCRIPT_HANDLE`.
- Produces: a map that uses the per-instance `<script type="application/json">` transport (same as the list view fix) instead of the global `wp_localize_script( 'skmctfMap' )`, and a text list collapsed to the first 10 with a "Show all N locations" toggle.

**Why the map change:** `parts/locations.php` currently uses the OLD `wp_localize_script` + global `window.skmctfMap` pattern, which (a) collides if the archive map is also on the page and (b) corrupts JSON for facility names containing quotes — the exact bug already fixed in the list view. `assets/js/map.js` already reads the per-instance `<script>` payload, so this aligns the single page with it.

- [ ] **Step 1: Replace the map payload emission with the per-instance script transport**

In `templates/parts/locations.php`, replace the `wp_localize_script( ... 'skmctfMap' ... )` block and the `.skmctf-map` container with the per-instance pattern. Build a unique id, emit short data-* attrs + a JSON `<script>`, and store raw titles (no `esc_html` on the title — map.js inserts via `textContent`):

```php
	if ( ! empty( $map_points ) ) {
		\SKMCTF\Frontend\Assets::enqueue_map();
		$skmctf_map_uid  = wp_unique_id( 'skmctf-map-' );
		$osm_attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
		$skmctf_payload  = array(
			'points' => $map_points,
			'view'   => array( 'lat' => '', 'lng' => '', 'zoom' => '' ),
			'i18n'   => array(),
		);
	}
```

Change the `$map_points[]` build above it to store the raw label (drop `esc_html`):

```php
			$map_points[] = array(
				'lat'   => $lat,
				'lng'   => $lng,
				'title' => $label,
			);
```

And replace the container markup with:

```php
	<?php if ( $show_map && ! empty( $map_points ) ) : ?>
	<div class="skmctf-map skmctf-trial__map"
		role="region"
		aria-label="<?php esc_attr_e( 'Trial locations map', 'kisho-clinical-trials' ); ?>"
		data-skmctf-map
		data-skmctf-data="<?php echo esc_attr( $skmctf_map_uid ); ?>"
		data-skmctf-image-path="<?php echo esc_attr( SKMCTF_URL . 'assets/lib/leaflet/images/' ); ?>"
		data-skmctf-attribution="<?php echo esc_attr( $osm_attribution ); ?>"
		data-skmctf-geolocation="0"></div>
	<script type="application/json" class="skmctf-map-data" id="<?php echo esc_attr( $skmctf_map_uid ); ?>"><?php echo wp_json_encode( $skmctf_payload, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a JSON script element; HEX flags neutralise markup. ?></script>
	<?php endif; ?>
```

- [ ] **Step 2: Collapse the text list to the first 10 with a toggle**

Wrap the list so items past the 10th get a `hidden` attribute, and add a toggle button when there are more than 10:

```php
	<?php
	$skmctf_total   = count( $locations );
	$skmctf_visible = 10;
	$skmctf_index   = 0;
	?>
	<ul class="skmctf-trial__locations-list" data-skmctf-loclist>
		<?php
		foreach ( $locations as $loc ) :
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$facility   = ! empty( $loc['facility'] ) ? $loc['facility'] : '';
			$city       = ! empty( $loc['city'] ) ? $loc['city'] : '';
			$state      = ! empty( $loc['state'] ) ? $loc['state'] : '';
			$country    = ! empty( $loc['country'] ) ? $loc['country'] : '';
			$loc_status = ! empty( $loc['status'] ) ? $loc['status'] : '';
			$address_parts = array_filter( array( $city, $state, $country ) );
			if ( ! $facility && ! $address_parts ) {
				continue;
			}
			$hidden = $skmctf_index >= $skmctf_visible ? ' hidden' : '';
			++$skmctf_index;
			?>
		<li class="skmctf-trial__location"<?php echo esc_attr( $hidden ) ? ' hidden' : ''; ?>>
			<?php if ( $facility ) : ?>
				<span class="skmctf-trial__location-facility"><?php echo esc_html( $facility ); ?></span>
			<?php endif; ?>
			<?php if ( $address_parts ) : ?>
				<span class="skmctf-trial__location-address"><?php echo esc_html( implode( ', ', $address_parts ) ); ?></span>
			<?php endif; ?>
			<?php if ( $loc_status ) : ?>
				<span class="skmctf-trial__location-status"><?php echo esc_html( $loc_status ); ?></span>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $skmctf_index > $skmctf_visible ) : ?>
		<button type="button" class="skmctf-trial__locations-toggle" data-skmctf-loctoggle aria-expanded="false">
			<?php
			/* translators: %d: total number of locations. */
			printf( esc_html__( 'Show all %d locations', 'kisho-clinical-trials' ), (int) $skmctf_index );
			?>
		</button>
	<?php endif; ?>
```

Note: replace the existing `<ul>...</ul>` block entirely with the above; the `<li>` `hidden` attribute is set inline (the `echo esc_attr( $hidden )` ternary keeps PHPCS happy — alternatively output a class and toggle via JS).

- [ ] **Step 3: Add the toggle behavior to filters.js (or a tiny inline handler)**

Append to `assets/js/filters.js` (inside its IIFE `boot()` or a new boot) a handler that, on toggle click, unhides the hidden `<li>`s and hides the button:

```js
	// Single-trial "show all locations" toggle.
	var locToggles = document.querySelectorAll( '[data-skmctf-loctoggle]' );
	for ( var t = 0; t < locToggles.length; t++ ) {
		locToggles[ t ].addEventListener( 'click', function () {
			var wrap = this.previousElementSibling; // the <ul data-skmctf-loclist>
			if ( wrap ) {
				var hiddenItems = wrap.querySelectorAll( 'li[hidden]' );
				for ( var i = 0; i < hiddenItems.length; i++ ) {
					hiddenItems[ i ].removeAttribute( 'hidden' );
				}
			}
			this.setAttribute( 'aria-expanded', 'true' );
			this.hidden = true;
		} );
	}
```

(Filters.js is already enqueued site-wide by `Assets::enqueue()`. Confirm it is enqueued on single pages — `Single_Renderer`/template path: the template does not call `Assets::enqueue()`, so add `\SKMCTF\Frontend\Assets::enqueue();` once near the top of `parts/locations.php` before output, guarded so it only runs when the list renders.)

- [ ] **Step 4: Add minimal CSS**

In `assets/css/frontend.css`, append:

```css
.skmctf-trial__locations-toggle { margin-top: .5rem; cursor: pointer; }
.skmctf-trial__location[hidden] { display: none; }
```

- [ ] **Step 5: Manual browser verification**

Open NCT00231400 (270+ sites). Expected: map renders all pins via the per-instance script payload (no console JSON.parse error), text list shows 10 with a "Show all 273 locations" button; clicking reveals the rest. Verify a facility name containing a quote does not break the map (`JSON.parse` succeeds).

- [ ] **Step 6: Lint + PHPCS**

Run: `node --check assets/js/filters.js`
Run: `vendor/bin/phpcs templates/parts/locations.php` (then `phpcbf` if needed)
Expected: clean

- [ ] **Step 7: Commit**

```bash
git add templates/parts/locations.php assets/js/filters.js assets/css/frontend.css
git commit -m "feat: single-page locations collapse + per-instance map transport"
```

---

### Task 7: i18n, readme, version, final verification

**Files:**
- Modify: `languages/kisho-clinical-trials.pot`
- Modify: `readme.txt`
- Modify: `kisho-clinical-trials.php` (version)

**Interfaces:** none (housekeeping/release).

- [ ] **Step 1: Regenerate the .pot**

Run (LocalWP shell or with wp-cli phar): `wp i18n make-pot . languages/kisho-clinical-trials.pot --domain=kisho-clinical-trials --slug=kisho-clinical-trials`
Expected: Success; new strings present ("What this study is testing:", "Who can join", "Questions to ask your doctor", "Show all %d locations", the who-can-join note). Confirm `Project-Id-Version` reflects the new version after Step 3.

- [ ] **Step 2: Add readme changelog + upgrade note**

In `readme.txt`, add a new `= 1.1.2 =` Changelog block and matching Upgrade Notice:

```
= 1.1.2 =
* Fixed: Single trial pages now render the full detail (status, conditions, sponsor, summary, eligibility, locations, map) — previously they showed only the title due to a rendering wiring gap.
* New: Patient-friendly LLM fields on single trial pages — a one-line "What this study is testing", a plain-language "Who can join", and "Questions to ask your doctor". Generated during sync (cached); run "Sync now" to populate them for existing trials. Requires an LLM API key; pages without it show the standard detail.
* Improved: On trials with many sites, the locations list shows the first 10 with a "Show all" toggle; the single-page map now uses the same robust per-instance data transport as the archive.
```

- [ ] **Step 3: Bump version**

In `kisho-clinical-trials.php` change `Version: 1.1.1` → `1.1.2` and `define( 'SKMCTF_VERSION', '1.1.1' )` → `'1.1.2'`. In `readme.txt` change `Stable tag: 1.1.1` → `1.1.2`. Then re-run Step 1 so the .pot header shows 1.1.2.

- [ ] **Step 4: Full verification suite**

Run: `composer test:unit` — Expected: `OK`
Run: `composer phpcs` — Expected: clean (exit 0)
Run: `composer validate --no-check-all --strict` — Expected: valid
Run: `composer test:integration` (LocalWP) — Expected: PASS (or documented as not-run if suite unavailable; do NOT treat missing suite as pass)
Manual: NCT00231400 single page shows all sections; with LLM-populated meta the three new sections appear; with empty meta they are absent.

- [ ] **Step 5: Commit**

```bash
git add languages/kisho-clinical-trials.pot readme.txt kisho-clinical-trials.php
git commit -m "chore: i18n, changelog, version 1.1.2 for single-trial page"
```

---

## Self-Review

**Spec coverage:**
- §1 wiring fix → Task 4 ✓
- §2 page structure / order → Task 5 (parts + ordering) ✓; at-a-glance restructure is light (existing phase/conditions/sponsor remain; optional CSS-only grouping not required for correctness) — covered as existing fields rendered by Task 4. ✓
- §2 locations collapse + map → Task 6 ✓
- §3 three LLM fields, one combined call, cached, disclaimer/safety → Tasks 1–3 ✓
- §3 settings gate (LLM disabled → clean page) → inherited via `provider === null` guard in Summary_Service (Task 3) + self-guarding parts (Task 5) ✓
- §4 SEO unchanged → no task touches `class-seo.php` (intentional) ✓
- §4 data flow → Tasks 3–4 ✓; backfill via "Sync now" → readme note Task 7 ✓
- §4 testing (unit prompt/parser, integration service, manual) → Tasks 2, 3, 4–6 ✓

**Placeholder scan:** no TBD/TODO; all code shown. ✓

**Type consistency:** `view_data()` keys match the template's extracted vars and Task 5 part expectations; `parse_enhanced()` returns `{study_purpose:string, who_can_join:string(HTML), doctor_questions:string[]}` consumed unchanged by Task 3 storage and Task 5 parts; meta short-keys identical across Tasks 1/3/4. `maybe_generate_enhanced` signature consistent between Task 3 definition and its `maybe_generate()` call site. ✓

**Note on §2 "at-a-glance" row:** kept as the existing stacked phase/conditions/sponsor fields (already rendered by the wiring fix). A purely cosmetic CSS regrouping into one row is optional polish, not a separate task — flagged here so the implementer doesn't treat its absence as a gap.
