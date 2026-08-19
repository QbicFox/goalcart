/**
 * FaraCart storefront progress widgets (Phase 11).
 *
 * Vanilla JS, no build step — mirrors the reference plugin's frontend
 * convention (assets/js + a single inline window config + a
 * must-never-throw contract). The PHP side prints an empty container per
 * display location and this library fills them from `GET /faracart/v1/progress`.
 *
 * Components (P11):
 *   MissionContainer    wrapper that hosts one mission's UI (full / compact)
 *   ProgressBar      percentage fill bar
 *   MissionMessage      the mission's progress message
 *   RewardStatus     locked / unlocked reward chip
 *   UnifiedRecommendations  one customer-facing product block
 *                     (Suggestions + Upsells consolidation: the payload
 *                     carries the merged, deduplicated, ranked list
 *                     from the ProductRecommendationEngine with per-row
 *                     source attribution; a rank-endpoint fallback
 *                     closes money-mission gaps)
 *   FloatingWidget   floating missions/campaigns button + progress drawer
 *                     (position preset + offsets, per-device settings,
 *                     drawer always opens toward the screen center,
 *                     safe-area aware, RTL-safe)
 *
 * Every eligible mission renders as its own card, stacked in a shared
 * wrapper (`.faracart-widget__missions`) — a campaign's milestones each get
 * a full card instead of one featured card + a tiny ladder. Each card
 * sees only itself.
 *
 * Templates (the six design templates): the mission body renders per the
 * active variant — template-1 (classic progress card), template-2
 * (minimal inline cart mission), template-3 (circular progress), template-4
 * (product recommendation + mission), template-5 (compact floating mission)
 * or template-6 (premium / elegant e-commerce style) — driven by
 * the mission's resolved `template` (item override → scope default →
 * legacy → fallback) or a per-container `data-faracart-template`
 * override. Appearance tokens (colors, radius, bar height) come from
 * the resolved `template_settings`; the animation toggle adds a
 * no-transition class when disabled.
 *
 * Contracts:
 *   - config comes from `window.faracartFrontend` (printed early in
 *     wp_footer before this script)
 *   - nothing here ever throws: every handler is guarded, so a failure
 *     can never break the storefront
 *   - each container is mounted exactly once (data attribute guard); a
 *     location that re-renders (mini-cart fragment refresh) re-mounts
 *     after the DOM swap
 *
 * @package FaraCart
 */
