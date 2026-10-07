/**
 * WP Free Site Cloner - admin page.
 *
 * Plain ES2017, no jQuery, no build step, no external requests. Built
 * strictly against the AJAX contract in internal/API.md. Server-supplied
 * strings (log lines, file names, error messages) are always inserted with
 * textContent, never innerHTML.
 *
 * v0.9.1 additions: a local pre-upload check for .tar/.wpress/.zip archives
 * (fail early, before sending any bytes), a visible "checking the archive"
 * state between upload completion and the inspect result (never stuck at
 * 100% with only a Cancel button), inline inspect/upload-check errors in
 * the import card, a step list with per-step progress/elapsed/heartbeat/
 * stall detection for both export and import, and timestamped notices that
 * are cleared whenever a new action starts. The server side of the step
 * list (steps/step_detail/step_progress/last_progress_at/server_time) is
 * optional in every job response: everything here falls back to the plain
 * phase_label/progress fields when it is absent, so this file works
 * against both the old and the new backend.
 */
( function () {
	'use strict';

	var CFG = window.FSC_Admin || {};
	// wp_localize_script() renders every scalar as a JSON string (e.g.
	// "chunkSize":"1677721", not 1677721) - a documented WP core quirk, not
	// a bug in the localized data itself. Left uncoerced, `offset +
	// CFG.chunkSize` in the chunked upload below does string concatenation
	// instead of addition (e.g. 1677721 + "1677721" -> "16777211677721"),
	// which Math.min() then coerces back into a number far larger than the
	// file, so the "next chunk" slice runs to the end of the file instead
	// of one chunk - the browser then POSTs the whole remainder in one
	// request and PHP rejects it for exceeding post_max_size. Coerce the
	// numeric fields once, here, rather than at each use.
	CFG.chunkSize = parseInt( CFG.chunkSize, 10 ) || 8 * 1048576;
	CFG.maxBudget = parseFloat( CFG.maxBudget ) || 15;

	/* ------------------------------------------------------------------ */
	/* Small utilities                                                     */
	/* ------------------------------------------------------------------ */

	function qs( id ) {
		return document.getElementById( id );
	}

	function ce( tag, className, text ) {
		var el = document.createElement( tag );
		if ( className ) {
			el.className = className;
		}
		if ( undefined !== text && null !== text ) {
			el.textContent = text;
		}
		return el;
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			setTimeout( resolve, ms );
		} );
	}

	/**
	 * Minimal printf-style formatter: replaces "%s" tokens in order. Good
	 * enough for the fixed message templates in admin/view-page.php; not a
	 * general i18n library (deliberately no wp.i18n dependency).
	 */
	function fmt( str ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( str ).replace( /%s/g, function () {
			var v = args[ i ];
			i++;
			return undefined === v ? '' : String( v );
		} );
	}

	function formatBytes( n ) {
		n = Number( n );
		if ( ! isFinite( n ) || n < 0 ) {
			n = 0;
		}
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i++;
		}
		return ( 0 === i ? String( Math.round( n ) ) : n.toFixed( 1 ) ) + ' ' + units[ i ];
	}

	/**
	 * Integer with thousands separators (","), locale-independent - used
	 * wherever an exact byte count matters (truncation diagnostics, the
	 * archives table) alongside the human-readable formatBytes().
	 */
	function formatIntCommas( n ) {
		n = Math.round( Number( n ) ) || 0;
		var neg = n < 0;
		var s = String( Math.abs( n ) );
		var out = '';
		for ( var i = 0; i < s.length; i++ ) {
			if ( i > 0 && ( s.length - i ) % 3 === 0 ) {
				out += ',';
			}
			out += s[ i ];
		}
		return ( neg ? '-' : '' ) + out;
	}

	/** Seconds -> "m:ss" or "h:mm:ss". */
	function formatDuration( sec ) {
		sec = Math.max( 0, Math.floor( Number( sec ) || 0 ) );
		var h = Math.floor( sec / 3600 );
		var m = Math.floor( ( sec % 3600 ) / 60 );
		var s = sec % 60;
		var ss = ( s < 10 ? '0' : '' ) + s;
		if ( h > 0 ) {
			return h + ':' + ( m < 10 ? '0' : '' ) + m + ':' + ss;
		}
		return m + ':' + ss;
	}

	function backoffDelay( attempt ) {
		return Math.min( 1000 * Math.pow( 2, attempt - 1 ), 8000 );
	}

	function isRetryable( err ) {
		return !! ( err && ( err.network || ( err.http && err.http >= 500 ) ) );
	}

	var I18N = {};
	function loadI18n() {
		var root = qs( 'fsc-i18n' );
		if ( ! root ) {
			return;
		}
		var spans = root.querySelectorAll( '[data-key]' );
		for ( var i = 0; i < spans.length; i++ ) {
			I18N[ spans[ i ].getAttribute( 'data-key' ) ] = spans[ i ].textContent;
		}
	}
	function t( key ) {
		return I18N[ key ] || key;
	}

	function showGlobalNotice( message ) {
		var el = qs( 'fsc-global-notice' );
		var msg = qs( 'fsc-global-notice-msg' );
		if ( ! el || ! msg ) {
			return;
		}
		msg.textContent = message;
		el.hidden = false;
	}

	/**
	 * Show an inline notice/error box with a local timestamp, and a
	 * re-render flash (disabled under prefers-reduced-motion via CSS) so a
	 * repeated identical message is still visibly a *new* message - the
	 * operator's ask: a stale message on screen must never be mistaken for
	 * a fresh one.
	 */
	function showMsgBox( boxEl, msgEl, timeEl, message ) {
		if ( ! boxEl || ! msgEl ) {
			return;
		}
		msgEl.textContent = message;
		if ( timeEl ) {
			timeEl.textContent = new Date().toLocaleTimeString();
		}
		boxEl.hidden = false;
		boxEl.classList.remove( 'fsc-flash' );
		// Force reflow so re-adding the class restarts the CSS animation
		// even when the message text is identical to what was already shown.
		void boxEl.offsetWidth; // eslint-disable-line no-void
		boxEl.classList.add( 'fsc-flash' );
	}

	function hideMsgBox( boxEl ) {
		if ( ! boxEl ) {
			return;
		}
		boxEl.hidden = true;
		boxEl.classList.remove( 'fsc-flash' );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX helper                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * POST to FSC_Admin.ajaxUrl. Resolves with response.data on
	 * {success:true}; rejects with an Error carrying .code, .http, .data,
	 * .network (transport/5xx/timeout failure), .timeout (network failure
	 * specifically caused by the timeoutMs deadline) or .cancelled (aborted
	 * by the caller) on anything else.
	 *
	 * @param {string} action     wp_ajax_ action name.
	 * @param {Object} fields     Form fields (nonce/token included by caller).
	 * @param {Object} [opts]     {file:{field,blob,filename}, controller, timeoutMs}
	 * @return {Promise<Object>}
	 */
	function callAjax( action, fields, opts ) {
		opts = opts || {};
		var fd = new FormData();
		fd.append( 'action', action );
		Object.keys( fields || {} ).forEach( function ( k ) {
			var v = fields[ k ];
			if ( undefined !== v && null !== v ) {
				fd.append( k, v );
			}
		} );
		if ( opts.file ) {
			fd.append( opts.file.field, opts.file.blob, opts.file.filename || 'chunk' );
		}
		var controller = opts.controller || new AbortController();
		var timeoutMs = opts.timeoutMs || 30000;
		var timedOut = false;
		var timer = setTimeout( function () {
			timedOut = true;
			controller.abort();
		}, timeoutMs );

		return fetch( CFG.ajaxUrl, {
			method: 'POST',
			body: fd,
			credentials: 'same-origin',
			signal: controller.signal,
		} ).then(
			function ( res ) {
				clearTimeout( timer );
				return res.json().catch( function () {
					return null;
				} ).then( function ( json ) {
					if ( ! json || true !== json.success ) {
						var data = ( json && json.data ) || {};
						var err = new Error( data.message || ( 'HTTP ' + res.status ) );
						err.code = data.code || 'error';
						err.http = res.status;
						err.data = data;
						if ( ! json && res.status >= 500 ) {
							err.network = true;
						}
						throw err;
					}
					return json.data;
				} );
			},
			function () {
				clearTimeout( timer );
				var err = new Error( 'network' );
				if ( controller.userCancelled ) {
					err.cancelled = true;
				} else if ( timedOut ) {
					err.network = true;
					err.timeout = true;
				} else {
					err.network = true;
				}
				throw err;
			}
		);
	}

	function abortController( ctx ) {
		ctx.controller = new AbortController();
		return ctx.controller;
	}

	function cancelController( ctx ) {
		if ( ctx.controller ) {
			ctx.controller.userCancelled = true;
			ctx.controller.abort();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Generic stepping/retry loop (export + import steps)                 */
	/* Protocol per internal/API.md "Stepping protocol": send step, render, */
	/* loop immediately while running; on network/5xx/timeout retry with   */
	/* backoff and a halved (min 2s) budget up to 5 consecutive failures;  */
	/* "busy" waits ~1-2s and is not a failure; anything else is fatal.    */
	/* ------------------------------------------------------------------ */

	function runStepLoop( ctx ) {
		ctx.active = true;
		var budget = CFG.maxBudget;
		var logSince = ctx.logSince || 0;
		var failures = 0;

		function step() {
			if ( ! ctx.active ) {
				return Promise.resolve();
			}
			return ctx.sendStep( budget, logSince ).then(
				function ( data ) {
					failures = 0;
					budget = CFG.maxBudget;
					logSince = data.log_seq;
					ctx.logSince = logSince;
					ctx.onJob( data );
					if ( 'done' === data.status ) {
						ctx.active = false;
						ctx.onDone( data );
						return;
					}
					if ( 'error' === data.status ) {
						ctx.active = false;
						ctx.onServerError( data );
						return;
					}
					return step();
				},
				function ( e ) {
					if ( e.cancelled || ! ctx.active ) {
						return;
					}
					if ( 'busy' === e.code ) {
						ctx.onBusy && ctx.onBusy();
						return sleep( 1000 + Math.random() * 1000 ).then( step );
					}
					if ( isRetryable( e ) ) {
						failures++;
						if ( failures > 5 ) {
							ctx.active = false;
							ctx.onConnectionLost( e );
							return;
						}
						budget = Math.max( 2, budget / 2 );
						var delayMs = backoffDelay( failures );
						// Visible retry text (operator ask: "The server did
						// not respond (attempt N of 5). Retrying in Ns...")
						// - the delay shown is the actual sleep below, not
						// the (unrelated) halved per-step budget.
						ctx.onRetrying( failures, 5, Math.max( 1, Math.round( delayMs / 1000 ) ) );
						return sleep( delayMs ).then( step );
					}
					ctx.active = false;
					ctx.onFatal( e );
				}
			);
		}

		return step();
	}

	/* ------------------------------------------------------------------ */
	/* Unload guard                                                        */
	/* ------------------------------------------------------------------ */

	var jobsRunning = 0;
	function jobStarted() {
		jobsRunning++;
	}
	function jobStopped() {
		jobsRunning = Math.max( 0, jobsRunning - 1 );
	}
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( jobsRunning > 0 ) {
			e.preventDefault();
			e.returnValue = t( 'leave_warning' );
			return e.returnValue;
		}
	} );

	/* ------------------------------------------------------------------ */
	/* Log + warnings rendering                                            */
	/* ------------------------------------------------------------------ */

	function appendLog( container, entries ) {
		if ( ! entries || ! entries.length ) {
			return;
		}
		for ( var i = 0; i < entries.length; i++ ) {
			var row = entries[ i ];
			var line = ce( 'div', 'fsc-log-line' );
			var time = new Date( row.time * 1000 );
			line.appendChild( ce( 'span', 'fsc-log-time', time.toLocaleTimeString() ) );
			line.appendChild( ce( 'span', 'fsc-log-msg', row.msg ) );
			container.appendChild( line );
		}
		while ( container.children.length > 500 ) {
			container.removeChild( container.firstChild );
		}
		container.scrollTop = container.scrollHeight;
	}

	function resetLog( container ) {
		while ( container.firstChild ) {
			container.removeChild( container.firstChild );
		}
	}

	function setProgress( progressEl, percentEl, value ) {
		value = Math.max( 0, Math.min( 100, Math.round( value ) ) );
		progressEl.value = value;
		progressEl.setAttribute( 'aria-valuenow', String( value ) );
		progressEl.removeAttribute( 'aria-valuetext' );
		percentEl.textContent = value + '%';
	}

	/**
	 * Collect "WARNING:" log lines (e.g. the compat safety net deactivating
	 * plugins or switching the theme, or the verify step warning about an
	 * unverified 0.9.0 archive - see internal/API.md) onto ctx.warnings as
	 * they stream in, so both the live running panel and the done screen
	 * can show them prominently even though the log itself is inside a
	 * collapsed <details>. Each poll only returns entries newer than
	 * log_since, so entries are never seen twice.
	 */
	function collectWarnings( ctx, entries ) {
		ctx.warnings = ctx.warnings || [];
		if ( ! entries || ! entries.length ) {
			return;
		}
		for ( var i = 0; i < entries.length; i++ ) {
			var msg = entries[ i ].msg || '';
			if ( 0 === msg.indexOf( 'WARNING:' ) ) {
				ctx.warnings.push( msg );
			}
		}
	}

	/**
	 * Render collected WARNING lines as a notice-warning list. Used both
	 * live (while a job is running) and on the done screen - textContent
	 * only, messages come from the server log.
	 */
	function renderWarnings( listEl, containerEl, warnings ) {
		while ( listEl.firstChild ) {
			listEl.removeChild( listEl.firstChild );
		}
		if ( warnings && warnings.length ) {
			warnings.forEach( function ( w ) {
				listEl.appendChild( ce( 'li', null, w ) );
			} );
			containerEl.hidden = false;
		} else {
			containerEl.hidden = true;
		}
	}

	/**
	 * fsc_cancel on a swapped-but-still-active import returns "conflict"
	 * (internal/API.md): the live database cannot be rolled back, only
	 * finished. Add the 10-minute idle rule so the message is actionable
	 * instead of a dead end.
	 */
	function cancelErrorMessage( e ) {
		var msg = fmt( t( 'err_prefix' ), e.message );
		if ( 'conflict' === e.code ) {
			msg += ' ' + t( 'cancel_conflict_hint' );
		}
		return msg;
	}

	/* ==================================================================== */
	/* Step list / progress panel (shared by export + import)               */
	/* ==================================================================== */

	function stepStateText( state ) {
		var map = { pending: 'step_pending', active: 'step_active', done: 'step_done', skipped: 'step_skipped', failed: 'step_failed' };
		return t( map[ state ] || 'step_pending' );
	}

	function unitLabel( unit ) {
		var map = { rows: 'unit_rows', files: 'unit_files', tables: 'unit_tables', statements: 'unit_statements' };
		return map[ unit ] ? t( map[ unit ] ) : ( unit || '' );
	}

	/** Reuse existing <li> nodes across renders instead of rebuilding the list every poll. */
	function renderSteps( listEl, steps ) {
		while ( listEl.children.length > steps.length ) {
			listEl.removeChild( listEl.lastChild );
		}
		for ( var i = 0; i < steps.length; i++ ) {
			var s = steps[ i ];
			var li = listEl.children[ i ];
			if ( ! li ) {
				li = document.createElement( 'li' );
				var icon = ce( 'span', 'fsc-step-icon' );
				icon.setAttribute( 'aria-hidden', 'true' );
				li.appendChild( icon );
				li.appendChild( ce( 'span', 'fsc-step-label' ) );
				li.appendChild( ce( 'span', 'screen-reader-text fsc-step-state-text' ) );
				listEl.appendChild( li );
			}
			li.className = 'fsc-step fsc-step-' + s.state;
			li.title = s.note || '';
			li.querySelector( '.fsc-step-label' ).textContent = s.label || s.key;
			li.querySelector( '.fsc-step-state-text' ).textContent = ' (' + stepStateText( s.state ) + ')' + ( s.note ? ': ' + s.note : '' );
		}
	}

	function renderStepProgress( progressEl, percentEl, counterEl, sp ) {
		if ( ! sp ) {
			progressEl.hidden = true;
			percentEl.textContent = '';
			counterEl.textContent = '';
			return;
		}
		var done = Number( sp.done ) || 0;
		var total = Number( sp.total ) || 0;
		progressEl.hidden = false;
		if ( total > 0 ) {
			setProgress( progressEl, percentEl, ( done / total ) * 100 );
			counterEl.textContent = 'bytes' === sp.unit
				? fmt( t( 'step_counter_bytes' ), formatBytes( done ), formatBytes( total ) )
				: fmt( t( 'step_counter_generic' ), formatIntCommas( done ), formatIntCommas( total ), unitLabel( sp.unit ) );
		} else {
			// Indeterminate: no known total. Drop the value attribute so
			// the <progress> element renders its native indeterminate
			// (striped/animated) state instead of looking stuck at 0.
			progressEl.removeAttribute( 'value' );
			progressEl.removeAttribute( 'aria-valuenow' );
			progressEl.setAttribute( 'aria-valuetext', t( 'step_active' ) );
			percentEl.textContent = '';
			counterEl.textContent = 'bytes' === sp.unit ? formatBytes( done ) : ( formatIntCommas( done ) + ' ' + unitLabel( sp.unit ) );
		}
	}

	/**
	 * p = { progress, percent, phase, stepsWrap, steps, stepDetail,
	 *       stepProgress, stepPercent, stepCounter, elapsedTotal,
	 *       elapsedStep, heartbeat, stall, stallMsg }
	 * Builds defensively: when data.steps is absent (old backend, or a
	 * request that raced ahead of the v0.9.1 backend work), the step list
	 * is simply hidden and only phase_label/progress (already rendered by
	 * the caller) are shown, exactly like before this feature existed.
	 */
	function renderProgressPanel( p, data ) {
		setProgress( p.progress, p.percent, data.progress );
		p.phase.textContent = data.phase_label || '';

		if ( data.steps && data.steps.length ) {
			p.stepsWrap.hidden = false;
			renderSteps( p.steps, data.steps );
			var active = null;
			for ( var i = 0; i < data.steps.length; i++ ) {
				if ( 'active' === data.steps[ i ].state ) {
					active = data.steps[ i ];
					break;
				}
			}
			if ( active ) {
				p.stepDetail.hidden = ! data.step_detail;
				p.stepDetail.textContent = data.step_detail || '';
				renderStepProgress( p.stepProgress, p.stepPercent, p.stepCounter, data.step_progress );
			} else {
				p.stepDetail.hidden = true;
				p.stepProgress.hidden = true;
				p.stepPercent.textContent = '';
				p.stepCounter.textContent = '';
			}
		} else {
			p.stepsWrap.hidden = true;
		}
	}

	/**
	 * Track job/step start times (for the elapsed clocks), the last time a
	 * response was received (for the heartbeat) and the server-reported
	 * progress staleness (for the stall warning) - purely from data already
	 * in each job response, no extra requests.
	 */
	function updateTimingState( ctx, data ) {
		var now = Date.now();
		if ( ! ctx.jobStartedAt ) {
			ctx.jobStartedAt = now;
		}
		var activeKey = null;
		if ( data.steps && data.steps.length ) {
			for ( var i = 0; i < data.steps.length; i++ ) {
				if ( 'active' === data.steps[ i ].state ) {
					activeKey = data.steps[ i ].key;
					break;
				}
			}
		}
		if ( activeKey !== ctx.activeStepKey ) {
			ctx.activeStepKey = activeKey;
			ctx.stepStartedAt = activeKey ? now : null;
		}
		ctx.lastPollClientTime = now;
		if ( 'number' === typeof data.last_progress_at && 'number' === typeof data.server_time ) {
			ctx.lastKnownStaleSec = Math.max( 0, data.server_time - data.last_progress_at );
		} else {
			ctx.lastKnownStaleSec = null;
		}
	}

	function tickPanel( ctx, p ) {
		var now = Date.now();
		if ( ctx.jobStartedAt ) {
			p.elapsedTotal.textContent = fmt( t( 'elapsed_total' ), formatDuration( ( now - ctx.jobStartedAt ) / 1000 ) );
		}
		p.elapsedStep.textContent = ctx.stepStartedAt ? fmt( t( 'elapsed_step' ), formatDuration( ( now - ctx.stepStartedAt ) / 1000 ) ) : '';
		if ( ctx.lastPollClientTime ) {
			p.heartbeat.textContent = fmt( t( 'heartbeat' ), Math.max( 0, Math.round( ( now - ctx.lastPollClientTime ) / 1000 ) ) );
		}
		if ( null !== ctx.lastKnownStaleSec && undefined !== ctx.lastKnownStaleSec ) {
			var stale = ctx.lastKnownStaleSec + ( now - ctx.lastPollClientTime ) / 1000;
			if ( stale >= 90 ) {
				p.stallMsg.textContent = fmt( t( 'stall_warning' ), Math.round( stale ) );
				p.stall.hidden = false;
			} else {
				p.stall.hidden = true;
			}
		} else {
			p.stall.hidden = true;
		}
	}

	function startTicker( ctx, p ) {
		stopTicker( ctx );
		ctx.tickerId = setInterval( function () {
			tickPanel( ctx, p );
		}, 1000 );
		tickPanel( ctx, p );
	}

	function stopTicker( ctx ) {
		if ( ctx.tickerId ) {
			clearInterval( ctx.tickerId );
			ctx.tickerId = null;
		}
	}

	/* ==================================================================== */
	/* Pre-upload local archive check                                       */
	/* Runs entirely in the browser before any byte is uploaded. Per-format */
	/* completeness checks from internal/API.md's v0.9.1 contract; a        */
	/* manifest that cannot be read/parsed always defers to the server      */
	/* ("let the server decide") rather than blocking a possibly-fine file. */
	/* ==================================================================== */

	function readBlobArrayBuffer( blob ) {
		if ( blob.arrayBuffer ) {
			return blob.arrayBuffer();
		}
		return new Promise( function ( resolve, reject ) {
			var reader = new FileReader();
			reader.onload = function () {
				resolve( reader.result );
			};
			reader.onerror = function () {
				reject( reader.error || new Error( 'read error' ) );
			};
			reader.readAsArrayBuffer( blob );
		} );
	}

	/** ASCII decode u8[start,end), stopping at the first NUL, trimmed. */
	function bytesAsciiTrim( u8, start, end ) {
		var s = '';
		for ( var i = start; i < end && i < u8.length; i++ ) {
			var c = u8[ i ];
			if ( 0 === c ) {
				break;
			}
			s += String.fromCharCode( c );
		}
		return s.replace( /^\s+|\s+$/g, '' );
	}

	/** Zero-padded octal tar field -> integer, or NaN. */
	function parseOctalField( u8, start, end ) {
		var s = bytesAsciiTrim( u8, start, end ).replace( /[^0-7]/g, '' );
		return s ? parseInt( s, 8 ) : NaN;
	}

	/** True if every byte in the buffer is 0. */
	function isAllZero( u8, start, end ) {
		for ( var i = start; i < end; i++ ) {
			if ( 0 !== u8[ i ] ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A well-formed tar always ends on a 512-byte boundary with (at least)
	 * one all-zero 512-byte block; checking the last 1024 bytes covers the
	 * conventional two-block trailer. This is independent of the manifest
	 * and is the only local signal available for archives that predate the
	 * archive_size field (0.9.0).
	 */
	function checkTarTrailer( file ) {
		if ( 0 !== file.size % 512 || file.size < 1024 ) {
			return Promise.resolve( false );
		}
		return readBlobArrayBuffer( file.slice( file.size - 1024, file.size ) ).then( function ( buf ) {
			return isAllZero( new Uint8Array( buf ), 0, 1024 );
		} );
	}

	/**
	 * Fallback for a manifest JSON too large to fully fit in our 64 KB
	 * probe (e.g. a very large "tables" list): internal/API.md guarantees
	 * the exporter always serializes "format", "format_version" then
	 * "archive_size" as the first three manifest keys in that order, so
	 * archive_size's 20 decimal digits always start at a fixed byte offset
	 * (512 + 51 = 563) regardless of how large the rest of the manifest
	 * grows. Sanity-checked against the "archive_size" label that must
	 * immediately precede it before trusting the fixed offset.
	 */
	function extractArchiveSizeDigitsAtFixedOffset( u8 ) {
		if ( u8.length < 583 ) {
			return null;
		}
		if ( 'archive_size' !== bytesAsciiTrim( u8, 548, 560 ) ) {
			return null;
		}
		var digits = bytesAsciiTrim( u8, 563, 583 );
		return /^[0-9]{1,20}$/.test( digits ) ? digits : null;
	}

	function checkTarLocal( file ) {
		var headLen = Math.min( file.size, 65536 );
		return readBlobArrayBuffer( file.slice( 0, headLen ) ).then( function ( buf ) {
			var u8 = new Uint8Array( buf );
			if ( u8.length < 136 || 'fsc-manifest.json' !== bytesAsciiTrim( u8, 0, 100 ) ) {
				return { ok: true }; // Not our format (or unreadable header): let the server decide.
			}
			var size = parseOctalField( u8, 124, 136 );
			var archiveSizeStr = null;
			if ( isFinite( size ) && size >= 0 && 512 + size <= u8.length ) {
				var jsonStr = '';
				for ( var i = 512; i < 512 + size; i++ ) {
					jsonStr += String.fromCharCode( u8[ i ] );
				}
				try {
					jsonStr = decodeURIComponent( escape( jsonStr ) ); // raw bytes -> UTF-8
				} catch ( e ) {} // eslint-disable-line no-empty
				var manifest = null;
				try {
					manifest = JSON.parse( jsonStr );
				} catch ( e ) {} // eslint-disable-line no-empty
				if ( manifest && 'string' === typeof manifest.archive_size ) {
					archiveSizeStr = manifest.archive_size;
				}
			} else {
				// Manifest did not fully fit in the 64 KB probe: try the
				// fixed-offset fallback instead of giving up.
				archiveSizeStr = extractArchiveSizeDigitsAtFixedOffset( u8 );
			}

			if ( null !== archiveSizeStr ) {
				if ( /^0+$/.test( archiveSizeStr ) ) {
					return { ok: false, message: t( 'tar_never_finished' ) };
				}
				var expected = parseInt( archiveSizeStr, 10 );
				if ( isFinite( expected ) && expected > 0 && file.size > expected ) {
					return {
						ok: false,
						message: fmt( t( 'tar_wrong_size' ), formatIntCommas( file.size ), formatIntCommas( expected ) ),
					};
				}
				if ( isFinite( expected ) && expected > 0 && file.size < expected ) {
					var pct = Math.max( 0, Math.round( ( file.size / expected ) * 100 ) );
					return {
						ok: false,
						message: fmt( t( 'tar_incomplete_pct' ), formatIntCommas( file.size ), formatIntCommas( expected ), pct ),
					};
				}
			}
			// No archive_size key at all = a pre-0.9.1 archive: only the
			// end-of-tar trailer can be checked locally (internal/API.md).
			return checkTarTrailer( file ).then( function ( trailerOk ) {
				return trailerOk ? { ok: true } : { ok: false, message: t( 'file_incomplete_generic' ) };
			} );
		} );
	}

	/**
	 * AIOWPM .wpress: the archive must end with one 4377-byte end block
	 * (internal/formats/wpress.md). Two variants: v1 is entirely NUL; v2
	 * has a NUL name field and the byte offset of the block (== file size -
	 * 4377) written as decimal ASCII in the size field.
	 */
	function checkWpressLocal( file ) {
		if ( file.size < 4377 ) {
			return Promise.resolve( { ok: false, message: t( 'file_incomplete_generic' ) } );
		}
		return readBlobArrayBuffer( file.slice( file.size - 4377, file.size ) ).then( function ( buf ) {
			var u8 = new Uint8Array( buf );
			if ( ! isAllZero( u8, 0, 255 ) ) {
				return { ok: false, message: t( 'file_incomplete_generic' ) };
			}
			if ( isAllZero( u8, 0, u8.length ) ) {
				return { ok: true }; // v1 EOF.
			}
			var offsetStr = bytesAsciiTrim( u8, 255, 269 ).replace( /[^0-9]/g, '' );
			var offset = offsetStr ? parseInt( offsetStr, 10 ) : NaN;
			if ( isFinite( offset ) && offset === file.size - 4377 ) {
				return { ok: true }; // v2 EOF.
			}
			return { ok: false, message: t( 'file_incomplete_generic' ) };
		} );
	}

	/** Duplicator .zip: an EOCD signature must exist within the last 65557 bytes (22 + max comment length). */
	function checkZipLocal( file ) {
		var tailLen = Math.min( file.size, 65557 );
		return readBlobArrayBuffer( file.slice( file.size - tailLen, file.size ) ).then( function ( buf ) {
			var u8 = new Uint8Array( buf );
			for ( var i = 0; i <= u8.length - 4; i++ ) {
				if ( 0x50 === u8[ i ] && 0x4b === u8[ i + 1 ] && 0x05 === u8[ i + 2 ] && 0x06 === u8[ i + 3 ] ) {
					return { ok: true };
				}
			}
			return { ok: false, message: t( 'file_incomplete_generic' ) };
		} );
	}

	/** @return {Promise<{ok:boolean,message?:string}>} */
	function checkArchiveLocal( file, ext ) {
		var result;
		if ( 'tar' === ext ) {
			result = checkTarLocal( file );
		} else if ( 'wpress' === ext ) {
			result = checkWpressLocal( file );
		} else if ( 'zip' === ext ) {
			result = checkZipLocal( file );
		} else {
			result = Promise.resolve( { ok: true } ); // .daf: no local check.
		}
		return result.catch( function () {
			return { ok: true }; // A local read failure should never block an otherwise-fine upload.
		} );
	}

	/* ==================================================================== */
	/* Export                                                                */
	/* ==================================================================== */

	var exportEls = {
		idle: qs( 'fsc-export-idle' ),
		startBtn: qs( 'fsc-export-start' ),
		running: qs( 'fsc-export-running' ),
		progress: qs( 'fsc-export-progress' ),
		percent: qs( 'fsc-export-percent' ),
		phase: qs( 'fsc-export-phase' ),
		status: qs( 'fsc-export-status' ),
		stepsWrap: qs( 'fsc-export-steps-wrap' ),
		steps: qs( 'fsc-export-steps' ),
		stepDetail: qs( 'fsc-export-step-detail' ),
		stepProgress: qs( 'fsc-export-step-progress' ),
		stepPercent: qs( 'fsc-export-step-percent' ),
		stepCounter: qs( 'fsc-export-step-counter' ),
		elapsedTotal: qs( 'fsc-export-elapsed-total' ),
		elapsedStep: qs( 'fsc-export-elapsed-step' ),
		heartbeat: qs( 'fsc-export-heartbeat' ),
		stall: qs( 'fsc-export-stall' ),
		stallMsg: qs( 'fsc-export-stall-msg' ),
		liveWarnings: qs( 'fsc-export-live-warnings' ),
		liveWarningsList: qs( 'fsc-export-live-warnings-list' ),
		cancelBtn: qs( 'fsc-export-cancel' ),
		log: qs( 'fsc-export-log' ),
		error: qs( 'fsc-export-error' ),
		errorMsg: qs( 'fsc-export-error-msg' ),
		errorTime: qs( 'fsc-export-error-time' ),
		resumeBtn: qs( 'fsc-export-resume' ),
		dismissBtn: qs( 'fsc-export-dismiss' ),
		done: qs( 'fsc-export-done' ),
		doneMsg: qs( 'fsc-export-done-msg' ),
		doneWarnings: qs( 'fsc-export-done-warnings' ),
		doneWarningsList: qs( 'fsc-export-done-warnings-list' ),
		doneDismissBtn: qs( 'fsc-export-done-dismiss' ),
	};

	var exportPanel = {
		progress: exportEls.progress,
		percent: exportEls.percent,
		phase: exportEls.phase,
		stepsWrap: exportEls.stepsWrap,
		steps: exportEls.steps,
		stepDetail: exportEls.stepDetail,
		stepProgress: exportEls.stepProgress,
		stepPercent: exportEls.stepPercent,
		stepCounter: exportEls.stepCounter,
		elapsedTotal: exportEls.elapsedTotal,
		elapsedStep: exportEls.elapsedStep,
		heartbeat: exportEls.heartbeat,
		stall: exportEls.stall,
		stallMsg: exportEls.stallMsg,
	};

	var exportCtx = { controller: null, jobId: null };

	function clearExportNotices() {
		hideMsgBox( exportEls.error );
		exportEls.errorMsg.textContent = '';
	}

	function exportShow( which ) {
		exportEls.idle.hidden = 'idle' !== which;
		exportEls.running.hidden = 'running' !== which;
		exportEls.error.hidden = 'error' !== which;
		exportEls.done.hidden = 'done' !== which;
	}

	function exportOnJob( data ) {
		if ( data.job_id !== exportCtx.jobId ) {
			exportCtx.jobId = data.job_id;
			resetLog( exportEls.log );
			exportCtx.warnings = [];
			exportCtx.jobStartedAt = null;
			exportCtx.activeStepKey = null;
		}
		exportShow( 'running' );
		updateTimingState( exportCtx, data );
		renderProgressPanel( exportPanel, data );
		exportEls.status.textContent = '';
		appendLog( exportEls.log, data.log );
		collectWarnings( exportCtx, data.log );
		renderWarnings( exportEls.liveWarningsList, exportEls.liveWarnings, exportCtx.warnings );
		startTicker( exportCtx, exportPanel );
	}

	function exportSendStep( budget, logSince ) {
		return callAjax(
			'fsc_export_step',
			{ nonce: CFG.nonce, job_id: exportCtx.jobId, budget: budget, log_since: logSince },
			{ controller: abortController( exportCtx ), timeoutMs: Math.max( 30000, budget * 1000 + 15000 ) }
		);
	}

	function exportOnDone( data ) {
		jobStopped();
		stopTicker( exportCtx );
		exportShow( 'idle' );
		var result = data.result || {};
		exportEls.doneMsg.textContent = fmt( t( 'export_done' ), result.archive || '', formatBytes( result.size ) );
		renderWarnings( exportEls.doneWarningsList, exportEls.doneWarnings, exportCtx.warnings );
		exportEls.done.hidden = false;
		refreshArchives();
	}

	function exportOnServerError( data ) {
		jobStopped();
		stopTicker( exportCtx );
		showMsgBox( exportEls.error, exportEls.errorMsg, exportEls.errorTime, fmt( t( 'err_prefix' ), data.error || '' ) );
		exportEls.resumeBtn.hidden = true;
		exportShow( 'error' );
	}

	function exportOnFatal( e ) {
		jobStopped();
		stopTicker( exportCtx );
		var msg = 'bad_nonce' === e.code ? t( 'reload_needed' ) : fmt( t( 'err_prefix' ), e.message );
		showMsgBox( exportEls.error, exportEls.errorMsg, exportEls.errorTime, msg );
		exportEls.resumeBtn.hidden = true;
		exportShow( 'error' );
	}

	function exportOnConnectionLost() {
		jobStopped();
		stopTicker( exportCtx );
		showMsgBox( exportEls.error, exportEls.errorMsg, exportEls.errorTime, t( 'connection_lost' ) );
		exportEls.resumeBtn.hidden = false;
		exportShow( 'error' );
	}

	function exportOnRetrying( attempt, max, delaySec ) {
		exportEls.status.textContent = fmt( t( 'retrying' ), attempt, max, delaySec );
	}

	function exportOnBusy() {
		exportEls.status.textContent = t( 'waiting_busy' );
	}

	function exportStartLoop() {
		jobStarted();
		// runStepLoop mutates exportCtx.active in place (via Object.assign, same
		// reference) so the Cancel button's exportCtx.active = false reliably
		// stops the loop even while it is asleep between retries, not only
		// when a request happens to be in flight to abort.
		runStepLoop( Object.assign( exportCtx, {
			sendStep: exportSendStep,
			onJob: exportOnJob,
			onDone: exportOnDone,
			onServerError: exportOnServerError,
			onFatal: exportOnFatal,
			onConnectionLost: exportOnConnectionLost,
			onRetrying: exportOnRetrying,
			onBusy: exportOnBusy,
			logSince: exportCtx.logSince || 0,
		} ) );
	}

	function exportResumeExisting( job ) {
		exportCtx.jobId = job.job_id;
		exportCtx.logSince = 0;
		exportOnJob( job );
		exportStartLoop();
	}

	function exportStart( force ) {
		clearExportNotices();
		exportEls.done.hidden = true;
		return callAjax( 'fsc_export_start', { nonce: CFG.nonce, force: force ? '1' : undefined } ).then(
			function ( job ) {
				exportCtx.jobId = job.job_id;
				exportCtx.logSince = 0;
				exportCtx.warnings = [];
				exportCtx.jobStartedAt = null;
				exportCtx.activeStepKey = null;
				exportOnJob( job );
				exportStartLoop();
			},
			function ( e ) {
				if ( 'conflict' === e.code && ! force && window.confirm( t( 'confirm_replace_job' ) ) ) {
					return exportStart( true );
				}
				showMsgBox( exportEls.error, exportEls.errorMsg, exportEls.errorTime, fmt( t( 'err_prefix' ), e.message ) );
				exportEls.resumeBtn.hidden = true;
				exportShow( 'error' );
			}
		);
	}

	exportEls.startBtn.addEventListener( 'click', function () {
		exportStart( false );
	} );
	exportEls.resumeBtn.addEventListener( 'click', function () {
		clearExportNotices();
		exportShow( 'running' );
		exportStartLoop();
	} );
	exportEls.dismissBtn.addEventListener( 'click', function () {
		callAjax( 'fsc_cancel', { nonce: CFG.nonce } ).then( refreshArchives, function () {} ).then( function () {
			exportShow( 'idle' );
		} );
	} );
	exportEls.doneDismissBtn.addEventListener( 'click', function () {
		exportEls.done.hidden = true;
	} );
	exportEls.cancelBtn.addEventListener( 'click', function () {
		if ( ! window.confirm( t( 'confirm_cancel' ) ) ) {
			return;
		}
		cancelController( exportCtx );
		exportCtx.active = false;
		stopTicker( exportCtx );
		jobStopped();
		callAjax( 'fsc_cancel', { nonce: CFG.nonce } ).then( function () {
			exportShow( 'idle' );
			refreshArchives();
		}, function () {
			exportShow( 'idle' );
		} );
	} );

	/* ==================================================================== */
	/* Import: upload                                                       */
	/* ==================================================================== */

	var importEls = {
		orphan: qs( 'fsc-import-orphan' ),
		orphanMsg: qs( 'fsc-import-orphan-msg' ),
		orphanCancelBtn: qs( 'fsc-import-orphan-cancel' ),
		idle: qs( 'fsc-import-idle' ),
		dropzone: qs( 'fsc-dropzone' ),
		fileInput: qs( 'fsc-file-input' ),
		storagePath: qs( 'fsc-storage-path' ),
		checking: qs( 'fsc-import-checking' ),
		checkingText: qs( 'fsc-checking-text' ),
		precheckError: qs( 'fsc-precheck-error' ),
		precheckErrorMsg: qs( 'fsc-precheck-error-msg' ),
		precheckErrorTime: qs( 'fsc-precheck-error-time' ),
		precheckChooseAnother: qs( 'fsc-precheck-choose-another' ),
		uploading: qs( 'fsc-import-uploading' ),
		uploadProgressWrap: qs( 'fsc-upload-progress-wrap' ),
		uploadProgress: qs( 'fsc-upload-progress' ),
		uploadPercent: qs( 'fsc-upload-percent' ),
		uploadStatus: qs( 'fsc-upload-status' ),
		uploadCancelBtn: qs( 'fsc-upload-cancel' ),
		uploadInspectingWrap: qs( 'fsc-upload-inspecting-wrap' ),
		uploadInspectingText: qs( 'fsc-upload-inspecting-text' ),
		uploadInspectingCancel: qs( 'fsc-upload-inspecting-cancel' ),
		uploadError: qs( 'fsc-upload-error' ),
		uploadErrorMsg: qs( 'fsc-upload-error-msg' ),
		uploadErrorTime: qs( 'fsc-upload-error-time' ),
		uploadDismissBtn: qs( 'fsc-upload-dismiss' ),
		inspectError: qs( 'fsc-inspect-error' ),
		inspectErrorMsg: qs( 'fsc-inspect-error-msg' ),
		inspectErrorTime: qs( 'fsc-inspect-error-time' ),
		inspectChooseAnother: qs( 'fsc-inspect-choose-another' ),
		inspectDeleteUpload: qs( 'fsc-inspect-delete-upload' ),
		inspect: qs( 'fsc-import-inspect' ),
		inspectHeading: qs( 'fsc-inspect-heading' ),
		inspName: qs( 'fsc-insp-name' ),
		inspHome: qs( 'fsc-insp-home' ),
		inspTargetHome: qs( 'fsc-insp-target-home' ),
		inspFormat: qs( 'fsc-insp-format' ),
		inspWpver: qs( 'fsc-insp-wpver' ),
		inspSize: qs( 'fsc-insp-size' ),
		inspWarnings: qs( 'fsc-insp-warnings' ),
		inspWarningsList: qs( 'fsc-insp-warnings-list' ),
		confirmCheckbox: qs( 'fsc-confirm-checkbox' ),
		importStartBtn: qs( 'fsc-import-start' ),
		inspectBackBtn: qs( 'fsc-import-inspect-back' ),
		running: qs( 'fsc-import-running' ),
		progress: qs( 'fsc-import-progress' ),
		percent: qs( 'fsc-import-percent' ),
		phase: qs( 'fsc-import-phase' ),
		status: qs( 'fsc-import-status' ),
		stepsWrap: qs( 'fsc-import-steps-wrap' ),
		steps: qs( 'fsc-import-steps' ),
		stepDetail: qs( 'fsc-import-step-detail' ),
		stepProgress: qs( 'fsc-import-step-progress' ),
		stepPercent: qs( 'fsc-import-step-percent' ),
		stepCounter: qs( 'fsc-import-step-counter' ),
		elapsedTotal: qs( 'fsc-import-elapsed-total' ),
		elapsedStep: qs( 'fsc-import-elapsed-step' ),
		heartbeat: qs( 'fsc-import-heartbeat' ),
		stall: qs( 'fsc-import-stall' ),
		stallMsg: qs( 'fsc-import-stall-msg' ),
		liveWarnings: qs( 'fsc-import-live-warnings' ),
		liveWarningsList: qs( 'fsc-import-live-warnings-list' ),
		cancelBtn: qs( 'fsc-import-cancel' ),
		log: qs( 'fsc-import-log' ),
		error: qs( 'fsc-import-error' ),
		errorMsg: qs( 'fsc-import-error-msg' ),
		errorTime: qs( 'fsc-import-error-time' ),
		resumeBtn: qs( 'fsc-import-resume' ),
		dismissBtn: qs( 'fsc-import-dismiss' ),
		done: qs( 'fsc-import-done' ),
		doneWarnings: qs( 'fsc-import-done-warnings' ),
		doneWarningsList: qs( 'fsc-import-done-warnings-list' ),
		loginLink: qs( 'fsc-import-login-link' ),
	};

	var importPanel = {
		progress: importEls.progress,
		percent: importEls.percent,
		phase: importEls.phase,
		stepsWrap: importEls.stepsWrap,
		steps: importEls.steps,
		stepDetail: importEls.stepDetail,
		stepProgress: importEls.stepProgress,
		stepPercent: importEls.stepPercent,
		stepCounter: importEls.stepCounter,
		elapsedTotal: importEls.elapsedTotal,
		elapsedStep: importEls.elapsedStep,
		heartbeat: importEls.heartbeat,
		stall: importEls.stall,
		stallMsg: importEls.stallMsg,
	};

	function clearImportNotices() {
		hideMsgBox( importEls.precheckError );
		importEls.precheckErrorMsg.textContent = '';
		hideMsgBox( importEls.uploadError );
		importEls.uploadErrorMsg.textContent = '';
		hideMsgBox( importEls.inspectError );
		importEls.inspectErrorMsg.textContent = '';
		hideMsgBox( importEls.error );
		importEls.errorMsg.textContent = '';
	}

	function importShow( which ) {
		importEls.orphan.hidden = 'orphan' !== which;
		importEls.idle.hidden = 'idle' !== which;
		importEls.checking.hidden = 'checking' !== which;
		importEls.precheckError.hidden = 'precheck-error' !== which;
		importEls.uploading.hidden = 'uploading' !== which;
		importEls.inspectError.hidden = 'inspect-error' !== which;
		importEls.inspect.hidden = 'inspect' !== which;
		importEls.running.hidden = 'running' !== which;
		importEls.error.hidden = 'error' !== which;
		importEls.done.hidden = 'done' !== which;
		if ( 'uploading' !== which ) {
			importEls.uploadError.hidden = true;
			// Reset the uploading panel's internal sub-state (progress vs.
			// "checking the archive") so a stale inner hidden="" attribute
			// never lingers on an element whose ancestor panel is now
			// hidden - both for the next real upload and for any code
			// (tests, a11y tools) that queries state by attribute rather
			// than computed visibility.
			importEls.uploadProgressWrap.hidden = false;
			importEls.uploadInspectingWrap.hidden = true;
		}
	}

	var ALLOWED_EXT = [ 'tar', 'wpress', 'zip', 'daf' ];

	function fileExtension( name ) {
		var m = /\.([a-z0-9]+)$/i.exec( name || '' );
		return m ? m[ 1 ].toLowerCase() : '';
	}

	function sanitizeFilename( name ) {
		name = String( name || '' ).trim();
		var ext = fileExtension( name ) || 'tar';
		var base = name.slice( 0, name.length - ext.length - 1 );
		// The server refuses any inner dot-part (e.g. "site.php.tar",
		// "a..tar") - a dotted base name could otherwise collide with an
		// executable extension on some servers, or collapse into "..".
		// Replace every dot in the base with "-" before the general
		// character filter, so only the final extension keeps its dot.
		base = base.replace( /\./g, '-' );
		base = base.replace( /[^A-Za-z0-9_-]+/g, '-' );
		base = base.replace( /^[^A-Za-z0-9]+/, '' );
		if ( ! base ) {
			base = 'archive';
		}
		var maxBase = 200 - ext.length - 1;
		if ( base.length > maxBase ) {
			base = base.slice( 0, maxBase );
		}
		return base + '.' + ext;
	}

	var uploadCtx = { controller: null, active: false };
	// Separate from uploadCtx: covers the post-upload fsc_import_inspect
	// call, which has its own AbortController/timeout/ticker and can be
	// cancelled independently of an in-progress chunk upload (there never
	// is one at that point - the upload already finished).
	var inspectCtx = { controller: null, tickerId: null, startedAt: 0 };
	var pendingInspectName = null;
	// Name of the archive behind whichever inline error box is currently
	// shown (freshly uploaded, or picked via "Use for import"): needed so
	// the inspect-error box's "Delete this upload" button knows what to
	// delete.
	var lastUploadedName = null;

	function handleChosenFile( file ) {
		if ( ! file ) {
			return;
		}
		clearImportNotices();
		var ext = fileExtension( file.name );
		if ( -1 === ALLOWED_EXT.indexOf( ext ) ) {
			showMsgBox( importEls.precheckError, importEls.precheckErrorMsg, importEls.precheckErrorTime, t( 'upload_invalid_type' ) );
			importShow( 'precheck-error' );
			return;
		}
		importEls.checkingText.textContent = t( 'checking_file' );
		importShow( 'checking' );
		checkArchiveLocal( file, ext ).then( function ( result ) {
			if ( ! result.ok ) {
				showMsgBox( importEls.precheckError, importEls.precheckErrorMsg, importEls.precheckErrorTime, result.message );
				importShow( 'precheck-error' );
				return;
			}
			uploadFile( file );
		} );
	}

	function uploadFile( file ) {
		var name = sanitizeFilename( file.name );
		var total = file.size;
		var offset = 0;
		var finalName = name;
		var failures = 0;
		var startTime = Date.now();
		var totalChunks = Math.max( 1, Math.ceil( total / CFG.chunkSize ) );

		uploadCtx.active = true;
		jobStarted();
		importShow( 'uploading' );
		importEls.uploadProgressWrap.hidden = false;
		importEls.uploadInspectingWrap.hidden = true;
		setProgress( importEls.uploadProgress, importEls.uploadPercent, 0 );
		importEls.uploadStatus.textContent = t( 'upload_preparing' );

		function updateProgress() {
			var elapsed = ( Date.now() - startTime ) / 1000;
			var speed = elapsed > 0 ? offset / elapsed : 0;
			var chunksDone = Math.min( totalChunks, Math.ceil( offset / CFG.chunkSize ) );
			setProgress( importEls.uploadProgress, importEls.uploadPercent, total > 0 ? ( offset / total ) * 100 : 0 );
			var line = fmt( t( 'upload_progress' ), formatBytes( offset ), formatBytes( total ), formatBytes( speed ) )
				+ ' - ' + fmt( t( 'upload_chunk_info' ), chunksDone, totalChunks );
			if ( speed > 0 && offset < total ) {
				line += ' - ' + fmt( t( 'upload_eta' ), formatDuration( ( total - offset ) / speed ) );
			}
			importEls.uploadStatus.textContent = line;
		}

		function sendOne() {
			if ( ! uploadCtx.active ) {
				return Promise.resolve();
			}
			var end = Math.min( offset + CFG.chunkSize, total );
			var blob = file.slice( offset, end );
			return callAjax(
				'fsc_upload_chunk',
				{ nonce: CFG.nonce, name: name, offset: offset, total: total },
				{ file: { field: 'chunk', blob: blob, filename: name }, controller: abortController( uploadCtx ), timeoutMs: 120000 }
			).then(
				function ( data ) {
					failures = 0;
					offset = data.received;
					finalName = data.name;
					updateProgress();
					if ( data.complete ) {
						return finishUpload( finalName, data.check );
					}
					return sendOne();
				},
				function ( e ) {
					if ( e.cancelled || ! uploadCtx.active ) {
						return;
					}
					if ( 'offset_mismatch' === e.code && e.data && 'number' === typeof e.data.expected ) {
						offset = e.data.expected;
						return sendOne();
					}
					if ( isRetryable( e ) ) {
						failures++;
						if ( failures > 5 ) {
							return uploadFailed( e );
						}
						importEls.uploadStatus.textContent = fmt( t( 'upload_retry' ), failures, 5 );
						return sleep( backoffDelay( failures ) ).then( sendOne );
					}
					return uploadFailed( e );
				}
			);
		}

		function uploadFailed( e ) {
			uploadCtx.active = false;
			jobStopped();
			showMsgBox( importEls.uploadError, importEls.uploadErrorMsg, importEls.uploadErrorTime, fmt( t( 'err_prefix' ), e.message ) );
			importEls.uploadError.hidden = false;
		}

		/**
		 * The root cause this whole feature fixes: after the last chunk,
		 * the panel must never sit at 100% with only a Cancel button while
		 * fsc_import_inspect runs silently in the background. Switch at
		 * once to a visible "checking the archive" state with its own
		 * elapsed-time counter and Cancel.
		 */
		function finishUpload( uploadedName, check ) {
			uploadCtx.active = false;
			jobStopped();
			lastUploadedName = uploadedName;
			if ( check && false === check.ok ) {
				showInspectError( uploadedName, check.message || t( 'file_incomplete_generic' ) );
				return;
			}
			beginPostUploadCheck( uploadedName );
		}

		sendOne();
	}

	function updateInspectingElapsed() {
		var sec = Math.round( ( Date.now() - inspectCtx.startedAt ) / 1000 );
		importEls.uploadInspectingText.textContent = t( 'upload_complete_checking' ) + ' ' + fmt( t( 'elapsed_suffix' ), sec );
	}

	function stopInspectingTicker() {
		if ( inspectCtx.tickerId ) {
			clearInterval( inspectCtx.tickerId );
			inspectCtx.tickerId = null;
		}
	}

	function beginPostUploadCheck( name ) {
		importEls.uploadProgressWrap.hidden = true;
		importEls.uploadInspectingWrap.hidden = false;
		inspectCtx.startedAt = Date.now();
		updateInspectingElapsed();
		inspectCtx.tickerId = setInterval( updateInspectingElapsed, 1000 );
		startInspect( name );
	}

	importEls.fileInput.addEventListener( 'change', function () {
		var file = importEls.fileInput.files && importEls.fileInput.files[ 0 ];
		importEls.fileInput.value = '';
		handleChosenFile( file );
	} );

	importEls.dropzone.addEventListener( 'click', function () {
		importEls.fileInput.click();
	} );
	importEls.dropzone.addEventListener( 'keydown', function ( e ) {
		if ( 'Enter' === e.key || ' ' === e.key || 'Spacebar' === e.key ) {
			e.preventDefault();
			importEls.fileInput.click();
		}
	} );
	[ 'dragenter', 'dragover' ].forEach( function ( evt ) {
		importEls.dropzone.addEventListener( evt, function ( e ) {
			e.preventDefault();
			importEls.dropzone.classList.add( 'is-dragover' );
		} );
	} );
	[ 'dragleave', 'dragend' ].forEach( function ( evt ) {
		importEls.dropzone.addEventListener( evt, function () {
			importEls.dropzone.classList.remove( 'is-dragover' );
		} );
	} );
	importEls.dropzone.addEventListener( 'drop', function ( e ) {
		e.preventDefault();
		importEls.dropzone.classList.remove( 'is-dragover' );
		var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ];
		handleChosenFile( file );
	} );

	importEls.uploadCancelBtn.addEventListener( 'click', function () {
		uploadCtx.active = false;
		cancelController( uploadCtx );
		jobStopped();
		importEls.uploadStatus.textContent = t( 'upload_cancelled' );
		importShow( 'idle' );
	} );
	importEls.uploadDismissBtn.addEventListener( 'click', function () {
		importShow( 'idle' );
	} );
	importEls.uploadInspectingCancel.addEventListener( 'click', function () {
		cancelController( inspectCtx );
		stopInspectingTicker();
		importShow( 'idle' );
	} );
	importEls.precheckChooseAnother.addEventListener( 'click', function () {
		clearImportNotices();
		importShow( 'idle' );
	} );
	importEls.inspectChooseAnother.addEventListener( 'click', function () {
		clearImportNotices();
		importShow( 'idle' );
	} );
	importEls.inspectDeleteUpload.addEventListener( 'click', function () {
		if ( ! lastUploadedName ) {
			importShow( 'idle' );
			return;
		}
		if ( ! window.confirm( fmt( t( 'confirm_delete' ), lastUploadedName ) ) ) {
			return;
		}
		callAjax( 'fsc_archive_delete', { nonce: CFG.nonce, name: lastUploadedName } ).then(
			function () {
				lastUploadedName = null;
				clearImportNotices();
				importShow( 'idle' );
				refreshArchives();
			},
			function ( e ) {
				showMsgBox( importEls.inspectError, importEls.inspectErrorMsg, importEls.inspectErrorTime, fmt( t( 'err_prefix' ), e.message ) );
			}
		);
	} );

	/* ==================================================================== */
	/* Import: inspect + confirm                                            */
	/* ==================================================================== */

	/**
	 * Shared by both entry points (a freshly uploaded file, and "Use for
	 * import" on an existing archive): always renders inline in the import
	 * card, with an explicit timeout, never only a page-top notice.
	 */
	function startInspect( name ) {
		pendingInspectName = name;
		lastUploadedName = name;
		return callAjax(
			'fsc_import_inspect',
			{ nonce: CFG.nonce, name: name },
			{ controller: abortController( inspectCtx ), timeoutMs: 120000 }
		).then(
			function ( info ) {
				stopInspectingTicker();
				renderInspect( info );
				importShow( 'inspect' );
				importEls.inspectHeading.focus();
			},
			function ( e ) {
				stopInspectingTicker();
				if ( e.cancelled ) {
					return;
				}
				var message = e.timeout ? fmt( t( 'inspect_timeout' ), 120 ) : fmt( t( 'err_prefix' ), e.message );
				showInspectError( name, message );
				if ( 'not_found' === e.code ) {
					refreshArchives();
				}
			}
		);
	}

	function showInspectError( name, message ) {
		lastUploadedName = name;
		showMsgBox( importEls.inspectError, importEls.inspectErrorMsg, importEls.inspectErrorTime, message );
		importShow( 'inspect-error' );
	}

	function renderInspect( info ) {
		var meta = info.meta || {};
		var target = info.target || {};
		importEls.inspName.textContent = info.name;
		importEls.inspHome.textContent = meta.home || meta.siteurl || '';
		importEls.inspTargetHome.textContent = target.home || target.siteurl || '';
		importEls.inspFormat.textContent = meta.format_name || meta.format || '';
		importEls.inspWpver.textContent = meta.wp_version || '';
		importEls.inspSize.textContent = formatBytes( info.size ) + ' (' + fmt( t( 'bytes_exact' ), formatIntCommas( info.size ) ) + ')';

		while ( importEls.inspWarningsList.firstChild ) {
			importEls.inspWarningsList.removeChild( importEls.inspWarningsList.firstChild );
		}
		var warnings = info.warnings || [];
		if ( warnings.length ) {
			warnings.forEach( function ( w ) {
				importEls.inspWarningsList.appendChild( ce( 'li', null, w ) );
			} );
			importEls.inspWarnings.hidden = false;
		} else {
			importEls.inspWarnings.hidden = true;
		}

		importEls.confirmCheckbox.checked = false;
		importEls.importStartBtn.disabled = true;
	}

	importEls.confirmCheckbox.addEventListener( 'change', function () {
		importEls.importStartBtn.disabled = ! importEls.confirmCheckbox.checked;
	} );
	importEls.inspectBackBtn.addEventListener( 'click', function () {
		pendingInspectName = null;
		importShow( 'idle' );
	} );

	/* ==================================================================== */
	/* Import: run                                                          */
	/* ==================================================================== */

	var importCtx = { controller: null, jobId: null, token: null };
	var TOKEN_KEY = 'fsc_import_token';

	function importOnJob( data ) {
		if ( data.job_id !== importCtx.jobId ) {
			importCtx.jobId = data.job_id;
			resetLog( importEls.log );
			importCtx.warnings = [];
			importCtx.jobStartedAt = null;
			importCtx.activeStepKey = null;
		}
		importShow( 'running' );
		updateTimingState( importCtx, data );
		renderProgressPanel( importPanel, data );
		importEls.status.textContent = '';
		appendLog( importEls.log, data.log );
		collectWarnings( importCtx, data.log );
		renderWarnings( importEls.liveWarningsList, importEls.liveWarnings, importCtx.warnings );
		importEls.cancelBtn.hidden = !! data.swapped;
		startTicker( importCtx, importPanel );
	}

	function importSendStep( budget, logSince ) {
		return callAjax(
			'fsc_import_step',
			{ token: importCtx.token, budget: budget, log_since: logSince },
			{ controller: abortController( importCtx ), timeoutMs: Math.max( 30000, budget * 1000 + 15000 ) }
		);
	}

	function importOnDone() {
		jobStopped();
		stopTicker( importCtx );
		try {
			window.sessionStorage.removeItem( TOKEN_KEY );
		} catch ( e ) {} // eslint-disable-line no-empty
		importCtx.token = null;
		renderWarnings( importEls.doneWarningsList, importEls.doneWarnings, importCtx.warnings );
		importShow( 'done' );
		importEls.loginLink.href = CFG.loginUrl;
	}

	function importOnServerError( data ) {
		jobStopped();
		stopTicker( importCtx );
		try {
			window.sessionStorage.removeItem( TOKEN_KEY );
		} catch ( e ) {} // eslint-disable-line no-empty
		showMsgBox( importEls.error, importEls.errorMsg, importEls.errorTime, fmt( t( 'err_prefix' ), data.error || '' ) );
		importEls.resumeBtn.hidden = true;
		importShow( 'error' );
	}

	function importOnFatal( e ) {
		jobStopped();
		stopTicker( importCtx );
		if ( 'bad_token' === e.code ) {
			try {
				window.sessionStorage.removeItem( TOKEN_KEY );
			} catch ( er ) {} // eslint-disable-line no-empty
			importCtx.token = null;
		}
		showMsgBox( importEls.error, importEls.errorMsg, importEls.errorTime, fmt( t( 'err_prefix' ), e.message ) );
		importEls.resumeBtn.hidden = true;
		importShow( 'error' );
	}

	function importOnConnectionLost() {
		jobStopped();
		stopTicker( importCtx );
		showMsgBox( importEls.error, importEls.errorMsg, importEls.errorTime, t( 'connection_lost' ) );
		importEls.resumeBtn.hidden = false;
		importShow( 'error' );
	}

	function importOnRetrying( attempt, max, delaySec ) {
		importEls.status.textContent = fmt( t( 'retrying' ), attempt, max, delaySec );
	}

	function importOnBusy() {
		importEls.status.textContent = t( 'waiting_busy' );
	}

	function importStartLoop() {
		jobStarted();
		// Same reference reused by runStepLoop (Object.assign), see the note
		// on exportStartLoop: keeps Cancel effective even mid-backoff-sleep.
		runStepLoop( Object.assign( importCtx, {
			sendStep: importSendStep,
			onJob: importOnJob,
			onDone: importOnDone,
			onServerError: importOnServerError,
			onFatal: importOnFatal,
			onConnectionLost: importOnConnectionLost,
			onRetrying: importOnRetrying,
			onBusy: importOnBusy,
			logSince: importCtx.logSince || 0,
		} ) );
	}

	importEls.importStartBtn.addEventListener( 'click', function () {
		if ( ! pendingInspectName || ! importEls.confirmCheckbox.checked ) {
			return;
		}
		clearImportNotices();
		importEls.importStartBtn.disabled = true;
		doImportStart( pendingInspectName, false );
	} );

	function doImportStart( name, force ) {
		return callAjax( 'fsc_import_start', { nonce: CFG.nonce, name: name, confirm: '1', force: force ? '1' : undefined } ).then(
			function ( job ) {
				importCtx.jobId = job.job_id;
				importCtx.token = job.token;
				try {
					window.sessionStorage.setItem( TOKEN_KEY, job.token );
				} catch ( e ) {} // eslint-disable-line no-empty
				importCtx.logSince = 0;
				importCtx.warnings = [];
				importCtx.jobStartedAt = null;
				importCtx.activeStepKey = null;
				importOnJob( job );
				importStartLoop();
			},
			function ( e ) {
				if ( 'conflict' === e.code && ! force && window.confirm( t( 'confirm_replace_job' ) ) ) {
					return doImportStart( name, true );
				}
				importEls.importStartBtn.disabled = false;
				showMsgBox( importEls.error, importEls.errorMsg, importEls.errorTime, fmt( t( 'err_prefix' ), e.message ) );
				importEls.resumeBtn.hidden = true;
				importShow( 'error' );
			}
		);
	}

	importEls.resumeBtn.addEventListener( 'click', function () {
		clearImportNotices();
		importShow( 'running' );
		importStartLoop();
	} );
	importEls.dismissBtn.addEventListener( 'click', function () {
		callAjax( 'fsc_cancel', { nonce: CFG.nonce } ).then( refreshArchives, function () {} ).then( function () {
			pendingInspectName = null;
			importShow( 'idle' );
		} );
	} );
	importEls.cancelBtn.addEventListener( 'click', function () {
		if ( ! window.confirm( t( 'confirm_cancel' ) ) ) {
			return;
		}
		cancelController( importCtx );
		importCtx.active = false;
		stopTicker( importCtx );
		jobStopped();
		callAjax( 'fsc_cancel', { nonce: CFG.nonce } ).then( function () {
			try {
				window.sessionStorage.removeItem( TOKEN_KEY );
			} catch ( e ) {} // eslint-disable-line no-empty
			pendingInspectName = null;
			importShow( 'idle' );
			refreshArchives();
		}, function ( e ) {
			showGlobalNotice( cancelErrorMessage( e ) );
		} );
	} );

	/**
	 * Resume an import after a page reload using the token kept in
	 * sessionStorage (internal/API.md: "Keep the token in memory and
	 * optionally sessionStorage so a reload can continue"). A single step
	 * call both authenticates the token and returns the current job, so
	 * this doubles as validation.
	 *
	 * @return {Promise<boolean>} true if a resume was attempted.
	 */
	function tryResumeImportFromToken() {
		var token;
		try {
			token = window.sessionStorage.getItem( TOKEN_KEY );
		} catch ( e ) {
			token = null;
		}
		if ( ! token ) {
			return Promise.resolve( false );
		}
		importCtx.token = token;
		return callAjax( 'fsc_import_step', { token: token, budget: 1, log_since: 0 } ).then(
			function ( data ) {
				importCtx.jobId = data.job_id;
				importCtx.logSince = data.log_seq;
				importOnJob( data );
				if ( 'done' === data.status ) {
					importOnDone( data );
				} else if ( 'error' === data.status ) {
					importOnServerError( data );
				} else {
					importStartLoop();
				}
				return true;
			},
			function () {
				try {
					window.sessionStorage.removeItem( TOKEN_KEY );
				} catch ( e ) {} // eslint-disable-line no-empty
				return false;
			}
		);
	}

	/* ==================================================================== */
	/* Archives list                                                        */
	/* ==================================================================== */

	var FORMAT_COLUMN_LABELS = { tar: 'format_label_tar', wpress: 'format_label_wpress', zip: 'format_label_duplicator', daf: 'format_label_duplicator' };

	function formatColumnLabel( format ) {
		var key = FORMAT_COLUMN_LABELS[ format ];
		return key ? t( key ) : format;
	}

	function buildArchiveRow( archive ) {
		var tr = document.createElement( 'tr' );
		tr.appendChild( ce( 'td', null, archive.name ) );

		// `bytes` (since 0.9.1) and `size` are the same value; prefer `bytes`
		// when present since it is documented as the exact-count field.
		var exactSize = undefined !== archive.bytes && null !== archive.bytes ? archive.bytes : archive.size;
		var sizeTd = ce( 'td', 'fsc-col-size', archive.size_human );
		var exactBytes = fmt( t( 'bytes_exact' ), formatIntCommas( exactSize ) );
		sizeTd.title = archive.sha256_available ? exactBytes + ' - ' + t( 'checksum_available' ) : exactBytes;
		sizeTd.appendChild( ce( 'span', 'fsc-col-size-exact', exactBytes ) );
		tr.appendChild( sizeTd );

		tr.appendChild( ce( 'td', null, archive.date ) );
		tr.appendChild( ce( 'td', null, formatColumnLabel( archive.format ) ) );

		var actions = document.createElement( 'td' );
		actions.className = 'fsc-archives-actions';

		var dl = document.createElement( 'a' );
		dl.className = 'button button-small';
		dl.href = archive.download;
		dl.textContent = t( 'download' );
		actions.appendChild( dl );

		var useBtn = ce( 'button', 'button button-small' );
		useBtn.type = 'button';
		useBtn.textContent = t( 'use_for_import' );
		useBtn.addEventListener( 'click', function () {
			clearImportNotices();
			importEls.checkingText.textContent = t( 'checking_archive' );
			importShow( 'checking' );
			startInspect( archive.name ).then( function () {
				var card = qs( 'fsc-import-card' );
				if ( card && card.scrollIntoView ) {
					card.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			} );
		} );
		actions.appendChild( useBtn );

		var delBtn = ce( 'button', 'button button-small' );
		delBtn.type = 'button';
		delBtn.textContent = t( 'delete' );
		delBtn.addEventListener( 'click', function () {
			if ( ! window.confirm( fmt( t( 'confirm_delete' ), archive.name ) ) ) {
				return;
			}
			callAjax( 'fsc_archive_delete', { nonce: CFG.nonce, name: archive.name } ).then(
				function () {
					refreshArchives();
				},
				function ( e ) {
					var notice = qs( 'fsc-archives-notice' );
					var msg = qs( 'fsc-archives-notice-msg' );
					notice.classList.remove( 'notice-success' );
					notice.classList.add( 'notice-error' );
					msg.textContent = fmt( t( 'err_prefix' ), e.message );
					notice.hidden = false;
				}
			);
		} );
		actions.appendChild( delBtn );

		tr.appendChild( actions );
		return tr;
	}

	function refreshArchives() {
		return callAjax( 'fsc_archives', { nonce: CFG.nonce } ).then( function ( data ) {
			var archives = data.archives || [];

			importEls.storagePath.textContent = data.storage_dir || '';

			var tbody = qs( 'fsc-archives-tbody' );
			while ( tbody.firstChild ) {
				tbody.removeChild( tbody.firstChild );
			}
			archives.forEach( function ( a ) {
				tbody.appendChild( buildArchiveRow( a ) );
			} );
			qs( 'fsc-archives-empty' ).hidden = archives.length > 0;

			var ftpHintEl = qs( 'fsc-archives-ftp-hint' );
			ftpHintEl.textContent = data.storage_dir ? fmt( t( 'ftp_hint' ), data.storage_dir ) : '';

			var freeEl = qs( 'fsc-archives-freespace' );
			if ( null !== data.free_space && undefined !== data.free_space ) {
				freeEl.textContent = fmt( t( 'free_space' ), formatBytes( data.free_space ) );
			} else {
				freeEl.textContent = '';
			}
			renderStorageInfo( data.storage, data.retention );
			renderRetentionNotice( data.retention );
			return data;
		}, function () {} );
	}

	function fillList( listEl, items ) {
		while ( listEl.firstChild ) {
			listEl.removeChild( listEl.firstChild );
		}
		( items || [] ).forEach( function ( text ) {
			listEl.appendChild( ce( 'li', null, text ) );
		} );
	}

	/**
	 * Storage location, permissions, retention setting and any storage
	 * warnings (fsc_archives "storage" and "retention", since 0.9.2).
	 * Absent on an older backend: the block then stays hidden.
	 */
	function renderStorageInfo( storage, retention ) {
		var box = qs( 'fsc-storage-info' );
		if ( ! box || ! storage ) {
			return;
		}
		box.hidden = false;
		var errBox = qs( 'fsc-storage-error' );
		if ( storage.error ) {
			qs( 'fsc-storage-error-msg' ).textContent = storage.error;
			errBox.hidden = false;
		} else {
			errBox.hidden = true;
		}
		qs( 'fsc-storage-location' ).textContent = storage.dir || storage.base || '';
		qs( 'fsc-storage-source' ).textContent = storage.custom ? t( 'storage_custom' ) : t( 'storage_default' );
		qs( 'fsc-storage-modes' ).textContent = fmt( t( 'storage_modes' ), storage.dir_mode, storage.file_mode ) +
			( storage.wp_modes ? ' ' + t( 'storage_modes_wp' ) : '' );
		var retEl = qs( 'fsc-storage-retention' );
		if ( retention ) {
			var days = parseInt( retention.days, 10 ) || 0;
			retEl.textContent = ( days > 0 ? fmt( t( 'retention_on' ), days ) : t( 'retention_off' ) ) + ' ' + t( 'retention_stale' );
		} else {
			retEl.textContent = '';
		}
		var warnings = storage.warnings || [];
		fillList( qs( 'fsc-storage-warnings-list' ), warnings );
		qs( 'fsc-storage-warnings' ).hidden = 0 === warnings.length;
		fillList( qs( 'fsc-storage-notes' ), storage.notes || [] );
	}

	function renderRetentionNotice( retention ) {
		var box = qs( 'fsc-retention-notice' );
		if ( ! box ) {
			return;
		}
		var count = retention ? parseInt( retention.old_count, 10 ) || 0 : 0;
		if ( count < 1 ) {
			box.hidden = true;
			return;
		}
		qs( 'fsc-retention-notice-msg' ).textContent = fmt( t( 'retention_notice' ), count, retention.notice_days, formatBytes( retention.old_bytes ) );
		box.hidden = false;
	}

	qs( 'fsc-delete-all' ) && qs( 'fsc-delete-all' ).addEventListener( 'click', function () {
		if ( ! window.confirm( t( 'confirm_delete_all' ) ) ) {
			return;
		}
		var btn = qs( 'fsc-delete-all' );
		btn.disabled = true;
		callAjax( 'fsc_archives_delete_all', { nonce: CFG.nonce, confirm: '1' } ).then(
			function ( data ) {
				btn.disabled = false;
				qs( 'fsc-retention-notice' ).hidden = true;
				var notice = qs( 'fsc-archives-notice' );
				notice.classList.remove( 'notice-error' );
				notice.classList.add( 'notice-success' );
				qs( 'fsc-archives-notice-msg' ).textContent = fmt( t( 'deleted_all' ), data.deleted );
				notice.hidden = false;
				refreshArchives();
			},
			function ( e ) {
				btn.disabled = false;
				var notice = qs( 'fsc-archives-notice' );
				notice.classList.remove( 'notice-success' );
				notice.classList.add( 'notice-error' );
				qs( 'fsc-archives-notice-msg' ).textContent = fmt( t( 'err_prefix' ), e.message );
				notice.hidden = false;
			}
		);
	} );

	/* ==================================================================== */
	/* Page-load status: reattach to a running export, or report an orphan  */
	/* import that cannot be resumed without its token.                     */
	/* ==================================================================== */

	function checkStatus() {
		return callAjax( 'fsc_status', { nonce: CFG.nonce } ).then( function ( data ) {
			var job = data.job;
			if ( ! job ) {
				return;
			}
			if ( 'export' === job.type ) {
				if ( 'running' === job.status ) {
					exportResumeExisting( job );
				} else if ( 'error' === job.status ) {
					exportCtx.jobId = job.job_id;
					exportOnServerError( job );
				}
				return;
			}
			if ( 'import' === job.type ) {
				if ( 'running' === job.status ) {
					importEls.orphanMsg.textContent = job.swapped ? t( 'import_orphan_swapped' ) : t( 'import_orphan_running' );
					importEls.orphanCancelBtn.hidden = !! job.swapped;
					importShow( 'orphan' );
				} else if ( 'error' === job.status ) {
					importCtx.jobId = job.job_id;
					importOnServerError( job );
				}
			}
		}, function () {} );
	}

	importEls.orphanCancelBtn.addEventListener( 'click', function () {
		if ( ! window.confirm( t( 'confirm_cancel' ) ) ) {
			return;
		}
		callAjax( 'fsc_cancel', { nonce: CFG.nonce } ).then( function () {
			importShow( 'idle' );
			refreshArchives();
		}, function ( e ) {
			showGlobalNotice( cancelErrorMessage( e ) );
		} );
	} );

	/* ==================================================================== */
	/* Init                                                                */
	/* ==================================================================== */

	function init() {
		loadI18n();
		if ( qs( 'fsc-main' ) && qs( 'fsc-main' ).hidden ) {
			return; // Multisite: page shows only the notice.
		}
		refreshArchives();
		tryResumeImportFromToken().then( function ( resumed ) {
			if ( ! resumed ) {
				checkStatus();
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
