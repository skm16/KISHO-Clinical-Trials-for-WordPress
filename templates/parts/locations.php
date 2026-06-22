<?php
/**
 * Locations partial for the single trial view.
 *
 * Expected variables (set by caller):
 *   array $locations  Array of location objects (facility, city, state, country, status, lat, lng).
 *   bool  $show_map   Whether to render the map container.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template-scope variables injected by caller; not global assignments.
defined( 'ABSPATH' ) || exit;

if ( empty( $locations ) || ! is_array( $locations ) ) {
	return;
}

// Enqueue frontend assets (filters.js + frontend.css) so they load on single pages.
\SKMCTF\Frontend\Assets::enqueue();

// Collect valid map points when map is requested.
$map_points = array();

if ( $show_map ) {
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
		if ( 0.0 === $lat && 0.0 === $lng ) {
			continue;
		}
		$facility = sanitize_text_field( $loc['facility'] ?? '' );
		$city     = sanitize_text_field( $loc['city'] ?? '' );
		$label    = $facility ? $facility : $city;
		if ( $facility && $city ) {
			$label .= ' — ' . $city;
		}
		// Store the RAW label — map.js inserts via textContent (the XSS boundary).
		// HTML-escaping here would embed entities that corrupt rendering in the popup.
		$map_points[] = array(
			'lat'   => $lat,
			'lng'   => $lng,
			'title' => $label,
		);
	}

	if ( ! empty( $map_points ) ) {
		\SKMCTF\Frontend\Assets::enqueue_map();
		$skmctf_map_uid  = wp_unique_id( 'skmctf-map-' );
		$osm_attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
		$skmctf_payload  = array(
			'points' => $map_points,
			'view'   => array(
				'lat'  => '',
				'lng'  => '',
				'zoom' => '',
			),
			'i18n'   => array(),
		);
	}
}
?>
<section class="skmctf-trial__locations" aria-labelledby="skmctf-locations-heading">
	<h2 class="skmctf-trial__section-heading" id="skmctf-locations-heading">
		<?php esc_html_e( 'Locations', 'kisho-clinical-trials' ); ?>
	</h2>

	<?php if ( $show_map && ! empty( $map_points ) ) : ?>
	<div class="skmctf-map skmctf-trial__map"
		role="region"
		aria-label="<?php esc_attr_e( 'Trial locations map', 'kisho-clinical-trials' ); ?>"
		data-skmctf-map
		data-skmctf-data="<?php echo esc_attr( $skmctf_map_uid ); ?>"
		data-skmctf-image-path="<?php echo esc_attr( SKMCTF_URL . 'assets/lib/leaflet/images/' ); ?>"
		data-skmctf-attribution="<?php echo esc_attr( $osm_attribution ); ?>"
		data-skmctf-geolocation="0"></div>
	<script type="application/json" class="skmctf-map-data" id="<?php echo esc_attr( $skmctf_map_uid ); ?>"><?php echo wp_json_encode( $skmctf_payload, JSON_HEX_TAG | JSON_HEX_AMP ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a JSON script element; HEX flags neutralise markup. ?></script>
	<?php endif; ?>

	<?php
	$skmctf_visible = 10;
	$skmctf_index   = 0;
	?>
	<ul class="skmctf-trial__locations-list" data-skmctf-loclist>
		<?php
		foreach ( $locations as $loc ) :
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$facility   = ! empty( $loc['facility'] ) ? $loc['facility'] : '';
			$city       = ! empty( $loc['city'] ) ? $loc['city'] : '';
			$state      = ! empty( $loc['state'] ) ? $loc['state'] : '';
			$country    = ! empty( $loc['country'] ) ? $loc['country'] : '';
			$loc_status = ! empty( $loc['status'] ) ? $loc['status'] : '';

			$address_parts = array_filter( array( $city, $state, $country ) );
			if ( ! $facility && ! $address_parts ) {
				continue;
			}
			$hidden = $skmctf_index >= $skmctf_visible ? ' hidden' : '';
			++$skmctf_index;
			?>
		<li class="skmctf-trial__location"<?php echo esc_attr( $hidden ) ? ' hidden' : ''; ?>>
			<?php if ( $facility ) : ?>
				<span class="skmctf-trial__location-facility"><?php echo esc_html( $facility ); ?></span>
			<?php endif; ?>
			<?php if ( $address_parts ) : ?>
				<span class="skmctf-trial__location-address"><?php echo esc_html( implode( ', ', $address_parts ) ); ?></span>
			<?php endif; ?>
			<?php if ( $loc_status ) : ?>
				<span class="skmctf-trial__location-status"><?php echo esc_html( $loc_status ); ?></span>
			<?php endif; ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php if ( $skmctf_index > $skmctf_visible ) : ?>
		<button type="button" class="skmctf-trial__locations-toggle" data-skmctf-loctoggle aria-expanded="false">
			<?php
			/* translators: %d: total number of locations. */
			printf( esc_html__( 'Show all %d locations', 'kisho-clinical-trials' ), (int) $skmctf_index );
			?>
		</button>
	<?php endif; ?>
</section>
