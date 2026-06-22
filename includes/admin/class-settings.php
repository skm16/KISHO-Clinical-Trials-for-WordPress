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

/**
 * Typed settings facade over the skmctf_settings WordPress option.
 */
final class Settings {

	/**
	 * WordPress option name.
	 *
	 * @var string
	 */
	public const OPTION = 'skmctf_settings';

	/**
	 * Allowed trial status values from ClinicalTrials.gov.
	 *
	 * @var string[]
	 */
	public const VALID_STATUSES = array(
		'RECRUITING',
		'NOT_YET_RECRUITING',
		'ENROLLING_BY_INVITATION',
		'ACTIVE_NOT_RECRUITING',
		'COMPLETED',
		'SUSPENDED',
		'TERMINATED',
		'WITHDRAWN',
		'UNKNOWN',
	);

	/**
	 * Supported LLM providers.
	 *
	 * @var string[]
	 */
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

	/**
	 * Supported listing view modes.
	 *
	 * @var string[]
	 */
	public const VALID_VIEWS = array( 'grid', 'list' );

	// -------------------------------------------------------------------------
	// Defaults
	// -------------------------------------------------------------------------

	/**
	 * Return the full set of option defaults.
	 *
	 * @return array<string,mixed>
	 */
	private static function defaults(): array {
		return array(
			'conditions'             => array(),
			'include_ncts'           => array(),
			'exclude_ncts'           => array(),
			'statuses'               => array( 'RECRUITING' ),
			'reconcile_mode'         => 'mark_closed',
			'summaries_enabled'      => false,
			'provider'               => 'anthropic',
			'api_key'                => '',
			'model'                  => '',
			'show_map'               => false,
			'default_lat'            => '',
			'default_lng'            => '',
			'default_zoom'           => '',
			'enable_geolocation'     => false,
			'single_pages'           => true,
			'index_singles_override' => false,
			'display_fields'         => array( 'status', 'phase', 'conditions', 'sponsor', 'locations', 'summary' ),
			'attribution'            => false,
			'theme'                  => 'skeleton',
			'theme_mode'             => 'light',
			'default_view'           => 'grid',
		);
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
		$o = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $o ) ? $o : array() );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key           Option key.
	 * @param mixed  $default_value Fallback when key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default_value = null ) {
		$a = self::all();
		return isset( $a[ $key ] ) ? $a[ $key ] : $default_value;
	}

	/**
	 * Return the configured disease conditions array.
	 *
	 * @return string[]
	 */
	public static function conditions(): array {
		return (array) self::get( 'conditions', array() );
	}

	/**
	 * Return the configured trial statuses, falling back to RECRUITING.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		$s = (array) self::get( 'statuses', array( 'RECRUITING' ) );
		return $s ? $s : array( 'RECRUITING' );
	}

	/**
	 * Return the list of NCT IDs to always include in the feed.
	 *
	 * @return string[]
	 */
	public static function include_ncts(): array {
		return (array) self::get( 'include_ncts', array() );
	}

	/**
	 * Return the list of NCT IDs to always exclude from the feed.
	 *
	 * @return string[]
	 */
	public static function exclude_ncts(): array {
		return (array) self::get( 'exclude_ncts', array() );
	}

	/**
	 * Return the reconcile mode ('mark_closed' or 'remove').
	 *
	 * @return string
	 */
	public static function reconcile_mode(): string {
		return 'remove' === self::get( 'reconcile_mode' ) ? 'remove' : 'mark_closed';
	}

	/**
	 * Return whether LLM summaries are enabled.
	 *
	 * @return bool
	 */
	public static function summaries_enabled(): bool {
		return (bool) self::get( 'summaries_enabled', false );
	}

	/**
	 * Return the configured LLM provider slug.
	 *
	 * @return string
	 */
	public static function provider(): string {
		$p = (string) self::get( 'provider', 'anthropic' );
		return in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic';
	}

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

	/**
	 * Return the default listing view ('grid' | 'list').
	 *
	 * @return string
	 */
	public static function default_view(): string {
		$v = (string) self::get( 'default_view', 'grid' );
		return in_array( $v, self::VALID_VIEWS, true ) ? $v : 'grid';
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

	/**
	 * Return the configured LLM model identifier.
	 *
	 * @return string
	 */
	public static function model(): string {
		return (string) self::get( 'model', '' );
	}

	/**
	 * Return whether the interactive map is enabled.
	 *
	 * @return bool
	 */
	public static function show_map(): bool {
		return (bool) self::get( 'show_map', false );
	}

	/**
	 * Return the configured default map latitude, or empty string if unset.
	 *
	 * @return string
	 */
	public static function default_lat(): string {
		return (string) self::get( 'default_lat', '' );
	}

	/**
	 * Return the configured default map longitude, or empty string if unset.
	 *
	 * @return string
	 */
	public static function default_lng(): string {
		return (string) self::get( 'default_lng', '' );
	}

	/**
	 * Return the configured default map zoom level, or empty string if unset.
	 *
	 * @return string
	 */
	public static function default_zoom(): string {
		return (string) self::get( 'default_zoom', '' );
	}

	/**
	 * Return whether the client-side geolocation feature is enabled.
	 *
	 * @return bool
	 */
	public static function geolocation_enabled(): bool {
		return (bool) self::get( 'enable_geolocation', false );
	}

	/**
	 * Return whether individual trial single pages are enabled.
	 *
	 * @return bool
	 */
	public static function single_pages_enabled(): bool {
		return (bool) self::get( 'single_pages', true );
	}

	/**
	 * Return whether the admin override forces single pages to be indexed.
	 *
	 * @return bool
	 */
	public static function index_singles_override(): bool {
		return (bool) self::get( 'index_singles_override', false );
	}

	/**
	 * Return the list of display field keys selected in settings.
	 *
	 * @return string[]
	 */
	public static function display_fields(): array {
		return (array) self::get( 'display_fields', array() );
	}

	/**
	 * Return whether SKM Digital attribution is enabled.
	 *
	 * @return bool
	 */
	public static function attribution_enabled(): bool {
		return (bool) self::get( 'attribution', false );
	}

	// -------------------------------------------------------------------------
	// Sanitizer
	// -------------------------------------------------------------------------

	/**
	 * Sanitize a latitude value; returns '' when invalid or out of range.
	 *
	 * Range: -90.0 to 90.0 (inclusive). Pure — no WordPress calls.
	 *
	 * @param mixed $v Raw input value.
	 * @return string Validated float as string, or empty string.
	 */
	public static function sanitize_lat( $v ): string {
		if ( '' === $v || ! is_numeric( $v ) ) {
			return '';
		}
		$f = (float) $v;
		return ( $f >= -90.0 && $f <= 90.0 ) ? (string) $f : '';
	}

	/**
	 * Sanitize a longitude value; returns '' when invalid or out of range.
	 *
	 * Range: -180.0 to 180.0 (inclusive). Pure — no WordPress calls.
	 *
	 * @param mixed $v Raw input value.
	 * @return string Validated float as string, or empty string.
	 */
	public static function sanitize_lng( $v ): string {
		if ( '' === $v || ! is_numeric( $v ) ) {
			return '';
		}
		$f = (float) $v;
		return ( $f >= -180.0 && $f <= 180.0 ) ? (string) $f : '';
	}

	/**
	 * Sanitize a Leaflet zoom level; returns '' when invalid or out of range.
	 *
	 * Range: 1 to 19 (inclusive), integer. Pure — no WordPress calls.
	 *
	 * @param mixed $v Raw input value.
	 * @return string Validated integer as string, or empty string.
	 */
	public static function sanitize_zoom( $v ): string {
		if ( '' === $v || ! is_numeric( $v ) ) {
			return '';
		}
		$i = (int) $v;
		return ( $i >= 1 && $i <= 19 ) ? (string) $i : '';
	}

	/**
	 * Parse a newline-separated list of NCT IDs, validate format, deduplicate.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	private static function parse_ncts( string $raw ): array {
		$out = array();
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
			$conds             = is_array( $input['conditions'] )
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
				$out['statuses'] = array( 'RECRUITING' );
			}
		}

		// Reconcile mode.
		$out['reconcile_mode'] = ( 'remove' === ( $input['reconcile_mode'] ?? '' ) )
			? 'remove'
			: 'mark_closed';

		// Boolean toggles.
		$out['summaries_enabled']      = ! empty( $input['summaries_enabled'] );
		$out['show_map']               = ! empty( $input['show_map'] );
		$out['single_pages']           = ! empty( $input['single_pages'] );
		$out['index_singles_override'] = ! empty( $input['index_singles_override'] );
		$out['attribution']            = ! empty( $input['attribution'] );
		$out['enable_geolocation']     = ! empty( $input['enable_geolocation'] );

		// Map view defaults — validated through pure sanitizers.
		$out['default_lat']  = self::sanitize_lat( $input['default_lat'] ?? '' );
		$out['default_lng']  = self::sanitize_lng( $input['default_lng'] ?? '' );
		$out['default_zoom'] = self::sanitize_zoom( $input['default_zoom'] ?? '' );

		// LLM provider (whitelisted).
		$p               = sanitize_text_field( $input['provider'] ?? 'anthropic' );
		$out['provider'] = in_array( $p, self::PROVIDERS, true ) ? $p : 'anthropic';

		// Theme (whitelisted).
		$theme        = sanitize_text_field( $input['theme'] ?? 'skeleton' );
		$out['theme'] = in_array( $theme, self::VALID_THEMES, true ) ? $theme : 'skeleton';

		// Appearance mode (whitelisted).
		$mode              = sanitize_text_field( $input['theme_mode'] ?? 'light' );
		$out['theme_mode'] = in_array( $mode, self::VALID_THEME_MODES, true ) ? $mode : 'light';

		// Default listing view (whitelisted).
		$view                = sanitize_text_field( $input['default_view'] ?? 'grid' );
		$out['default_view'] = in_array( $view, self::VALID_VIEWS, true ) ? $view : 'grid';

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
