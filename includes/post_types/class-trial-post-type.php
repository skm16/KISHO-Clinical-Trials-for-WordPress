<?php
/**
 * Registers the skmctf_trial custom post type.
 *
 * @package SKMCTF\Post_Types
 */

namespace SKMCTF\Post_Types;

final class Trial_Post_Type {

	public const POST_TYPE = 'skmctf_trial';

	/**
	 * Register the CPT with WordPress.
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = [
			'name'          => __( 'Clinical Trials', 'kisho-clinical-trials' ),
			'singular_name' => __( 'Clinical Trial', 'kisho-clinical-trials' ),
			'menu_name'     => __( 'Clinical Trials', 'kisho-clinical-trials' ),
			'all_items'     => __( 'All Trials', 'kisho-clinical-trials' ),
			'search_items'  => __( 'Search Trials', 'kisho-clinical-trials' ),
			'not_found'     => __( 'No trials found.', 'kisho-clinical-trials' ),
		];

		register_post_type( self::POST_TYPE, [
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => true,
			'show_in_rest'    => true,
			'supports'        => [ 'title', 'editor', 'custom-fields' ],
			'rewrite'         => [ 'slug' => apply_filters( 'skmctf_trial_rewrite_slug', 'clinical-trials' ) ],
			'menu_icon'       => 'dashicons-clipboard',
			'capability_type' => 'post',
		] );
	}
}
