<?php
/**
 * Admin-page render test: stub WordPress, fake the Yac extension and render
 * yac_ocache_render_admin_page() across the health-verdict scenarios, asserting
 * on the key markup.
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
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/yac-ocache-render-' . getmypid() );
define( 'WP_CACHE', true );
@mkdir( WP_CONTENT_DIR, 0777, true );

$GLOBALS['yac_ocache_hooks'] = array();
function register_activation_hook( $f, $cb )   {}
function register_deactivation_hook( $f, $cb ) {}
function register_uninstall_hook( $f, $cb )    {}
function add_action( $hook, $cb )              {}
function plugin_dir_url( $f ) { return 'http://example.com/wp-content/plugins/yac-ocache-cache/'; }
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
function wp_create_nonce( $action = -1 ) { return 'x'; }
function wp_cache_supports( $feature ) { return 'get_multiple' === $feature; }
function size_format( $bytes, $dec = 0 ) { return round( $bytes / 1024, $dec ) . ' KB'; }
function apply_filters( $hook, $value ) { return $value; }
function get_bloginfo( $show ) { return '6.9-test'; }
function wp_rand() { return mt_rand(); }

// --- Storage fixture -----------------------------------------------------------
// yac_ocache_storage_info() checks $GLOBALS['yac_ocache_test_storage_info'] first, so
// the real extension (if loaded) is bypassed entirely. Same for the snapshot.

require __DIR__ . '/../yac-ocache.php';

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
		'start_time'          => time() - ( 3 * 86400 + 4 * 3600 + 120 ), /* 3 d 4 h 2 m */
	), $over );
}

function fake_snapshot( $over = array() ) {
	return array_merge( array(
		'entries'  => 1262,
		'bytes'    => 2000000,
		'occupied' => 26000000, /* well below the 64M pool */
		'own'      => 1262,
		'average'  => 20602,
		/* rows: [ v_len, size, key, hits, atime ]; hits/atime null on
		   older Yac builds (then has_meta is false and no Hottest tab) */
		'largest'  => array(
			array( 71300, 148000, 'wp_x:options:alloptions', 41, 1748160000 ),
			array( 50000, 95000, 'wp_x:posts:42', 23, 1748159000 ),
		),
		'has_meta' => true,
		'hits_max' => 1200,
		'hottest'  => array(
			array( 900, 1400, 'wp_x:posts:1', 1200, 1748160000 ),
			array( 2100, 4000, 'wp_x:options:alloptions', 890, 1748160000 ),
		),
		'groups'   => array(
			array( 'label' => 'options', 'n' => 900, 'bytes' => 18000000 ),
			array( 'label' => 'posts', 'n' => 250, 'bytes' => 5000000 ),
			array( 'label' => 'hashed (long keys)', 'n' => 112, 'bytes' => 3000000 ),
		),
	), $over );
}

function render_page() {
	ob_start();
	yac_ocache_render_admin_page();
	return ob_get_clean();
}

// --- Uptime formatting --------------------------------------------------------

check( 'uptime keeps the two largest units', '3 d 4 h' === yac_ocache_format_uptime( 3 * 86400 + 4 * 3600 + 120 ) );
check( 'uptime skips zero units', '42 m 7 s' === yac_ocache_format_uptime( 42 * 60 + 7 ) );
check( 'uptime floors to the second', '0 s' === yac_ocache_format_uptime( 0 ) );

// --- Scenario 1: green — slots and values far from full -----------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 50000,
	'miss'       => 3000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot();

$html = render_page();

