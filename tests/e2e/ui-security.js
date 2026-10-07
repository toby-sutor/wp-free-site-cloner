#!/usr/bin/env node
/**
 * v0.9.2 security pass checks on the dest site (Playwright, host chromium,
 * headless, plus podman exec into the dest web container):
 *
 *   main     storage modes (stat), storage block on the admin page, old-
 *            archives notice, trust warning on the import confirmation, an
 *            injected TypeError answered as JSON with a reference (mu-plugin,
 *            removed afterwards), the daily cleanup cron (stale .part/tmp
 *            purge, retention via filter, schedule/unschedule on
 *            deactivate/activate) and the "Delete all archives" button.
 *   custom   FSC_STORAGE_DIR=/var/fsc-outside/storage in dest's wp-config.php:
 *            storage block shows it, modes inside it. Leaves the constant
 *            in place so ui-flow.js can import through it; run "restore".
 *   invalid  FSC_STORAGE_DIR=relative/path: the admin page shows the error.
 *   restore  removes the FSC_STORAGE_DIR line again.
 *
 * Run: flatpak-spawn --host --env=FSC_E2E_DATA=... node tests/e2e/ui-security.js main
 * Screenshots: $FSC_E2E_DATA/screens/v5/.
 */

const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const DATA_DIR = process.env.FSC_E2E_DATA || path.join( require( 'os' ).homedir(), '.cache', 'wp-free-site-cloner-e2e' );
const { chromium } = require( 'playwright-core' );
const lib = require( './ui-lib.js' );

const DEST_URL = 'http://127.0.0.1:8082';
const DEST_PAIRS = [ [ 'destadmin', 'dest-admin-456' ], [ 'admin', 'admin-pass-123' ] ];
const WEB = 'fsc-e2e-dest-web';
const WP = '/var/www/html';
const STORAGE = WP + '/wp-content/fsc-storage';
const OUTSIDE = '/var/fsc-outside/storage';
const MARK = '// fsc-e2e-storage';
const SCREEN_DIR = path.join( DATA_DIR, 'screens/v5' );

const shot = lib.makeShotter( SCREEN_DIR, 'sec-' );
var failures = [];

function check( cond, msg ) {
	if ( cond ) {
		console.log( '[ok] ' + msg );
	} else {
		failures.push( msg );
		console.error( '[FAIL] ' + msg );
	}
}

function sh( cmd, user ) {
	return execFileSync( 'podman', [ 'exec', '-u', user || 'www-data', WEB, 'sh', '-c', cmd ], { encoding: 'utf8' } );
}

/** Run PHP with WordPress loaded, as the web server user. */
function wpPhp( code ) {
	var boot = 'define( "WP_ADMIN", true ); require "' + WP + '/wp-load.php"; require_once ABSPATH . "wp-admin/includes/plugin.php"; ';
	return execFileSync( 'podman', [ 'exec', '-u', 'www-data', '-w', WP, WEB, 'php', '-r', boot + code ], { encoding: 'utf8' } );
}

function privateDir( base ) {
	return sh( 'ls -d ' + base + '/private-* 2>/dev/null | head -n1' ).trim();
}