( () => {
	'use strict';

	var cfg = window.faracartFrontend || null;

	// No config = plugin disabled or assets loaded on a widget-less page.
	if ( ! cfg || ! cfg.endpoint ) {
		return;
	}

	// Phase 27 (Internationalization): format numbers/money in the site
	// locale (from the PHP config) so digits and grouping match the store
	// language — Persian digits for fa_IR, etc. Undefined falls back to
	// the browser default, preserving the pre-Phase-27 behavior.
	var uiLocale = ( cfg && cfg.locale ) ? String( cfg.locale ).replace( '_', '-' ) : undefined;

	var WIDGET_SELECTOR = '[data-faracart-widget]';
	var FLOATING_ID = 'faracart-floating';

	// Floating widget (floating missions/campaigns button + drawer) state:
	// whether the drawer is open, whether the button/drawer markup was
	// built once (the payload only rebuilds the drawer content), the
	// drawer content fingerprint (rebuild only when the missions changed),
	// the resolved drawer direction + button side (re-applied on resize)
	// and the default button glyph.
	var floatingOpen = false;
	var floatingBuilt = false;
	var floatingFingerprint = null;
	var floatingDirection = 'right';
	// The button side remembered at open time for the resize handler.
	// (Named *State so it never shadows the floatingButtonSide() resolver.)
	var floatingButtonSideState = 'right';
	var FLOATING_DEFAULT_ICON = '\uD83D\uDED2'; // shopping cart

	// The template ids the floating drawer accepts when resolving a mission's
	// card (the same ids widgetTemplate honors, minus per-container overrides).
	var FLOATING_TEMPLATES = [ 'template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6', 'milestone_chain', 'campaign_progress' ];

	// Phase 16 analytics: window.faracartTracking (printed by the Tracker)
	// carries the track endpoint, the nonce and the session id. Absent =
	// tracking disabled — every tracker call is a guarded no-op.
	var tracking = window.faracartTracking || null;

	// Per-session dedup: impressions / completions / suggestion impressions
	// are reported once per mission (or mission+product); progress only when the
	// percentage actually changed. Keeps refreshes quiet while still
	// capturing the funnel events.
	var reportedImpressions = {};
	var reportedCompletions = {};
	var reportedSuggestionImpressions = {};
	var reportedProgress = {};

	// Phase 33.7 (Frontend Upsell Integration): the smart upsell panel
	// config (endpoint, track endpoint, limit, labels). Absent = the
	// panel is disabled and every upsell call is a guarded no-op.
	var upsells = cfg.upsells || null;

	// Per-session dedup for upsell impressions (one per mission + product).
	var reportedUpsellImpressions = {};

	// Per-mission ranking cache keyed by "missionId:remaining": a cart change
	// re-renders the card and reuses the last payload when the gap did
	// not move, instead of refetching on every poll.
	var upsellRankCache = {};

	// Phase 23 (Performance → update only changed UI fragments): each
	// widget records a fingerprint of the payload it last rendered.
	// refresh() skips the DOM rebuild for containers whose fingerprint is
	// unchanged (the poll interval and cart events fire refresh() even
	// when nothing moved), so only the fragments whose numbers actually
	// changed are touched.
	var renderedFingerprints = {};

	// Phase 32: per-session state for the celebration animation (one burst
	// per mission).
	var celebrated = {};

	// Confetti palette (Phase 32 celebration).
	var CONFETTI_COLORS = [ '#2271b1', '#00a32a', '#d63638', '#f0b849', '#7e5af5', '#2c7a7b' ];

	/**
	 * Guard every entry point: a thrown error must never reach the page.
	 *
	 * @param {Function} fn Callback to run.
	 * @return {*} Result or null.
	 */
	const safe = ( fn ) => {
		try {
			return fn();
		} catch ( error ) {
			if ( window.console && window.console.warn ) {
				window.console.warn( 'FaraCart frontend:', error );
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
	const el = ( tag, klass, text ) => {
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
	const fetchProgress = ( done, ended ) => {
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

		const finish = () => {
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

		request.onload = () => {
			if ( myEpoch !== fetchEpoch ) {
				finish();
				return;
			}

			if ( request.status < 200 || request.status >= 300 ) {
				finish();
				return;
			}

			safe( () => {
				var payload = JSON.parse( request.responseText );
				if ( payload && payload.data ) {
					// Self-healing tracking nonce: every /progress response
					// carries a freshly minted faracart_track nonce. Adopt it
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

		request.onerror = () => { finish(); };
		request.ontimeout = () => { finish(); };
		// Safety net: fires on success, error, timeout AND abort, so the
		// "updating" state can never linger behind a request that ends in
		// any way (finish() is idempotent — the activeFetch guard makes
		// superseded requests no-ops).
		request.onloadend = () => { finish(); };
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
	const sendTrack = ( eventType, data ) => {
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
	 * Report an upsell interaction to the upsell track endpoint (Phase
	 * 33.7).
	 *
	 * The smart upsell funnel (impression / clicked / added) posts to the
	 * public `POST /faracart/v1/upsell/track` route — NOT the Phase 16
	 * track endpoint, which only whitelists the mission/reward events. The
	 * route reuses the same tracking nonce + session id the Phase 16
	 * tracker already holds, so no second nonce is needed. Fire-and-forget
	 * and must never throw, exactly like sendTrack.
	 *
	 * @param {string} eventType upsell_impression | upsell_clicked |
	 *                           upsell_added.
	 * @param {Object} data      Optional event fields.
	 * @return {void}
	 */
	const sendUpsellTrack = ( eventType, data ) => {
		if ( ! upsells || ! upsells.trackEndpoint || ! tracking || ! tracking.nonce ) {
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
				navigator.sendBeacon( upsells.trackEndpoint, new Blob( [ body ], { type: 'application/json' } ) );
				return;
			} catch ( error ) {
				// Fall through to the XHR path.
			}
		}

		var request = new XMLHttpRequest();
		request.open( 'POST', upsells.trackEndpoint, true );
		request.setRequestHeader( 'Content-Type', 'application/json' );
		request.send( body );
	}

	/**
	 * Fetch the ranked upsell products for one mission (Phase 33.7).
	 *
	 * GETs the public rank endpoint with just mission_id + limit — the
	 * server computes the remaining gap from the live cart, so the client
	 * never needs to send (or trust) the gap. Cache-busted with a
	 * timestamp, mirroring fetchProgress.
	 *
	 * @param {string}   missionId Mission id.
	 * @param {Function} done   Callback receiving the payload `data`
	 *                          object, or null on any failure.
	 * @return {void}
	 */
	const fetchUpsells = ( missionId, done ) => {
		var request = new XMLHttpRequest();
		var separator = upsells.endpoint.indexOf( '?' ) >= 0 ? '&' : '?';
		var url = upsells.endpoint + separator
			+ 'mission_id=' + encodeURIComponent( missionId )
			+ '&limit=' + encodeURIComponent( upsells.limit || 3 )
			+ '&_=' + Date.now();

		request.open( 'GET', url, true );
		request.timeout = 10000;

		request.onload = () => {
			if ( request.status < 200 || request.status >= 300 ) {
				done( null );
				return;
			}

			safe( () => {
				var payload = JSON.parse( request.responseText );
				done( payload && payload.data ? payload.data : null );
			} );
		};

		request.onerror = () => { done( null ); };
		request.ontimeout = () => { done( null ); };
		request.send();
	}

	/**
	 * The cart money value for event payloads.
	 *
	 * Uses the first money-based mission's current value (the storefront
	 * payload carries per-mission values, not a cart total).
	 *
	 * @param {Array} missions Progress mission entries.
	 * @return {number}
	 */
	const cartValue = ( missions ) => {
		if ( ! missions ) {
			return 0;
		}

		for ( var i = 0; i < missions.length; i++ ) {
			if ( missions[ i ].is_money && Number( missions[ i ].current ) > 0 ) {
				return Number( missions[ i ].current ) || 0;
			}
		}

		return 0;
	}

	/**
	 * Report the per-mission analytics events for a payload (Phase 16).
	 *
	 * Runs after every render: impressions once per mission per session,
	 * progress when the percentage changed, completion events once per
	 * mission, and suggestion impressions once per mission+product.
	 *
	 * @param {Object} data Progress payload data.
	 * @return {void}
	 */
	const trackMissions = ( data ) => {
		var missions = ( data && data.missions ) || [];
		var value = cartValue( missions );

		for ( var i = 0; i < missions.length; i++ ) {
			var mission = missions[ i ];
			var missionId = String( mission.mission_id || 0 );

			if ( ! reportedImpressions[ missionId ] && mission.eligible !== false ) {
				reportedImpressions[ missionId ] = true;
				sendTrack( 'mission_impression', {
					mission_id: missionId,
					campaign_id: mission.campaign_id || 0,
					cart_value: value,
				} );
			}

			var percentage = Math.round( Number( mission.percentage ) || 0 );

			// Progress is reported only for eligible missions (an ineligible
			// mission never renders a widget — no ghost events for hidden ones).
			if ( mission.eligible !== false && reportedProgress[ missionId ] !== percentage ) {
				reportedProgress[ missionId ] = percentage;
				sendTrack( 'mission_progress', {
					mission_id: missionId,
					campaign_id: mission.campaign_id || 0,
					cart_value: value,
					percentage: percentage,
				} );
			}

			if ( mission.completed && ! reportedCompletions[ missionId ] ) {
				reportedCompletions[ missionId ] = true;
				// A conflict-suppressed reward never reports as activated
				// (Phase 26): only the completion is recorded.
				sendTrack( mission.reward && mission.reward.type && ! rewardBlocked( mission ) ? 'reward_activated' : 'mission_completed', {
					mission_id: missionId,
					campaign_id: mission.campaign_id || 0,
					cart_value: value,
				} );
			}

			// Unified recommendations preserve source attribution in the
			// existing funnels: suggestion-sourced items feed the Phase 16
			// suggestion funnel, upsell-sourced items feed the Phase 33.5
			// upsell funnel, and 'both' items feed both — one impression per
			// mission + product per session per funnel, never duplicates.
			if ( mission.suggestions && mission.suggestions.length ) {
				for ( var j = 0; j < mission.suggestions.length; j++ ) {
					var item = mission.suggestions[ j ] || {};
					var productId = String( item.product_id || item.id || 0 );
					var key = missionId + ':' + productId;
					var src = String( item.source || '' );

					if ( productId && ! reportedSuggestionImpressions[ key ] && ( src === 'suggestion' || src === 'both' ) ) {
						reportedSuggestionImpressions[ key ] = true;
						sendTrack( 'suggestion_impression', {
							mission_id: missionId,
							product_id: productId,
						} );
					}

					if ( productId && ! reportedUpsellImpressions[ key ] && ( src === 'upsell' || src === 'both' ) ) {
						reportedUpsellImpressions[ key ] = true;
						sendUpsellTrack( 'upsell_impression', {
							mission_id: missionId,
							product_id: productId,
							cart_value: Number( mission.current ) || 0,
							source: src,
						} );
					}
				}
			}
		}
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
	const formatMoney = ( value, currency ) => {
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
	 * Format a catalog price with the same settings as mission targets.
	 *
	 * The API keeps price_html for compatibility, but that value is
	 * produced by WooCommerce and cannot reflect FaraCart's currency
	 * display setting. Raw prices therefore win whenever available.
	 *
	 * @param {Object} item Catalog item.
	 * @return {string}
	 */
	const formatProductPrice = ( item ) => {
		if ( item && item.price !== null && item.price !== undefined && item.price !== '' ) {
			return formatMoney( item.price, cfg.currency );
		}

		return String( ( item && item.price_html ) || '' );
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
	const mobileHidden = () => {
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
	const payloadFingerprint = ( data ) => {
		var missions = ( data && data.missions ) || [];
		var parts = [];

		for ( var i = 0; i < missions.length; i++ ) {
			var mission = missions[ i ] || {};
			var suggestionIds = ( mission.suggestions || [] ).map( ( suggestion ) => {
				return String( suggestion.id || 0 ) + ':' + String( suggestion.name || '' );
			} ).join( ',' );

			parts.push(
				[
					String( mission.mission_id || 0 ),
					String( mission.mission_name || '' ),
					String( mission.icon || '' ),
					String( mission.template || '' ),
					String( mission.current || 0 ),
					String( mission.target || 0 ),
					String( mission.percentage || 0 ),
					mission.completed ? '1' : '0',
					mission.eligible === false ? '0' : '1',
					rewardBlocked( mission ) ? '0' : '1',
					String( mission.state || '' ),
					String( mission.message || '' ),
					String( ( mission.reward && mission.reward.type ) || '' ),
					String( ( mission.reward && mission.reward.value ) || '' ),
					String( mission.countdown_end || '' ),
					( mission.reward && mission.reward.gift_chosen ) ? '1' : '0',
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
	const formatNumber = ( value ) => {
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
	const uiLabel = ( key, fallback ) => {
		return ( cfg.labels && cfg.labels[ key ] ) ? String( cfg.labels[ key ] ) : String( fallback );
	}

	/**
	 * The live countdown text for an ISO end timestamp (Phase 32).
	 *
	 * @param {string} end ISO local-time timestamp.
	 * @return {string}
	 */
	const countdownText = ( end ) => {
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

		const pad2 = ( n ) => {
			return n < 10 ? '0' + n : '' + n;
		}

		var time = formatNumber( pad2( hours ) ) + ':' + formatNumber( pad2( minutes ) ) + ':' + formatNumber( pad2( seconds ) );

		return days > 0 ? formatNumber( days ) + 'd ' + time : time;
	}

	/**
	 * Countdown chip for a mission/campaign with an end time (Phase 32).
	 *
	 * The chip carries the end timestamp on a data attribute; a single
	 * global ticker (started in init) rewrites the readout every second
	 * without re-rendering the widget.
	 *
	 * @param {Object} entry Mission or campaign group with countdown_end.
	 * @return {HTMLElement|null}
	 */
	const countdownPanel = ( entry ) => {
		if ( ! entry || ! entry.countdown_end || cfg.countdown === false ) {
			return null;
		}

		var wrap = el( 'div', 'faracart-countdown' );

		wrap.appendChild( el( 'span', 'faracart-countdown__label', uiLabel( 'countdown', 'Ends in' ) ) );

		var time = el( 'span', 'faracart-countdown__time' );
		time.setAttribute( 'data-faracart-end', String( entry.countdown_end ) );
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
	const isSafeUrl = ( url ) => {
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
	 * The featured mission: the first eligible one, else the first listed.
	 *
	 * @param {Array} missions Progress mission entries.
	 * @return {Object|null}
	 */
	const featuredMission = ( missions ) => {
		if ( ! missions || ! missions.length ) {
			return null;
		}

		for ( var i = 0; i < missions.length; i++ ) {
			if ( missions[ i ].eligible !== false ) {
				return missions[ i ];
			}
		}

		return missions[ 0 ];
	}

	/* ------------------------------------------------------------------ *
	 * Components
	 * ------------------------------------------------------------------ */

	/**
	 * ProgressBar — a percentage fill bar.
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {HTMLElement}
	 */
	const progressBar = ( mission ) => {
		var track = el( 'div', 'faracart-progress' );
		var fill = el( 'div', 'faracart-progress__fill' );
		var percent = Math.max( 0, Math.min( 100, Number( mission.percentage ) || 0 ) );

		fill.style.width = percent + '%';

		if ( mission.completed ) {
			track.classList.add( 'faracart-progress--complete' );
		}

		track.appendChild( fill );

		return track;
	}

	/**
	 * MissionMessage — the mission's progress message.
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {HTMLElement}
	 */
	const missionMessage = ( mission ) => {
		return el( 'p', 'faracart-message', String( mission.message || '' ) );
	}

	/**
	 * Whether a mission's reward is suppressed by a conflict (Phase 26).
	 *
	 * The progress payload resolves conflicts with the same rules the
	 * reward engine grants with; a suppressed reward must never render as
	 * unlocked (the shopper would see a claim the cart does not grant).
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {boolean}
	 */
	const rewardBlocked = ( mission ) => {
		return !!( mission.conflict && mission.conflict.resolved === false );
	}

	/**
	 * RewardStatus — a locked/unlocked chip for the mission's reward.
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {HTMLElement|null}
	 */
	const rewardStatus = ( mission ) => {
		var reward = mission.reward || null;

		if ( ! reward || ! reward.type ) {
			return null;
		}

		var blocked = rewardBlocked( mission );
		var unlocked = mission.completed && ! blocked;
		var label = ( cfg.labels && cfg.labels[ reward.type ] ) || reward.type;
		var chip = el( 'span', 'faracart-reward' );

		chip.classList.add( unlocked ? 'faracart-reward--unlocked' : 'faracart-reward--locked' );

		if ( blocked && mission.conflict && mission.conflict.reason ) {
			chip.setAttribute( 'title', String( mission.conflict.reason ) );
		}

		chip.appendChild( el( 'span', 'faracart-reward__icon', unlocked ? '\u2713' : '\uD83D\uDD12' ) );
		chip.appendChild( el( 'span', 'faracart-reward__label', label ) );

		return chip;
	}

	/**
	 * The unified recommendation source for tracking.
	 *
	 * Payload items carry the merged source the backend preserved
	 * ('suggestion' | 'upsell' | 'both'); rank-endpoint fallback items
	 * carry the ranker's internal source keys and belong to the upsell
	 * funnel in the unified experience.
	 *
	 * @param {Object} item Recommendation item.
	 * @return {string} 'suggestion' | 'upsell' | 'both' | ''.
	 */
	const recommendationSource = ( item ) => {
		var src = String( ( item && item.source ) || '' );

		if ( src === 'suggestion' || src === 'both' ) {
			return src;
		}

		return src ? 'upsell' : '';
	}

	/**
	 * A localized upsell label with a sensible fallback.
	 *
	 * The upsell labels live in cfg.upsells.labels (not cfg.labels, which
	 * carries the reward/countdown strings).
	 *
	 * @param {string} key      Label key in upsells.labels.
	 * @param {string} fallback Fallback text.
	 * @return {string}
	 */
	const upsellLabel = ( key, fallback ) => {
		return ( upsells && upsells.labels && upsells.labels[ key ] )
			? String( upsells.labels[ key ] )
			: String( fallback );
	}

	/**
	 * One upsell product row (Phase 33.7).
	 *
	 * Image, name (link), server-formatted price and an add-to-cart
	 * button. The ids, the permalink and the current cart value ride on
	 * data attributes so the single delegated click handler can act
	 * without resolving anything.
	 *
	 * @param {Object} item Ranked product payload row.
	 * @param {Object} mission The mission the ranking belongs to.
	 * @return {HTMLElement}
	 */
	const upsellRow = ( item, mission ) => {
		var row = el( 'div', 'faracart-upsell' );

		row.setAttribute( 'data-faracart-upsell-product', String( item.product_id || item.id || 0 ) );
		row.setAttribute( 'data-faracart-upsell-mission', String( mission.mission_id || 0 ) );
		row.setAttribute( 'data-faracart-upsell-permalink', String( item.permalink || '' ) );
		row.setAttribute( 'data-faracart-upsell-value', String( Number( mission.current ) || 0 ) );
		// Source attribution rides on the row so the delegated handlers can
		// keep the suggestion and upsell funnels separate after unification.
		row.setAttribute( 'data-faracart-upsell-source', recommendationSource( item ) );

		if ( item.image ) {
			var img = el( 'img', 'faracart-upsell__image' );
			img.setAttribute( 'src', String( item.image ) );
			img.setAttribute( 'alt', '' );
			img.setAttribute( 'loading', 'lazy' );
			row.appendChild( img );
		}

		var link = el( 'a', 'faracart-upsell__name' );
		link.setAttribute( 'href', isSafeUrl( item.permalink ) ? String( item.permalink ) : '#' );
		link.setAttribute( 'data-faracart-upsell-id', String( item.product_id || item.id || 0 ) );
		link.setAttribute( 'data-faracart-upsell-mission', String( mission.mission_id || 0 ) );
		link.setAttribute( 'data-faracart-upsell-source', recommendationSource( item ) );
		link.textContent = String( item.name || '' );
		row.appendChild( link );

		// Prefer the raw amount so the configured currency display style is
		// applied consistently; price_html remains a safe legacy fallback.
		row.appendChild( el( 'span', 'faracart-upsell__price', formatProductPrice( item ) ) );

		var button = el( 'button', 'faracart-upsell__add' );
		button.type = 'button';
		button.textContent = upsellLabel( 'add', 'Add to cart' );
		row.appendChild( button );

		return row;
	}

	/**
	 * UnifiedRecommendations — one customer-facing product recommendation
	 * panel (Suggestions + Upsells consolidation).
	 *
	 * Renders the mission's unified recommendation list — the progress
	 * payload already carries the merged, deduplicated, ranked candidates
	 * (suggestion + upsell engines, `source` preserved per row) — for any
	 * mission that has one. Money missions with a positive remaining gap fall
	 * back to the public rank endpoint when the payload carried nothing:
	 * the ranker closes the gap from the live cart with signals beyond
	 * the suggestion pool (the result is cached per mission:remaining so
	 * cart-change re-renders reuse it). Every rendered product reports
	 * one impression per session per funnel (suggestion-sourced rows feed
	 * the Phase 16 suggestion funnel, upsell-sourced rows the Phase 33.7
	 * upsell funnel, 'both' rows feed both).
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {HTMLElement|null}
	 */
	const upsellPanel = ( mission ) => {
		var missionId = String( mission.mission_id || 0 );

		if ( ! missionId ) {
			return null;
		}

		// The unified recommendations ride on the payload; they render for
		// any mission that has them (money or not, like the original
		// suggestions block they replace).
		var payloadItems = ( mission.suggestions && mission.suggestions.length ) ? mission.suggestions : [];

		// Money missions with a gap fall back to the rank endpoint when the
		// payload carried nothing — a completed mission needs no
		// recommendations.
		var useRank = false;
		var remaining = Number( mission.remaining );

		if ( upsells && upsells.enabled && upsells.endpoint && mission.is_money && ! mission.completed && remaining > 0 ) {
			useRank = true;
		}

		if ( ! payloadItems.length && ! useRank ) {
			return null;
		}

		var panel = el( 'div', 'faracart-upsells' );
		panel.appendChild( el( 'div', 'faracart-upsells__title', upsellLabel( 'heading', 'Products suggested for you' ) ) );

		var list = el( 'div', 'faracart-upsells__list' );
		panel.appendChild( list );

		var cacheKey = missionId + ':' + Math.round( remaining );
		var cached = upsellRankCache[ cacheKey ] || null;

		const renderRows = ( rows ) => {
			list.replaceChildren();

			if ( ! rows.length ) {
				list.appendChild( el( 'div', 'faracart-upsells__empty', upsellLabel( 'unavailable', 'No recommendations available right now.' ) ) );
				return;
			}

			for ( var i = 0; i < rows.length; i++ ) {
				var item = rows[ i ] || {};

				if ( ! item.product_id ) {
					continue;
				}

				list.appendChild( upsellRow( item, mission ) );

				// Upsell-funnel impressions for upsell/both-sourced rows.
				// Suggestion-sourced rows skip this — trackMissions reports
				// their suggestion_impression (and the upsell side of a
				// 'both' row) when it scans the payload; the shared dedup
				// map keeps a single impression per mission + product per
				// funnel per session.
				var src = recommendationSource( item );

				if ( src !== 'suggestion' ) {
					var key = missionId + ':' + String( item.product_id );

					if ( ! reportedUpsellImpressions[ key ] ) {
						reportedUpsellImpressions[ key ] = true;
						sendUpsellTrack( 'upsell_impression', {
							mission_id: missionId,
							product_id: String( item.product_id ),
							cart_value: Number( mission.current ) || 0,
							source: src || 'upsell',
						} );
					}
				}
			}
		}

		// Payload-first: the unified recommendations render synchronously.
		if ( payloadItems.length ) {
			renderRows( payloadItems );
			return panel;
		}

		const fill = ( payload ) => {
			if ( ! payload ) {
				// Network/parse failure: drop the panel entirely — never a
				// broken half-rendered widget.
				try {
					panel.remove();
				} catch ( error ) {}
				return;
			}

			var rows = ( payload.available && payload.recommendations ) ? payload.recommendations : [];
			renderRows( rows );
		}

		if ( cached ) {
			fill( cached );
			return panel;
		}

		// Loading state: a subtle placeholder; the fetch fills the list.
		list.appendChild( el( 'div', 'faracart-upsells__loading', '…' ) );

		fetchUpsells( missionId, ( payload ) => {
			upsellRankCache[ cacheKey ] = payload;

			safe( () => {
				// The widget re-rendered while the fetch was in flight —
				// this panel is detached; the fresh one will fetch itself.
				if ( ! list.isConnected ) {
					return;
				}

				fill( payload );
			} );
		} );

		return panel;
	}

	/**
	 * Add a unified recommendation to the cart (Suggestions + Upsells
	 * consolidation, ex Phase 33.7).
	 *
	 * Reports the click up front with source attribution — suggestion-
	 * sourced items feed the Phase 16 suggestion funnel, upsell-sourced
	 * items the Phase 33.7 upsell funnel, 'both' items feed both (rank-
	 * endpoint fallback rows carry no source and belong to the upsell
	 * funnel; suggestion adds are attributed server-side from their
	 * impressions). Then adds through WooCommerce's own public
	 * `?wc-ajax=add_to_cart` endpoint (no nonce needed — the same
	 * endpoint the theme's add-to-cart buttons use, so it works in every
	 * theme). On success it reports upsell_added and funnels into the
	 * centralized cart-changed bridge, which re-polls the progress
	 * endpoint and recomputes the mission gap live. Falls back to the
	 * classic `?add-to-cart=` redirect when the AJAX surface is missing,
	 * and to the product page when the item needs a variation choice.
	 *
	 * @param {HTMLElement} button The clicked add button.
	 * @return {void}
	 */
	const upsellAdd = ( button ) => {
		// The unified upsell rows AND the template-4 recommend rows share
		// the same data attributes, so one handler serves both.
		var row = button.closest ? button.closest( '.faracart-upsell, .faracart-recommend' ) : null;

		if ( ! row ) {
			return;
		}

		var productId = row.getAttribute( 'data-faracart-upsell-product' ) || '';
		var missionId = row.getAttribute( 'data-faracart-upsell-mission' ) || '';
		var permalink = row.getAttribute( 'data-faracart-upsell-permalink' ) || '';
		var cartValue = Number( row.getAttribute( 'data-faracart-upsell-value' ) || 0 );
		var src = row.getAttribute( 'data-faracart-upsell-source' ) || '';

		if ( ! productId ) {
			return;
		}

		if ( src === 'suggestion' || src === 'both' ) {
			sendTrack( 'suggestion_clicked', {
				mission_id: missionId,
				product_id: productId,
			} );
		}

		if ( ! src || src === 'upsell' || src === 'both' ) {
			sendUpsellTrack( 'upsell_clicked', {
				mission_id: missionId,
				product_id: productId,
				cart_value: cartValue,
			} );
		}
		var ajaxUrl = '';
		var params = window.wc_add_to_cart_params || null;

		if ( params && params.wc_ajax_url ) {
			ajaxUrl = String( params.wc_ajax_url ).replace( /%%endpoint%%/g, 'add_to_cart' );
		}

		const success = () => {
			button.textContent = upsellLabel( 'added', 'Added' );
			button.disabled = true;

			// upsell_added only for upsell/both-sourced items: suggestion
			// adds are attributed server-side on conversion from their
			// impression, so no separate add event for them.
			if ( ! src || src === 'upsell' || src === 'both' ) {
				sendUpsellTrack( 'upsell_added', {
					mission_id: missionId,
					product_id: productId,
					cart_value: cartValue,
				} );
			}

			// Re-poll progress: the gap shrinks and the panel/bar update.
			emitCartChanged();
		}

		const restore = () => {
			button.textContent = upsellLabel( 'add', 'Add to cart' );
			button.disabled = false;
		}

		const go = ( url ) => {
			if ( url && isSafeUrl( url ) ) {
				window.location.href = url;
			}
		}

		if ( ! ajaxUrl ) {
			// No WooCommerce AJAX surface (unusual): the classic redirect
			// add-to-cart. The page reloads and the widget re-boots on the
			// new cart.
			var separator = permalink.indexOf( '?' ) >= 0 ? '&' : '?';
			go( permalink + separator + 'add-to-cart=' + encodeURIComponent( productId ) );
			return;
		}

		button.textContent = upsellLabel( 'adding', 'Adding…' );
		button.disabled = true;

		var request = new XMLHttpRequest();
		request.open( 'POST', ajaxUrl, true );
		request.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8' );

		request.onload = () => {
			var ok = request.status >= 200 && request.status < 300;
			var errored = false;

			if ( ok ) {
				safe( () => {
					var parsed = JSON.parse( request.responseText );

					if ( parsed && ( parsed.error || parsed.result === 'error' ) ) {
						errored = true;
					}
				} );
			}

			if ( ok && ! errored ) {
				success();
			} else {
				// The item needs a choice (variation) or is not purchasable
				// as-is: send the shopper to the product page.
				restore();
				go( permalink );
			}
		};

		request.onerror = () => { restore(); go( permalink ); };
		request.ontimeout = () => { restore(); go( permalink ); };
		request.send( 'product_id=' + encodeURIComponent( productId ) + '&quantity=1' );
	}

	/**
	 * GiftPicker — the shopper's free-gift selection (Phase 32).
	 *
	 * Renders for completed missions whose free-gift reward is in "choose"
	 * mode: one button per candidate gift. Clicking claims the gift through
	 * the public gift endpoint (nonce-guarded), then the widgets refresh
	 * and the picker flips to its "added" state.
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {HTMLElement|null}
	 */
	const giftPicker = ( mission ) => {
		var reward = mission.reward || null;

		if ( ! reward || reward.type !== 'free_gift' || ! reward.gift || ! reward.gift.length ) {
			return null;
		}

		if ( ! cfg.giftEndpoint || ! cfg.giftNonce ) {
			return null;
		}

		var picker = el( 'div', 'faracart-gift-picker' );

		if ( reward.gift_chosen ) {
			picker.classList.add( 'faracart-gift-picker--done' );
			picker.appendChild( el( 'p', 'faracart-gift-picker__done', uiLabel( 'gift_chosen', 'Gift added to your cart' ) ) );
			return picker;
		}

		picker.appendChild( el( 'div', 'faracart-gift-picker__title', uiLabel( 'gift_picker', 'Pick your free gift' ) ) );

		var list = el( 'ul', 'faracart-gift-picker__list' );

		for ( var i = 0; i < reward.gift.length; i++ ) {
			var item = reward.gift[ i ];
			var li = el( 'li', 'faracart-gift-picker__item' );
			var button = el( 'button', 'faracart-gift-picker__button' );

			button.type = 'button';
			button.setAttribute( 'data-faracart-gift-product', String( item.id || 0 ) );
			button.setAttribute( 'data-faracart-gift-mission', String( mission.mission_id || 0 ) );

			if ( item.image ) {
				var img = el( 'img', 'faracart-gift-picker__image' );
				img.src = String( item.image );
				img.alt = '';
				button.appendChild( img );
			}

			button.appendChild( el( 'span', 'faracart-gift-picker__name', String( item.name || '' ) ) );

			if ( item.price !== null && item.price !== undefined && item.price !== '' ) {
				button.appendChild( el( 'span', 'faracart-gift-picker__price', formatMoney( item.price, cfg.currency ) ) );
			} else if ( item.price_html ) {
				button.appendChild( el( 'span', 'faracart-gift-picker__price', String( item.price_html ) ) );
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
	const claimGift = ( button ) => {
		var missionId = button.getAttribute( 'data-faracart-gift-mission' ) || '0';
		var productId = button.getAttribute( 'data-faracart-gift-product' ) || '0';

		if ( ! missionId || ! productId || button.disabled ) {
			return;
		}

		button.disabled = true;
		button.classList.add( 'faracart-gift-picker__button--pending' );

		var body;
		try {
			body = JSON.stringify( {
				mission_id: Number( missionId ) || 0,
				product_id: Number( productId ) || 0,
				nonce: cfg.giftNonce || '',
			} );
		} catch ( error ) {
			button.disabled = false;
			button.classList.remove( 'faracart-gift-picker__button--pending' );
			return;
		}

		var request = new XMLHttpRequest();

		request.open( 'POST', cfg.giftEndpoint, true );
		request.setRequestHeader( 'Content-Type', 'application/json' );
		request.timeout = 10000;

		request.onload = () => {
			if ( request.status >= 200 && request.status < 300 ) {
				emitCartChanged();
				return;
			}

			// A failed claim (nonce, stock) re-enables the button so the
			// shopper can retry after the next poll adopts a fresh nonce.
			button.disabled = false;
			button.classList.remove( 'faracart-gift-picker__button--pending' );
		};

		request.onerror = () => {
			button.disabled = false;
			button.classList.remove( 'faracart-gift-picker__button--pending' );
		};
		request.ontimeout = () => {
			button.disabled = false;
			button.classList.remove( 'faracart-gift-picker__button--pending' );
		};
		request.send( body );
	}

	/**
	 * The per-widget template: an explicit container override wins, then
	 * the mission's own Display template (the mission builder's template picker),
	 * then the store-wide Appearance template.
	 * 	 * @param {HTMLElement} container Widget container.
	 * @param {Object}      mission     Mission this card renders (may be null).
	 * @return {string}
	 */
	const widgetTemplate = ( container, mission ) => {
		var override = container.getAttribute( 'data-faracart-template' );
		var names = [ 'template-1', 'template-2', 'template-3', 'template-4', 'template-5', 'template-6', 'milestone_chain', 'campaign_progress' ];

		if ( override && names.indexOf( override ) !== -1 ) {
			return override;
		}

		// The mission's Display settings can pin a template per mission; it wins
		// over the store-wide Appearance template.
		if ( mission && mission.template && names.indexOf( mission.template ) !== -1 ) {
			return mission.template;
		}

		if ( cfg.template && names.indexOf( cfg.template ) !== -1 ) {
			return cfg.template;
		}

		return 'template-1';
	}

	/**
	 * Apply a template's resolved settings to a node as CSS custom
	 * properties (pluggable template engine).
	 *
	 * The backend resolves each mission's effective template settings (item
	 * override → scope default → legacy → fallback) and ships them in the
	 * payload; the stylesheet reads the same --faracart-* custom
	 * properties the global Appearance settings override, so a per-mission
	 * (or per-campaign) template styles exactly what its settings say.
	 *
	 * @param {HTMLElement} node     Element to style.
	 * @param {Object}      settings Resolved template settings.
	 * @return {void}
	 */
	const applyTemplateSettings = ( node, settings ) => {
		if ( ! settings || typeof settings !== 'object' ) {
			return;
		}

		var map = {
			accent: '--faracart-accent',
			bg: '--faracart-bg',
			border: '--faracart-border',
			text: '--faracart-text',
			secondaryText: '--faracart-text-muted',
			radius: '--faracart-radius',
			barHeight: '--faracart-bar-height',
			trackColor: '--faracart-track',
			progressColor: '--faracart-progress-color',
			buttonColor: '--faracart-button-bg',
			buttonTextColor: '--faracart-button-text',
			buttonRadius: '--faracart-button-radius',
			iconBg: '--faracart-icon-bg',
			iconColor: '--faracart-icon-color',
			headerBg: '--faracart-header-bg',
			ringSize: '--faracart-ring-size',
			strokeWidth: '--faracart-ring-stroke',
			productImageSize: '--faracart-product-image',
			shadow: '--faracart-shadow-intensity',
			percentColor: '--faracart-percent-color',
			percentSize: '--faracart-percent-size',
			dotColor: '--faracart-dot-color',
			doneColor: '--faracart-done-color',
			connectorColor: '--faracart-connector-color'
		};

		for ( var key in map ) {
			if ( ! Object.prototype.hasOwnProperty.call( settings, key ) ) {
				continue;
			}

			var value = settings[ key ];

			if ( value === undefined || value === null || value === '' ) {
				continue;
			}

			var isPx = 'radius' === key || 'barHeight' === key || 'percentSize' === key
				|| 'ringSize' === key || 'strokeWidth' === key || 'productImageSize' === key
				|| 'buttonRadius' === key || 'shadow' === key;
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
	const applyTemplateCss = ( css ) => {
		if ( ! css ) {
			return;
		}

		var styleId = 'faracart-template-css-' + hashString( css );

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
	const hashString = ( value ) => {
		var hash = 5381;

		for ( var i = 0; i < value.length; i++ ) {
			hash = ( ( hash << 5 ) + hash ) + value.charCodeAt( i );
		}

		return Math.abs( hash );
	}

	/**
	 * Clamp a progress value to 0–100.
	 *
	 * @param {number} value Raw progress value.
	 * @return {number}
	 */
	const clampPercent = ( value ) => {
		return Math.max( 0, Math.min( 100, Number( value ) || 0 ) );
	}

	/**
	 * The mission's icon glyph — the configured mission icon (emoji / dashicon
	 * name) as text, or a template fallback glyph when none is set.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} fallback Fallback glyph.
	 * @return {HTMLElement}
	 */
	const tplIcon = ( mission, fallback ) => {
		var icon = String( mission.icon || '' ).trim();

		return el( 'span', 'faracart-tpl-icon', icon || fallback );
	}

	/**
	 * The template CTA — links to the top recommended product (the
	 * gap-closing product) when one exists; hidden otherwise.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @param {string} label    Button label.
	 * @param {string} klass    Extra class ('' = none).
	 * @return {HTMLElement|null}
	 */
	const tplCta = ( mission, currency, label, klass ) => {
		var items = ( mission.suggestions && mission.suggestions.length ) ? mission.suggestions : [];

		if ( ! items.length ) {
			return null;
		}

		var item = items[ 0 ];
		var cta = el( 'a', 'faracart-cta' + ( klass ? ' ' + klass : '' ) );

		if ( item.permalink && isSafeUrl( item.permalink ) ) {
			cta.href = String( item.permalink );
		}

		cta.setAttribute( 'rel', 'noreferrer' );
		cta.textContent = label;
		return cta;
	}

	/**
	 * The remaining-amount label ("%s left"), localized.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {string}
	 */
	const remainingLabel = ( mission, currency ) => {
		var amount = mission.is_money
			? formatMoney( mission.remaining, currency )
			: formatNumber( mission.remaining );

		return uiLabel( 'left', '%s left' ).replace( '%s', amount );
	}

	/**
	 * The "Add %s more" CTA label, localized.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {string}
	 */
	const addMoreLabel = ( mission, currency ) => {
		var amount = mission.is_money
			? formatMoney( mission.remaining, currency )
			: formatNumber( mission.remaining );

		return uiLabel( 'add_more', 'Add %s more' ).replace( '%s', amount );
	}

	/**
	 * A circular gauge — SVG ring whose stroke-dashoffset draws exactly
	 * `percent` of the circumference (Concept 03 / template-3). The center
	 * readout is a localized percent + "Progress" label, or a check when
	 * the mission is done.
	 *
	 * @param {number} percent    0–100 progress.
	 * @param {number} size       Ring diameter (px).
	 * @param {number} stroke     Stroke width (px).
	 * @param {string} trackColor Track stroke color.
	 * @param {string} accent     Progress stroke color.
	 * @param {string} inner      'percent' | 'check' | ''.
	 * @return {HTMLElement}
	 */
	const circularSvg = ( percent, size, stroke, trackColor, accent, inner ) => {
		var radius = ( size - stroke ) / 2;
		var circumference = 2 * Math.PI * radius;
		var NS = 'http://www.w3.org/2000/svg';
		var wrap = el( 'div', 'faracart-t3__ring' );

		wrap.style.width = size + 'px';
		wrap.style.height = size + 'px';

		var svg = document.createElementNS( NS, 'svg' );
		var track = document.createElementNS( NS, 'circle' );
		var fill = document.createElementNS( NS, 'circle' );

		svg.setAttribute( 'viewBox', '0 0 ' + size + ' ' + size );
		svg.setAttribute( 'width', String( size ) );
		svg.setAttribute( 'height', String( size ) );
		svg.setAttribute( 'role', 'img' );
		svg.setAttribute( 'class', 'faracart-t3__svg' );

		track.setAttribute( 'class', 'faracart-t3__track' );
		track.setAttribute( 'cx', String( size / 2 ) );
		track.setAttribute( 'cy', String( size / 2 ) );
		track.setAttribute( 'r', String( radius ) );
		track.setAttribute( 'fill', 'none' );
		track.setAttribute( 'stroke', trackColor );
		track.setAttribute( 'stroke-width', String( stroke ) );

		fill.setAttribute( 'class', 'faracart-t3__fill' );
		fill.setAttribute( 'cx', String( size / 2 ) );
		fill.setAttribute( 'cy', String( size / 2 ) );
		fill.setAttribute( 'r', String( radius ) );
		fill.setAttribute( 'fill', 'none' );
		fill.setAttribute( 'stroke', accent );
		fill.setAttribute( 'stroke-width', String( stroke ) );
		fill.setAttribute( 'stroke-linecap', 0 === percent ? 'butt' : 'round' );
		fill.setAttribute( 'stroke-dasharray', String( circumference ) );
		fill.setAttribute( 'stroke-dashoffset', String( circumference * ( 1 - percent / 100 ) ) );
		fill.setAttribute( 'transform', 'rotate(-90 ' + size / 2 + ' ' + size / 2 + ')' );

		svg.appendChild( track );
		svg.appendChild( fill );
		wrap.appendChild( svg );

		if ( 'check' === inner ) {
			wrap.appendChild( el( 'span', 'faracart-t3__check', '\u2713' ) );
		} else if ( 'percent' === inner ) {
			var readout = el( 'div', 'faracart-t3__readout' );
			readout.appendChild( el( 'span', 'faracart-t3__percent', formatNumber( Math.round( percent ) ) + '%' ) );
			readout.appendChild( el( 'span', 'faracart-t3__progress-label', uiLabel( 'progress', 'Progress' ) ) );
			wrap.appendChild( readout );
		}

		return wrap;
	}

	/**
	 * One recommended product row (template-4 / Concept 07): real product
	 * image (or a neutral placeholder), name, "Only %s" price and an
	 * add-to-cart button. The row carries the same data attributes the
	 * unified upsell rows use, so the single delegated add-to-cart handler
	 * serves it without a second code path.
	 *
	 * @param {Object} item Recommended product payload row.
	 * @param {Object} mission Progress mission entry.
	 * @return {HTMLElement}
	 */
	const recommendRow = ( item, mission ) => {
		var row = el( 'div', 'faracart-recommend' );
		var productId = String( item.id || item.product_id || 0 );

		row.setAttribute( 'data-faracart-upsell-product', productId );
		row.setAttribute( 'data-faracart-upsell-mission', String( mission.mission_id || 0 ) );
		row.setAttribute( 'data-faracart-upsell-permalink', String( item.permalink || '' ) );
		row.setAttribute( 'data-faracart-upsell-value', String( Number( mission.current ) || 0 ) );
		row.setAttribute( 'data-faracart-upsell-source', 'suggestion' );

		var image;
		if ( item.image ) {
			image = el( 'img', 'faracart-recommend__image' );
			image.setAttribute( 'src', String( item.image ) );
			image.setAttribute( 'alt', String( item.name || '' ) );
			image.setAttribute( 'loading', 'lazy' );
		} else {
			image = el( 'span', 'faracart-recommend__image faracart-recommend__image--placeholder', '\uD83D\uDECD' );
		}
		row.appendChild( image );

		var info = el( 'div', 'faracart-recommend__info' );
		var link = el( 'a', 'faracart-recommend__name' );

		if ( item.permalink && isSafeUrl( item.permalink ) ) {
			link.href = String( item.permalink );
		}

		link.textContent = String( item.name || '' );
		info.appendChild( link );
		info.appendChild( el( 'span', 'faracart-recommend__price', uiLabel( 'only_price', 'Only %s' ).replace( '%s', formatProductPrice( item ) ) ) );
		row.appendChild( info );

		var button = el( 'button', 'faracart-recommend__add' );
		button.type = 'button';
		button.textContent = uiLabel( 'add', 'Add' );
		row.appendChild( button );

		return row;
	}

	/**
	 * Template 1 — Classic Progress Card (Concept 01). The most
	 * general-purpose template: icon badge + label/title + percentage
	 * chip, a horizontal bar, current/remaining amounts and a CTA, with
	 * completed and expired states.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const t1Panel = ( mission, currency ) => {
		var settings = mission.template_settings || {};
		var percent = clampPercent( mission.percentage );
		var accent = settings.accent || '#f97316';
		var muted = settings.secondaryText || '#9ca3af';
		var text = settings.text || '#1f2937';
		var panel = el( 'div', 'faracart-t1' );

		// Expired / ended: muted clock row.
		if ( mission.eligible === false || mission.state === 'inactive' || mission.state === 'unavailable' ) {
			panel.classList.add( 'faracart-t1--expired' );
			var expiredRow = el( 'div', 'faracart-t1__expired' );
			expiredRow.appendChild( tplIcon( mission, '\u23F0' ) );
			var expiredInfo = el( 'div', 'faracart-t1__expired-info' );
			expiredInfo.appendChild( el( 'span', 'faracart-t1__expired-label', uiLabel( 'expired', 'Expired' ) ) );
			expiredInfo.appendChild( el( 'span', 'faracart-t1__expired-title', uiLabel( 'mission_ended', 'This mission has ended' ) ) );
			expiredRow.appendChild( expiredInfo );
			expiredRow.appendChild( el( 'span', 'faracart-t1__expired-chip', uiLabel( 'expired', 'Expired' ) ) );
			panel.appendChild( expiredRow );
			return panel;
		}

		// Completed: green card with a check + full bar.
		if ( mission.completed ) {
			panel.classList.add( 'faracart-t1--done' );
			var done = el( 'div', 'faracart-t1__done' );
			var doneRow = el( 'div', 'faracart-t1__done-row' );
			doneRow.appendChild( tplIcon( mission, '\u2705' ) );
			var doneInfo = el( 'div', 'faracart-t1__done-info' );
			doneInfo.appendChild( el( 'span', 'faracart-t1__done-label', uiLabel( 'mission_reached', 'Mission completed' ) + ' \uD83C\uDF89' ) );
			doneInfo.appendChild( el( 'span', 'faracart-t1__done-title', String( mission.mission_name || '' ) ) );
			doneRow.appendChild( doneInfo );
			done.appendChild( doneRow );
			done.appendChild( progressBar( mission ) );
			panel.appendChild( done );
			return panel;
		}

		// Head: icon + label/title + percent chip.
		var head = el( 'div', 'faracart-t1__head' );
		var headMain = el( 'div', 'faracart-t1__head-main' );

		if ( settings.showIcon !== false ) {
			headMain.appendChild( tplIcon( mission, '\uD83D\uDE9A' ) );
		}

		var headText = el( 'div', 'faracart-t1__head-text' );
		headText.appendChild( el( 'span', 'faracart-t1__label', uiLabel( 'shopping_mission', 'Shopping mission' ) ) );
		headText.appendChild( el( 'span', 'faracart-t1__title', String( mission.mission_name || '' ) ) );
		headMain.appendChild( headText );
		head.appendChild( headMain );

		if ( settings.showPercent !== false ) {
			head.appendChild( el( 'span', 'faracart-t1__percent', Math.round( percent ) + '%' ) );
		}

		panel.appendChild( head );
		panel.appendChild( progressBar( mission ) );

		if ( settings.showAmounts !== false ) {
			var amounts = el( 'div', 'faracart-t1__amounts' );
			amounts.appendChild( el( 'span', 'faracart-t1__current', mission.is_money ? formatMoney( mission.current, currency ) : formatNumber( mission.current ) ) );

			if ( settings.showRemaining !== false ) {
				amounts.appendChild( el( 'span', 'faracart-t1__remaining', remainingLabel( mission, currency ) ) );
			}

			panel.appendChild( amounts );
		}

		if ( settings.showCta !== false ) {
			var cta = tplCta( mission, currency, addMoreLabel( mission, currency ), 'faracart-t1__cta' );

			if ( cta ) {
				panel.appendChild( cta );
			}
		}

		return panel;
	}

	/**
	 * Template 2 — Minimal Inline Cart Mission (Concept 02). A very compact
	 * inline strip: icon, title, remaining amount, a slim bar and a
	 * compact CTA. Fits between the cart content and the totals.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const t2Panel = ( mission, currency ) => {
		var settings = mission.template_settings || {};
		var panel = el( 'div', 'faracart-t2' );

		if ( settings.showIcon !== false ) {
			panel.appendChild( tplIcon( mission, '\uD83D\uDE9A' ) );
		}

		var body = el( 'div', 'faracart-t2__body' );
		var row = el( 'div', 'faracart-t2__row' );

		if ( settings.showTitle !== false ) {
			row.appendChild( el( 'span', 'faracart-t2__title', String( mission.mission_name || '' ) ) );
		}

		if ( mission.completed ) {
			row.appendChild( el( 'span', 'faracart-t2__done', uiLabel( 'completed', 'Completed' ) + ' \u2713' ) );
		} else if ( settings.showRemaining !== false ) {
			row.appendChild( el( 'span', 'faracart-t2__remaining', remainingLabel( mission, currency ) ) );
		}

		body.appendChild( row );
		body.appendChild( progressBar( mission ) );
		panel.appendChild( body );

		if ( settings.showCta !== false && ! mission.completed ) {
			var cta = tplCta( mission, currency, uiLabel( 'add', 'Add' ), 'faracart-t2__cta' );

			if ( cta ) {
				panel.appendChild( cta );
			}
		}

		return panel;
	}

	/**
	 * Template 3 — Circular Progress (Concept 03). A circular gauge with
	 * the percentage centered inside, beside the icon, title, description
	 * and the current/remaining amounts, plus a CTA; the completed state
	 * draws a full green ring with a check.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const t3Panel = ( mission, currency ) => {
		var settings = mission.template_settings || {};
		var percent = clampPercent( mission.percentage );
		var accent = settings.accent || '#6366f1';
		var trackColor = settings.trackColor || '#e5e7eb';
		var muted = settings.secondaryText || '#6b7280';
		var text = settings.text || '#1f2937';
		var size = Number( settings.ringSize ) || 100;
		var stroke = Number( settings.strokeWidth ) || 8;
		var panel = el( 'div', 'faracart-t3' );

		if ( mission.completed ) {
			var doneRow = el( 'div', 'faracart-t3__done-row' );
			doneRow.appendChild( circularSvg( 100, Math.round( size * 0.8 ), stroke, trackColor, '#10b981', 'check' ) );
			var doneInfo = el( 'div', 'faracart-t3__done-info' );
			doneInfo.appendChild( el( 'span', 'faracart-t3__done-title', uiLabel( 'congrats', 'Congratulations!' ) + ' \uD83C\uDF89' ) );
			doneInfo.appendChild( el( 'span', 'faracart-t3__done-sub', String( mission.mission_name || '' ) ) );
			doneRow.appendChild( doneInfo );
			panel.appendChild( doneRow );
			return panel;
		}

		var row = el( 'div', 'faracart-t3__row' );
		row.appendChild( circularSvg( percent, size, stroke, trackColor, accent, settings.showPercent === false ? '' : 'percent' ) );

		var info = el( 'div', 'faracart-t3__info' );
		var titleRow = el( 'div', 'faracart-t3__title-row' );
		titleRow.appendChild( tplIcon( mission, '\uD83D\uDE9A' ) );
		titleRow.appendChild( el( 'span', 'faracart-t3__title', String( mission.mission_name || '' ) ) );
		info.appendChild( titleRow );

		if ( settings.showDescription !== false ) {
			info.appendChild( el( 'p', 'faracart-t3__desc', uiLabel( 'with_purchase', 'With a purchase of' ) + ' ' + ( mission.is_money ? formatMoney( mission.target, currency ) : formatNumber( mission.target ) ) ) );
		}

		if ( settings.showAmounts !== false ) {
			var paid = el( 'div', 'faracart-t3__amount' );
			paid.appendChild( el( 'span', 'faracart-t3__amount-label', uiLabel( 'paid', 'Paid' ) ) );
			paid.appendChild( el( 'span', 'faracart-t3__amount-value', mission.is_money ? formatMoney( mission.current, currency ) : formatNumber( mission.current ) ) );
			info.appendChild( paid );

			var left = el( 'div', 'faracart-t3__amount' );
			left.appendChild( el( 'span', 'faracart-t3__amount-label', uiLabel( 'remaining', 'Remaining' ) ) );
			left.appendChild( el( 'span', 'faracart-t3__amount-value faracart-t3__amount-value--accent', mission.is_money ? formatMoney( mission.remaining, currency ) : formatNumber( mission.remaining ) ) );
			info.appendChild( left );
		}

		row.appendChild( info );
		panel.appendChild( row );

		if ( settings.showCta !== false ) {
			var cta = tplCta( mission, currency, uiLabel( 'view_products', 'View products' ), 'faracart-t3__cta' );

			if ( cta ) {
				panel.appendChild( cta );
			}
		}

		return panel;
	}

	/**
	 * Template 4 — Product Recommendation + Mission (Concept 07). A gradient
	 * progress header (title + remaining chip + bar) followed by the
	 * mission's own recommended products (the existing FaraCart / WooCommerce
	 * recommendation data) with add-to-cart buttons.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const t4Panel = ( mission, currency ) => {
		var settings = mission.template_settings || {};
		var accent = settings.accent || '#2563eb';
		var headerBg = settings.headerBg || accent;
		var muted = settings.secondaryText || '#6b7280';
		var text = settings.text || '#1f2937';
		var products = ( mission.suggestions && mission.suggestions.length ) ? mission.suggestions : [];
		var panel = el( 'div', 'faracart-t4' );

		// Gradient progress header.
		var header = el( 'div', 'faracart-t4__header' );
		header.style.background = 'linear-gradient(135deg, ' + headerBg + ', ' + headerBg + 'cc)';
		var headerRow = el( 'div', 'faracart-t4__header-row' );
		headerRow.appendChild( el( 'span', 'faracart-t4__title', String( mission.mission_name || '' ) ) );

		if ( mission.completed ) {
			headerRow.appendChild( el( 'span', 'faracart-t4__chip', uiLabel( 'completed', 'Completed' ) + ' \u2713' ) );
		} else if ( settings.showRemaining !== false ) {
			headerRow.appendChild( el( 'span', 'faracart-t4__chip', remainingLabel( mission, currency ) ) );
		}

		header.appendChild( headerRow );
		header.appendChild( progressBar( mission ) );
		panel.appendChild( header );

		// Recommended products.
		var body = el( 'div', 'faracart-t4__body' );

		if ( settings.showHeading !== false ) {
			var heading = el( 'p', 'faracart-t4__heading' );
			heading.appendChild( el( 'span', 'faracart-t4__heading-icon', '\uD83D\uDCA1' ) );
			heading.appendChild( document.createTextNode( ' ' + uiLabel( 'recommend_heading', 'Add these products to reach your mission faster:' ) ) );
			body.appendChild( heading );
		}

		if ( ! products.length ) {
			body.appendChild( el( 'p', 'faracart-t4__empty', uiLabel( 'unavailable', 'No recommendations available right now.' ) ) );
		} else {
			for ( var i = 0; i < products.length; i++ ) {
				body.appendChild( recommendRow( products[ i ], mission ) );
			}
		}

		panel.appendChild( body );
		return panel;
	}

	/**
	 * Template 5 — Compact Floating / Sticky Mission (Concept 08). A compact
	 * dark bar: icon badge, slim progress, remaining amount and a small
	 * CTA. Deliberately compact — never a normal large card.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const t5Panel = ( mission, currency ) => {
		var settings = mission.template_settings || {};
		var accent = settings.accent || '#4ade80';
		var panel = el( 'div', 'faracart-t5' );

		if ( settings.showIcon !== false ) {
			var badge = el( 'span', 'faracart-t5__badge', String( mission.icon || '' ).trim() || '\uD83D\uDE9A' );
			panel.appendChild( badge );
		}

		var body = el( 'div', 'faracart-t5__body' );
		var row = el( 'div', 'faracart-t5__row' );
		row.appendChild( el( 'span', 'faracart-t5__title', String( mission.mission_name || '' ) ) );

		if ( mission.completed ) {
			row.appendChild( el( 'span', 'faracart-t5__done', uiLabel( 'completed', 'Completed' ) + ' \u2713' ) );
		} else if ( settings.showRemaining !== false ) {
			row.appendChild( el( 'span', 'faracart-t5__remaining', remainingLabel( mission, currency ) ) );
		}

		body.appendChild( row );
		body.appendChild( progressBar( mission ) );
		panel.appendChild( body );

		if ( settings.showCta !== false && ! mission.completed ) {
			var cta = tplCta( mission, currency, uiLabel( 'add', 'Add' ), 'faracart-t5__cta' );

			if ( cta ) {
				panel.appendChild( cta );
			}
		}

		return panel;
	}

	/**
	 * Template 6 — Premium / Elegant E-commerce Style (Concept 09).
	 * Gold-accented elegant card: a slim header with a gold rail, a large
	 * title + description, a thin gold bar with a marker dot, the
	 * current/remaining amounts and a refined outline CTA, plus a
	 * highlighted "almost completed" callout.
	 *
	 * @param {Object} mission     Progress mission entry.
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const t6Panel = ( mission, currency ) => {
		var settings = mission.template_settings || {};
		var percent = clampPercent( mission.percentage );
		var gold = settings.accent || '#d4af37';
		var progressColor = settings.progressColor || gold;
		var muted = settings.secondaryText || '#9ca3af';
		var text = settings.text || '#111827';
		var outlineColor = settings.buttonTextColor || '#b8922a';
		var panel = el( 'div', 'faracart-t6' );

		// Slim header with a gold rail.
		var header = el( 'div', 'faracart-t6__header' );
		var headerMain = el( 'div', 'faracart-t6__header-main' );
		headerMain.appendChild( el( 'span', 'faracart-t6__rail' ) );
		headerMain.appendChild( el( 'span', 'faracart-t6__eyebrow', uiLabel( 'shopping_mission', 'Shopping mission' ) ) );
		header.appendChild( headerMain );
		header.appendChild( el( 'span', 'faracart-t6__header-icon', '\uD83D\uDE9A' ) );
		panel.appendChild( header );

		panel.appendChild( el( 'h4', 'faracart-t6__title', String( mission.mission_name || '' ) ) );
		panel.appendChild( el( 'p', 'faracart-t6__desc', uiLabel( 'with_purchase', 'With a purchase of' ) + ' ' + ( mission.is_money ? formatMoney( mission.target, currency ) : formatNumber( mission.target ) ) ) );

		// Elegant progress with a marker dot at the end.
		var progress = el( 'div', 'faracart-t6__progress' );
		progress.appendChild( progressBar( mission ) );

		if ( ! mission.completed && percent > 0 && percent < 100 ) {
			var dot = el( 'span', 'faracart-t6__dot' );
			dot.style.setProperty( '--faracart-t6-dot', percent + '%' );
			progress.appendChild( dot );
		}

		panel.appendChild( progress );

		if ( settings.showAmounts !== false ) {
			var amounts = el( 'div', 'faracart-t6__amounts' );
			var paid = el( 'div', 'faracart-t6__amount' );
			paid.appendChild( el( 'span', 'faracart-t6__amount-label', uiLabel( 'paid', 'Paid' ) ) );
			paid.appendChild( el( 'span', 'faracart-t6__amount-value', mission.is_money ? formatMoney( mission.current, currency ) : formatNumber( mission.current ) ) );
			amounts.appendChild( paid );

			var left = el( 'div', 'faracart-t6__amount faracart-t6__amount--end' );
			left.appendChild( el( 'span', 'faracart-t6__amount-label', uiLabel( 'remaining', 'Remaining' ) ) );
			left.appendChild( el( 'span', 'faracart-t6__amount-value faracart-t6__amount-value--gold', mission.is_money ? formatMoney( mission.remaining, currency ) : formatNumber( mission.remaining ) ) );
			amounts.appendChild( left );
			panel.appendChild( amounts );
		}

		if ( settings.showCta !== false ) {
			var cta = tplCta( mission, currency, uiLabel( 'view_products', 'View products' ), 'faracart-t6__cta' );

			if ( cta ) {
				panel.appendChild( cta );
			}
		}

		// Almost-completed callout.
		if ( mission.state === 'nearly_complete' && ! mission.completed ) {
			var callout = el( 'div', 'faracart-t6__callout' );
			callout.appendChild( el( 'span', 'faracart-t6__callout-icon', '\uD83D\uDD25' ) );
			var calloutText = el( 'div', 'faracart-t6__callout-text' );
			calloutText.appendChild( el( 'span', 'faracart-t6__callout-title', uiLabel( 'almost_done', 'Almost there!' ) + ' — ' + remainingLabel( mission, currency ) ) );
			calloutText.appendChild( el( 'span', 'faracart-t6__callout-sub', uiLabel( 'finish_today', 'Finish today — your reward is waiting' ) ) );
			callout.appendChild( calloutText );
			panel.appendChild( callout );
		}

		return panel;
	}

	/**
	 * The template's core visual (everything except the shared message /
	 * reward chip / suggestion flow).
	 *
	 * @param {Object}  mission     Mission this card renders.
	 * @param {string}  currency ISO currency code.
	 * @param {string}  template Template variant.
	 * @return {HTMLElement}
	 */
	const templateBody = ( mission, currency, template, showBar ) => {
		switch ( template ) {
			case 'template-1':
				return t1Panel( mission, currency );
			case 'template-2':
				return t2Panel( mission, currency );
			case 'template-3':
				return t3Panel( mission, currency );
			case 'template-4':
				return t4Panel( mission, currency );
			case 'template-5':
				return t5Panel( mission, currency );
			case 'template-6':
				return t6Panel( mission, currency );
			default:
				return false === showBar ? null : progressBar( mission );
		}
	}

	/**
	 * MissionContainer — the widget body for one mission's card.
	 *
	 * Full: reward chip + template body + message + the unified
	 * recommendations panel. Compact: template body + message + reward
	 * chip. Every eligible mission renders as its own card (renderWidget
	 * stacks them), so there is no cross-mission ladder here anymore.
	 *
	 * @param {Object} mission     Mission this card renders.
	 * @param {string} currency ISO currency code.
	 * @param {string} variant  full|compact.
	 * @param {string} template Template variant.
	 * @return {HTMLElement}
	 */
	const missionContainer = ( mission, currency, variant, template ) => {
		// The Phase 13 message state (inactive / unavailable / progressing /
		// nearly_complete / completed / reward_activated) lands as a modifier
		// class so the stylesheet can highlight near-completion etc.
		var stateClass = mission.state ? ' faracart-state--' + mission.state : '';
		var card = el( 'div', 'faracart-card faracart-template--' + template + stateClass );
		var settings = mission.template_settings || {};

		// The resolved template settings drive this card's appearance
		// (colors, radius, bar height) through the shared CSS variables.
		applyTemplateSettings( card, settings );
		applyTemplateCss( settings.customCss );

		if ( settings.cssClass ) {
			card.classList.add( String( settings.cssClass ) );
		}

		var compact = 'compact' === variant;
		var reward = rewardStatus( mission );
		var showReward = settings.showReward !== false;
		var showMessage = settings.showMessage !== false;

		if ( compact ) {
			var compactBody = templateBody( mission, currency, template, settings.showBar );
			if ( compactBody ) {
				card.appendChild( compactBody );
			}
			if ( showMessage ) {
				card.appendChild( missionMessage( mission ) );
			}
			if ( reward && showReward ) {
				card.appendChild( reward );
			}
			return card;
		}

		var head = el( 'div', 'faracart-card__head' );
		if ( reward && showReward ) {
			head.appendChild( reward );
		}
		card.appendChild( head );

		var body = templateBody( mission, currency, template, settings.showBar );
		if ( body ) {
			card.appendChild( body );
		}

		if ( showMessage ) {
			card.appendChild( missionMessage( mission ) );
		}

		// Phase 32 (countdown + free gift selection): the deadline chip and
		// the gift picker render at the bottom of the full card.
		var countdown = countdownPanel( mission );
		if ( countdown ) {
			card.appendChild( countdown );
		}

		var gift = giftPicker( mission );
		if ( gift ) {
			card.appendChild( gift );
		}

		// Unified product recommendations (Suggestions + Upsells
		// consolidation): the merged panel renders at the bottom of the
		// full card, after the reward, message, countdown and gift picker.
		// Template-4 renders its recommended products inline as its body,
		// so the shared panel would duplicate them — it is suppressed.
		if ( 'template-4' !== template ) {
			var upsell = upsellPanel( mission );
			if ( upsell ) {
				card.appendChild( upsell );
			}
		}

		return card;
	}

	/**
	 * Celebration — a confetti burst + pulse when a mission completes
	 * (Phase 32).
	 *
	 * Runs once per mission per session (the `celebrated` map). The pieces are
	 * plain CSS-animated spans removed after the animation, so nothing
	 * lingers on the page.
	 *
	 * @param {HTMLElement} card The mission card.
	 * @param {Object}      mission Progress mission entry.
	 * @return {void}
	 */
	const celebrate = ( card, mission ) => {
		celebrated[ String( mission.mission_id || 0 ) ] = true;

		card.classList.add( 'faracart-card--celebrate' );

		var confetti = el( 'div', 'faracart-confetti' );
		confetti.setAttribute( 'aria-hidden', 'true' );

		for ( var i = 0; i < 18; i++ ) {
			var piece = el( 'span', 'faracart-confetti__piece' );
			piece.style.left = ( Math.random() * 100 ) + '%';
			piece.style.background = CONFETTI_COLORS[ i % CONFETTI_COLORS.length ];
			piece.style.animationDelay = ( Math.random() * 0.35 ) + 's';
			piece.style.setProperty( '--faracart-confetti-x', ( ( Math.random() * 160 ) - 80 ) + 'px' );
			confetti.appendChild( piece );
		}

		card.appendChild( confetti );

		window.setTimeout( () => {
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
	 * template is 'milestone_chain'; otherwise the campaign's missions render
	 * as individual cards.
	 *
	 * @param {Array}  missions    The campaign's eligible milestone missions.
	 * @param {Object} campaign Campaign group (template + resolved settings).
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const campaignChain = ( missions, campaign, currency ) => {
		var settings = campaign.settings || {};
		var panel = el( 'div', 'faracart-chain faracart-template--' + campaign.template );

		applyTemplateSettings( panel, settings );
		applyTemplateCss( settings.customCss );

		if ( settings.cssClass ) {
			panel.classList.add( String( settings.cssClass ) );
		}

		if ( campaign.name ) {
			panel.appendChild( el( 'div', 'faracart-chain__title', String( campaign.name ) ) );
		}

		var rung = el( 'ol', 'faracart-chain__steps' );

		for ( var i = 0; i < missions.length; i++ ) {
			var mission = missions[ i ];
			var step = el( 'li', 'faracart-chain__step' );

			step.classList.add( mission.completed ? 'faracart-chain__step--done' : 'faracart-chain__step--pending' );
			step.appendChild( el( 'span', 'faracart-chain__dot' ) );

			if ( settings.showLabels !== false ) {
				step.appendChild( el( 'span', 'faracart-chain__label', String( mission.mission_name || '' ) ) );
			}

			if ( settings.showTargets !== false ) {
				var target = mission.is_money
					? formatMoney( mission.target, currency )
					: formatNumber( mission.target );
				step.appendChild( el( 'span', 'faracart-chain__target', target ) );
			}

			if ( settings.showRewards !== false && mission.reward && mission.reward.type ) {
				step.appendChild( el( 'span', 'faracart-chain__reward', ( cfg.labels && cfg.labels[ mission.reward.type ] ) || mission.reward.type ) );
			}

			rung.appendChild( step );
		}

		panel.appendChild( rung );

		// Overall progress: the top milestone drives the bar.
		var top = null;

		for ( var j = 0; j < missions.length; j++ ) {
			if ( ! top || Number( missions[ j ].target ) > Number( top.target ) ) {
				top = missions[ j ];
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
	 * @param {Array}  missions    The campaign's eligible milestone missions.
	 * @param {Object} campaign Campaign group (template + resolved settings).
	 * @param {string} currency ISO currency code.
	 * @return {HTMLElement}
	 */
	const campaignProgress = ( missions, campaign, currency ) => {
		var settings = campaign.settings || {};
		var panel = el( 'div', 'faracart-campaign faracart-template--' + campaign.template );

		applyTemplateSettings( panel, settings );
		applyTemplateCss( settings.customCss );

		if ( settings.cssClass ) {
			panel.classList.add( String( settings.cssClass ) );
		}

		if ( settings.showTitle !== false && campaign.name ) {
			panel.appendChild( el( 'div', 'faracart-campaign__title', String( campaign.name ) ) );
		}

		if ( settings.showCounter !== false ) {
			var done = 0;

			for ( var i = 0; i < missions.length; i++ ) {
				if ( missions[ i ].completed ) {
					done++;
				}
			}

			panel.appendChild(
				el( 'div', 'faracart-campaign__counter', formatNumber( done ) + ' / ' + formatNumber( missions.length ) )
			);
		}

		// Overall progress: the top milestone drives the bar.
		var top = null;

		for ( var j = 0; j < missions.length; j++ ) {
			if ( ! top || Number( missions[ j ].target ) > Number( top.target ) ) {
				top = missions[ j ];
			}
		}

		if ( top ) {
			panel.appendChild( progressBar( top ) );
		}

		if ( settings.showRewards !== false ) {
			var chips = el( 'div', 'faracart-campaign__rewards' );

			for ( var k = 0; k < missions.length; k++ ) {
				if ( missions[ k ].reward && missions[ k ].reward.type ) {
					chips.appendChild(
						el( 'span', 'faracart-campaign__reward', ( cfg.labels && cfg.labels[ missions[ k ].reward.type ] ) || missions[ k ].reward.type )
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
	 * Every eligible mission renders as its own card, stacked in a shared
	 * wrapper — a campaign's milestones each get a full card instead of
	 * one featured card + a tiny ladder. Each card resolves its own
	 * template (per-widget override → mission Display template → global
	 * Appearance template) and sees only itself, so the milestone
	 * template degrades to the mission's own single rung.
	 *
	 * Empty state: no eligible missions (or no missions at all) → the container
	 * is hidden entirely rather than showing a broken bar.
	 *
	 * @param {HTMLElement} container Widget container.
	 * @param {Object}      data      Progress payload data.
	 * @return {void}
	 */
	const renderWidget = ( container, data ) => {
		if ( ! container ) {
			return;
		}

		var missions = ( data && data.missions ) || [];
		var variant = 'compact' === container.getAttribute( 'data-faracart-variant' ) ? 'compact' : 'full';

		// The animation toggle (Phase 12) freezes the fill transition via a
		// class; re-render in place on every refresh so live cart updates
		// (AJAX add-to-cart, quantity changes, fragment refreshes) always
		// show the current progress — no mount-once freeze.
		container.classList.toggle( 'faracart-widget--no-anim', false === cfg.animation );
		container.replaceChildren();

		// Phase 18 (mobile behavior): hide the widget on small screens.
		if ( mobileHidden() ) {
			container.classList.add( 'faracart-widget--mobile-hidden' );
			return;
		}
		container.classList.remove( 'faracart-widget--mobile-hidden' );

		// Group the eligible missions by campaign so a campaign template
		// (e.g. the milestone chain) can render the whole group as one
		// unit. Campaign groups without a configured campaign template —
		// and every standalone mission — render as individual cards.
		var groups = {};
		var order = [];

		for ( var i = 0; i < missions.length; i++ ) {
			var mission = missions[ i ];

			if ( ! mission || mission.eligible === false ) {
				continue;
			}

			var groupId = mission.campaign_id || 0;

			if ( ! groups[ groupId ] ) {
				groups[ groupId ] = [];
				order.push( groupId );
			}

			groups[ groupId ].push( mission );
		}

		var campaigns = ( data && data.campaigns ) || [];
		var campaignById = {};

		for ( var c = 0; c < campaigns.length; c++ ) {
			campaignById[ campaigns[ c ].campaign_id ] = campaigns[ c ];
		}

		// Stack one card per eligible mission (or one chain per campaign
		// group). Ineligible missions never render (they are skipped, not
		// broken), and when nothing is left the whole widget hides.
		var rendered = 0;
		var stack = el( 'div', 'faracart-widget__missions' );

		for ( var g = 0; g < order.length; g++ ) {
			var groupMissions = groups[ order[ g ] ];
			var campaign = campaignById[ order[ g ] ];

			if ( campaign && campaign.template ) {
				if ( 'milestone_chain' === campaign.template ) {
					stack.appendChild( campaignChain( groupMissions, campaign, data.currency || cfg.currency ) );
					rendered++;
					continue;
				}

				// Phase 32: the second campaign template — one overall bar.
				if ( 'campaign_progress' === campaign.template ) {
					stack.appendChild( campaignProgress( groupMissions, campaign, data.currency || cfg.currency ) );
					rendered++;
					continue;
				}
			}

			for ( var j = 0; j < groupMissions.length; j++ ) {
				var mission = groupMissions[ j ];
				var card = missionContainer( mission, data.currency || cfg.currency, variant, widgetTemplate( container, mission ) );

				// Phase 32 (celebration): one confetti burst + pulse per
				// completed mission per session.
				if ( mission.completed && cfg.celebrate && ! celebrated[ String( mission.mission_id || 0 ) ] ) {
					celebrate( card, mission );
				}

				stack.appendChild( card );
				rendered++;
			}
		}

		if ( ! rendered ) {
			container.classList.add( 'faracart-widget--empty' );
			return;
		}

		container.classList.remove( 'faracart-widget--empty' );
		container.appendChild( stack );
	}

	/* ------------------------------------------------------------------ *
	 * Floating widget (floating missions/campaigns button + drawer)
	 * ------------------------------------------------------------------ */

	/**
	 * Whether the current viewport is a mobile (small) screen.
	 *
	 * Uses the same 782px breakpoint as mobileHidden() (the WP admin
	 * mobile breakpoint) so the floating widget's per-device position and
	 * visibility flags agree with the rest of the storefront widgets.
	 *
	 * @return {boolean}
	 */
	const isMobileViewport = () => {
		if ( ! window.matchMedia ) {
			return false;
		}

		return window.matchMedia( '(max-width: 782px)' ).matches;
	}

	/**
	 * The resolved floating-widget config for the current device.
	 *
	 * Mirrors the PHP `floating_config()` payload: the master toggle, the
	 * per-device position (mobile reuses desktop when
	 * mobileUseDesktop is on), the per-device visibility flag and the
	 * display options. Every value is normalized so a malformed stored
	 * setting can never reach the DOM.
	 *
	 * @return {Object}
	 */
	const floatingConfig = () => {
		var floating = cfg.floating || {};
		var device = isMobileViewport() ? 'mobile' : 'desktop';
		var position = ( device === 'mobile' && ! floating.mobileUseDesktop )
			? ( floating.mobile || {} )
			: ( floating.desktop || {} );

		return {
			enabled: !! floating.enabled,
			device: device,
			position: position,
			visible: device === 'mobile'
				? floating.showMobile !== false
				: floating.showDesktop !== false,
			buttonSize: Math.min( 96, Math.max( 32, Number( floating.buttonSize ) || 56 ) ),
			animation: floating.animation !== false,
			icon: String( floating.icon || '' ),
			label: String( floating.label || '' ),
			labels: floating.labels || {},
		};
	}	/**
	 * The mobile-browser safe-area insets (home indicator, notches).
	 *
	 * Reads `env(safe-area-inset-*)` through a hidden probe element so the
	 * button never hides under a phone's home indicator or rounded corner
	 * — the insets are added to the configured offsets on anchored sides.
	 * Cached for the session (orientation changes invalidate it on resize).
	 *
	 * @return {Object} { top, right, bottom, left } px.
	 */
	var floatingInsetCache = null;

	const floatingSafeInsets = () => {
		if ( floatingInsetCache ) {
			return floatingInsetCache;
		}

		var zero = { top: 0, right: 0, bottom: 0, left: 0 };

		if ( ! window.CSS || ! window.CSS.supports || ! window.CSS.supports( 'padding', 'env(safe-area-inset-bottom)' ) ) {
			floatingInsetCache = zero;
			return floatingInsetCache;
		}

		const readInset = ( side ) => {
			try {
				var probe = el( 'div', '' );
				probe.setAttribute( 'style', 'position:fixed;visibility:hidden;pointer-events:none;left:0;top:0;padding-' + side + ':env(safe-area-inset-' + side + ');' );
				document.body.appendChild( probe );
				var value = parseFloat( window.getComputedStyle( probe ).getPropertyValue( 'padding-' + side ) ) || 0;
				probe.remove();
				return value;
			} catch ( error ) {
				return 0;
			}
		}

		floatingInsetCache = {
			top: readInset( 'top' ),
			right: readInset( 'right' ),
			bottom: readInset( 'bottom' ),
			left: readInset( 'left' ),
		};

		return floatingInsetCache;
	}

	/**
	 * Apply the configured physical position to the floating container.
	 *
	 * The axes are physical sides (left/right × top/center/bottom), never
	 * logical start/end, so the admin's chosen side keeps its visual
	 * result in RTL. Safe positioning: the offsets are clamped so the
	 * button always stays fully inside the viewport (small screens, large
	 * zoom), and the mobile safe-area insets ride along on anchored sides.
	 *
	 * @param {HTMLElement} container   The #faracart-floating element.
	 * @param {Object}      position    { preset, offset_x, offset_y }.
	 * @param {number}      buttonSize  Button diameter (px).
	 * @param {Object}      safeInsets  { top, right, bottom, left } px.
	 * @return {void}
	 */
	const applyFloatingPosition = ( container, position, buttonSize, safeInsets ) => {
		var horizontal = floatingButtonSide( position );
		var vertical = floatingVerticalAxis( position );
		var offsetX = Math.max( 0, Number( ( position && position.offset_x ) || 0 ) );
		var offsetY = Math.max( 0, Number( ( position && position.offset_y ) || 0 ) );

		// Viewport clamp: the button must always fit fully on screen (very
		// small screens, large browser zoom).
		var vw = window.innerWidth || document.documentElement.clientWidth || 0;
		var vh = window.innerHeight || document.documentElement.clientHeight || 0;
		var margin = 8;
		var maxX = Math.max( 0, vw - buttonSize - margin );
		var maxY = Math.max( 0, vh - buttonSize - margin );

		offsetX = Math.min( offsetX, maxX );
		offsetY = Math.min( offsetY, maxY );

		// Reset the previous anchors (and the center transform) first.
		container.classList.remove( 'faracart-floating--center', 'faracart-floating--button-left', 'faracart-floating--button-right' );
		container.style.removeProperty( 'left' );
		container.style.removeProperty( 'right' );
		container.style.removeProperty( 'top' );
		container.style.removeProperty( 'bottom' );
		container.style.removeProperty( '--faracart-fy' );

		// The button-side class tells the CSS which way an up/down drawer
		// aligns (always toward the screen center).
		container.classList.add( 'faracart-floating--button-' + horizontal );

		if ( horizontal === 'left' ) {
			container.style.left = ( offsetX + safeInsets.left ) + 'px';
		} else {
			container.style.right = ( offsetX + safeInsets.right ) + 'px';
		}

		if ( vertical === 'top' ) {
			container.style.top = ( offsetY + safeInsets.top ) + 'px';
		} else if ( vertical === 'bottom' ) {
			container.style.bottom = ( offsetY + safeInsets.bottom ) + 'px';
		} else {
			// Center: top at the viewport midline and the transform
			// translateY(-50% + fy) composes with the offset (the CSS
			// class handles it, the offset rides on --faracart-fy).
			container.style.top = '50%';
			container.classList.add( 'faracart-floating--center' );
			container.style.setProperty( '--faracart-fy', ( offsetY + ( ( safeInsets.top - safeInsets.bottom ) / 2 ) ) + 'px' );
		}
	}

	/**
	 * The drawer opening direction for a position preset.
	 *
	 * The drawer always opens from the button toward the screen center,
	 * so the direction is fully determined by the preset: top presets
	 * open down, bottom presets open up, center-left opens right and
	 * center-right opens left. There is no admin direction setting.
	 *
	 * @param {Object} position { preset, offset_x, offset_y }.
	 * @return {string} 'left' | 'right' | 'up' | 'down'.
	 */
	const floatingDrawerDirection = ( position ) => {
		var preset = ( position && position.preset ) || 'bottom-right';

		if ( preset === 'top-left' || preset === 'top-right' ) {
			return 'down';
		}

		if ( preset === 'bottom-left' || preset === 'bottom-right' ) {
			return 'up';
		}

		if ( preset === 'center-left' ) {
			return 'right';
		}

		return 'left'; // center-right (and any fallback).
	}

	/**
	 * The physical screen side the button hugs for a position preset.
	 *
	 * Up/down drawers align toward the center using this side (a left
	 * button's drawer extends right; a right button's extends left).
	 *
	 * @param {Object} position { preset, offset_x, offset_y }.
	 * @return {string} 'left' | 'right'.
	 */
	const floatingButtonSide = ( position ) => {
		var preset = ( position && position.preset ) || 'bottom-right';

		return ( preset === 'top-left' || preset === 'center-left' || preset === 'bottom-left' )
			? 'left'
			: 'right';
	}

	/**
	 * The vertical anchor for a position preset ('top' | 'center' | 'bottom').
	 *
	 * @param {Object} position { preset, offset_x, offset_y }.
	 * @return {string}
	 */
	const floatingVerticalAxis = ( position ) => {
		var preset = ( position && position.preset ) || 'bottom-right';

		if ( preset === 'top-left' || preset === 'top-right' ) {
			return 'top';
		}

		if ( preset === 'center-left' || preset === 'center-right' ) {
			return 'center';
		}

		return 'bottom';
	}

	/**
	 * Constrain the open drawer to the viewport.
	 *
	 * The stylesheet already scrolls the panel internally (overflow: auto
	 * + max-height), but the anchored panel can still extend past the
	 * viewport edges — a side drawer on a button at the very top/bottom,
	 * or an up/down drawer that grows past the opposite edge on a small
	 * screen. This measures the (still hidden) panel and clamps its
	 * anchor so it always stays fully on screen, and caps the height by
	 * the space actually available from the button, so the internal
	 * scrollbar is always reachable.
	 *
	 * @param {HTMLElement} drawer      The drawer element.
	 * @param {DOMRect}     buttonRect  The button's bounding rect.
	 * @param {string}      direction   'left' | 'right' | 'up' | 'down'.
	 * @param {string}      buttonSide  'left' | 'right' (the preset's side).
	 * @return {void}
	 */
	const constrainFloatingDrawer = ( drawer, buttonRect, direction, buttonSide ) => {
		var vw = window.innerWidth || document.documentElement.clientWidth || 0;
		var vh = window.innerHeight || document.documentElement.clientHeight || 0;
		var margin = 12;
		var gap = 12;

		// The drawer is absolutely positioned inside the fixed button
		// container, so its left/top offsets are relative to the container
		// — not the viewport. The clamps below run in viewport coordinates
		// (buttonRect, vw, vh), so every applied offset is converted back
		// by subtracting the container's own viewport rect; otherwise a
		// bottom-right button would push the panel off-screen (e.g. a
		// clamped left:12px lands 12px right of the container, which sits
		// at the screen edge).
		var container = drawer.parentElement;
		var containerRect = container ? container.getBoundingClientRect() : null;
		var originLeft = containerRect ? containerRect.left : buttonRect.left;
		var originTop = containerRect ? containerRect.top : buttonRect.top;

		// Reset any previous constraints (the direction can change between
		// opens, and the viewport can shrink).
		drawer.style.removeProperty( 'top' );
		drawer.style.removeProperty( 'left' );
		drawer.style.removeProperty( 'right' );
		drawer.style.removeProperty( 'max-height' );

		// Cap the height by the space actually available from the button
		// (up/down drawers grow toward the nearest edge); left/right
		// drawers center vertically so the full band is safe.
		var maxHeight = vh - margin * 2;

		if ( direction === 'up' ) {
			maxHeight = Math.min( maxHeight, buttonRect.top - margin );
		} else if ( direction === 'down' ) {
			maxHeight = Math.min( maxHeight, vh - buttonRect.bottom - margin );
		}

		drawer.style.maxHeight = Math.max( 120, Math.round( maxHeight ) ) + 'px';

		// Measure the panel (it is laid out even while hidden) so the
		// clamp below can keep it fully inside the viewport.
		var width = drawer.offsetWidth || 300;
		var height = drawer.offsetHeight || Math.max( 120, Math.round( maxHeight ) );

		if ( direction === 'left' || direction === 'right' ) {
			// The stylesheet centers the panel vertically on the button
			// (top:50% + translateY(-50%)); pin the anchor so it fits.
			var centerY = buttonRect.top + buttonRect.height / 2;
			var top = Math.min( Math.max( margin + height / 2, centerY ), vh - margin - height / 2 );
			drawer.style.top = ( top - originTop ) + 'px';

			// The panel opens horizontally from the button (toward the
			// center); clamp so it never spills off the opposite edge.
			var sideLeft = direction === 'right'
				? buttonRect.right + gap
				: buttonRect.left - gap - width;
			drawer.style.left = ( clampViewport( sideLeft, width, vw, margin ) - originLeft ) + 'px';
			drawer.style.right = 'auto';
		} else {
			// The panel opens up/down from the button, aligned toward the
			// screen center (button-left → extends right, button-right →
			// extends left); clamp horizontally so it always fits.
			var centerLeft = buttonSide === 'left'
				? buttonRect.right + gap
				: buttonRect.left - gap - width;
			drawer.style.left = ( clampViewport( centerLeft, width, vw, margin ) - originLeft ) + 'px';
			drawer.style.right = 'auto';
		}
	}

	/**
	 * Clamp a drawer left coordinate so the panel stays fully inside the
	 * viewport (never past either edge, even on tiny screens).
	 *
	 * @param {number} left    The desired left coordinate (px).
	 * @param {number} width   The panel width (px).
	 * @param {number} vw      Viewport width (px).
	 * @param {number} margin  Minimum edge gap (px).
	 * @return {number}
	 */
	const clampViewport = ( left, width, vw, margin ) => {
		var min = margin;
		var max = Math.max( margin, vw - width - margin );

		return Math.min( Math.max( min, left ), max );
	}

	/**
	 * Toggle the floating drawer open/closed.
	 *
	 * The direction always comes from the position preset (toward the
	 * screen center) — there is no admin direction setting. The panel is
	 * then constrained to the viewport so its internal scroll area is
	 * always reachable.
	 *
	 * @param {boolean} open Whether the drawer should open.
	 * @return {void}
	 */
	const setFloatingOpen = ( open ) => {
		var container = document.getElementById( FLOATING_ID );

		if ( ! container ) {
			return;
		}

		floatingOpen = !! open;

		if ( floatingOpen ) {
			var drawer = container.querySelector( '.faracart-floating__drawer' );
			var button = container.querySelector( '.faracart-floating__button' );
			var position = floatingConfig().position;
			var direction = floatingDrawerDirection( position );
			var buttonSide = floatingButtonSide( position );

			// Remember for the resize handler (the button can move with
			// scroll/resize while the drawer is open).
			floatingDirection = direction;
			floatingButtonSideState = buttonSide;

			container.classList.remove( 'faracart-floating--dir-left', 'faracart-floating--dir-right', 'faracart-floating--dir-up', 'faracart-floating--dir-down' );
			container.classList.add( 'faracart-floating--dir-' + direction );

			if ( drawer && button ) {
				constrainFloatingDrawer( drawer, button.getBoundingClientRect(), direction, buttonSide );
			}

			container.classList.add( 'faracart-floating--open' );
		} else {
			container.classList.remove( 'faracart-floating--open' );
		}
	}

	/**
	 * The template for a mission card inside the floating drawer.
	 *
	 * The drawer has no per-container override, so the resolution is the
	 * mission's Display template → the store-wide Appearance template → the
	 * fallback (the same chain widgetTemplate uses for regular widgets).
	 *
	 * @param {Object} mission Progress mission entry.
	 * @return {string}
	 */
	const floatingTemplate = ( mission ) => {
		if ( mission && mission.template && FLOATING_TEMPLATES.indexOf( mission.template ) !== -1 ) {
			return mission.template;
		}

		if ( cfg.template && FLOATING_TEMPLATES.indexOf( cfg.template ) !== -1 ) {
			return cfg.template;
		}

		return 'template-1';
	}

	/**
	 * Rebuild the drawer's mission cards from the payload.
	 *
	 * The drawer hosts the same compact mission cards the storefront renders
	 * (missionContainer with the compact variant), capped at a few cards so
	 * the panel stays scannable — it scrolls internally past that. The
	 * close button is preserved across rebuilds.
	 *
	 * @param {Object} data Progress payload data.
	 * @return {void}
	 */
	const renderFloatingDrawer = ( data ) => {
		var container = document.getElementById( FLOATING_ID );

		if ( ! container ) {
			return;
		}

		var drawer = container.querySelector( '.faracart-floating__drawer' );

		if ( ! drawer ) {
			return;
		}

		var close = drawer.querySelector( '.faracart-floating__close' );
		drawer.replaceChildren();

		if ( close ) {
			drawer.appendChild( close );
		}

		var missions = ( data && data.missions ) || [];
		var currency = ( data && data.currency ) || cfg.currency;
		var widget = el( 'div', 'faracart-widget faracart-widget--full' );
		var stack = el( 'div', 'faracart-widget__missions' );

		for ( var i = 0; i < missions.length; i++ ) {
			var mission = missions[ i ];

			if ( ! mission || mission.eligible === false ) {
				continue;
			}

			stack.appendChild( missionContainer( mission, currency, 'compact', floatingTemplate( mission ) ) );
		}

		if ( stack.childNodes.length ) {
			widget.appendChild( stack );
			drawer.appendChild( widget );
		}
	}

	/**
	 * Build the floating button + drawer markup once.
	 *
	 * The container rendered by PHP is inert until an eligible mission
	 * exists; the button/drawer markup is built on first show and kept
	 * (only the drawer's mission cards rebuild per payload). Global click /
	 * Escape handlers close the drawer, and the animation toggle rides on
	 * a class the stylesheet freezes.
	 *
	 * @param {HTMLElement} container The #faracart-floating element.
	 * @return {void}
	 */
	const buildFloating = ( container ) => {
		if ( floatingBuilt ) {
			return;
		}

		var button = el( 'button', 'faracart-floating__button' );
		button.type = 'button';
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.appendChild( el( 'span', 'faracart-floating__icon' ) );
		container.appendChild( button );

		var drawer = el( 'div', 'faracart-floating__drawer' );
		drawer.setAttribute( 'role', 'dialog' );
		container.appendChild( drawer );

		var close = el( 'button', 'faracart-floating__close' );
		close.type = 'button';
		close.textContent = '\u00D7';
		drawer.appendChild( close );

		button.addEventListener( 'click', () => {
			setFloatingOpen( ! floatingOpen );
		} );

		close.addEventListener( 'click', () => {
			setFloatingOpen( false );
		} );

		// Close on outside click and Escape (never while the shopper is
		// interacting inside the drawer).
		document.addEventListener( 'click', ( event ) => {
			if ( floatingOpen && container && ! container.contains( event.target ) ) {
				setFloatingOpen( false );
			}
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( floatingOpen && ( event.key === 'Escape' || event.key === 'Esc' ) ) {
				setFloatingOpen( false );
			}
		} );

		floatingBuilt = true;
	}

	/**
	 * Render the floating widget for a progress payload.
	 *
	 * Gated on the master toggle, the per-device visibility flag and at
	 * least one eligible mission — the button stays hidden (and the drawer
	 * closes) whenever any gate fails. The position and button appearance
	 * apply on every render (cheap, and keeps the widget correct across
	 * resize/scroll), while the drawer mission cards only rebuild when the
	 * payload fingerprint changes.
	 *
	 * @param {Object} data Progress payload data.
	 * @return {void}
	 */
	const renderFloating = ( data ) => {
		var container = document.getElementById( FLOATING_ID );

		if ( ! container ) {
			return;
		}

		var floating = floatingConfig();
		var missions = ( data && data.missions ) || [];
		var hasEligible = false;

		for ( var i = 0; i < missions.length; i++ ) {
			if ( missions[ i ] && missions[ i ].eligible !== false ) {
				hasEligible = true;
				break;
			}
		}

		var visible = floating.enabled && floating.visible && hasEligible;

		if ( ! visible ) {
			if ( floatingOpen ) {
				setFloatingOpen( false );
			}

			container.classList.remove( 'faracart-floating--visible' );
			container.setAttribute( 'aria-hidden', 'true' );
			return;
		}

		if ( ! floatingBuilt ) {
			buildFloating( container );
		}

		applyFloatingPosition( container, floating.position, floating.buttonSize, floatingSafeInsets() );

		container.classList.toggle( 'faracart-floating--no-anim', ! floating.animation );
		container.style.setProperty( '--faracart-floating-size', floating.buttonSize + 'px' );

		var icon = container.querySelector( '.faracart-floating__icon' );

		if ( icon ) {
			icon.textContent = floating.icon || FLOATING_DEFAULT_ICON;
		}

		var button = container.querySelector( '.faracart-floating__button' );

		if ( button ) {
			var label = floating.label || floating.labels.open || 'View your cart missions';
			button.setAttribute( 'aria-label', label );
			button.title = label;
		}

		var fingerprint = payloadFingerprint( data );

		if ( floatingFingerprint !== fingerprint ) {
			floatingFingerprint = fingerprint;
			renderFloatingDrawer( data );
		}

		container.classList.add( 'faracart-floating--visible' );
		container.setAttribute( 'aria-hidden', 'false' );
	}

	/**
	 * Re-evaluate the floating widget when the viewport crosses the mobile
	 * breakpoint (device-specific position and visibility) — debounced so
	 * a resize drag does not fetch the progress endpoint per event.
	 *
	 * @return {void}
	 */
	const bindFloatingResize = () => {
		if ( ! cfg.floating || ! cfg.floating.enabled ) {
			return;
		}

		var timer = null;

		window.addEventListener( 'resize', () => {
			if ( timer ) {
				window.clearTimeout( timer );
			}

			timer = window.setTimeout( () => {
			timer = null;
			// Orientation/rotation changes safe-area insets; the next
			// render re-measures them.
			floatingInsetCache = null;

			safe( () => {
				// Re-constrain an open drawer: the viewport (and possibly the
				// device position) changed, so the panel must stay on screen
				// with its scroll area reachable. The direction/side come
				// from the position preset and were stored at open time.
				if ( floatingOpen ) {
					var container = document.getElementById( FLOATING_ID );

					if ( container ) {
						var drawer = container.querySelector( '.faracart-floating__drawer' );
						var button = container.querySelector( '.faracart-floating__button' );

						if ( drawer && button ) {
							constrainFloatingDrawer( drawer, button.getBoundingClientRect(), floatingDirection, floatingButtonSideState );
						}
					}
				}

				refresh();
			} );
		}, 200 );
		}, { passive: true } );
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
 * (mini-cart fragment refresh) is empty, so it always mounts.
 * Analytics stay on their own per-session dedup.
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
	const setUpdating = ( on ) => {
		var containers = document.querySelectorAll( WIDGET_SELECTOR );

		for ( var i = 0; i < containers.length; i++ ) {
			containers[ i ].classList.toggle( 'faracart-widget--updating', !! on );
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
	const refresh = ( options ) => {
		safe( () => {
			options = options || {};

			if ( options.updating ) {
				setUpdating( true );
			}

			fetchProgress( ( data ) => {
				safe( () => {
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

					// The floating widget resolves its own device position and
					// visibility every render (cheap), so it always tracks the
					// current payload and viewport.
					renderFloating( data );

					trackMissions( data );
				} );
			}, () => {
				safe( () => {
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
	const refreshAfterCartChange = () => {
		if ( cartRefreshTimer ) {
			window.clearTimeout( cartRefreshTimer );
		}
		cartRefreshTimer = window.setTimeout( () => {
			cartRefreshTimer = null;
			refresh( { updating: true } );
		}, 150 );

		if ( cartFollowUpTimer ) {
			window.clearTimeout( cartFollowUpTimer );
		}
		cartFollowUpTimer = window.setTimeout( () => {
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
	 * `faracart:cart-changed` event on document.body. A single listener
	 * runs the debounced refresh, so every widget instance reacts to
	 * every entry point consistently and a future entry point only has to
	 * dispatch the event.
	 *
	 * @return {void}
	 */
	const emitCartChanged = () => {
		try {
			document.body.dispatchEvent( new CustomEvent( 'faracart:cart-changed', { bubbles: true } ) );
		} catch ( error ) {
			refreshAfterCartChange();
		}
	}

	/**
	 * Bind the centralized cart-changed bridge listener.
	 *
	 * @return {void}
	 */
	const bindCartChangedBridge = () => {
		document.body.addEventListener( 'faracart:cart-changed', refreshAfterCartChange );
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
	const bindCartEvents = () => {
		var events = [
			'added_to_cart',
			'removed_from_cart',
			'updated_cart_totals',
			'updated_wc_div',
			'wc_fragments_refreshed',
			'wc_fragments_loaded',
			// Coupon apply/remove and cart emptied change the totals (and
			// therefore mission eligibility) without an item mutation — the
			// widget must refresh for them too.
			'applied_coupon',
			'removed_coupon',
			'wc_cart_emptied',
		];

		if ( window.jQuery ) {
			safe( () => {
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
	 * any cart-data change into the same `faracart:cart-changed` bridge.
	 * The `wc-blocks_*` DOM events the blocks package translates from the
	 * classic jQuery events (add/remove only) are bound too, so block
	 * add-to-cart from archive/product grids is covered even before the
	 * data store updates.
	 *
	 * @return {void}
	 */
	const bindBlockStore = () => {
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
		const cartFingerprint = () => {
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
		wpData.subscribe( () => {
			safe( () => {
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
	 * Bind the delegated unified-recommendation click handler (Suggestions
	 * + Upsells consolidation).
	 *
	 * One listener on document.body covers every widget's panel: the add
	 * buttons run the AJAX add-to-cart flow, and the product name links
	 * report the click. Attribution follows the row's merged source —
	 * suggestion-sourced items keep the Phase 16 suggestion funnel,
	 * upsell-sourced items the Phase 33.7 upsell funnel, 'both' items
	 * feed both (rank-endpoint fallback rows carry no source and belong
	 * to the upsell funnel). The ids and source ride on the row's data
	 * attributes.
	 *
	 * @return {void}
	 */
	const bindUpsellPanel = () => {
		// Bound unconditionally: the template-4 recommend add buttons must
		// work even when the smart-upsell ranking panel is disabled (they
		// add suggestion products through the same public wc-ajax surface).
		document.body.addEventListener( 'click', ( event ) => {
			var target = event.target;

			while ( target && target !== document.body ) {
				if ( target.classList ) {
					if ( target.classList.contains( 'faracart-upsell__add' ) || target.classList.contains( 'faracart-recommend__add' ) ) {
						upsellAdd( target );
						return;
					}

					if ( target.classList.contains( 'faracart-upsell__name' ) ) {
						var missionId = target.getAttribute( 'data-faracart-upsell-mission' ) || '';
						var productId = target.getAttribute( 'data-faracart-upsell-id' ) || '';
						var src = target.getAttribute( 'data-faracart-upsell-source' ) || '';

						if ( src === 'suggestion' || src === 'both' ) {
							sendTrack( 'suggestion_clicked', {
								mission_id: missionId,
								product_id: productId,
							} );
						}

						if ( ! src || src === 'upsell' || src === 'both' ) {
							sendUpsellTrack( 'upsell_clicked', {
								mission_id: missionId,
								product_id: productId,
							} );
						}
						return;
					}
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
	const bindGiftPicker = () => {
		if ( ! cfg.giftEndpoint || ! cfg.giftNonce ) {
			return;
		}

		document.body.addEventListener( 'click', ( event ) => {
			var target = event.target;

			while ( target && target !== document.body ) {
				if ( target.classList && target.classList.contains( 'faracart-gift-picker__button' ) ) {
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
	 * One interval rewrites every `.faracart-countdown__time` readout from
	 * its data attribute every second — no widget re-render involved.
	 *
	 * @return {void}
	 */
	const bindCountdownTicker = () => {
		window.setInterval( () => {
			safe( () => {
				var nodes = document.querySelectorAll( '.faracart-countdown__time' );

				for ( var i = 0; i < nodes.length; i++ ) {
					var end = nodes[ i ].getAttribute( 'data-faracart-end' );

				if ( end ) {
					nodes[ i ].textContent = countdownText( end );
				}
			}
		} );
		}, 1000 );
	}

	/**
	 * Boot the widgets.
	 *
	 * @return {void}
	 */
	const init = () => {
		bindCartChangedBridge();
		bindCartEvents();
		bindBlockStore();
		bindUpsellPanel();
		bindGiftPicker();
		bindCountdownTicker();
		bindFloatingResize();

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
		document.addEventListener( 'DOMContentLoaded', () => {
			safe( init );
		} );
	} else {
		safe( init );
	}
} )();
