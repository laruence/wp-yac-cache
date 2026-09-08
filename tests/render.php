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
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
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
function get_option( $k, $d = false ) {
	if ( YAC_OCACHE_SAMPLE_OPTION === $k && isset( $GLOBALS['yac_ocache_test_samples'] ) ) {
		return $GLOBALS['yac_ocache_test_samples'];
	}
	return $d;
}
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
function date_i18n( $fmt, $ts = false ) { return date( $fmt, false === $ts ? time() : $ts ); }
function wp_json_encode( $data, $flags = 0, $depth = 512 ) { return json_encode( $data, $flags, $depth ); }

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

function yac_ocache_decode_chart_data( $html ) {
	if ( ! preg_match( '~<script[^>]*id="yac-ocache-chart-data"[^>]*>(.*?)</script>~s', $html, $m ) ) {
		return null;
	}
	$data = json_decode( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ), true );
	return is_array( $data ) ? $data : null;
}

function fake_samples( $over = null ) {
	if ( null !== $over ) {
		return $over;
	}
	$now    = time();
	$start  = $now - 7 * 86400;
	$samples = array();
	$hits     = 0;
	$miss     = 0;
	$kicks    = 0;
	$recycles = 0;
	for ( $t = $start; $t <= $now; $t += 900 ) {
		$hits  += 94;
		$miss  += 6;
		$kicks += 1;
		$samples[] = array(
			'time'       => $t,
			'hits'       => $hits,
			'miss'       => $miss,
			'fails'      => 0,
			'kicks'      => $kicks,
			'recycles'   => $recycles,
			'start_time' => $start,
		);
	}
	return $samples;
}

// --- Uptime formatting --------------------------------------------------------

check( 'uptime keeps the two largest units', '3 d 4 h' === yac_ocache_format_uptime( 3 * 86400 + 4 * 3600 + 120 ) );
check( 'uptime skips zero units', '42 m 7 s' === yac_ocache_format_uptime( 42 * 60 + 7 ) );
check( 'uptime floors to the second', '0 s' === yac_ocache_format_uptime( 0 ) );

// --- 24-hour counter windows ---------------------------------------------------

$window_now   = time();
$window_epoch = $window_now - 4 * DAY_IN_SECONDS;
$window_samples = array(
	array( 'time' => $window_now - DAY_IN_SECONDS - 900, 'hits' => 1000, 'miss' => 100, 'fails' => 0, 'kicks' => 10, 'recycles' => 1, 'start_time' => $window_epoch ),
	array( 'time' => $window_now - 900, 'hits' => 4500, 'miss' => 450, 'fails' => 0, 'kicks' => 60, 'recycles' => 5, 'start_time' => $window_epoch ),
);
$window_info = fake_info( array( 'hits' => 5000, 'miss' => 500, 'fails' => 8, 'kicks' => 70, 'recycles' => 6, 'start_time' => $window_epoch ) );
$window_24h  = yac_ocache_hitrate_windows( $window_samples, $window_info, array( '24h' => DAY_IN_SECONDS ) )['24h'];
check( '24h window uses the cumulative hit/miss delta', 4000 === $window_24h['hits'] && 400 === $window_24h['miss'] && abs( $window_24h['rate'] - ( 4000 / 4400 * 100 ) ) < 0.0001 );
check( '24h window uses the cumulative event deltas', 60 === $window_24h['kicks'] && 8 === $window_24h['fails'] && 5 === $window_24h['recycles'] );
check( 'complete counter baselines report the full window', $window_24h['kicks_complete'] && $window_24h['fails_complete'] && $window_24h['recycles_complete'] && DAY_IN_SECONDS === $window_24h['kicks_observed_seconds'] );
$legacy_kick_samples = $window_samples;
unset( $legacy_kick_samples[0]['kicks'] );
$legacy_24h = yac_ocache_hitrate_windows( $legacy_kick_samples, $window_info, array( '24h' => DAY_IN_SECONDS ) )['24h'];
check( 'missing legacy kicks use the first known baseline', 10 === $legacy_24h['kicks'] && ! $legacy_24h['kicks_complete'] && $legacy_24h['kicks_observed_seconds'] >= 899 );
check( 'complete window is marked available and complete', $window_24h['available'] && $window_24h['complete'] && DAY_IN_SECONDS === $window_24h['observed_seconds'] );

