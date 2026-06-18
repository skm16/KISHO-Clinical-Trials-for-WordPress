<?php
/**
 * LLM Provider interface — contract that all LLM back-ends must satisfy.
 *
 * NOTE: The brief refers to this file as interface-llm-provider.php, but the
 * autoloader always prepends "class-" to the lowercased class/interface name.
 * The file is therefore named class-llm-provider.php so that the autoloader can
 * resolve SKMCTF\LLM\Llm_Provider without a manual require.
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

interface Llm_Provider {

	/**
	 * Generate a plain-language summary.
	 *
	 * @param string  $system System / instruction prompt.
	 * @param string  $user   User / content prompt.
	 * @param array   $opts   Optional overrides (e.g. 'max_tokens').
	 * @return string|\WP_Error Plain text on success, WP_Error on any failure.
	 */
	public function generate_summary( string $system, string $user, array $opts = [] );

	/**
	 * Return a short stable identifier for this provider (e.g. 'anthropic').
	 *
	 * @return string
	 */
	public function id(): string;
}
