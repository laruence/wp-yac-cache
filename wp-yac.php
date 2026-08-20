<?php
/**
 * Plugin Name: Yac Object Cache
 * Plugin URI: https://github.com/laruence/wordpress-yac-cache
 * Description: Yac (lock-free shared memory) backed object cache for WordPress. Auto-deploys the object-cache.php drop-in on activation. No external servers: the cache lives in shared memory inherited by PHP-FPM workers.
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.0
 * Author: Xinchen Hui
 * Author URI: https://www.laruence.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_YAC_VERSION', '1.0.0' );
define( 'WP_YAC_PLUGIN_FILE', __FILE__ );
define( 'WP_YAC_DROPIN_SOURCE', __DIR__ . '/object-cache.php' );
define( 'WP_YAC_DROPIN_DEST', WP_CONTENT_DIR . '/object-cache.php' );
define( 'WP_YAC_ADMIN_PAGE', 'wp-yac' );

register_activation_hook( __FILE__, 'wp_yac_activate' );
register_deactivation_hook( __FILE__, 'wp_yac_deactivate' );

add_action( 'admin_menu', 'wp_yac_admin_menu' );
add_action( 'admin_notices', 'wp_yac_admin_notices' );
add_action( 'admin_init', 'wp_yac_admin_init' );
add_action( 'debug_information', 'wp_yac_site_health_info' );
add_action( 'wp_ajax_wp_yac_dismiss_status_notice', 'wp_yac_ajax_dismiss_status_notice' );

/* the uninstall hook cannot live in the drop-in */
if ( is_admin() ) {
	register_uninstall_hook( __FILE__, 'wp_yac_uninstall' );
}

function wp_yac_activate() {
	if ( ! defined( 'WP_INSTALLING' ) || ! WP_INSTALLING ) {
		wp_yac_deploy_dropin();
	}

	set_transient( 'wp_yac_activated', 1, 60 );
}

function wp_yac_deactivate() {
	/* keep the drop-in: an object cache should not vanish just because
	   someone toggled a plugin; removal is an explicit action */
}

function wp_yac_uninstall() {
	if ( wp_yac_dropin_is_ours() ) {
		wp_delete_file( WP_YAC_DROPIN_DEST );
	}
	delete_option( 'wp_yac_dropin_deployed' );
}

/* copy the drop-in into wp-content/; never touches a foreign drop-in;
   $force rewrites even when the current drop-in is already ours */
function wp_yac_deploy_dropin( $force = false ) {
	$ours = wp_yac_dropin_is_ours();

	if ( $ours && ! $force ) {
		update_option( 'wp_yac_dropin_deployed', WP_YAC_VERSION, true );
		return true;
	}

	if ( ! $ours && file_exists( WP_YAC_DROPIN_DEST ) ) {
		/* another cache plugin owns it */
		update_option( 'wp_yac_dropin_deployed', '', true );
		return false;
	}

	if ( ! is_readable( WP_YAC_DROPIN_SOURCE ) ) {
		update_option( 'wp_yac_dropin_deployed', '', true );
		return false;
	}

	if ( ! defined( 'FS_CHMOD_FILE' ) ) {
		define( 'FS_CHMOD_FILE', ( fileperms( ABSPATH . 'index.php' ) & 0777 | 0644 ) );
	}

	$content = file_get_contents( WP_YAC_DROPIN_SOURCE );
	if ( false === $content || ! @file_put_contents( WP_YAC_DROPIN_DEST, $content ) ) {
		update_option( 'wp_yac_dropin_deployed', '', true );
		return false;
	}

	/* no explicit permission change: the drop-in inherits the
	   webserver-friendly mode from the umask at creation time */
	update_option( 'wp_yac_dropin_deployed', WP_YAC_VERSION, true );

	return true;
}

function wp_yac_dropin_is_ours() {
	if ( ! file_exists( WP_YAC_DROPIN_DEST ) ) {
		return false;
	}

	/* the loaded drop-in defines this constant */
	if ( defined( 'WP_YAC_DROPIN_VERSION' ) ) {
		return true;
	}

	/* fallback when the constant isn't loaded in this context */
	$head = file_get_contents( WP_YAC_DROPIN_DEST, false, null, 0, 2048 );

	return is_string( $head ) && false !== strpos( $head, 'Yac Object Cache' );
}

/* the version of the deployed drop-in, null when it is unreadable */
function wp_yac_dropin_version() {
	if ( ! file_exists( WP_YAC_DROPIN_DEST ) ) {
		return null;
	}

	/* the loaded drop-in defines this constant */
	if ( defined( 'WP_YAC_DROPIN_VERSION' ) ) {
		return WP_YAC_DROPIN_VERSION;
	}

	$head = file_get_contents( WP_YAC_DROPIN_DEST, false, null, 0, 4096 );
	if ( ! is_string( $head ) || ! preg_match( "/WP_YAC_DROPIN_VERSION', '([^']+)'/", $head, $m ) ) {
		return null;
	}

	return $m[1];
}

/* the drop-in's Yac instance prefix: WP_YAC_KEY_PREFIX (0-6 chars,
   sanitized) + 8 hex chars of the key salt. The drop-in builds the same
   string from the same wp-config.php constants, so nothing to sync */
function wp_yac_key_prefix() {
	$salt = defined( 'WP_CACHE_KEY_SALT' ) ? WP_CACHE_KEY_SALT : 'wp-yac-default-salt';
	$user = defined( 'WP_YAC_KEY_PREFIX' ) ? WP_YAC_KEY_PREFIX : 'wp_';

	return preg_replace( '/[^A-Za-z0-9_]/', '', substr( (string) $user, 0, 6 ) )
		. substr( hash( 'crc32b', $salt ), 0, 8 ) . ':';
}

/* one round-trip through shared memory (set/get/delete), timed in ms;
   reuses a fixed key: Yac's delete is a tombstone (the slot is only
   reclaimed on recycle), so random keys would leak a slot per page view */