$short_epoch = $window_now - 2 * HOUR_IN_SECONDS;
$short_samples = array(
	array( 'time' => $short_epoch, 'hits' => 10, 'miss' => 2, 'fails' => 0, 'kicks' => 1, 'recycles' => 0, 'start_time' => $short_epoch ),
);
$short_info = fake_info( array( 'hits' => 110, 'miss' => 12, 'kicks' => 4, 'recycles' => 1, 'start_time' => $short_epoch ) );
$short_24h  = yac_ocache_hitrate_windows( $short_samples, $short_info, array( '24h' => DAY_IN_SECONDS ) )['24h'];
check( 'short current epoch reports its actual observed duration', $short_24h['available'] && ! $short_24h['complete'] && $short_24h['observed_seconds'] >= 2 * HOUR_IN_SECONDS - 1 && 100 === $short_24h['hits'] && 10 === $short_24h['miss'] );

$stale_samples = $short_samples;
$stale_samples[0]['start_time'] = $window_epoch;
$stale_24h = yac_ocache_hitrate_windows( $stale_samples, $short_info, array( '24h' => DAY_IN_SECONDS ) )['24h'];
check( 'retention shorter than 24 hours without a new start is unavailable', ! $stale_24h['available'] && null === $stale_24h['hits'] );

$reset_info = $window_info;
$reset_info['hits'] = 999;
$reset_24h = yac_ocache_hitrate_windows( $window_samples, $reset_info, array( '24h' => DAY_IN_SECONDS ) )['24h'];
check( 'counter regression makes the window unavailable', ! $reset_24h['available'] && null === $reset_24h['hits'] );

$changed_start_info = $window_info;
$changed_start_info['start_time'] = $window_epoch + 1;
$changed_start_24h = yac_ocache_hitrate_windows( $window_samples, $changed_start_info, array( '24h' => DAY_IN_SECONDS ) )['24h'];
check( 'changed start time makes the window unavailable', ! $changed_start_24h['available'] && null === $changed_start_24h['hits'] );

$chart_now = time();
$chart_start = $chart_now - 2 * HOUR_IN_SECONDS;
$chart_samples = array(
	array( 'time' => $chart_now - 1800, 'hits' => 100, 'miss' => 10, 'fails' => 2, 'kicks' => 10, 'recycles' => 3, 'start_time' => $chart_start ),
	array( 'time' => $chart_now - 900, 'hits' => 120, 'miss' => 12, 'fails' => 5, 'kicks' => 12, 'recycles' => 7, 'start_time' => $chart_start ),
);
$chart_info = fake_info( array( 'hits' => 120, 'miss' => 12, 'fails' => 9, 'kicks' => 17, 'recycles' => 8, 'start_time' => $chart_start ) );
$chart_data = yac_ocache_chart_data( $chart_samples, $chart_info );
check( 'chart payload keeps all counter columns aligned', count( $chart_data['min']['t'] ) === count( $chart_data['min']['k'] ) && count( $chart_data['min']['t'] ) === count( $chart_data['min']['f'] ) && count( $chart_data['min']['t'] ) === count( $chart_data['min']['r'] ) && count( $chart_data['hr']['t'] ) === count( $chart_data['hr']['k'] ) && count( $chart_data['hr']['t'] ) === count( $chart_data['hr']['f'] ) && count( $chart_data['hr']['t'] ) === count( $chart_data['hr']['r'] ) );
check( 'chart payload emits per-sample event deltas', 3 === $chart_data['min']['f'][1] && 4 === $chart_data['min']['r'][1] );
check( 'live tail carries kick, fail, and recycle deltas', 5 === end( $chart_data['min']['k'] ) && 4 === end( $chart_data['min']['f'] ) && 1 === end( $chart_data['min']['r'] ) );

$event_only_info = fake_info( array( 'hits' => 120, 'miss' => 12, 'fails' => 11, 'kicks' => 12, 'recycles' => 10, 'start_time' => $chart_start ) );
$event_only_data = yac_ocache_chart_data( $chart_samples, $event_only_info );
check( 'event-only live delta enters the trailing chart vertex', 6 === end( $event_only_data['min']['f'] ) && 3 === end( $event_only_data['min']['r'] ) && 0 === end( $event_only_data['min']['h'] ) && 0 === end( $event_only_data['min']['m'] ) );

