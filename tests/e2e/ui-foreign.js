#!/usr/bin/env node
/**
 * Foreign-format UI import check: drives the real admin UI (Playwright,
 * host chromium, headless) to upload the real .daf (Duplicator) and
 * .wpress (All-in-One WP Migration) sample archives from
 * <FSC_E2E_DATA>/samples/ into
 * dest, checks the inspect screen shows the right format name (from
 * meta.format_name, not a hardcoded label - see internal/API.md), and runs
 * each import through to completion, then logs back in to dest with the
 * SOURCE admin credentials (proves the import actually replaced dest's
 * DB). Complements ui-flow.js, which only exercises the native .tar path.
 *
 * Run: flatpak-spawn --host node tests/e2e/ui-foreign.js [daf|wpress|both]
 * Requires: tests/e2e up (up.sh), the samples above (from samples.sh),
 * playwright-core in tests/e2e/node_modules, host chromium at
 * /usr/bin/chromium.
 *
 * Each import fully replaces dest. After this script finishes, run
 * tests/e2e/assert.sh dest http://127.0.0.1:8082 http://127.0.0.1:8081
 * once per format imported (re-run it after each) to confirm the
 * migration actually landed correctly, not just that the UI reported
 * success.
 */

const path = require( 'path' );
const fs = require( 'fs' );
// Test data (samples, downloads, screenshots) lives outside the repo, because
// the repo is bind-mounted as the plugin and would otherwise be exported.
const DATA_DIR = process.env.FSC_E2E_DATA || path.join( require( 'os' ).homedir(), '.cache', 'wp-free-site-cloner-e2e' );
const { chromium } = require( 'playwright-core' );

const DEST_URL = 'http://127.0.0.1:8082';
const SOURCE_USER = 'admin';
const SOURCE_PASS = 'admin-pass-123';
// dest's admin account is destadmin/dest-admin-456 only until the first
// successful import lands; after that it's the source's admin account.
// loginAny() tries destadmin first and falls back automatically.
const DEST_USER = 'destadmin';
const DEST_PASS = 'dest-admin-456';

const SAMPLES_DIR = path.join( DATA_DIR, 'samples' );
const SCREEN_DIR = path.join( DATA_DIR, 'screens' );
const CLONER_PATH = '/wp-admin/tools.php?page=wp-free-site-cloner';

fs.mkdirSync( SCREEN_DIR, { recursive: true } );

var shotN = 0;
async function shot( page, name ) {
	shotN++;
	var file = path.join( SCREEN_DIR, 'foreign-' + String( shotN ).padStart( 2, '0' ) + '-' + name + '.png' );
	await page.screenshot( { path: file, fullPage: true } );
	console.log( '[shot] ' + file );
}

// See the matching comment in ui-flow.js: neither of these indicates a bug
// in the code under test.
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

/**
 * Log in trying each [user, pass] pair in order; throws if none work.
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
			return;
		}
	}
	throw new Error( 'loginAny: none of the ' + pairs.length + ' credential pair(s) worked for ' + baseUrl );
}

async function gotoCloner( page, baseUrl ) {
	await page.goto( baseUrl + CLONER_PATH, { waitUntil: 'load' } );
	await page.waitForSelector( '#fsc-app', { state: 'attached' } );
}

/**
 * Reads the #fsc-import-steps list's final key labels/states (v0.9.1) and
 * checks a Verify step is present and every step ended done or skipped.
 * Foreign archives (.daf/.wpress) skip Verify with a note instead of
 * running it (internal/API.md), so "skipped" must count as finished here,
 * not just "done" - this is exactly the case that distinguishes the two.
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

function findSample( ext ) {
	var files = fs.readdirSync( SAMPLES_DIR ).filter( function ( f ) {
		return f.toLowerCase().endsWith( '.' + ext );
	} );
	if ( ! files.length ) {
		throw new Error( 'no .' + ext + ' sample found in ' + SAMPLES_DIR );
	}
	files.sort();
	return path.join( SAMPLES_DIR, files[ files.length - 1 ] );
}

/**
 * Upload filePath on dest via the UI, wait for the inspect screen, assert
 * its format label contains expectFormatSubstr, confirm and run the
 * import to completion, then log back in with the source admin creds.
 *
 * @return {Promise<string[]>} failure messages (empty = clean run).
 */