check( 'health donut rendered', strpos( $html, 'yac-ocache-health-donut' ) !== false );
check( 'donut shows hit rate', strpos( $html, '94.3%' ) !== false );
check( 'healthy chip shown', strpos( $html, 'Healthy' ) !== false );
check( 'green renders no advice', strpos( $html, 'class="yac-ocache-advice' ) === false );
check( 'health bars rendered', strpos( $html, 'yac-ocache-bars' ) !== false );
check( 'missing drop-in reported in problem table', strpos( $html, 'FAIL' ) !== false );
check( 'configuration rendered as table', strpos( $html, 'yac-ocache-config-table' ) !== false );
check( 'config table lists directives', strpos( $html, 'yac.keys_memory_size' ) !== false );
check( 'legacy counters panel removed', strpos( $html, '>Counters<' ) === false );
check( 'memory contents panel rendered', strpos( $html, 'Shared memory contents' ) !== false );
check( 'largest keys are clickable', strpos( $html, 'yac-ocache-entry-inspect' ) !== false && strpos( $html, 'data-key="wp_x:options:alloptions"' ) !== false );
check( 'hottest tab rendered when dump reports hits', strpos( $html, 'yac-ocache-tab' ) !== false && strpos( $html, '>Hottest<' ) !== false );
check( 'hottest list hidden by default', strpos( $html, 'yac-ocache-entry-list is-hidden' ) !== false );
check( 'hottest caption names the hit ceiling', strpos( $html, 'top 1,200 hits' ) !== false );
check( 'entry inspector modal rendered', strpos( $html, 'yac-ocache-modal' ) !== false );
check( 'occupied metric uses padded size', strpos( $html, 'Occupied' ) !== false );
check( 'group pie rendered', strpos( $html, 'yac-ocache-pie' ) !== false );
check( 'config lists wp-config directives first', strpos( $html, 'WP_CACHE' ) < strpos( $html, 'yac.enable' ) );
check( 'legacy card row removed', strpos( $html, 'class="yac-ocache-cards' ) === false );
check( 'legacy values-health panel removed', strpos( $html, 'Values memory health' ) === false );
check( 'legacy recycle scare removed', strpos( $html, 'Memory pressure' ) === false );

// --- Scenario 1b: cold cache — warm-up state, no verdict ---------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 120,
	'hits'       => 600,
	'miss'       => 300,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 120, 'own' => 120 ) );

$html = render_page();

check( 'warm-up donut shows N/A', strpos( $html, '>N/A<' ) !== false );
check( 'warm-up donut sub caption', strpos( $html, '>warming up<' ) !== false );
check( 'warm-up chip shown', strpos( $html, 'Warming up' ) !== false );
check( 'warm-up explains the threshold', strpos( $html, 'Warming up — the cache just started' ) !== false );
check( 'warm-up shows lookups progress', strpos( $html, '(900 so far)' ) !== false );
check( 'warm-up renders no colored verdict advice', strpos( $html, 'class="yac-ocache-advice-warn' ) === false && strpos( $html, 'class="yac-ocache-advice-err' ) === false );
check( 'warm-up bars stay neutral', strpos( $html, 'background: #8c8f94' ) !== false );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_warm = ob_get_clean();
check( 'widget shows N/A while warming up', strpos( $widget_warm, '>N/A<' ) !== false && strpos( $widget_warm, 'warming up' ) !== false );

// --- Scenario 1c: warm-up threshold reached — the verdict starts --------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 120,
	'hits'       => 950,
	'miss'       => 50,
) );

$html = render_page();

check( 'verdict starts once the threshold is reached', strpos( $html, '95%' ) !== false && strpos( $html, 'Healthy' ) !== false );

// --- Scenario 2: keys full, hit rate 80% — yellow keys advice ------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 30100,
	'hits'       => 80000,
	'miss'       => 20000,
	'kicks'      => 9000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 30100, 'own' => 30100 ) );

$html = render_page();

check( 'keys-full yellow chip', strpos( $html, 'Attention' ) !== false );
check( 'keys advice shown', strpos( $html, 'Key slots full and hit rate below 90' ) !== false );
check( 'kicks bar carries miss attribution', strpos( $html, '% of misses' ) !== false );

// --- Scenario 3: keys full, hit rate 50% — red ---------------------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 30100,
	'hits'       => 50000,
	'miss'       => 50000,
	'kicks'      => 30000,
) );

$html = render_page();

check( 'red critical chip', strpos( $html, 'Critical' ) !== false );
check( 'strong keys advice shown', strpos( $html, 'Strongly raise' ) !== false );
check( 'red advice style', strpos( $html, 'yac-ocache-advice-err' ) !== false );

// --- Scenario 4: keys not full, values full — yellow values advice -------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 30000,
	'miss'       => 10000,
	'recycles'   => 8000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'occupied' => 64000000 ) );

$html = render_page();

