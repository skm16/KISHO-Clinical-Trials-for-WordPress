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

defined( 'ABSPATH' ) || exit;

if ( empty( $locations ) || ! is_array( $locations ) ) {
	return;
}

// Collect valid map points when map is requested.
$map_points = [];

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
		$label    = $facility ?: $city;
		if ( $facility && $city ) {
			$label .= ' — ' . $city;
		}
		$map_points[] = [
			'lat'   => $lat,
			'lng'   => $lng,
			'title' => esc_html( $label ),
		];
	}

	if ( ! empty( $map_points ) ) {
		\SKMCTF\Frontend\Assets::enqueue_map();

		$osm_attribution = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

		wp_localize_script(
			\SKMCTF\Frontend\Assets::MAP_SCRIPT_HANDLE,
			'skmctfMap',
			[
				'points'      => $map_points,
				'imagePath'   => SKMCTF_URL . 'assets/lib/leaflet/images/',
				'attribution' => $osm_attribution,
			]
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
		aria-label="<?php esc_attr_e( 'Trial locations map', 'kisho-clinical-trials' ); ?>">
	</div>
	<?php endif; ?>

	<ul class="skmctf-trial__locations-list">
		<?php foreach ( $locations as $loc ) :
			if ( ! is_array( $loc ) ) {
				continue;
			}
			$facility = ! empty( $loc['facility'] ) ? $loc['facility'] : '';
			$city     = ! empty( $loc['city'] )     ? $loc['city']     : '';
			$state    = ! empty( $loc['state'] )    ? $loc['state']    : '';
			$country  = ! empty( $loc['country'] )  ? $loc['country']  : '';
			$loc_status = ! empty( $loc['status'] ) ? $loc['status']   : '';

			$address_parts = array_filter( [ $city, $state, $country ] );
			if ( ! $facility && ! $address_parts ) {
				continue;
			}
		?>
		<li class="skmctf-trial__location">
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
</section>
