<?php
/**
 * WP-CLI surface test: stub WP_CLI enough to load yac-ocache.php's command
 * class, then exercise the drop-in subcommands against a temporary
 * WP_CONTENT_DIR.
 *
 * Run: php tests/cli.php
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
$tmp = sys_get_temp_dir() . '/yac-ocache-cli-' . getmypid();
@mkdir( $tmp, 0777, true );
@mkdir( $tmp . '/wp-content', 0777, true );

define( 'ABSPATH', $tmp . '/' );
define( 'WP_CONTENT_DIR', $tmp . '/wp-content' );
file_put_contents( $tmp . '/index.php', "<?php\n" );

// The plugin only defines its command class under WP-CLI.
define( 'WP_CLI', true );

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
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function wp_delete_file( $f ) { @unlink( $f ); }
function __( $s, $d = '' ) { return $s; }
function esc_html__( $s, $d = '' ) { return $s; }
function number_format_i18n( $n, $dec = 0 ) { return number_format( $n, $dec ); }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }

/* WP_CLI::error() halts the command; model that with an exception so a test
   can assert the refusal instead of taking down the whole run. */
class YAC_OCACHE_CLI_Halt extends Exception {}

class WP_CLI {
	public static $log = array();

	public static function add_command( $name, $class ) {
		self::$log[] = array( 'add_command', $name, $class );
	}
	public static function log( $m )     { self::$log[] = array( 'log', $m ); }
	public static function success( $m ) { self::$log[] = array( 'success', $m ); }
	public static function warning( $m ) { self::$log[] = array( 'warning', $m ); }
	public static function error( $m )   { self::$log[] = array( 'error', $m ); throw new YAC_OCACHE_CLI_Halt( $m ); }
	public static function confirm( $q, $assoc_args = array() ) {
		self::$log[] = array( 'confirm', $q );
		if ( empty( $assoc_args['yes'] ) ) {
			self::error( 'declined' );
		}
	}

	/* messages of one kind, most recent last */
	public static function messages( $kind ) {
		$out = array();
		foreach ( self::$log as $entry ) {
			if ( $entry[0] === $kind ) {
				$out[] = $entry[1];
			}
		}
		return $out;
	}
	public static function last( $kind ) {
		$all = self::messages( $kind );
		return $all ? end( $all ) : null;
	}
	public static function reset() { self::$log = array(); }
}

require __DIR__ . '/../yac-ocache.php';

$dest = WP_CONTENT_DIR . '/object-cache.php';

// --- Registration ------------------------------------------------------------

check( 'command class defined under WP_CLI', class_exists( 'YAC_OCACHE_CLI_Command' ) );

$registered = null;
foreach ( WP_CLI::$log as $entry ) {
	if ( 'add_command' === $entry[0] ) {
		$registered = $entry;
	}
}
check( 'registers the yac command', $registered && 'yac' === $registered[1] );

/* WP-CLI does NOT turn method_name into method-name on its own -- core's own
   Plugin_Command::is_installed() carries an explicit "@subcommand is-installed".
   Without the tag these would register as "wp yac update_dropin", so assert the
   tag is present rather than trusting the method name. */
$expected_tags = array(
	'deploy_dropin' => 'deploy-dropin',
	'update_dropin' => 'update-dropin',
	'remove_dropin' => 'remove-dropin',
);
foreach ( $expected_tags as $method => $subcommand ) {
	$exists = method_exists( 'YAC_OCACHE_CLI_Command', $method );
	check( "method $method exists", $exists );
	if ( ! $exists ) {
		continue;
	}
	$doc = ( new ReflectionMethod( 'YAC_OCACHE_CLI_Command', $method ) )->getDocComment();
	check(
		"$method is tagged @subcommand $subcommand",
		is_string( $doc ) && preg_match( '/@subcommand\s+' . preg_quote( $subcommand, '/' ) . '\s*$/m', $doc )
	);
}

$cmd = new YAC_OCACHE_CLI_Command();

// --- deploy-dropin -----------------------------------------------------------

WP_CLI::reset();
$cmd->deploy_dropin();
check( 'deploy-dropin writes the drop-in', file_exists( $dest ) );
check( 'deploy-dropin reports success', null !== WP_CLI::last( 'success' ) );
check( 'deploy-dropin records the deployed version', YAC_OCACHE_VERSION === get_option( 'yac_ocache_dropin_deployed' ) );

