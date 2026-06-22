<?php
/**
 * Renders the full detail view of a single clinical trial.
 *
 * All output is escaped. Only plain_summary is run through wp_kses_post
 * because it is stored as authored HTML through WP's sanitize pipeline.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Admin\Settings;
use SKMCTF\Support\Template_Loader;

/**
 * Renders the full detail view for a single skmctf_trial post.
 */
final class Single_Renderer {

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

	/**
	 * Render the full detail view for a trial post.
	 *
	 * @param int $post_id WP post ID.
	 * @return string HTML ready for output.
	 */
	public static function render( int $post_id ): string {
		ob_start();
		// phpcs:disable WordPress.PHP.DontExtract.extract_extract
		extract( self::view_data( $post_id ) );
		// phpcs:enable

		try {
			include Template_Loader::locate( 'single-skmctf_trial.php' );
		} catch ( \RuntimeException $e ) {
			echo '<p class="skmctf-error">' . esc_html__( 'Template error.', 'kisho-clinical-trials' ) . '</p>';
		}

		return ob_get_clean();
	}

	/**
	 * Load all display meta for a single trial post.
	 *
	 * @param int $post_id WP post ID.
	 * @return array<string,mixed>
	 */
	private static function get_meta( int $post_id ): array {
		$keys = Trial_Meta::KEYS;
		return array(
			'nct_id'           => get_post_meta( $post_id, $keys['nct_id'], true ),
			'official_title'   => get_post_meta( $post_id, $keys['official_title'], true ),
			'overall_status'   => get_post_meta( $post_id, $keys['overall_status'], true ),
			'phase'            => get_post_meta( $post_id, $keys['phase'], true ),
			'conditions'       => get_post_meta( $post_id, $keys['conditions'], true ),
			'lead_sponsor'     => get_post_meta( $post_id, $keys['lead_sponsor'], true ),
			'locations'        => get_post_meta( $post_id, $keys['locations'], true ),
			'eligibility'      => get_post_meta( $post_id, $keys['eligibility'], true ),
			'brief_summary'    => get_post_meta( $post_id, $keys['brief_summary'], true ),
			'plain_summary'    => get_post_meta( $post_id, $keys['plain_summary'], true ),
			'ct_url'           => get_post_meta( $post_id, $keys['ct_url'], true ),
			'study_purpose'    => get_post_meta( $post_id, $keys['study_purpose'], true ),
			'who_can_join'     => get_post_meta( $post_id, $keys['who_can_join'], true ),
			'doctor_questions' => get_post_meta( $post_id, $keys['doctor_questions'], true ),
		);
	}
}
