/**
 * Integrate Rybbit helper.
 *
 * Calls clearUserId() after logout and identify() for logged-in users.
 * Sends custom events when a visitor clicks an element that matches a rule.
 * The configuration comes from window.integrateRybbitConfig.
 */
( function () {
	'use strict';

	var config = window.integrateRybbitConfig;
	if ( ! config ) {
		return;
	}

	var STORAGE_KEY = 'integrate-rybbit-identify';
	var POLL_INTERVAL = 100;
	var MAX_ATTEMPTS = 100;

	function readStored() {
		try {
			return window.localStorage.getItem( STORAGE_KEY );
		} catch ( e ) {
			return null;
		}
	}

	function writeStored( value ) {
		try {
			if ( null === value ) {
				window.localStorage.removeItem( STORAGE_KEY );
			} else {
				window.localStorage.setItem( STORAGE_KEY, value );
			}
		} catch ( e ) {
			// Storage can be blocked. Rybbit still works without it.
		}
	}

	function clearUser( rybbit ) {
		if ( typeof rybbit.clearUserId === 'function' ) {
			rybbit.clearUserId();
		}
		writeStored( null );
	}

	function identifyUser( rybbit, identify ) {
		if ( typeof rybbit.identify !== 'function' ) {
			return;
		}

		var traits = identify.traits || {};
		var signature = JSON.stringify( [ identify.userId, traits ] );
		var current = typeof rybbit.getUserId === 'function' ? rybbit.getUserId() : null;

		// Each identify() and setTraits() call sends a request. Skip it when nothing changed.
		if ( current === identify.userId && readStored() === signature ) {
			return;
		}

		if ( current === identify.userId && typeof rybbit.setTraits === 'function' ) {
			rybbit.setTraits( traits );
		} else {
			rybbit.identify( identify.userId, traits );
		}

		writeStored( signature );
	}

	function run( rybbit ) {
		if ( config.clearUser ) {
			clearUser( rybbit );
		}

		if ( config.identify && config.identify.userId ) {
			identifyUser( rybbit, config.identify );
		}
	}

	function start( rybbit ) {
		// onReady() waits until the tracker is initialized. getUserId() works only after that.
		if ( typeof rybbit.onReady === 'function' ) {
			rybbit.onReady( function ( ready ) {
				run( ready || rybbit );
			} );
			return;
		}

		run( rybbit );
	}

	var attempts = 0;

	function needsUserSync() {
		return !! ( config.clearUser || ( config.identify && config.identify.userId ) );
	}

	function sendEvent( rule, target ) {
		var rybbit = window.rybbit;
		if ( ! rybbit || typeof rybbit.event !== 'function' ) {
			return;
		}

		var matches = false;
		try {
			matches = null !== target.closest( rule.selector );
		} catch ( e ) {
			// An invalid selector must not break the other rules.
			return;
		}

		if ( matches ) {
			rybbit.event( rule.event, rule.props || {} );
		}
	}

	function trackEvents( rules ) {
		// The capture phase runs first. Scripts of the theme cannot block it with stopPropagation().
		document.addEventListener(
			'click',
			function ( clickEvent ) {
				var target = clickEvent.target;
				if ( ! target || typeof target.closest !== 'function' ) {
					return;
				}

				rules.forEach( function ( rule ) {
					if ( rule && rule.selector && rule.event ) {
						sendEvent( rule, target );
					}
				} );
			},
			true
		);
	}

	if ( Array.isArray( config.events ) && config.events.length ) {
		trackEvents( config.events );
	}

	if ( ! needsUserSync() ) {
		return;
	}

	( function wait() {
		var rybbit = window.rybbit;
		if ( rybbit && typeof rybbit === 'object' ) {
			start( rybbit );
			return;
		}

		attempts++;
		if ( attempts < MAX_ATTEMPTS ) {
			window.setTimeout( wait, POLL_INTERVAL );
		}
	} )();
} )();
