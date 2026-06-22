<?php
/**
 * Settings page view — Clinical Trials Feed.
 *
 * Variables available from Settings_Page::render():
 *   array  $s        All settings (merged with defaults).
 *   bool   $key_set  Whether an API key is currently stored.
 *   array  $last     Last sync summary (may be empty).
 *   string $error    Last error message (empty string if none).
 *
 * Security checklist:
 *   - Every dynamic value escaped with esc_html(), esc_attr(), esc_url(),
 *     checked(), selected(), or wp_date().
 *   - API key value attribute is ALWAYS empty (write-only).
 *   - Sync-now form is a SEPARATE <form> — never nested inside settings form.
 *
 * @package SKMCTF\Admin
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables injected by Settings_Page::render(); not global assignments.
defined( 'ABSPATH' ) || exit;

use SKMCTF\Admin\Settings;
use SKMCTF\Admin\Sync_Now_Controller;
use SKMCTF\Admin\Cleanup_Controller;
use SKMCTF\Admin\Settings_Page;

// Convenience shorthands for the arrays stored in $s. Raw values here; each is
// escaped with esc_textarea() at the point of output below.
$conditions   = implode( "\n", (array) $s['conditions'] );
$include_ncts = implode( "\n", (array) $s['include_ncts'] );
$exclude_ncts = implode( "\n", (array) $s['exclude_ncts'] );
$cur_statuses = (array) $s['statuses'];
$cur_fields   = (array) $s['display_fields'];

$all_display_fields = array(
	'status'     => __( 'Status', 'kisho-clinical-trials' ),
	'phase'      => __( 'Phase', 'kisho-clinical-trials' ),
	'conditions' => __( 'Conditions', 'kisho-clinical-trials' ),
	'sponsor'    => __( 'Sponsor', 'kisho-clinical-trials' ),
	'locations'  => __( 'Locations', 'kisho-clinical-trials' ),
	'summary'    => __( 'Summary', 'kisho-clinical-trials' ),
);

$all_themes = array(
	'skeleton' => __( 'Skeleton (default)', 'kisho-clinical-trials' ),
	'clinical' => __( 'Clinical', 'kisho-clinical-trials' ),
	'warm'     => __( 'Patient-friendly', 'kisho-clinical-trials' ),
);
$all_modes  = array(
	'light' => __( 'Light', 'kisho-clinical-trials' ),
	'dark'  => __( 'Dark', 'kisho-clinical-trials' ),
);
$all_views  = array(
	'grid' => __( 'Card grid', 'kisho-clinical-trials' ),
	'list' => __( 'List', 'kisho-clinical-trials' ),
);
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Clinical Trials Feed', 'kisho-clinical-trials' ); ?></h1>

	<div id="skmctf-settings-wrap" style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">

		<!-- =====================================================================
			Main settings form
			===================================================================== -->
		<div style="flex:1;min-width:520px;">
			<form method="post" action="options.php">
				<?php settings_fields( 'skmctf_group' ); ?>

				<!-- ── Trial Discovery ─────────────────────────────────── -->
				<h2><?php esc_html_e( 'Trial Discovery', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">
							<label for="skmctf_conditions"><?php esc_html_e( 'Conditions to search', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<textarea
								id="skmctf_conditions"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[conditions]"
								rows="5"
								cols="50"
								class="large-text"
							><?php echo esc_textarea( $conditions ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One search term per line, e.g. "Pompe disease".', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_include_ncts"><?php esc_html_e( 'Always include NCT IDs', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<textarea
								id="skmctf_include_ncts"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[include_ncts]"
								rows="4"
								cols="50"
								class="large-text"
							><?php echo esc_textarea( $include_ncts ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One NCT ID per line (NCT########). These trials are always included.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_exclude_ncts"><?php esc_html_e( 'Always exclude NCT IDs', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<textarea
								id="skmctf_exclude_ncts"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[exclude_ncts]"
								rows="4"
								cols="50"
								class="large-text"
							><?php echo esc_textarea( $exclude_ncts ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One NCT ID per line. These trials are always excluded.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Trial statuses to include', 'kisho-clinical-trials' ); ?></th>
						<td>
							<?php foreach ( Settings::VALID_STATUSES as $status_value ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input
										type="checkbox"
										name="<?php echo esc_attr( Settings::OPTION ); ?>[statuses][]"
										value="<?php echo esc_attr( $status_value ); ?>"
										<?php checked( in_array( $status_value, $cur_statuses, true ) ); ?>
									>
									<?php echo esc_html( ucwords( strtolower( str_replace( '_', ' ', $status_value ) ) ) ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'At least one status must be selected; defaults to Recruiting.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

				</table>

				<!-- ── Sync Behaviour ──────────────────────────────────── -->
				<h2><?php esc_html_e( 'Sync Behaviour', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'When a trial leaves the feed', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:6px;">
								<input
									type="radio"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[reconcile_mode]"
									value="mark_closed"
									<?php checked( $s['reconcile_mode'], 'mark_closed' ); ?>
								>
								<?php esc_html_e( 'Mark as closed (keep the post)', 'kisho-clinical-trials' ); ?>
							</label>
							<label>
								<input
									type="radio"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[reconcile_mode]"
									value="remove"
									<?php checked( $s['reconcile_mode'], 'remove' ); ?>
								>
								<?php esc_html_e( 'Remove the post entirely', 'kisho-clinical-trials' ); ?>
							</label>
						</td>
					</tr>

				</table>

				<!-- ── Display ─────────────────────────────────────────── -->
				<h2><?php esc_html_e( 'Display', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Fields to display', 'kisho-clinical-trials' ); ?></th>
						<td>
							<?php foreach ( $all_display_fields as $field_key => $field_label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input
										type="checkbox"
										name="<?php echo esc_attr( Settings::OPTION ); ?>[display_fields][]"
										value="<?php echo esc_attr( $field_key ); ?>"
										<?php checked( in_array( $field_key, $cur_fields, true ) ); ?>
									>
									<?php echo esc_html( $field_label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_default_view"><?php esc_html_e( 'Default view', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_default_view" name="<?php echo esc_attr( Settings::OPTION ); ?>[default_view]">
								<?php foreach ( $all_views as $view_key => $view_label ) : ?>
									<option
										value="<?php echo esc_attr( $view_key ); ?>"
										<?php selected( $s['default_view'], $view_key ); ?>
									>
										<?php echo esc_html( $view_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Initial layout for the trials list. Visitors can switch between card grid and list, and their choice is remembered in their browser.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Interactive map', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[show_map]"
									value="1"
									<?php checked( ! empty( $s['show_map'] ) ); ?>
								>
								<?php esc_html_e( 'Show a trial-location map on listing pages', 'kisho-clinical-trials' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Single trial pages', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[single_pages]"
									value="1"
									<?php checked( ! empty( $s['single_pages'] ) ); ?>
								>
								<?php esc_html_e( 'Enable individual pages for each trial', 'kisho-clinical-trials' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Index single trial pages', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[index_singles_override]"
									value="1"
									<?php checked( ! empty( $s['index_singles_override'] ) ); ?>
								>
								<?php esc_html_e( 'Allow search engines to index individual trial pages (overrides default noindex)', 'kisho-clinical-trials' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Attribution', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[attribution]"
									value="1"
									<?php checked( ! empty( $s['attribution'] ) ); ?>
								>
								<?php esc_html_e( 'Show "Data sourced from ClinicalTrials.gov" attribution on trial listings', 'kisho-clinical-trials' ); ?>
							</label>
						</td>
					</tr>

				</table>

				<!-- ── Theme & appearance ──────────────────────────────── -->
				<h2><?php esc_html_e( 'Theme &amp; appearance', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row">
							<label for="skmctf_theme"><?php esc_html_e( 'Theme', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_theme" name="<?php echo esc_attr( Settings::OPTION ); ?>[theme]">
								<?php foreach ( $all_themes as $theme_key => $theme_label ) : ?>
									<option
										value="<?php echo esc_attr( $theme_key ); ?>"
										<?php selected( $s['theme'], $theme_key ); ?>
									>
										<?php echo esc_html( $theme_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Out-of-the-box look for the trials list and single-trial pages. Skeleton keeps the default styling.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_theme_mode"><?php esc_html_e( 'Appearance', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_theme_mode" name="<?php echo esc_attr( Settings::OPTION ); ?>[theme_mode]">
								<?php foreach ( $all_modes as $mode_key => $mode_label ) : ?>
									<option
										value="<?php echo esc_attr( $mode_key ); ?>"
										<?php selected( $s['theme_mode'], $mode_key ); ?>
									>
										<?php echo esc_html( $mode_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Light or dark appearance. Applies to all themes, including Skeleton.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>

				</table>

				<!-- ── Map View Configuration ──────────────────────────── -->
				<h2><?php esc_html_e( 'Map View Configuration', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Enable geolocation button', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[enable_geolocation]"
									value="1"
									<?php checked( ! empty( $s['enable_geolocation'] ) ); ?>
								>
								<?php esc_html_e( 'Show a "Find trials near me" button on map-enabled listings', 'kisho-clinical-trials' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When checked, a "Find trials near me" button appears on map-enabled listings. Requires HTTPS. Coordinates are used only in the visitor\'s browser and are never sent to the server.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_default_lat"><?php esc_html_e( 'Default map latitude', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="skmctf_default_lat"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[default_lat]"
								value="<?php echo esc_attr( $s['default_lat'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'e.g. 40.7128 (range: -90 to 90)', 'kisho-clinical-trials' ); ?>"
							>
							<p class="description"><?php esc_html_e( 'Decimal degrees, -90 to 90. Leave blank to auto-fit to trial locations.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_default_lng"><?php esc_html_e( 'Default map longitude', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="skmctf_default_lng"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[default_lng]"
								value="<?php echo esc_attr( $s['default_lng'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'e.g. -74.0060 (range: -180 to 180)', 'kisho-clinical-trials' ); ?>"
							>
							<p class="description"><?php esc_html_e( 'Decimal degrees, -180 to 180. Leave blank to auto-fit to trial locations.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_default_zoom"><?php esc_html_e( 'Default map zoom', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								id="skmctf_default_zoom"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[default_zoom]"
								value="<?php echo esc_attr( $s['default_zoom'] ); ?>"
								class="small-text"
								min="1"
								max="19"
								placeholder="<?php esc_attr_e( '1–19', 'kisho-clinical-trials' ); ?>"
							>
							<p class="description"><?php esc_html_e( 'Integer 1–19. Leave blank to use the auto-fit zoom. Only applies when a default latitude and longitude are set.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

				</table>

				<!-- ── AI Summaries ────────────────────────────────────── -->
				<h2><?php esc_html_e( 'AI Summaries (optional)', 'kisho-clinical-trials' ); ?></h2>
				<table class="form-table" role="presentation">

					<tr>
						<th scope="row"><?php esc_html_e( 'Enable AI summaries', 'kisho-clinical-trials' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[summaries_enabled]"
									value="1"
									<?php checked( ! empty( $s['summaries_enabled'] ) ); ?>
								>
								<?php esc_html_e( 'Generate plain-language summaries via your own LLM API key', 'kisho-clinical-trials' ); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_provider"><?php esc_html_e( 'LLM provider', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<select id="skmctf_provider" name="<?php echo esc_attr( Settings::OPTION ); ?>[provider]">
								<?php foreach ( Settings::PROVIDERS as $prov ) : ?>
									<option
										value="<?php echo esc_attr( $prov ); ?>"
										<?php selected( $s['provider'], $prov ); ?>
									>
										<?php echo esc_html( ucfirst( $prov ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_api_key"><?php esc_html_e( 'API key', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<?php if ( $key_set ) : ?>
								<p style="margin-bottom:8px;">
									<span class="dashicons dashicons-lock" aria-hidden="true"></span>
									<strong><?php esc_html_e( 'A key is saved.', 'kisho-clinical-trials' ); ?></strong>
									<?php esc_html_e( 'Leave the field below empty to keep it unchanged.', 'kisho-clinical-trials' ); ?>
								</p>
								<p style="margin-bottom:8px;">
									<label>
										<input
											type="checkbox"
											name="<?php echo esc_attr( Settings::OPTION ); ?>[clear_api_key]"
											value="1"
										>
										<?php esc_html_e( 'Clear saved key', 'kisho-clinical-trials' ); ?>
									</label>
								</p>
							<?php endif; ?>
							<input
								type="password"
								id="skmctf_api_key"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[api_key]"
								value=""
								autocomplete="new-password"
								class="regular-text"
								placeholder="<?php echo $key_set ? esc_attr__( 'Enter a new key to replace', 'kisho-clinical-trials' ) : esc_attr__( 'Paste your API key', 'kisho-clinical-trials' ); ?>"
							>
							<p class="description">
								<?php esc_html_e( 'Your key is stored in the WordPress database (autoload disabled) and is never displayed here. Treat your site database as you would any credential store.', 'kisho-clinical-trials' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="skmctf_model"><?php esc_html_e( 'Model name (optional)', 'kisho-clinical-trials' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="skmctf_model"
								name="<?php echo esc_attr( Settings::OPTION ); ?>[model]"
								value="<?php echo esc_attr( $s['model'] ); ?>"
								class="regular-text"
								placeholder="<?php esc_attr_e( 'e.g. claude-3-5-haiku-latest', 'kisho-clinical-trials' ); ?>"
							>
							<p class="description"><?php esc_html_e( 'Leave blank to use the provider default.', 'kisho-clinical-trials' ); ?></p>
						</td>
					</tr>

				</table>

				<?php submit_button( __( 'Save Settings', 'kisho-clinical-trials' ) ); ?>

			</form><!-- /settings form -->
		</div><!-- /main -->

		<!-- =====================================================================
			Sidebar
			===================================================================== -->
		<div style="width:280px;flex-shrink:0;">

			<!-- ── Sync Status ─────────────────────────────────────────── -->
			<div class="postbox" style="padding:12px 16px;margin-bottom:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Sync Status', 'kisho-clinical-trials' ); ?></h3>

				<?php if ( ! empty( $last['t'] ) ) : ?>
					<p style="margin:0 0 8px;">
						<strong><?php esc_html_e( 'Last sync:', 'kisho-clinical-trials' ); ?></strong><br>
						<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['t'] ) ); ?>
					</p>
					<?php if ( isset( $last['fetched'] ) ) : ?>
						<p style="margin:0 0 8px;">
							<?php
							printf(
								/* translators: %d: number of trials fetched */
								esc_html__( 'Fetched: %d trials', 'kisho-clinical-trials' ),
								(int) $last['fetched']
							);
							?>
						</p>
					<?php endif; ?>
					<?php if ( isset( $last['upserted'] ) ) : ?>
						<p style="margin:0 0 8px;">
							<?php
							printf(
								/* translators: %d: number of trials upserted */
								esc_html__( 'Upserted: %d', 'kisho-clinical-trials' ),
								(int) $last['upserted']
							);
							?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<p style="margin:0 0 8px;"><?php esc_html_e( 'No sync has run yet.', 'kisho-clinical-trials' ); ?></p>
				<?php endif; ?>

				<?php if ( '' !== $error ) : ?>
					<p style="color:#d63638;margin:0 0 8px;">
						<strong><?php esc_html_e( 'Last error:', 'kisho-clinical-trials' ); ?></strong><br>
						<?php echo esc_html( $error ); ?>
					</p>
				<?php endif; ?>

				<!-- Sync-now is a SEPARATE form — not nested inside settings form -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Sync_Now_Controller::ACTION ); ?>">
					<?php wp_nonce_field( Sync_Now_Controller::ACTION ); ?>
					<?php
					submit_button(
						__( 'Sync now', 'kisho-clinical-trials' ),
						'secondary',
						'skmctf_sync_now_btn',
						false
					);
					?>
				</form>
			</div>

			<!-- ── Maintenance ─────────────────────────────────────────── -->
			<div class="postbox" style="padding:12px 16px;margin-bottom:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Maintenance', 'kisho-clinical-trials' ); ?></h3>

				<p style="margin:0 0 8px;">
					<?php esc_html_e( 'Remove trials that no longer match your current conditions. This re-checks ClinicalTrials.gov, shows you what would be removed, and only deletes after you confirm.', 'kisho-clinical-trials' ); ?>
				</p>

				<?php $skmctf_has_conditions = ! empty( Settings::conditions() ); ?>

				<?php if ( ! $skmctf_has_conditions ) : ?>
					<p style="color:#646970;margin:0;">
						<?php esc_html_e( 'Add at least one condition to use cleanup.', 'kisho-clinical-trials' ); ?>
					</p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Cleanup_Controller::ACTION_PREVIEW ); ?>">
						<?php wp_nonce_field( Cleanup_Controller::ACTION_PREVIEW ); ?>
						<?php submit_button( __( 'Preview cleanup', 'kisho-clinical-trials' ), 'secondary', 'skmctf_cleanup_preview_btn', false ); ?>
					</form>

					<?php
					$skmctf_cleanup  = isset( $_GET['skmctf_cleanup'] ) ? sanitize_key( wp_unslash( $_GET['skmctf_cleanup'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$skmctf_snapshot = get_transient( Cleanup_Controller::TRANSIENT );
					if ( 'preview' === $skmctf_cleanup && is_array( $skmctf_snapshot ) ) :
						$skmctf_count = count( $skmctf_snapshot );
						?>
						<?php if ( 0 === $skmctf_count ) : ?>
							<p style="margin:8px 0 0;"><?php esc_html_e( 'No off-condition trials found.', 'kisho-clinical-trials' ); ?></p>
						<?php else : ?>
							<p style="margin:8px 0 4px;"><strong>
								<?php
								printf(
									/* translators: %d: number of trials that would be deleted */
									esc_html( _n( '%d trial would be deleted:', '%d trials would be deleted:', $skmctf_count, 'kisho-clinical-trials' ) ),
									(int) $skmctf_count
								);
								?>
							</strong></p>
							<ul style="margin:0 0 8px;max-height:160px;overflow:auto;font-size:12px;">
								<?php foreach ( array_slice( $skmctf_snapshot, 0, 20 ) as $skmctf_nct ) : ?>
									<li><?php echo esc_html( $skmctf_nct ); ?></li>
								<?php endforeach; ?>
								<?php if ( $skmctf_count > 20 ) : ?>
									<li>
										<?php
										printf(
											/* translators: %d: number of additional trials not listed */
											esc_html__( '…and %d more', 'kisho-clinical-trials' ),
											(int) ( $skmctf_count - 20 )
										);
										?>
									</li>
								<?php endif; ?>
							</ul>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
								onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete these trials? This cannot be undone.', 'kisho-clinical-trials' ) ); ?>');">
								<input type="hidden" name="action" value="<?php echo esc_attr( Cleanup_Controller::ACTION_CONFIRM ); ?>">
								<?php wp_nonce_field( Cleanup_Controller::ACTION_CONFIRM ); ?>
								<?php
								submit_button(
									sprintf(
										/* translators: %d: number of trials to delete */
										_n( 'Delete %d trial', 'Delete %d trials', $skmctf_count, 'kisho-clinical-trials' ),
										(int) $skmctf_count
									),
									'delete',
									'skmctf_cleanup_confirm_btn',
									false
								);
								?>
							</form>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<!-- ── SKM Digital card ─────────────────────────────────────── -->
			<div class="postbox" style="padding:12px 16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'About this plugin', 'kisho-clinical-trials' ); ?></h3>
				<p style="margin:0 0 8px;">
					<?php esc_html_e( 'Built by SKM Digital — custom rare disease tools, Salesforce integrations, and headless builds for PAGs.', 'kisho-clinical-trials' ); ?>
				</p>
				<p style="margin:0;">
					<a href="<?php echo esc_url( 'https://skm.digital' ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'skm.digital', 'kisho-clinical-trials' ); ?>
					</a>
				</p>
			</div>

		</div><!-- /sidebar -->

	</div><!-- /wrap-inner -->
</div><!-- /wrap -->
