<?php
/**
 * Builds WP_Query args from filter inputs.
 *
 * A pure argument builder — no side effects; any WP_Query instance returned
 * by ::query() is the only place WP infrastructure is touched.
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Post_Types\Trial_Taxonomies;
use SKMCTF\Post_Types\Trial_Meta;

/**
 * Builds and executes WP_Query args from normalised filter inputs.
 */
final class Trials_Query {

	/**
	 * Build WP_Query args from a set of normalised filter values.
	 *
	 * Filters accepted:
	 *   status   (string)  — trial_status taxonomy term name
	 *   phase    (string)  — trial_phase taxonomy term name
	 *   country  (string)  — trial_country taxonomy term name
	 *   state    (string)  — two-letter state abbrev; LIKE search on locations meta
	 *   per_page (int)
	 *   paged    (int)
	 *
	 * @param array<string,mixed> $filters Caller-supplied filter values.
	 * @return array<string,mixed> WP_Query-compatible args array.
	 */
	public static function args( array $filters ): array {
		$args = array(
			'post_type'      => Trial_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => isset( $filters['per_page'] ) ? absint( $filters['per_page'] ) : 20,
			'paged'          => isset( $filters['paged'] ) ? max( 1, absint( $filters['paged'] ) ) : 1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => false,
		);

		// --- Taxonomy filters ---------------------------------------------------
		$tax = array();
		if ( ! empty( $filters['status'] ) ) {
			$tax[] = array(
				'taxonomy' => Trial_Taxonomies::STATUS,
				'field'    => 'name',
				'terms'    => sanitize_text_field( $filters['status'] ),
			);
		}
		if ( ! empty( $filters['phase'] ) ) {
			$tax[] = array(
				'taxonomy' => Trial_Taxonomies::PHASE,
				'field'    => 'name',
				'terms'    => sanitize_text_field( $filters['phase'] ),
			);
		}
		if ( ! empty( $filters['country'] ) ) {
			$tax[] = array(
				'taxonomy' => Trial_Taxonomies::COUNTRY,
				'field'    => 'name',
				'terms'    => sanitize_text_field( $filters['country'] ),
			);
		}
		if ( $tax ) {
			$tax['relation']   = 'AND';
			$args['tax_query'] = $tax; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- intentional filter on a small (rare-disease) dataset; see design spec.
		}

		// --- Meta filters -------------------------------------------------------
		if ( ! empty( $filters['state'] ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional filter on a small (rare-disease) dataset; see design spec.
				array(
					'key'     => Trial_Meta::KEYS['locations'],
					'value'   => sanitize_text_field( $filters['state'] ),
					'compare' => 'LIKE',
				),
			);
		}

		return $args;
	}

	/**
	 * Execute a query with the given filters and return the WP_Query object.
	 *
	 * @param array<string,mixed> $filters Filter values.
	 * @return \WP_Query
	 */
	public static function query( array $filters ): \WP_Query {
		return new \WP_Query( self::args( $filters ) );
	}
}
