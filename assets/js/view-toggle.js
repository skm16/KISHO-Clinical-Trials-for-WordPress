/*
 * Clinical Trials Feed — grid/list view toggle.
 *
 * Progressive enhancement: the correct view is already rendered server-side
 * (the <ul> carries .skmctf-list--view-* and data-skmctf-view). This script
 * lets visitors switch and remembers their choice in localStorage. No deps.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

( function () {
	'use strict';

	var KEY   = 'skmctf_view';
	var VALID = [ 'grid', 'list' ];

	/**
	 * Return the stored visitor view, but ONLY if it was saved against the
	 * current admin default. A preference is a deliberate override OF a known
	 * default; when the admin changes that default, prior preferences are stale
	 * and must not silently override the new default. We stamp each saved
	 * preference with the default it was made against and discard on mismatch.
	 *
	 * Legacy bare-string entries (no stamped default) are treated as stale and
	 * discarded, so existing visitors fall back to the admin default once.
	 *
	 * @param {string} defaultView Current admin default ('grid' | 'list').
	 * @returns {string|null} A valid stored view, or null to use the default.
	 */
	function read( defaultView ) {
		try {
			var raw = window.localStorage.getItem( KEY );
			if ( ! raw ) {
				return null;
			}
			var data = JSON.parse( raw );
			if ( ! data || typeof data !== 'object' ) {
				return null; // legacy bare string → stale, ignore.
			}
			if ( VALID.indexOf( data.v ) === -1 || data.d !== defaultView ) {
				return null; // unknown view, or saved against a different default.
			}
			return data.v;
		} catch ( e ) {
			return null;
		}
	}

	function write( view, defaultView ) {
		try {
			window.localStorage.setItem( KEY, JSON.stringify( { v: view, d: defaultView } ) );
		} catch ( e ) {
			/* storage unavailable — ignore; the view still switches this load. */
		}
	}

	function apply( toggle, list, view ) {
		list.classList.remove( 'skmctf-list--view-grid', 'skmctf-list--view-list' );
		list.classList.add( 'skmctf-list--view-' + view );
		list.setAttribute( 'data-skmctf-view', view );

		var btns = toggle.querySelectorAll( '[data-skmctf-view-btn]' );
		for ( var i = 0; i < btns.length; i++ ) {
			var on = btns[ i ].getAttribute( 'data-skmctf-view-btn' ) === view;
			btns[ i ].setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			btns[ i ].classList.toggle( 'is-active', on );
		}
	}

	function init( toggle ) {
		// The list is a .skmctf-list within the toggle's parent (toggle then <ul>).
		var list = toggle.parentNode ? toggle.parentNode.querySelector( '.skmctf-list' ) : null;
		if ( ! list ) {
			return;
		}

		// The admin default the server rendered against; preferences are scoped
		// to it so a changed default invalidates stale visitor choices.
		var defaultView = toggle.getAttribute( 'data-skmctf-default-view' )
			|| list.getAttribute( 'data-skmctf-view' );

		var stored = read( defaultView );
		if ( stored && stored !== list.getAttribute( 'data-skmctf-view' ) ) {
			apply( toggle, list, stored );
		}

		toggle.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-skmctf-view-btn]' ) : null;
			if ( ! btn ) {
				return;
			}
			var view = btn.getAttribute( 'data-skmctf-view-btn' );
			if ( VALID.indexOf( view ) === -1 ) {
				return;
			}
			apply( toggle, list, view );
			write( view, defaultView );
		} );
	}

	function boot() {
		var toggles = document.querySelectorAll( '.skmctf-view-toggle' );
		for ( var i = 0; i < toggles.length; i++ ) {
			init( toggles[ i ] );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