WP_CLI::reset();
$cmd->deploy_dropin();
check(
	'deploy-dropin on an existing Yac drop-in points at update-dropin',
	false !== strpos( (string) WP_CLI::last( 'success' ), 'wp yac update-dropin' )
);

// --- update-dropin -----------------------------------------------------------

/* age the deployed copy so the version comparison has something to report */
$current = file_get_contents( $dest );
file_put_contents( $dest, str_replace(
	"YAC_OCACHE_DROPIN_VERSION', '" . YAC_OCACHE_VERSION . "'",
	"YAC_OCACHE_DROPIN_VERSION', '0.9.0'",
	$current
) );
clearstatcache( true, $dest );
check( 'deployed drop-in reads as the aged version', '0.9.0' === yac_ocache_dropin_version() );

check(
	'status tells CLI users to run wp yac update-dropin',
	(bool) preg_grep( '/wp yac update-dropin/', array_column( yac_ocache_status(), 2 ) )
);

WP_CLI::reset();
$cmd->update_dropin();
clearstatcache( true, $dest );
check( 'update-dropin refreshes the deployed version', YAC_OCACHE_VERSION === yac_ocache_dropin_version() );
check(
	'update-dropin reports the version transition',
	(bool) preg_grep( '/v0\.9\.0 -> v' . preg_quote( YAC_OCACHE_VERSION, '/' ) . '/', WP_CLI::messages( 'log' ) )
);

// --- remove-dropin -----------------------------------------------------------

WP_CLI::reset();
try {
	$cmd->remove_dropin( array(), array() );
	check( 'remove-dropin without --yes halts', false );
} catch ( YAC_OCACHE_CLI_Halt $e ) {
	check( 'remove-dropin without --yes halts', true );
}
check( 'remove-dropin asked for confirmation', null !== WP_CLI::last( 'confirm' ) );
check( 'remove-dropin kept the drop-in when declined', file_exists( $dest ) );

WP_CLI::reset();
$cmd->remove_dropin( array(), array( 'yes' => true ) );
clearstatcache( true, $dest );
check( 'remove-dropin --yes removes the drop-in', ! file_exists( $dest ) );
check( 'remove-dropin clears the deployed option', false === get_option( 'yac_ocache_dropin_deployed' ) );

WP_CLI::reset();
try {
	$cmd->remove_dropin( array(), array( 'yes' => true ) );
	check( 'remove-dropin on a missing drop-in errors', false );
} catch ( YAC_OCACHE_CLI_Halt $e ) {
	check( 'remove-dropin on a missing drop-in errors', false !== strpos( $e->getMessage(), 'No drop-in' ) );
}

// --- foreign drop-in is never touched ----------------------------------------

file_put_contents( $dest, "<?php\n// some other cache plugin\n" );
clearstatcache( true, $dest );
check( 'foreign drop-in is not ours', ! yac_ocache_dropin_is_ours() );

WP_CLI::reset();
try {
	$cmd->deploy_dropin();
	check( 'deploy-dropin refuses to overwrite a foreign drop-in', false );
} catch ( YAC_OCACHE_CLI_Halt $e ) {
	check( 'deploy-dropin refuses to overwrite a foreign drop-in', false !== strpos( $e->getMessage(), 'foreign' ) );
}
check(
	'foreign drop-in survives deploy-dropin',
	false !== strpos( (string) file_get_contents( $dest ), 'some other cache plugin' )
);

WP_CLI::reset();
try {
	$cmd->remove_dropin( array(), array( 'yes' => true ) );
	check( 'remove-dropin refuses to delete a foreign drop-in', false );
} catch ( YAC_OCACHE_CLI_Halt $e ) {
	check( 'remove-dropin refuses to delete a foreign drop-in', false !== strpos( $e->getMessage(), 'not owned by Yac' ) );
}
check( 'foreign drop-in survives remove-dropin', file_exists( $dest ) );

// --- Cleanup -----------------------------------------------------------------

@unlink( $dest );
@unlink( $tmp . '/index.php' );
@rmdir( WP_CONTENT_DIR );
@rmdir( $tmp );

echo "\npassed: $passed, failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