$legacy_chart_samples = $chart_samples;
unset( $legacy_chart_samples[0]['kicks'], $legacy_chart_samples[0]['fails'], $legacy_chart_samples[0]['recycles'] );
$legacy_chart_data = yac_ocache_chart_data( $legacy_chart_samples, $chart_info );
check( 'legacy counters remain unknown in their source intervals', null === $legacy_chart_data['min']['k'][0] && null === $legacy_chart_data['min']['f'][0] && null === $legacy_chart_data['min']['r'][0] && null === $legacy_chart_data['min']['k'][1] && null === $legacy_chart_data['min']['f'][1] && null === $legacy_chart_data['min']['r'][1] );
check( 'known live deltas survive unknown intervals in hourly buckets', 5 === end( $legacy_chart_data['hr']['k'] ) && 4 === end( $legacy_chart_data['hr']['f'] ) && 1 === end( $legacy_chart_data['hr']['r'] ) );

// --- Scenario 1: green — slots and values far from full -----------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 50000,
	'miss'       => 3000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot();
$GLOBALS['yac_ocache_test_samples']  = fake_samples();

$html = render_page();

check( 'trend chart rendered', strpos( $html, 'yac-ocache-chart' ) !== false );
check( 'health details popover is rendered hidden and labelled', strpos( $html, 'id="yac-ocache-health-popover"' ) !== false && strpos( $html, 'role="dialog"' ) !== false && strpos( $html, 'aria-labelledby="yac-ocache-health-title"' ) !== false && strpos( $html, 'data-yac-health-range' ) !== false );
check( 'healthy diagnosis does not infer memory pressure from hit rate alone', strpos( $html, 'Low hit rate alone does not justify more memory.' ) !== false );
check( 'range control offers today/yesterday/week', strpos( $html, 'data-yac-range="today"' ) !== false && strpos( $html, 'data-yac-range="yday"' ) !== false && strpos( $html, 'data-yac-range="week"' ) !== false );
check( 'metrics row carries the rate and five selectable counters', strpos( $html, 'data-yac-series="rate"' ) !== false && strpos( $html, 'data-yac-series="hits"' ) !== false && strpos( $html, 'data-yac-series="miss"' ) !== false && strpos( $html, 'data-yac-series="kicks"' ) !== false && strpos( $html, 'data-yac-series="fails"' ) !== false && strpos( $html, 'data-yac-series="recycles"' ) !== false );
check( 'count metric selectors use pressed button semantics', substr_count( $html, 'class="yac-ocache-metric is-selected"' ) === 5 && substr_count( $html, 'aria-pressed="true"' ) >= 5 );
check( 'chart-data JSON includes counter and event columns', ( $cd = yac_ocache_decode_chart_data( $html ) ) && isset( $cd['min']['t'], $cd['min']['k'], $cd['min']['f'], $cd['min']['r'], $cd['hr']['t'], $cd['hr']['k'], $cd['hr']['f'], $cd['hr']['r'], $cd['ranges'] ) );
check( 'chart describes selectable trends and event markers', strpos( $html, 'selectable hits, misses, and kicks trend lines plus recycle and failure event markers' ) !== false && strpos( $html, 'aria-live="polite"' ) !== false );
check( 'chart-data has both minute and hourly buckets', $cd && ! empty( $cd['min']['t'] ) && ! empty( $cd['hr']['t'] ) );
check( 'week range includes today through now', $cd && $cd['ranges']['week'][0] < $cd['ranges']['yday'][0] && $cd['ranges']['week'][1] === $cd['now'] && $cd['ranges']['week'][1] > $cd['ranges']['today'][0] );
check( 'green renders no advice', strpos( $html, 'class="yac-ocache-advice' ) === false );
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
check( 'group pie uses the cache status palette', strpos( $html, '#3675b5' ) !== false && strpos( $html, '#d86135' ) !== false && strpos( $html, '#1b9e77' ) !== false );
check( 'config lists wp-config directives first', strpos( $html, 'WP_CACHE' ) < strpos( $html, 'yac.enable' ) );
check( 'legacy card row removed', strpos( $html, 'class="yac-ocache-cards' ) === false );
check( 'legacy values-health panel removed', strpos( $html, 'Values memory health' ) === false );
check( 'legacy recycle scare removed', strpos( $html, 'Memory pressure' ) === false );

