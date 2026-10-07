#!/usr/bin/env node
/**
 * UI error-path checks for v0.9.1 (Playwright, host chromium, headless).
 * Covers the operator's "stuck at 100%" bug report and the fixes for it:
 * a local pre-upload check that fails fast for a truncated archive, a
 * visible "checking the archive" state after the last chunk, inline
 * inspect errors (never a page-top-only notice), message clearing on a
 * new action, and visible retry/heartbeat text in the step loop.
 *
 * Run: flatpak-spawn --host node tests/e2e/ui-errors.js
 * Requires: tests/e2e up (up.sh), playwright-core in tests/e2e/node_modules,
 * host chromium at /usr/bin/chromium, and the truncated fixtures below
 * (generated once into $FSC_E2E_DATA, never the repo).
 */

const path = require( 'path' );
const fs = require( 'fs' );
// Test data (samples, downloads, screenshots) lives outside the repo, because
// the repo is bind-mounted as the plugin and would otherwise be exported.
const DATA_DIR = process.env.FSC_E2E_DATA || path.join( require( 'os' ).homedir(), '.cache', 'wp-free-site-cloner-e2e' );
const { chromium } = require( 'playwright-core' );
const lib = require( './ui-lib.js' );

const SOURCE_URL = 'http://127.0.0.1:8081';
const DEST_URL = 'http://127.0.0.1:8082';
const SOURCE_USER = 'admin';
const SOURCE_PASS = 'admin-pass-123';
const DEST_USER = 'destadmin';
const DEST_PASS = 'dest-admin-456';

const SCREEN_DIR = path.join( DATA_DIR, 'screens/v4' );
const TRUNCATED_DIR = path.join( DATA_DIR, 'ui-downloads/truncated' );
const WORK_DIR = path.join( DATA_DIR, 'ui-downloads/truncated' );

const TRUNCATED_TAR = path.join( TRUNCATED_DIR, 'truncated-native.tar' );
const TRUNCATED_WPRESS = path.join( TRUNCATED_DIR, 'truncated-sample.wpress' );
// A truncated copy of a genuine 0.9.1 archive (has a manifest archive_size
// field), so this specifically exercises the precise "X of Y bytes (Z%)"
// message path, not just the generic pre-0.9.1 end-blocks-only path that
// TRUNCATED_TAR above happens to hit.
const TRUNCATED_TAR_V091 = path.join( TRUNCATED_DIR, 'truncated-v091.tar' );
// A genuine, complete, valid 0.9.0 archive (no archive_size key) - real
// enough to pass both the local pre-upload check and the server's
// final-chunk completeness check, so it reaches fsc_import_inspect for
// real and lets (d2) test a route()-intercepted inspect failure.
const VALID_OLD_ARCHIVE = path.join( DATA_DIR, 'v091/old090.tar' );

const shot = lib.makeShotter( SCREEN_DIR, 'errors-' );

var failures = [];

function assertTrue( cond, msg ) {
	if ( ! cond ) {
		failures.push( msg );
		console.error( '[FAIL] ' + msg );
	} else {
		console.log( '[ok] ' + msg );
	}
}

/** (a) A truncated native .tar is rejected BEFORE upload; no fsc_upload_chunk request is ever sent. */
async function testTruncatedTar( page ) {
	console.log( '\n=== (a) truncated native .tar rejected before upload ===' );
	await lib.gotoCloner( page, DEST_URL );

	var sawUploadChunk = false;
	function onRequest( req ) {
		if ( 'POST' === req.method() && /admin-ajax\.php/.test( req.url() ) ) {
			var pd = req.postData() || '';
			if ( -1 !== pd.indexOf( 'fsc_upload_chunk' ) ) {
				sawUploadChunk = true;
			}
		}
	}
	page.on( 'request', onRequest );

	await page.setInputFiles( '#fsc-file-input', TRUNCATED_TAR );
	await page.waitForSelector( '#fsc-precheck-error:not([hidden])', { timeout: 15000 } );
	var msg = await page.textContent( '#fsc-precheck-error-msg' );
	var timeText = await page.textContent( '#fsc-precheck-error-time' );
	await shot( page, 'a-precheck-tar-incomplete' );
	page.off( 'request', onRequest );

	console.log( '[a] message: "' + msg.trim() + '"' );
	assertTrue( /incomplete/i.test( msg ), '(a) precheck message mentions "incomplete": got "' + msg + '"' );
	assertTrue( ! sawUploadChunk, '(a) no fsc_upload_chunk request was sent' );
	assertTrue( !! timeText && timeText.trim().length > 0, '(a) error box shows a timestamp' );

	return true;
}

