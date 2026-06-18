<?php
/**
 * Uninstall — runs when the user deletes the plugin from WP admin.
 *
 * Removes plugin options and unschedules the daily sync action.
 * Trial posts (skmctf_trial CPT) are deliberately NOT deleted: they
 * represent content the site owner has published and may have SEO value.
 *
 * @package SKMCTF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Remove all plugin options.
foreach ( [ 'skmctf_settings', 'skmctf_last_sync', 'skmctf_last_error', 'skmctf_log' ] as $opt ) {
	delete_option( $opt );
}

// Unschedule the daily sync action (best-effort; Action Scheduler may already be gone).
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'skmctf_daily_sync', [], 'kisho-clinical-trials' );
}

// NOTE: trial posts are deliberately NOT deleted on uninstall (user content / SEO value).
// A "delete trials on uninstall" toggle could be added in a future version; the default keeps content.
