<?php
/**
 * Prompt Builder — assembles system and user prompts, and enforces the
 * medical disclaimer on any LLM-generated summary text.
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

final class Prompt_Builder {

	/**
	 * Return the translatable medical disclaimer sentence.
	 *
	 * @return string
	 */
	public static function disclaimer(): string {
		return __(
			'This is a plain-language summary — please read the full trial record and talk to your doctor before making any decisions.',
			'kisho-clinical-trials'
		);
	}

	/**
	 * Return the system (instruction) prompt for the LLM.
	 *
	 * @return string
	 */
	public static function system(): string {
		return __(
			'You write short, plain-language summaries of clinical trials for patients and families. Use about an 8th-grade reading level. Be factual and only use the information provided. Do not give medical advice, do not speculate, and do not invent details. Write 2-4 short sentences. End with the exact disclaimer sentence the user provides.',
			'kisho-clinical-trials'
		);
	}

	/**
	 * Build the user (content) prompt from trial meta.
	 *
	 * @param array $meta Associative array of trial meta values.
	 * @return string
	 */
	public static function user( array $meta ): string {
		$elig  = is_array( $meta['eligibility'] ?? null ) ? $meta['eligibility'] : array();
		$lines = array();

		/* translators: %s: the trial's brief title. */
		$lines[] = sprintf( __( 'Title: %s', 'kisho-clinical-trials' ), $meta['brief_title'] ?? '' );
		/* translators: %s: the trial's recruitment status. */
		$lines[] = sprintf( __( 'Status: %s', 'kisho-clinical-trials' ), $meta['overall_status'] ?? '' );
		/* translators: %s: the trial's phase. */
		$lines[] = sprintf( __( 'Phase: %s', 'kisho-clinical-trials' ), $meta['phase'] ?? '' );
		$lines[] = sprintf(
			/* translators: %s: comma-separated list of conditions. */
			__( 'Conditions: %s', 'kisho-clinical-trials' ),
			implode( ', ', (array) ( $meta['conditions'] ?? array() ) )
		);
		/* translators: %s: the trial's lead sponsor name. */
		$lines[] = sprintf( __( 'Sponsor: %s', 'kisho-clinical-trials' ), $meta['lead_sponsor'] ?? '' );
		$lines[] = sprintf(
			/* translators: 1: eligible sex, 2: minimum age, 3: maximum age. */
			__( 'Who can join: sex %1$s, ages %2$s to %3$s', 'kisho-clinical-trials' ),
			$elig['sex'] ?? '',
			$elig['min_age'] ?? '',
			$elig['max_age'] ?? ''
		);
		$lines[] = sprintf(
			/* translators: %s: the official brief summary text from ClinicalTrials.gov. */
			__( 'Official description: %s', 'kisho-clinical-trials' ),
			$meta['brief_summary'] ?? ''
		);
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: the required disclaimer sentence. */
			__( 'End your summary with exactly this sentence: %s', 'kisho-clinical-trials' ),
			self::disclaimer()
		);

		return implode( "\n", $lines );
	}

	/**
	 * Guarantee that $text contains the disclaimer phrase.
	 *
	 * If the phrase "talk to your doctor" is already present (case-insensitive)
	 * the text is returned unchanged. Otherwise the full disclaimer sentence is
	 * appended so that a medical disclaimer is always visible to the reader.
	 *
	 * @param string $text LLM-generated summary text.
	 * @return string Summary with disclaimer guaranteed to be present.
	 */
	public static function enforce_disclaimer( string $text ): string {
		$text = trim( $text );
		if ( false !== stripos( $text, 'talk to your doctor' ) ) {
			return $text;
		}
		return $text . "\n\n" . self::disclaimer();
	}
}
