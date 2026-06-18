<?php
/**
 * Trial meta keys, definitions, and sanitizers.
 *
 * This class is the single source of truth for all post meta used by
 * the skmctf_trial CPT. Every later task should reference KEYS constants
 * rather than hard-coding meta key strings.
 *
 * @package SKMCTF\Post_Types
 */

namespace SKMCTF\Post_Types;

final class Trial_Meta {

	/** short_key => full meta_key */
	public const KEYS = array(
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
	);

	/**
	 * Sanitize an NCT ID to uppercase canonical form (NCT########).
	 *
	 * @param mixed $value Raw input value.
	 * @return string Canonical NCT ID or empty string if invalid.
	 */
	public static function sanitize_nct( $value ): string {
		$value = strtoupper( trim( (string) $value ) );
		return preg_match( '/^NCT\d{8}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize a date string to YYYY-MM-DD format.
	 *
	 * @param mixed $value Raw input value.
	 * @return string Date string or empty string if invalid.
	 */
	public static function sanitize_date( $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	/**
	 * Sanitize a flat list of strings.
	 *
	 * @param mixed $value Raw input value (expected array).
	 * @return array Sanitized array of strings.
	 */
	public static function sanitize_string_list( $value ): array {
		return array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					is_array( $value ) ? $value : array()
				)
			)
		);
	}

	/**
	 * Sanitize an array of location objects.
	 *
	 * @param mixed $value Raw input value.
	 * @return array Sanitized array of location objects.
	 */
	public static function sanitize_locations( $value ): array {
		$out = array();
		foreach ( (array) $value as $loc ) {
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$out[] = array(
				'facility' => sanitize_text_field( $loc['facility'] ?? '' ),
				'city'     => sanitize_text_field( $loc['city'] ?? '' ),
				'state'    => sanitize_text_field( $loc['state'] ?? '' ),
				'country'  => sanitize_text_field( $loc['country'] ?? '' ),
				'status'   => sanitize_text_field( $loc['status'] ?? '' ),
				'lat'      => isset( $loc['lat'] ) ? (float) $loc['lat'] : null,
				'lng'      => isset( $loc['lng'] ) ? (float) $loc['lng'] : null,
			);
		}
		return $out;
	}

	/**
	 * Sanitize the eligibility object.
	 *
	 * @param mixed $value Raw input value.
	 * @return array Sanitized eligibility object.
	 */
	public static function sanitize_eligibility( $value ): array {
		$value = is_array( $value ) ? $value : array();
		return array(
			'sex'      => sanitize_text_field( $value['sex'] ?? '' ),
			'min_age'  => sanitize_text_field( $value['min_age'] ?? '' ),
			'max_age'  => sanitize_text_field( $value['max_age'] ?? '' ),
			'criteria' => sanitize_textarea_field( $value['criteria'] ?? '' ),
		);
	}

	/**
	 * Return meta definitions for register_post_meta.
	 *
	 * @return array meta_key => definition array.
	 */
	public static function definitions(): array {
		$k    = self::KEYS;
		$str  = array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_text_field',
			'show_in_rest'      => true,
		);
		$text = array(
			'type'              => 'string',
			'single'            => true,
			'sanitize_callback' => 'sanitize_textarea_field',
			'show_in_rest'      => true,
		);
		return array(
			$k['nct_id']                    => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_nct' ),
				'show_in_rest'      => true,
			),
			$k['official_title']            => $str,
			$k['brief_title']               => $str,
			$k['overall_status']            => $str,
			$k['phase']                     => $str,
			$k['study_type']                => $str,
			$k['lead_sponsor']              => $str,
			$k['ct_last_update']            => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'show_in_rest'      => true,
			),
			$k['plain_summary_source_date'] => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_date' ),
				'show_in_rest'      => true,
			),
			$k['last_synced']               => array(
				'type'              => 'integer',
				'single'            => true,
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
			),
			$k['ct_url']                    => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
			),
			$k['brief_summary']             => $text,
			$k['plain_summary']             => array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'wp_kses_post',
				'show_in_rest'      => true,
			),
			$k['conditions']                => array(
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
			$k['locations']                 => array(
				'type'              => 'array',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_locations' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'facility' => array( 'type' => 'string' ),
								'city'     => array( 'type' => 'string' ),
								'state'    => array( 'type' => 'string' ),
								'country'  => array( 'type' => 'string' ),
								'status'   => array( 'type' => 'string' ),
								'lat'      => array( 'type' => array( 'number', 'null' ) ),
								'lng'      => array( 'type' => array( 'number', 'null' ) ),
							),
						),
					),
				),
			),
			$k['eligibility']               => array(
				'type'              => 'object',
				'single'            => true,
				'sanitize_callback' => array( self::class, 'sanitize_eligibility' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'sex'      => array( 'type' => 'string' ),
							'min_age'  => array( 'type' => 'string' ),
							'max_age'  => array( 'type' => 'string' ),
							'criteria' => array( 'type' => 'string' ),
						),
					),
				),
			),
		);
	}

	/**
	 * Register all meta keys with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( self::definitions() as $meta_key => $def ) {
			register_post_meta( Trial_Post_Type::POST_TYPE, $meta_key, $def );
		}
	}
}
