/**
 * Integrate Rybbit opt-out switch.
 *
 * The Rybbit script reads localStorage["disable-<namespace>"] when it loads.
 * If the entry exists, the script does not track. This file sets and removes
 * that entry. The change takes effect on the next page load.
 */
( function () {
	'use strict';

	var SELECTOR = '[data-integrate-rybbit-optout]';
	var widgets = [];

	function storageKey( widget ) {
		return 'disable-' + ( widget.getAttribute( 'data-namespace' ) || 'rybbit' );
	}

	// Returns true or false, or null when the browser blocks the storage.
	function isOptedOut( key ) {
		try {
			return null !== window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function setOptedOut( key, optedOut ) {
		try {
			if ( optedOut ) {
				window.localStorage.setItem( key, 'true' );
			} else {
				window.localStorage.removeItem( key );
			}
		} catch ( e ) {
			return false;
		}

		return isOptedOut( key ) === optedOut;
	}

	function render( widget ) {
		var status = widget.querySelector( '.integrate-rybbit-optout__status' );
		var button = widget.querySelector( '.integrate-rybbit-optout__button' );
		var optedOut = isOptedOut( storageKey( widget ) );

		if ( ! status || ! button ) {
			return;
		}

		if ( null === optedOut ) {
			status.textContent = widget.getAttribute( 'data-error-text' );
			status.hidden = false;
			button.hidden = true;
			return;
		}

		status.textContent = widget.getAttribute( optedOut ? 'data-untracked-text' : 'data-tracked-text' );
		button.textContent = widget.getAttribute( optedOut ? 'data-optin-label' : 'data-optout-label' );
		status.hidden = false;
		button.hidden = false;
	}

	function renderAll() {
		widgets.forEach( render );
	}

	function onClick( event ) {
		var widget = event.currentTarget.closest( SELECTOR );
		var key = storageKey( widget );
		var optedOut = ! isOptedOut( key );

		if ( setOptedOut( key, optedOut ) ) {
			// The tracking script on this page is already loaded. This flag covers scripts that load later.
			window.__RYBBIT_OPTOUT__ = optedOut;
		}

		renderAll();
	}

	function init() {
		widgets = Array.prototype.slice.call( document.querySelectorAll( SELECTOR ) );

		widgets.forEach( function ( widget ) {
			var button = widget.querySelector( '.integrate-rybbit-optout__button' );
			if ( button ) {
				button.addEventListener( 'click', onClick );
			}
		} );

		renderAll();

		// Keep other tabs of the same site in sync.
		window.addEventListener( 'storage', renderAll );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
