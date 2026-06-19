/**
 * Clinical Trials Map — initialises a Leaflet/OSM map from localised data.
 *
 * Expects: window.skmctfMap = {
 *   points      : [ { lat, lng, title, url }, … ],
 *   imagePath   : 'http://…/assets/lib/leaflet/images/',
 *   attribution : '&copy; …',
 *   view        : { lat, lng, zoom },   // optional default center/zoom
 *   geolocation : false                 // optional — enable "Find trials near me"
 * }
 *
 * Bundled Leaflet 1.9.4 (no CDN). Marker images served from the same
 * local path to avoid broken-icon URLs.
 *
 * Geolocation is 100% client-side; no coordinates are sent to the server
 * or stored anywhere. User-facing strings are English in v1.1.0 (JS is not
 * localised via wp_localize_script in this release).
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

( function () {
	'use strict';

	// Guard: do nothing if Leaflet or localised data is missing.
	if ( typeof L === 'undefined' ) {
		return;
	}

	var data = window.skmctfMap;

	if ( ! data ) {
		return;
	}

	// ------------------------------------------------------------------
	// Read optional config added in v1.1.0.
	// ------------------------------------------------------------------
	var view       = data.view || {};
	var geoEnabled = !! data.geolocation;

	// ------------------------------------------------------------------
	// Fix Leaflet's default marker icon path to our bundled images.
	// Without this, markers show broken images because Leaflet tries to
	// resolve the images relative to leaflet.js, which doesn't work when
	// the file is loaded from an arbitrary plugin URL.
	// ------------------------------------------------------------------
	if ( data.imagePath ) {
		// Delete the auto-detected _getIconUrl so our options take over.
		delete L.Icon.Default.prototype._getIconUrl;

		L.Icon.Default.mergeOptions( {
			iconUrl:       data.imagePath + 'marker-icon.png',
			iconRetinaUrl: data.imagePath + 'marker-icon-2x.png',
			shadowUrl:     data.imagePath + 'marker-shadow.png',
		} );
	}

	// ------------------------------------------------------------------
	// Find the map container.
	// ------------------------------------------------------------------
	var container = document.querySelector( '.skmctf-map' );
	if ( ! container ) {
		return;
	}

	// ------------------------------------------------------------------
	// Init Leaflet map.
	// ------------------------------------------------------------------
	var map = L.map( container );

	// OSM tile layer — attribution is REQUIRED by the OSM licence.
	var attribution = data.attribution ||
		'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

	L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution: attribution,
		maxZoom:     19,
	} ).addTo( map );

	// ------------------------------------------------------------------
	// Add markers and collect bounds.
	// ------------------------------------------------------------------
	var bounds = [];

	if ( Array.isArray( data.points ) ) {
		data.points.forEach( function ( point ) {
			// Validate coordinates (must be finite numbers).
			var lat = parseFloat( point.lat );
			var lng = parseFloat( point.lng );

			if ( ! isFinite( lat ) || ! isFinite( lng ) ) {
				return;
			}

			var latlng = L.latLng( lat, lng );
			bounds.push( latlng );

			// Build popup content — title and url are pre-escaped server-side.
			// We assemble them as text nodes here for extra XSS safety.
			var popupEl = document.createElement( 'div' );

			if ( point.url && point.title ) {
				var link = document.createElement( 'a' );
				link.href        = point.url;
				link.textContent = point.title;
				link.target      = '_blank';
				link.rel         = 'noopener noreferrer';
				popupEl.appendChild( link );
			} else if ( point.title ) {
				popupEl.textContent = point.title;
			}

			var marker = L.marker( latlng );
			marker.bindPopup( popupEl );
			marker.addTo( map );
		} );
	}

	// ------------------------------------------------------------------
	// Set initial map view — precedence chain:
	//   1. Explicit default center/zoom from config (att → global setting).
	//   2. Single marker → setView at zoom 10.
	//   3. Multiple markers → fitBounds with padding.
	// ------------------------------------------------------------------
	if (
		view.lat !== '' && view.lat != null &&
		view.lng !== '' && view.lng != null
	) {
		var z = ( view.zoom !== '' && view.zoom != null ) ? parseInt( view.zoom, 10 ) : 8;
		map.setView( [ parseFloat( view.lat ), parseFloat( view.lng ) ], z );
	} else if ( bounds.length === 1 ) {
		map.setView( bounds[ 0 ], 10 );
	} else if ( bounds.length > 1 ) {
		map.fitBounds( L.latLngBounds( bounds ), { padding: [ 40, 40 ] } );
	} else {
		// Fallback: no markers and no configured default view.
		// Show a world-overview so Leaflet is never left in an uninitialised state.
		map.setView( [ 20, 0 ], 2 );
	}

	// ------------------------------------------------------------------
	// Geolocation button handler (v1.1.0).
	// Runs only when the admin has enabled geolocation for this instance.
	// The visitor's coordinates are used only in the browser (setView) and
	// are never sent to the server or stored anywhere.
	// ------------------------------------------------------------------
	if ( geoEnabled ) {
		var geoBtn    = document.querySelector( '[data-skmctf-geo]' );
		var geoStatus = document.querySelector( '[data-skmctf-geo-status]' );

		if ( geoBtn && geoStatus ) {
			geoBtn.addEventListener( 'click', function () {

				// Guard: API not available (non-HTTPS or old browser).
				if ( ! navigator.geolocation ) {
					geoStatus.textContent = 'Location is not available in this browser.';
					return;
				}

				navigator.geolocation.getCurrentPosition(

					// Success callback.
					function ( pos ) {
						var lat = pos.coords.latitude;
						var lng = pos.coords.longitude;

						// Re-center the map on the visitor's location.
						map.setView( [ lat, lng ], 9 );

						// Drop a visually-distinct "you are here" circle marker.
						var youAreHerePopup = document.createElement( 'div' );
						youAreHerePopup.textContent = 'You are here';

						L.circleMarker(
							[ lat, lng ],
							{
								radius:    8,
								className: 'skmctf-you-are-here',
							}
						).bindPopup( youAreHerePopup ).addTo( map );

						geoStatus.textContent = 'Centered on your location.';
					},

					// Error callback (denied, unavailable, or timeout).
					function () {
						geoStatus.textContent = 'Location unavailable — showing default view.';
					}
				);
			} );
		}
	}

}() );
