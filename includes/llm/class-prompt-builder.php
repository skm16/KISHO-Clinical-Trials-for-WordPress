<?php
/**
 * Prompt Builder — assembles system and user prompts, and enforces the
 * medical disclaimer on any LLM-generated summary text.
 *
 * @package SKMCTF\LLM
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\LLM;

/**
 * Assembles system and user prompts and enforces the medical disclaimer on summaries.
 */
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
}
