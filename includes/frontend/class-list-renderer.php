<?php
/**
 * Renders the clinical trials list with a progressive-enhancement filter form.
 *
 * Output strategy:
 *   - All output escaped except wp_kses_post( $plain_summary ) which is
 *     authored content stored through WP's sanitise pipeline.
 *   - Filter form uses method="get" so it works without JavaScript.
 *   - filters.js is enqueued only when render() actually runs (conditional).
 *
 * @package SKMCTF\Frontend
 * @license GPL-2.0-or-later
 */

namespace SKMCTF\Frontend;

use SKMCTF\Admin\Settings;
use SKMCTF\Data\Trial_Repository;
use SKMCTF\Post_Types\Trial_Meta;
use SKMCTF\Post_Types\Trial_Taxonomies;
use SKMCTF\Support\Template_Loader;

/**
 * Renders the clinical trials list component for the shortcode and block.
 */
final class List_Renderer {

	/**
	 * Render the full trials list component and return escaped HTML.
	 *
	 * @param array<string,mixed> $atts Shortcode / block attributes.
	 * @return string HTML ready for output.
	 */
	public static function render( array $atts ): string {
		// --- Normalise atts --------------------------------------------------
		$atts = array_merge(
			array(
				'status'       => '',
				'phase'        => '',
				'state'        => '',
				'country'      => '',
				'per_page'     => 20,
				'columns'      => 1,
				'map'          => false,
				'default_lat'  => '',
				'default_lng'  => '',
				'default_zoom' => '',
				'geolocation'  => false,
			),
			$atts
		);

		// Merge any GET params that match filter keys (form submission).
		$get_status  = isset( $_GET['skmctf_status'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_phase   = isset( $_GET['skmctf_phase'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_phase'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_state   = isset( $_GET['skmctf_state'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_country = isset( $_GET['skmctf_country'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_country'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_paged   = isset( $_GET['skmctf_paged'] ) ? max( 1, absint( wp_unslash( $_GET['skmctf_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Resolve map-view precedence: explicit att → global setting → default ('').
		$default_lat  = '' !== (string) $atts['default_lat'] ? Settings::sanitize_lat( $atts['default_lat'] ) : Settings::default_lat();
		$default_lng  = '' !== (string) $atts['default_lng'] ? Settings::sanitize_lng( $atts['default_lng'] ) : Settings::default_lng();
		$default_zoom = '' !== (string) $atts['default_zoom'] ? Settings::sanitize_zoom( $atts['default_zoom'] ) : Settings::default_zoom();

		// Geolocation: explicit att (any truthy value) OR global setting.
		$geo = ! empty( $atts['geolocation'] ) || Settings::geolocation_enabled();

		// Distinguish "param absent" (use att default) from "param present but
		// empty" (honour the cleared filter) via isset() — mirrors the GET reads
		// above. A bare truthiness check would silently re-apply the att default
		// when the user selects "All …" / clears the field.
		$filters = array(
			'status'   => isset( $_GET['skmctf_status'] ) ? $get_status : $atts['status'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'phase'    => isset( $_GET['skmctf_phase'] ) ? $get_phase : $atts['phase'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'state'    => isset( $_GET['skmctf_state'] ) ? $get_state : $atts['state'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'country'  => isset( $_GET['skmctf_country'] ) ? $get_country : $atts['country'], // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'per_page' => absint( $atts['per_page'] ),
			'paged'    => $get_paged,
		);

		// Drop a state filter that does not belong to the selected country — e.g.
		// a stale ?skmctf_state=Connecticut left over when switching to Canada —
		// so the query never filters to an impossible (empty) result. The form
		// applies the same rule when rendering its dropdown.
		if ( '' !== (string) $filters['state'] && '' !== (string) $filters['country'] ) {
			$states_for_country = ( new Trial_Repository() )->states_by_country();
			$allowed_states     = $states_for_country[ $filters['country'] ] ?? array();
			if ( ! in_array( (string) $filters['state'], $allowed_states, true ) ) {
				$filters['state'] = '';
			}
		}

		// --- Query -----------------------------------------------------------
		$query = Trials_Query::query( $filters );

		// --- Settings --------------------------------------------------------
		$single_pages   = Settings::single_pages_enabled();
		$display_fields = Settings::display_fields();
		$attribution    = Settings::attribution_enabled();

		// Map is enabled only when the block/shortcode requests it AND the
		// global setting is on (or the block/shortcode explicitly enables it).
		// Per-block showMap overrides the global default: if the block passes
		// map=true the map shows even if the global default is off; the global
		// setting acts as the default when the attribute is absent/false.
		$map_requested = ! empty( $atts['map'] ) || Settings::show_map();

		// --- Enqueue assets --------------------------------------------------
		Assets::enqueue();

		// --- Collect map points (when map is requested and query has posts) ----
		$map_points = array();

		// Geographic filters constrain WHICH locations are plotted, not just which
		// trials match. A multinational trial can match a country/state filter on
		// the strength of one site; without this, the map would plot all of that
		// trial's worldwide sites. When a country and/or state filter is active we
		// plot only the locations matching it, so the map agrees with the filter.
		// Comparison is case-insensitive and trimmed, mirroring the taxonomy term
		// match used by Trials_Query. Empty filter => no constraint on that field.
		$filter_country = isset( $filters['country'] ) ? strtolower( trim( (string) $filters['country'] ) ) : '';
		$filter_state   = isset( $filters['state'] ) ? strtolower( trim( (string) $filters['state'] ) ) : '';

		if ( $map_requested && $query->have_posts() ) {
			$location_key = Trial_Meta::KEYS['locations'];

			while ( $query->have_posts() ) {
				$query->the_post();
				global $post;

				$post_id    = $post->ID;
				$post_url   = get_permalink( $post_id );
				$post_title = get_the_title( $post_id );

				$locations = get_post_meta( $post_id, $location_key, true );

				if ( is_array( $locations ) ) {
					foreach ( $locations as $loc ) {
						if (
							! is_array( $loc ) ||
							! isset( $loc['lat'], $loc['lng'] ) ||
							null === $loc['lat'] ||
							null === $loc['lng']
						) {
							continue;
						}

						// Respect the active geographic filter: skip locations that
						// do not match the filtered country/state.
						if (
							'' !== $filter_country &&
							strtolower( trim( (string) ( $loc['country'] ?? '' ) ) ) !== $filter_country
						) {
							continue;
						}
						if (
							'' !== $filter_state &&
							strtolower( trim( (string) ( $loc['state'] ?? '' ) ) ) !== $filter_state
						) {
							continue;
						}

						$lat = (float) $loc['lat'];
						$lng = (float) $loc['lng'];

						// Skip zero/invalid coordinates.
						if ( 0.0 === $lat && 0.0 === $lng ) {
							continue;
						}

						// Build popup label: facility + city.
						// NOTE: store the RAW label (sanitised, but NOT HTML-escaped).
						// map.js inserts it via Node.textContent, which is the XSS
						// boundary, and the payload travels as JSON. HTML-escaping here
						// would embed entities (e.g. &#034;) that the browser decodes on
						// read, corrupting the JSON — so escaping must NOT happen at this
						// layer.
						$facility = sanitize_text_field( $loc['facility'] ?? '' );
						$city     = sanitize_text_field( $loc['city'] ?? '' );
						$label    = $facility ? $facility : $post_title;
						if ( $city ) {
							$label .= ' — ' . $city;
						}

						$map_points[] = array(
							'lat'   => $lat,
							'lng'   => $lng,
							'title' => $label,
							'url'   => esc_url( (string) $post_url ),
						);
					}
				}
			}

			wp_reset_postdata();
			$query->rewind_posts();
		}

		// Only render map when at least one valid coordinate exists.
		$show_map = $map_requested && ! empty( $map_points );

		// Per-instance map payload. Each render emits its OWN data so that
		// multiple map blocks / shortcodes on one page never clobber a shared
		// global. The bulky, quote-prone data (points, view, i18n) travels in a
		// dedicated <script type="application/json"> element rather than an HTML
		// attribute: a JSON string placed in an attribute is corrupted when the
		// browser entity-decodes it on read (e.g. a facility name containing a
		// double quote breaks JSON.parse). Script-element text is not subject to
		// that attribute round-trip, so the JSON survives intact. Only short,
		// simple strings remain as data-* attributes on the container.
		$map_uid        = '';
		$map_data_attrs = '';
		$map_data_json  = '';

		if ( $show_map ) {
			Assets::enqueue_map();

			$map_uid = wp_unique_id( 'skmctf-map-' );

			// OSM attribution (required by OpenStreetMap licence).
			$osm_attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

			// Translated geolocation strings handed to map.js (F7). map.js falls
			// back to identical English literals if any key is missing.
			$map_i18n = array(
				'youAreHere'  => __( 'You are here', 'kisho-clinical-trials' ),
				'centered'    => __( 'Centered on your location.', 'kisho-clinical-trials' ),
				'unavailable' => __( 'Location unavailable — showing default view.', 'kisho-clinical-trials' ),
				'noGeo'       => __( 'Location is not available in this browser.', 'kisho-clinical-trials' ),
			);

			$map_payload = array(
				'points' => $map_points,
				'view'   => array(
					'lat'  => $default_lat,
					'lng'  => $default_lng,
					'zoom' => $default_zoom,
				),
				'i18n'   => $map_i18n,
			);

			// Container attributes: only short, entity-safe scalar strings here.
			$map_data_attrs = ' data-skmctf-map'
				. ' data-skmctf-data="' . esc_attr( $map_uid ) . '"'
				. ' data-skmctf-image-path="' . esc_attr( SKMCTF_URL . 'assets/lib/leaflet/images/' ) . '"'
				. ' data-skmctf-attribution="' . esc_attr( $osm_attribution ) . '"'
				. ' data-skmctf-geolocation="' . ( $geo ? '1' : '0' ) . '"';

			// JSON payload for this instance, inlined in a JSON script element.
			// JSON_HEX_TAG|JSON_HEX_AMP escape '<', '>' and '&' to < etc., so a
			// stray "</script>" (or any markup) inside facility names cannot break
			// out of the script element — defence-in-depth on top of the JSON
			// transport. The data is also read with JSON.parse, which is unaffected
			// by the \uXXXX escaping.
			$map_data_json = '<script type="application/json" class="skmctf-map-data" id="'
				. esc_attr( $map_uid ) . '">'
				. wp_json_encode( $map_payload, JSON_HEX_TAG | JSON_HEX_AMP )
				. '</script>';
		}

		// --- Build output ----------------------------------------------------
		ob_start();

		$skmctf_skin = Theme::skin_class();
		echo '<div class="skmctf-trials-wrap'
			. ( '' !== $skmctf_skin ? ' ' . esc_attr( $skmctf_skin ) : '' )
			. '" ' . Theme::mode_attr() . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- skin class is esc_attr'd; mode_attr() returns a pre-escaped attribute.

		// Filter form — works with or without JS.
		self::render_filter_form( $filters );

		// Map container — only output when map is enabled AND ≥1 coordinate.
		// No empty container is emitted when there are no valid coordinates
		// (graceful degradation to list-only view).
		if ( $show_map ) {
			// Gate geolocation button on HTTPS: navigator.geolocation is unavailable
			// on plain HTTP, so showing the button there produces a broken UX.
			if ( $geo && is_ssl() ) {
				echo '<div class="skmctf-geo">';
				echo '<button type="button" class="skmctf-geo-btn" data-skmctf-geo>'
					. esc_html__( 'Find trials near me', 'kisho-clinical-trials' ) . '</button>';
				echo '<span class="skmctf-geo-status" data-skmctf-geo-status role="status" aria-live="polite"></span>';
				echo '</div>';
			}

			// Per-instance map payload: short scalars live on the container's
			// data-* attributes ($map_data_attrs, pre-escaped above); the bulky
			// JSON (points/view/i18n) lives in the adjacent <script> element
			// ($map_data_json) keyed by $map_uid. Each map block initialises from
			// its own data, so multiple blocks on a page never collide.
			echo '<div class="skmctf-map" role="region" aria-label="'
				. esc_attr__( 'Trial locations map', 'kisho-clinical-trials' )
				. '"' . $map_data_attrs . '></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			echo $map_data_json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contents are wp_json_encode (HTML-safe) inside a JSON script element; markup is static.
		}

		if ( ! $query->have_posts() ) {
			echo '<p class="skmctf-no-results">'
				. esc_html__( 'No clinical trials match your current filters.', 'kisho-clinical-trials' )
				. '</p>';
		} else {
			$view      = Settings::default_view();
			$col_class = ( 'grid' === $view && absint( $atts['columns'] ) > 1 )
				? ' skmctf-list--cols-' . absint( $atts['columns'] )
				: '';

			self::render_view_toggle( $view );

			echo '<ul class="skmctf-list skmctf-list--view-' . esc_attr( $view ) . esc_attr( $col_class )
				. '" data-skmctf-view="' . esc_attr( $view ) . '">';

			while ( $query->have_posts() ) {
				$query->the_post();
				global $post;

				$meta = self::get_meta( $post->ID );

				// Make template variables available to the template file.
				// phpcs:disable WordPress.PHP.DontExtract.extract_extract
				extract(
					array(
						'post'           => $post,
						'meta'           => $meta,
						'display_fields' => $display_fields,
						'single_pages'   => $single_pages,
						'attribution'    => $attribution,
					)
				);
				// phpcs:enable

				try {
					include Template_Loader::locate( 'list-item.php' );
				} catch ( \RuntimeException $e ) {
					// Template missing — fail gracefully.
					echo '<li class="skmctf-card skmctf-card--error">'
						. esc_html__( 'Template error.', 'kisho-clinical-trials' )
						. '</li>';
				}
			}

			wp_reset_postdata();

			echo '</ul>';

			// Pagination.
			$pagination = paginate_links(
				array(
					'total'    => $query->max_num_pages,
					'current'  => $get_paged,
					'format'   => '?skmctf_paged=%#%',
					'add_args' => array_filter(
						array(
							'skmctf_status'  => $filters['status'],
							'skmctf_phase'   => $filters['phase'],
							'skmctf_state'   => $filters['state'],
							'skmctf_country' => $filters['country'],
						)
					),
				)
			);
			if ( $pagination ) {
				echo '<nav class="skmctf-pagination" aria-label="' . esc_attr__( 'Clinical trials pages', 'kisho-clinical-trials' ) . '">';
				echo wp_kses_post( $pagination );
				echo '</nav>';
			}
		}

		// --- Attribution footer -----------------------------------------------
		// CT.gov credit is ALWAYS shown (data source attribution).
		// SKM Digital line is only shown when attribution_enabled() is true (off by default).
		// Links are preserved through wp_kses() so the <a> tags render instead of
		// being escaped to literal text by esc_html__(); only safe attrs pass.
		$allowed_links = array(
			'a' => array(
				'href'   => array(),
				'rel'    => array(),
				'target' => array(),
			),
		);

		echo '<p class="skmctf-attribution">';
		echo wp_kses(
			sprintf(
				/* translators: %s: linked "ClinicalTrials.gov" text */
				esc_html__( 'Data from %s', 'kisho-clinical-trials' ),
				'<a href="' . esc_url( 'https://clinicaltrials.gov' ) . '" rel="noopener noreferrer" target="_blank">'
					. esc_html__( 'ClinicalTrials.gov', 'kisho-clinical-trials' )
				. '</a>'
			),
			$allowed_links
		);
		echo '</p>';

		if ( $attribution ) {
			echo '<p class="skmctf-attribution skmctf-attribution--skm">';
			echo wp_kses(
				sprintf(
					/* translators: %s: linked "SKM Digital" text */
					esc_html__( 'Trial display by %s', 'kisho-clinical-trials' ),
					'<a href="' . esc_url( 'https://skm.digital' ) . '" rel="noopener">'
						. esc_html__( 'SKM Digital', 'kisho-clinical-trials' )
					. '</a>'
				),
				$allowed_links
			);
			echo '</p>';
		}

		echo '</div><!-- .skmctf-trials-wrap -->';

		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Render the grid/list view toggle.
	 *
	 * Real buttons; the active view is pre-pressed server-side so the correct
	 * view renders without JS. view-toggle.js adds switching + persistence.
	 *
	 * @param string $active 'grid' | 'list'.
	 * @return void
	 */
	private static function render_view_toggle( string $active ): void {
		$buttons = array(
			'grid' => __( 'Grid view', 'kisho-clinical-trials' ),
			'list' => __( 'List view', 'kisho-clinical-trials' ),
		);
		echo '<div class="skmctf-view-toggle" role="group" data-skmctf-default-view="'
			. esc_attr( $active ) . '" aria-label="'
			. esc_attr__( 'Choose how trials are displayed', 'kisho-clinical-trials' ) . '">';
		foreach ( $buttons as $view => $label ) {
			$is = $view === $active;
			printf(
				'<button type="button" class="skmctf-view-toggle__btn%1$s" data-skmctf-view-btn="%2$s" aria-pressed="%3$s">'
					. '<span class="skmctf-view-toggle__icon skmctf-view-toggle__icon--%2$s" aria-hidden="true"></span>'
					. '<span class="skmctf-view-toggle__label">%4$s</span>'
					. '</button>',
				$is ? ' is-active' : '',
				esc_attr( $view ),
				$is ? 'true' : 'false',
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	/**
	 * Render the no-JS-compatible filter form.
	 *
	 * @param array<string,mixed> $current Current filter values.
	 * @return void
	 */
	private static function render_filter_form( array $current ): void {
		$statuses  = get_terms(
			array(
				'taxonomy'   => Trial_Taxonomies::STATUS,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$phases    = get_terms(
			array(
				'taxonomy'   => Trial_Taxonomies::PHASE,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$countries = get_terms(
			array(
				'taxonomy'   => Trial_Taxonomies::COUNTRY,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$statuses  = is_wp_error( $statuses ) ? array() : (array) $statuses;
		$phases    = is_wp_error( $phases ) ? array() : (array) $phases;
		$countries = is_wp_error( $countries ) ? array() : (array) $countries;

		// State/Province options are scoped to the selected country (the map is
		// derived from location meta, since trial_state terms carry no country).
		// With no country selected, offer the union of all states/provinces.
		$states_by_country = ( new Trial_Repository() )->states_by_country();
		$current_country   = (string) ( $current['country'] ?? '' );
		if ( '' !== $current_country && isset( $states_by_country[ $current_country ] ) ) {
			$state_options = $states_by_country[ $current_country ];
		} else {
			$all = array();
			foreach ( $states_by_country as $list ) {
				foreach ( $list as $s ) {
					$all[ $s ] = true;
				}
			}
			$state_options = array_keys( $all );
			sort( $state_options );
		}

		// Unique per-instance prefix so multiple forms on one page never share
		// element IDs (which would break label/control association). CSS and JS
		// target classes, so prefixed IDs are safe.
		$uid = wp_unique_id( 'skmctf-' );
		?>
		<form method="get" class="skmctf-filters" data-skmctf-filters>
			<fieldset class="skmctf-filters__fieldset">
				<legend class="skmctf-filters__legend screen-reader-text">
					<?php esc_html_e( 'Filter clinical trials', 'kisho-clinical-trials' ); ?>
				</legend>

				<?php if ( $statuses ) : ?>
				<div class="skmctf-filters__group">
					<label for="<?php echo esc_attr( $uid ); ?>-status" class="skmctf-filters__label">
						<?php esc_html_e( 'Status', 'kisho-clinical-trials' ); ?>
					</label>
					<select id="<?php echo esc_attr( $uid ); ?>-status" name="skmctf_status" class="skmctf-filters__select">
						<option value=""><?php esc_html_e( 'All statuses', 'kisho-clinical-trials' ); ?></option>
						<?php foreach ( $statuses as $term ) : ?>
							<option value="<?php echo esc_attr( $term->name ); ?>"
								<?php selected( $current['status'], $term->name ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<?php if ( $phases ) : ?>
				<div class="skmctf-filters__group">
					<label for="<?php echo esc_attr( $uid ); ?>-phase" class="skmctf-filters__label">
						<?php esc_html_e( 'Phase', 'kisho-clinical-trials' ); ?>
					</label>
					<select id="<?php echo esc_attr( $uid ); ?>-phase" name="skmctf_phase" class="skmctf-filters__select">
						<option value=""><?php esc_html_e( 'All phases', 'kisho-clinical-trials' ); ?></option>
						<?php foreach ( $phases as $term ) : ?>
							<option value="<?php echo esc_attr( $term->name ); ?>"
								<?php selected( $current['phase'], $term->name ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<?php if ( $countries ) : ?>
				<div class="skmctf-filters__group">
					<label for="<?php echo esc_attr( $uid ); ?>-country" class="skmctf-filters__label">
						<?php esc_html_e( 'Country', 'kisho-clinical-trials' ); ?>
					</label>
					<select id="<?php echo esc_attr( $uid ); ?>-country" name="skmctf_country" class="skmctf-filters__select">
						<option value=""><?php esc_html_e( 'All countries', 'kisho-clinical-trials' ); ?></option>
						<?php foreach ( $countries as $term ) : ?>
							<option value="<?php echo esc_attr( $term->name ); ?>"
								<?php selected( $current['country'], $term->name ); ?>>
								<?php echo esc_html( $term->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<?php
				// State/Province appears AFTER Country and lists only the states /
				// provinces within the selected country (or the union of all when no
				// country is chosen). A previously-selected state that is not in the
				// current option set is treated as "All" so a country switch never
				// silently filters to an empty result.
				if ( $state_options ) :
					$selected_state = in_array( (string) $current['state'], $state_options, true )
						? (string) $current['state']
						: '';
					?>
				<div class="skmctf-filters__group">
					<label for="<?php echo esc_attr( $uid ); ?>-state" class="skmctf-filters__label">
						<?php esc_html_e( 'State / Province', 'kisho-clinical-trials' ); ?>
					</label>
					<select id="<?php echo esc_attr( $uid ); ?>-state" name="skmctf_state" class="skmctf-filters__select">
						<option value=""><?php esc_html_e( 'All states / provinces', 'kisho-clinical-trials' ); ?></option>
						<?php foreach ( $state_options as $state_name ) : ?>
							<option value="<?php echo esc_attr( $state_name ); ?>"
								<?php selected( $selected_state, $state_name ); ?>>
								<?php echo esc_html( $state_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php endif; ?>

				<div class="skmctf-filters__group skmctf-filters__group--submit">
					<button type="submit" class="skmctf-filters__submit">
						<?php esc_html_e( 'Filter', 'kisho-clinical-trials' ); ?>
					</button>
					<?php if ( $current['status'] || $current['phase'] || $current['state'] || $current['country'] ) : ?>
						<a href="<?php echo esc_url( remove_query_arg( array( 'skmctf_status', 'skmctf_phase', 'skmctf_state', 'skmctf_country', 'skmctf_paged' ) ) ); ?>" class="skmctf-filters__reset">
							<?php esc_html_e( 'Reset filters', 'kisho-clinical-trials' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</fieldset>
		</form>
		<?php
	}

	/**
	 * Load all display meta for a single trial post.
	 *
	 * @param int $post_id WP post ID.
	 * @return array<string,mixed> Keyed by short meta name.
	 */
	private static function get_meta( int $post_id ): array {
		$keys = Trial_Meta::KEYS;
		return array(
			'nct_id'         => get_post_meta( $post_id, $keys['nct_id'], true ),
			'overall_status' => get_post_meta( $post_id, $keys['overall_status'], true ),
			'phase'          => get_post_meta( $post_id, $keys['phase'], true ),
			'conditions'     => get_post_meta( $post_id, $keys['conditions'], true ),
			'lead_sponsor'   => get_post_meta( $post_id, $keys['lead_sponsor'], true ),
			'locations'      => get_post_meta( $post_id, $keys['locations'], true ),
			'brief_summary'  => get_post_meta( $post_id, $keys['brief_summary'], true ),
			'plain_summary'  => get_post_meta( $post_id, $keys['plain_summary'], true ),
			'ct_url'         => get_post_meta( $post_id, $keys['ct_url'], true ),
		);
	}
}
