<?php
/**
 * Settings facade — typed getter layer over the skmctf_settings option.
 *
 * All reads go through self::all() which merges stored data with defaults.
 * The sanitize() method is used as the option's sanitize_callback; it is
 * pure-ish (receives $existing) and handles the write-only API-key contract:
 *   - blank submit  → preserve existing key
 *   - non-blank     → replace with sanitized value
 *   - clear_api_key → wipe to empty string (highest precedence)
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Admin;

final class Settings {

	/** @var string WordPress option name. */
	public const OPTION = 'skmctf_settings';

	/** @var string[] Allowed trial status values from ClinicalTrials.gov. */
	public const VALID_STATUSES = [
		'RECRUITING',
		'NOT_YET_RECRUITING',
		'ENROLLING_BY_INVITATION',
		'ACTIVE_NOT_RECRUITING',
		'COMPLETED',
		'SUSPENDED',
		'TERMINATED',
		'WITHDRAWN',
		'UNKNOWN',
	];

	/** @var string[] Supported LLM providers. */
	public const PROVIDERS = [ 'anthropic', 'openai' ];

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Return the full set of option defaults.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return [
			'conditions'             => [],
			'include_ncts'           => [],
			'exclude_ncts'           => [],
			'statuses'               => [ 'RECRUITING' ],
			'reconcile_mode'         => 'mark_closed',
			'summaries_enabled'      => false,
			'provider'               => 'anthropic',
			'api_key'                => '',
			'model'                  => '',
			'show_map'               => false,
			'single_pages'           => true,
			'index_singles_override' => false,
			'display_fields'         => [ 'status', 'phase', 'conditions', 'sponsor', 'locations', 'summary' ],
			'attribution'            => false,
		];
	}

	// -------------------------------------------------------------------------
	// Read facade
	// -------------------------------------------------------------------------

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$o = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $o ) ? $o : [] );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback when key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$a = self::all();
		return $a[ $key ] ?? $default;
	}

	/** @return string[] */
	public static function conditions(): array {
		return (array) self::get( 'conditions', [] );
	}

	/** @return string[] */
	public static function statuses(): array {
		$s = (array) self::get( 'statuses', [ 'RECRUITING' ] );
		return $s ?: [ 'RECRUITING' ];
	}

	/** @return string[] */
	public static function include_ncts(): array {
		return (array) self::get( 'include_ncts', [] );
	}

	/** @return string[] */
	public static function exclude_ncts(): array {
		return (array) self::get( 'exclude_ncts', [] );
	}

	/** @return string 'mark_closed'|'remove' */
	public static function reconcile_mode(): string {
		return self::get( 'reconcile_mode' ) === 'remove' ? 'remove' : 'mark_closed';
	}

	/** @return bool */
	public static function summaries_enabled(): bool {
		return (bool) self::get( 'summaries_enabled', false );
	}

	/** @return string 'anthropic'|'openai' */
	public static function provider(): string {
		$p = (string) self::get( 'provider', 'anthropic' );
		return in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic';
	}

	/**
	 * Read-only access to the stored LLM API key.
	 * The key must never be echoed to the browser; this getter is the only
	 * sanctioned reader.
	 *
	 * @return string
	 */
	public static function get_api_key(): string {
		return (string) self::get( 'api_key', '' );
	}

	/** @return string */
	public static function model(): string {
		return (string) self::get( 'model', '' );
	}

	/** @return bool */
	public static function show_map(): bool {
		return (bool) self::get( 'show_map', false );
	}

	/** @return bool */
	public static function single_pages_enabled(): bool {
		return (bool) self::get( 'single_pages', true );
	}

	/** @return bool */
	public static function index_singles_override(): bool {
		return (bool) self::get( 'index_singles_override', false );
	}

	/** @return string[] */
	public static function display_fields(): array {
		return (array) self::get( 'display_fields', [] );
	}

	/** @return bool */
	public static function attribution_enabled(): bool {
		return (bool) self::get( 'attribution', false );
	}

	// -------------------------------------------------------------------------
	// Sanitizer
	// -------------------------------------------------------------------------

	/**
	 * Parse a newline-separated list of NCT IDs, validate format, deduplicate.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	private static function parse_ncts( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$nct = strtoupper( trim( $line ) );
			if ( preg_match( '/^NCT\d{8}$/', $nct ) ) {
				$out[] = $nct;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Sanitize raw form input for the skmctf_settings option.
	 *
	 * Designed to be used as register_setting() sanitize_callback, with the
	 * existing stored value passed in separately so tests can stay pure.
	 *
	 * API-key write-only contract:
	 *   - blank submit (`api_key` empty / whitespace) → PRESERVE existing key.
	 *   - non-empty submit                            → REPLACE with new sanitized value.
	 *   - `clear_api_key` flag set                   → WIPE to empty string (highest precedence).
	 *
	 * @param array<string,mixed> $input    Raw $_POST data.
	 * @param array<string,mixed> $existing Previously stored option value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input, array $existing ): array {
		$out = array_merge( self::defaults(), $existing );

		// Conditions — array or newline-delimited string.
		if ( isset( $input['conditions'] ) ) {
			$conds = is_array( $input['conditions'] )
				? $input['conditions']
				: preg_split( '/\r\n|\r|\n/', (string) $input['conditions'] );
			$out['conditions'] = array_values(
				array_filter(
					array_map(
						'sanitize_text_field',
						array_map( 'wp_unslash', (array) $conds )
					)
				)
			);
		}

		// NCT ID lists.
		if ( isset( $input['include_ncts'] ) ) {
			$out['include_ncts'] = self::parse_ncts( (string) wp_unslash( $input['include_ncts'] ) );
		}
		if ( isset( $input['exclude_ncts'] ) ) {
			$out['exclude_ncts'] = self::parse_ncts( (string) wp_unslash( $input['exclude_ncts'] ) );
		}

		// Statuses — validated against whitelist; falls back to RECRUITING.
		if ( isset( $input['statuses'] ) ) {
			$out['statuses'] = array_values(
				array_intersect(
					self::VALID_STATUSES,
					array_map( 'sanitize_text_field', (array) $input['statuses'] )
				)
			);
			if ( ! $out['statuses'] ) {
				$out['statuses'] = [ 'RECRUITING' ];
			}
		}

		// Reconcile mode.
		$out['reconcile_mode'] = ( ( $input['reconcile_mode'] ?? '' ) === 'remove' )
			? 'remove'
			: 'mark_closed';

		// Boolean toggles.
		$out['summaries_enabled']      = ! empty( $input['summaries_enabled'] );
		$out['show_map']               = ! empty( $input['show_map'] );
		$out['single_pages']           = ! empty( $input['single_pages'] );
		$out['index_singles_override'] = ! empty( $input['index_singles_override'] );
		$out['attribution']            = ! empty( $input['attribution'] );

		// LLM provider (whitelisted).
		$p             = sanitize_text_field( $input['provider'] ?? 'anthropic' );
		$out['provider'] = in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic';

		// Model name.
		$out['model'] = sanitize_text_field( $input['model'] ?? '' );

		// Display fields list.
		if ( isset( $input['display_fields'] ) ) {
			$out['display_fields'] = array_values(
				array_map( 'sanitize_text_field', (array) $input['display_fields'] )
			);
		}

		// API key — write-only contract.
		if ( ! empty( $input['clear_api_key'] ) ) {
			// Explicit clear takes highest precedence.
			$out['api_key'] = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			// Non-blank new value replaces the stored key.
			$out['api_key'] = sanitize_text_field( $input['api_key'] );
		} else {
			// Blank / missing submit preserves the existing key.
			$out['api_key'] = $existing['api_key'] ?? '';
		}

		return $out;
	}
}
