#!/usr/bin/env node
/**
 * Drives the real WP Free Site Cloner admin UI with Playwright (host
 * chromium, headless): export on source, download the archive through the
 * UI, upload it on dest via the chunked-upload UI, inspect, confirm,
 * import to completion, then log in to dest with the SOURCE admin
 * credentials (proves the import actually replaced dest's DB).
 *
 * Run: flatpak-spawn --host node tests/e2e/ui-flow.js
 * Requires: tests/e2e up (up.sh), playwright-core installed in
 * tests/e2e/node_modules (flatpak-spawn --host npm i playwright-core),
 * host chromium at /usr/bin/chromium.
 */

const path = require( 'path' );
const fs = require( 'fs' );
// Test data (samples, downloads, screenshots) lives outside the repo, because
// the repo is bind-mounted as the plugin and would otherwise be exported.
const DATA_DIR = process.env.FSC_E2E_DATA || path.join( require( 'os' ).homedir(), '.cache', 'wp-free-site-cloner-e2e' );
const { chromium } = require( 'playwright-core' );

const SOURCE_URL = 'http://127.0.0.1:8081';
const DEST_URL = 'http://127.0.0.1:8082';
const SOURCE_USER = 'admin';
const SOURCE_PASS = 'admin-pass-123';
// The dest site's admin account is destadmin/dest-admin-456 only until the
// first successful import lands: after that dest's users table is the
// source's, so admin/admin-pass-123 is the only account that still works.
// loginAny() (below) tries destadmin first and falls back automatically.
const DEST_USER = 'destadmin';
const DEST_PASS = 'dest-admin-456';

const SCREEN_DIR = path.join( DATA_DIR, 'screens' );
// Downloaded archives are large (hundreds of MB) and must never land inside
// the repo tree: the repo root is bind-mounted read-only as the plugin
// directory into every e2e site, so a file written under tests/e2e/ here
// gets re-exported by the *next* export run (the exporter walks its own
// plugin folder) and the archive snowballs on every cycle. Keep downloads
// entirely outside the repo instead.
const WORK_DIR = path.join( DATA_DIR, 'ui-downloads' );
const CLONER_PATH = '/wp-admin/tools.php?page=wp-free-site-cloner';

fs.mkdirSync( SCREEN_DIR, { recursive: true } );
fs.mkdirSync( WORK_DIR, { recursive: true } );

/**
 * Log in trying each [user, pass] pair in order; returns the pair that
 * worked. Needed for the dest site, whose admin account changes identity
 * across repeated import runs (see the DEST_USER comment above).
 */
async function loginAny( page, baseUrl, pairs ) {
	for ( var i = 0; i < pairs.length; i++ ) {
		var user = pairs[ i ][ 0 ];
		var pass = pairs[ i ][ 1 ];
		await page.goto( baseUrl + '/wp-login.php' );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'load' } ),
			page.click( '#wp-submit' ),
		] );
		if ( -1 === page.url().indexOf( 'wp-login.php' ) ) {
			return { user: user, pass: pass };
		}
	}
	throw new Error( 'loginAny: none of the ' + pairs.length + ' credential pair(s) worked for ' + baseUrl );
}

var shotN = 0;
async function shot( page, name ) {
	shotN++;
	var file = path.join( SCREEN_DIR, String( shotN ).padStart( 2, '0' ) + '-' + name + '.png' );
	await page.screenshot( { path: file, fullPage: true } );
	console.log( '[shot] ' + file );
}

// Chromium/wp-admin noise unrelated to this plugin, seen on stock wp-admin
// pages with no plugin at all: the browser's automatic /favicon.ico probe
// (these dev sites serve none) and a wp-admin core JS View Transitions
// call that a couple of admin-bar navigations abort. Neither indicates a
// bug in the code under test, so they are filtered out here rather than
// chased as false failures.
var IGNORE_PAGEERROR = /ViewTransition|Transition was aborted/;

