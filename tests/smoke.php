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
	return ! empty( $GLOBALS['yac_ocache_test_multisite'] );
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
// stored false: coerced to 0 before hitting the backend (a stored false is
// indistinguishable from a miss for Yac::get()), so it reads back as 0
// ---------------------------------------------------------------------------
wp_cache_set( 'is_false', false );
$found = null;
$v = wp_cache_get( 'is_false', 'default', false, $found );
check( 'stored false is retrievable (found=true)', $found === true && $v === 0 );

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
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();
	check( 'value persists into a new instance (cross-request)', wp_cache_get( 'persist' ) === 'across-requests' );

	// Regression: within one request, add() then set() on the same key
	// must actually re-write shared memory. An old early-return in set()
	// short-circuited when the key was already marked written by add(),
	// leaving the stale add() value behind for the next request.
	wp_cache_add( 'readd', 'first' );
	wp_cache_set( 'readd', 'second' );
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();
	check( 'set after add overwrites shared value', wp_cache_get( 'readd' ) === 'second' );

	// And flush wipes shared memory, so a new instance after flush misses.
	wp_cache_set( 'persist2', 'x' );
	wp_cache_flush();
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();
	check( 'flush takes effect across instances', wp_cache_get( 'persist2' ) === false );
} else {
	echo "  skip cross-request persistence (Yac not active)\n";
}

// ---------------------------------------------------------------------------
// Raw/embedded storage: values go into Yac unwrapped so small scalars
// land embedded in the slot itself, no value block. false is coerced to
// 0 before writing (a stored false reads back like a miss — get()
// returns false for both), null stays the shared negative result
// (found=true, value=false).
// ---------------------------------------------------------------------------
if ( $yac_on ) {
	wp_cache_set( 'emb_int', 42 );
	wp_cache_set( 'emb_zero', 0 );
	wp_cache_set( 'emb_true', true );
	wp_cache_set( 'emb_str', 'short' );
	wp_cache_set( 'emb_empty_str', '' );
	wp_cache_set( 'emb_empty_arr', array() );
	wp_cache_set( 'emb_false', false );
	wp_cache_set( 'emb_null', null );

	/* fresh instance = new request: everything must come back from shm */
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();

	check( 'int survives cross-request', wp_cache_get( 'emb_int' ) === 42 );
	check( 'int 0 survives cross-request', wp_cache_get( 'emb_zero' ) === 0 );
	check( 'true survives cross-request', wp_cache_get( 'emb_true' ) === true );
	check( 'short string survives cross-request', wp_cache_get( 'emb_str' ) === 'short' );
	check( 'empty string survives cross-request', wp_cache_get( 'emb_empty_str' ) === '' );
	check( 'empty array survives cross-request', wp_cache_get( 'emb_empty_arr' ) === array() );

	$found = null;
	$v = wp_cache_get( 'emb_false', 'default', false, $found );
	check( 'stored false survives cross-request as 0', $found === true && $v === 0 );

	$found = null;
	$v = wp_cache_get( 'emb_null', 'default', false, $found );
	check( 'stored null survives cross-request as found-false', $found === true && $v === false );

	/* replace()/add() treat the stored 0 as an existing value */
	check( 'replace on stored-false key succeeds', wp_cache_replace( 'emb_false', 'nope' ) === true );
	check( 'add on stored-false key reports failure', wp_cache_add( 'emb_false', 'nope' ) === false );
	check( 'replace value visible', wp_cache_get( 'emb_false' ) === 'nope' );

	/* a probe with the same instance prefix inspects the shared store
	   directly (drop-in default prefix: wp:) */
	$probe  = new Yac( 'wp:' );
	$by_key = array();
	foreach ( (array) $probe->dump( -1 ) as $it ) {
		if ( isset( $it['key'] ) ) {
			$by_key[ $it['key'] ] = $it;
		}
	}

	if ( isset( $by_key['wp:default:emb_int']['embedded'] ) ) {
		check( 'small scalars stored embedded',
			$by_key['wp:default:emb_int']['embedded']
			&& $by_key['wp:default:emb_zero']['embedded']
			&& $by_key['wp:default:emb_str']['embedded']
			&& $by_key['wp:default:emb_empty_str']['embedded']
			&& $by_key['wp:default:emb_empty_arr']['embedded']
			&& $by_key['wp:default:emb_false']['embedded']
			&& $by_key['wp:default:emb_null']['embedded'] );
		check( 'embedded entries use no value-block memory', 0 === (int) $by_key['wp:default:emb_int']['size'] );
	} else {
		echo "  skip embedded-flag assertions (Yac build without dump metadata)\n";
	}
} else {
	echo "  skip raw/embedded storage assertions (Yac not active)\n";
}