// --- Scenario 1b: cold cache — warm-up state, no verdict ---------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 120,
	'hits'       => 699,
	'miss'       => 300,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 120, 'own' => 120 ) );

$html = render_page();

check( 'warm-up explains the threshold', strpos( $html, 'Warming up — the cache just started' ) !== false );
check( '999 lookups remain in warm-up', strpos( $html, '(999 so far)' ) !== false );
check( 'warm-up renders the neutral info advice', strpos( $html, 'yac-ocache-advice-info' ) !== false );
check( 'warm-up renders no colored verdict advice', strpos( $html, 'class="yac-ocache-advice-warn' ) === false && strpos( $html, 'class="yac-ocache-advice-err' ) === false );
$warm_chart = yac_ocache_decode_chart_data( $html );
check( 'chart suppresses health verdicts during warm-up', $warm_chart && false === $warm_chart['healthReady'] );
check( 'chart omits the shared-memory status note', strpos( $html, 'Shared memory running since' ) === false );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_warm = ob_get_clean();
check( 'widget donut shows N/A while collecting history', strpos( $widget_warm, '>N/A<' ) !== false && strpos( $widget_warm, '>collecting history<' ) !== false );
check( 'widget omits the 24-hour history note', strpos( $widget_warm, 'Collecting 24-hour history' ) === false );
check( 'widget never falls back to cumulative counters while unavailable', strpos( $widget_warm, '699 / 300' ) === false && strpos( $widget_warm, '— / —' ) !== false );
check( 'widget shows the warming-up chip', strpos( $widget_warm, 'Warming up' ) !== false );

// --- Scenario 1c: warm-up threshold reached — the verdict starts --------------

$window_epoch = time() - DAY_IN_SECONDS - 900;
$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 120,
	'hits'       => 950,
	'miss'       => 50,
	'kicks'      => 7,
	'fails'      => 0,
	'recycles'   => 1,
	'start_time' => $window_epoch,
) );
$GLOBALS['yac_ocache_test_samples'] = array(
	array( 'time' => $window_epoch, 'hits' => 0, 'miss' => 0, 'fails' => 0, 'kicks' => 0, 'recycles' => 0, 'start_time' => $window_epoch ),
);

$html = render_page();

check( 'verdict starts green once the threshold is reached (no advice on the page)', strpos( $html, 'class="yac-ocache-advice' ) === false );
$ready_chart = yac_ocache_decode_chart_data( $html );
check( 'chart enables health verdicts at the warm-up threshold', $ready_chart && true === $ready_chart['healthReady'] );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_green = ob_get_clean();
check( 'widget shows the 95% rate and Healthy chip', strpos( $widget_green, '95%' ) !== false && strpos( $widget_green, 'Healthy' ) !== false );
check( 'widget displays 24h counter deltas', strpos( $widget_green, 'Hits / Misses (24 h)' ) !== false && strpos( $widget_green, '950 / 50' ) !== false && strpos( $widget_green, 'Kicks / Fails / Recycles (24 h)' ) !== false && strpos( $widget_green, '7 / 0 / 1' ) !== false );
check( 'widget displays known zero fails', strpos( $widget_green, '7 / 0 / 1' ) !== false );

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'hits'       => 3921364,
	'miss'       => 337592,
	'kicks'      => 4169,
	'recycles'   => 5,
	'start_time' => $window_epoch,
) );
ob_start();
yac_ocache_render_dashboard_widget();
$widget_compact = ob_get_clean();
check( 'widget compacts large 24h counters', strpos( $widget_compact, '3.9M / 337.6K' ) !== false && strpos( $widget_compact, '4.2K / 0 / 5' ) !== false );

// --- Scenario 2: keys full, hit rate 80% — yellow keys advice ------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 30100,
	'hits'       => 80000,
	'miss'       => 20000,
	'kicks'      => 9000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 30100, 'own' => 30100 ) );

$html = render_page();

check( 'keys advice shown', strpos( $html, 'Key slots are full and evictions reduce the hit rate' ) !== false );
check( 'keys-full renders the warn advice block', strpos( $html, 'yac-ocache-advice-warn' ) !== false );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_keys = ob_get_clean();
check( 'widget shows the Attention chip', strpos( $widget_keys, 'Attention' ) !== false );