function attachErrorLog( page, label ) {
	var errors = [];
	page.on( 'pageerror', function ( e ) {
		if ( IGNORE_PAGEERROR.test( e.message ) ) {
			return;
		}
		var msg = '[' + label + '] pageerror: ' + e.message;
		console.error( msg );
		errors.push( msg );
	} );
	// Track failed responses only for the plugin's own assets/AJAX calls
	// (not e.g. the browser's unrelated favicon.ico probe or other core
	// wp-admin network chatter) - this is the network surface actually
	// under test here.
	page.on( 'response', function ( res ) {
		var url = res.url();
		if ( res.status() >= 400 && /wp-free-site-cloner|admin-ajax\.php/.test( url ) ) {
			var msg = '[' + label + '] HTTP ' + res.status() + ' ' + url;
			console.error( msg );
			errors.push( msg );
		}
	} );
	return errors;
}

async function login( page, baseUrl, user, pass ) {
	await page.goto( baseUrl + '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'load' } ),
		page.click( '#wp-submit' ),
	] );
}

async function gotoCloner( page, baseUrl ) {
	await page.goto( baseUrl + CLONER_PATH, { waitUntil: 'load' } );
	await page.waitForSelector( '#fsc-app', { state: 'attached' } );
}

/**
 * Reads the #fsc-import-steps list's final key labels/states (v0.9.1) and
 * checks a Verify step is present and every step ended done or skipped -
 * never left pending/active/failed once the done screen is showing. The
 * list stays in the DOM (only its ancestor panel gets hidden) once the
 * import finishes, so this works regardless of #fsc-import-running's
 * visibility at the time of the call.
 *
 * @return {Promise<Array<string>>} failure message(s), empty if all good.
 */
async function assertImportStepsFinished( page, label ) {
	var steps = await page.evaluate( function () {
		var list = document.getElementById( 'fsc-import-steps' );
		if ( ! list ) {
			return null;
		}
		var out = [];
		for ( var i = 0; i < list.children.length; i++ ) {
			var li = list.children[ i ];
			var labelEl = li.querySelector( '.fsc-step-label' );
			var m = /fsc-step-(pending|active|done|skipped|failed)/.exec( li.className );
			out.push( { label: labelEl ? labelEl.textContent : '', state: m ? m[ 1 ] : '' } );
		}
		return out;
	} );
	console.log( '[steps] ' + label + ': ' + JSON.stringify( steps ) );
	var failures = [];
	if ( ! steps || ! steps.length ) {
		failures.push( label + ': step list was empty or missing (older backend without the v0.9.1 steps field?)' );
		return failures;
	}
	if ( ! steps.some( function ( s ) {
		return /verify/i.test( s.label );
	} ) ) {
		failures.push( label + ': step list has no "Verify" step: ' + JSON.stringify( steps ) );
	}
	var notFinished = steps.filter( function ( s ) {
		return 'done' !== s.state && 'skipped' !== s.state;
	} );
	if ( notFinished.length ) {
		failures.push( label + ': step(s) not done/skipped at the done screen: ' + JSON.stringify( notFinished ) );
	}
	return failures;
}