/**
 * (a, precise variant) A truncated copy of a genuine 0.9.1 archive (which
 * has a manifest archive_size field) must hit the precise "X of Y bytes
 * (Z%)" message, not just the generic end-blocks-only message - proves the
 * archive_size-aware branch of the local check, not only its fallback.
 */
async function testTruncatedTarV091Precise( page ) {
	console.log( '\n=== (a, precise) truncated 0.9.1 archive shows the exact X of Y (Z%) message ===' );
	await lib.gotoCloner( page, DEST_URL );

	var sawUploadChunk = false;
	function onRequest( req ) {
		if ( 'POST' === req.method() && /admin-ajax\.php/.test( req.url() ) ) {
			var pd = req.postData() || '';
			if ( -1 !== pd.indexOf( 'fsc_upload_chunk' ) ) {
				sawUploadChunk = true;
			}
		}
	}
	page.on( 'request', onRequest );

	await page.setInputFiles( '#fsc-file-input', TRUNCATED_TAR_V091 );
	await page.waitForSelector( '#fsc-precheck-error:not([hidden])', { timeout: 15000 } );
	var msg = await page.textContent( '#fsc-precheck-error-msg' );
	await shot( page, 'a2-precheck-tar-precise-pct' );
	page.off( 'request', onRequest );

	console.log( '[a-precise] message: "' + msg.trim() + '"' );
	assertTrue( /113,063,116/.test( msg ) && /188,438,528/.test( msg ) && /60%/.test( msg ),
		'(a-precise) message names the exact byte counts and percent: got "' + msg + '"' );
	assertTrue( ! sawUploadChunk, '(a-precise) no fsc_upload_chunk request was sent' );
}

/** (b) A truncated .wpress (from the real AIOWPM sample) is rejected early too. */
async function testTruncatedWpress( page ) {
	console.log( '\n=== (b) truncated .wpress rejected before upload ===' );
	await lib.gotoCloner( page, DEST_URL );

	var sawUploadChunk = false;
	function onRequest( req ) {
		if ( 'POST' === req.method() && /admin-ajax\.php/.test( req.url() ) ) {
			var pd = req.postData() || '';
			if ( -1 !== pd.indexOf( 'fsc_upload_chunk' ) ) {
				sawUploadChunk = true;
			}
		}
	}
	page.on( 'request', onRequest );

	await page.setInputFiles( '#fsc-file-input', TRUNCATED_WPRESS );
	await page.waitForSelector( '#fsc-precheck-error:not([hidden])', { timeout: 15000 } );
	var msg = await page.textContent( '#fsc-precheck-error-msg' );
	await shot( page, 'b-precheck-wpress-incomplete' );
	page.off( 'request', onRequest );

	console.log( '[b] message: "' + msg.trim() + '"' );
	assertTrue( /incomplete/i.test( msg ), '(b) precheck message mentions "incomplete": got "' + msg + '"' );
	assertTrue( ! sawUploadChunk, '(b) no fsc_upload_chunk request was sent' );
}

/** (c) Right after (a)'s error, choosing a valid file clears the precheck-error box. Runs on the SAME page/state as (a), deliberately not reloaded. */
async function testClearingAfterError( page ) {
	console.log( '\n=== (c) choosing a new file clears the old error ===' );
	var before = await page.getAttribute( '#fsc-precheck-error', 'hidden' );
	assertTrue( null === before, '(c) precondition: precheck-error is still visible from (a)' );

	var fakePath = path.join( WORK_DIR, 'fake-native-fast-c.tar' );
	fs.writeFileSync( fakePath, lib.buildFakeNativeTar() );
	await page.setInputFiles( '#fsc-file-input', fakePath );

	await page.waitForFunction( function () {
		var el = document.getElementById( 'fsc-precheck-error' );
		return el && el.hidden;
	}, null, { timeout: 15000 } );
	await shot( page, 'c-error-cleared-after-new-file' );
	assertTrue( true, '(c) precheck-error box was cleared after picking a new file' );

	// Let this settle, then reload for full isolation before (d).
	await lib.gotoCloner( page, DEST_URL );
}

