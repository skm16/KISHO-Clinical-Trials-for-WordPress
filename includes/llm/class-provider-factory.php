<?php
/**
 * Provider factory — reads Settings, applies filter seams, and returns the
 * correct Llm_Provider instance (or null when summaries are disabled / no key).
 *
 * The `skmctf_llm_api_key` filter is the future-proofing seam that allows a
 * core/global key vault to supply the key without touching stored settings.
 * The `skmctf_llm_model` filter allows per-environment model overrides.
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

use SKMCTF\Admin\Settings;

final class Provider_Factory {

	/**
	 * Build and return the configured LLM provider, or null when unavailable.
	 *
	 * Returns null when:
	 *   - Summaries are disabled in Settings.
	 *   - The API key is empty (even after the filter).
	 *
	 * @return Llm_Provider|null
	 */
	public static function make(): ?Llm_Provider {
		if ( ! Settings::summaries_enabled() ) {
			return null;
		}

		$provider_name = Settings::provider();

		/**
		 * Filter the LLM API key.
		 *
		 * A future core/global key vault can supply the key here, overriding
		 * (or supplementing) whatever is stored in Settings.
		 *
		 * @param string $key           The stored API key.
		 * @param string $provider_name The active provider slug ('anthropic'|'openai').
		 */
		$key = (string) apply_filters( 'skmctf_llm_api_key', Settings::get_api_key(), $provider_name );

		/**
		 * Filter the LLM model identifier.
		 *
		 * Allows per-environment model overrides without touching stored settings.
		 *
		 * @param string $model         The stored model string (may be empty).
		 * @param string $provider_name The active provider slug.
		 */
		$model = (string) apply_filters( 'skmctf_llm_model', Settings::model(), $provider_name );

		if ( '' === trim( $key ) ) {
			return null;
		}

		return 'openai' === $provider_name
			? new Openai_Provider( $key, $model )
			: new Anthropic_Provider( $key, $model );
	}
}