async function main() {
	var browser = await chromium.launch( {
		executablePath: '/usr/bin/chromium',
		headless: true,
		args: [ '--no-sandbox', '--disable-gpu' ],
	} );

	var failures = [];

	try {
		/* -------------------------- Source: export -------------------------- */
		var srcCtx = await browser.newContext( { viewport: { width: 1280, height: 1000 } } );
		var src = await srcCtx.newPage();
		var srcErrors = attachErrorLog( src, 'source' );

		await login( src, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
		await gotoCloner( src, SOURCE_URL );
		await shot( src, 'source-idle' );

		await src.click( '#fsc-export-start' );
		await src.waitForSelector( '#fsc-export-running:not([hidden])', { timeout: 15000 } );
		await shot( src, 'source-exporting' );

		await src.waitForSelector( '#fsc-export-done:not([hidden])', { timeout: 120000 } );
		var doneMsg = await src.textContent( '#fsc-export-done-msg' );
		console.log( '[export] ' + doneMsg );
		await shot( src, 'source-export-done' );

		await src.waitForSelector( '#fsc-archives-tbody tr', { timeout: 15000 } );
		var archiveName = await src.textContent( '#fsc-archives-tbody tr:first-child td:first-child' );
		archiveName = archiveName.trim();
		console.log( '[export] archive in list: ' + archiveName );
		await shot( src, 'source-archives-list' );

		var downloadLink = src.locator( '#fsc-archives-tbody tr:first-child a.button' );
		var [ download ] = await Promise.all( [
			src.waitForEvent( 'download' ),
			downloadLink.click(),
		] );
		var archivePath = path.join( WORK_DIR, archiveName );
		await download.saveAs( archivePath );
		var archiveStat = fs.statSync( archivePath );
		console.log( '[export] downloaded ' + archivePath + ' (' + archiveStat.size + ' bytes)' );
		if ( archiveStat.size <= 0 ) {
			throw new Error( 'downloaded archive is empty' );
		}

		/* --------------------- Narrow-width layout check ---------------------- */
		var narrowCtx = await browser.newContext( { viewport: { width: 400, height: 844 } } );
		var narrow = await narrowCtx.newPage();
		attachErrorLog( narrow, 'narrow' );
		await login( narrow, SOURCE_URL, SOURCE_USER, SOURCE_PASS );
		await gotoCloner( narrow, SOURCE_URL );
		await shot( narrow, 'narrow-400-idle' );
		// Also capture the Archives table (name/size/date/format columns,
		// scrolled) and its row action buttons (size no-wrap, compact
		// buttons) at 400px, once it has at least one row.
		await narrow.waitForSelector( '#fsc-archives-tbody tr' );
		await narrow.locator( '#fsc-archives-card' ).scrollIntoViewIfNeeded();
		await shot( narrow, 'narrow-400-archives' );
		await narrow.evaluate( function () {
			document.getElementById( 'fsc-archives-table' ).scrollLeft = 9999;
		} );
		await shot( narrow, 'narrow-400-archives-actions' );
		await narrowCtx.close();

		/* -------------------------- Dest: upload + import ---------------------- */
		var destCtx = await browser.newContext( { viewport: { width: 1280, height: 1000 }, acceptDownloads: true } );
		var dest = await destCtx.newPage();
		var destErrors = attachErrorLog( dest, 'dest' );

		await loginAny( dest, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );
		await gotoCloner( dest, DEST_URL );
		await shot( dest, 'dest-idle' );

		var storagePath = await dest.textContent( '#fsc-storage-path' );
		console.log( '[import] storage path shown to user: ' + storagePath );
		if ( ! storagePath || ! storagePath.trim() ) {
			failures.push( 'storage path not shown in Import section' );
		}

		await dest.setInputFiles( '#fsc-file-input', archivePath );
		await dest.waitForSelector( '#fsc-import-uploading:not([hidden]), #fsc-import-inspect:not([hidden])', { timeout: 15000 } );
		await shot( dest, 'dest-uploading' );

		await dest.waitForSelector( '#fsc-import-inspect:not([hidden])', { timeout: 120000 } );
		await shot( dest, 'dest-inspect' );

		var inspHome = await dest.textContent( '#fsc-insp-home' );
		var inspTarget = await dest.textContent( '#fsc-insp-target-home' );
		var inspFormat = await dest.textContent( '#fsc-insp-format' );
		console.log( '[import] inspect: ' + inspHome + ' -> ' + inspTarget + ' (' + inspFormat + ')' );
		if ( -1 === inspHome.indexOf( '8081' ) ) {
			failures.push( 'inspect did not show the source URL (8081): got "' + inspHome + '"' );
		}
		if ( -1 === inspTarget.indexOf( '8082' ) ) {
			failures.push( 'inspect did not show the destination URL (8082): got "' + inspTarget + '"' );
		}

		await dest.check( '#fsc-confirm-checkbox' );
		var startDisabled = await dest.getAttribute( '#fsc-import-start', 'disabled' );
		if ( null !== startDisabled ) {
			failures.push( 'Start import stayed disabled after checking the confirm checkbox' );
		}
		await shot( dest, 'dest-inspect-confirmed' );

		await dest.click( '#fsc-import-start' );
		await dest.waitForSelector( '#fsc-import-running:not([hidden])', { timeout: 15000 } );
		await shot( dest, 'dest-importing' );

		// Race done vs error so a real server-side failure surfaces immediately
		// instead of burning the full timeout.
		var finished = await Promise.race( [
			dest.waitForSelector( '#fsc-import-done:not([hidden])', { timeout: 240000 } ).then( function () {
				return 'done';
			} ),
			dest.waitForSelector( '#fsc-import-error:not([hidden])', { timeout: 240000 } ).then( function () {
				return 'error';
			} ),
		] );
		if ( 'error' === finished ) {
			var importErrMsg = await dest.textContent( '#fsc-import-error-msg' );
			await shot( dest, 'dest-import-error' );
			throw new Error( 'import reported an error: ' + importErrMsg );
		}
		await shot( dest, 'dest-import-done' );

		failures = failures.concat( await assertImportStepsFinished( dest, 'dest native import' ) );

		// The done screen must not be covered by core's wp-auth-check
		// session-expiry popup: the import's DB swap replaces the users
		// table mid-job, which invalidates the admin session outright, and
		// the heartbeat (15-60s interval) would otherwise pop the modal
		// over our own done screen. Wait past the heartbeat's interval and
		// confirm the element isn't even in the DOM (it is only added when
		// core's wp_auth_check_load runs, which our own page now disables
		// via the wp_auth_check_load filter in class-fsc-plugin.php).
		await dest.waitForTimeout( 22000 );
		var authCheckCount = await dest.locator( '#wp-auth-check-wrap' ).count();
		console.log( '[auth-check] #wp-auth-check-wrap present after 22s on the done screen: ' + ( authCheckCount > 0 ) );
		if ( authCheckCount > 0 ) {
			failures.push( 'wp-auth-check session-expiry modal appeared over our done screen' );
		}
		await shot( dest, 'dest-import-done-after-auth-check-wait' );

		var loginHref = await dest.getAttribute( '#fsc-import-login-link', 'href' );
		console.log( '[import] done, login link: ' + loginHref );

		/* --------------- Log in to dest with the SOURCE admin creds ------------ */
		await dest.click( '#fsc-import-login-link' );
		await dest.waitForSelector( '#user_login', { timeout: 15000 } );
		await dest.fill( '#user_login', SOURCE_USER );
		await dest.fill( '#user_pass', SOURCE_PASS );
		await Promise.all( [
			dest.waitForNavigation( { waitUntil: 'load' } ),
			dest.click( '#wp-submit' ),
		] );
		await shot( dest, 'dest-login-as-source-admin' );

		var loggedInUrl = dest.url();
		var hasAdminBar = await dest.locator( '#wpadminbar' ).count();
		console.log( '[login] landed on ' + loggedInUrl + ', wpadminbar present: ' + hasAdminBar );
		if ( -1 !== loggedInUrl.indexOf( 'wp-login.php' ) || 0 === hasAdminBar ) {
			failures.push( 'logging in to dest with the source admin credentials failed after import' );
		}

		if ( srcErrors.length ) {
			failures.push( srcErrors.length + ' JS error(s) on source page: ' + srcErrors[ 0 ] );
		}
		if ( destErrors.length ) {
			failures.push( destErrors.length + ' JS error(s) on dest page: ' + destErrors[ 0 ] );
		}

		fs.writeFileSync( path.join( WORK_DIR, 'archive-name.txt' ), archiveName );
	} finally {
		await browser.close();
	}

	if ( failures.length ) {
		console.error( '\nUI-FLOW FAILURES:' );
		failures.forEach( function ( f ) {
			console.error( ' - ' + f );
		} );
		process.exitCode = 1;
	} else {
		console.log( '\nUI-FLOW OK' );
	}
}

main().catch( function ( e ) {
	console.error( 'ui-flow.js crashed: ' + ( e && e.stack || e ) );
	process.exitCode = 1;
} );