/**
 * (d1) A file that passes the *local* check but that the server rejects:
 * the fake tar here has no archive_size key and valid (zero) end blocks,
 * so it clears the browser-side check, but it is not a real WP Free Site
 * Cloner archive, so the server's own final-chunk `check` (internal/
 * API.md: "manifest archive_size vs file size, end blocks... ok:false
 * means the archive is truncated or not a WP Free Site Cloner archive")
 * rejects it right after the last chunk - this must render inline at
 * once, never leave the upload panel stuck at 100% with only Cancel.
 */
async function testUploadCheckRejection( page ) {
	console.log( '\n=== (d1) server-side check.ok:false renders inline, no stuck 100% ===' );
	await lib.gotoCloner( page, DEST_URL );

	var fakePath = path.join( WORK_DIR, 'fake-native-fast-d1.tar' );
	fs.writeFileSync( fakePath, lib.buildFakeNativeTar() );

	await page.setInputFiles( '#fsc-file-input', fakePath );

	// Upload is tiny (a couple KB): should reach an inline error fast,
	// never sit at 100% with only Cancel.
	await page.waitForSelector( '#fsc-inspect-error:not([hidden])', { timeout: 20000 } );
	var msg = await page.textContent( '#fsc-inspect-error-msg' );
	var timeText = await page.textContent( '#fsc-inspect-error-time' );
	await shot( page, 'd1-upload-check-inline-error' );

	var hasChooseAnother = await page.locator( '#fsc-inspect-choose-another' ).isVisible();
	var hasDelete = await page.locator( '#fsc-inspect-delete-upload' ).isVisible();
	var uploadingVisible = await page.locator( '#fsc-import-uploading:not([hidden])' ).count();

	console.log( '[d1] inline message: "' + msg.trim() + '"' );
	assertTrue( !! msg && msg.trim().length > 0, '(d1) the server\'s check.ok:false message is shown inline' );
	assertTrue( hasChooseAnother, '(d1) "Choose another file" button present' );
	assertTrue( hasDelete, '(d1) "Delete this upload" button present' );
	assertTrue( 0 === uploadingVisible, '(d1) uploading panel (Cancel-only state) is hidden once the error shows' );
	assertTrue( !! timeText && timeText.trim().length > 0, '(d1) inspect-error box shows a timestamp' );

	// Exercise "Delete this upload" too, since it depends on the name from the upload response.
	page.once( 'dialog', function ( d ) {
		d.accept();
	} );
	await page.click( '#fsc-inspect-delete-upload' );
	await page.waitForFunction( function () {
		var el = document.getElementById( 'fsc-import-idle' );
		return el && ! el.hidden;
	}, null, { timeout: 15000 } );
	assertTrue( true, '(d1) "Delete this upload" returns to idle (archive-delete action wired up)' );
}

/**
 * (d2) A real, complete, valid archive (old090.tar - a genuine 0.9.0
 * export) passes both the local check and the server's final-chunk
 * check.ok, so it genuinely reaches fsc_import_inspect - which is then
 * intercepted with Playwright route() to simulate a server-side rejection
 * at that later stage. Covers the other half of the operator's bug report
 * (startInspect() itself failing), distinctly from (d1) above.
 */
async function testInspectRouteError( page ) {
	console.log( '\n=== (d2) fsc_import_inspect failure (route()) renders inline, no stuck 100% ===' );
	await lib.gotoCloner( page, DEST_URL );

	var SIMULATED_MSG = 'Simulated inspect failure for ui-errors.js test (d2).';
	await page.route( '**/admin-ajax.php', async function ( route ) {
		var pd = route.request().postData() || '';
		if ( -1 !== pd.indexOf( 'fsc_import_inspect' ) ) {
			return route.fulfill( {
				status: 400,
				contentType: 'application/json',
				body: JSON.stringify( { success: false, data: { code: 'error', message: SIMULATED_MSG } } ),
			} );
		}
		return route.continue();
	} );

	await page.setInputFiles( '#fsc-file-input', VALID_OLD_ARCHIVE );

	// Real ~70 MB upload: must show the post-upload "checking the archive"
	// state (never a bare 100%-with-only-Cancel state) once the last chunk
	// lands, well before the (intercepted) inspect result comes back.
	await page.waitForSelector( '#fsc-upload-inspecting-wrap:not([hidden]), #fsc-inspect-error:not([hidden])', { timeout: 120000 } );
	await shot( page, 'd2-upload-complete-checking' );

	await page.waitForSelector( '#fsc-inspect-error:not([hidden])', { timeout: 20000 } );
	var msg = await page.textContent( '#fsc-inspect-error-msg' );
	var timeText = await page.textContent( '#fsc-inspect-error-time' );
	await shot( page, 'd2-inspect-inline-error' );

	var hasChooseAnother = await page.locator( '#fsc-inspect-choose-another' ).isVisible();
	var hasDelete = await page.locator( '#fsc-inspect-delete-upload' ).isVisible();
	var uploadingVisible = await page.locator( '#fsc-import-uploading:not([hidden])' ).count();

	await page.unroute( '**/admin-ajax.php' );

	console.log( '[d2] inline message: "' + msg.trim() + '"' );
	assertTrue( -1 !== msg.indexOf( SIMULATED_MSG ), '(d2) inspect error message shown inline verbatim' );
	assertTrue( hasChooseAnother, '(d2) "Choose another file" button present' );
	assertTrue( hasDelete, '(d2) "Delete this upload" button present' );
	assertTrue( 0 === uploadingVisible, '(d2) uploading panel (Cancel-only state) is hidden once the error shows' );
	assertTrue( !! timeText && timeText.trim().length > 0, '(d2) inspect-error box shows a timestamp' );
}

