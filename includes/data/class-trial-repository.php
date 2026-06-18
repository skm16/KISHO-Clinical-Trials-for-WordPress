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
	 * Also syncs trial_status and trial_phase taxonomy terms.
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

		if ( '' !== $meta['overall_status'] ) {
			wp_set_object_terms( $id, $meta['overall_status'], Trial_Taxonomies::STATUS );
		}
		if ( ! empty( $meta['phase'] ) ) {
			wp_set_object_terms( $id, $meta['phase'], Trial_Taxonomies::PHASE );
		}

		return $id;
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
}