// --- Scenario 3: keys full, hit rate 50% — red ---------------------------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 30100,
	'hits'       => 50000,
	'miss'       => 50000,
	'kicks'      => 30000,
) );

$html = render_page();

check( 'strong keys advice shown', strpos( $html, 'Cache thrashing' ) !== false && strpos( $html, 'yac.keys_memory_size' ) !== false );
check( 'red advice style', strpos( $html, 'yac-ocache-advice-err' ) !== false );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_red = ob_get_clean();
check( 'widget shows the Critical chip', strpos( $widget_red, 'Critical' ) !== false );

// --- Scenario 4: keys not full, values full — yellow values advice -------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 30000,
	'miss'       => 10000,
	'recycles'   => 8000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'occupied' => 64000000 ) );

$html = render_page();

check( 'values-full advice shown', strpos( $html, 'Values memory is full' ) !== false && strpos( $html, 'yac.values_memory_size' ) !== false );

// --- Scenario 4b: many recycles alone are not evidence of memory pressure -----

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 1262,
	'hits'       => 95000,
	'miss'       => 5000,
	'recycles'   => 8000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'occupied' => 26000000 ) );

$html = render_page();

check( 'recycles alone do not trigger values-memory advice', strpos( $html, 'Values memory is full' ) === false && strpos( $html, 'Increase <code>yac.values_memory_size</code>' ) === false );
check( 'recycles alone retain the no-capacity-pressure diagnosis', strpos( $html, 'No capacity pressure detected' ) !== false );

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

check( 'distribution anomaly advice shown', strpos( $html, 'Kicks are' ) !== false );
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

check( 'early slot pressure advice shown', strpos( $html, 'Evictions cause at least a third of misses' ) !== false );

// --- Scenario 7: mostly foreign entries — shared-pool occupancy ----------------

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 20000,
	'hits'       => 50000,
	'miss'       => 3000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 20000, 'own' => 6000 ) );

$html = render_page();

check( 'shared-pool advice shown', strpos( $html, 'Only 30% of entries belong to this site' ) !== false );

// --- Scenario 7b: slots_used high-water vs live entries ------------------------
// Health uses live entries because slots_used includes expired entries.

$GLOBALS['yac_ocache_test_storage_info'] = fake_info( array(
	'slots_used' => 31000,
	'hits'       => 95000,
	'miss'       => 5000,
) );
$GLOBALS['yac_ocache_test_snapshot'] = fake_snapshot( array( 'entries' => 12000, 'own' => 12000 ) );

$html = render_page();

check( 'contents count shows live entries, not the slots_used high-water', strpos( $html, '12,000 <small>/ 32,768 slots</small>' ) !== false );
check( 'verdict ignores the slots_used high-water', strpos( $html, 'class="yac-ocache-advice' ) === false && strpos( $html, 'Key slots full' ) === false );

ob_start();
yac_ocache_render_dashboard_widget();
$widget_diverged = ob_get_clean();
check( 'widget shows live entries too', strpos( $widget_diverged, '12,000 / 32,768' ) !== false );
check( 'widget labels live entries as key slots used', strpos( $widget_diverged, 'Key slots used' ) !== false );
check( 'widget shows cache uptime', strpos( $widget_diverged, 'Running for' ) !== false && strpos( $widget_diverged, '3 d 4 h' ) !== false );

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
check( 'dashboard widget degrades missing uptime gracefully', strpos( $widget_html, 'Running for' ) !== false && strpos( $widget_html, '>—<' ) !== false );
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
check( 'inspector omits unsupported access metadata', ! array_key_exists( 'access', $d ) );
check( 'inspector formats sizes', '100 B' === $d['v_len'] || '100 KB' === $d['v_len'] );
check( 'inspector reports c_len as null for uncompressed entries', null === $d['c_len'] );

$fake->store['wp:options:alloptions']['atime']    = 1234567890;
$fake->store['wp:options:alloptions']['hits']     = 42;
$fake->store['wp:options:alloptions']['embedded'] = true;
$d = yac_ocache_entry_detail( $fake, 'wp:options:alloptions' );
check( 'inspector groups supported access metadata', isset( $d['access'] ) && 1234567890 === $d['access']['atime'] && 42 === $d['access']['hits'] && true === $d['access']['embedded'] );

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
