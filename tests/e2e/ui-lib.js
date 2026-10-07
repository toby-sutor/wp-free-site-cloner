/**
 * Shared helpers for the v0.9.1 UI test scripts (ui-errors.js,
 * ui-screens-v4.js). Kept separate from ui-flow.js/ui-foreign.js, which
 * predate this file and duplicate a couple of these functions inline -
 * left as-is there to avoid an unrelated refactor of working tests.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const CLONER_PATH = '/wp-admin/tools.php?page=wp-free-site-cloner';

// Chromium/wp-admin noise unrelated to this plugin - see the matching
// comment in ui-flow.js.
const IGNORE_PAGEERROR = /ViewTransition|Transition was aborted/;

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

async function login( page, baseUrl, user, pass ) {
	await page.goto( baseUrl + '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', pass );
	await Promise.all( [
		page.waitForNavigation( { waitUntil: 'load', timeout: 60000 } ),
		page.click( '#wp-submit' ),
	] );
}

/** Tries each [user, pass] pair in order; needed for dest, whose admin identity changes after the first import lands. */
async function loginAny( page, baseUrl, pairs ) {
	for ( var i = 0; i < pairs.length; i++ ) {
		var user = pairs[ i ][ 0 ];
		var pass = pairs[ i ][ 1 ];
		await page.goto( baseUrl + '/wp-login.php' );
		await page.fill( '#user_login', user );
		await page.fill( '#user_pass', pass );
		await Promise.all( [
			page.waitForNavigation( { waitUntil: 'load', timeout: 60000 } ),
			page.click( '#wp-submit' ),
		] );
		if ( -1 === page.url().indexOf( 'wp-login.php' ) ) {
			return { user: user, pass: pass };
		}
	}
	throw new Error( 'loginAny: none of the ' + pairs.length + ' credential pair(s) worked for ' + baseUrl );
}

async function gotoCloner( page, baseUrl ) {
	await page.goto( baseUrl + CLONER_PATH, { waitUntil: 'load' } );
	await page.waitForSelector( '#fsc-app', { state: 'attached' } );
}

function makeShotter( screenDir, prefix ) {
	fs.mkdirSync( screenDir, { recursive: true } );
	var n = 0;
	return async function shot( page, name ) {
		n++;
		var file = path.join( screenDir, prefix + String( n ).padStart( 2, '0' ) + '-' + name + '.png' );
		await page.screenshot( { path: file, fullPage: true } );
		console.log( '[shot] ' + file );
		return file;
	};
}

/**
 * A minimal, fast-to-upload fake "native" .tar: a 512-byte ustar header
 * naming the first entry "fsc-manifest.json" with a correct octal size
 * field, the manifest JSON itself (deliberately *without* an archive_size
 * key, as a real pre-0.9.1 archive would be), NUL-padded to the next 512
 * boundary, then two all-zero 512-byte end blocks. This is enough to pass
 * the browser's local pre-upload check (internal/API.md: "No archive_size
 * key = only the end blocks can be checked", and this buffer's end blocks
 * are genuinely zero) without needing to upload a real multi-MB archive -
 * used only to drive the post-upload inspect path (the server will still
 * reject it as an unparseable/unsupported archive, or a route() handler
 * intercepts the inspect call, depending on the test).
 */
function buildFakeNativeTar() {
	var manifest = Buffer.from( JSON.stringify( { format: 'fsc', format_version: 1, plugin_version: '0.9.0' } ), 'utf8' );
	var header = Buffer.alloc( 512 );
	header.write( 'fsc-manifest.json', 0, 'ascii' );
	var octal = manifest.length.toString( 8 );
	while ( octal.length < 11 ) {
		octal = '0' + octal;
	}
	header.write( octal + '\0', 124, 'ascii' );
	var pad = manifest.length % 512 === 0 ? 0 : 512 - ( manifest.length % 512 );
	var content = Buffer.concat( [ manifest, Buffer.alloc( pad ) ] );
	var trailer = Buffer.alloc( 1024 ); // two all-zero end blocks
	return Buffer.concat( [ header, content, trailer ] );
}

module.exports = {
	CLONER_PATH: CLONER_PATH,
	attachErrorLog: attachErrorLog,
	login: login,
	loginAny: loginAny,
	gotoCloner: gotoCloner,
	makeShotter: makeShotter,
	buildFakeNativeTar: buildFakeNativeTar,
};
