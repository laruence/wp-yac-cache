<?php
/**
 * Enqueue smoke test: stub WordPress enough to verify that yac-ocache.php
 * registers its CSS/JS through the standard enqueue API and that no
 * inline <script>/<style> tags are echoed by the notice renderer.
 *
 * Run: php tests/enqueue.php
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

// --- Temporary WordPress skeleton -------------------------------------------
$tmp = sys_get_temp_dir() . '/yac-ocache-enqueue-' . getmypid();
@mkdir( $tmp, 0777, true );
@mkdir( $tmp . '/wp-content', 0777, true );

define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );

// --- WP function stubs -------------------------------------------------------
$GLOBALS['yac_ocache_hooks']  = array();
$GLOBALS['yac_ocache_screen'] = null;
$GLOBALS['yac_ocache_enq']    = array();

function register_activation_hook( $f, $cb )   {}
function register_deactivation_hook( $f, $cb ) {}
function register_uninstall_hook( $f, $cb )    {}
function add_action( $hook, $cb )              { $GLOBALS['yac_ocache_hooks'][ $hook ] = $cb; }
function is_admin() { return true; }
function is_multisite() { return false; }
function get_current_screen() { return $GLOBALS['yac_ocache_screen']; }
function get_current_user_id() { return 1; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce( $a ) { return 'nonce-' . $a; }
function get_user_meta( $u, $k, $s = false ) { return ''; }
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
function __( $s, $d = '' ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( $n, $dec ); }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }

function wp_enqueue_style( $h, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
	$GLOBALS['yac_ocache_enq']['styles'][ $h ] = $src;
}
function wp_enqueue_script( $h, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['yac_ocache_enq']['scripts'][ $h ] = $src;
}
function wp_localize_script( $h, $name, $data ) {
	$GLOBALS['yac_ocache_enq']['localized'][ $name ] = $data;
}
function wp_print_scripts( $h = false ) {
	$GLOBALS['yac_ocache_enq']['printed'][] = $h;
}

// Load the plugin shell.
require __DIR__ . '/../yac-ocache.php';

// --- Tests -------------------------------------------------------------------

/* no drop-in + no WP_CACHE -> yac_ocache_status() yields err rows, so the
   status notice should be due */
check( 'status notice is due (error rows present)', yac_ocache_show_status_notice() === true );

/* generic admin screen (e.g. the dashboard): only the notice script loads */
$GLOBALS['yac_ocache_screen'] = (object) array( 'id' => 'dashboard' );
yac_ocache_admin_enqueue_scripts( 'index.php' );
check( 'notice JS enqueued on any admin page', isset( $GLOBALS['yac_ocache_enq']['scripts']['yac-ocache-notice'] ) );
check( 'YAC_OCACHE_CONFIG localized', isset( $GLOBALS['yac_ocache_enq']['localized']['YAC_OCACHE_CONFIG'] ) );
$cfg = isset( $GLOBALS['yac_ocache_enq']['localized']['YAC_OCACHE_CONFIG'] ) ? $GLOBALS['yac_ocache_enq']['localized']['YAC_OCACHE_CONFIG'] : array();
check( 'config carries ajaxUrl', isset( $cfg['ajaxUrl'] ) && false !== strpos( $cfg['ajaxUrl'], 'admin-ajax.php' ) );
check( 'config carries both nonces', isset( $cfg['noticeNonce'], $cfg['entryNonce'] ) );
check( 'admin CSS NOT enqueued off the plugin page', ! isset( $GLOBALS['yac_ocache_enq']['styles']['yac-ocache-admin'] ) );
check( 'admin JS NOT enqueued off the plugin page', ! isset( $GLOBALS['yac_ocache_enq']['scripts']['yac-ocache-admin'] ) );

/* the plugin's own tools page: CSS + admin JS join */
$GLOBALS['yac_ocache_screen'] = (object) array( 'id' => 'tools_page_yac-ocache' );
yac_ocache_admin_enqueue_scripts( 'tools.php' );
check( 'admin CSS enqueued on the plugin page', isset( $GLOBALS['yac_ocache_enq']['styles']['yac-ocache-admin'] ) );
check( 'admin JS enqueued on the plugin page', isset( $GLOBALS['yac_ocache_enq']['scripts']['yac-ocache-admin'] ) );
check( 'asset URLs use YAC_OCACHE_PLUGIN_URL', 0 === strpos( $GLOBALS['yac_ocache_enq']['styles']['yac-ocache-admin'], YAC_OCACHE_PLUGIN_URL . 'assets/' ) );

/* the status notice prints markup only — no inline script; back on a
   generic screen, because the plugin's own page suppresses the notice */
$GLOBALS['yac_ocache_screen'] = (object) array( 'id' => 'dashboard' );
ob_start();
yac_ocache_status_notice();
$notice_html = ob_get_clean();
check( 'notice markup printed', false !== strpos( $notice_html, 'yac-ocache-status-notice' ) );
check( 'notice prints no <script> tag', false === strpos( $notice_html, '<script' ) );
check( 'notice prints no <style> tag', false === strpos( $notice_html, '<style' ) );

/* deferred print: the dismiss script materializes at footer time */
yac_ocache_admin_notice_script();
check( 'notice script force-printed when the notice shows', in_array( 'yac-ocache-notice', (array) ( $GLOBALS['yac_ocache_enq']['printed'] ?? array() ), true ) );

echo "\npassed: $passed, failed: $failed\n";
exit( $failed ? 1 : 0 );
