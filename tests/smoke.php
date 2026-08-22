<?php
/**
 * Smoke test for the Yac object-cache.php drop-in.
 *
 * Run (shared-memory path):
 *   php -d yac.enable_cli=1 tests/smoke.php
 * Run (runtime-only fallback path, no yac):
 *   php tests/smoke.php          # or  php -d extension=... without yac
 *
 * Stubs the handful of WordPress functions the drop-in touches so the file
 * can be loaded and exercised standalone.
 */

error_reporting( E_ALL );

// ---------------------------------------------------------------------------
// Minimal WordPress stubs
// ---------------------------------------------------------------------------

$GLOBALS['table_prefix'] = 'wp_';
$GLOBALS['blog_id']      = 1;

function apply_filters( $hook, $value ) {
	return $value;
}

function is_multisite() {
	return ! empty( $GLOBALS['wp_yac_test_multisite'] );
}

function wp_rand( $min = 0, $max = 0 ) {
	return mt_rand( $min, $max );
}

function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}

function __( $s, $d = '' ) {
	return $s;
}

function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}

function esc_js( $s ) {
	return $s;
}

function number_format_i18n( $n, $dec = 0 ) {
	return number_format( $n, $dec );
}

function size_format( $bytes, $dec = 0 ) {
	return round( $bytes / 1024, $dec ) . ' KB';
}

// ---------------------------------------------------------------------------
// Tiny assertion harness
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;

function check( $label, $cond ) {
	global $passed, $failed;
	if ( $cond ) {
		$passed++;
		echo "  ok   $label\n";
	} else {
		$failed++;
		echo "  FAIL $label\n";
	}
}

// ---------------------------------------------------------------------------
// Load the drop-in under test
// ---------------------------------------------------------------------------

define( 'ABSPATH', sys_get_temp_dir() . '/' );

require __DIR__ . '/../object-cache.php';

wp_cache_init();

$yac_on = isset( $GLOBALS['wp_object_cache'] ) && $GLOBALS['wp_object_cache']->yac_available;
echo "Yac backend available: " . ( $yac_on ? "yes (shared memory)\n" : "no (runtime-only fallback)\n" );

// ---------------------------------------------------------------------------
// Basic set / get
// ---------------------------------------------------------------------------
check( 'set returns true', wp_cache_set( 'foo', 'bar' ) === true );
check( 'get returns stored value', wp_cache_get( 'foo' ) === 'bar' );

$found = null;
wp_cache_get( 'foo', 'default', false, $found );
check( 'get sets $found=true on hit', $found === true );

$found = null;
wp_cache_get( 'missing-key', 'default', false, $found );
check( 'get sets $found=false on miss', $found === false );

// ---------------------------------------------------------------------------
// false ambiguity — store the literal false, must be retrievable as a hit
// ---------------------------------------------------------------------------
wp_cache_set( 'is_false', false );
$found = null;
$v = wp_cache_get( 'is_false', 'default', false, $found );
check( 'stored false is retrievable (found=true)', $found === true && $v === false );

// ---------------------------------------------------------------------------
// add semantics
// ---------------------------------------------------------------------------
check( 'add on new key succeeds', wp_cache_add( 'addkey', 'first' ) === true );
check( 'add on existing key fails', wp_cache_add( 'addkey', 'second' ) === false );
check( 'add did not overwrite value', wp_cache_get( 'addkey' ) === 'first' );

// Regression: a miss leaves a negative local entry; add must still succeed.
$found = null;
wp_cache_get( 'add-after-miss', 'default', false, $found );
check( 'add succeeds after a prior get-miss', wp_cache_add( 'add-after-miss', 'now' ) === true );
check( 'value stored by add-after-miss', wp_cache_get( 'add-after-miss' ) === 'now' );

// ---------------------------------------------------------------------------
// delete
// ---------------------------------------------------------------------------
wp_cache_set( 'delme', 'x' );
check( 'delete returns true', wp_cache_delete( 'delme' ) === true );
check( 'deleted key is gone', wp_cache_get( 'delme' ) === false );

// ---------------------------------------------------------------------------
// replace
// ---------------------------------------------------------------------------
check( 'replace on missing key fails', wp_cache_replace( 'no_such', 'v' ) === false );
wp_cache_set( 'rep', 'old' );
check( 'replace on existing key succeeds', wp_cache_replace( 'rep', 'new' ) === true );
check( 'replace updated value', wp_cache_get( 'rep' ) === 'new' );