/** (e) Heartbeat + visible retries: force two 503s on fsc_export_step, then let it proceed. */
async function testHeartbeatAndRetry( browser ) {
	console.log( '\n=== (e) heartbeat + visible retries, progress continues after ===' );
	var ctx = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
	var page = await ctx.newPage();
	lib.attachErrorLog( page, 'retry' );
	await lib.login( page, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
	await lib.gotoCloner( page, SOURCE_URL );

	var stepAttempts = 0;
	await page.route( '**/admin-ajax.php', async function ( route ) {
		var req = route.request();
		var pd = req.postData() || '';
		if ( 'POST' === req.method() && -1 !== pd.indexOf( 'fsc_export_step' ) ) {
			stepAttempts++;
			if ( stepAttempts <= 2 ) {
				return route.fulfill( { status: 503 } );
			}
		}
		return route.continue();
	} );

	page.once( 'dialog', function ( d ) {
		d.accept();
	} ); // in case an old job from a prior run needs "replace?"
	await page.click( '#fsc-export-start' );

	// The heartbeat comes from the fsc_export_start response itself (not
	// intercepted), so it should appear right away.
	await page.waitForFunction( function () {
		var el = document.getElementById( 'fsc-export-heartbeat' );
		return el && /Last response from server/.test( el.textContent );
	}, null, { timeout: 20000 } );
	var heartbeatText = await page.textContent( '#fsc-export-heartbeat' );
	await shot( page, 'e-heartbeat' );

	await page.waitForFunction( function () {
		var el = document.getElementById( 'fsc-export-status' );
		return el && /attempt/i.test( el.textContent );
	}, null, { timeout: 20000 } );
	var retryText = await page.textContent( '#fsc-export-status' );
	await shot( page, 'e-retry-text' );

	console.log( '[e] heartbeat="' + heartbeatText.trim() + '", retry="' + retryText.trim() + '"' );
	assertTrue( /Last response from server/.test( heartbeatText ), '(e) heartbeat text present' );
	assertTrue( /attempt/i.test( retryText ) && /\d/.test( retryText ), '(e) retry text names an attempt number' );

	await page.unroute( '**/admin-ajax.php' );

	await page.waitForSelector( '#fsc-export-done:not([hidden])', { timeout: 120000 } );
	await shot( page, 'e-export-done-after-retry' );
	assertTrue( true, '(e) export reached done after the forced retries: progress continued' );

	await ctx.close();
}

/**
 * Bonus (not in the required a-e list, but cheap and directly requested
 * by the screenshot list): the stall warning. Lets one real step response
 * through, then patches last_progress_at 95s into the past relative to
 * server_time before the UI sees it - the export itself still completes
 * normally, only the *displayed* staleness is simulated (waiting a real
 * 90s would make this test needlessly slow).
 */
async function testStallWarning( browser ) {
	console.log( '\n=== (bonus) stall warning (synthetic running response) ===' );
	var ctx = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
	var page = await ctx.newPage();
	lib.attachErrorLog( page, 'stall' );
	await lib.login( page, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
	await lib.gotoCloner( page, SOURCE_URL );

	// This seed site is small enough that a real export finishes inside a
	// single fsc_export_step call (verified directly against the live
	// backend), so there is never a second "still running" poll to
	// doctor - nothing for the ticker to observe as stale before the job
	// already moved to "done". Instead, fully fake the first step response
	// (never sent to the real server - route.fulfill() without route.fetch()
	// intercepts before any request goes out) with status "running" and a
	// last_progress_at 95s stale; the job_id comes from the real
	// fsc_export_start response so it stays consistent with the server's
	// actual (unaffected) job once routing reverts to real responses.
	var jobId = null;
	var stepPatched = false;
	await page.route( '**/admin-ajax.php', async function ( route ) {
		var pd = route.request().postData() || '';
		if ( -1 !== pd.indexOf( 'fsc_export_start' ) ) {
			var response = await route.fetch();
			var json = await response.json();
			if ( json && json.data ) {
				jobId = json.data.job_id;
			}
			return route.fulfill( { response: response, json: json } );
		}
		if ( ! stepPatched && -1 !== pd.indexOf( 'fsc_export_step' ) ) {
			stepPatched = true;
			var now = Math.floor( Date.now() / 1000 );
			var fake = {
				job_id: jobId, type: 'export', status: 'running', phase: 'files', phase_label: 'Adding files',
				progress: 50, done: false, error: null, archive: null, result: null,
				log: [ { seq: 1, time: now, msg: 'Adding files (simulated for screenshot/test purposes).' } ],
				log_seq: 1, updated: now,
				steps: [
					{ key: 'database', label: 'Export database', state: 'done' },
					{ key: 'files', label: 'Add files', state: 'active' },
					{ key: 'checksum', label: 'Compute checksums', state: 'pending' },
					{ key: 'finish', label: 'Finish archive', state: 'pending' },
				],
				step_detail: 'Adding files: 1,203 of 4,410 (380 MB of 1.2 GB)',
				step_progress: { done: 1203, total: 4410, unit: 'files' },
				last_progress_at: now - 95,
				server_time: now,
				budget: 5,
			};
			return route.fulfill( { status: 200, contentType: 'application/json', body: JSON.stringify( { success: true, data: fake } ) } );
		}
		return route.continue();
	} );

	page.once( 'dialog', function ( d ) {
		d.accept();
	} );
	await page.click( '#fsc-export-start' );

	await page.waitForSelector( '#fsc-export-stall:not([hidden])', { timeout: 20000 } );
	var stallMsg = await page.textContent( '#fsc-export-stall-msg' );
	await shot( page, 'f-stall-warning' );
	console.log( '[stall] ' + stallMsg.trim() );
	assertTrue( /No progress/i.test( stallMsg ), '(bonus) stall warning text shown' );

	await page.unroute( '**/admin-ajax.php' );
	// The server's real job (unaffected by the faked response above) is
	// still just past its first checkpoint; the next real poll finishes it.
	await page.waitForSelector( '#fsc-export-done:not([hidden])', { timeout: 60000 } ).catch( function () {} );
	await ctx.close();
}

async function main() {
	[ TRUNCATED_TAR, TRUNCATED_WPRESS, TRUNCATED_TAR_V091, VALID_OLD_ARCHIVE ].forEach( function ( p ) {
		if ( ! fs.existsSync( p ) ) {
			throw new Error( 'Missing test fixture: ' + p );
		}
	} );

	var browser = await chromium.launch( {
		executablePath: '/usr/bin/chromium',
		headless: true,
		args: [ '--no-sandbox', '--disable-gpu' ],
	} );

	try {
		var destCtx = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
		var dest = await destCtx.newPage();
		lib.attachErrorLog( dest, 'dest' );
		await lib.loginAny( dest, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );

		await testTruncatedTar( dest );
		await testClearingAfterError( dest ); // continues directly from (a)'s state
		await testTruncatedTarV091Precise( dest );
		await testTruncatedWpress( dest );
		await testUploadCheckRejection( dest ); // (d1)
		await testInspectRouteError( dest ); // (d2)
		await destCtx.close();

		await testHeartbeatAndRetry( browser );
		await testStallWarning( browser );
	} finally {
		await browser.close();
	}

	console.log( '\n' + ( failures.length ? 'UI-ERRORS FAILURES:' : 'UI-ERRORS OK' ) );
	failures.forEach( function ( f ) {
		console.error( ' - ' + f );
	} );
	process.exitCode = failures.length ? 1 : 0;
}

main().catch( function ( e ) {
	console.error( 'ui-errors.js crashed: ' + ( e && e.stack || e ) );
	process.exitCode = 1;
} );