// ---------------------------------------------------------------------------
// YAC_OCACHE_EMPTY_TTL: empty arrays (negative cache results) are shared
// with a lifetime cap instead of living forever; non-empty and explicit
// short expiries are untouched
// ---------------------------------------------------------------------------
$long_key = 'overlong-' . str_repeat( 'x', 60 );
$junk_key = 'get_page_by_path:' . md5( '/some/crawler/path' );
wp_cache_set( 'short-empty', array() );
wp_cache_set( $long_key, array() );
wp_cache_set( $junk_key, array() );
check( 'junk-path empty served within the request', wp_cache_get( $junk_key ) === array() );
if ( $yac_on ) {
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();
	check( 'short-key empty array persists cross-request', wp_cache_get( 'short-empty' ) === array() );
	check( 'over-long entity-key empty array persists cross-request', wp_cache_get( $long_key ) === array() );
	check( 'junk-path empty persists cross-request', wp_cache_get( $junk_key ) === array() );

	wp_cache_set( $junk_key, 'non-empty' );
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();
	check( 'junk-path non-empty value persists cross-request', wp_cache_get( $junk_key ) === 'non-empty' );

	/* the lifetime cap shows up in the shared store's ttl field */
	wp_cache_set( 'ttl-empty', array() );
	wp_cache_set( 'ttl-full', array( 1 ) );
	wp_cache_set( 'ttl-empty-str', '' );
	wp_cache_set( 'ttl-short', array(), '', 120 );
	$GLOBALS['wp_object_cache'] = new Yac_Ocache_Object_Cache();

	$probe  = new Yac( 'wp:' );
	$by_key = array();
	$pages  = ( defined( 'YAC_VERSION' ) && version_compare( YAC_VERSION, '2.4.0', '>=' ) );
	foreach ( (array) $probe->dump( $pages ? 1000 : -1 ) as $it ) {
		if ( isset( $it['key'] ) ) {
			$by_key[ $it['key'] ] = $it;
		}
	}
	$now = time();
	check( 'empty array written with the EMPTY_TTL cap',
		isset( $by_key['wp:default:ttl-empty'] )
		&& (int) $by_key['wp:default:ttl-empty']['ttl'] > $now
		&& (int) $by_key['wp:default:ttl-empty']['ttl'] <= $now + YAC_OCACHE_EMPTY_TTL );
	check( 'non-empty array keeps the original (no) expiry',
		isset( $by_key['wp:default:ttl-full'] ) && 0 === (int) $by_key['wp:default:ttl-full']['ttl'] );
	check( 'empty string keeps the original (no) expiry',
		isset( $by_key['wp:default:ttl-empty-str'] ) && 0 === (int) $by_key['wp:default:ttl-empty-str']['ttl'] );
	check( 'explicit shorter expiry wins over the cap',
		isset( $by_key['wp:default:ttl-short'] )
		&& (int) $by_key['wp:default:ttl-short']['ttl'] <= $now + 120 );
} else {
	echo "  skip empty-ttl persistence assertions (Yac not active)\n";
}

// ---------------------------------------------------------------------------
// switch_to_blog: keys carry no blog prefix anymore; blogs intentionally
// share the namespace (separate installs/prefixes when they must not)
// ---------------------------------------------------------------------------
$GLOBALS['yac_ocache_test_multisite'] = true;
wp_cache_switch_to_blog( 1 );
wp_cache_set( 'blogkey', 'blog1' );
wp_cache_switch_to_blog( 2 );
check( 'blogs share the namespace by design (no per-blog prefix)', wp_cache_get( 'blogkey' ) === 'blog1' );
wp_cache_switch_to_blog( 1 );
check( 'switching back keeps access', wp_cache_get( 'blogkey' ) === 'blog1' );
$GLOBALS['yac_ocache_test_multisite'] = false;

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
// YAC_OCACHE_KEY_PREFIX: cosmetic, sanitized to [A-Za-z0-9_], capped at 6 chars.
// The prefix lives in the Yac instance prefix, so read it back via
// reflection on the private storage_prefix property.
// ---------------------------------------------------------------------------
$storage_prefix = function ( $instance = null ) {
	if ( null === $instance ) {
		$instance = new Yac_Ocache_Object_Cache();
	}
	$r = new ReflectionProperty( 'Yac_Ocache_Object_Cache', 'storage_prefix' );
	if ( PHP_VERSION_ID < 80100 ) {
		$r->setAccessible( true );
	}
	return $r->getValue( $instance );
};

check( 'storage prefix starts with default wp', 0 === strpos( $storage_prefix(), 'wp' ) );

/* constants can't be redefined, so each prefix gets its own subprocess */
$prefix_probe = function ( $prefix ) {
	$script = sprintf(
		'$GLOBALS["table_prefix"]="wp_"; define("ABSPATH", sys_get_temp_dir() . "/"); define("YAC_OCACHE_KEY_PREFIX", %s); require %s; $r = new ReflectionProperty("Yac_Ocache_Object_Cache", "storage_prefix"); if (PHP_VERSION_ID < 80100) { $r->setAccessible(true); } echo $r->getValue(new Yac_Ocache_Object_Cache());',
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