function wp_yac_self_test() {
	if ( ! wp_yac_backend_usable() ) {
		return null;
	}

	$yac   = new Yac( wp_yac_key_prefix() );
	$key   = 'diag:self-test';
	$value = 'self-test-' . wp_rand();

	$started = microtime( true );

	$ok_set  = $yac->set( $key, $value, 60 );
	$ok_get  = ( $yac->get( $key ) === $value );
	$ok_del  = $yac->delete( $key );

	return array(
		'ok'      => $ok_set && $ok_get && $ok_del,
		'elapsed' => ( microtime( true ) - $started ) * 1000,
	);
}

/* entry-level statistics from Yac::dump() (null when unavailable);
   dump(-1) fetches everything — the default limit is 100 */
function wp_yac_memory_snapshot( $top = 10 ) {
	if ( ! wp_yac_backend_usable() ) {
		return null;
	}

	$yac     = new Yac();
	$entries = $yac->dump( -1 );
	if ( ! is_array( $entries ) ) {
		return null;
	}

	$total   = count( $entries );
	$bytes   = 0;
	$largest = array();

	foreach ( $entries as $entry ) {
		/* v_len is the serialized value length; 'size' is the padded
		   allocation and would overstate content usage */
		$size = isset( $entry['v_len'] ) ? (int) $entry['v_len'] : 0;
		$key  = isset( $entry['key'] ) ? $entry['key'] : '';

		$bytes    += $size;
		$largest[] = array( $size, $key );
	}

	rsort( $largest );

	return array(
		'entries' => $total,
		'bytes'   => $bytes,
		'average' => $total > 0 ? $bytes / $total : 0,
		'largest' => array_slice( $largest, 0, $top ),
	);
}

/* returns rows of [ key, ok|warn|err, message ] */
function wp_yac_status() {
	if ( isset( $GLOBALS['wp_yac_test_status'] ) ) {
		return $GLOBALS['wp_yac_test_status'];
	}

	$status = array();

	$dropin_exists = file_exists( WP_YAC_DROPIN_DEST );
	$ours          = wp_yac_dropin_is_ours();

	if ( $ours ) {
		$status[] = array( 'dropin', 'ok', 'object-cache.php drop-in deployed by Yac.' );

		$deployed = wp_yac_dropin_version();
		if ( null === $deployed ) {
			$status[] = array( 'dropin_version', 'warn', 'Could not determine the deployed drop-in version.' );
		} elseif ( version_compare( WP_YAC_VERSION, $deployed, '>' ) ) {
			$status[] = array( 'dropin_version', 'warn', sprintf(
				'A newer drop-in is available: plugin v%1$s, deployed v%2$s. Update it from the actions below.',
				WP_YAC_VERSION,
				$deployed
			) );
		} else {
			$status[] = array( 'dropin_version', 'ok', sprintf(
				'Drop-in v%s is up to date.',
				$deployed
			) );
		}
	} elseif ( $dropin_exists ) {
		$status[] = array( 'dropin', 'err', 'A foreign object-cache.php exists. Yac will not overwrite it; the other cache is in charge.' );
	} else {
		$status[] = array( 'dropin', 'err', 'object-cache.php drop-in is missing.' );
	}

	if ( ! defined( 'WP_CACHE' ) || ! WP_CACHE ) {
		$status[] = array( 'wp_cache', 'err', sprintf(
			"WP_CACHE is not enabled. Add %s to wp-config.php above the \"That's all\" line.",
			"<code>define( 'WP_CACHE', true );</code>"
		) );
	} else {
		$status[] = array( 'wp_cache', 'ok', 'WP_CACHE is enabled.' );
	}

	if ( extension_loaded( 'yac' ) ) {
		$status[] = array( 'extension', 'ok', sprintf(
			'Yac extension loaded (shared memory: %s).',
			esc_html( ini_get( 'yac.keys_memory_size' ) . ' keys / ' . ini_get( 'yac.values_memory_size' ) . ' values' )
		) );
	} else {
		$status[] = array( 'extension', 'err', 'Yac extension not loaded. Install it (pecl install yac) or the cache degrades to per-request memory.' );
	}

	$salt = defined( 'WP_CACHE_KEY_SALT' ) ? constant( 'WP_CACHE_KEY_SALT' ) : '';

	if ( '' === $salt || 'wp-yac-default-salt' === $salt ) {
		$status[] = array( 'salt', 'warn', sprintf(
			'Using the default key salt. Set a unique %s in wp-config.php, especially when multiple installs share this PHP pool.',
			"<code>WP_CACHE_KEY_SALT</code>"
		) );
	} else {
		$status[] = array( 'salt', 'ok', 'WP_CACHE_KEY_SALT is set.' );
	}

	if ( defined( 'WP_YAC_KEY_PREFIX' ) && strlen( WP_YAC_KEY_PREFIX ) > 6 ) {
		$status[] = array( 'prefix', 'warn', sprintf(
			'%1$s is %2$d chars long; only the first %3$d count. Shorter is better: every byte shrinks the room left for the logical key.',
			'<code>WP_YAC_KEY_PREFIX</code>',
			strlen( WP_YAC_KEY_PREFIX ),
			6
		) );
	}

	return $status;
}

/* true when the shared backend is fully wired up */
function wp_yac_is_operational() {
	foreach ( wp_yac_status() as $row ) {
		if ( in_array( $row[0], array( 'dropin', 'wp_cache', 'extension' ), true ) && 'ok' !== $row[1] ) {
			return false;
		}
	}

	return true;
}

/* true when the extension is loaded AND enabled (yac.enable=1);
   new Yac() throws when it isn't, so probe once per request */
function wp_yac_backend_usable() {
	static $usable = null;

	if ( null === $usable ) {
		if ( ! extension_loaded( 'yac' ) ) {
			$usable = false;
		} else {
			try {
				new Yac( wp_yac_key_prefix() );
				$usable = true;
			} catch ( Throwable $e ) {
				$usable = false;
			}
		}
	}

	return $usable;
}