check( 'values-full advice shown', strpos( $html, 'Keys not full but values full' ) !== false );

// --- Scenario 5: keys 49%, kicks far above uniform expectation, rate < 90 ----
// inserts = 16000 + 3000 = 19000, observed = 15.8%, expected = 0.488^4/5 ~= 1.1%

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 16000,
	'hits'       => 85000,
	'miss'       => 15000,
	'kicks'      => 3000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 16000, 'own' => 16000 ) );

$html = render_page();

check( 'distribution anomaly advice shown', strpos( $html, 'placement is unlucky' ) !== false );
check( 'distribution advice offers prefix re-roll', strpos( $html, 'YAC_OCACHE_KEY_PREFIX' ) !== false );
check( 'distribution advice offers more slots too', strpos( $html, 'yac.keys_memory_size' ) !== false );

// --- Scenario 5b: same kick creep but healthy rate stays green ----------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 16000,
	'hits'       => 95000,
	'miss'       => 5000,
	'kicks'      => 3000,
) );

$html = render_page();

check( 'harmless long-uptime kick creep stays green', strpos( $html, 'class="yac-ocache-advice' ) === false );

// --- Scenario 6: keys 80%, moderate kicks, eviction-driven misses --------------
// observed = 4000/30214 ~= 13.2% < 3*E(8.2%)=24.6% so distribution stays quiet;
// kicks/misses = 44% >= 1/3 with rate 87% -> early slot pressure

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 26214,
	'hits'       => 60000,
	'miss'       => 9000,
	'kicks'      => 4000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 26214, 'own' => 26214 ) );

$html = render_page();

check( 'early slot pressure advice shown', strpos( $html, 'slot pressure arrives early' ) !== false );

// --- Scenario 7: mostly foreign entries — shared-pool occupancy ----------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 20000,
	'hits'       => 50000,
	'miss'       => 3000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 20000, 'own' => 6000 ) );

$html = render_page();

check( 'shared-pool advice shown', strpos( $html, 'shared-pool occupancy' ) !== false );

// --- Scenario 7b: slots_used high-water vs live entries ------------------------
// slots_used counts every slot populated since the last flush, expired entries
// included; the Keys bar and the verdict must follow the live-entry count

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 31000,
	'hits'       => 95000,
	'miss'       => 5000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 12000, 'own' => 12000 ) );

$html = render_page();

check( 'Keys bar shows live entries, not the slots_used high-water', strpos( $html, '12,000 <small>/ 32,768</small>' ) !== false );
check( 'verdict ignores the slots_used high-water', strpos( $html, 'Healthy' ) !== false && strpos( $html, 'Key slots full' ) === false );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_diverged = ob_get_clean();
check( 'widget shows live entries too', strpos( $widget_diverged, '12,000 / 32,768' ) !== false );

// --- Scenario 8: drop-in version check -----------------------------------------

file_put_contents( YAC_OCACHE_DROPIN_DEST, file_get_contents( YAC_OCACHE_DROPIN_SOURCE ) );
$version = yac_ocache_dropin_version();
check( 'drop-in version parsed from file', $version === YAC_OCACHE_VERSION );

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 50000,
	'miss'       => 3000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot();

$html = render_page();
check( 'status shows drop-in up to date', strpos( $html, 'up to date' ) !== false );
check( 'no update button when versions match', strpos( $html, 'Update drop-in' ) === false );

// --- Scenario 9: fully healthy status collapses to the active bar ------------

$GLOBALS['yac_ocache_test_status'] = array(
	array( 'dropin', 'ok', 'object-cache.php drop-in deployed by Yac.' ),
	array( 'dropin_version', 'ok', 'Drop-in v1.0 is up to date.' ),
	array( 'wp_cache', 'ok', 'WP_CACHE is enabled.' ),
	array( 'extension', 'ok', 'Yac extension loaded (shared memory: 4M keys / 64M values).' ),
);

$html = render_page();
check( 'healthy status collapses to active bar', strpos( $html, 'Active' ) !== false );
check( 'healthy status shows no problem rows', strpos( $html, 'FAIL' ) === false && strpos( $html, '>WARN<' ) === false );
check( 'active bar keeps the short form', strpos( $html, 'running on Yac SHM' ) !== false );
check( 'active bar shows SHM uptime', strpos( $html, 'for 3 d 4 h' ) !== false );
check( 'round trip folded into Active bar', ! yac_ocache_backend_usable() || strpos( $html, 'round trip' ) !== false );

