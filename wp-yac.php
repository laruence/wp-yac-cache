<?php
/**
 * Plugin Name: Yac Object Cache
 * Plugin URI: https://github.com/laruence/wordpress-yac-cache
 * Description: Yac (lock-free shared memory) backed object cache for WordPress. Auto-deploys the object-cache.php drop-in on activation. No external servers: the cache lives in shared memory inherited by PHP-FPM workers.
 * Version: 1.1.1
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

define( 'WP_YAC_VERSION', '1.1.1' );
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
   sanitized) + ':'. The drop-in builds the same string from the same
   wp-config.php constant, so nothing to sync; it is the only isolation
   between installs sharing one PHP pool */
function wp_yac_key_prefix() {
	$user = defined( 'WP_YAC_KEY_PREFIX' ) ? WP_YAC_KEY_PREFIX : 'wp';

	return preg_replace( '/[^A-Za-z0-9_]/', '', substr( (string) $user, 0, 6 ) ) . ':';
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
function wp_yac_memory_snapshot( $top = 10, $prefix = '' ) {
	if ( isset( $GLOBALS['wp_yac_test_snapshot'] ) ) {
		return $GLOBALS['wp_yac_test_snapshot'];
	}

	if ( ! wp_yac_backend_usable() ) {
		return null;
	}

	$yac     = new Yac();
	$entries = $yac->dump( -1 );
	if ( ! is_array( $entries ) ) {
		return null;
	}

	$total    = count( $entries );
	$bytes    = 0;
	$occupied = 0;
	$own      = 0;
	$largest  = array();
	$groups   = array();

	/* the drop-in stores "<storage_prefix><group>:<key>" */
	foreach ( $entries as $entry ) {
		/* v_len is the serialized value length; 'size' is the padded
		   allocation Yac actually reserves — the right measure of how
		   much of the values pool the current entries occupy */
		$vlen  = isset( $entry['v_len'] ) ? (int) $entry['v_len'] : 0;
		$alloc = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
		$key   = isset( $entry['key'] ) ? $entry['key'] : '';

		$bytes    += $vlen;
		$occupied += $alloc;
		$largest[] = array( $vlen, $alloc, $key );

		if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
			$own++;

			$logical = substr( $key, strlen( $prefix ) );
			if ( 8 === strlen( $logical ) && ctype_xdigit( $logical ) ) {
				$group = 'hashed (long keys)';
			} else {
			$colon = strpos( $logical, ':' );
				$group = ( false === $colon ) ? $logical : substr( $logical, 0, $colon );
				if ( '' === $group ) {
					$group = 'default';
				}
			}
		} else {
			$group = 'other Yac users';
		}

		if ( ! isset( $groups[ $group ] ) ) {
			$groups[ $group ] = array( 0, 0 );
		}
		$groups[ $group ][0]++;
		$groups[ $group ][1] += $alloc;
	}

	$group_list = array();
	foreach ( $groups as $label => $agg ) {
		$group_list[] = array( 'label' => $label, 'n' => $agg[0], 'bytes' => $agg[1] );
	}
	usort( $group_list, function ( $a, $b ) {
		return $b['n'] - $a['n'];
	} );
	if ( count( $group_list ) > 7 ) {
		$other = array( 'label' => 'other', 'n' => 0, 'bytes' => 0 );
		foreach ( array_splice( $group_list, 6 ) as $g ) {
			$other['n']     += $g['n'];
			$other['bytes'] += $g['bytes'];
		}
		$group_list[] = $other;
	}

	rsort( $largest );

	return array(
		'entries'  => $total,
		'bytes'    => $bytes,
		'occupied' => $occupied,
		'own'      => $own,
		'average'  => $total > 0 ? $occupied / $total : 0,
		'largest'  => array_slice( $largest, 0, $top ),
		'groups'   => $group_list,
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

/* health verdict from keys/values fullness + hit rate. 'causes' names
   the bars that take the verdict color instead of green; when healthy
   every bar is green */
function wp_yac_health( $info, $snapshot ) {
	$keys_total = (int) $info['slots_size'];
	$keys_used  = (int) $info['slots_used'];
	$keys_pct   = $keys_total > 0 ? $keys_used / $keys_total * 100 : 0;

	$values_total = (int) $info['values_memory_size'];
	$occupied     = $snapshot ? (float) $snapshot['occupied'] : 0;
	$vals_pct     = $values_total > 0 ? $occupied / $values_total * 100 : 0;

	/* yac builds with window counters report the hit rate of the last
	   completed ~20K-lookup window; older builds fall back to the
	   since-FPM-start aggregate */
	if ( isset( $info['win_rate'], $info['win_reset_tv'] ) && (int) $info['win_reset_tv'] > time() - 3600 ) {
		$rate = (float) $info['win_rate'] / 10;
	} else {
		$lookups = (int) $info['hits'] + (int) $info['miss'];
		$rate    = $lookups > 0 ? (int) $info['hits'] / $lookups * 100 : 0;
	}

	$verdict = 'green';
	$causes  = array();
	$advice  = '';

	$kicks  = (int) $info['kicks'];
	$misses = (int) $info['miss'];

	/* every successful insert either took a free slot (slots_used) or
	   kicked one, so inserts ~= slots_used + kicks; the observed kick
	   share vs the uniform-hashing expectation A^4/5 exposes a bad
	   placement; kicks/misses attributes misses to evictions */
	$inserts     = $keys_used + $kicks;
	$kick_obs    = $inserts > 0 ? $kicks / $inserts * 100 : 0;
	$kick_exp    = pow( min( 1, $keys_pct / 100 ), 4 ) / 5 * 100;
	$foreign_pct = ( $snapshot && $snapshot['entries'] > 0 )
		? ( $snapshot['entries'] - $snapshot['own'] ) / $snapshot['entries'] * 100
		: 0;

	if ( $keys_pct >= 90 ) {
		/* slots (nearly) full: circular eviction kicks in, so health is
		   whatever the hit rate says */
		if ( $rate < 70 ) {
			$verdict = 'red';
			$causes  = array( 'keys', 'kicks', 'misses' );
			$advice  = 'keys-strong';
		} elseif ( $rate < 90 ) {
			$verdict = 'yellow';
			$causes  = array( 'keys', 'kicks', 'misses' );
			$advice  = 'keys';
		}
	} elseif ( $vals_pct >= 90 ) {
		/* slots not full but the working set does not fit the values
		   pool: entries get ring-overwritten before re-read */
		$verdict = 'yellow';
		$causes  = array( 'values', 'recycles', 'misses' );
		$advice  = 'values';
	} elseif ( $rate < 90 && $kick_obs >= max( 3 * $kick_exp, 5 ) ) {
		/* far more kicks than uniform hashing predicts at this
		   occupancy: the placement roll is bad, or the table is too
		   small for the churn. Requires observed harm — on long
		   uptimes kicks creep up harmlessly (slots never free), and
		   that must stay green */
		$verdict = 'yellow';
		$causes  = array( 'keys', 'kicks' );
		$advice  = 'distribution';
	} elseif ( $rate < 90 && $misses > 0 && $kicks / $misses >= 1 / 3 ) {
		/* slot pressure arrives early: a third+ of the misses are
		   eviction-driven although the table is not even full */
		$verdict = 'yellow';
		$causes  = array( 'keys', 'kicks', 'misses' );
		$advice  = 'keys-early';
	} elseif ( $foreign_pct > 50 ) {
		/* our working set is small; the occupancy belongs to other Yac
		   users sharing this machine's pool */
		$verdict = 'yellow';
		$causes  = array( 'keys' );
		$advice  = 'foreign';
	}

	return array(
		'verdict'     => $verdict,
		'rate'        => $rate,
		'keys_pct'    => $keys_pct,
		'vals_pct'    => $vals_pct,
		'kick_obs'    => $kick_obs,
		'kick_exp'    => $kick_exp,
		'foreign_pct' => $foreign_pct,
		'causes'      => $causes,
		'advice'      => $advice,
	);
}

/* SVG pie from [ label, value, color ] slices; circle-stroke trick:
   half-radius circle with full-radius stroke width */
function wp_yac_pie( $slices ) {
	$total = 0;
	foreach ( $slices as $s ) {
		$total += $s[1];
	}
	if ( $total <= 0 ) {
		return '';
	}

	$r      = 35;
	$circ   = 2 * M_PI * $r;
	$offset = 0;
	$svg    = '<svg class="wp-yac-pie" viewBox="0 0 140 140" role="img">';

	foreach ( $slices as $s ) {
		$dash    = $s[1] / $total * $circ;
		$svg    .= sprintf(
			'<circle cx="70" cy="70" r="%1$d" stroke="%2$s" stroke-width="70" fill="none" stroke-dasharray="%3$s %4$s" stroke-dashoffset="%5$s" transform="rotate(-90 70 70)"/>',
			$r,
			esc_attr( $s[2] ),
			esc_attr( round( $dash, 2 ) ),
			esc_attr( round( $circ, 2 ) ),
			esc_attr( round( -$offset, 2 ) )
		);
		$offset += $dash;
	}

	return $svg . '</svg>';
}
function wp_yac_health_donut( $pct, $color ) {
	$ratio = max( 0, min( 1, $pct / 100 ) );
	$r     = 80;
	$circ  = 2 * M_PI * $r;

	return sprintf(
		'<svg class="wp-yac-health-donut" viewBox="0 0 200 200" role="img" aria-label="%1$s%%">'
		. '<circle class="wp-yac-donut-track" cx="100" cy="100" r="%2$d"/>'
		. '<circle class="wp-yac-donut-fill" cx="100" cy="100" r="%2$d" stroke="%3$s" stroke-dasharray="%4$s %5$s" transform="rotate(-90 100 100)"/>'
		. '<text class="wp-yac-donut-pct" x="100" y="98">%1$s%%</text>'
		. '<text class="wp-yac-donut-sub" x="100" y="122">hit rate</text>'
		. '</svg>',
		esc_attr( round( $pct, 1 ) ),
		$r,
		esc_attr( $color ),
		esc_attr( round( $circ * $ratio, 2 ) ),
		esc_attr( round( $circ, 2 ) )
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
			"define( 'WP_YAC_KEY_PREFIX', '…' )",
			sprintf(
				'%s%s',
				defined( 'WP_YAC_KEY_PREFIX' ) ? WP_YAC_KEY_PREFIX : '',
				defined( 'WP_YAC_KEY_PREFIX' ) ? '' : ' (' . 'default' . ')'
			),
			'wp-config.php',
			'storage key prefix, 0-6 chars; the only isolation between installs sharing one PHP pool — use a different one per site; multisite blogs share it',
		),
		array(
			"define( 'WP_YAC_SKIP_EMPTY', false )",
			defined( 'WP_YAC_SKIP_EMPTY' ) && ! WP_YAC_SKIP_EMPTY ? 'disabled' : 'enabled (default)',
			'wp-config.php',
			'the single pollution filter: empty get_page_by_path negatives (bot 404 probes, never re-read) stay request-local',
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
	$wp_yac_notice_key = isset( $_GET['wp_yac_notice'] ) ? sanitize_key( wp_unslash( $_GET['wp_yac_notice'] ) ) : '';
	if ( $wp_yac_notice_key ) {
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
		.wp-yac-grid { display: flex; gap: 14px; flex-wrap: wrap; }
		.wp-yac-panel { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 18px 20px; box-shadow: 0 1px 1px rgba(0, 0, 0, .04); }
		.wp-yac-panel > h2 { margin-top: 0; padding: 0; border: 0; font-size: 15px; }
		.wp-yac-panel-note { color: #646970; font-size: 12px; margin: -4px 0 12px; }
		.wp-yac-donut-track { fill: none; stroke: #f0f0f1; stroke-width: 13; }
		.wp-yac-donut-fill { fill: none; stroke-width: 13; stroke-linecap: round; }
		.wp-yac-donut-pct { font-size: 22px; font-weight: 600; fill: #1d2327; text-anchor: middle; }
		.wp-yac-donut-sub { font-size: 9px; fill: #646970; text-anchor: middle; }
		.wp-yac-advice { display: flex; gap: 10px; border-radius: 8px; padding: 12px 14px; margin: 14px 0 0; font-size: 13px; line-height: 1.6; }
		.wp-yac-advice-warn { background: #fcf9e8; border: 1px solid #f0e1a0; color: #8a6d00; }
		.wp-yac-advice strong { display: block; }
		.wp-yac-actions { margin-top: 18px; }
		.wp-yac-actions form { display: inline-block; margin-right: 8px; }
		.wp-yac-config-table { width: 100%; border-collapse: collapse; }
		.wp-yac-config-table th { text-align: left; font-size: 12px; color: #646970; padding: 7px 10px; border-bottom: 1px solid #f0f0f1; }
		.wp-yac-config-table td { border: 0; padding: 7px 10px; font-size: 13px; }
		.wp-yac-config-table td code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 12px; white-space: nowrap; }
		.wp-yac-config-table td:nth-child(3) { white-space: nowrap; }
		.wp-yac-op-list { list-style: none; margin: 8px 0 0; padding: 0; }
		.wp-yac-op-list li { display: flex; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid #f0f0f1; font-size: 13px; }
		.wp-yac-op-list span { color: #646970; }
		.wp-yac-entry-bars { list-style: none; margin: 8px 0 0; padding: 0; }
		.wp-yac-entry-bars li { display: flex; align-items: center; gap: 8px; padding: 3px 0; font-size: 12px; }
		.wp-yac-entry-key { width: 170px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #50575e; flex: none; font-family: Consolas, Monaco, monospace; }
		.wp-yac-entry-bar-track { flex: 1; background: #f0f0f1; border-radius: 4px; height: 10px; overflow: hidden; }
		.wp-yac-entry-bar { display: block; height: 100%; background: #72aee6; border-radius: 4px; }
		.wp-yac-entry-bars strong { width: 76px; text-align: right; flex: none; }
		.wp-yac-pie-wrap { display: flex; gap: 16px; align-items: center; margin-top: 8px; flex-wrap: wrap; }
		.wp-yac-pie { width: 140px; height: 140px; flex: none; display: block; }
		.wp-yac-pie-legend { list-style: none; margin: 0; padding: 0; font-size: 12px; flex: 1 1 200px; }
		.wp-yac-pie-legend li { display: flex; align-items: center; gap: 8px; padding: 3px 0; }
		.wp-yac-pie-legend .dot { width: 10px; height: 10px; border-radius: 50%; flex: none; }
		.wp-yac-pie-legend .g { flex: 1; color: #50575e; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.wp-yac-pie-legend strong { text-align: right; }
		.wp-yac-pie-legend small { color: #646970; font-weight: 400; }
		.wp-yac-pie-legend .g small { color: #646970; }
		.wp-yac-contents { display: flex; gap: 28px; flex-wrap: wrap; align-items: stretch; }
		.wp-yac-contents-left { flex: 0 0 340px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
		.wp-yac-contents-left .wp-yac-pie { width: 240px; height: 240px; }
		.wp-yac-contents-left .wp-yac-pie-legend { flex: none; width: 100%; max-width: 300px; margin-top: 12px; }
		.wp-yac-contents-right { flex: 1 1 480px; min-width: 320px; }
		.wp-yac-entry-bars-lg li { font-size: 13px; padding: 5px 0; }
		.wp-yac-entry-bars-lg .wp-yac-entry-key { width: 300px; }
		.wp-yac-entry-bars-lg .wp-yac-entry-bar-track { height: 14px; }
		.wp-yac-entry-bars-lg strong { width: 90px; }
		.wp-yac-note { color: #646970; font-size: 12px; margin-top: 8px; }
		.wp-yac-health { display: flex; gap: 28px; flex-wrap: wrap; align-items: center; }
		.wp-yac-health-ring { flex: 0 0 240px; display: flex; flex-direction: column; align-items: center; }
		.wp-yac-health-donut { width: 200px; height: 200px; display: block; }
		.wp-yac-health-donut .wp-yac-donut-track, .wp-yac-health-donut .wp-yac-donut-fill { stroke-width: 18; }
		.wp-yac-health-donut .wp-yac-donut-pct { font-size: 34px; }
		.wp-yac-health-donut .wp-yac-donut-sub { font-size: 13px; }
		.wp-yac-health-bars { flex: 1 1 420px; }
		.wp-yac-bars { list-style: none; margin: 0; padding: 0; }
		.wp-yac-bars li { display: grid; grid-template-columns: 86px 1fr 150px; align-items: center; gap: 10px; padding: 7px 0; font-size: 13px; }
		.wp-yac-bars .lbl { color: #50575e; }
		.wp-yac-bars .track { background: #f0f0f1; border-radius: 6px; height: 13px; overflow: hidden; }
		.wp-yac-bars .fill { display: block; height: 100%; border-radius: 6px; }
		.wp-yac-bars .val { text-align: right; font-weight: 600; color: #1d2327; }
		.wp-yac-bars .val small { font-weight: 400; color: #646970; }
		.wp-yac-chip { display: inline-flex; align-items: center; gap: 6px; border-radius: 20px; padding: 6px 14px; font-size: 13px; font-weight: 600; margin-top: 12px; }
		.wp-yac-chip-good { background: #edfaef; border: 1px solid #b8e6bf; color: #1e7a31; }
		.wp-yac-chip-warn { background: #fcf9e8; border: 1px solid #f0e1a0; color: #8a6d00; }
		.wp-yac-chip-err { background: #fcf0f1; border: 1px solid #f0c0c5; color: #d63638; }
		.wp-yac-advice-err { background: #fcf0f1; border: 1px solid #f0c0c5; color: #d63638; }
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
		<?php $self_test = wp_yac_self_test(); ?>
		<?php if ( empty( $wp_yac_problems ) ) : ?>
			<p style="display: inline-flex; align-items: center; gap: 8px; background: #edfaef; border: 1px solid #b8e6bf; color: #1e7a31; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin: 4px 0 0;">
				<span>✓</span><strong><?php echo esc_html( 'Active' ); ?></strong>
				<span>
					<?php echo esc_html( '— the object cache is running on Yac shared memory' ); ?>
					<?php if ( null !== $self_test && $self_test['ok'] ) : ?>
						<span title="<?php echo esc_attr( 'set/get/delete round trip' ); ?>"> &middot; <?php echo esc_html( 'round trip ' . number_format_i18n( $self_test['elapsed'], 3 ) . ' ms' ); ?></span>
					<?php endif; ?>
				</span>
			</p>
			<?php if ( null !== $self_test && ! $self_test['ok'] ) : ?>
				<p style="display: inline-flex; align-items: center; gap: 8px; background: #fcf0f1; border: 1px solid #f0c0c5; color: #d63638; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin: 8px 0 0;">
					<span>✗</span><span><?php echo esc_html( 'Shared-memory round trip failed — a value did not survive set/get. Check the Yac extension configuration.' ); ?></span>
				</p>
			<?php endif; ?>
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
			$snapshot = wp_yac_memory_snapshot( 10, wp_yac_key_prefix() );
			$health   = wp_yac_health( $info, $snapshot );

			$health_colors = array( 'green' => '#00a32a', 'yellow' => '#dba617', 'red' => '#d63638' );
			$health_color  = $health_colors[ $health['verdict'] ];

			$keys_used    = (int) $info['slots_used'];
			$keys_total   = (int) $info['slots_size'];
			$values_total = (int) $info['values_memory_size'];
			$occupied     = $snapshot ? (float) $snapshot['occupied'] : 0;

			$hits      = (int) $info['hits'];
			$misses    = (int) $info['miss'];
			$lookups   = $hits + $misses;
			$recycles  = (int) $info['recycles'];
			$kicks     = (int) $info['kicks'];
			$fails     = (int) $info['fails'];
			$ops_total = $lookups + $fails;

			/* one bar row; color = verdict color when the metric is a
			   cause of the verdict, green otherwise */
			$wp_yac_bar = function ( $label, $width, $color, $value_html ) {
				return '<li><span class="lbl">' . esc_html( $label ) . '</span>'
					. '<span class="track"><span class="fill" style="width: ' . esc_attr( round( min( 100, max( 0, $width ) ), 1 ) ) . '%; background: ' . esc_attr( $color ) . '"></span></span>'
					. '<span class="val">' . $value_html . '</span></li>';
			};
			$wp_yac_cause = function ( $key ) use ( $health, $health_color ) {
				return in_array( $key, $health['causes'], true ) ? $health_color : '#00a32a';
			};

			$wp_yac_bars  = $wp_yac_bar( 'Keys', $health['keys_pct'], $wp_yac_cause( 'keys' ), number_format_i18n( $keys_used ) . ' <small>/ ' . number_format_i18n( $keys_total ) . '</small>' );
			$wp_yac_bars .= $wp_yac_bar( 'Values', $health['vals_pct'], $wp_yac_cause( 'values' ), esc_html( wp_yac_format_bytes( $occupied ) ) . ' <small>/ ' . esc_html( wp_yac_format_bytes( $values_total ) ) . '</small>' );
			$wp_yac_bars .= $wp_yac_bar( 'Hits', $lookups > 0 ? $hits / $lookups * 100 : 0, '#00a32a', number_format_i18n( $hits ) );
			$wp_yac_bars .= $wp_yac_bar( 'Misses', $lookups > 0 ? $misses / $lookups * 100 : 0, $wp_yac_cause( 'misses' ), number_format_i18n( $misses ) );
			$wp_yac_bars .= $wp_yac_bar( 'Kicks', $kicks / max( 1, $ops_total ) * 100, $wp_yac_cause( 'kicks' ), number_format_i18n( $kicks ) . ( $kicks > 0 && $misses > 0 ? ' <small>(≈' . round( $kicks / $misses * 100 ) . '% of misses)</small>' : '' ) );
			$wp_yac_bars .= $wp_yac_bar( 'Recycles', $recycles / max( 1, $ops_total ) * 100, $wp_yac_cause( 'recycles' ), number_format_i18n( $recycles ) );

			$wp_yac_chips = array(
				'green'  => array( 'wp-yac-chip-good', '✓ Healthy' ),
				'yellow' => array( 'wp-yac-chip-warn', '⚠ Attention' ),
				'red'    => array( 'wp-yac-chip-err', '✗ Critical' ),
			);
			$wp_yac_chip = $wp_yac_chips[ $health['verdict'] ];

			if ( 'green' !== $health['verdict'] ) {
				$wp_yac_advices = array(
					'keys'        => array( 'wp-yac-advice-warn', '⚠', sprintf( 'Key slots full and hit rate below 90%% — entries are being kicked before re-read. Raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ),
					'keys-strong' => array( 'wp-yac-advice-err', '✗', sprintf( 'Key slots full and hit rate below 70%% — the cache is thrashing and requests fall through to the database. Strongly raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ),
					'values'      => array( 'wp-yac-advice-warn', '⚠', sprintf( 'Keys not full but values full — the current entries occupy more than the values pool and get ring-overwritten before re-read. Raise <code>yac.values_memory_size</code> (currently %s).', esc_html( wp_yac_format_bytes( $values_total ) ) ) ),
					'distribution'=> array( 'wp-yac-advice-warn', '⚠', sprintf( 'Kicks are %1$.1f%% of inserts vs ≈%2$.1f%% expected under uniform hashing — the placement is unlucky or the slot table too small for the churn. Change <code>WP_YAC_KEY_PREFIX</code> to re-roll the layout (costs one cold start), or raise <code>yac.keys_memory_size</code> (a new mask re-spreads the keys too).', $health['kick_obs'], $health['kick_exp'] ) ),
					'keys-early'  => array( 'wp-yac-advice-warn', '⚠', sprintf( 'Key slots are not full but the hit rate is under 90%% and a third or more of misses are eviction-driven — slot pressure arrives early. Raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ),
					'foreign'     => array( 'wp-yac-advice-warn', '⚠', sprintf( 'Only %1$.0f%% of the slotted entries belong to this install — the slot pressure is shared-pool occupancy from other Yac users on this machine. Raise <code>yac.keys_memory_size</code> or run a separate pool.', 100 - $health['foreign_pct'] ) ),
				);
			}
			?>
			<h2><?php echo esc_html( 'Cache health' ); ?></h2>
			<div class="wp-yac-panel">
				<div class="wp-yac-health">
					<div class="wp-yac-health-ring">
						<?php echo wp_yac_health_donut( $health['rate'], $health_color ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="wp-yac-chip <?php echo esc_attr( $wp_yac_chip[0] ); ?>"><?php echo esc_html( $wp_yac_chip[1] ); ?></span>
					</div>
					<div class="wp-yac-health-bars">
						<ul class="wp-yac-bars"><?php echo $wp_yac_bars; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts ?></ul>
						<p class="wp-yac-note"><?php echo esc_html( 'Values bar = Σ entry.size (padded). All bars green when healthy; only the causing metrics take the verdict color.' ); ?></p>
					</div>
				</div>
				<?php if ( '' !== $health['advice'] ) : ?>
					<?php $wp_yac_advice = $wp_yac_advices[ $health['advice'] ]; ?>
					<div class="wp-yac-advice <?php echo esc_attr( $wp_yac_advice[0] ); ?>">
						<span><?php echo esc_html( $wp_yac_advice[1] ); ?></span>
						<div><?php echo wp_kses_post( $wp_yac_advice[2] ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<h2><?php echo esc_html( 'Shared memory contents' ); ?></h2>
			<div class="wp-yac-panel">
				<?php if ( null === $snapshot ) : ?>
					<p class="wp-yac-note"><?php echo esc_html( 'Requires the Yac extension.' ); ?></p>
				<?php else : ?>
					<div class="wp-yac-contents">
						<div class="wp-yac-contents-left">
							<?php if ( ! empty( $snapshot['groups'] ) ) : ?>
								<?php
								$wp_yac_palette = array( '#2271b1', '#72aee6', '#00a32a', '#dba617', '#d63638', '#787c82', '#996800' );
								$wp_yac_slices  = array();
								foreach ( $snapshot['groups'] as $wp_yac_gi => $wp_yac_g ) {
									$wp_yac_slices[] = array( $wp_yac_g['label'], $wp_yac_g['n'], $wp_yac_g['bytes'], $wp_yac_palette[ $wp_yac_gi % 7 ] );
								}
								?>
								<?php echo wp_yac_pie( array_map( function ( $s ) { return array( $s[0], $s[1], $s[3] ); }, $wp_yac_slices ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<ul class="wp-yac-pie-legend">
								<?php foreach ( $wp_yac_slices as $wp_yac_s ) : ?>
									<li>
										<span class="dot" style="background: <?php echo esc_attr( $wp_yac_s[3] ); ?>"></span>
										<span class="g" title="<?php echo esc_attr( $wp_yac_s[0] ); ?>"><?php echo esc_html( $wp_yac_s[0] ); ?> <small><?php echo esc_html( round( $wp_yac_s[1] / max( 1, $snapshot['entries'] ) * 100 ) . '%' ); ?></small></span>
										<strong><?php echo esc_html( number_format_i18n( $wp_yac_s[1] ) ); ?> <small>keys &middot; <?php echo esc_html( wp_yac_format_bytes( $wp_yac_s[2] ) ); ?></small></strong>
									</li>
								<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
						<div class="wp-yac-contents-right">
							<ul class="wp-yac-op-list">
								<li><span><?php echo esc_html( 'Total entries' ); ?></span><strong><?php echo esc_html( number_format_i18n( $snapshot['entries'] ) ); ?></strong></li>
								<li><span><?php echo esc_html( 'Occupied (Σ size, padded)' ); ?></span><strong><?php echo esc_html( wp_yac_format_bytes( $snapshot['occupied'] ) ); ?></strong></li>
								<li><span><?php echo esc_html( 'Content (Σ v_len)' ); ?></span><strong><?php echo esc_html( wp_yac_format_bytes( $snapshot['bytes'] ) ); ?></strong></li>
								<li><span><?php echo esc_html( 'Average occupied / entry' ); ?></span><strong><?php echo esc_html( wp_yac_format_bytes( $snapshot['average'] ) ); ?></strong></li>
							</ul>
							<?php if ( ! empty( $snapshot['largest'] ) ) : ?>
								<h3 style="margin: 14px 0 6px; font-size: 13px"><?php echo esc_html( 'Largest entries (by content length)' ); ?></h3>
								<?php $largest_size = max( array_column( $snapshot['largest'], 0 ) ); ?>
								<ul class="wp-yac-entry-bars wp-yac-entry-bars-lg">
								<?php foreach ( $snapshot['largest'] as $entry ) : ?>
									<li>
										<span class="wp-yac-entry-key" title="<?php echo esc_attr( $entry[2] ); ?>"><?php echo esc_html( $entry[2] ); ?></span>
										<span class="wp-yac-entry-bar-track"><span class="wp-yac-entry-bar" style="width: <?php echo esc_attr( round( $entry[0] / $largest_size * 100 ) ); ?>%"></span></span>
										<strong><?php echo esc_html( wp_yac_format_bytes( $entry[0] ) ); ?></strong>
									</li>
								<?php endforeach; ?>
								</ul>
							<?php endif; ?>
							<p class="wp-yac-note"><?php echo esc_html( 'Occupied = Σ entry.size (padded); Content = Σ v_len; overwritten-but-unreclaimed entries slightly overstate. Flush wipes the machine’s entire shared memory.' ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<h2><?php echo esc_html( 'Configuration' ); ?></h2>
			<div class="wp-yac-panel">
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

		<?php endif; ?>

		<h2><?php echo esc_html( 'Diagnostics' ); ?></h2>
		<div class="wp-yac-panel">
			<table class="wp-yac-config-table">
				<thead>
					<tr>
						<th><?php echo esc_html( 'Item' ); ?></th>
						<th><?php echo esc_html( 'Value' ); ?></th>
					</tr>
				</thead>
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
				<tr>
					<td><code>yac.enable</code></td>
					<td><code><?php echo esc_html( ini_get( 'yac.enable' ) ? '1' : '0' ); ?></code> — <?php echo esc_html( 'master switch of the extension' ); ?></td>
				</tr>
				<tr>
					<td><code>yac.keys_memory_size</code></td>
					<td><code><?php echo esc_html( ini_get( 'yac.keys_memory_size' ) ); ?></code> — <?php echo esc_html( 'slot table (~32K slots per 4M); raise when keys fill and hit rate suffers' ); ?></td>
				</tr>
				<tr>
					<td><code>yac.values_memory_size</code></td>
					<td><code><?php echo esc_html( ini_get( 'yac.values_memory_size' ) ); ?></code> — <?php echo esc_html( 'values pool; raise when occupied approaches it' ); ?></td>
				</tr>
				<tr>
					<td><code>yac.serializer</code></td>
					<td><code><?php echo esc_html( ini_get( 'yac.serializer' ) ?: 'php' ); ?></code></td>
				</tr>
				<tr>
					<td><code>yac.compress_threshold</code></td>
					<td><code><?php echo esc_html( ini_get( 'yac.compress_threshold' ) ?: '-1' ); ?></code> — <?php echo esc_html( 'values larger than this (bytes) are fastlz-compressed; -1 disables' ); ?></td>
				</tr>
				<tr>
					<td><code>yac.enable_cli</code></td>
					<td><code><?php echo esc_html( ini_get( 'yac.enable_cli' ) ? '1' : '0' ); ?></code></td>
				</tr>
				<tr>
					<td><?php echo esc_html( 'Key budget' ); ?></td>
					<td><code><?php echo esc_html( ( defined( 'YAC_MAX_KEY_LEN' ) ? YAC_MAX_KEY_LEN : 48 ) ); ?> B</code> max, <code><?php echo esc_html( ( defined( 'YAC_MAX_KEY_LEN' ) ? YAC_MAX_KEY_LEN : 48 ) - strlen( wp_yac_key_prefix() ) ); ?> B</code> logical after prefix <code><?php echo esc_html( wp_yac_key_prefix() ); ?></code> — <?php echo esc_html( 'longer keys keep the group and hash the rest' ); ?></td>
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