/* storage metrics straight from the Yac extension (null when unavailable) */
function wp_yac_storage_info() {
	if ( isset( $GLOBALS['wp_yac_test_storage_info'] ) ) {
		return $GLOBALS['wp_yac_test_storage_info'];
	}

	if ( ! wp_yac_backend_usable() ) {
		return null;
	}

	$info = ( new Yac() )->info();

	return is_array( $info ) ? $info : null;
}

/* human readable byte size */
function wp_yac_format_bytes( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB' );
	$bytes = (float) $bytes;
	$i     = 0;

	while ( $bytes >= 1024 && $i < count( $units ) - 1 ) {
		$bytes /= 1024;
		$i++;
	}

	return round( $bytes, $i ? 2 : 0 ) . ' ' . $units[ $i ];
}

/* SVG donut chart; pure markup, no JS, no CDN dependency */
function wp_yac_donut( $used, $total, $color ) {
	$ratio = $total > 0 ? min( 1, $used / $total ) : 0;
	$pct   = round( $ratio * 100, 1 );
	$r     = 54;
	$circ  = 2 * M_PI * $r;
	$dash  = $circ * $ratio;

	return sprintf(
		'<svg class="wp-yac-donut" viewBox="0 0 140 140" role="img" aria-label="%1$s%%">'
		. '<circle class="wp-yac-donut-track" cx="70" cy="70" r="%2$d"/>'
		. '<circle class="wp-yac-donut-fill" cx="70" cy="70" r="%2$d" stroke="%3$s" stroke-dasharray="%4$s %5$s" transform="rotate(-90 70 70)"/>'
		. '<text class="wp-yac-donut-pct" x="70" y="66">%1$s%%</text>'
		. '<text class="wp-yac-donut-sub" x="70" y="86">%6$s</text>'
		. '</svg>',
		esc_attr( $pct ),
		$r,
		esc_attr( $color ),
		esc_attr( round( $dash, 2 ) ),
		esc_attr( round( $circ, 2 ) ),
		esc_html( number_format_i18n( $used ) . ' / ' . number_format_i18n( $total ) )
	);
}

/* rows of [ directive, current value, scope, description ] for the config table */
function wp_yac_config_rows() {
	return array(
		array(
			"define( 'WP_CACHE', true )",
			defined( 'WP_CACHE' ) && WP_CACHE ? 'enabled' : 'missing',
			'wp-config.php',
			'loads the object-cache.php drop-in',
		),
		array(
			"define( 'WP_CACHE_KEY_SALT', '…' )",
			( defined( 'WP_CACHE_KEY_SALT' ) && 'wp-yac-default-salt' !== constant( 'WP_CACHE_KEY_SALT' ) ) ? 'set' : 'default — should be changed',
			'wp-config.php',
			'unique prefix, required when installs share one PHP pool',
		),
		array(
			"define( 'WP_YAC_KEY_PREFIX', '…' )",
			sprintf(
				'%s%s',
				defined( 'WP_YAC_KEY_PREFIX' ) ? WP_YAC_KEY_PREFIX : '',
				defined( 'WP_YAC_KEY_PREFIX' ) ? '' : ' (' . 'default' . ')'
			),
			'wp-config.php',
			'cosmetic prefix of storage keys; 0-6 chars, shorter is better — edit wp-config.php to change it',
		),
		array(
			'yac.enable',
			ini_get( 'yac.enable' ) ? '1' : '0',
			'php.ini',
			'master switch of the extension',
		),
		array(
			'yac.keys_memory_size',
			ini_get( 'yac.keys_memory_size' ),
			'php.ini',
			'slot table size (~32K slots per 4M)',
		),
		array(
			'yac.values_memory_size',
			ini_get( 'yac.values_memory_size' ),
			'php.ini',
			'raise when recycles are reported',
		),
		array(
			"define( 'WP_YAC_DISABLE', true )",
			defined( 'WP_YAC_DISABLE' ) && WP_YAC_DISABLE ? 'enabled' : 'not set',
			'wp-config.php',
			'escape hatch: force runtime-only mode',
		),
	);
}

function wp_yac_admin_menu() {
	add_management_page(
		'Yac Object Cache',
		'Yac Object Cache',
		'manage_options',
		WP_YAC_ADMIN_PAGE,
		'wp_yac_render_admin_page'
	);
}

function wp_yac_admin_notices() {
	/* only right after activation, when WP redirects to plugins.php with
	   activate=1; a miss on this transient is otherwise paid on every
	   admin page load */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display; the actions that set it were nonce-verified
	if ( isset( $_GET['activate'] ) && get_transient( 'wp_yac_activated' ) ) {
		delete_transient( 'wp_yac_activated' );

		$url = '<a href="' . esc_url( admin_url( 'tools.php?page=' . WP_YAC_ADMIN_PAGE ) ) . '">' . esc_html( 'Tools → Yac Object Cache' ) . '</a>';

		if ( wp_yac_dropin_is_ours() ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
				'Yac: object-cache.php deployed. See status under %s.',
				$url // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above from esc_url() + esc_html()
			) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . sprintf(
				'Yac: could not deploy object-cache.php (a foreign drop-in exists, or wp-content is not writable). Check %s.',
				$url // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above from esc_url() + esc_html()
			) . '</p></div>';
		}
		return;
	}

	if ( ! wp_yac_is_operational() ) {
		wp_yac_status_notice();
	}
}

/* one combined dismissible notice for error-level status rows; kept off the
   plugin's own page (that page shows the same diagnostics in full) */