async function runForeignImport( browser, label, filePath, expectFormatSubstr ) {
	var failures = [];
	var ctx = await browser.newContext( { viewport: { width: 1280, height: 1000 }, acceptDownloads: true } );
	var page = await ctx.newPage();
	var errors = attachErrorLog( page, label );

	console.log( '\n=== ' + label + ': ' + filePath + ' ===' );

	try {
		await loginAny( page, DEST_URL, [ [ DEST_USER, DEST_PASS ], [ SOURCE_USER, SOURCE_PASS ] ] );
		await gotoCloner( page, DEST_URL );
		await shot( page, label + '-idle' );

		await page.setInputFiles( '#fsc-file-input', filePath );
		await page.waitForSelector( '#fsc-import-uploading:not([hidden]), #fsc-import-inspect:not([hidden])', { timeout: 15000 } );
		await shot( page, label + '-uploading' );

		await page.waitForSelector( '#fsc-import-inspect:not([hidden])', { timeout: 240000 } );
		await shot( page, label + '-inspect' );

		var inspFormat = await page.textContent( '#fsc-insp-format' );
		var inspName = await page.textContent( '#fsc-insp-name' );
		console.log( '[' + label + '] inspect format: "' + inspFormat + '" (archive ' + inspName + ')' );
		if ( -1 === inspFormat.indexOf( expectFormatSubstr ) ) {
			failures.push( label + ': inspect format label did not contain "' + expectFormatSubstr + '": got "' + inspFormat + '"' );
		}

		var warningsHidden = await page.getAttribute( '#fsc-insp-warnings', 'hidden' );
		if ( null === warningsHidden ) {
			var warningsText = await page.textContent( '#fsc-insp-warnings-list' );
			console.log( '[' + label + '] warnings shown: ' + warningsText.trim() );
		}

		await page.check( '#fsc-confirm-checkbox' );
		await page.click( '#fsc-import-start' );
		await page.waitForSelector( '#fsc-import-running:not([hidden])', { timeout: 15000 } );
		await shot( page, label + '-importing' );

		var finished = await Promise.race( [
			page.waitForSelector( '#fsc-import-done:not([hidden])', { timeout: 300000 } ).then( function () {
				return 'done';
			} ),
			page.waitForSelector( '#fsc-import-error:not([hidden])', { timeout: 300000 } ).then( function () {
				return 'error';
			} ),
		] );
		if ( 'error' === finished ) {
			var errMsg = await page.textContent( '#fsc-import-error-msg' );
			await shot( page, label + '-import-error' );
			throw new Error( label + ': import reported an error: ' + errMsg );
		}
		await shot( page, label + '-import-done' );

		failures = failures.concat( await assertImportStepsFinished( page, label + ' import' ) );

		await page.click( '#fsc-import-login-link' );
		await page.waitForSelector( '#user_login', { timeout: 15000 } );
		await page.fill( '#user_login', SOURCE_USER );
		await page.fill( '#user_pass', SOURCE_PASS );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'load' } ),
			page.click( '#wp-submit' ),
		] );
		var hasAdminBar = await page.locator( '#wpadminbar' ).count();
		if ( -1 !== page.url().indexOf( 'wp-login.php' ) || 0 === hasAdminBar ) {
			failures.push( label + ': logging in to dest with the source admin credentials failed after import' );
		}
		await shot( page, label + '-login-as-source-admin' );

		if ( errors.length ) {
			failures.push( label + ': ' + errors.length + ' JS error(s): ' + errors[ 0 ] );
		}
	} finally {
		await ctx.close();
	}

	return failures;
}

async function main() {
	var which = process.argv[ 2 ] || 'both';
	var browser = await chromium.launch( {
		executablePath: '/usr/bin/chromium',
		headless: true,
		args: [ '--no-sandbox', '--disable-gpu' ],
	} );

	var failures = [];
	try {
		if ( 'daf' === which || 'both' === which ) {
			var dafPath = findSample( 'daf' );
			failures = failures.concat( await runForeignImport( browser, 'daf', dafPath, 'Duplicator' ) );
		}
		if ( 'wpress' === which || 'both' === which ) {
			var wpressPath = findSample( 'wpress' );
			failures = failures.concat( await runForeignImport( browser, 'wpress', wpressPath, 'All-in-One WP Migration' ) );
		}
	} finally {
		await browser.close();
	}

	if ( failures.length ) {
		console.error( '\nUI-FOREIGN FAILURES:' );
		failures.forEach( function ( f ) {
			console.error( ' - ' + f );
		} );
		process.exitCode = 1;
	} else {
		console.log( '\nUI-FOREIGN OK' );
	}
}

main().catch( function ( e ) {
	console.error( 'ui-foreign.js crashed: ' + ( e && e.stack || e ) );
	process.exitCode = 1;
} );
