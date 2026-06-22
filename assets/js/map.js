/**
 * Clinical Trials Map — initialises one Leaflet/OSM map per `.skmctf-map`
 * container, reading each instance's data from its own data attributes so that
 * multiple map blocks / shortcodes on a single page never clobber one another.
 *
 * Each `.skmctf-map` container carries:
 *   data-skmctf-map                  — presence flag
 *   data-skmctf-data                 — id of the sibling JSON <script> element
 *                                      holding { points, view, i18n } for this
 *                                      instance
 *   data-skmctf-image-path           — 'http://…/assets/lib/leaflet/images/'
 *   data-skmctf-attribution          — '&copy; …'
 *   data-skmctf-geolocation          — '1' / '0'  ("Find trials near me")
 *
 * The bulky / quote-prone payload (points, view, i18n) lives in a
 * <script type="application/json" id="…"> element rather than an attribute,
 * because a JSON string placed in an HTML attribute is corrupted when the
 * browser entity-decodes it on read (e.g. a facility name with a double quote
 * breaks JSON.parse). Script-element text is immune to that round-trip.
 *
 * Back-compat: if a container has no JSON payload element but a legacy global
 * window.skmctfMap exists, that global is used instead.
 *
 * Bundled Leaflet 1.9.4 (no CDN). Marker images served from the same local
 * path to avoid broken-icon URLs.
 *
 * Geolocation is 100% client-side; no coordinates are sent to the server or
 * stored anywhere. User-facing strings come from data-skmctf-i18n (translated
 * server-side) with English fallbacks.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

( function () {
	'use strict';

	// Guard: do nothing if Leaflet is missing.
	if ( typeof L === 'undefined' ) {
		return;
	}

	/**
	 * Safely JSON-parse an attribute value.
	 *
	 * @param {string} value Raw attribute string.
	 * @param {*}      fallback Value returned on empty/invalid input.
	 * @returns {*}
	 */
	function parseJSONAttr( value, fallback ) {
		if ( ! value ) {
			return fallback;
		}
		try {
			return JSON.parse( value );
		} catch ( e ) {
			return fallback;
		}
	}

	/**
	 * Fix Leaflet's default marker icon path to our bundled images. Without
	 * this, markers show broken images because Leaflet resolves the images
	 * relative to leaflet.js, which fails from an arbitrary plugin URL.
	 *
	 * Global / one-time: run once for the first container that supplies a path.
	 *
	 * @param {string} imagePath Base URL for Leaflet marker images.
	 */
	var iconPathFixed = false;
	function fixIconPath( imagePath ) {
		if ( iconPathFixed || ! imagePath ) {
			return;
		}
		// Delete the auto-detected _getIconUrl so our options take over.
		delete L.Icon.Default.prototype._getIconUrl;

		L.Icon.Default.mergeOptions( {
			iconUrl:       imagePath + 'marker-icon.png',
			iconRetinaUrl: imagePath + 'marker-icon-2x.png',
			shadowUrl:     imagePath + 'marker-shadow.png',
		} );

		iconPathFixed = true;
	}

	/**
	 * Initialise a single map container from its own data.
	 *
	 * @param {HTMLElement} container A `.skmctf-map` element.
	 */
	function initMap( container ) {
		// Legacy global as a back-compat fallback only.
		var legacy = window.skmctfMap || {};

		// Resolve this instance's JSON payload from the sibling <script> element
		// referenced by data-skmctf-data. Falls back to the legacy global when no
		// payload element is present.
		var payload    = null;
		var dataId     = container.getAttribute( 'data-skmctf-data' );
		var dataScript = dataId ? document.getElementById( dataId ) : null;
		if ( dataScript ) {
			payload = parseJSONAttr( dataScript.textContent, null );
		}

		var points = ( payload && Array.isArray( payload.points ) )
			? payload.points
			: ( legacy.points || [] );

		var view = ( payload && payload.view ) ? payload.view : ( legacy.view || {} );

		var i18n = ( payload && payload.i18n ) ? payload.i18n : {};

		var imagePath = container.getAttribute( 'data-skmctf-image-path' ) || legacy.imagePath || '';

		var attribution = container.getAttribute( 'data-skmctf-attribution' ) || legacy.attribution ||
			'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

		var geoEnabled;
		if ( container.hasAttribute( 'data-skmctf-geolocation' ) ) {
			geoEnabled = '1' === container.getAttribute( 'data-skmctf-geolocation' );
		} else {
			geoEnabled = !! legacy.geolocation;
		}

		// English fallbacks must EXACTLY match the originals so nothing
		// regresses when i18n is absent.
		var t = {
			youAreHere:  i18n.youAreHere  || 'You are here',
			centered:    i18n.centered    || 'Centered on your location.',
			unavailable: i18n.unavailable || 'Location unavailable — showing default view.',
			noGeo:       i18n.noGeo       || 'Location is not available in this browser.',
		};

		fixIconPath( imagePath );

		// ------------------------------------------------------------------
		// Init Leaflet map.
		// ------------------------------------------------------------------
		var map = L.map( container );

		// OSM tile layer — attribution is REQUIRED by the OSM licence.
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			attribution: attribution,
			maxZoom:     19,
		} ).addTo( map );

		// ------------------------------------------------------------------
		// Add markers and collect bounds.
		// ------------------------------------------------------------------
		var bounds = [];

		if ( Array.isArray( points ) ) {
			points.forEach( function ( point ) {
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
		// The button/status are scoped to THIS map's wrapper so each map wires
		// up its own controls. The visitor's coordinates are used only in the
		// browser (setView) and are never sent to the server or stored.
		// ------------------------------------------------------------------
		if ( geoEnabled ) {
			var wrap      = container.closest( '.skmctf-trials-wrap' ) || document;
			var geoBtn    = wrap.querySelector( '[data-skmctf-geo]' );
			var geoStatus = wrap.querySelector( '[data-skmctf-geo-status]' );

			if ( geoBtn && geoStatus ) {
				geoBtn.addEventListener( 'click', function () {

					// Guard: API not available (non-HTTPS or old browser).
					if ( ! navigator.geolocation ) {
						geoStatus.textContent = t.noGeo;
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
							youAreHerePopup.textContent = t.youAreHere;

							L.circleMarker(
								[ lat, lng ],
								{
									radius:    8,
									className: 'skmctf-you-are-here',
								}
							).bindPopup( youAreHerePopup ).addTo( map );

							geoStatus.textContent = t.centered;
						},

						// Error callback (denied, unavailable, or timeout).
						function () {
							geoStatus.textContent = t.unavailable;
						}
					);
				} );
			}
		}
	}

	/**
	 * Boot — initialise every map container on the page independently.
	 */
	function boot() {
		var containers = document.querySelectorAll( '.skmctf-map' );
		for ( var i = 0; i < containers.length; i++ ) {
			initMap( containers[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
