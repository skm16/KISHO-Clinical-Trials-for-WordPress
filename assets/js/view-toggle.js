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

	function read() {
		try {
			var v = window.localStorage.getItem( KEY );
			return VALID.indexOf( v ) !== -1 ? v : null;
		} catch ( e ) {
			return null;
		}
	}

	function write( v ) {
		try {
			window.localStorage.setItem( KEY, v );
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

		var stored = read();
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
			write( view );
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
