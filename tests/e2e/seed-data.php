<?php
/**
 * Seeds the source site with realistic content for the e2e migration tests.
 * Run inside the WP environment via `wp eval-file seed-data.php` (see seed.sh).
 * Not idempotent by design: seed.sh is meant to run once against a fresh
 * install from up.sh, so this always inserts fresh rows rather than
 * checking for existing ones first.
 */

$site = home_url();

// Posts with absolute URLs to the site baked into the content, the way real
// posts often do (hardcoded links, image tags) so search-replace has real
// work to do.
$posts = array(
	array(
		'title'   => 'FSC Seed Post One',
		'content' => "See our other page at {$site}/fsc-seed-page-two/ and an image at "
			. "<img src=\"{$site}/wp-content/uploads/fsc-seed/fsc-seed-1.png\" /> for reference.",
	),
	array(
		'title'   => 'FSC Seed Post Two',
		'content' => "Absolute link: <a href=\"{$site}/fsc-seed-post-one/\">home</a>. Raw URL: {$site}",
	),
	array(
		'title'   => 'FSC Seed Post Three',
		'content' => "Contact us at {$site}/contact or visit {$site}/about for details.",
	),
);
foreach ( $posts as $p ) {
	wp_insert_post(
		array(
			'post_title'   => $p['title'],
			'post_content' => $p['content'],
			'post_status'  => 'publish',
			'post_type'    => 'post',
		)
	);
}

// A page, same idea.
wp_insert_post(
	array(
		'post_title'   => 'FSC Seed Page Two',
		'post_content' => "Back to <a href=\"{$site}/\">home</a>. Full URL: {$site}/fsc-seed-page-two/",
		'post_status'  => 'publish',
		'post_type'    => 'page',
	)
);

// UTF-8 multibyte title: emoji + umlauts + CJK, to catch charset/collation
// mishandling in the DB export/import path.
wp_insert_post(
	array(
		'post_title'   => 'Ünïcödé Test 🎉🚀 Über Café 日本語',
		'post_content' => "Multibyte content: äöüß 日本語 emoji 🎉🚀. Site: {$site}",
		'post_status'  => 'publish',
		'post_type'    => 'post',
	)
);

// Serialized array option shaped like real widget instance data, containing
// the site URL in several places (top-level string and nested array).
update_option(
	'fsc_test_serialized_widget',
	array(
		2 => array(
			'title' => 'FSC Test Widget',
			'text'  => "Visit {$site} for more.",
			'url'   => $site . '/some-page',
			'links' => array( $site . '/a', $site . '/b' ),
		),
		'_multiwidget' => 1,
	)
);

// JSON-escaped URL option: PHP's json_encode escapes "/" as "\/" by default,
// which is a common trap for naive string-replace search/replace code.
update_option(
	'fsc_test_json_option',
	wp_json_encode(
		array(
			'site'   => $site,
			'nested' => array( 'home' => home_url( '/' ) ),
		)
	)
);

// Serialized PHP object option.
$obj            = new stdClass();
$obj->site_url  = $site;
$nested         = new stdClass();
$nested->x      = 1;
$nested->url    = $site . '/nested';
$obj->nested    = $nested;
update_option( 'fsc_test_object_option', $obj );

// User meta on the admin user (user_id 1) containing a site URL.
update_user_meta( 1, 'fsc_test_user_meta', "profile url {$site}/author/admin/ plus some more text" );

echo "fsc-seed-data: done\n";
