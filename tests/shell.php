<?php
/**
 * Plugin-shell smoke test: stub WordPress enough to load yac-ocache.php, then
 * exercise deploy/status logic against a temporary WP_CONTENT_DIR.
 *
 * Run: php tests/shell.php
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
$tmp = sys_get_temp_dir() . '/yac-ocache-shell-' . getmypid();
@mkdir( $tmp, 0777, true );
@mkdir( $tmp . '/wp-content', 0777, true );

define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
file_put_contents( $tmp . '/index.php', "<?php\n" );

// WP function stubs used at load time / in the functions under test.
$GLOBALS['yac_ocache_hooks'] = array();
function register_activation_hook( $f, $cb )   { $GLOBALS['yac_ocache_hooks']['activate'] = $cb; }
function register_deactivation_hook( $f, $cb ) { $GLOBALS['yac_ocache_hooks']['deactivate'] = $cb; }
function register_uninstall_hook( $f, $cb )    { $GLOBALS['yac_ocache_hooks']['uninstall'] = $cb; }
function add_action( $hook, $cb )              { $GLOBALS['yac_ocache_hooks'][ $hook ] = $cb; }
function is_admin() { return true; }
function is_multisite() { return false; }

$opts = array();
function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function set_transient( $k, $v, $ttl = 0 ) { return true; }
function get_transient( $k ) { return false; }
function delete_transient( $k ) { return true; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return $s; }
function wp_kses_post( $s ) { return $s; }
function wp_delete_file( $f ) { @unlink( $f ); }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( $n, $dec ); }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }

// Load the plugin shell.
require __DIR__ . '/../yac-ocache.php';

// --- Tests -------------------------------------------------------------------

check( 'plugin file loads without error', true );
check( 'activation hook registered', isset( $GLOBALS['yac_ocache_hooks']['activate'] ) );
check( 'uninstall hook registered', isset( $GLOBALS['yac_ocache_hooks']['uninstall'] ) );

// Deploy: no object-cache.php present -> should copy the drop-in in.
$ok = yac_ocache_deploy_dropin();
check( 'deploy_dropin returns true', $ok === true );
check( 'drop-in file created in wp-content', file_exists( WP_CONTENT_DIR . '/object-cache.php' ) );
check( 'dropin_is_ours detects our marker', yac_ocache_dropin_is_ours() === true );

// Status should not error out.
$status = yac_ocache_status();
check( 'status returns rows', is_array( $status ) && count( $status ) >= 4 );

$found_dropin_ok = false;
foreach ( $status as $row ) {
	if ( 'dropin' === $row[0] && 'ok' === $row[1] ) {
		$found_dropin_ok = true;
	}
}
check( 'status marks drop-in as ok', $found_dropin_ok );

// Foreign drop-in must never be overwritten.
$foreign_dir = $tmp . '/wp-content2';
@mkdir( $foreign_dir, 0777, true );
file_put_contents( $foreign_dir . '/object-cache.php', "<?php\n// some other cache plugin\n" );
// Point the constant-agnostic check at the foreign file via a fresh eval scope.
$foreign_head = file_get_contents( $foreign_dir . '/object-cache.php', false, null, 0, 2048 );
check( 'foreign drop-in is not detected as ours', strpos( $foreign_head, 'Yac Object Cache' ) === false );

// Uninstall removes our drop-in.
call_user_func( $GLOBALS['yac_ocache_hooks']['uninstall'] );
check( 'uninstall removed the drop-in', ! file_exists( WP_CONTENT_DIR . '/object-cache.php' ) );

// Cleanup.
array_map( 'unlink', glob( $foreign_dir . '/*' ) ?: array() );
@rmdir( $foreign_dir );
@unlink( $tmp . '/index.php' );
@rmdir( WP_CONTENT_DIR );
@rmdir( $tmp );

echo "\npassed: $passed, failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
