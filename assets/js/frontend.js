/**
 * Goal Cart storefront progress widgets (Phase 11).
 *
 * Vanilla JS, no build step — mirrors the reference plugin's frontend
 * convention (assets/js + a single inline window config + a
 * must-never-throw contract). The PHP side prints an empty container per
 * display location and this library fills them from `GET /goalcart/v1/progress`.
 *
 * Components (P11):
 *   GoalContainer    wrapper that hosts one goal's UI (full / compact)
 *   ProgressBar      percentage fill bar
 *   GoalMessage      the goal's progress message
 *   GoalMilestones   ordered ladder of the active goals
 *   RewardStatus     locked / unlocked reward chip
 *   SuggestionList   product suggestions (empty until Phase 14)
 *   StickyGoalBar    fixed bottom bar (cart/checkout progress at a glance)
 *
 * Templates (P12): the goal body renders per the active variant — basic
 * (bar), percentage (big % + bar), milestone (ladder + bar) or card
 * (icon + title + bar) — driven by `cfg.template` or a per-container
 * `data-goalcart-template` override. Appearance tokens (colors, radius,
 * bar height) come from the same config; the animation toggle adds a
 * no-transition class when disabled.
 *
 * Contracts:
 *   - config comes from `window.goalcartFrontend` (printed early in
 *     wp_footer before this script)
 *   - nothing here ever throws: every handler is guarded, so a failure
 *     can never break the storefront
 *   - each container is mounted exactly once (data attribute guard); a
 *     location that re-renders (mini-cart fragment refresh) re-mounts
 *     after the DOM swap
 *
 * @package GoalCart
 */
( function () {
	'use strict';

	var cfg = window.goalcartFrontend || null;

	// No config = plugin disabled or assets loaded on a widget-less page.
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	var WIDGET_SELECTOR = '[data-goalcart-widget]';
	var STICKY_ID = 'goalcart-sticky';
	var stickyDismissed = false;

	/**
	 * Guard every entry point: a thrown error must never reach the page.
	 *
	 * @param {Function} fn Callback to run.
	 * @return {*} Result or null.
	 */
	function safe( fn ) {
		try {
			return fn();
		} catch ( error ) {
			if ( window.console && window.console.warn ) {
				window.console.warn( 'Goal Cart frontend:', error );
			}
			return null;
		}
	}

	/**
	 * Create an element with optional class names and text.
	 *
	 * @param {string} tag      Tag name.
	 * @param {string} [klass]  Space-separated class names.
	 * @param {string} [text]   Text content.
	 * @return {HTMLElement}
	 */
	function el( tag, klass, text ) {
		var node = document.createElement( tag );
		if ( klass ) {
			node.className = klass;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	/**
	 * Fetch the progress payload.
	 *
	 * @param {Function} done Callback receiving the parsed `data` object.
	 * @return {void}
	 */
	function fetchProgress( done ) {
		var request = new XMLHttpRequest();

		request.open( 'GET', cfg.endpoint, true );
		request.timeout = 10000;

		request.onload = function () {
			if ( request.status < 200 || request.status >= 300 ) {
				return;
			}

			safe( function () {
				var payload = JSON.parse( request.responseText );
				if ( payload && payload.data ) {
					done( payload.data );
				}
			} );
		};

		request.onerror = function () {};
		request.ontimeout = function () {};
		request.send();
	}

	/**
	 * Format a money amount with the store currency.
	 *
	 * @param {number} value    Amount.
	 * @param {string} currency ISO code.
	 * @return {string}
	 */
	function formatMoney( value, currency ) {
		try {
			return new Intl.NumberFormat( undefined, {
				style: 'currency',
				currency: currency || cfg.currency || 'USD',
			} ).format( Number( value ) || 0 );
		} catch ( error ) {
			return String( value );
		}
	}

	/**
	 * Format a plain number.
	 *
	 * @param {number} value Number.
	 * @return {string}
	 */
	function formatNumber( value ) {
		try {
			return new Intl.NumberFormat( undefined ).format( Number( value ) || 0 );
		} catch ( error ) {
			return String( value );
		}
	}

	/**
	 * Whether a suggestion URL is safe to open (http/https or a relative
	 * path — never a javascript: or other scheme).
	 *
	 * @param {string} url Candidate URL.
	 * @return {boolean}
	 */
	function isSafeUrl( url ) {
		var value = String( url || '' ).trim();

		if ( ! value ) {
			return false;
		}

		if ( /^\/|^\.\.?\//.test( value ) ) {
			return true; // Relative path.
		}

		return /^https?:\/\//i.test( value );
	}

	/**
	 * The featured goal: the first eligible one, else the first listed.
	 *
	 * @param {Array} goals Progress goal entries.
	 * @return {Object|null}
	 */
	function featuredGoal( goals ) {
		if ( ! goals || ! goals.length ) {
			return null;
		}

		for ( var i = 0; i < goals.length; i++ ) {
			if ( goals[ i ].eligible !== false ) {
				return goals[ i ];
			}
		}

		return goals[ 0 ];
	}

	/* ------------------------------------------------------------------ *
	 * Components
	 * ------------------------------------------------------------------ */

	/**
	 * ProgressBar — a percentage fill bar.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement}
	 */
	function progressBar( goal ) {
		var track = el( 'div', 'goalcart-progress' );
		var fill = el( 'div', 'goalcart-progress__fill' );
		var percent = Math.max( 0, Math.min( 100, Number( goal.percentage ) || 0 ) );

		fill.style.width = percent + '%';

		if ( goal.completed ) {
			track.classList.add( 'goalcart-progress--complete' );
		}

		track.appendChild( fill );

		return track;
	}

	/**
	 * GoalMessage — the goal's progress message.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement}
	 */
	function goalMessage( goal ) {
		return el( 'p', 'goalcart-message', String( goal.message || '' ) );
	}

	/**
	 * GoalMilestones — the active goals as an ordered ladder.
	 *
	 * Each step shows its target and fills once reached. Hidden when there
	 * is a single goal (a ladder needs at least two rungs).
	 *
	 * @param {Array}  goals    Progress goal entries.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement|null}
	 */
	function goalMilestones( goals, currency ) {
		if ( ! goals || goals.length < 2 ) {
			return null;
		}

		var list = el( 'ol', 'goalcart-milestones' );

		for ( var i = 0; i < goals.length; i++ ) {
			var goal = goals[ i ];
			var step = el( 'li', 'goalcart-milestone' );

			if ( goal.completed ) {
				step.classList.add( 'goalcart-milestone--complete' );
			}

			var target = goal.is_money ? formatMoney( goal.target, currency ) : formatNumber( goal.target );

			step.appendChild( el( 'span', 'goalcart-milestone__dot' ) );
			step.appendChild( el( 'span', 'goalcart-milestone__target', target ) );
			list.appendChild( step );
		}

		return list;
	}

	/**
	 * RewardStatus — a locked/unlocked chip for the goal's reward.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement|null}
	 */
	function rewardStatus( goal ) {
		var reward = goal.reward || null;

		if ( ! reward || ! reward.type ) {
			return null;
		}

		var label = ( cfg.labels && cfg.labels[ reward.type ] ) || reward.type;
		var chip = el( 'span', 'goalcart-reward' );

		chip.classList.add(
			goal.completed ? 'goalcart-reward--unlocked' : 'goalcart-reward--locked'
		);
		chip.appendChild( el( 'span', 'goalcart-reward__icon', goal.completed ? '\u2713' : '\uD83D\uDD12' ) );
		chip.appendChild( el( 'span', 'goalcart-reward__label', label ) );

		return chip;
	}

	/**
	 * SuggestionList — products that help reach the goal.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement|null}
	 */
	function suggestionList( goal ) {
		var items = ( goal.suggestions && goal.suggestions.length ) ? goal.suggestions : [];

		if ( ! items.length ) {
			return null;
		}

		var list = el( 'ul', 'goalcart-suggestions' );

		for ( var i = 0; i < items.length; i++ ) {
			var item = items[ i ];
			var li = el( 'li', 'goalcart-suggestion' );

			if ( item.permalink && isSafeUrl( item.permalink ) ) {
				var link = el( 'a', 'goalcart-suggestion__link' );
				link.href = String( item.permalink );
				link.appendChild( el( 'span', 'goalcart-suggestion__name', String( item.name || '' ) ) );
				link.appendChild( el( 'span', 'goalcart-suggestion__price', String( item.price || '' ) ) );
				li.appendChild( link );
			} else {
				li.appendChild( el( 'span', 'goalcart-suggestion__name', String( item.name || '' ) ) );
			}

			list.appendChild( li );
		}

		return list;
	}

	/**
	 * The per-widget template: container override, else the global config.
	 *
	 * @param {HTMLElement} container Widget container.
	 * @return {string}
	 */
	function widgetTemplate( container ) {
		var override = container.getAttribute( 'data-goalcart-template' );
		var names = [ 'basic', 'percentage', 'milestone', 'card' ];

		if ( override && names.indexOf( override ) !== -1 ) {
			return override;
		}

		if ( cfg.template && names.indexOf( cfg.template ) !== -1 ) {
			return cfg.template;
		}

		return 'basic';
	}

	/**
	 * Percentage template — a large percent readout above the bar.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement}
	 */
	function percentagePanel( goal ) {
		var wrap = el( 'div', 'goalcart-percentage' );
		var percent = Math.max( 0, Math.min( 100, Number( goal.percentage ) || 0 ) );

		wrap.appendChild( el( 'span', 'goalcart-percentage__value', Math.round( percent ) + '%' ) );
		wrap.appendChild( progressBar( goal ) );

		return wrap;
	}

	/**
	 * Milestone template — the goal ladder as the primary visual, with the
	 * featured bar underneath. Falls back to the bare bar when there is no
	 * ladder (a single goal has nothing to ladder). Compact widgets skip
	 * the ladder (a mini-cart has no room for it) and keep just the bar.
	 *
	 * @param {Object}  goal     Featured goal.
	 * @param {Array}   goals    All active goals.
	 * @param {string}  currency ISO currency code.
	 * @param {boolean} compact  Compact variant flag.
	 * @return {HTMLElement}
	 */
	function milestonePanel( goal, goals, currency, compact ) {
		var wrap = el( 'div', 'goalcart-milestone-panel' );

		if ( ! compact ) {
			var ladder = goalMilestones( goals, currency );

			if ( ladder ) {
				wrap.appendChild( ladder );
			}
		}

		wrap.appendChild( progressBar( goal ) );

		return wrap;
	}

	/**
	 * Card template — icon + goal title above the bar (the reward chip and
	 * message come from the standard flow; suggestions act as the CTA once
	 * Phase 14 fills them).
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement}
	 */
	function cardPanel( goal ) {
		var panel = el( 'div', 'goalcart-card-panel' );

		panel.appendChild( el( 'span', 'goalcart-card-panel__icon', String( goal.icon || '\uD83C\uDFAF' ) ) );
		panel.appendChild( el( 'span', 'goalcart-card-panel__title', String( goal.goal_name || '' ) ) );
		panel.appendChild( progressBar( goal ) );

		return panel;
	}

	/**
	 * The template's core visual (everything except the shared message /
	 * reward chip / suggestion flow).
	 *
	 * @param {Object}  goal     Featured goal.
	 * @param {Array}   goals    All active goals (for the ladder).
	 * @param {string}  currency ISO currency code.
	 * @param {string}  template Template variant.
	 * @param {boolean} compact  Compact variant flag.
	 * @return {HTMLElement}
	 */
	function templateBody( goal, goals, currency, template, compact ) {
		switch ( template ) {
			case 'percentage':
				return percentagePanel( goal );
			case 'milestone':
				return milestonePanel( goal, goals, currency, compact );
			case 'card':
				return cardPanel( goal );
			default:
				return progressBar( goal );
		}
	}

	/**
	 * GoalContainer — the widget body for one featured goal.
	 *
	 * Full: reward chip + template body + message + milestones (except the
	 * milestone template, which shows the ladder in its body) + suggestions.
	 * Compact: template body + message + reward chip.
	 *
	 * @param {Object} goal     Featured goal.
	 * @param {Array}  goals    All active goals (for the ladder).
	 * @param {string} currency ISO currency code.
	 * @param {string} variant  full|compact.
	 * @param {string} template Template variant.
	 * @return {HTMLElement}
	 */
	function goalContainer( goal, goals, currency, variant, template ) {
		var card = el( 'div', 'goalcart-card goalcart-template--' + template );

		var compact = 'compact' === variant;

		if ( compact ) {
			card.appendChild( templateBody( goal, goals, currency, template, true ) );
			card.appendChild( goalMessage( goal ) );
			card.appendChild( rewardStatus( goal ) );
			return card;
		}

		var head = el( 'div', 'goalcart-card__head' );
		head.appendChild( rewardStatus( goal ) );
		card.appendChild( head );

		card.appendChild( templateBody( goal, goals, currency, template, false ) );
		card.appendChild( goalMessage( goal ) );

		if ( 'milestone' !== template ) {
			var milestones = goalMilestones( goals, currency );
			if ( milestones ) {
				card.appendChild( milestones );
			}
		}

		var suggestions = suggestionList( goal );
		if ( suggestions ) {
			card.appendChild( suggestions );
		}

		return card;
	}

	/* ------------------------------------------------------------------ *
	 * Mounting
	 * ------------------------------------------------------------------ */

	/**
	 * Render the progress payload into a single widget container.
	 *
	 * Empty state: no eligible goals (or no goals at all) → the container
	 * is hidden entirely rather than showing a broken bar.
	 *
	 * @param {HTMLElement} container Widget container.
	 * @param {Object}      data      Progress payload data.
	 * @return {void}
	 */
	function renderWidget( container, data ) {
		if ( ! container ) {
			return;
		}

		var goals = ( data && data.goals ) || [];
		var goal = featuredGoal( goals );
		var variant = 'compact' === container.getAttribute( 'data-goalcart-variant' ) ? 'compact' : 'full';
		var template = widgetTemplate( container );

		// The animation toggle (Phase 12) freezes the fill transition via a
		// class; re-render in place on every refresh so live cart updates
		// (AJAX add-to-cart, quantity changes, fragment refreshes) always
		// show the current progress — no mount-once freeze.
		container.classList.toggle( 'goalcart-widget--no-anim', false === cfg.animation );
		container.replaceChildren();

		if ( ! goal ) {
			container.classList.add( 'goalcart-widget--empty' );
			return;
		}

		container.classList.remove( 'goalcart-widget--empty' );
		container.appendChild( goalContainer( goal, goals, data.currency || cfg.currency, variant, template ) );
	}

	/**
	 * StickyGoalBar — a fixed bottom bar with the featured goal's progress.
	 *
	 * Visible only while the cart has progress to show (current > 0 or the
	 * goal is completed); the close button dismisses it for the session.
	 *
	 * @param {Object} data Progress payload data.
	 * @return {void}
	 */
	function renderSticky( data ) {
		var bar = document.getElementById( STICKY_ID );

		if ( ! bar ) {
			return;
		}

		var goals = ( data && data.goals ) || [];
		var goal = featuredGoal( goals );
		var hasProgress = false;

		for ( var i = 0; i < goals.length; i++ ) {
			if ( Number( goals[ i ].current ) > 0 || goals[ i ].completed ) {
				hasProgress = true;
				break;
			}
		}

		if ( ! goal || ! hasProgress || stickyDismissed ) {
			bar.classList.remove( 'goalcart-sticky--visible' );
			bar.setAttribute( 'aria-hidden', 'true' );
			bar.replaceChildren();
			return;
		}

		bar.classList.add( 'goalcart-sticky--visible' );
		bar.classList.toggle( 'goalcart-no-anim', false === cfg.animation );
		bar.setAttribute( 'aria-hidden', 'false' );

		// Rebuild the bar content on every refresh so the fill and message
		// track the live cart (no mount-once freeze).
		var inner = el( 'div', 'goalcart-sticky__inner' );
		var content = el( 'div', 'goalcart-sticky__content' );
		var close = el( 'button', 'goalcart-sticky__close' );
		var reward = rewardStatus( goal );

		content.appendChild( progressBar( goal ) );
		content.appendChild( goalMessage( goal ) );
		if ( reward ) {
			content.appendChild( reward );
		}

		close.type = 'button';
		close.setAttribute( 'aria-label', 'Dismiss' );
		close.textContent = '\u00D7';
		close.addEventListener( 'click', function () {
			stickyDismissed = true;
			bar.classList.remove( 'goalcart-sticky--visible' );
			bar.setAttribute( 'aria-hidden', 'true' );
		} );

		inner.appendChild( content );
		inner.appendChild( close );
		bar.replaceChildren( inner );
	}

	/**
	 * Refresh every mounted widget from the progress endpoint.
	 *
	 * Re-runs mount discovery so containers added by fragment refreshes
	 * (mini cart) get mounted after the DOM swap.
	 *
	 * @return {void}
	 */
	function refresh() {
		safe( function () {
			fetchProgress( function ( data ) {
				safe( function () {
					var containers = document.querySelectorAll( WIDGET_SELECTOR );

					for ( var i = 0; i < containers.length; i++ ) {
						renderWidget( containers[ i ], data );
					}

					renderSticky( data );
				} );
			} );
		} );
	}

	/**
	 * Bind the WooCommerce cart-update events.
	 *
	 * WooCommerce fires these jQuery events on document.body for both
	 * classic and Blocks cart mutations. Native addEventListener misses
	 * jQuery-triggered events, so we bind through jQuery when present and
	 * fall back to native CustomEvent listeners otherwise.
	 *
	 * @return {void}
	 */
	function bindCartEvents() {
		var events = [
			'added_to_cart',
			'removed_from_cart',
			'updated_cart_totals',
			'updated_wc_div',
			'wc_fragments_refreshed',
			'wc_fragments_loaded',
		];

		if ( window.jQuery ) {
			safe( function () {
				window.jQuery( document.body ).on( events.join( ' ' ), refresh );
			} );
		} else {
			for ( var i = 0; i < events.length; i++ ) {
				document.body.addEventListener( events[ i ], refresh );
			}
		}
	}

	/**
	 * Boot the widgets.
	 *
	 * @return {void}
	 */
	function init() {
		bindCartEvents();

		refresh();

		if ( cfg.refresh && cfg.refresh > 0 ) {
			window.setInterval( refresh, Number( cfg.refresh ) * 1000 );
		}
	}

	// Run once the DOM is ready (or immediately if already interactive).
	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			safe( init );
		} );
	} else {
		safe( init );
	}
} )();
