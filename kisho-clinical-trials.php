<?php
/**
 * Plugin Name:       Clinical Trials Feed
 * Plugin URI:        https://skm.digital/clinical-trials-feed
 * Description:       Pulls condition-relevant trials from ClinicalTrials.gov and displays them on your site, kept fresh automatically, with optional plain-language summaries via your own LLM API key.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            SKM Digital
 * Author URI:        https://skm.digital
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kisho-clinical-trials
 * Domain Path:       /languages
 *
 * @package SKMCTF
 */

defined( 'ABSPATH' ) || exit;

define( 'SKMCTF_VERSION', '1.0.0' );
define( 'SKMCTF_FILE', __FILE__ );
define( 'SKMCTF_PATH', plugin_dir_path( __FILE__ ) );
define( 'SKMCTF_URL', plugin_dir_url( __FILE__ ) );
define( 'SKMCTF_TEXT_DOMAIN', 'kisho-clinical-trials' );

// Load Action Scheduler (self-guards against double-loading; safe to bundle alongside WooCommerce et al.).
require_once SKMCTF_PATH . 'vendor-lib/action-scheduler/action-scheduler.php';

require_once SKMCTF_PATH . 'includes/class-autoloader.php';
( new SKMCTF\Autoloader( SKMCTF_PATH . 'includes' ) )->register();

// Activation: register CPT (so rewrite rules exist), flush rewrites, then schedule the daily job.
register_activation_hook(
	__FILE__,
	static function () {
		\SKMCTF\Post_Types\Trial_Post_Type::register();
		flush_rewrite_rules();
		( new \SKMCTF\Sync\Scheduler() )->activate();
	}
);

// Deactivation: remove the scheduled job and flush rewrite rules.
register_deactivation_hook(
	__FILE__,
	static function () {
		( new \SKMCTF\Sync\Scheduler() )->deactivate();
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		SKMCTF\Plugin::instance()->boot();
	}
);
