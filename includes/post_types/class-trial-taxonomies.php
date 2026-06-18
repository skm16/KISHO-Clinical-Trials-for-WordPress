<?php
/**
 * Registers the trial_status and trial_phase taxonomies.
 *
 * @package SKMCTF\Post_Types
 */

namespace SKMCTF\Post_Types;

final class Trial_Taxonomies {

	public const STATUS = 'trial_status';
	public const PHASE  = 'trial_phase';

	/**
	 * Register both taxonomies with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		foreach ( [
			self::STATUS => __( 'Trial Status', 'kisho-clinical-trials' ),
			self::PHASE  => __( 'Trial Phase', 'kisho-clinical-trials' ),
		] as $tax => $label ) {
			register_taxonomy( $tax, Trial_Post_Type::POST_TYPE, [
				'label'             => $label,
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => [ 'slug' => str_replace( '_', '-', $tax ) ],
			] );
		}
	}
}
