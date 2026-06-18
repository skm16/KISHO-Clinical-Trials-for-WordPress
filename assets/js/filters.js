/**
 * Clinical Trials Feed — progressive-enhancement filter script.
 *
 * The filter form works fully via a standard GET form reload (no JS required).
 * This script layers on instant client-side filtering when JS is available:
 *   - Submit event is intercepted to show a loading state.
 *   - A debounced input listener on the state field triggers re-submission
 *     so the user gets live feedback.
 *
 * No frameworks, no CDN, plain ES5+ compatible with WP's default script loading.
 *
 * @package SKMCTF
 * @license GPL-2.0-or-later
 */

( function () {
	'use strict';

	/**
	 * Debounce helper.
	 *
	 * @param {Function} fn    Function to debounce.
	 * @param {number}   delay Delay in ms.
	 * @returns {Function}
	 */
	function debounce( fn, delay ) {
		var timer;
		return function () {
			clearTimeout( timer );
			var args = arguments;
			var ctx  = this;
			timer = setTimeout( function () {
				fn.apply( ctx, args );
			}, delay );
		};
	}

	/**
	 * Initialise a single filter form.
	 *
	 * @param {HTMLFormElement} form
	 */
	function initFilterForm( form ) {
		var wrap    = form.closest( '.skmctf-trials-wrap' );
		var list    = wrap ? wrap.querySelector( '.skmctf-list' ) : null;
		var stateInput = form.querySelector( '.skmctf-filters__input' );

		// Show a polite loading state during form submission.
		form.addEventListener( 'submit', function () {
			if ( list ) {
				list.setAttribute( 'aria-busy', 'true' );
			}
		} );

		// Debounced auto-submit when the state text input changes.
		if ( stateInput ) {
			stateInput.addEventListener(
				'input',
				debounce( function () {
					form.submit();
				}, 600 )
			);
		}

		// Auto-submit selects immediately on change.
		var selects = form.querySelectorAll( '.skmctf-filters__select' );
		for ( var i = 0; i < selects.length; i++ ) {
			selects[ i ].addEventListener( 'change', function () {
				form.submit();
			} );
		}
	}

	/**
	 * Boot on DOMContentLoaded.
	 */
	function boot() {
		var forms = document.querySelectorAll( '[data-skmctf-filters]' );
		for ( var j = 0; j < forms.length; j++ ) {
			initFilterForm( forms[ j ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );
