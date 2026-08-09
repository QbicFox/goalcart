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
 *   SuggestionList   product suggestions (Phase 14: served by the
 *                     SuggestionEngine, price shown server-formatted)
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

	// Phase 27 (Internationalization): format numbers/money in the site
	// locale (from the PHP config) so digits and grouping match the store
	// language — Persian digits for fa_IR, etc. Undefined falls back to
	// the browser default, preserving the pre-Phase-27 behavior.
	var uiLocale = ( cfg && cfg.locale ) ? String( cfg.locale ).replace( '_', '-' ) : undefined;

	var WIDGET_SELECTOR = '[data-goalcart-widget]';
	var STICKY_ID = 'goalcart-sticky';
	var stickyDismissed = false;

	// Phase 16 analytics: window.goalcartTracking (printed by the Tracker)
	// carries the track endpoint, the nonce and the session id. Absent =
	// tracking disabled — every tracker call is a guarded no-op.
	var tracking = window.goalcartTracking || null;

	// Per-session dedup: impressions / completions / suggestion impressions
	// are reported once per goal (or goal+product); progress only when the
	// percentage actually changed. Keeps refreshes quiet while still
	// capturing the funnel events.
	var reportedImpressions = {};
	var reportedCompletions = {};
	var reportedSuggestionImpressions = {};
	var reportedProgress = {};

	// Phase 23 (Performance → update only changed UI fragments): each
	// widget records a fingerprint of the payload it last rendered.
	// refresh() skips the DOM rebuild for containers whose fingerprint is
	// unchanged (the poll interval and cart events fire refresh() even
	// when nothing moved), so only the fragments whose numbers actually
	// changed are touched.
	var renderedFingerprints = {};
	var stickyFingerprint = null;

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
	 * Report an analytics event to the track endpoint (Phase 16).
	 *
	 * Fire-and-forget, must never throw: a failed report (network, JSON
	 * body, disabled endpoint) must not disturb the storefront. Uses
	 * sendBeacon when available so the report survives page unload.
	 *
	 * @param {string} eventType Event type (whitelisted server-side).
	 * @param {Object} data      Optional event fields.
	 * @return {void}
	 */
	function sendTrack( eventType, data ) {
		if ( ! tracking || ! tracking.endpoint ) {
			return;
		}

		var payload = {
			event_type: eventType,
			nonce: tracking.nonce || '',
			session_id: tracking.sessionId || '',
		};

		if ( data ) {
			for ( var key in data ) {
				if ( Object.prototype.hasOwnProperty.call( data, key ) ) {
					payload[ key ] = data[ key ];
				}
			}
		}

		var body;
		try {
			body = JSON.stringify( payload );
		} catch ( error ) {
			return;
		}

		if ( navigator.sendBeacon ) {
			try {
				navigator.sendBeacon( tracking.endpoint, new Blob( [ body ], { type: 'application/json' } ) );
				return;
			} catch ( error ) {
				// Fall through to the XHR path.
			}
		}

		var request = new XMLHttpRequest();
		request.open( 'POST', tracking.endpoint, true );
		request.setRequestHeader( 'Content-Type', 'application/json' );
		request.send( body );
	}

	/**
	 * The cart money value for event payloads.
	 *
	 * Uses the first money-based goal's current value (the storefront
	 * payload carries per-goal values, not a cart total).
	 *
	 * @param {Array} goals Progress goal entries.
	 * @return {number}
	 */
	function cartValue( goals ) {
		if ( ! goals ) {
			return 0;
		}

		for ( var i = 0; i < goals.length; i++ ) {
			if ( goals[ i ].is_money && Number( goals[ i ].current ) > 0 ) {
				return Number( goals[ i ].current ) || 0;
			}
		}

		return 0;
	}

	/**
	 * Report the per-goal analytics events for a payload (Phase 16).
	 *
	 * Runs after every render: impressions once per goal per session,
	 * progress when the percentage changed, completion events once per
	 * goal, and suggestion impressions once per goal+product.
	 *
	 * @param {Object} data Progress payload data.
	 * @return {void}
	 */
	function trackGoals( data ) {
		var goals = ( data && data.goals ) || [];
		var value = cartValue( goals );

		for ( var i = 0; i < goals.length; i++ ) {
			var goal = goals[ i ];
			var goalId = String( goal.goal_id || 0 );

			if ( ! reportedImpressions[ goalId ] && goal.eligible !== false ) {
				reportedImpressions[ goalId ] = true;
				sendTrack( 'goal_impression', {
					goal_id: goalId,
					campaign_id: goal.campaign_id || 0,
					cart_value: value,
				} );
			}

			var percentage = Math.round( Number( goal.percentage ) || 0 );

			// Progress is reported only for eligible goals (an ineligible
			// goal never renders a widget — no ghost events for hidden ones).
			if ( goal.eligible !== false && reportedProgress[ goalId ] !== percentage ) {
				reportedProgress[ goalId ] = percentage;
				sendTrack( 'goal_progress', {
					goal_id: goalId,
					campaign_id: goal.campaign_id || 0,
					cart_value: value,
					percentage: percentage,
				} );
			}

			if ( goal.completed && ! reportedCompletions[ goalId ] ) {
				reportedCompletions[ goalId ] = true;
				// A conflict-suppressed reward never reports as activated
				// (Phase 26): only the completion is recorded.
				sendTrack( goal.reward && goal.reward.type && ! rewardBlocked( goal ) ? 'reward_activated' : 'goal_completed', {
					goal_id: goalId,
					campaign_id: goal.campaign_id || 0,
					cart_value: value,
				} );
			}

			if ( goal.suggestions && goal.suggestions.length ) {
				for ( var j = 0; j < goal.suggestions.length; j++ ) {
					var productId = String( goal.suggestions[ j ].id || 0 );
					var key = goalId + ':' + productId;

					if ( productId && ! reportedSuggestionImpressions[ key ] ) {
						reportedSuggestionImpressions[ key ] = true;
						sendTrack( 'suggestion_impression', {
							goal_id: goalId,
							product_id: productId,
						} );
					}
				}
			}
		}
	}

	/**
	 * Report a suggestion click (Phase 16).
	 *
	 * @param {string} goalId    Goal id (data attribute, may be '').
	 * @param {string} productId Product id (data attribute, may be '').
	 * @return {void}
	 */
	function trackSuggestionClick( goalId, productId ) {
		sendTrack( 'suggestion_clicked', {
			goal_id: goalId || 0,
			product_id: productId || 0,
		} );
	}

	/**
	 * Format a money amount with the store currency.
	 *
	 * Phase 18 (Settings → General → currency display): the config's
	 * currencyDisplay (symbol | code | name) becomes Intl's currencyDisplay
	 * option, so stores can show $100, USD 100 or US dollars.
	 *
	 * @param {number} value    Amount.
	 * @param {string} currency ISO code.
	 * @return {string}
	 */
	function formatMoney( value, currency ) {
		try {
			return new Intl.NumberFormat( uiLocale, {
				style: 'currency',
				currency: currency || cfg.currency || 'USD',
				currencyDisplay: cfg.currencyDisplay || 'symbol',
			} ).format( Number( value ) || 0 );
		} catch ( error ) {
			return String( value );
		}
	}

	/**
	 * Whether the widgets should hide on this viewport.
	 *
	 * Phase 18 (Settings → Frontend → mobile behavior): when the config
	 * says 'hide', widgets are suppressed on small screens (the WP admin
	 * mobile breakpoint of 782px).
	 *
	 * @return {boolean}
	 */
	function mobileHidden() {
		if ( ! cfg || cfg.mobile !== 'hide' ) {
			return false;
		}

		if ( ! window.matchMedia ) {
			return false;
		}

		return window.matchMedia( '(max-width: 782px)' ).matches;
	}

	/**
	 * A stable fingerprint of the parts of the progress payload the
	 * widgets render, plus the viewport-driven mobile state.
	 *
	 * Phase 23 (Performance → update only changed UI fragments): two
	 * payloads with the same fingerprint render identically, so refresh()
	 * skips the DOM rebuild for a container that already shows it. The
	 * mobile state is folded in so a resize that crosses the breakpoint
	 * still triggers the hide/show toggle.
	 *
	 * @param {Object} data Progress payload data.
	 * @return {string}
	 */
	function payloadFingerprint( data ) {
		var goals = ( data && data.goals ) || [];
		var parts = [];

		for ( var i = 0; i < goals.length; i++ ) {
			var goal = goals[ i ] || {};
			var suggestionIds = ( goal.suggestions || [] ).map( function ( suggestion ) {
				return String( suggestion.id || 0 ) + ':' + String( suggestion.name || '' );
			} ).join( ',' );

			parts.push(
				[
					String( goal.goal_id || 0 ),
					String( goal.goal_name || '' ),
					String( goal.icon || '' ),
					String( goal.template || '' ),
					String( goal.current || 0 ),
					String( goal.target || 0 ),
					String( goal.percentage || 0 ),
					goal.completed ? '1' : '0',
					goal.eligible === false ? '0' : '1',
					rewardBlocked( goal ) ? '0' : '1',
					String( goal.state || '' ),
					String( goal.message || '' ),
					String( ( goal.reward && goal.reward.type ) || '' ),
					String( ( goal.reward && goal.reward.value ) || '' ),
					suggestionIds,
				].join( '|' )
			);
		}

		return ( ( data && data.currency ) || '' ) + '::' + ( mobileHidden() ? 'm' : 'd' ) + '::' + parts.join( ';' );
	}

	/**
	 * Format a plain number.
	 *
	 * @param {number} value Number.
	 * @return {string}
	 */
	function formatNumber( value ) {
		try {
			return new Intl.NumberFormat( uiLocale ).format( Number( value ) || 0 );
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
	 * Whether a goal's reward is suppressed by a conflict (Phase 26).
	 *
	 * The progress payload resolves conflicts with the same rules the
	 * reward engine grants with; a suppressed reward must never render as
	 * unlocked (the shopper would see a claim the cart does not grant).
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {boolean}
	 */
	function rewardBlocked( goal ) {
		return !!( goal.conflict && goal.conflict.resolved === false );
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

		var blocked = rewardBlocked( goal );
		var unlocked = goal.completed && ! blocked;
		var label = ( cfg.labels && cfg.labels[ reward.type ] ) || reward.type;
		var chip = el( 'span', 'goalcart-reward' );

		chip.classList.add( unlocked ? 'goalcart-reward--unlocked' : 'goalcart-reward--locked' );

		if ( blocked && goal.conflict && goal.conflict.reason ) {
			chip.setAttribute( 'title', String( goal.conflict.reason ) );
		}

		chip.appendChild( el( 'span', 'goalcart-reward__icon', unlocked ? '\u2713' : '\uD83D\uDD12' ) );
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
				// Phase 16 analytics: the ids ride on the link so a delegated
				// click handler can report suggestion_clicked without
				// resolving the product again.
				if ( item.id ) {
					link.setAttribute( 'data-goalcart-suggestion-id', String( item.id ) );
				}
				if ( goal && goal.goal_id ) {
					link.setAttribute( 'data-goalcart-goal-id', String( goal.goal_id ) );
				}
				link.appendChild( el( 'span', 'goalcart-suggestion__name', String( item.name || '' ) ) );
				// price_html is the server-formatted price (Phase 14); fall
				// back to the raw price for hand-built payloads.
				link.appendChild( el( 'span', 'goalcart-suggestion__price', String( item.price_html || item.price || '' ) ) );
				li.appendChild( link );
			} else {
				li.appendChild( el( 'span', 'goalcart-suggestion__name', String( item.name || '' ) ) );
			}

			list.appendChild( li );
		}

		return list;
	}

	/**
	 * The per-widget template: an explicit container override wins, then
	 * the goal's own Display template (the goal builder's template picker),
	 * then the store-wide Appearance template.
	 *
	 * @param {HTMLElement} container Widget container.
	 * @param {Object}      goal     Featured goal entry.
	 * @return {string}
	 */
	function widgetTemplate( container, goal ) {
		var override = container.getAttribute( 'data-goalcart-template' );
		var names = [ 'basic', 'percentage', 'milestone', 'card' ];

		if ( override && names.indexOf( override ) !== -1 ) {
			return override;
		}

		// The goal's Display settings can pin a template per goal; it wins
		// over the store-wide Appearance template.
		if ( goal && goal.template && names.indexOf( goal.template ) !== -1 ) {
			return goal.template;
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
		var ladder = goalMilestones( goals, currency );

		if ( ladder && ! compact ) {
			// The multi-goal ladder is a full-size hero visual — too big
			// for a compact widget (mini cart, shop, product page).
			wrap.appendChild( ladder );
		} else if ( goals && goals.length === 1 ) {
			// A single goal has no ladder to climb, but the milestone
			// template should still show its one threshold as a rung
			// (dot + target label) so it reads as "milestones" rather
			// than a bare bar that looks identical to the basic template.
			// One rung is tiny, so it fits the compact variant too — the
			// template stays visually distinct everywhere.
			var rung = el( 'ol', 'goalcart-milestones' );
			var step = el( 'li', 'goalcart-milestone' );
			var target = goal.is_money
				? formatMoney( goal.target, currency )
				: formatNumber( goal.target );

			if ( goal.completed ) {
				step.classList.add( 'goalcart-milestone--complete' );
			}

			step.appendChild( el( 'span', 'goalcart-milestone__dot' ) );
			step.appendChild( el( 'span', 'goalcart-milestone__target', target ) );
			rung.appendChild( step );
			wrap.appendChild( rung );
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
		// The Phase 13 message state (inactive / unavailable / progressing /
		// nearly_complete / completed / reward_activated) lands as a modifier
		// class so the stylesheet can highlight near-completion etc.
		var stateClass = goal.state ? ' goalcart-state--' + goal.state : '';
		var card = el( 'div', 'goalcart-card goalcart-template--' + template + stateClass );

		var compact = 'compact' === variant;
		var reward = rewardStatus( goal );

		if ( compact ) {
			card.appendChild( templateBody( goal, goals, currency, template, true ) );
			card.appendChild( goalMessage( goal ) );
			if ( reward ) {
				card.appendChild( reward );
			}
			return card;
		}

		var head = el( 'div', 'goalcart-card__head' );
		if ( reward ) {
			head.appendChild( reward );
		}
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
		var template = widgetTemplate( container, goal );

		// The animation toggle (Phase 12) freezes the fill transition via a
		// class; re-render in place on every refresh so live cart updates
		// (AJAX add-to-cart, quantity changes, fragment refreshes) always
		// show the current progress — no mount-once freeze.
		container.classList.toggle( 'goalcart-widget--no-anim', false === cfg.animation );
		container.replaceChildren();

		// Phase 18 (mobile behavior): hide the widget on small screens.
		if ( mobileHidden() ) {
			container.classList.add( 'goalcart-widget--mobile-hidden' );
			return;
		}
		container.classList.remove( 'goalcart-widget--mobile-hidden' );

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

		// Phase 18 (mobile behavior): the sticky bar hides too.
		if ( ! goal || ! hasProgress || stickyDismissed || mobileHidden() ) {
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
	 * Phase 23 (Performance → update only changed UI fragments): each
	 * container is skipped when the payload fingerprint matches its last
	 * render AND it already holds content — a freshly swapped container
	 * (mini-cart fragment refresh) is empty, so it always mounts. The
	 * sticky bar follows the same rule, and analytics stay on their own
	 * per-session dedup.
	 *
	 * @return {void}
	 */
	function refresh() {
		safe( function () {
			fetchProgress( function ( data ) {
				safe( function () {
					var containers = document.querySelectorAll( WIDGET_SELECTOR );
					var fingerprint = payloadFingerprint( data );

					for ( var i = 0; i < containers.length; i++ ) {
						var container = containers[ i ];
						var hasContent = !! container.firstChild;

						if ( hasContent && renderedFingerprints[ container.id ] === fingerprint ) {
							continue;
						}

						renderWidget( container, data );
						renderedFingerprints[ container.id ] = fingerprint;
					}

					if ( stickyFingerprint !== fingerprint ) {
						renderSticky( data );
						stickyFingerprint = fingerprint;
					}

					trackGoals( data );
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
	 * Bind the delegated suggestion-click tracker (Phase 16).
	 *
	 * One listener on document.body covers every widget and the sticky
	 * bar; the suggestion ids ride on the link's data attributes.
	 *
	 * @return {void}
	 */
	function bindSuggestionTracking() {
		if ( ! tracking || ! tracking.endpoint ) {
			return;
		}

		document.body.addEventListener( 'click', function ( event ) {
			var target = event.target;

			while ( target && target !== document.body ) {
				if ( target.classList && target.classList.contains( 'goalcart-suggestion__link' ) ) {
					var goalId = target.getAttribute( 'data-goalcart-goal-id' ) || '';
					var productId = target.getAttribute( 'data-goalcart-suggestion-id' ) || '';
					trackSuggestionClick( goalId, productId );
					return;
				}
				target = target.parentNode;
			}
		} );
	}

	/**
	 * Boot the widgets.
	 *
	 * @return {void}
	 */
	function init() {
		bindCartEvents();
		bindSuggestionTracking();

		// Phase 18 (mobile behavior): re-render when the viewport crosses
		// the mobile breakpoint so hidden widgets appear/disappear live.
		if ( cfg.mobile === 'hide' ) {
			window.addEventListener( 'resize', refresh );
		}

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