function wp_yac_status_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false !== strpos( $screen->id, WP_YAC_ADMIN_PAGE ) ) {
		return;
	}

	$errors = array();
	foreach ( wp_yac_status() as $row ) {
		if ( 'err' === $row[1] ) {
			$errors[] = $row[2];
		}
	}

	if ( ! $errors ) {
		return;
	}

	/* the dismissal sticks to this exact set of errors: a different
	   fingerprint means the situation changed, so show it again */
	$fingerprint = md5( implode( "\n", $errors ) );
	if ( get_user_meta( get_current_user_id(), 'wp_yac_notice_dismissed', true ) === $fingerprint ) {
		return;
	}

	echo '<div class="notice notice-error is-dismissible" id="wp-yac-status-notice"><p><strong>' . esc_html( 'Yac:' ) . '</strong> ' . wp_kses_post( implode( '<br>', $errors ) ) . '</p>'
		. '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . WP_YAC_ADMIN_PAGE ) ) . '">' . esc_html( 'Open the status page for details and fixes' ) . '</a></p>'
		. '</div>';

	?>
	<script>
	( function() {
		var notice = document.getElementById( 'wp-yac-status-notice' );
		if ( ! notice ) {
			return;
		}
		notice.querySelector( '.notice-dismiss' ).addEventListener( 'click', function() {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>' );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.send( 'action=wp_yac_dismiss_status_notice&_wpnonce=<?php echo esc_js( wp_create_nonce( 'wp_yac_dismiss_notice' ) ); ?>' );
		} );
	} )();
	</script>
	<?php
}

/* persist the dismissal; no privilege escalation: only the current user's
   meta is touched, and a dismissed notice stays dismissed until the set of
   errors changes */
function wp_yac_ajax_dismiss_status_notice() {
	check_ajax_referer( 'wp_yac_dismiss_notice' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}

	$errors = array();
	foreach ( wp_yac_status() as $row ) {
		if ( 'err' === $row[1] ) {
			$errors[] = $row[2];
		}
	}

	update_user_meta( get_current_user_id(), 'wp_yac_notice_dismissed', md5( implode( "\n", $errors ) ) );
	wp_send_json_success();
}

