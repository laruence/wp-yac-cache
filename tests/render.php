<?php
/**
 * Admin-page render test: stub WordPress, fake the Yac extension and render
 * wp_yac_render_admin_page() twice — healthy state and memory-pressure
 * state — asserting on the key markup.
 *
 * Run:
 *   php tests/render.php                       # assertions
 *   RENDER_HTML=/tmp/page.html php tests/render.php && open /tmp/page.html
 */

error_reporting( E_ALL );

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

// --- Minimal WordPress stubs -------------------------------------------------

define( 'ABSPATH', sys_get_temp_dir() . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/wp-yac-render-' . getmypid() );
define( 'WP_CACHE', true );
define( 'WP_CACHE_KEY_SALT', 'render-test-salt' );
@mkdir( WP_CONTENT_DIR, 0777, true );

$GLOBALS['wp_yac_hooks'] = array();
function register_activation_hook( $f, $cb )   {}
function register_deactivation_hook( $f, $cb ) {}
function register_uninstall_hook( $f, $cb )    {}
function add_action( $hook, $cb )              {}
function is_admin() { return true; }
function is_multisite() { return false; }
function current_user_can( $cap ) { return true; }
function wp_die( $msg ) { throw new RuntimeException( (string) $msg ); }
function update_option( $k, $v, $autoload = null ) { return true; }
function get_option( $k, $d = false ) { return $d; }
function delete_option( $k ) { return true; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
function get_transient( $k ) { return false; }
function delete_transient( $k ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function esc_html_e( $s, $d = '' ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr_e( $s, $d = '' ) { echo htmlspecialchars( (string) $s, ENT_QUOTES ); }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_js( $s ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( $n, $dec ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function wp_cache_supports( $feature ) { return 'get_multiple' === $feature; }
function size_format( $bytes, $dec = 0 ) { return round( $bytes / 1024, $dec ) . ' KB'; }
function apply_filters( $hook, $value ) { return $value; }
function get_bloginfo( $show ) { return '6.9-test'; }

// --- Storage fixture -----------------------------------------------------------
// wp_yac_storage_info() checks $GLOBALS['wp_yac_test_storage_info'] first, so
// the real extension (if loaded) is bypassed entirely.

require __DIR__ . '/../wp-yac.php';

function fake_info( $over = array() ) {
	return array_merge( array(
		'memory_size'         => 71303168,
		'slots_memory_size'   => 4194304,
		'values_memory_size'  => 67108864,
		'segment_size'        => 4194304,
		'segment_num'         => 16,
		'miss'                => 0,
		'hits'                => 0,
		'fails'               => 0,
		'kicks'               => 0,
		'recycles'            => 0,

		'slots_size'          => 32768,
		'slots_used'          => 0,
	), $over );
}

function render_page() {
	ob_start();
	wp_yac_render_admin_page();
	return ob_get_clean();
}

// --- Scenario 1: healthy ------------------------------------------------------

$GLOBALS['wp_yac_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 50000,
	'miss'       => 3000,
	'recycles'   => 0,
	'kicks'      => 0,
	'fails'      => 0,
) );

$html = render_page();

check( 'donut chart rendered', strpos( $html, 'wp-yac-donut' ) !== false );
check( 'donut shows slot usage percent', strpos( $html, '3.9%' ) !== false );
check( 'missing drop-in reported in problem table', strpos( $html, 'FAIL' ) !== false );
check( 'storage cards rendered', strpos( $html, 'wp-yac-cards' ) !== false );
check( 'hit rate shown', strpos( $html, '94.3%' ) !== false );
check( 'healthy advice shown', strpos( $html, 'Healthy' ) !== false );
check( 'no recycle warning when recycles=0', strpos( $html, 'Memory pressure' ) === false );
check( 'counter table rendered', strpos( $html, 'Counters' ) !== false );
check( 'configuration rendered as table', strpos( $html, 'wp-yac-config-table' ) !== false );
check( 'config table lists directives', strpos( $html, 'yac.values_memory_size' ) !== false );
check( 'misleading data-size metric removed', strpos( $html, 'Total Yac data size' ) === false );
check( 'per-request stats panel removed', strpos( $html, 'Operations by group' ) === false && strpos( $html, 'This request' ) === false );
check( 'diagnostics section rendered', strpos( $html, 'Diagnostics' ) !== false );
check( 'environment panel rendered', strpos( $html, 'Environment' ) !== false );
check( 'memory contents panel rendered', strpos( $html, 'Shared memory contents' ) !== false );
check( 'no flush-number orphan wording left', strpos( $html, 'flush number' ) === false );
if ( wp_yac_backend_usable() ) {
	check( 'self-test strip rendered', strpos( $html, 'wp-yac-selftest' ) !== false );
} else {
	echo "  skip self-test assertions (Yac backend not usable in this environment)\n";
}

// --- Scenario 2: under memory pressure ----------------------------------------

$GLOBALS['wp_yac_test_storage_info'] = fake_info( array(
	'slots_used' => 30100,
	'hits'       => 80000,
	'miss'       => 20000,
	'recycles'   => 150,
	'kicks'      => 0,
	'fails'      => 2,
) );

$html = render_page();

check( 'recycle warning shown', strpos( $html, 'Memory pressure' ) !== false );
check( 'suggests doubling values_memory_size', strpos( $html, '128 MB' ) !== false );
check( 'slot warning shown above 90%', strpos( $html, 'entries start getting kicked' ) !== false );

// --- Scenario 3: heavy pressure suggests larger pool --------------------------

$GLOBALS['wp_yac_test_storage_info'] = fake_info( array( 'recycles' => 200000000, 'hits' => 100, 'miss' => 1 ) );

$html = render_page();
check( 'suggestion capped at 256 MB', strpos( $html, '256 MB' ) !== false );

// --- Scenario 4: drop-in version check -----------------------------------------

file_put_contents( WP_YAC_DROPIN_DEST, file_get_contents( WP_YAC_DROPIN_SOURCE ) );
$version = wp_yac_dropin_version();
check( 'drop-in version parsed from file', $version === WP_YAC_VERSION );

$GLOBALS['wp_yac_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 50000,
	'miss'       => 3000,
) );

$html = render_page();
check( 'status shows drop-in up to date', strpos( $html, 'up to date' ) !== false );
check( 'no update button when versions match', strpos( $html, 'Update drop-in' ) === false );

// --- Scenario 5: fully healthy status collapses to the active bar ------------

$GLOBALS['wp_yac_test_status'] = array(
	array( 'dropin', 'ok', 'object-cache.php drop-in deployed by Yac.' ),
	array( 'dropin_version', 'ok', 'Drop-in v1.0 is up to date.' ),
	array( 'wp_cache', 'ok', 'WP_CACHE is enabled.' ),
	array( 'extension', 'ok', 'Yac extension loaded (shared memory: 4M keys / 64M values).' ),
	array( 'salt', 'ok', 'WP_CACHE_KEY_SALT is set.' ),
);

$html = render_page();
check( 'healthy status collapses to active bar', strpos( $html, 'Active' ) !== false );
check( 'healthy status shows no problem rows', strpos( $html, 'FAIL' ) === false && strpos( $html, '>WARN<' ) === false );

// --- Dump for visual inspection ------------------------------------------------

if ( getenv( 'RENDER_HTML' ) ) {
	$GLOBALS['wp_yac_test_storage_info'] = fake_info( array(
		'slots_used' => 13119,
		'hits'       => 89918,
		'miss'       => 18455,
		'recycles'   => 0,
		'kicks'      => 0,
		'fails'      => 0,
	) );

	$page = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Yac admin page preview</title>'
		. '<style>body { background: #f0f0f1; font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 20px; }</style>'
		. '</head><body>' . render_page() . '</body></html>';

	file_put_contents( getenv( 'RENDER_HTML' ), $page );
	echo "  html written to " . getenv( 'RENDER_HTML' ) . "\n";
}

echo "\npassed: $passed, failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