function setStorageConstant( value ) {
	sh( "sed -i '/" + MARK.replace( /\//g, '\\/' ) + "$/d' " + WP + '/wp-config.php', 'root' );
	if ( null !== value ) {
		sh( "sed -i \"1a define( 'FSC_STORAGE_DIR', '" + value + "' ); " + MARK + '" ' + WP + '/wp-config.php', 'root' );
	}	// The image's opcache revalidates files every 2 seconds.
	Atomics.wait( new Int32Array( new SharedArrayBuffer( 4 ) ), 0, 0, 3000 );
}

async function openCloner( page ) {
	await lib.gotoCloner( page, DEST_URL );
	await page.waitForSelector( '#fsc-storage-info:not([hidden])', { timeout: 30000 } );
}

async function testStorageBlock( page ) {
	var priv = privateDir( STORAGE );
	var modes = sh( 'stat -c "%a %n" ' + STORAGE + ' ' + priv + ' ' + priv + '/tmp ' + priv + '/*.* ' + priv + '/tmp/* 2>/dev/null' );
	var bad = modes.split( '\n' ).filter( function ( l ) {
		return l && ! /^(700|600) /.test( l );
	} );
	check( 0 === bad.length, 'storage dirs 700 / files 600 (' + modes.split( '\n' ).length + ' entries)' + ( bad.length ? ': ' + bad.join( '; ' ) : '' ) );
	await openCloner( page );
	var loc = await page.textContent( '#fsc-storage-location' );
	check( loc === priv, 'storage block shows the private dir: ' + loc );
	var m = await page.textContent( '#fsc-storage-modes' );
	check( /0700/.test( m ) && /0600/.test( m ), 'storage block shows modes: ' + m );
	var ret = await page.textContent( '#fsc-storage-retention' );
	check( /^Off/.test( ret ), 'retention shown as off: ' + ret );
	var risk = await page.textContent( '#fsc-archives-risk' );
	check( /not encrypted/.test( risk ), 'risk note next to the Archives list' );
	await page.locator( '#fsc-archives-card' ).screenshot( { path: path.join( SCREEN_DIR, 'sec-storage-default.png' ) } );
}

async function testRetentionNotice( page ) {
	var priv = privateDir( STORAGE );
	var names = sh( 'ls ' + priv + ' | grep -E "\\.(tar|wpress|zip|daf)$" | head -n2' ).trim().split( '\n' ).filter( Boolean );
	check( names.length > 0, 'archives available to age: ' + names.length );
	sh( names.map( function ( n ) {
		return 'touch -d "10 days ago" "' + priv + '/' + n + '"';
	} ).join( ' && ' ) );
	await openCloner( page );
	await page.waitForSelector( '#fsc-retention-notice:not([hidden])', { timeout: 15000 } );
	var msg = await page.textContent( '#fsc-retention-notice-msg' );
	check( new RegExp( '^' + names.length + ' archive\\(s\\) in storage are older than 7 days' ).test( msg ), 'retention notice: ' + msg );
	await shot( page, 'retention-notice' );
}

async function testConfirmWarning( page ) {
	await openCloner( page );
	var row = page.locator( '#fsc-archives-tbody tr', { hasText: '.tar' } ).first();
	await row.locator( 'button', { hasText: 'Use for import' } ).click();
	await page.waitForSelector( '#fsc-import-inspect:not([hidden])', { timeout: 60000 } );
	var txt = await page.textContent( '#fsc-confirm-trust' );
	check( /Only import archives you created or fully trust/.test( txt ) && /mu-plugins/.test( txt ), 'trust warning on the confirmation' );
	await page.locator( '#fsc-import-inspect' ).screenshot( { path: path.join( SCREEN_DIR, 'sec-confirm-warning.png' ) } );
	await page.click( '#fsc-import-inspect-back' );
}

async function testInjectedTypeError( page ) {
	sh( 'mkdir -p ' + WP + '/wp-content/mu-plugins && cat > ' + WP + "/wp-content/mu-plugins/fsc-e2e-inject.php <<'EOF'\n" +
		"<?php\n// e2e only: throws a TypeError inside fsc_archives when fsc_inject=1.\n" +
		"add_filter( 'fsc_archive_retention_days', function ( $d ) {\n" +
		"\tif ( isset( $_REQUEST['fsc_inject'] ) ) {\n\t\treturn strlen( array() );\n\t}\n\treturn $d;\n} );\nEOF" );
	try {
		await openCloner( page );
		var res = await page.evaluate( async function () {
			var fd = new FormData();
			fd.append( 'action', 'fsc_archives' );
			fd.append( 'nonce', window.FSC_Admin.nonce );
			fd.append( 'fsc_inject', '1' );
			var r = await fetch( window.FSC_Admin.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } );
			var text = await r.text();
			return { status: r.status, text: text };
		} );
		var json = null;
		try {
			json = JSON.parse( res.text );
		} catch ( e ) {
			json = null;
		}
		check( 400 === res.status, 'injected TypeError -> HTTP ' + res.status );
		check( json && false === json.success && 'error' === json.data.code && /^[0-9a-f]{8}$/.test( json.data.ref ), 'JSON error with reference: ' + res.text.slice( 0, 200 ) );
		check( json && json.data.message.indexOf( json.data.ref ) >= 0 && ! /strlen|TypeError|\.php/.test( json.data.message ), 'message names the reference, no details' );
		if ( json && json.data.ref ) {
			// Apache writes PHP's error_log() to the container's stderr.
			var all = execFileSync( 'sh', [ '-c', 'podman logs --since 10m ' + WEB + ' 2>&1' ], { encoding: 'utf8', maxBuffer: 64 * 1048576 } );
			check( all.indexOf( 'reference ' + json.data.ref ) >= 0 && /TypeError/.test( all ), 'error log has the reference and the TypeError' );
		}
		var ok = await page.evaluate( async function () {
			var fd = new FormData();
			fd.append( 'action', 'fsc_archives' );
			fd.append( 'nonce', window.FSC_Admin.nonce );
			var r = await fetch( window.FSC_Admin.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' } );
			return r.status;
		} );
		check( 200 === ok, 'same request without injection -> ' + ok );
	} finally {
		sh( 'rm -f ' + WP + '/wp-content/mu-plugins/fsc-e2e-inject.php' );
	}
	check( '' === sh( 'ls ' + WP + '/wp-content/mu-plugins/ 2>/dev/null | grep fsc-e2e || true' ).trim(), 'injection mu-plugin removed' );
}

function testCron() {
	var priv = privateDir( STORAGE );
	var sched = wpPhp( 'echo wp_next_scheduled( "fsc_daily_cleanup" ) ? "yes" : "no";' ).trim();
	check( 'yes' === sched, 'cron scheduled after the admin page load: ' + sched );
	sh( 'cd ' + priv + ' && printf x > stale-e2e.tar.part && touch -d "2 days ago" stale-e2e.tar.part' +
		' && printf x > fresh-e2e.tar.part' +
		' && printf x > tmp/0123456789abcdef.sql && touch -d "2 days ago" tmp/0123456789abcdef.sql' +
		' && printf x > old-e2e.tar && touch -d "6 days ago" old-e2e.tar' );
	wpPhp( 'do_action( "fsc_daily_cleanup" );' );
	var after = sh( 'cd ' + priv + ' && ls stale-e2e.tar.part fresh-e2e.tar.part tmp/0123456789abcdef.sql old-e2e.tar 2>/dev/null || true' );
	check( -1 === after.indexOf( 'stale-e2e' ) && -1 === after.indexOf( '0123456789abcdef' ), 'cron purged stale .part and tmp files' );
	check( after.indexOf( 'fresh-e2e.tar.part' ) >= 0 && after.indexOf( 'old-e2e.tar' ) >= 0, 'cron kept fresh .part and (retention off) the 6-day-old archive' );
	wpPhp( 'add_filter( "fsc_archive_retention_days", function () { return 5; } ); do_action( "fsc_daily_cleanup" );' );
	after = sh( 'cd ' + priv + ' && ls old-e2e.tar 2>/dev/null || true' );
	check( -1 === after.indexOf( 'old-e2e.tar' ), 'retention 5 days deleted the 6-day-old archive' );
	sh( 'rm -f ' + priv + '/fresh-e2e.tar.part' );
	var plugin = 'wp-free-site-cloner/wp-free-site-cloner.php';
	var deact = wpPhp( 'deactivate_plugins( "' + plugin + '" ); echo wp_next_scheduled( "fsc_daily_cleanup" ) ? "yes" : "no";' ).trim();
	check( 'no' === deact, 'deactivation unschedules the cron: ' + deact );
	var act = wpPhp( 'activate_plugin( "' + plugin + '" ); echo wp_next_scheduled( "fsc_daily_cleanup" ) ? "yes" : "no";' ).trim();
	check( 'yes' === act, 'activation schedules the cron: ' + act );
}

async function testDeleteAll( page ) {
	// The retention run above deleted the aged archives: age the rest.
	var priv = privateDir( STORAGE );
	sh( 'cd ' + priv + ' && for f in *.tar *.wpress *.zip *.daf; do [ -f "$f" ] && touch -d "10 days ago" "$f"; done; true' );
	await openCloner( page );
	await page.waitForSelector( '#fsc-retention-notice:not([hidden])', { timeout: 15000 } );
	page.once( 'dialog', function ( d ) {
		check( /Delete ALL archives/.test( d.message() ), 'delete-all asks for confirmation' );
		d.accept();
	} );
	await page.click( '#fsc-delete-all' );
	await page.waitForSelector( '#fsc-archives-notice.notice-success:not([hidden])', { timeout: 30000 } );
	var msg = await page.textContent( '#fsc-archives-notice-msg' );
	check( /archive\(s\) deleted/.test( msg ), 'delete-all result: ' + msg );
	await page.waitForFunction( function () {
		return 0 === document.querySelectorAll( '#fsc-archives-tbody tr' ).length;
	}, null, { timeout: 15000 } );
	check( await page.isHidden( '#fsc-retention-notice' ), 'retention notice gone' );
	var left = sh( 'ls ' + privateDir( STORAGE ) + ' | grep -E "\\.(tar|wpress|zip|daf)$" || true' ).trim();
	check( '' === left, 'no archives left in storage' );
	await shot( page, 'after-delete-all' );
}

async function testCustom( page ) {
	sh( 'mkdir -p /var/fsc-outside && chown www-data:www-data /var/fsc-outside', 'root' );
	setStorageConstant( OUTSIDE );
	await openCloner( page );
	var loc = await page.textContent( '#fsc-storage-location' );
	check( 0 === loc.indexOf( OUTSIDE + '/private-' ), 'storage block shows FSC_STORAGE_DIR: ' + loc );
	var src = await page.textContent( '#fsc-storage-source' );
	check( /FSC_STORAGE_DIR/.test( src ), 'source label: ' + src );
	var path0 = await page.textContent( '#fsc-storage-path' );
	check( path0 === loc, 'FTP path in the import card follows it' );
	var modes = sh( 'stat -c "%a %n" ' + OUTSIDE + ' ' + loc + ' ' + loc + '/tmp ' + loc + '/index.php' );
	check( ! /^(?!700 |600 )/m.test( modes.trim() ), 'modes in FSC_STORAGE_DIR: ' + modes.trim().replace( /\n/g, '; ' ) );
	var notes = await page.textContent( '#fsc-storage-notes' );
	check( ! /inside the web root/.test( notes ), 'no web-root note for an outside dir' );
	await page.locator( '#fsc-archives-card' ).screenshot( { path: path.join( SCREEN_DIR, 'sec-storage-custom.png' ) } );
}

async function testInvalid( page ) {
	setStorageConstant( 'relative/path' );
	try {
		await openCloner( page );
		await page.waitForSelector( '#fsc-storage-error:not([hidden])', { timeout: 15000 } );
		var msg = await page.textContent( '#fsc-storage-error-msg' );
		check( /absolute path/.test( msg ), 'invalid FSC_STORAGE_DIR error shown: ' + msg );
		await page.locator( '#fsc-archives-card' ).screenshot( { path: path.join( SCREEN_DIR, 'sec-storage-invalid.png' ) } );
	} finally {
		setStorageConstant( null );
	}
}

async function main() {
	var mode = process.argv[ 2 ] || 'main';
	if ( 'restore' === mode ) {
		setStorageConstant( null );
		console.log( 'FSC_STORAGE_DIR line removed' );
		return;
	}
	var browser = await chromium.launch( { executablePath: '/usr/bin/chromium', headless: true, args: [ '--no-sandbox', '--disable-gpu' ] } );
	try {
		var page = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
		var errors = lib.attachErrorLog( page, 'dest' );
		await lib.loginAny( page, DEST_URL, DEST_PAIRS );
		if ( 'main' === mode ) {
			await testStorageBlock( page );
			await testRetentionNotice( page );
			await testConfirmWarning( page );
			await testInjectedTypeError( page );
			testCron();
			await testDeleteAll( page );
		} else if ( 'custom' === mode ) {
			await testCustom( page );
		} else if ( 'invalid' === mode ) {
			await testInvalid( page );
		}
		// The injected request is expected to answer 400.
		var unexpected = errors.filter( function ( e ) {
			return ! /HTTP 400 .*admin-ajax\.php/.test( e ) || 'main' !== mode;
		} );
		check( 0 === unexpected.length, 'no unexpected page errors / HTTP errors' + ( unexpected.length ? ': ' + unexpected.join( '; ' ) : '' ) );
	} finally {
		await browser.close();
	}
	if ( failures.length ) {
		console.error( '\nUI-SECURITY FAILED (' + failures.length + ')' );
		process.exit( 1 );
	}
	console.log( '\nUI-SECURITY OK (' + mode + ')' );
}

main().catch( function ( e ) {
	console.error( e );
	process.exit( 1 );
} );
