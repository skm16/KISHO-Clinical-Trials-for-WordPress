<?php
/**
 * Trial Repository — the single writer of skmctf_trial posts.
 *
 * All code that needs to insert or update trial posts must go through
 * this class. No other class should call wp_insert_post for trials.
 *
 * @package SKMCTF\Data
 */

namespace SKMCTF\Data;

use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Post_Types\Trial_Post_Type;
use SKMCTF\Post_Types\Trial_Taxonomies;

/**
 * Single writer of skmctf_trial posts — all inserts and updates go through this class.
 */
final class Trial_Repository implements Repo_Interface {

	/**
	 * Find a trial post ID by its NCT ID.
	 *
	 * Uses a slug (post_name) lookup — fast index scan, no meta join.
	 *
	 * @param string $nct NCT ID string (e.g. "NCT00000001").
	 * @return int|null Post ID, or null if not found.
	 */
	public function find_id_by_nct( string $nct ): ?int {
		$found = get_posts(
			array(
				'post_type'     => Trial_Post_Type::POST_TYPE,
				'post_status'   => 'any',
				'name'          => strtolower( $nct ),
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		return $found ? (int) $found[0] : null;
	}

	/**
	 * Insert or update a trial post from a short-keyed meta array.
	 *
	 * $meta is expected to use the short keys from Trial_Meta::KEYS
	 * (as produced by Field_Mapper). Writes all meta keys except
	 * last_synced (which is set to time() unconditionally).
	 * Also syncs the trial_status, trial_phase, trial_country, and
	 * trial_state taxonomy terms.
	 *
	 * @param array $meta Short-keyed meta array from Field_Mapper.
	 * @return int Post ID (0 on failure).
	 */
	public function upsert( array $meta ): int {
		$nct     = $meta['nct_id'];
		$postarr = array(
			'post_type'    => Trial_Post_Type::POST_TYPE,
			'post_status'  => 'publish',
			'post_title'   => '' !== $meta['brief_title'] ? $meta['brief_title'] : $meta['official_title'],
			'post_name'    => strtolower( $nct ),
			'post_content' => '',
		);

		$id = $this->find_id_by_nct( $nct );
		if ( $id ) {
			$postarr['ID'] = $id;
			wp_update_post( $postarr );
		} else {
			$id = (int) wp_insert_post( $postarr );
		}

		if ( ! $id ) {
			return 0;
		}

		foreach ( Trial_Meta::KEYS as $short => $meta_key ) {
			if ( 'last_synced' === $short ) {
				continue;
			}
			if ( array_key_exists( $short, $meta ) ) {
				update_post_meta( $id, $meta_key, $meta[ $short ] );
			}
		}
		update_post_meta( $id, Trial_Meta::KEYS['last_synced'], time() );

		$status_terms = '' !== $meta['overall_status'] ? array( $meta['overall_status'] ) : array();
		wp_set_object_terms( $id, $status_terms, Trial_Taxonomies::STATUS );

		$phase_terms = '' !== $meta['phase'] ? array_map( 'trim', explode( '/', $meta['phase'] ) ) : array();
		$phase_terms = array_values( array_filter( $phase_terms, static fn( $p ) => '' !== $p ) );
		wp_set_object_terms( $id, $phase_terms, Trial_Taxonomies::PHASE );

		$countries = array();
		foreach ( (array) ( $meta['locations'] ?? array() ) as $loc ) {
			$c = is_array( $loc ) && isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
			if ( '' !== $c ) {
				$countries[ $c ] = true;
			}
		}
		wp_set_object_terms( $id, array_keys( $countries ), Trial_Taxonomies::COUNTRY );

		$states = array();
		foreach ( (array) ( $meta['locations'] ?? array() ) as $loc ) {
			$s = is_array( $loc ) && isset( $loc['state'] ) ? trim( (string) $loc['state'] ) : '';
			if ( '' !== $s ) {
				$states[ $s ] = true;
			}
		}
		wp_set_object_terms( $id, array_keys( $states ), Trial_Taxonomies::STATE );

		return $id;
	}

	/**
	 * Backfill trial_country terms for all existing trial posts from their stored locations meta.
	 *
	 * Iterates every skmctf_trial post, reads the locations meta, derives distinct country
	 * strings, and assigns them as trial_country terms. Passing an empty array clears stale
	 * terms, so posts without locations end up with no country terms.
	 *
	 * @return int Number of posts processed.
	 */
	public function backfill_country_terms(): int {
		$ids   = get_posts(
			array( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin-only backfill; small CPT dataset by design.
				'post_type'      => Trial_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$locations = get_post_meta( $id, Trial_Meta::KEYS['locations'], true );
			$countries = array();
			foreach ( (array) $locations as $loc ) {
				$c = is_array( $loc ) && isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
				if ( '' !== $c ) {
					$countries[ $c ] = true;
				}
			}
			wp_set_object_terms( (int) $id, array_keys( $countries ), Trial_Taxonomies::COUNTRY );
			++$count;
		}
		return $count;
	}

	/**
	 * Backfill trial_state terms for all existing trial posts from their stored locations meta.
	 *
	 * Iterates every skmctf_trial post, reads the locations meta, derives distinct state
	 * strings, and assigns them as trial_state terms. Passing an empty array clears stale
	 * terms, so posts without locations end up with no state terms.
	 *
	 * @return int Number of posts processed.
	 */
	public function backfill_state_terms(): int {
		$ids   = get_posts(
			array( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- admin-only backfill; small CPT dataset by design.
				'post_type'      => Trial_Post_Type::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$count = 0;
		foreach ( $ids as $id ) {
			$locations = get_post_meta( $id, Trial_Meta::KEYS['locations'], true );
			$states    = array();
			foreach ( (array) $locations as $loc ) {
				$s = is_array( $loc ) && isset( $loc['state'] ) ? trim( (string) $loc['state'] ) : '';
				if ( '' !== $s ) {
					$states[ $s ] = true;
				}
			}
			wp_set_object_terms( (int) $id, array_keys( $states ), Trial_Taxonomies::STATE );
			++$count;
		}
		return $count;
	}

	/**
	 * Return all NCT IDs stored in the database.
	 *
	 * @return array Array of NCT ID strings.
	 */
	public function all_nct_ids(): array {
		$ids  = get_posts(
			array(
				'post_type'     => Trial_Post_Type::POST_TYPE,
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$ncts = array();
		foreach ( $ids as $id ) {
			$nct = get_post_meta( $id, Trial_Meta::KEYS['nct_id'], true );
			if ( $nct ) {
				$ncts[] = $nct;
			}
		}
		return $ncts;
	}

	/**
	 * Mark a trial as CLOSED — sets status meta and taxonomy term.
	 *
	 * @param string $nct NCT ID of the trial to close.
	 * @return void
	 */
	public function mark_closed( string $nct ): void {
		$id = $this->find_id_by_nct( $nct );
		if ( $id ) {
			update_post_meta( $id, Trial_Meta::KEYS['overall_status'], 'CLOSED' );
			wp_set_object_terms( $id, 'CLOSED', Trial_Taxonomies::STATUS );
		}
	}

	/**
	 * Permanently delete a trial post by NCT ID.
	 *
	 * @param string $nct NCT ID of the trial to delete.
	 * @return void
	 */
	public function delete_by_nct( string $nct ): void {
		$id = $this->find_id_by_nct( $nct );
		if ( $id ) {
			wp_delete_post( $id, true );
		}
	}

	/** Transient key for the cached country => states map. */
	private const STATES_BY_COUNTRY_CACHE = 'skmctf_states_by_country';

	/**
	 * Build a map of country => sorted list of distinct state/province names.
	 *
	 * Derived from the stored skmctf_locations meta (the only place that pairs a
	 * state with its country — the trial_state taxonomy terms are flat and carry
	 * no country association). Used to populate a country-scoped State/Province
	 * filter dropdown. The result is cached in a transient and rebuilt when a
	 * trial is saved or deleted (see flush_states_by_country()).
	 *
	 * @return array<string,string[]> Country name => list of state/province names.
	 */
	public function states_by_country(): array {
		if ( function_exists( 'get_transient' ) ) {
			$cached = get_transient( self::STATES_BY_COUNTRY_CACHE );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$ids = get_posts(
			array( // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- small CPT dataset by design; result is cached.
				'post_type'      => Trial_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$map = array();
		foreach ( $ids as $id ) {
			$locations = get_post_meta( $id, Trial_Meta::KEYS['locations'], true );
			foreach ( (array) $locations as $loc ) {
				if ( ! is_array( $loc ) ) {
					continue;
				}
				$country = isset( $loc['country'] ) ? trim( (string) $loc['country'] ) : '';
				$state   = isset( $loc['state'] ) ? trim( (string) $loc['state'] ) : '';
				if ( '' === $country || '' === $state ) {
					continue;
				}
				$map[ $country ][ $state ] = true;
			}
		}

		// Normalise to sorted, de-duplicated lists.
		$out = array();
		foreach ( $map as $country => $states ) {
			$names = array_keys( $states );
			sort( $names );
			$out[ $country ] = $names;
		}
		ksort( $out );

		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::STATES_BY_COUNTRY_CACHE, $out, DAY_IN_SECONDS );
		}

		return $out;
	}

	/**
	 * Invalidate the cached country => states map.
	 *
	 * Hooked to trial save/delete so the State/Province dropdown reflects the
	 * latest data after a sync. Safe to call when transients are unavailable.
	 *
	 * @return void
	 */
	public static function flush_states_by_country(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::STATES_BY_COUNTRY_CACHE );
		}
	}
}
