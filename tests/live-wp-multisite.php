<?php
/**
 * Real-WordPress MULTISITE test for the Yac Object Cache drop-in.
 *
 * Requires: a multisite install (wp core multisite-install), the plugin
 * ACTIVE, the drop-in deployed, and at least two blogs in the network.
 * Run from the WP root (the script boots WP via getcwd()/wp-load.php):
 *   php -d yac.enable_cli=1 -d display_errors=0 -d log_errors=1 -d error_log=/tmp/wp-yac-ms.log tests/live-wp-multisite.php
 *
 * This is the regression test for the "second site shows as the first /
 * data duplication" report: before per-blog namespacing every blog wrote
 * into one Yac namespace, so a global-group entry cached on blog A was
 * served to blog B. The assertions below prove the blogs no longer share.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'display_errors', 0 );

$checks = array();
function ck( $label, $cond ) {
	global $checks;
	$checks[] = (bool) $cond;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
}

/* ms-settings.php (loaded by wp-settings.php during the require below)
   resolves the current blog from HTTP_HOST + REQUEST_URI. In CLI those
   are unset, so the network boot cannot find a blog and bails. Point at
   the main site (the workflow installs on http://localhost/) BEFORE
   requiring wp-load.php, so the boot lands on blog 1. */
$_SERVER['HTTP_HOST']       = 'localhost';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['QUERY_STRING']    = '';

require getcwd() . '/wp-load.php';

ck( 'WordPress booted under WP_DEBUG', function_exists( 'add_action' ) && defined( 'WP_DEBUG' ) && WP_DEBUG );
ck( 'this is a multisite install', is_multisite() );

global $wp_object_cache;
ck( '$wp_object_cache is Yac_Ocache_Object_Cache', $wp_object_cache instanceof Yac_Ocache_Object_Cache );
ck( 'yac backend active (shared memory)', ! empty( $wp_object_cache->yac_available ) );
ck( 'drop-in version matches the plugin', defined( 'YAC_OCACHE_DROPIN_VERSION' ) && YAC_OCACHE_DROPIN_VERSION === YAC_OCACHE_VERSION );

/* two blogs to compare; the workflow creates the second with `wp site create` */
$blog_ids = array_map( 'intval', get_sites( array( 'number' => 2, 'fields' => 'ids' ) ) );
ck( 'network has at least two blogs', count( $blog_ids ) >= 2 );
if ( count( $blog_ids ) < 2 ) {
	echo 'found blogs: ' . implode( ', ', $blog_ids ) . "\n";
	exit( 1 );
}
list( $blog_a, $blog_b ) = array_slice( $blog_ids, 0, 2 );

/* reflection on the private storage_prefix so we assert the namespace
   itself, not just the observable cache behavior */
$rp = new ReflectionProperty( 'Yac_Ocache_Object_Cache', 'storage_prefix' );
if ( PHP_VERSION_ID < 80100 ) {
	$rp->setAccessible( true );
}
$prefix_of = function () use ( $wp_object_cache, $rp ) {
	return $rp->getValue( $wp_object_cache );
};
/* expected instance prefix for a blog: base (default 'wp') + id + ':' */
$expected_prefix = function ( $blog_id ) {
	$base = defined( 'YAC_OCACHE_KEY_PREFIX' )
		? preg_replace( '/[^A-Za-z0-9_]/', '', substr( (string) YAC_OCACHE_KEY_PREFIX, 0, 6 ) )
		: 'wp';
	return $base . ( (int) $blog_id < 10000 ? $blog_id : substr( hash( 'crc32b', (string) $blog_id ), -4 ) ) . ':';
};

/* a key every blog would use — the point is they must NOT collide */
$key   = 'yac_ms_isolation_key';
$group = 'default';

switch_to_blog( $blog_a );
ck( "on blog $blog_a: prefix carries the blog id", $prefix_of() === $expected_prefix( $blog_a ) );
wp_cache_set( $key, 'value-A', $group );
ck( 'on blog A: value round trips', wp_cache_get( $key, $group ) === 'value-A' );

switch_to_blog( $blog_b );
ck( "on blog $blog_b: prefix re-namespaced", $prefix_of() === $expected_prefix( $blog_b ) );
ck( 'BLOGS ARE ISOLATED: blog A value not visible on blog B', wp_cache_get( $key, $group ) === false );
wp_cache_set( $key, 'value-B', $group );
ck( 'on blog B: its own value round trips', wp_cache_get( $key, $group ) === 'value-B' );

switch_to_blog( $blog_a );
ck( "back on blog $blog_a: prefix re-namespaced", $prefix_of() === $expected_prefix( $blog_a ) );
ck( 'blog A value intact after visiting B (no corruption)', wp_cache_get( $key, $group ) === 'value-A' );

/* cross-request persistence is per-blog too: a fresh instance on blog A
   reads A's value, and one on blog B reads B's — shared memory holds both
   under distinct namespaces */