// ---------------------------------------------------------------------------
// incr / decr
// ---------------------------------------------------------------------------
wp_cache_set( 'counter', 5 );
check( 'incr by 3 -> 8', wp_cache_incr( 'counter', 3 ) === 8 );
check( 'decr by 10 -> 0 (floor)', wp_cache_decr( 'counter', 10 ) === 0 );
check( 'incr on missing key -> false', wp_cache_incr( 'no_counter' ) === false );

// ---------------------------------------------------------------------------
// non-persistent groups: live only for the request
// ---------------------------------------------------------------------------
wp_cache_add_non_persistent_groups( 'ephemeral' );
wp_cache_set( 'np', 'value', 'ephemeral' );
check( 'non-persistent get works within request', wp_cache_get( 'np', 'ephemeral' ) === 'value' );

// ---------------------------------------------------------------------------
// multiple-key API
// ---------------------------------------------------------------------------
$m = wp_cache_set_multiple( array( 'm1' => 'a', 'm2' => 'b' ) );
check( 'set_multiple returns array of true', $m === array( 'm1' => true, 'm2' => true ) );
$g = wp_cache_get_multiple( array( 'm1', 'm2', 'mX' ) );
check( 'get_multiple returns values + false for miss', $g['m1'] === 'a' && $g['m2'] === 'b' && $g['mX'] === false );

$a = wp_cache_add_multiple( array( 'm1' => 'zzz', 'm3' => 'c' ) );
check( 'add_multiple: existing false, new true', $a['m1'] === false && $a['m3'] === true );

$d = wp_cache_delete_multiple( array( 'm1', 'm2' ) );
check( 'delete_multiple returns true per key', $d['m1'] === true && $d['m2'] === true );

// ---------------------------------------------------------------------------
// get_multi (classic shape)
// ---------------------------------------------------------------------------
wp_cache_set( 'gm1', 1 );
wp_cache_set( 'gm2', 2 );
$gm = wp_cache_get_multi( array( 'default' => array( 'gm1', 'gm2', 'gmNone' ) ) );
check( 'get_multi returns keyed results', isset( $gm ) && in_array( 1, $gm, true ) );

// ---------------------------------------------------------------------------
// flush: Yac::flush() wipes the entire shared memory
// ---------------------------------------------------------------------------
wp_cache_set( 'flush_target', 'before' );
check( 'value present before flush', wp_cache_get( 'flush_target' ) === 'before' );
check( 'flush returns true', wp_cache_flush() === true );
check( 'value gone after flush', wp_cache_get( 'flush_target' ) === false );

// ---------------------------------------------------------------------------
// Cross-request persistence (only meaningful when Yac is active):
// build a fresh object cache instance, simulating a new request, and verify
// previously stored values survive.
// ---------------------------------------------------------------------------
if ( $yac_on ) {
	wp_cache_set( 'persist', 'across-requests' );

	// Fresh instance = new request.
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	check( 'value persists into a new instance (cross-request)', wp_cache_get( 'persist' ) === 'across-requests' );

	// And flush wipes shared memory, so a new instance after flush misses.
	wp_cache_set( 'persist2', 'x' );
	wp_cache_flush();
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	check( 'flush takes effect across instances', wp_cache_get( 'persist2' ) === false );
} else {
	echo "  skip cross-request persistence (Yac not active)\n";
}

// ---------------------------------------------------------------------------
// WP_YAC_SKIP_EMPTY: empty values of unbounded-URL-derived keys stay
// request-local; stable per-entity negative caches keep being shared
// ---------------------------------------------------------------------------
$long_key = 'overlong-' . str_repeat( 'x', 60 );
$junk_key = 'get_page_by_path:' . md5( '/some/crawler/path' );
wp_cache_set( 'short-empty', array() );
wp_cache_set( $long_key, array() );
wp_cache_set( $junk_key, array() );
check( 'junk-path empty served within the request', wp_cache_get( $junk_key ) === array() );
if ( $yac_on ) {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	check( 'short-key empty array persists cross-request', wp_cache_get( 'short-empty' ) === array() );
	check( 'over-long entity-key empty array persists cross-request', wp_cache_get( $long_key ) === array() );
	check( 'junk-path empty does NOT persist cross-request', wp_cache_get( $junk_key ) === false );

	wp_cache_set( $junk_key, 'non-empty' );
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
	check( 'junk-path non-empty value persists cross-request', wp_cache_get( $junk_key ) === 'non-empty' );
} else {
	echo "  skip skip-empty persistence assertions (Yac not active)\n";
}

