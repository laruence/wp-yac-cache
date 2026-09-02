<?php
/**
 * Real-WordPress debug test for the renamed Yac Object Cache plugin.
 * Requires: wp-config.php with WP_DEBUG=true, plugin ACTIVE, drop-in deployed.
 * Run from the WP root (the script boots WP via getcwd()/wp-load.php):
 *   php -d yac.enable_cli=1 -d display_errors=0 -d log_errors=1 -d error_log=/tmp/wp-yac-test.log tests/live-wp-debug.php
 */
error_reporting( E_ALL & ~E_DEPRECATED );
ini_set( 'display_errors', 0 );

$checks = array();
function ck( $label, $cond ) {
	global $checks;
	$checks[] = (bool) $cond;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
}

require getcwd() . '/wp-load.php';

ck( 'WordPress booted under WP_DEBUG', function_exists( 'add_action' ) && defined( 'WP_DEBUG' ) && WP_DEBUG );

/* plugin active: yac-ocache.php loaded as part of the plugin list */
$active = (array) get_option( 'active_plugins', array() );
ck( 'plugin is in active_plugins', in_array( 'yac-ocache/yac-ocache.php', $active, true ) );
ck( 'new function yac_ocache_status() exists', function_exists( 'yac_ocache_status' ) );
ck( 'new class Yac_Ocache_Object_Cache exists', class_exists( 'Yac_Ocache_Object_Cache' ) );
ck( 'new constant YAC_OCACHE_VERSION defined', defined( 'YAC_OCACHE_VERSION' ) );
ck( 'old name wp_yac_status() gone', ! function_exists( 'wp_yac_status' ) );
ck( 'old constant WP_YAC_VERSION gone', ! defined( 'WP_YAC_VERSION' ) );

/* the activated drop-in (renamed, 1.2.0) serves WordPress */
global $wp_object_cache;
ck( '$wp_object_cache is Yac_Ocache_Object_Cache', $wp_object_cache instanceof Yac_Ocache_Object_Cache );
ck( 'yac backend active (shared memory)', ! empty( $wp_object_cache->yac_available ) );
ck( 'drop-in version matches the plugin', defined( 'YAC_OCACHE_DROPIN_VERSION' ) && YAC_OCACHE_DROPIN_VERSION === YAC_OCACHE_VERSION );
ck( 'wp_cache_set/get round trip', wp_cache_set( 'yac_ocache_test', 'hello-42', 'default' ) && wp_cache_get( 'yac_ocache_test', 'default' ) === 'hello-42' );
ck( 'value visible from a fresh instance (cross-request)', ( function () {
	$fresh = new Yac_Ocache_Object_Cache();
	return $fresh->get( 'yac_ocache_test', 'default' ) === 'hello-42';
} )() );
ck( 'delete works', wp_cache_delete( 'yac_ocache_test', 'default' ) && wp_cache_get( 'yac_ocache_test', 'default' ) === false );

/* status / health plumbing */
$states = array();
foreach ( yac_ocache_status() as $row ) {
	$states[ $row[0] ] = $row[1];
}
ck( 'status: dropin ok', isset( $states['dropin'] ) && 'ok' === $states['dropin'] );
ck( 'status: dropin_version ok', ! isset( $states['dropin_version'] ) || 'ok' === $states['dropin_version'] );
ck( 'status: wp_cache ok', isset( $states['wp_cache'] ) && 'ok' === $states['wp_cache'] );
ck( 'status: extension ok', isset( $states['extension'] ) && 'ok' === $states['extension'] );
ck( 'plugin reports operational', yac_ocache_is_operational() );
$st = yac_ocache_self_test();
ck( 'self test round trip', is_array( $st ) && ! empty( $st['ok'] ) );
ck( 'storage info available', is_array( yac_ocache_storage_info() ) );

/* a real front page renders with WP_DEBUG on. Mimic wp-blog-header.php:
   wp-load.php already ran above, so dispatch the main query and then hand
   off to the template loader — that is what renders the page. */
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', true );
}
$host = parse_url( get_option( 'siteurl' ), PHP_URL_HOST ) ?: 'localhost';
$_SERVER += array(
	'HTTP_HOST'       => $host,
	'REQUEST_URI'     => '/',
	'REQUEST_METHOD'  => 'GET',
	'SERVER_PROTOCOL' => 'HTTP/1.1',
	'QUERY_STRING'    => '',
);
if ( class_exists( 'WP' ) ) {
	$wp = new WP();
	ob_start();
	$wp->main();
	require ABSPATH . WPINC . '/template-loader.php';
	$html = ob_get_clean();
	/* a complete document means the theme template ran to the end;
	   a raw byte-length check is fragile against WP's own output */
	$rendered = false !== strpos( $html, '</html>' );
	ck( 'front page renders to a complete document', $rendered );
	ck( 'no PHP fatal/warning/notice in output', ! preg_match( '/(Fatal error|Parse error|Warning:|Notice:)/i', $html ) );
	if ( ! $rendered ) {
		echo "--- front page diagnostics ---\n";
		echo 'output length: ' . strlen( $html ) . "\n";
		echo substr( $html, 0, 500 ) . "\n";
		$wplog = defined( 'WP_DEBUG_LOG' ) ? WP_DEBUG_LOG : false;
		if ( is_string( $wplog ) && file_exists( $wplog ) ) {
			echo "--- WP_DEBUG_LOG tail (non-deprecated, last 20) ---\n";
			$lines = array_filter( file( $wplog, FILE_IGNORE_NEW_LINES ), function ( $l ) {
				return false === strpos( $l, 'Deprecated' );
			} );
			foreach ( array_slice( $lines, -20 ) as $line ) {
				echo $line . "\n";
			}
		}
	}
}

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