$fresh_a = new Yac_Ocache_Object_Cache();
ck( 'fresh instance on blog A reads value-A', $fresh_a->get( $key, $group ) === 'value-A' );

switch_to_blog( $blog_b );
$fresh_b = new Yac_Ocache_Object_Cache();
ck( 'fresh instance on blog B reads value-B', $fresh_b->get( $key, $group ) === 'value-B' );
switch_to_blog( $blog_a );

/* the raw shared store actually holds two distinct keys, proving the
   isolation is in the namespace and not a same-request illusion */
$raw = new Yac();
$dump = array();
foreach ( (array) $raw->dump( -1 ) as $it ) {
	if ( isset( $it['key'] ) ) {
		$dump[ $it['key'] ] = true;
	}
}
$ka = $expected_prefix( $blog_a ) . $group . ':' . $key;
$kb = $expected_prefix( $blog_b ) . $group . ':' . $key;
ck( "shared store has blog A key ($ka)", isset( $dump[ $ka ] ) );
ck( "shared store has blog B key ($kb)", isset( $dump[ $kb ] ) );

/* the admin diagnostics must mirror the drop-in's namespace: the prefix
   the dashboard reports for this blog equals the drop-in's live prefix */
ck( 'admin yac_ocache_key_prefix() mirrors the drop-in prefix', yac_ocache_key_prefix() === $prefix_of() );

/* the admin snapshot has two scopes out of one dump(): GLOBAL aggregates
   (entries/occupied/own) feed Cache status and must be identical no matter
   which blog's admin is open; PER-BLOG listings (largest/groups) feed the
   entries table and pie and must contain only that blog's keys. cache_ttl=0
   so each call re-dumps rather than reading the per-blog cache. */
$keys_in = function ( $list ) {
	$out = array();
	foreach ( (array) $list as $row ) {
		$out[] = $row[2];
	}
	return $out;
};
$snap_a = yac_ocache_memory_snapshot( 1000, $expected_prefix( $blog_a ) );
$snap_b = yac_ocache_memory_snapshot( 1000, $expected_prefix( $blog_b ) );
ck( 'snapshot available for both blogs', is_array( $snap_a ) && is_array( $snap_b ) );
if ( is_array( $snap_a ) && is_array( $snap_b ) ) {
	/* GLOBAL aggregates identical across blogs (same machine-wide pool) */
	ck( 'global entries aggregate is blog-independent', $snap_a['entries'] === $snap_b['entries'] );
	ck( 'global occupied aggregate is blog-independent', $snap_a['occupied'] === $snap_b['occupied'] );
	ck( 'global own counts both blogs (plugin-wide, not per-blog)', $snap_a['own'] === $snap_b['own'] && $snap_a['own'] >= 2 );

	/* PER-BLOG listings isolated: each blog sees its own key, not the other's */
	$list_a = $keys_in( $snap_a['largest'] );
	$list_b = $keys_in( $snap_b['largest'] );
	ck( "blog A entries list has blog A key ($ka)", in_array( $ka, $list_a, true ) );
	ck( "blog A entries list EXCLUDES blog B key ($kb)", ! in_array( $kb, $list_a, true ) );
	ck( "blog B entries list has blog B key ($kb)", in_array( $kb, $list_b, true ) );
	ck( "blog B entries list EXCLUDES blog A key ($ka)", ! in_array( $ka, $list_b, true ) );

	/* the pie (groups) is per-blog too: every listed key carries this blog's prefix */
	$groups_all_prefixed = function ( $snap, $pfx ) use ( $keys_in ) {
		foreach ( $keys_in( $snap['largest'] ) as $k ) {
			if ( 0 !== strpos( $k, $pfx ) ) {
				return false;
			}
		}
		return true;
	};
	ck( 'blog A listings all carry the blog A prefix', $groups_all_prefixed( $snap_a, $expected_prefix( $blog_a ) ) );
	ck( 'blog B listings all carry the blog B prefix', $groups_all_prefixed( $snap_b, $expected_prefix( $blog_b ) ) );
}

/* clean up so a re-run starts cold; each switch_to_blog re-namespaces
   independently, so no restore is needed (the script exits next) */
switch_to_blog( $blog_a );
wp_cache_delete( $key, $group );
switch_to_blog( $blog_b );
wp_cache_delete( $key, $group );

$failures = 0;
foreach ( $checks as $ok ) {
	if ( ! $ok ) {
		$failures++;
	}
}
echo "\npassed: " . ( count( $checks ) - $failures ) . ", failed: $failures\n";

$log = ini_get( 'error_log' );
if ( $log && file_exists( $log ) ) {
	$lines = array_filter( file( $log, FILE_IGNORE_NEW_LINES ), function ( $l ) {
		return false === strpos( $l, 'Deprecated' );
	} );
	echo "--- error_log tail (non-deprecated, last 20) ---\n";
	foreach ( array_slice( $lines, -20 ) as $line ) {
		echo $line . "\n";
	}
}
exit( $failures > 0 ? 1 : 0 );
