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

/**
 * Generates and caches plain-language trial summaries via the configured LLM provider.
 */
final class Summary_Service {

	/**
	 * LLM back-end, or null when summaries are disabled.
	 *
	 * @var Llm_Provider|null
	 */
	private $provider;

	/**
	 * Logger for recording errors.
	 *
	 * @var Logger_Interface
	 */
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

		// The enhanced patient-facing fields have their own change-detection
		// cache (keyed on study_purpose_source_date). They must be generated
		// independently of the plain-summary cache: a trial whose summary is
		// already current would otherwise never get the enhanced fields, since
		// the summary cache-hit below returns before they could be produced.
		$enhanced_written = $this->maybe_generate_enhanced( $post_id, $meta );

		$new_date = (string) ( $meta['ct_last_update'] ?? '' );
		$existing = (string) get_post_meta( $post_id, Trial_Meta::KEYS['plain_summary'], true );
		$src_date = (string) get_post_meta( $post_id, Trial_Meta::KEYS['plain_summary_source_date'], true );

		// Cache hit: existing summary and date unchanged — skip the summary API
		// call. Still report whether enhanced fields were (re)written above.
		if ( '' !== $existing && '' !== $new_date && $src_date === $new_date ) {
			return $enhanced_written;
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
			return $enhanced_written; // Front end unaffected; falls back to raw display.
		}

		$text = Prompt_Builder::enforce_disclaimer( (string) $text );

		update_post_meta( $post_id, Trial_Meta::KEYS['plain_summary'], wp_kses_post( $text ) );
		update_post_meta( $post_id, Trial_Meta::KEYS['plain_summary_source_date'], $new_date );

		return true;
	}

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

		// Self-heal: an earlier plugin version stored who_can_join through a
		// tag-stripping sanitizer, leaving run-on text with no list markup. Such
		// rows would otherwise survive the source-date cache forever. Treat a
		// non-empty who_can_join that lacks <li> markup as stale so a re-sync
		// regenerates it through the current (HTML-preserving) path.
		$who_can_join  = (string) get_post_meta( $post_id, Trial_Meta::KEYS['who_can_join'], true );
		$wcj_malformed = ( '' !== $who_can_join && false === strpos( $who_can_join, '<li>' ) );

		if ( '' !== $existing && '' !== $new_date && $src_date === $new_date && ! $wcj_malformed ) {
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
}
