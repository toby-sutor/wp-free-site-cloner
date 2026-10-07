#!/usr/bin/env node
/**
 * Dedicated screenshot pass for the v0.9.1 UI work, at both 1280 and 400
 * px wide: pre-upload incomplete error, upload-complete/checking, the
 * inspect inline error, the import step list mid-run, the retry state,
 * the stall warning, and the done screen. Not a pass/fail check (ui-
 * errors.js already asserts behavior) - this only exists to produce and
 * eyeball the screenshots the brief asked for.
 *
 * Run: flatpak-spawn --host node tests/e2e/ui-screens-v4.js
 * Requires: tests/e2e up (up.sh), playwright-core in tests/e2e/node_modules,
 * host chromium at /usr/bin/chromium, and the same fixtures as ui-errors.js.
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
const TRUNCATED_TAR_V091 = path.join( TRUNCATED_DIR, 'truncated-v091.tar' );
const VALID_OLD_ARCHIVE = path.join( DATA_DIR, 'v091/old090.tar' );

const WIDTHS = [ 1280, 400 ];

async function shotBoth( ctxFactory, browser, name, fn ) {
	for ( var i = 0; i < WIDTHS.length; i++ ) {
		var width = WIDTHS[ i ];
		var ctx = await browser.newContext( { viewport: { width: width, height: width <= 480 ? 900 : 1000 }, acceptDownloads: true } );
		var page = await ctx.newPage();
		lib.attachErrorLog( page, name + '-' + width );
		try {
			await fn( page, width );
			var dir = path.join( SCREEN_DIR, String( width ) );
			fs.mkdirSync( dir, { recursive: true } );
			var file = path.join( dir, name + '.png' );
			await page.screenshot( { path: file, fullPage: true } );
			console.log( '[shot] ' + file );
		} catch ( e ) {
			console.error( '[skip] ' + name + ' @ ' + width + 'px: ' + ( e && e.message || e ) );
		} finally {
			await ctx.close();
		}
	}
}

async function main() {
	var browser = await chromium.launch( {
		executablePath: '/usr/bin/chromium',
		headless: true,
		args: [ '--no-sandbox', '--disable-gpu' ],
	} );

	try {
		/* 1. Pre-upload incomplete error */
		await shotBoth( null, browser, '01-pre-upload-incomplete-error', async function ( page ) {
			await lib.loginAny( page, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );
			await lib.gotoCloner( page, DEST_URL );
			await page.setInputFiles( '#fsc-file-input', TRUNCATED_TAR_V091 );
			await page.waitForSelector( '#fsc-precheck-error:not([hidden])', { timeout: 15000 } );
		} );

		/* 2. Upload complete / checking the archive */
		await shotBoth( null, browser, '02-upload-complete-checking', async function ( page ) {
			await lib.loginAny( page, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );
			await lib.gotoCloner( page, DEST_URL );
			// Slow the inspect call down so the "checking" state is on screen
			// long enough to reliably capture at both widths.
			await page.route( '**/admin-ajax.php', async function ( route ) {
				var pd = route.request().postData() || '';
				if ( -1 !== pd.indexOf( 'fsc_import_inspect' ) ) {
					await new Promise( function ( r ) {
						setTimeout( r, 3000 );
					} );
				}
				return route.continue();
			} );
			await page.setInputFiles( '#fsc-file-input', VALID_OLD_ARCHIVE );
			await page.waitForSelector( '#fsc-upload-inspecting-wrap:not([hidden])', { timeout: 120000 } );
			await page.waitForTimeout( 500 ); // let the elapsed-seconds counter render at least once
		} );

		/* 3. Inspect inline error */
		await shotBoth( null, browser, '03-inspect-inline-error', async function ( page ) {
			await lib.loginAny( page, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );
			await lib.gotoCloner( page, DEST_URL );
			await page.route( '**/admin-ajax.php', async function ( route ) {
				var pd = route.request().postData() || '';
				if ( -1 !== pd.indexOf( 'fsc_import_inspect' ) ) {
					return route.fulfill( {
						status: 400,
						contentType: 'application/json',
						body: JSON.stringify( { success: false, data: { code: 'error', message: 'This archive could not be read (screenshot demo).' } } ),
					} );
				}
				return route.continue();
			} );
			var fakePath = '/tmp/fsc-screens-fake.tar';
			// Build inline (avoid a require cycle concern): reuse the shared helper.
			fs.writeFileSync( fakePath, lib.buildFakeNativeTar() );
			// This tiny fake fails the server's own check.ok first (see
			// ui-errors.js d1/d2 for why); use the real archive instead so the
			// intercepted fsc_import_inspect path is the one shown here.
			await page.setInputFiles( '#fsc-file-input', VALID_OLD_ARCHIVE );
			await page.waitForSelector( '#fsc-inspect-error:not([hidden])', { timeout: 120000 } );
		} );

		/* 4. Import step list mid-run (real import, native archive from source) */
		await runRealImportForScreens( browser );

		/* 5. Retry state */
		await shotBoth( null, browser, '05-retry-state', async function ( page ) {
			await lib.login( page, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
			await lib.gotoCloner( page, SOURCE_URL );
			var attempts = 0;
			await page.route( '**/admin-ajax.php', async function ( route ) {
				var pd = route.request().postData() || '';
				if ( 'POST' === route.request().method() && -1 !== pd.indexOf( 'fsc_export_step' ) ) {
					attempts++;
					if ( attempts <= 2 ) {
						return route.fulfill( { status: 503 } );
					}
				}
				return route.continue();
			} );
			page.once( 'dialog', function ( d ) {
				d.accept();
			} );
			await page.click( '#fsc-export-start' );
			await page.waitForFunction( function () {
				var el = document.getElementById( 'fsc-export-status' );
				return el && /attempt/i.test( el.textContent );
			}, null, { timeout: 20000 } );
		} );

		/* 6. Stall warning */
		await shotBoth( null, browser, '06-stall-warning', async function ( page ) {
			await lib.login( page, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
			await lib.gotoCloner( page, SOURCE_URL );
			// This seed site finishes a real export inside a single
			// fsc_export_step call, so there is no genuine second "still
			// running" poll to doctor. Fully fake the first step response
			// instead (route.fulfill() without route.fetch() first means the
			// real request never reaches the server, so its actual job state
			// is unaffected) with status "running" and a stale
			// last_progress_at - see ui-errors.js testStallWarning for the
			// same approach with more detail.
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
						log: [ { seq: 1, time: now, msg: 'Adding files (simulated for screenshot purposes).' } ],
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
		} );

		/* 7. Done screen (export - fast and simple; also exercises the warnings-list markup path) */
		await shotBoth( null, browser, '07-done-screen', async function ( page ) {
			await lib.login( page, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
			await lib.gotoCloner( page, SOURCE_URL );
			page.once( 'dialog', function ( d ) {
				d.accept();
			} );
			await page.click( '#fsc-export-start' );
			await page.waitForSelector( '#fsc-export-done:not([hidden])', { timeout: 120000 } );
		} );
	} finally {
		await browser.close();
	}
	console.log( '\nui-screens-v4.js done.' );
}

/** Drives a real import far enough to show the step list mid-run, for shot 04. */
async function runRealImportForScreens( browser ) {
	for ( var i = 0; i < WIDTHS.length; i++ ) {
		var width = WIDTHS[ i ];
		var ctx = await browser.newContext( { viewport: { width: width, height: width <= 480 ? 900 : 1000 }, acceptDownloads: true } );
		var page = await ctx.newPage();
		lib.attachErrorLog( page, 'steps-mid-run-' + width );
		try {
			await lib.loginAny( page, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );
			await lib.gotoCloner( page, DEST_URL );
			await page.setInputFiles( '#fsc-file-input', VALID_OLD_ARCHIVE );
			await page.waitForSelector( '#fsc-import-inspect:not([hidden])', { timeout: 120000 } );
			await page.check( '#fsc-confirm-checkbox' );
			await page.click( '#fsc-import-start' );
			await page.waitForSelector( '#fsc-import-running:not([hidden])', { timeout: 20000 } );
			// Let a couple of steps advance so the list shows a mix of
			// done/active/pending, not just the very first frame.
			await page.waitForFunction( function () {
				var list = document.getElementById( 'fsc-import-steps' );
				if ( ! list ) {
					return false;
				}
				var doneCount = 0;
				for ( var j = 0; j < list.children.length; j++ ) {
					if ( -1 !== list.children[ j ].className.indexOf( 'fsc-step-done' ) ) {
						doneCount++;
					}
				}
				return doneCount >= 1;
			}, null, { timeout: 60000 } ).catch( function () {} );
			var dir = path.join( SCREEN_DIR, String( width ) );
			fs.mkdirSync( dir, { recursive: true } );
			var file = path.join( dir, '04-import-step-list-mid-run.png' );
			await page.screenshot( { path: file, fullPage: true } );
			console.log( '[shot] ' + file );
		} catch ( e ) {
			console.error( '[skip] 04-import-step-list-mid-run @ ' + width + 'px: ' + ( e && e.message || e ) );
		} finally {
			await ctx.close();
		}
	}
}

main().catch( function ( e ) {
	console.error( 'ui-screens-v4.js crashed: ' + ( e && e.stack || e ) );
	process.exitCode = 1;
} );
