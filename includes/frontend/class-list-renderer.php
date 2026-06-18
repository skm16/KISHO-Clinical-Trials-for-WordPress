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
				'status'   => '',
				'phase'    => '',
				'state'    => '',
				'per_page' => 20,
				'columns'  => 1,
				'map'      => false,
			),
			$atts
		);

		// Merge any GET params that match filter keys (form submission).
		$get_status = isset( $_GET['skmctf_status'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_phase  = isset( $_GET['skmctf_phase'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_phase'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_state  = isset( $_GET['skmctf_state'] ) ? sanitize_text_field( wp_unslash( $_GET['skmctf_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$get_paged  = isset( $_GET['skmctf_paged'] ) ? max( 1, absint( wp_unslash( $_GET['skmctf_paged'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$filters = array(
			'status'   => $get_status ? $get_status : $atts['status'],
			'phase'    => $get_phase ? $get_phase : $atts['phase'],
			'state'    => $get_state ? $get_state : $atts['state'],
			'per_page' => absint( $atts['per_page'] ),
			'paged'    => $get_paged,
		);

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

						$lat = (float) $loc['lat'];
						$lng = (float) $loc['lng'];

						// Skip zero/invalid coordinates.
						if ( 0.0 === $lat && 0.0 === $lng ) {
							continue;
						}

						// Build popup label: facility + city, escaped.
						$facility = sanitize_text_field( $loc['facility'] ?? '' );
						$city     = sanitize_text_field( $loc['city'] ?? '' );
						$label    = $facility ? $facility : $post_title;
						if ( $city ) {
							$label .= ' — ' . $city;
						}

						$map_points[] = array(
							'lat'   => $lat,
							'lng'   => $lng,
							'title' => esc_html( $label ),
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

		if ( $show_map ) {
			Assets::enqueue_map();

			// OSM attribution (required by OpenStreetMap licence).
			$osm_attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

			wp_localize_script(
				Assets::MAP_SCRIPT_HANDLE,
				'skmctfMap',
				array(
					'points'      => $map_points,
					'imagePath'   => SKMCTF_URL . 'assets/lib/leaflet/images/',
					'attribution' => $osm_attribution,
				)
			);
		}

		// --- Build output ----------------------------------------------------
		ob_start();

		echo '<div class="skmctf-trials-wrap">';

		// Filter form — works with or without JS.
		self::render_filter_form( $filters );

		// Map container — only output when map is enabled AND ≥1 coordinate.
		// No empty container is emitted when there are no valid coordinates
		// (graceful degradation to list-only view).
		if ( $show_map ) {
			echo '<div class="skmctf-map" role="region" aria-label="'
				. esc_attr__( 'Trial locations map', 'kisho-clinical-trials' )
				. '"></div>';
		}

		if ( ! $query->have_posts() ) {
			echo '<p class="skmctf-no-results">'
				. esc_html__( 'No clinical trials match your current filters.', 'kisho-clinical-trials' )
				. '</p>';
		} else {
			$col_class = absint( $atts['columns'] ) > 1
				? ' skmctf-list--cols-' . absint( $atts['columns'] )
				: '';

			echo '<ul class="skmctf-list' . esc_attr( $col_class ) . '">';

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
							'skmctf_status' => $filters['status'],
							'skmctf_phase'  => $filters['phase'],
							'skmctf_state'  => $filters['state'],
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
	 * Render the no-JS-compatible filter form.
	 *
	 * @param array<string,mixed> $current Current filter values.
	 * @return void
	 */
	private static function render_filter_form( array $current ): void {
		$statuses = get_terms(
			array(
				'taxonomy'   => Trial_Taxonomies::STATUS,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);
		$phases   = get_terms(
			array(
				'taxonomy'   => Trial_Taxonomies::PHASE,
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);

		$statuses = is_wp_error( $statuses ) ? array() : (array) $statuses;
		$phases   = is_wp_error( $phases ) ? array() : (array) $phases;
		?>
		<form method="get" class="skmctf-filters" data-skmctf-filters>
			<fieldset class="skmctf-filters__fieldset">
				<legend class="skmctf-filters__legend screen-reader-text">
					<?php esc_html_e( 'Filter clinical trials', 'kisho-clinical-trials' ); ?>
				</legend>

				<?php if ( $statuses ) : ?>
				<div class="skmctf-filters__group">
					<label for="skmctf-filter-status" class="skmctf-filters__label">
						<?php esc_html_e( 'Status', 'kisho-clinical-trials' ); ?>
					</label>
					<select id="skmctf-filter-status" name="skmctf_status" class="skmctf-filters__select">
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
					<label for="skmctf-filter-phase" class="skmctf-filters__label">
						<?php esc_html_e( 'Phase', 'kisho-clinical-trials' ); ?>
					</label>
					<select id="skmctf-filter-phase" name="skmctf_phase" class="skmctf-filters__select">
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

				<div class="skmctf-filters__group">
					<label for="skmctf-filter-state" class="skmctf-filters__label">
						<?php esc_html_e( 'State', 'kisho-clinical-trials' ); ?>
					</label>
					<input
						type="text"
						id="skmctf-filter-state"
						name="skmctf_state"
						class="skmctf-filters__input"
						value="<?php echo esc_attr( $current['state'] ); ?>"
						placeholder="<?php esc_attr_e( 'e.g. MA', 'kisho-clinical-trials' ); ?>"
						maxlength="50"
					/>
				</div>

				<div class="skmctf-filters__group skmctf-filters__group--submit">
					<button type="submit" class="skmctf-filters__submit">
						<?php esc_html_e( 'Filter', 'kisho-clinical-trials' ); ?>
					</button>
					<?php if ( $current['status'] || $current['phase'] || $current['state'] ) : ?>
						<a href="<?php echo esc_url( remove_query_arg( array( 'skmctf_status', 'skmctf_phase', 'skmctf_state', 'skmctf_paged' ) ) ); ?>" class="skmctf-filters__reset">
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
