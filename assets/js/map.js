/**
 * Clinical Trials Map — initialises a Leaflet/OSM map from localised data.
 *
 * Expects: window.skmctfMap = {
 *   points    : [ { lat, lng, title, url }, … ],
 *   imagePath : 'http://…/assets/lib/leaflet/images/',
 *   attribution: '&copy; …'
 * }
 *
 * Bundled Leaflet 1.9.4 (no CDN). Marker images served from the same
 * local path to avoid broken-icon URLs.
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

	if (
		! data ||
		! Array.isArray( data.points ) ||
		data.points.length === 0
	) {
		return;
	}

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

	data.points.forEach( function ( point ) {
		// Validate coordinates (must be finite numbers).
		var lat = parseFloat( point.lat );
		var lng = parseFloat( point.lng );

		if ( ! isFinite( lat ) || ! isFinite( lng ) ) {
			return;
		}

		var latlng = L.latLng( lat, lng );
		bounds.push( latlng );

		// Build popup HTML — title and url are pre-escaped server-side.
		// We assemble them as text nodes here for extra safety.
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

	// ------------------------------------------------------------------
	// Fit map to all markers.
	// ------------------------------------------------------------------
	if ( bounds.length === 1 ) {
		map.setView( bounds[ 0 ], 10 );
	} else if ( bounds.length > 1 ) {
		map.fitBounds( L.latLngBounds( bounds ), { padding: [ 40, 40 ] } );
	}

}() );