// ---------------------------------------------------------------------------
// switch_to_blog: keys carry no blog prefix anymore; blogs intentionally
// share the namespace (separate installs/prefixes when they must not)
// ---------------------------------------------------------------------------
$GLOBALS['wp_yac_test_multisite'] = true;
wp_cache_switch_to_blog( 1 );
wp_cache_set( 'blogkey', 'blog1' );
wp_cache_switch_to_blog( 2 );
check( 'blogs share the namespace by design (no per-blog prefix)', wp_cache_get( 'blogkey' ) === 'blog1' );
wp_cache_switch_to_blog( 1 );
check( 'switching back keeps access', wp_cache_get( 'blogkey' ) === 'blog1' );
$GLOBALS['wp_yac_test_multisite'] = false;

// ---------------------------------------------------------------------------
// flush_group: runtime-only flush (shared entries survive until TTL, by
// design, since Yac can't delete by prefix cheaply)
// ---------------------------------------------------------------------------
wp_cache_set( 'fg', 'v', 'grp' );
check( 'value present in group', wp_cache_get( 'fg', 'grp' ) === 'v' );
check( 'flush_group returns true', wp_cache_flush_group( 'grp' ) === true );
if ( $yac_on ) {
	check( 'shared copy still retrievable after flush_group (expected)', wp_cache_get( 'fg', 'grp' ) === 'v' );
} else {
	check( 'runtime-only: value gone after flush_group', wp_cache_get( 'fg', 'grp' ) === false );
}

// ---------------------------------------------------------------------------
// flush_runtime: clears request cache, shared store untouched
// ---------------------------------------------------------------------------
check( 'flush_runtime returns true', wp_cache_flush_runtime() === true );
if ( $yac_on ) {
	check( 'shared value survives flush_runtime', wp_cache_get( 'fg', 'grp' ) === 'v' );
}
check( 'supports flush_group reports false (runtime-only impl)', wp_cache_supports( 'flush_group' ) === false );
check( 'supports flush_runtime reports true', wp_cache_supports( 'flush_runtime' ) === true );

// ---------------------------------------------------------------------------
// wp_cache_supports
// ---------------------------------------------------------------------------
check( 'supports get_multiple', wp_cache_supports( 'get_multiple' ) === true );
check( 'does not support unknown', wp_cache_supports( 'bogus' ) === false );

// ---------------------------------------------------------------------------
// close
// ---------------------------------------------------------------------------
check( 'close returns true', wp_cache_close() === true );

// ---------------------------------------------------------------------------
// WP_YAC_KEY_PREFIX: cosmetic, sanitized to [A-Za-z0-9_], capped at 6 chars.
// The prefix lives in the Yac instance prefix, so read it back via
// reflection on the private storage_prefix property.
// ---------------------------------------------------------------------------
$storage_prefix = function ( $instance = null ) {
	if ( null === $instance ) {
		$instance = new WP_Object_Cache();
	}
	$r = new ReflectionProperty( 'WP_Object_Cache', 'storage_prefix' );
	if ( PHP_VERSION_ID < 80100 ) {
		$r->setAccessible( true );
	}
	return $r->getValue( $instance );
};

check( 'storage prefix starts with default wp', 0 === strpos( $storage_prefix(), 'wp' ) );

/* constants can't be redefined, so each prefix gets its own subprocess */
$prefix_probe = function ( $prefix ) {
	$script = sprintf(
		'$GLOBALS["table_prefix"]="wp_"; define("ABSPATH", sys_get_temp_dir() . "/"); define("WP_YAC_KEY_PREFIX", %s); require %s; $r = new ReflectionProperty("WP_Object_Cache", "storage_prefix"); if (PHP_VERSION_ID < 80100) { $r->setAccessible(true); } echo $r->getValue(new WP_Object_Cache());',
		var_export( $prefix, true ),
		var_export( __DIR__ . '/../object-cache.php', true )
	);
	$out = shell_exec( 'php -r ' . escapeshellarg( $script ) . ' 2>&1' );

	return is_string( $out ) ? trim( $out ) : '';
};

check( 'prefix longer than 6 chars is truncated', 0 === strpos( $prefix_probe( 'abcdefgh' ), 'abcdef' ) );
check( 'invalid chars stripped from prefix', 0 === strpos( $prefix_probe( 'a:b-c' ), 'abc' ) );
check( 'same prefix => same storage prefix', $prefix_probe( 'ab' ) === $prefix_probe( 'ab' ) && '' !== $prefix_probe( 'ab' ) );

echo "\n----------------------------------------\n";
echo "passed: $passed, failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
