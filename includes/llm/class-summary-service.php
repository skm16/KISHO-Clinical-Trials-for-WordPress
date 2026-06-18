<?php
/**
 * Summary Service — generates plain-language trial summaries via an LLM provider,
 * using change-detection caching to avoid redundant API calls.
 *
 * A summary is (re-)generated only when:
 *   - No existing plain_summary is stored for the post, OR
 *   - The incoming ct_last_update date differs from the stored plain_summary_source_date.
 *
 * On WP_Error from the provider the error is logged and the method returns false
 * so that the front end can fall back to raw display without any exception being
 * thrown.
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Support\Logger_Interface;

final class Summary_Service {

	/** @var Llm_Provider|null */
	private $provider;

	/** @var Logger_Interface */
	private $log;

	/**
	 * Constructor.
	 *
	 * @param Llm_Provider|null $provider LLM back-end, or null to disable.
	 * @param Logger_Interface  $log      Logger.
	 */
	public function __construct( $provider, Logger_Interface $log ) {
		$this->provider = $provider;
		$this->log      = $log;
	}

	/**
	 * Maybe generate and store a plain-language summary for a trial post.
	 *
	 * Returns true if a new summary was written, false if the cache was valid
	 * or if generation was skipped / failed.
	 *
	 * @param int   $post_id WordPress post ID.
	 * @param array $meta    Trial meta array (must include 'ct_last_update').
	 * @return bool
	 */
	public function maybe_generate( int $post_id, array $meta ): bool {
		if ( null === $this->provider ) {
			return false;
		}

		$new_date = (string) ( $meta['ct_last_update'] ?? '' );
		$existing = (string) get_post_meta( $post_id, Trial_Meta::KEYS['plain_summary'], true );
		$src_date = (string) get_post_meta( $post_id, Trial_Meta::KEYS['plain_summary_source_date'], true );

		// Cache hit: existing summary and date unchanged — skip API call.
		if ( '' !== $existing && '' !== $new_date && $src_date === $new_date ) {
			return false;
		}

		$text = $this->provider->generate_summary(
			Prompt_Builder::system(),
			Prompt_Builder::user( $meta )
		);

		if ( is_wp_error( $text ) ) {
			$this->log->error(
				'Summary generation failed: ' . $text->get_error_message(),
				array( 'post' => $post_id )
			);
			return false; // Front end unaffected; falls back to raw display.
		}

		$text = Prompt_Builder::enforce_disclaimer( (string) $text );

		update_post_meta( $post_id, Trial_Meta::KEYS['plain_summary'], wp_kses_post( $text ) );
		update_post_meta( $post_id, Trial_Meta::KEYS['plain_summary_source_date'], $new_date );

		return true;
	}
}
