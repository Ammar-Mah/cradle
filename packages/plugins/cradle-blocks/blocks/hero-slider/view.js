/**
 * Hero slider view script (vanilla JS, no dependencies; Cradle Blocks).
 *
 * Progressive enhancement for server-rendered `[data-cradle-hero]` slideshows:
 * - each instance is initialized once and keeps its own state and timer;
 * - controls are revealed only here (without JS the first slide simply shows);
 * - autoplay runs only when enabled, never under prefers-reduced-motion, pauses
 *   while hovered (optional) or when the page is hidden, and stops for good when
 *   keyboard focus enters or the visitor navigates (the play button restarts it);
 * - the slides region is aria-live="polite" only while not rotating;
 * - touch/pen swipe uses pointer events; arrow keys work on the controls.
 */
( function () {
	'use strict';

	var SELECTOR = '[data-cradle-hero]';
	var TRANSITIONS = [ 'none', 'fade', 'slide' ];
	var SWIPE_MIN = 40;
	var reduceQuery = window.matchMedia ? window.matchMedia( '(prefers-reduced-motion: reduce)' ) : null;

	function clampInt( value, min, max, fallback ) {
		var n = parseInt( value, 10 );
		return isNaN( n ) ? fallback : Math.min( max, Math.max( min, n ) );
	}

	function readConfig( root ) {
		var raw = {};
		try {
			raw = JSON.parse( root.getAttribute( 'data-cradle-hero' ) || '{}' ) || {};
		} catch ( e ) {
			raw = {};
		}
		return {
			autoplay: raw.autoplay === true,
			interval: clampInt( raw.interval, 3000, 20000, 7000 ),
			duration: clampInt( raw.duration, 0, 1200, 500 ),
			transition: TRANSITIONS.indexOf( raw.transition ) !== -1 ? raw.transition : 'fade',
			loop: raw.loop !== false,
			swipe: raw.swipe !== false,
			pauseOnHover: raw.pauseOnHover !== false
		};
	}

	function prefersReducedMotion() {
		return !! ( reduceQuery && reduceQuery.matches );
	}

	function init( root ) {
		if ( root.hasAttribute( 'data-cradle-hero-ready' ) ) {
			return;
		}
		var track = root.querySelector( '.cradle-hero__slides' );
		if ( ! track ) {
			return;
		}
		var slides = Array.prototype.filter.call( track.children, function ( node ) {
			return node.classList.contains( 'cradle-hero__slide' );
		} );
		if ( slides.length < 2 ) {
			return;
		}
		root.setAttribute( 'data-cradle-hero-ready', '' );

		var cfg = readConfig( root );
		var controls = root.querySelector( '.cradle-hero__controls' );
		var rotation = root.querySelector( '.cradle-hero__rotation' );
		var prevButton = root.querySelector( '.cradle-hero__prev' );
		var nextButton = root.querySelector( '.cradle-hero__next' );
		var dots = Array.prototype.slice.call( root.querySelectorAll( '.cradle-hero__dot' ) );
		var viewport = root.querySelector( '.cradle-hero__viewport' ) || track;

		var state = {
			index: 0,
			rotating: cfg.autoplay && ! prefersReducedMotion(),
			hovered: false,
			timer: 0,
			pointer: null,
			lastPointer: 0
		};

		function isRtl() {
			return window.getComputedStyle( root ).direction === 'rtl';
		}

		function setInactive( slide, inactive ) {
			slide.setAttribute( 'aria-hidden', inactive ? 'true' : 'false' );
			if ( inactive ) {
				slide.setAttribute( 'inert', '' );
			} else {
				slide.removeAttribute( 'inert' );
			}
		}

		function updateControls() {
			dots.forEach( function ( dot, n ) {
				if ( n === state.index ) {
					dot.setAttribute( 'aria-current', 'true' );
				} else {
					dot.removeAttribute( 'aria-current' );
				}
			} );
			if ( ! cfg.loop ) {
				if ( prevButton ) {
					prevButton.setAttribute( 'aria-disabled', state.index === 0 ? 'true' : 'false' );
				}
				if ( nextButton ) {
					nextButton.setAttribute( 'aria-disabled', state.index === slides.length - 1 ? 'true' : 'false' );
				}
			}
		}

		function updateRotation() {
			track.setAttribute( 'aria-live', state.rotating ? 'off' : 'polite' );
			root.classList.toggle( 'is-rotating', state.rotating );
			if ( rotation ) {
				var text = rotation.getAttribute( state.rotating ? 'data-label-pause' : 'data-label-play' ) || '';
				var label = rotation.querySelector( '.cradle-hero__sr' );
				if ( label ) {
					label.textContent = text;
				}
				rotation.classList.toggle( 'is-paused', ! state.rotating );
			}
			schedule();
		}

		function canAdvance() {
			return state.rotating && ! state.hovered && ! document.hidden && ! prefersReducedMotion();
		}

		function schedule() {
			window.clearTimeout( state.timer );
			state.timer = 0;
			if ( canAdvance() ) {
				state.timer = window.setTimeout( function () {
					if ( ! cfg.loop && state.index >= slides.length - 1 ) {
						// Without looping, rotation ends on the last slide.
						state.rotating = false;
						updateRotation();
						return;
					}
					go( state.index + 1, 1 );
				}, cfg.interval );
			}
		}

		function instant() {
			return cfg.transition === 'none' || cfg.duration === 0 || prefersReducedMotion();
		}

		function go( target, direction ) {
			var count = slides.length;
			if ( cfg.loop ) {
				target = ( target + count ) % count;
			} else if ( target < 0 || target >= count ) {
				schedule();
				return;
			}
			if ( target === state.index ) {
				schedule();
				return;
			}
			var from = slides[ state.index ];
			var to = slides[ target ];
			direction = direction || ( target > state.index ? 1 : -1 );

			if ( cfg.transition === 'slide' && ! instant() ) {
				// Park the incoming slide on the side it enters from, without animating.
				to.classList.add( 'is-instant' );
				to.classList.toggle( 'is-prev', direction < 0 );
				void to.offsetWidth; // eslint-disable-line no-void
				to.classList.remove( 'is-instant' );
				from.classList.toggle( 'is-prev', direction > 0 );
			} else {
				from.classList.remove( 'is-prev' );
				to.classList.remove( 'is-prev' );
			}
			from.classList.remove( 'is-active' );
			to.classList.remove( 'is-prev' );
			to.classList.add( 'is-active' );
			setInactive( from, true );
			setInactive( to, false );
			state.index = target;
			updateControls();
			schedule();
		}

		// A deliberate interaction ends automatic rotation (restart with the play button).
		function interact() {
			if ( state.rotating ) {
				state.rotating = false;
				updateRotation();
			}
		}

		function previous() {
			interact();
			go( state.index - 1, -1 );
		}

		function next() {
			interact();
			go( state.index + 1, 1 );
		}

		// Reveal the enhanced state.
		slides.forEach( function ( slide, n ) {
			slide.removeAttribute( 'hidden' );
			slide.classList.toggle( 'is-active', n === 0 );
			setInactive( slide, n !== 0 );
		} );
		if ( controls ) {
			controls.removeAttribute( 'hidden' );
		}
		root.classList.add( 'is-ready' );
		root.classList.toggle( 'is-reduced-motion', prefersReducedMotion() );

		if ( prevButton ) {
			prevButton.addEventListener( 'click', function () {
				if ( prevButton.getAttribute( 'aria-disabled' ) !== 'true' ) {
					previous();
				}
			} );
		}
		if ( nextButton ) {
			nextButton.addEventListener( 'click', function () {
				if ( nextButton.getAttribute( 'aria-disabled' ) !== 'true' ) {
					next();
				}
			} );
		}
		dots.forEach( function ( dot, n ) {
			dot.addEventListener( 'click', function () {
				interact();
				go( n, n > state.index ? 1 : -1 );
			} );
		} );
		if ( rotation ) {
			rotation.addEventListener( 'click', function () {
				state.rotating = ! state.rotating;
				updateRotation();
			} );
		}

		// Arrow keys on any slideshow control (mirrored for right-to-left pages).
		if ( controls ) {
			controls.addEventListener( 'keydown', function ( event ) {
				if ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' ) {
					return;
				}
				var forward = ( event.key === 'ArrowRight' ) !== isRtl();
				event.preventDefault();
				if ( forward ) {
					next();
				} else {
					previous();
				}
				var current = dots[ state.index ];
				if ( current && dots.indexOf( document.activeElement ) !== -1 ) {
					current.focus();
				}
			} );
		}

		// Keyboard focus entering the slideshow, or reaching a slide's links, stops rotation
		// (WCAG 2.2.2). Focus caused by a pointer press is left to the click handlers.
		root.addEventListener( 'pointerdown', function () {
			state.lastPointer = Date.now();
		}, true );
		root.addEventListener( 'focusin', function ( event ) {
			if ( Date.now() - ( state.lastPointer || 0 ) < 800 ) {
				return;
			}
			var from = event.relatedTarget;
			if ( track.contains( event.target ) || ! from || ! root.contains( from ) ) {
				interact();
			}
		} );

		if ( cfg.pauseOnHover ) {
			root.addEventListener( 'pointerenter', function ( event ) {
				if ( event.pointerType === 'mouse' ) {
					state.hovered = true;
					schedule();
				}
			} );
			root.addEventListener( 'pointerleave', function ( event ) {
				if ( event.pointerType === 'mouse' ) {
					state.hovered = false;
					schedule();
				}
			} );
		}

		if ( cfg.swipe ) {
			viewport.addEventListener( 'pointerdown', function ( event ) {
				if ( event.pointerType === 'mouse' || ! event.isPrimary ) {
					return;
				}
				state.pointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
			} );
			viewport.addEventListener( 'pointerup', function ( event ) {
				var start = state.pointer;
				state.pointer = null;
				if ( ! start || start.id !== event.pointerId ) {
					return;
				}
				var dx = event.clientX - start.x;
				var dy = event.clientY - start.y;
				if ( Math.abs( dx ) < SWIPE_MIN || Math.abs( dx ) < Math.abs( dy ) * 1.2 ) {
					return;
				}
				var forward = ( dx < 0 ) !== isRtl();
				if ( forward ) {
					next();
				} else {
					previous();
				}
			} );
			viewport.addEventListener( 'pointercancel', function () {
				state.pointer = null;
			} );
		}

		document.addEventListener( 'visibilitychange', schedule );

		if ( reduceQuery ) {
			var onMotionChange = function () {
				root.classList.toggle( 'is-reduced-motion', prefersReducedMotion() );
				if ( prefersReducedMotion() && state.rotating ) {
					state.rotating = false;
					updateRotation();
				}
			};
			if ( reduceQuery.addEventListener ) {
				reduceQuery.addEventListener( 'change', onMotionChange );
			} else if ( reduceQuery.addListener ) {
				reduceQuery.addListener( onMotionChange );
			}
		}

		updateControls();
		updateRotation();
	}

	function initAll() {
		Array.prototype.forEach.call( document.querySelectorAll( SELECTOR ), init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