// --- Scenario 9b: old Yac builds without start_time degrade gracefully -------

unset( $GLOBALS['yac_ocache_test_storage_info']['start_time'] );

$html = render_page();
check( 'no uptime on Yac builds without start_time', strpos( $html, 'for 3 d 4 h' ) === false && strpos( $html, 'Active' ) !== false );

// --- Dashboard widget -------------------------------------------------------

ob_start();
yac_ocache_render_dashboard_widget();
$widget_html = ob_get_clean();
check( 'dashboard widget renders hit rate', strpos( $widget_html, 'hit rate' ) !== false );
check( 'dashboard widget links to full dashboard', strpos( $widget_html, 'Full dashboard' ) !== false );

// --- Entry inspector --------------------------------------------------------

class Fake_Yac {
	public $store = array();
	public function dump( $limit = 100 ) {
		$out = array();
		foreach ( $this->store as $k => $m ) {
			$m['key'] = $k;
			$out[]    = $m;
		}
		return $out;
	}
	public function get( $k ) {
		return isset( $this->store[ $k ] ) ? $this->store[ $k ]['value'] : false;
	}
	public function delete( $k ) {
		if ( isset( $this->store[ $k ] ) ) {
			unset( $this->store[ $k ] );
			return true;
		}
		return false;
	}
}

$fake = new Fake_Yac();
$fake->store['wp:options:alloptions'] = array(
	'value' => array( 'v' => array( 'blogname' => 'Test' ) ),
	'v_len' => 100,
	'size'  => 128,
	'ttl'   => 0,
);

$d = yac_ocache_entry_detail( $fake, 'wp:options:alloptions' );
check( 'inspector unwraps drop-in v wrapper', strpos( $d['content'], '"blogname": "Test"' ) !== false );
check( 'inspector reports never-expiring ttl', 0 === $d['ttl'] );
check( 'inspector reports missing atime as null', null === $d['atime'] );
check( 'inspector formats sizes', '100 B' === $d['v_len'] || '100 KB' === $d['v_len'] );
check( 'inspector reports c_len as null for uncompressed entries', null === $d['c_len'] );

$fake->store['wp:options:alloptions']['atime'] = 1234567890;
$d = yac_ocache_entry_detail( $fake, 'wp:options:alloptions' );
check( 'inspector passes atime through when the build has it', 1234567890 === $d['atime'] );

/* compressed entries (Yac >= 2.4.0 dumps): c_len is the stored compressed
   payload, v_len the original uncompressed length */
$fake->store['wp:options:alloptions']['c_len'] = 30000;
$fake->store['wp:options:alloptions']['v_len'] = 58470;
$d = yac_ocache_entry_detail( $fake, 'wp:options:alloptions' );
check( 'inspector reports c_len for compressed entries', '29.3 KB' === $d['c_len'] );
check( 'inspector keeps v_len as the original size', '57.1 KB' === $d['v_len'] );

$d = yac_ocache_entry_detail( $fake, 'wp:nope' );
check( 'inspector reports gone entry', $d['gone'] );

check( 'inspector delete removes the entry', yac_ocache_entry_delete( $fake, 'wp:options:alloptions' ) && ! isset( $fake->store['wp:options:alloptions'] ) );

// --- Dump for visual inspection ------------------------------------------------

if ( getenv( 'RENDER_HTML' ) ) {
	$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
		'slots_used' => 13119,
		'hits'       => 89918,
		'miss'       => 18455,
	) );
	$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 13119, 'own' => 13119 ) );

	$page = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Yac admin page preview</title>'
		. '<style>body { background: #f0f0f1; font-family: -apple-system, "Segoe UI", Roboto, sans-serif; margin: 20px; }</style>'
		. '</head><body>' . render_page() . '</body></html>';

	file_put_contents( getenv( 'RENDER_HTML' ), $page );
	echo "  html written to " . getenv( 'RENDER_HTML' ) . "\n";
}

echo "\npassed: $passed, failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
