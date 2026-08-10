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
 *   RewardStatus     locked / unlocked reward chip
 *   SuggestionList   product suggestions (Phase 14: served by the
 *                     SuggestionEngine, price shown server-formatted)
 *   StickyGoalBar    fixed bottom bar (cart/checkout progress at a glance)
 *
 * Every eligible goal renders as its own card, stacked in a shared
 * wrapper (`.goalcart-widget__goals`) — a campaign's milestones each get
 * a full card instead of one featured card + a tiny ladder. Each card
 * sees only itself, so the milestone template degrades to the goal's own
 * single rung.
 *
 * Templates (P12): the goal body renders per the active variant — basic
 * (bar), percentage (big % + bar), milestone (single threshold rung +
 * bar), card (icon + title + bar) or ring (circular gauge) — driven by
 * `cfg.template` or a per-container `data-goalcart-template` override.
 * Appearance tokens (colors, radius, bar height) come from the same
 * config; the animation toggle adds a no-transition class when disabled.
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

	// Phase 32: per-session state for the celebration animation (one burst
	// per goal), the sticky bar's delay gating and the auto-hide scroll
	// direction tracker.
	var celebrated = {};
	var stickyShown = false;
	var stickyDelayTimer = null;
	var stickyLastScrollY = 0;
	var stickyAutoHidden = false;

	// Confetti palette (Phase 32 celebration).
	var CONFETTI_COLORS = [ '#2271b1', '#00a32a', '#d63638', '#f0b849', '#7e5af5', '#2c7a7b' ];

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

	// Race guard (live cart updates): every fetch gets a monotonically
	// increasing epoch. When a newer refresh starts while an older one is
	// still in flight, the older request is aborted and its response is
	// ignored — a stale response can never overwrite fresher progress
	// (e.g. two rapid cart changes, or a poll racing a cart event).
	var fetchEpoch = 0;
	var activeFetch = null;

	/**
	 * Fetch the progress payload.
	 *
	 * Cache-busting: the guest REST payload carries no Cache-Control
	 * header (WP core only sends nocache headers for cookie-authenticated
	 * requests), so a bare GET can be heuristically cached by the browser
	 * and serve a STALE payload — the bar would keep showing the previous
	 * cart's progress after the shopper adds or removes items. A unique
	 * timestamp query parameter forces a fresh evaluation every poll.
	 *
	 * @param {Function} done  Callback receiving the parsed `data` object.
	 * @param {Function} [ended] Called when the request finishes (success,
	 *                          failure or superseded) — used to clear the
	 *                          transient "updating" state.
	 * @return {void}
	 */
	function fetchProgress( done, ended ) {
		var request = new XMLHttpRequest();
		var separator = cfg.endpoint.indexOf( '?' ) >= 0 ? '&' : '?';
		var myEpoch = ++fetchEpoch;

		// A newer refresh supersedes this one: abort the in-flight request
		// so its response can never reach the widgets, then take its place.
		if ( activeFetch ) {
			try {
				activeFetch.abort();
			} catch ( error ) {}
		}
		activeFetch = request;

		function finish() {
			// Only the CURRENT request may release the "updating" state; a
			// superseded request's late callback is a no-op.
			if ( activeFetch === request ) {
				activeFetch = null;
				if ( ended ) {
					ended();
				}
			}
		}

		request.open( 'GET', cfg.endpoint + separator + '_=' + Date.now(), true );
		request.timeout = 10000;

		request.onload = function () {
			if ( myEpoch !== fetchEpoch ) {
				finish();
				return;
			}

			if ( request.status < 200 || request.status >= 300 ) {
				finish();
				return;
			}

			safe( function () {
				var payload = JSON.parse( request.responseText );
				if ( payload && payload.data ) {
					// Self-healing tracking nonce: every /progress response
					// carries a freshly minted goalcart_track nonce. Adopt it
					// before the next event report so a cached page's expired
					// or foreign nonce can never block analytics for the rest
					// of the session.
					if ( tracking && payload.data.tracking_nonce ) {
						tracking.nonce = payload.data.tracking_nonce;
					}

					// Phase 32 (free gift selection): the payload also
					// mints a fresh gift nonce every poll, so a long-lived
					// cart page never outlives its gift-claim nonce window
					// (adopt it before the shopper claims a gift).
					if ( payload.data.gift_nonce ) {
						cfg.giftNonce = payload.data.gift_nonce;
					}

					done( payload.data );
				}
			} );

			finish();
		};

		request.onerror = function () { finish(); };
		request.ontimeout = function () { finish(); };
		// Safety net: fires on success, error, timeout AND abort, so the
		// "updating" state can never linger behind a request that ends in
		// any way (finish() is idempotent — the activeFetch guard makes
		// superseded requests no-ops).
		request.onloadend = function () { finish(); };
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
					String( goal.countdown_end || '' ),
					( goal.reward && goal.reward.gift_chosen ) ? '1' : '0',
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
	 * A localized text label with a sensible fallback.
	 *
	 * @param {string} key      Label key in cfg.labels.
	 * @param {string} fallback Fallback text.
	 * @return {string}
	 */
	function uiLabel( key, fallback ) {
		return ( cfg.labels && cfg.labels[ key ] ) ? String( cfg.labels[ key ] ) : String( fallback );
	}

	/**
	 * The live countdown text for an ISO end timestamp (Phase 32).
	 *
	 * @param {string} end ISO local-time timestamp.
	 * @return {string}
	 */
	function countdownText( end ) {
		var ms = Date.parse( String( end || '' ) ) - Date.now();

		if ( isNaN( ms ) ) {
			return '';
		}

		if ( ms <= 0 ) {
			return uiLabel( 'countdown_ended', 'Ended' );
		}

		var total = Math.floor( ms / 1000 );
		var days = Math.floor( total / 86400 );
		var hours = Math.floor( ( total % 86400 ) / 3600 );
		var minutes = Math.floor( ( total % 3600 ) / 60 );
		var seconds = total % 60;

		function pad2( n ) {
			return n < 10 ? '0' + n : '' + n;
		}

		var time = formatNumber( pad2( hours ) ) + ':' + formatNumber( pad2( minutes ) ) + ':' + formatNumber( pad2( seconds ) );

		return days > 0 ? formatNumber( days ) + 'd ' + time : time;
	}

	/**
	 * Countdown chip for a goal/campaign with an end time (Phase 32).
	 *
	 * The chip carries the end timestamp on a data attribute; a single
	 * global ticker (started in init) rewrites the readout every second
	 * without re-rendering the widget.
	 *
	 * @param {Object} entry Goal or campaign group with countdown_end.
	 * @return {HTMLElement|null}
	 */
	function countdownPanel( entry ) {
		if ( ! entry || ! entry.countdown_end || cfg.countdown === false ) {
			return null;
		}

		var wrap = el( 'div', 'goalcart-countdown' );

		wrap.appendChild( el( 'span', 'goalcart-countdown__label', uiLabel( 'countdown', 'Ends in' ) ) );

		var time = el( 'span', 'goalcart-countdown__time' );
		time.setAttribute( 'data-goalcart-end', String( entry.countdown_end ) );
		time.textContent = countdownText( entry.countdown_end );
		wrap.appendChild( time );

		return wrap;
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
	 * GiftPicker — the shopper's free-gift selection (Phase 32).
	 *
	 * Renders for completed goals whose free-gift reward is in "choose"
	 * mode: one button per candidate gift. Clicking claims the gift through
	 * the public gift endpoint (nonce-guarded), then the widgets refresh
	 * and the picker flips to its "added" state.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement|null}
	 */
	function giftPicker( goal ) {
		var reward = goal.reward || null;

		if ( ! reward || reward.type !== 'free_gift' || ! reward.gift || ! reward.gift.length ) {
			return null;
		}

		if ( ! cfg.giftEndpoint || ! cfg.giftNonce ) {
			return null;
		}

		var picker = el( 'div', 'goalcart-gift-picker' );

		if ( reward.gift_chosen ) {
			picker.classList.add( 'goalcart-gift-picker--done' );
			picker.appendChild( el( 'p', 'goalcart-gift-picker__done', uiLabel( 'gift_chosen', 'Gift added to your cart' ) ) );
			return picker;
		}

		picker.appendChild( el( 'div', 'goalcart-gift-picker__title', uiLabel( 'gift_picker', 'Pick your free gift' ) ) );

		var list = el( 'ul', 'goalcart-gift-picker__list' );

		for ( var i = 0; i < reward.gift.length; i++ ) {
			var item = reward.gift[ i ];
			var li = el( 'li', 'goalcart-gift-picker__item' );
			var button = el( 'button', 'goalcart-gift-picker__button' );

			button.type = 'button';
			button.setAttribute( 'data-goalcart-gift-product', String( item.id || 0 ) );
			button.setAttribute( 'data-goalcart-gift-goal', String( goal.goal_id || 0 ) );

			if ( item.image ) {
				var img = el( 'img', 'goalcart-gift-picker__image' );
				img.src = String( item.image );
				img.alt = '';
				button.appendChild( img );
			}

			button.appendChild( el( 'span', 'goalcart-gift-picker__name', String( item.name || '' ) ) );

			if ( item.price_html ) {
				button.appendChild( el( 'span', 'goalcart-gift-picker__price', String( item.price_html ) ) );
			}

			li.appendChild( button );
			list.appendChild( li );
		}

		picker.appendChild( list );

		return picker;
	}

	/**
	 * Claim a chosen gift through the public gift endpoint.
	 *
	 * @param {HTMLElement} button The gift button clicked.
	 * @return {void}
	 */
	function claimGift( button ) {
		var goalId = button.getAttribute( 'data-goalcart-gift-goal' ) || '0';
		var productId = button.getAttribute( 'data-goalcart-gift-product' ) || '0';

		if ( ! goalId || ! productId || button.disabled ) {
			return;
		}

		button.disabled = true;
		button.classList.add( 'goalcart-gift-picker__button--pending' );

		var body;
		try {
			body = JSON.stringify( {
				goal_id: Number( goalId ) || 0,
				product_id: Number( productId ) || 0,
				nonce: cfg.giftNonce || '',
			} );
		} catch ( error ) {
			button.disabled = false;
			button.classList.remove( 'goalcart-gift-picker__button--pending' );
			return;
		}

		var request = new XMLHttpRequest();

		request.open( 'POST', cfg.giftEndpoint, true );
		request.setRequestHeader( 'Content-Type', 'application/json' );
		request.timeout = 10000;

		request.onload = function () {
			if ( request.status >= 200 && request.status < 300 ) {
				emitCartChanged();
				return;
			}

			// A failed claim (nonce, stock) re-enables the button so the
			// shopper can retry after the next poll adopts a fresh nonce.
			button.disabled = false;
			button.classList.remove( 'goalcart-gift-picker__button--pending' );
		};

		request.onerror = function () {
			button.disabled = false;
			button.classList.remove( 'goalcart-gift-picker__button--pending' );
		};
		request.ontimeout = function () {
			button.disabled = false;
			button.classList.remove( 'goalcart-gift-picker__button--pending' );
		};
		request.send( body );
	}

	/**
	 * The per-widget template: an explicit container override wins, then
	 * the goal's own Display template (the goal builder's template picker),
	 * then the store-wide Appearance template.
	 * 	 * @param {HTMLElement} container Widget container.
	 * @param {Object}      goal     Goal this card renders (may be null).
	 * @return {string}
	 */
	function widgetTemplate( container, goal ) {
		var override = container.getAttribute( 'data-goalcart-template' );
		var names = [ 'basic', 'percentage', 'milestone', 'card', 'ring', 'milestone_chain' ];

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
	 * Apply a template's resolved settings to a node as CSS custom
	 * properties (pluggable template engine).
	 *
	 * The backend resolves each goal's effective template settings (item
	 * override → scope default → legacy → fallback) and ships them in the
	 * payload; the stylesheet reads the same --goalcart-* custom
	 * properties the global Appearance settings override, so a per-goal
	 * (or per-campaign) template styles exactly what its settings say.
	 *
	 * @param {HTMLElement} node     Element to style.
	 * @param {Object}      settings Resolved template settings.
	 * @return {void}
	 */
	function applyTemplateSettings( node, settings ) {
		if ( ! settings || typeof settings !== 'object' ) {
			return;
		}

		var map = {
			accent: '--goalcart-accent',
			bg: '--goalcart-bg',
			border: '--goalcart-border',
			text: '--goalcart-text',
			radius: '--goalcart-radius',
			barHeight: '--goalcart-bar-height',
			percentColor: '--goalcart-percent-color',
			percentSize: '--goalcart-percent-size',
			dotColor: '--goalcart-dot-color',
			doneColor: '--goalcart-done-color',
			connectorColor: '--goalcart-connector-color'
		};

		for ( var key in map ) {
			if ( ! Object.prototype.hasOwnProperty.call( settings, key ) ) {
				continue;
			}

			var value = settings[ key ];

			if ( value === undefined || value === null || value === '' ) {
				continue;
			}

			var isPx = 'radius' === key || 'barHeight' === key || 'percentSize' === key;
			node.style.setProperty( map[ key ], isPx ? Number( value ) + 'px' : String( value ) );
		}
	}

	/**
	 * Inject a template's custom CSS once per unique payload.
	 *
	 * Custom CSS is admin-authored and sanitized server-side (tag-free,
	 * bounded); it renders into a single cached <style> element so a page
	 * with several templates never duplicates rules.
	 *
	 * @param {string} css Custom CSS.
	 * @return {void}
	 */
	function applyTemplateCss( css ) {
		if ( ! css ) {
			return;
		}

		var styleId = 'goalcart-template-css-' + hashString( css );

		if ( document.getElementById( styleId ) ) {
			return;
		}

		var style = el( 'style', '' );
		style.id = styleId;
		style.textContent = css;
		document.head.appendChild( style );
	}

	/**
	 * A tiny non-cryptographic hash (djb2) used to dedupe injected CSS.
	 *
	 * @param {string} value Input string.
	 * @return {number}
	 */
	function hashString( value ) {
		var hash = 5381;

		for ( var i = 0; i < value.length; i++ ) {
			hash = ( ( hash << 5 ) + hash ) + value.charCodeAt( i );
		}

		return Math.abs( hash );
	}

	/**
	 * Percentage template — a large percent readout above the bar.
	 *
	 * @param {Object}  goal    Progress goal entry.
	 * @param {boolean} showBar Whether the bar renders under the percent.
	 * @return {HTMLElement}
	 */
	function percentagePanel( goal, showBar ) {
		var wrap = el( 'div', 'goalcart-percentage' );
		var percent = Math.max( 0, Math.min( 100, Number( goal.percentage ) || 0 ) );

		wrap.appendChild( el( 'span', 'goalcart-percentage__value', Math.round( percent ) + '%' ) );
		if ( false !== showBar ) {
			wrap.appendChild( progressBar( goal ) );
		}

		return wrap;
	}

	/**
	 * Milestone template — the goal's own threshold as a single rung
	 * (dot + target label) above the bar.
	 *
	 * Every goal renders as its own card now, so there is no cross-goal
	 * ladder to climb: the milestone template shows the one threshold this
	 * card is tracking, keeping the template visually distinct from basic
	 * without duplicating the other goals' cards. The single rung is tiny,
	 * so it fits the compact variant too.
	 *
	 * @param {Object}  goal     Goal this card renders.
	 * @param {string}  currency ISO currency code.
	 * @return {HTMLElement}
	 */
	function milestonePanel( goal, currency, showBar ) {
		var wrap = el( 'div', 'goalcart-milestone-panel' );
		var rung = el( 'ol', 'goalcart-milestones' );
		var step = el( 'li', 'goalcart-milestone' );
		var settings = goal.template_settings || {};
		var showLabels = settings.showLabels !== false;
		var target = goal.is_money
			? formatMoney( goal.target, currency )
			: formatNumber( goal.target );

		if ( goal.completed ) {
			step.classList.add( 'goalcart-milestone--complete' );
		}

		step.appendChild( el( 'span', 'goalcart-milestone__dot' ) );
		if ( showLabels ) {
			step.appendChild( el( 'span', 'goalcart-milestone__target', target ) );
		}
		rung.appendChild( step );
		wrap.appendChild( rung );
		if ( false !== showBar ) {
			wrap.appendChild( progressBar( goal ) );
		}

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
	 * Ring template — a circular gauge (SVG circle instead of a fill bar).
	 *
	 * The target renders as a ring whose stroke-dashoffset draws exactly
	 * `percentage` of the circumference; the percent readout sits centered
	 * inside (locale-aware digits via formatNumber, so Persian stores see
	 * Persian digits). The ring-specific settings (size, stroke width,
	 * track color) come from the resolved template settings; the shared
	 * accent drives the progress stroke.
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement}
	 */
	function ringPanel( goal ) {
		var settings = goal.template_settings || {};
		var percent = Math.max( 0, Math.min( 100, Number( goal.percentage ) || 0 ) );
		var size = Number( settings.ringSize ) || 120;
		var stroke = Number( settings.strokeWidth ) || 12;
		var accent = settings.accent || '#2271b1';
		var trackColor = settings.trackColor || '#f0f0f1';
		var showPercent = settings.showPercent !== false;
		var radius = ( size - stroke ) / 2;
		var circumference = 2 * Math.PI * radius;
		var NS = 'http://www.w3.org/2000/svg';

		var wrap = el( 'div', 'goalcart-ring' );
		var svg = document.createElementNS( NS, 'svg' );
		var track = document.createElementNS( NS, 'circle' );
		var fill = document.createElementNS( NS, 'circle' );

		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'width', String( size ) );
		svg.setAttribute( 'height', String( size ) );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'class', 'goalcart-ring__svg' );

		// Track: the full circle behind the progress stroke.
		track.setAttribute( 'class', 'goalcart-ring__track' );
		track.setAttribute( 'cx', String( size / 2 ) );
		track.setAttribute( 'cy', String( size / 2 ) );
		track.setAttribute( 'r', String( radius ) );
		track.setAttribute( 'fill', 'none' );
		track.setAttribute( 'stroke', trackColor );
		track.setAttribute( 'stroke-width', String( stroke ) );

		// Progress: same circle, dashed so only `percent` of the ring is
		// drawn, rotated to start at 12 o'clock.
		fill.setAttribute( 'class', 'goalcart-ring__fill' );
		fill.setAttribute( 'cx', String( size / 2 ) );
		fill.setAttribute( 'cy', String( size / 2 ) );
		fill.setAttribute( 'r', String( radius ) );
		fill.setAttribute( 'fill', 'none' );
		fill.setAttribute( 'stroke', accent );
		fill.setAttribute( 'stroke-width', String( stroke ) );
		// Round caps can render a residual dot at the start point when the
		// dash is empty (0%), so fall back to butt caps at zero progress.
		fill.setAttribute( 'stroke-linecap', 0 === percent ? 'butt' : 'round' );
		fill.setAttribute( 'stroke-dasharray', String( circumference ) );
		fill.setAttribute( 'stroke-dashoffset', String( circumference * ( 1 - percent / 100 ) ) );
		fill.setAttribute( 'transform', 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' );

		svg.appendChild( track );
		svg.appendChild( fill );
		wrap.appendChild( svg );

		if ( showPercent ) {
			wrap.appendChild( el( 'span', 'goalcart-ring__percent', formatNumber( Math.round( percent ) ) + '%' ) );
		}

		return wrap;
	}

	/**
	 * The template's core visual (everything except the shared message /
	 * reward chip / suggestion flow).
	 *
	 * @param {Object}  goal     Goal this card renders.
	 * @param {string}  currency ISO currency code.
	 * @param {string}  template Template variant.
	 * @return {HTMLElement}
	 */
	function templateBody( goal, currency, template, showBar ) {
		switch ( template ) {
			case 'percentage':
				return percentagePanel( goal, showBar );
			case 'milestone':
				return milestonePanel( goal, currency, showBar );
			case 'card':
				return cardPanel( goal );
			case 'ring':
				return ringPanel( goal );
			default:
				return false === showBar ? null : progressBar( goal );
		}
	}

	/**
	 * GoalContainer — the widget body for one goal's card.
	 *
	 * Full: reward chip + template body + message + suggestions. Compact:
	 * template body + message + reward chip. Every eligible goal renders
	 * as its own card (renderWidget stacks them), so there is no
	 * cross-goal ladder here anymore.
	 *
	 * @param {Object} goal     Goal this card renders.
	 * @param {string} currency ISO currency code.
	 * @param {string} variant  full|compact.
	 * @param {string} template Template variant.
	 * @return {HTMLElement}
	 */
	function goalContainer( goal, currency, variant, template ) {
		// The Phase 13 message state (inactive / unavailable / progressing /
		// nearly_complete / completed / reward_activated) lands as a modifier
		// class so the stylesheet can highlight near-completion etc.
		var stateClass = goal.state ? ' goalcart-state--' + goal.state : '';
		var card = el( 'div', 'goalcart-card goalcart-template--' + template + stateClass );
		var settings = goal.template_settings || {};

		// The resolved template settings drive this card's appearance
		// (colors, radius, bar height) through the shared CSS variables.
		applyTemplateSettings( card, settings );
		applyTemplateCss( settings.customCss );

		if ( settings.cssClass ) {
			card.classList.add( String( settings.cssClass ) );
		}

		var compact = 'compact' === variant;
		var reward = rewardStatus( goal );
		var showReward = 'card' !== template || settings.showReward !== false;
		var showMessage = settings.showMessage !== false;

		if ( compact ) {
			var compactBody = templateBody( goal, currency, template, settings.showBar );
			if ( compactBody ) {
				card.appendChild( compactBody );
			}
			if ( showMessage ) {
				card.appendChild( goalMessage( goal ) );
			}
			if ( reward && showReward ) {
				card.appendChild( reward );
			}
			return card;
		}

		var head = el( 'div', 'goalcart-card__head' );
		if ( reward && showReward ) {
			head.appendChild( reward );
		}
		card.appendChild( head );

		var body = templateBody( goal, currency, template, settings.showBar );
		if ( body ) {
			card.appendChild( body );
		}

		if ( showMessage ) {
			card.appendChild( goalMessage( goal ) );
		}

		// Phase 32 (countdown + free gift selection): the deadline chip and
		// the gift picker render at the bottom of the full card.
		var countdown = countdownPanel( goal );
		if ( countdown ) {
			card.appendChild( countdown );
		}

		var suggestions = suggestionList( goal );
		if ( suggestions ) {
			card.appendChild( suggestions );
		}

		var gift = giftPicker( goal );
		if ( gift ) {
			card.appendChild( gift );
		}

		return card;
	}

	/**
	 * Celebration — a confetti burst + pulse when a goal completes
	 * (Phase 32).
	 *
	 * Runs once per goal per session (the `celebrated` map). The pieces are
	 * plain CSS-animated spans removed after the animation, so nothing
	 * lingers on the page.
	 *
	 * @param {HTMLElement} card The goal card.
	 * @param {Object}      goal Progress goal entry.
	 * @return {void}
	 */
	function celebrate( card, goal ) {
		celebrated[ String( goal.goal_id || 0 ) ] = true;

		card.classList.add( 'goalcart-card--celebrate' );

		var confetti = el( 'div', 'goalcart-confetti' );
		confetti.setAttribute( 'aria-hidden', 'true' );

		for ( var i = 0; i < 18; i++ ) {
			var piece = el( 'span', 'goalcart-confetti__piece' );
			piece.style.left = ( Math.random() * 100 ) + '%';
			piece.style.background = CONFETTI_COLORS[ i % CONFETTI_COLORS.length ];
			piece.style.animationDelay = ( Math.random() * 0.35 ) + 's';
			piece.style.setProperty( '--goalcart-confetti-x', ( ( Math.random() * 160 ) - 80 ) + 'px' );
			confetti.appendChild( piece );
		}

		card.appendChild( confetti );

		window.setTimeout( function () {
			try {
				confetti.remove();
			} catch ( error ) {}
		}, 2000 );
	}

	/**
	 * Campaign milestone chain (pluggable engine, campaign scope).
	 *
	 * Renders a campaign's milestones as one connected ladder — dots,
	 * names, targets and rewards per step, with an overall progress bar
	 * driven by the top milestone. Used when the campaign's resolved
	 * template is 'milestone_chain'; otherwise the campaign's goals render
	 * as individual cards.
	 *
	 * @param {Array}  goals    The campaign's eligible milestone goals.
	 * @param {Object} campaign Campaign group (template + resolved settings).
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	function campaignChain( goals, campaign, currency ) {
		var settings = campaign.settings || {};
		var panel = el( 'div', 'goalcart-chain goalcart-template--' + campaign.template );

		applyTemplateSettings( panel, settings );
		applyTemplateCss( settings.customCss );

		if ( settings.cssClass ) {
			panel.classList.add( String( settings.cssClass ) );
		}

		if ( campaign.name ) {
			panel.appendChild( el( 'div', 'goalcart-chain__title', String( campaign.name ) ) );
		}

		var rung = el( 'ol', 'goalcart-chain__steps' );

		for ( var i = 0; i < goals.length; i++ ) {
			var goal = goals[ i ];
			var step = el( 'li', 'goalcart-chain__step' );

			step.classList.add( goal.completed ? 'goalcart-chain__step--done' : 'goalcart-chain__step--pending' );
			step.appendChild( el( 'span', 'goalcart-chain__dot' ) );

			if ( settings.showLabels !== false ) {
				step.appendChild( el( 'span', 'goalcart-chain__label', String( goal.goal_name || '' ) ) );
			}

			if ( settings.showTargets !== false ) {
				var target = goal.is_money
					? formatMoney( goal.target, currency )
					: formatNumber( goal.target );
				step.appendChild( el( 'span', 'goalcart-chain__target', target ) );
			}

			if ( settings.showRewards !== false && goal.reward && goal.reward.type ) {
				step.appendChild( el( 'span', 'goalcart-chain__reward', ( cfg.labels && cfg.labels[ goal.reward.type ] ) || goal.reward.type ) );
			}

			rung.appendChild( step );
		}

		panel.appendChild( rung );

		// Overall progress: the top milestone drives the bar.
		var top = null;

		for ( var j = 0; j < goals.length; j++ ) {
			if ( ! top || Number( goals[ j ].target ) > Number( top.target ) ) {
				top = goals[ j ];
			}
		}

		if ( top ) {
			panel.appendChild( progressBar( top ) );
		}

		return panel;
	}

	/**
	 * Campaign progress (pluggable engine, campaign scope — Phase 32).
	 *
	 * Renders the whole campaign as one readout: title, a "n / m"
	 * milestone counter, one bar driven by the top milestone, the reward
	 * chips and an optional countdown. Used when the campaign's resolved
	 * template is 'campaign_progress'; the milestone_chain stays the
	 * connected-ladder variant.
	 *
	 * @param {Array}  goals    The campaign's eligible milestone goals.
	 * @param {Object} campaign Campaign group (template + resolved settings).
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	function campaignProgress( goals, campaign, currency ) {
		var settings = campaign.settings || {};
		var panel = el( 'div', 'goalcart-campaign goalcart-template--' + campaign.template );

		applyTemplateSettings( panel, settings );
		applyTemplateCss( settings.customCss );

		if ( settings.cssClass ) {
			panel.classList.add( String( settings.cssClass ) );
		}

		if ( settings.showTitle !== false && campaign.name ) {
			panel.appendChild( el( 'div', 'goalcart-campaign__title', String( campaign.name ) ) );
		}

		if ( settings.showCounter !== false ) {
			var done = 0;

			for ( var i = 0; i < goals.length; i++ ) {
				if ( goals[ i ].completed ) {
					done++;
				}
			}

			panel.appendChild(
				el( 'div', 'goalcart-campaign__counter', formatNumber( done ) + ' / ' + formatNumber( goals.length ) )
			);
		}

		// Overall progress: the top milestone drives the bar.
		var top = null;

		for ( var j = 0; j < goals.length; j++ ) {
			if ( ! top || Number( goals[ j ].target ) > Number( top.target ) ) {
				top = goals[ j ];
			}
		}

		if ( top ) {
			panel.appendChild( progressBar( top ) );
		}

		if ( settings.showRewards !== false ) {
			var chips = el( 'div', 'goalcart-campaign__rewards' );

			for ( var k = 0; k < goals.length; k++ ) {
				if ( goals[ k ].reward && goals[ k ].reward.type ) {
					chips.appendChild(
						el( 'span', 'goalcart-campaign__reward', ( cfg.labels && cfg.labels[ goals[ k ].reward.type ] ) || goals[ k ].reward.type )
					);
				}
			}

			if ( chips.firstChild ) {
				panel.appendChild( chips );
			}
		}

		var countdown = countdownPanel( campaign );
		if ( countdown ) {
			panel.appendChild( countdown );
		}

		return panel;
	}

	/* ------------------------------------------------------------------ *
	 * Mounting
	 * ------------------------------------------------------------------ */ 	/**
	 * Render the progress payload into a single widget container.
	 *
	 * Every eligible goal renders as its own card, stacked in a shared
	 * wrapper — a campaign's milestones each get a full card instead of
	 * one featured card + a tiny ladder. Each card resolves its own
	 * template (per-widget override → goal Display template → global
	 * Appearance template) and sees only itself, so the milestone
	 * template degrades to the goal's own single rung.
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
		var variant = 'compact' === container.getAttribute( 'data-goalcart-variant' ) ? 'compact' : 'full';

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

		// Group the eligible goals by campaign so a campaign template
		// (e.g. the milestone chain) can render the whole group as one
		// unit. Campaign groups without a configured campaign template —
		// and every standalone goal — render as individual cards.
		var groups = {};
		var order = [];

		for ( var i = 0; i < goals.length; i++ ) {
			var goal = goals[ i ];

			if ( ! goal || goal.eligible === false ) {
				continue;
			}

			var groupId = goal.campaign_id || 0;

			if ( ! groups[ groupId ] ) {
				groups[ groupId ] = [];
				order.push( groupId );
			}

			groups[ groupId ].push( goal );
		}

		var campaigns = ( data && data.campaigns ) || [];
		var campaignById = {};

		for ( var c = 0; c < campaigns.length; c++ ) {
			campaignById[ campaigns[ c ].campaign_id ] = campaigns[ c ];
		}

		// Stack one card per eligible goal (or one chain per campaign
		// group). Ineligible goals never render (they are skipped, not
		// broken), and when nothing is left the whole widget hides.
		var rendered = 0;
		var stack = el( 'div', 'goalcart-widget__goals' );

		for ( var g = 0; g < order.length; g++ ) {
			var groupGoals = groups[ order[ g ] ];
			var campaign = campaignById[ order[ g ] ];

			if ( campaign && campaign.template ) {
				if ( 'milestone_chain' === campaign.template ) {
					stack.appendChild( campaignChain( groupGoals, campaign, data.currency || cfg.currency ) );
					rendered++;
					continue;
				}

				// Phase 32: the second campaign template — one overall bar.
				if ( 'campaign_progress' === campaign.template ) {
					stack.appendChild( campaignProgress( groupGoals, campaign, data.currency || cfg.currency ) );
					rendered++;
					continue;
				}
			}

			for ( var j = 0; j < groupGoals.length; j++ ) {
				var goal = groupGoals[ j ];
				var card = goalContainer( goal, data.currency || cfg.currency, variant, widgetTemplate( container, goal ) );

				// Phase 32 (celebration): one confetti burst + pulse per
				// completed goal per session.
				if ( goal.completed && cfg.celebrate && ! celebrated[ String( goal.goal_id || 0 ) ] ) {
					celebrate( card, goal );
				}

				stack.appendChild( card );
				rendered++;
			}
		}

		if ( ! rendered ) {
			container.classList.add( 'goalcart-widget--empty' );
			return;
		}

		container.classList.remove( 'goalcart-widget--empty' );
		container.appendChild( stack );
	}

	/**
	 * StickyGoalBar — a fixed progress bar (bottom by default, Phase 32
	 * adds the top position, an appearance delay and an auto-hide
	 * behavior).
	 *
	 * Visible only while the cart has progress to show (current > 0 or the
	 * goal is completed). dismissible mode keeps the close button
	 * (session-persistent); auto_hide mode fades the bar out while
	 * scrolling down and back in while scrolling up. The full display
	 * variant adds the countdown chip and the top suggestion.
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
		var sticky = cfg.sticky || {};

		for ( var i = 0; i < goals.length; i++ ) {
			if ( Number( goals[ i ].current ) > 0 || goals[ i ].completed ) {
				hasProgress = true;
				break;
			}
		}

		var visible = ! ! goal && hasProgress && ! stickyDismissed && ! mobileHidden();

		// Delay gate: the bar waits sticky.delay seconds after the first
		// render before appearing (the timer in init flips stickyShown).
		if ( visible && Number( sticky.delay ) > 0 && ! stickyShown ) {
			visible = false;
		}

		// Auto-hide: hidden while scrolling down (past a small threshold),
		// shown again when scrolling up or near the top.
		if ( visible && sticky.behavior === 'auto_hide' ) {
			var y = window.pageYOffset || document.documentElement.scrollTop || 0;

			if ( y > 120 && y > stickyLastScrollY ) {
				visible = false;
				stickyAutoHidden = true;
			}
		}

		if ( ! visible ) {
			bar.classList.remove( 'goalcart-sticky--visible' );
			bar.setAttribute( 'aria-hidden', 'true' );
			bar.replaceChildren();
			return;
		}

		bar.classList.add( 'goalcart-sticky--visible' );
		bar.classList.toggle( 'goalcart-sticky--top', 'top' === sticky.position );
		bar.classList.toggle( 'goalcart-no-anim', false === cfg.animation );
		bar.setAttribute( 'aria-hidden', 'false' );

		// Rebuild the bar content on every refresh so the fill and message
		// track the live cart (no mount-once freeze).
		var inner = el( 'div', 'goalcart-sticky__inner' );
		var content = el( 'div', 'goalcart-sticky__content' );
		var reward = rewardStatus( goal );

		content.appendChild( progressBar( goal ) );
		content.appendChild( goalMessage( goal ) );
		if ( reward ) {
			content.appendChild( reward );
		}

		if ( sticky.countdown && goal.countdown_end ) {
			var countdown = countdownPanel( goal );

			if ( countdown ) {
				content.appendChild( countdown );
			}
		}

		// Full display: the top suggestion rides along as a small link.
		if ( sticky.display === 'full' && sticky.suggestions ) {
			var suggestion = stickySuggestion( goal );

			if ( suggestion ) {
				content.appendChild( suggestion );
			}
		}

		inner.appendChild( content );

		if ( sticky.behavior !== 'auto_hide' ) {
			var close = el( 'button', 'goalcart-sticky__close' );

			close.type = 'button';
			close.setAttribute( 'aria-label', uiLabel( 'dismiss', 'Dismiss' ) );
			close.textContent = '\u00D7';
			close.addEventListener( 'click', function () {
				stickyDismissed = true;
				bar.classList.remove( 'goalcart-sticky--visible' );
				bar.setAttribute( 'aria-hidden', 'true' );
			} );
			inner.appendChild( close );
		}

		bar.replaceChildren( inner );
	}

	/**
	 * The top suggestion as a single compact link (sticky bar full mode).
	 *
	 * @param {Object} goal Progress goal entry.
	 * @return {HTMLElement|null}
	 */
	function stickySuggestion( goal ) {
		var items = ( goal.suggestions && goal.suggestions.length ) ? goal.suggestions : [];

		if ( ! items.length ) {
			return null;
		}

		var item = items[ 0 ];
		var link = el( 'a', 'goalcart-sticky__suggestion' );

		if ( item.permalink && isSafeUrl( item.permalink ) ) {
			link.href = String( item.permalink );
		}

		link.setAttribute( 'rel', 'noreferrer' );
		link.appendChild( el( 'span', 'goalcart-sticky__suggestion-name', String( item.name || '' ) ) );
		link.appendChild( el( 'span', 'goalcart-sticky__suggestion-price', String( item.price_html || item.price || '' ) ) );

		return link;
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
	/**
	 * Toggle the subtle "updating" feedback across every mounted widget
	 * (cart-change refreshes only — never a blank/unmount or a flash).
	 *
	 * @param {boolean} on Whether the widgets are refreshing.
	 * @return {void}
	 */
	function setUpdating( on ) {
		var containers = document.querySelectorAll( WIDGET_SELECTOR );

		for ( var i = 0; i < containers.length; i++ ) {
			containers[ i ].classList.toggle( 'goalcart-widget--updating', !! on );
		}

		var bar = document.getElementById( STICKY_ID );

		if ( bar ) {
			bar.classList.toggle( 'goalcart-widget--updating', !! on );
		}
	}

	/**
	 * Refresh every mounted widget from the progress endpoint.
	 *
	 * @param {Object} [options] Options.
	 * @param {boolean} [options.updating] Show the subtle updating state
	 *                                     while this refresh is in flight
	 *                                     (cart-change refreshes).
	 * @return {void}
	 */
	function refresh( options ) {
		safe( function () {
			options = options || {};

			if ( options.updating ) {
				setUpdating( true );
			}

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
			}, function () {
				safe( function () {
					setUpdating( false );
				} );
			} );
		} );
	}

	// Cart-change refresh timers. WooCommerce fires several events per
	// mutation (added_to_cart, then wc_fragments_refreshed, then
	// updated_cart_totals …) and the Blocks data store can notify several
	// times per change too, so both timers are reset on every signal and
	// only the trailing refresh of a burst survives.
	var cartRefreshTimer = null;
	var cartFollowUpTimer = null;

	/**
	 * Refresh after a WooCommerce cart mutation (the single handler every
	 * cart-change signal funnels into).
	 *
	 * Debounce: the immediate refresh is trailing-debounced (150 ms) so a
	 * quantity stepper clicked repeatedly or a burst of fragment events
	 * fires ONE request, not a storm. Follow-up: the AJAX request that
	 * triggered the cart event only persists the session on PHP shutdown
	 * — after its response has been flushed to the browser. A poll fired
	 * straight from the event can therefore race that write and read the
	 * previous cart, leaving the widgets frozen on stale progress until
	 * the next cart event. One extra poll 700 ms after the burst settles
	 * lets the widgets land on the persisted cart; it is cheap because
	 * unchanged payloads are skipped by the fingerprint check.
	 *
	 * @return {void}
	 */
	function refreshAfterCartChange() {
		if ( cartRefreshTimer ) {
			window.clearTimeout( cartRefreshTimer );
		}
		cartRefreshTimer = window.setTimeout( function () {
			cartRefreshTimer = null;
			refresh( { updating: true } );
		}, 150 );

		if ( cartFollowUpTimer ) {
			window.clearTimeout( cartFollowUpTimer );
		}
		cartFollowUpTimer = window.setTimeout( function () {
			cartFollowUpTimer = null;
			refresh();
		}, 700 );
	}

	/**
	 * The centralized cart-changed bridge.
	 *
	 * Every WooCommerce cart-change mechanism — the classic jQuery events,
	 * the Blocks wc-blocks_* DOM events, the Blocks wc/store/cart data
	 * store and the gift-claim flow — is normalized into ONE custom
	 * `goalcart:cart-changed` event on document.body. A single listener
	 * runs the debounced refresh, so every widget instance reacts to
	 * every entry point consistently and a future entry point only has to
	 * dispatch the event.
	 *
	 * @return {void}
	 */
	function emitCartChanged() {
		try {
			document.body.dispatchEvent( new CustomEvent( 'goalcart:cart-changed', { bubbles: true } ) );
		} catch ( error ) {
			refreshAfterCartChange();
		}
	}

	/**
	 * Bind the centralized cart-changed bridge listener.
	 *
	 * @return {void}
	 */
	function bindCartChangedBridge() {
		document.body.addEventListener( 'goalcart:cart-changed', refreshAfterCartChange );
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
			// Coupon apply/remove and cart emptied change the totals (and
			// therefore goal eligibility) without an item mutation — the
			// widget must refresh for them too.
			'applied_coupon',
			'removed_coupon',
			'wc_cart_emptied',
		];

		if ( window.jQuery ) {
			safe( function () {
				window.jQuery( document.body ).on( events.join( ' ' ), emitCartChanged );
			} );
		} else {
			for ( var i = 0; i < events.length; i++ ) {
				document.body.addEventListener( events[ i ], emitCartChanged );
			}
		}
	}

	/**
	 * Subscribe to the WooCommerce Blocks cart data store.
	 *
	 * Classic cart mutations fire the jQuery events bound above, but the
	 * Cart/Checkout blocks mutate the cart through the Store API and only
	 * the `wc/store/cart` data store (window.wp.data, loaded by the blocks
	 * on the frontend) reflects every change — quantity steppers, item
	 * removals, coupons and shipping inside the blocks never fire a
	 * classic jQuery event. This subscribes to the store and normalizes
	 * any cart-data change into the same `goalcart:cart-changed` bridge.
	 * The `wc-blocks_*` DOM events the blocks package translates from the
	 * classic jQuery events (add/remove only) are bound too, so block
	 * add-to-cart from archive/product grids is covered even before the
	 * data store updates.
	 *
	 * @return {void}
	 */
	function bindBlockStore() {
		// The blocks package dispatches these native DOM events on
		// document.body for block-driven add/remove actions.
		document.body.addEventListener( 'wc-blocks_added_to_cart', emitCartChanged );
		document.body.addEventListener( 'wc-blocks_removed_from_cart', emitCartChanged );

		var wpData = window.wp && window.wp.data;

		if ( ! wpData || ! wpData.select || ! wpData.subscribe ) {
			return;
		}

		// A compact fingerprint of the cart store state: the server-computed
		// cartHash when available, otherwise item keys + quantities.
		function cartFingerprint() {
			try {
				var store = wpData.select( 'wc/store/cart' );

				if ( ! store || ! store.getCartData ) {
					return null;
				}

				var cart = store.getCartData() || {};

				// The totals fold in coupon discounts and the shipping rate,
				// neither of which the item-based cartHash covers — a coupon
				// applied inside the Cart block changes the totals but not the
				// items, so without this the widget would not refresh.
				var totals = cart.totals || {};
				var totalsPart = String( totals.total_price || '' ) + ':' + String( totals.currency_code || '' );

				if ( cart.cartHash ) {
					return String( cart.cartHash ) + '|' + totalsPart;
				}

				var parts = [];
				var items = cart.items || [];

				for ( var i = 0; i < items.length; i++ ) {
					parts.push( String( items[ i ].key || '' ) + ':' + String( items[ i ].quantity || 0 ) );
				}

				return String( cart.itemsCount || 0 ) + '|' + parts.join( ',' ) + '|' + totalsPart;
			} catch ( error ) {
				return null;
			}
		}

		var lastCartFingerprint = null;

		// The plain subscribe(listener) form works on every @wordpress/data
		// version; the fingerprint guard keeps unrelated store changes
		// silent. (Newer versions accept a store-name second argument, but
		// the global form degrades safely everywhere.)
		wpData.subscribe( function () {
			safe( function () {
				var fingerprint = cartFingerprint();

				if ( fingerprint === null || fingerprint === lastCartFingerprint ) {
					return;
				}

				lastCartFingerprint = fingerprint;
				emitCartChanged();
			} );
		} );
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
	 * Bind the delegated gift-picker click handler (Phase 32).
	 *
	 * One listener on document.body covers every widget's picker buttons;
	 * the ids ride on the button's data attributes.
	 *
	 * @return {void}
	 */
	function bindGiftPicker() {
		if ( ! cfg.giftEndpoint || ! cfg.giftNonce ) {
			return;
		}

		document.body.addEventListener( 'click', function ( event ) {
			var target = event.target;

			while ( target && target !== document.body ) {
				if ( target.classList && target.classList.contains( 'goalcart-gift-picker__button' ) ) {
					claimGift( target );
					return;
				}
				target = target.parentNode;
			}
		} );
	}

	/**
	 * Start the countdown ticker (Phase 32).
	 *
	 * One interval rewrites every `.goalcart-countdown__time` readout from
	 * its data attribute every second — no widget re-render involved.
	 *
	 * @return {void}
	 */
	function bindCountdownTicker() {
		window.setInterval( function () {
			safe( function () {
				var nodes = document.querySelectorAll( '.goalcart-countdown__time' );

				for ( var i = 0; i < nodes.length; i++ ) {
					var end = nodes[ i ].getAttribute( 'data-goalcart-end' );

				if ( end ) {
					nodes[ i ].textContent = countdownText( end );
				}
			}
		} );
		}, 1000 );
	}

	/**
	 * Track the scroll direction for the sticky bar's auto-hide behavior
	 * (Phase 32).
	 *
	 * @return {void}
	 */
	function bindStickyScroll() {
		var sticky = cfg.sticky || {};

		if ( sticky.behavior !== 'auto_hide' ) {
			return;
		}

		window.addEventListener( 'scroll', function () {
			var y = window.pageYOffset || document.documentElement.scrollTop || 0;
			stickyLastScrollY = y;
			stickyAutoHidden = false;
			safe( refresh );
		}, { passive: true } );
	}

	/**
	 * Boot the widgets.
	 *
	 * @return {void}
	 */
	function init() {
		bindCartChangedBridge();
		bindCartEvents();
		bindBlockStore();
		bindSuggestionTracking();
		bindGiftPicker();
		bindCountdownTicker();
		bindStickyScroll();

		// Phase 18 (mobile behavior): re-render when the viewport crosses
		// the mobile breakpoint so hidden widgets appear/disappear live.
		if ( cfg.mobile === 'hide' ) {
			window.addEventListener( 'resize', refresh );
		}

		// Phase 32 (sticky bar delay): after sticky.delay seconds the bar
		// becomes eligible to show.
		var sticky = cfg.sticky || {};

		if ( Number( sticky.delay ) > 0 ) {
			stickyDelayTimer = window.setTimeout( function () {
				stickyShown = true;
				safe( refresh );
			}, Number( sticky.delay ) * 1000 );
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