/* flush / redeploy / remove via admin-post */
function wp_yac_admin_init() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in the action handler below before any state change
	if ( ! isset( $_POST['wp_yac_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'wp_yac_admin' );

	$redirect = admin_url( 'tools.php?page=' . WP_YAC_ADMIN_PAGE );

	switch ( $_POST['wp_yac_action'] ) {
		case 'redeploy':
			$ok = wp_yac_deploy_dropin();
			wp_safe_redirect( add_query_arg( 'wp_yac_notice', $ok ? 'deployed' : 'deploy_failed', $redirect ) );
			exit;

		case 'update_dropin':
			$ok = wp_yac_deploy_dropin( true );
			wp_safe_redirect( add_query_arg( 'wp_yac_notice', $ok ? 'deployed' : 'deploy_failed', $redirect ) );
			exit;

		case 'flush':
			if ( wp_yac_is_operational() && function_exists( 'wp_cache_flush' ) ) {
				$result = wp_cache_flush();
			} else {
				$result = false;
			}
			wp_safe_redirect( add_query_arg( 'wp_yac_notice', $result ? 'flushed' : 'flush_failed', $redirect ) );
			exit;

		case 'remove':
			$removed = false;
			if ( wp_yac_dropin_is_ours() ) {
				$removed = wp_delete_file( WP_YAC_DROPIN_DEST ) && ! file_exists( WP_YAC_DROPIN_DEST );
			}
			wp_safe_redirect( add_query_arg( 'wp_yac_notice', $removed ? 'removed' : 'remove_failed', $redirect ) );
			exit;
	}
}

function wp_yac_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'You do not have permission to access this page.' ) );
	}

	$info = wp_yac_storage_info();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display; the actions that set it were nonce-verified
	if ( isset( $_GET['wp_yac_notice'] ) ) {
		$wp_yac_notice_key = sanitize_key( wp_unslash( $_GET['wp_yac_notice'] ) );
		$notices = array(
			'deployed'      => array( 'success', 'Drop-in deployed.' ),
			'deploy_failed' => array( 'error', 'Deployment failed: a foreign object-cache.php exists or wp-content is not writable.' ),
			'flushed'       => array( 'success', 'Object cache flushed: the entire Yac shared memory on this machine was cleared.' ),
			'flush_failed'  => array( 'error', 'Flush failed: the cache backend is not operational.' ),
			'removed'       => array( 'success', 'Drop-in removed.' ),
			'remove_failed' => array( 'error', 'Could not remove the drop-in (permissions, or it is not owned by Yac).' ),
		);
		$notice = isset( $notices[ $wp_yac_notice_key ] ) ? $notices[ $wp_yac_notice_key ] : null;
		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
	}
	?>
	<style>
		.wp-yac-wrap { max-width: 1100px; margin-top: 12px; }
		.wp-yac-cards { display: flex; gap: 14px; flex-wrap: wrap; margin: 16px 0; }
		.wp-yac-card { flex: 1 1 150px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; box-shadow: 0 1px 1px rgba(0, 0, 0, .04); }
		.wp-yac-card-num { font-size: 24px; font-weight: 600; line-height: 1.2; color: #1d2327; }
		.wp-yac-card-label { font-size: 12px; color: #646970; margin-top: 2px; }
		.wp-yac-grid { display: flex; gap: 14px; flex-wrap: wrap; }
		.wp-yac-panel { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px 20px; box-shadow: 0 1px 1px rgba(0, 0, 0, .04); }
		.wp-yac-panel > h2 { margin-top: 0; padding: 0; border: 0; font-size: 15px; }
		.wp-yac-panel-note { color: #646970; font-size: 12px; margin: -4px 0 12px; }
		.wp-yac-donut { width: 150px; height: 150px; display: block; margin: 4px auto; }
		.wp-yac-donut-track { fill: none; stroke: #f0f0f1; stroke-width: 13; }
		.wp-yac-donut-fill { fill: none; stroke-width: 13; stroke-linecap: round; }
		.wp-yac-donut-pct { font-size: 22px; font-weight: 600; fill: #1d2327; text-anchor: middle; }
		.wp-yac-donut-sub { font-size: 9px; fill: #646970; text-anchor: middle; }
		.wp-yac-legend { list-style: none; margin: 10px 0 0; padding: 0; font-size: 12px; color: #50575e; }
		.wp-yac-legend li { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
		.wp-yac-legend .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
		.wp-yac-bar-track { background: #f0f0f1; border-radius: 6px; height: 12px; overflow: hidden; margin: 6px 0 14px; }
		.wp-yac-bar-fill { display: block; height: 100%; border-radius: 6px; }
		.wp-yac-metric { margin-bottom: 14px; font-size: 13px; }
		.wp-yac-metric-label { display: flex; justify-content: space-between; margin-bottom: 2px; color: #50575e; }
		.wp-yac-metric-label strong { color: #1d2327; }
		.wp-yac-advice { display: flex; gap: 10px; border-radius: 8px; padding: 12px 14px; margin: 14px 0 0; font-size: 13px; line-height: 1.6; }
		.wp-yac-advice-good { background: #edfaef; border: 1px solid #b8e6bf; color: #1e7a31; }
		.wp-yac-advice-warn { background: #fcf9e8; border: 1px solid #f0e1a0; color: #8a6d00; }
		.wp-yac-advice strong { display: block; }
		.wp-yac-actions { margin-top: 18px; }
		.wp-yac-actions form { display: inline-block; margin-right: 8px; }
		.wp-yac-config-table { width: 100%; border-collapse: collapse; }
		.wp-yac-config-table th { text-align: left; font-size: 12px; color: #646970; padding: 7px 10px; border-bottom: 1px solid #f0f0f1; }
		.wp-yac-config-table td { border: 0; padding: 7px 10px; font-size: 13px; }
		.wp-yac-config-table td code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; white-space: nowrap; }
		.wp-yac-diag { max-width: 640px; }
		.wp-yac-diag td { border: 0; padding: 7px 10px; font-size: 13px; }
		.wp-yac-diag td:first-child { color: #646970; width: 160px; }
		.wp-yac-diag code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; white-space: nowrap; }
		.wp-yac-selftest { display: inline-flex; align-items: center; gap: 8px; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin: 14px 0 0; }
		.wp-yac-selftest-pass { background: #edfaef; border: 1px solid #b8e6bf; color: #1e7a31; }
		.wp-yac-selftest-fail { background: #fcf0f1; border: 1px solid #f0c0c5; color: #d63638; }
		.wp-yac-op-list { list-style: none; margin: 8px 0 0; padding: 0; max-width: 420px; }
		.wp-yac-op-list li { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
		.wp-yac-op-list span { color: #646970; }
		.wp-yac-entry-bars { list-style: none; margin: 8px 0 0; padding: 0; }
		.wp-yac-entry-bars li { display: flex; align-items: center; gap: 8px; padding: 3px 0; font-size: 12px; }
		.wp-yac-entry-key { width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #50575e; flex: none; font-family: Consolas, Monaco, monospace; }
		.wp-yac-entry-bar-track { flex: 1; background: #f0f0f1; border-radius: 4px; height: 10px; overflow: hidden; }
		.wp-yac-entry-bar { display: block; height: 100%; background: #72aee6; border-radius: 4px; }
		.wp-yac-entry-bars strong { width: 76px; text-align: right; flex: none; }
		.wp-yac-note { color: #646970; font-size: 12px; margin-top: 8px; }
	</style>
	<div class="wrap wp-yac-wrap">
		<h1><?php echo esc_html( 'Yac Object Cache' ); ?></h1>

		<?php if ( null === $info ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( 'Yac extension is not loaded. The charts below need a running Yac backend.' ); ?></p></div>
		<?php endif; ?>

		<h2><?php echo esc_html( 'Status' ); ?></h2>
		<?php
		$wp_yac_problems = array_values( array_filter( wp_yac_status(), function ( $row ) {
			return 'ok' !== $row[1];
		} ) );
		?>
		<?php if ( empty( $wp_yac_problems ) ) : ?>
			<p style="display: inline-flex; align-items: center; gap: 8px; background: #edfaef; border: 1px solid #b8e6bf; color: #1e7a31; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin: 4px 0 0;">
				<span>✓</span><strong><?php echo esc_html( 'Active' ); ?></strong>
				<span><?php echo esc_html( '— the object cache is running on Yac shared memory.' ); ?></span>
			</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width: 960px">
				<tbody>
				<?php foreach ( $wp_yac_problems as $row ) : ?>
					<tr>
						<td style="width: 4em; font-weight: 600; color: <?php echo 'warn' === $row[1] ? '#996800' : '#d63638'; ?>">
							<?php echo esc_html( 'warn' === $row[1] ? 'WARN' : 'FAIL' ); ?>
						</td>
						<td><?php echo wp_kses_post( $row[2] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( null !== $info ) : ?>
			<?php
			$keys_used  = (int) $info['slots_used'];
			$keys_total = (int) $info['slots_size'];
			$keys_pct   = $keys_total > 0 ? $keys_used / $keys_total * 100 : 0;
			$keys_color = $keys_pct < 70 ? '#2271b1' : ( $keys_pct < 90 ? '#dba617' : '#d63638' );

			$values_total = (int) $info['values_memory_size'];
			$hits         = (int) $info['hits'];
			$misses       = (int) $info['miss'];
			$lookups      = $hits + $misses;
			$hit_rate     = $lookups > 0 ? $hits / $lookups * 100 : 0;
			$recycles     = (int) $info['recycles'];
			$kicks        = (int) $info['kicks'];
			$fails        = (int) $info['fails'];
			$ops_total    = $lookups + $fails;

			$metric_bars = array(
				array(
					'Recycles',
					'recycle events in values memory',
					$recycles,
					max( 1, $ops_total ),
				),
				array(
					'Kicks',
					'entries evicted from full slots',
					$kicks,
					max( 1, $ops_total ),
				),
				array(
					'Fails',
					'writes that could not be stored',
					$fails,
					max( 1, $ops_total ),
				),
			);

			$next_values = $values_total;
			while ( $next_values < 268435456 ) { /* cap the suggestion at 256M */
				$next_values *= 2;
				if ( $recycles * 100 <= $next_values ) {
					break;
				}
			}
			?>
			<h2><?php echo esc_html( 'Storage' ); ?></h2>
			<div class="wp-yac-cards">
				<div class="wp-yac-card">
					<div class="wp-yac-card-num"><?php echo esc_html( number_format_i18n( $keys_used ) ); ?> <span style="font-size: 14px; color: #646970">/ <?php echo esc_html( number_format_i18n( $keys_total ) ); ?></span></div>
					<div class="wp-yac-card-label"><?php echo esc_html( 'Key slots in use' ); ?></div>
				</div>
				<div class="wp-yac-card">
					<div class="wp-yac-card-num"><?php echo esc_html( number_format_i18n( $lookups ) ); ?></div>
					<div class="wp-yac-card-label"><?php echo esc_html( 'Lookups (hits + misses)' ); ?></div>
				</div>
				<div class="wp-yac-card">
					<div class="wp-yac-card-num"><?php echo esc_html( round( $hit_rate, 1 ) ); ?>%</div>
					<div class="wp-yac-card-label"><?php echo esc_html( 'Hit rate' ); ?></div>
				</div>
				<div class="wp-yac-card">
					<div class="wp-yac-card-num"><?php echo esc_html( number_format_i18n( $recycles ) ); ?></div>
					<div class="wp-yac-card-label"><?php echo esc_html( 'Value memory recycles' ); ?></div>
				</div>
				<div class="wp-yac-card">
					<div class="wp-yac-card-num"><?php echo esc_html( wp_yac_format_bytes( $values_total ) ); ?></div>
					<div class="wp-yac-card-label"><?php echo esc_html( 'Values memory pool' ); ?></div>
				</div>
			</div>

			<div class="wp-yac-grid">
				<div class="wp-yac-panel" style="flex: 1 1 260px">
					<h2><?php echo esc_html( 'Key slots' ); ?></h2>
					<p class="wp-yac-panel-note"><?php echo esc_html( sprintf( 'keys_memory_size = %s', ini_get( 'yac.keys_memory_size' ) ) ); ?></p>
					<?php echo wp_yac_donut( $keys_used, $keys_total, $keys_color ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					<ul class="wp-yac-legend">
						<li><span class="dot" style="background: <?php echo esc_attr( $keys_color ); ?>"></span><?php echo esc_html( sprintf( '%1$s in use (%2$s%%)', number_format_i18n( $keys_used ), number_format_i18n( round( $keys_pct, 1 ) ) ) ); ?></li>
						<li><span class="dot" style="background: #f0f0f1"></span><?php echo esc_html( sprintf( '%s free', number_format_i18n( $keys_total - $keys_used ) ) ); ?></li>
					</ul>
					<p class="wp-yac-note"><?php echo esc_html( 'Note: deleted keys keep their slot until a recycle reclaims it, and expired entries are only detected lazily on read — the counter counts everything still slotted.' ); ?></p>
					<?php if ( $keys_pct >= 90 ) : ?>
						<div class="wp-yac-advice wp-yac-advice-warn">
							<span>⚠</span>
							<div><?php echo wp_kses_post( sprintf( 'Slot usage above 90%% — entries start getting kicked. Raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ); ?></div>
						</div>
					<?php endif; ?>
				</div>

				<div class="wp-yac-panel" style="flex: 1 1 320px">
					<h2><?php echo esc_html( 'Values memory health' ); ?></h2>
					<p class="wp-yac-panel-note"><?php echo esc_html( sprintf( 'pool = %s (values_memory_size). Yac does not report the used bytes; health is judged by recycle pressure.', wp_yac_format_bytes( $values_total ) ) ); ?></p>

					<div class="wp-yac-metric">
						<div class="wp-yac-metric-label"><span><?php echo esc_html( 'Recycles — values memory freed old entries' ); ?></span><strong><?php echo esc_html( number_format_i18n( $recycles ) ); ?></strong></div>
						<div class="wp-yac-bar-track"><span class="wp-yac-bar-fill" style="width: <?php echo esc_attr( min( 100, $recycles / max( 1, $ops_total ) * 100 ) ); ?>%; background: <?php echo 0 === $recycles ? '#2271b1' : '#d63638'; ?>"></span></div>
					</div>
					<div class="wp-yac-metric">
						<div class="wp-yac-metric-label"><span><?php echo esc_html( 'Kicks — keys evicted from full slots' ); ?></span><strong><?php echo esc_html( number_format_i18n( $kicks ) ); ?></strong></div>
						<div class="wp-yac-bar-track"><span class="wp-yac-bar-fill" style="width: <?php echo esc_attr( min( 100, $kicks / max( 1, $ops_total ) * 100 ) ); ?>%; background: <?php echo 0 === $kicks ? '#2271b1' : '#dba617'; ?>"></span></div>
					</div>
					<div class="wp-yac-metric">
						<div class="wp-yac-metric-label"><span><?php echo esc_html( 'Fails — writes rejected (various causes)' ); ?></span><strong><?php echo esc_html( number_format_i18n( $fails ) ); ?></strong></div>
						<div class="wp-yac-bar-track"><span class="wp-yac-bar-fill" style="width: <?php echo esc_attr( min( 100, $fails / max( 1, $ops_total ) * 100 ) ); ?>%; background: <?php echo 0 === $fails ? '#2271b1' : '#dba617'; ?>"></span></div>
					</div>

					<?php if ( 0 === $recycles ) : ?>
						<div class="wp-yac-advice wp-yac-advice-good">
							<span>✓</span>
							<div><?php echo wp_kses_post( sprintf( '<strong>Healthy.</strong> No value-memory recycles since the FPM master started. The current <code>yac.values_memory_size</code> (%s) is sufficient.', esc_html( wp_yac_format_bytes( $values_total ) ) ) ); ?></div>
						</div>
					<?php else : ?>
						<div class="wp-yac-advice wp-yac-advice-warn">
							<span>⚠</span>
							<div><?php echo wp_kses_post( sprintf(
								'<strong>Memory pressure.</strong> %1$s recycle(s) recorded: values memory ran short and Yac reclaimed old entries. Raise <code>yac.values_memory_size</code> to <code>%2$s</code> (currently %3$s).',
								esc_html( number_format_i18n( $recycles ) ),
								esc_html( wp_yac_format_bytes( $next_values ) ),
								esc_html( wp_yac_format_bytes( $values_total ) )
							) ); ?></div>
						</div>
					<?php endif; ?>
				</div>

				<div class="wp-yac-panel" style="flex: 1 1 260px">
					<h2><?php echo esc_html( 'Counters' ); ?></h2>
					<p class="wp-yac-panel-note"><?php echo esc_html( sprintf( 'segments: %1$d × %2$s · since FPM master start', $info['segment_num'], wp_yac_format_bytes( $info['segment_size'] ) ) ); ?></p>
					<table class="widefat striped" style="border: 0">
						<tbody>
						<?php
						$counters = array(
							array( 'Hits', $hits ),
							array( 'Misses', $misses ),
							array( 'Hit rate', round( $hit_rate, 1 ) . '%' ),
							array( 'Fails', $fails ),
							array( 'Kicks', $kicks ),
							array( 'Recycles', $recycles ),
						);
						foreach ( $counters as $counter ) :
							?>
							<tr>
								<td style="border: 0; padding: 8px 10px"><?php echo esc_html( $counter[0] ); ?></td>
								<td style="border: 0; padding: 8px 10px; text-align: right; font-weight: 600"><?php echo esc_html( is_int( $counter[1] ) ? number_format_i18n( $counter[1] ) : $counter[1] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>

		<?php endif; ?>

		<h2><?php echo esc_html( 'Diagnostics' ); ?></h2>
		<?php $self_test = wp_yac_self_test(); ?>
		<?php if ( null !== $self_test ) : ?>
			<div class="wp-yac-selftest <?php echo $self_test['ok'] ? 'wp-yac-selftest-pass' : 'wp-yac-selftest-fail'; ?>">
				<span><?php echo $self_test['ok'] ? '✓' : '✗'; ?></span>
				<?php if ( $self_test['ok'] ) : ?>
					<span><?php echo esc_html( sprintf( 'Shared-memory round trip (set → get → delete) succeeded in %s ms.', number_format_i18n( $self_test['elapsed'], 3 ) ) ); ?></span>
				<?php else : ?>
					<span><?php echo esc_html( 'Shared-memory round trip failed: a value did not survive set/get. Check the Yac extension configuration.' ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="wp-yac-grid">
			<div class="wp-yac-panel" style="flex: 2 1 500px">
				<h2><?php echo esc_html( 'Environment' ); ?></h2>
				<table class="wp-yac-diag">
					<tbody>
					<tr>
						<td><?php echo esc_html( 'Plugin version' ); ?></td>
						<td><code><?php echo esc_html( WP_YAC_VERSION ); ?></code></td>
					</tr>
					<tr>
						<td><?php echo esc_html( 'Drop-in version' ); ?></td>
						<td>
							<?php $dropin_version = wp_yac_dropin_version(); ?>
							<?php if ( null === $dropin_version ) : ?>
								<code>—</code>
							<?php else : ?>
								<code><?php echo esc_html( $dropin_version ); ?></code>
								<?php if ( version_compare( WP_YAC_VERSION, $dropin_version, '>' ) ) : ?>
									<span style="color: #996800"> — <?php echo esc_html( 'outdated, update it from the actions below' ); ?></span>
								<?php else : ?>
									<span style="color: #1e7a31"> — <?php echo esc_html( 'up to date' ); ?></span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td>WordPress</td>
						<td><code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></td>
					</tr>
					<tr>
						<td>PHP</td>
						<td><code><?php echo esc_html( PHP_VERSION ); ?> (<?php echo esc_html( PHP_SAPI ); ?>)</code></td>
					</tr>
					<tr>
						<td><?php echo esc_html( 'Yac extension' ); ?></td>
						<td><code><?php echo esc_html( extension_loaded( 'yac' ) ? ( phpversion( 'yac' ) ? phpversion( 'yac' ) : 'loaded' ) : 'not loaded' ); ?></code></td>
					</tr>
					<?php if ( null !== $info ) : ?>
						<tr>
							<td><?php echo esc_html( 'Shared memory segments' ); ?></td>
							<td><code><?php echo esc_html( $info['segment_num'] . ' × ' . wp_yac_format_bytes( $info['segment_size'] ) ); ?></code></td>
						</tr>
					<?php endif; ?>
					<tr>
						<td><?php echo esc_html( 'Drop-in path' ); ?></td>
						<td><code><?php echo esc_html( WP_YAC_DROPIN_DEST ); ?></code></td>
					</tr>
					</tbody>
				</table>

				<h3 style="margin: 16px 0 6px; font-size: 13px"><?php echo esc_html( 'Configuration' ); ?></h3>
				<table class="wp-yac-config-table">
					<thead>
						<tr>
							<th><?php echo esc_html( 'Directive' ); ?></th>
							<th><?php echo esc_html( 'Current value' ); ?></th>
							<th><?php echo esc_html( 'Where' ); ?></th>
							<th><?php echo esc_html( 'Description' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( wp_yac_config_rows() as $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $row[0] ); ?></code></td>
							<td><?php echo esc_html( $row[1] ); ?></td>
							<td><?php echo esc_html( $row[2] ); ?></td>
							<td><?php echo esc_html( $row[3] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="wp-yac-panel" style="flex: 1 1 340px">
				<h2><?php echo esc_html( 'Shared memory contents' ); ?></h2>
				<?php $snapshot = wp_yac_memory_snapshot(); ?>
				<?php if ( null === $snapshot ) : ?>
					<p class="wp-yac-note"><?php echo esc_html( 'Requires the Yac extension.' ); ?></p>
				<?php else : ?>
					<ul class="wp-yac-op-list">
						<li><span><?php echo esc_html( 'Total entries' ); ?></span><strong><?php echo esc_html( number_format_i18n( $snapshot['entries'] ) ); ?></strong></li>
						<li><span><?php echo esc_html( 'Data size' ); ?></span><strong><?php echo esc_html( wp_yac_format_bytes( $snapshot['bytes'] ) ); ?></strong></li>
						<li><span><?php echo esc_html( 'Average entry size' ); ?></span><strong><?php echo esc_html( wp_yac_format_bytes( $snapshot['average'] ) ); ?></strong></li>
					</ul>

					<?php if ( ! empty( $snapshot['largest'] ) ) : ?>
						<h3 style="margin: 14px 0 4px; font-size: 13px"><?php echo esc_html( 'Largest entries' ); ?></h3>
						<?php $largest_size = max( array_column( $snapshot['largest'], 0 ) ); ?>
						<ul class="wp-yac-entry-bars">
						<?php foreach ( $snapshot['largest'] as $entry ) : ?>
							<li>
								<span class="wp-yac-entry-key" title="<?php echo esc_attr( $entry[1] ); ?>"><?php echo esc_html( $entry[1] ); ?></span>
								<span class="wp-yac-entry-bar-track"><span class="wp-yac-entry-bar" style="width: <?php echo esc_attr( round( $entry[0] / $largest_size * 100 ) ); ?>%"></span></span>
								<strong><?php echo esc_html( wp_yac_format_bytes( $entry[0] ) ); ?></strong>
							</li>
						<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<p class="wp-yac-note"><?php echo esc_html( 'Sizes are the serialized value length (v_len), without Yac’s internal padding. wp_cache_flush() calls Yac::flush(), which wipes the ENTIRE shared memory on this machine — including data written by other Yac users on the same PHP pool.' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<h2><?php echo esc_html( 'Actions' ); ?></h2>
		<div class="wp-yac-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( 'Flush the object cache? This wipes the ENTIRE Yac shared memory on this machine, including data of other Yac users sharing the PHP pool.' ); ?>')">
				<?php wp_nonce_field( 'wp_yac_admin' ); ?>
				<input type="hidden" name="wp_yac_action" value="flush">
				<button class="button button-primary"><?php echo esc_html( 'Flush object cache' ); ?></button>
			</form>
			<?php if ( ! wp_yac_dropin_is_ours() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wp_yac_admin' ); ?>
					<input type="hidden" name="wp_yac_action" value="redeploy">
					<button class="button"><?php echo esc_html( 'Deploy drop-in' ); ?></button>
				</form>
			<?php else : ?>
				<?php if ( version_compare( WP_YAC_VERSION, (string) wp_yac_dropin_version(), '>' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'wp_yac_admin' ); ?>
						<input type="hidden" name="wp_yac_action" value="update_dropin">
						<button class="button button-primary"><?php echo esc_html( 'Update drop-in' ); ?></button>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( 'Remove the Yac drop-in? The object cache will fall back to WordPress default (database/options).' ); ?>')">
					<?php wp_nonce_field( 'wp_yac_admin' ); ?>
					<input type="hidden" name="wp_yac_action" value="remove">
					<button class="button button-link-delete"><?php echo esc_html( 'Remove drop-in' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	</div>
	<?php
}

function wp_yac_site_health_info( $info ) {
	$operational = wp_yac_is_operational();

	$info['wp_yac'] = array(
		'label'       => 'Yac Object Cache',
		'description' => 'Yac-backed object cache status.',
		'fields'      => array(
			'status'    => array(
				'label' => 'Backend status',
				'value' => $operational ? 'Operational' : 'Degraded — check Tools → Yac Object Cache',
				'debug' => $operational ? 'operational' : 'degraded',
			),
			'extension' => array(
				'label' => 'Yac extension',
				'value' => extension_loaded( 'yac' ) ? 'Loaded' : 'Not loaded',
				'debug' => extension_loaded( 'yac' ) ? 'loaded' : 'missing',
			),
			'dropin'    => array(
				'label' => 'Drop-in',
				'value' => wp_yac_dropin_is_ours() ? 'Deployed by Yac' : 'Missing or foreign',
				'debug' => wp_yac_dropin_is_ours() ? 'ours' : 'missing-or-foreign',
			),
		),
	);

	return $info;
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {

	class WP_Yac_CLI_Command {

		/**
		 * Flush the object cache.
		 *
		 * ## EXAMPLES
		 *
		 *     wp yac flush
		 */
		public function flush() {
			if ( function_exists( 'wp_cache_flush' ) && wp_cache_flush() ) {
				\WP_CLI::success( 'Object cache flushed.' );
			} else {
				\WP_CLI::error( 'Flush failed. Is the cache backend operational?' );
			}
		}

		/**
		 * Show deployment status.
		 *
		 * ## EXAMPLES
		 *
		 *     wp yac status
		 */
		public function status() {
			$strip_tags = static function ( $message ) {
				return html_entity_decode( wp_strip_all_tags( $message ) );
			};

			foreach ( wp_yac_status() as $row ) {
				$mark = 'ok' === $row[1] ? 'OK  ' : ( 'warn' === $row[1] ? 'WARN' : 'ERR ' );
				\WP_CLI::log( sprintf( '[%s] %s', $mark, $strip_tags( $row[2] ) ) );
			}
		}
	}

	\WP_CLI::add_command( 'yac', 'WP_Yac_CLI_Command' );
}
