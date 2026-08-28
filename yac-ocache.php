<?php
/**
 * Plugin Name: Yac Object Cache
 * Plugin URI: https://github.com/laruence/wp-yac-cache
 * Description: Yac (lock-free shared memory) backed object cache for WordPress. Auto-deploys the object-cache.php drop-in on activation. No external servers: the cache lives in shared memory inherited by PHP-FPM workers.
 * Version: 1.2.0
 * Requires at least: 5.6
 * Requires PHP: 7.0
 * Author: Xinchen Hui<laruence@php.net>
 * Author URI: https://www.laruence.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'YAC_OCACHE_VERSION', '1.2.0' );
define( 'YAC_OCACHE_PLUGIN_FILE', __FILE__ );
define( 'YAC_OCACHE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'YAC_OCACHE_DROPIN_SOURCE', __DIR__ . '/object-cache.php' );
define( 'YAC_OCACHE_DROPIN_DEST', WP_CONTENT_DIR . '/object-cache.php' );
define( 'YAC_OCACHE_ADMIN_PAGE', 'yac-ocache' );

if ( ! defined( 'YAC_OCACHE_WARMUP_LOOKUPS' ) ) {
	/* health metrics stay untouched until this many lookups have passed:
	   a cold cache is dominated by compulsory first-read misses, so
	   early verdicts would be false alarms */
	define( 'YAC_OCACHE_WARMUP_LOOKUPS', 1000 );
}

register_activation_hook( __FILE__, 'yac_ocache_activate' );
register_deactivation_hook( __FILE__, 'yac_ocache_deactivate' );

add_action( 'admin_menu', 'yac_ocache_admin_menu' );
add_action( 'wp_dashboard_setup', 'yac_ocache_register_dashboard_widget' );
add_action( 'admin_notices', 'yac_ocache_admin_notices' );
add_action( 'admin_init', 'yac_ocache_admin_init' );
add_action( 'debug_information', 'yac_ocache_site_health_info' );
add_action( 'wp_ajax_yac_ocache_dismiss_status_notice', 'yac_ocache_ajax_dismiss_status_notice' );
add_action( 'wp_ajax_yac_ocache_entry', 'yac_ocache_ajax_entry' );
add_action( 'wp_ajax_yac_ocache_entry_delete', 'yac_ocache_ajax_entry_delete' );
add_action( 'admin_enqueue_scripts', 'yac_ocache_admin_enqueue_scripts' );
add_action( 'admin_print_footer_scripts', 'yac_ocache_admin_notice_script' );

/* the uninstall hook cannot live in the drop-in */
if ( is_admin() ) {
	register_uninstall_hook( __FILE__, 'yac_ocache_uninstall' );
}

function yac_ocache_activate() {
	if ( ! defined( 'WP_INSTALLING' ) || ! WP_INSTALLING ) {
		yac_ocache_deploy_dropin();
	}

	set_transient( 'yac_ocache_activated', 1, 60 );
}

function yac_ocache_deactivate() {
	/* keep the drop-in: an object cache should not vanish just because
	   someone toggled a plugin; removal is an explicit action */
}

function yac_ocache_uninstall() {
	if ( yac_ocache_dropin_is_ours() ) {
		wp_delete_file( YAC_OCACHE_DROPIN_DEST );
	}
	delete_option( 'yac_ocache_dropin_deployed' );
}

/* copy the drop-in into wp-content/; never touches a foreign drop-in;
   $force rewrites even when the current drop-in is already ours */
function yac_ocache_deploy_dropin( $force = false ) {
	$ours = yac_ocache_dropin_is_ours();

	if ( $ours && ! $force ) {
		update_option( 'yac_ocache_dropin_deployed', YAC_OCACHE_VERSION, true );
		return true;
	}

	if ( ! $ours && file_exists( YAC_OCACHE_DROPIN_DEST ) ) {
		/* another cache plugin owns it */
		update_option( 'yac_ocache_dropin_deployed', '', true );
		return false;
	}

	if ( ! is_readable( YAC_OCACHE_DROPIN_SOURCE ) ) {
		update_option( 'yac_ocache_dropin_deployed', '', true );
		return false;
	}

	$content = file_get_contents( YAC_OCACHE_DROPIN_SOURCE );
	if ( false === $content || ! @file_put_contents( YAC_OCACHE_DROPIN_DEST, $content ) ) {
		update_option( 'yac_ocache_dropin_deployed', '', true );
		return false;
	}

	/* no explicit permission change: the drop-in inherits the
	   webserver-friendly mode from the umask at creation time */
	update_option( 'yac_ocache_dropin_deployed', YAC_OCACHE_VERSION, true );

	return true;
}

function yac_ocache_dropin_is_ours() {
	if ( ! file_exists( YAC_OCACHE_DROPIN_DEST ) ) {
		return false;
	}

	/* the loaded drop-in defines this constant */
	if ( defined( 'YAC_OCACHE_DROPIN_VERSION' ) ) {
		return true;
	}

	/* fallback when the constant isn't loaded in this context */
	$head = file_get_contents( YAC_OCACHE_DROPIN_DEST, false, null, 0, 2048 );

	return is_string( $head ) && false !== strpos( $head, 'Yac Object Cache' );
}

/* the version of the deployed drop-in, null when it is unreadable */
function yac_ocache_dropin_version() {
	if ( ! file_exists( YAC_OCACHE_DROPIN_DEST ) ) {
		return null;
	}

	/* the loaded drop-in defines this constant */
	if ( defined( 'YAC_OCACHE_DROPIN_VERSION' ) ) {
		return YAC_OCACHE_DROPIN_VERSION;
	}

	$head = file_get_contents( YAC_OCACHE_DROPIN_DEST, false, null, 0, 4096 );
	if ( ! is_string( $head ) || ! preg_match( "/YAC_OCACHE_DROPIN_VERSION', '([^']+)'/", $head, $m ) ) {
		return null;
	}

	return $m[1];
}

/* the drop-in's Yac instance prefix: YAC_OCACHE_KEY_PREFIX (0-6 chars,
   sanitized) + ':'. The drop-in builds the same string from the same
   wp-config.php constant, so nothing to sync; it is the only isolation
   between installs sharing one PHP pool */
function yac_ocache_key_prefix() {
	$user = defined( 'YAC_OCACHE_KEY_PREFIX' ) ? YAC_OCACHE_KEY_PREFIX : 'wp';

	return preg_replace( '/[^A-Za-z0-9_]/', '', substr( (string) $user, 0, 6 ) ) . ':';
}

/* one round-trip through shared memory (set/get/delete), timed in ms;
   reuses a fixed key: Yac's delete is a tombstone (the slot is only
   reclaimed on recycle), so random keys would leak a slot per page view */
function yac_ocache_self_test() {
	if ( ! yac_ocache_backend_usable() ) {
		return null;
	}

	$yac   = new Yac( yac_ocache_key_prefix() );
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

/* every entry from Yac::dump(), paged $page_size at a time (null on
   failure); dump(-1) materializes the entire shared-memory table as one
   PHP array, which blows past the memory limit on a busy cache.
   dump($limit, $offset) exists only since Yac 2.4.0 — older builds fall
   back to the single full dump, the best they offer. */
function yac_ocache_dump_all( $yac, $page_size = 1000 ) {
	if ( defined( 'YAC_VERSION' ) && version_compare( YAC_VERSION, '2.4.0', '>=' ) ) {
		$entries = array();
		$offset  = 0;
		for ( ;; ) {
			$page = $yac->dump( $page_size, $offset );
			if ( ! is_array( $page ) ) {
				return null;
			}
			if ( 0 === count( $page ) ) {
				break;
			}
			foreach ( $page as $entry ) {
				$entries[] = $entry;
			}
			$offset += count( $page );
			if ( count( $page ) < $page_size ) {
				break;
			}
		}
		return $entries;
	}

	$entries = $yac->dump( -1 );
	return is_array( $entries ) ? $entries : null;
}

/* entry-level statistics from Yac::dump() (null when unavailable) */
function yac_ocache_memory_snapshot( $top = 10, $prefix = '', $cache_ttl = 0 ) {
	if ( isset( $GLOBALS['yac_ocache_test_snapshot'] ) ) {
		return $GLOBALS['yac_ocache_test_snapshot'];
	}

	if ( ! yac_ocache_backend_usable() ) {
		return null;
	}

	$yac = new Yac();

	if ( $cache_ttl > 0 && '' !== $prefix ) {
		$cached = $yac->get( 'diag:snapshot' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$entries = yac_ocache_dump_all( $yac );
	if ( null === $entries ) {
		return null;
	}

	$now      = time();
	$total    = 0;
	$bytes    = 0;
	$occupied = 0;
	$own      = 0;
	$largest  = array();
	$groups   = array();
	$hits_max = 0;

	/* the drop-in stores "<storage_prefix><group>:<key>" */
	foreach ( $entries as $entry ) {
		/* v_len is the serialized value length; 'size' is the padded
		   allocation Yac actually reserves — the right measure of how
		   much of the values pool the current entries occupy */
		$vlen  = isset( $entry['v_len'] ) ? (int) $entry['v_len'] : 0;
		$alloc = isset( $entry['size'] ) ? (int) $entry['size'] : 0;
		$key   = isset( $entry['key'] ) ? $entry['key'] : '';

		/* expired entries (ttl elapsed) are dead data awaiting overwrite;
		   the contents view reports live entries only */
		if ( ! empty( $entry['ttl'] ) && $entry['ttl'] <= $now ) {
			continue;
		}
		$total++;

		$bytes    += $vlen;
		$occupied += $alloc;

		/* diag:* keys are the plugin's own markers; keep them out of
		   the pie chart and the entry listings */
		$is_diag = ( '' !== $prefix && 0 === strpos( $key, $prefix . 'diag:' ) );
		if ( ! $is_diag ) {
			/* newer Yac builds add per-entry hits/atime to dump(); older
			   builds return no such keys. The admin page shows the
			   hottest tab only when hits exists — probe the first
			   non-diag entry, it is guaranteed a find() touch if the
			   build tracks it */
			$largest[] = array(
				(int) $vlen,
				(int) $alloc,
				$key,
				array_key_exists( 'hits', $entry ) ? (int) $entry['hits'] : null,
				array_key_exists( 'atime', $entry ) ? (int) $entry['atime'] : null,
			);
			if ( isset( $entry['hits'] ) && (int) $entry['hits'] > $hits_max ) {
				$hits_max = (int) $entry['hits'];
			}
		}

		if ( $is_diag ) {
			continue;
		}

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

	rsort( $largest ); /* rows sort by v_len (largest first), ties broken by key */

	/* 'hits' only exists in newer Yac builds (see above); show the
	   hottest tab only when it came back on the first probed entry */
	$has_meta = ! empty( $largest ) && null !== $largest[0][3];

	$result = array(
		'entries'  => $total,
		'bytes'    => $bytes,
		'occupied' => $occupied,
		'own'      => $own,
		'average'  => $total > 0 ? $occupied / $total : 0,
		'largest'  => array_slice( $largest, 0, $top ),
		'has_meta' => $has_meta,
		'hits_max' => $hits_max,
		'groups'   => $group_list,
	);

	if ( $has_meta ) {
		$by_hits = $largest;
		usort( $by_hits, function ( $a, $b ) {
			return $b[3] <=> $a[3] ?: $b[0] <=> $a[0];
		} );
		$result['hottest'] = array_slice( $by_hits, 0, $top );
	}

	if ( $cache_ttl > 0 && '' !== $prefix ) {
		$yac->set( 'diag:snapshot', $result, $cache_ttl );
	}

	return $result;
}

/* returns rows of [ key, ok|warn|err, message ] */
function yac_ocache_status() {
	if ( isset( $GLOBALS['yac_ocache_test_status'] ) ) {
		return $GLOBALS['yac_ocache_test_status'];
	}

	$status = array();

	$dropin_exists = file_exists( YAC_OCACHE_DROPIN_DEST );
	$ours          = yac_ocache_dropin_is_ours();

	if ( $ours ) {
		$status[] = array( 'dropin', 'ok', 'object-cache.php drop-in deployed by Yac.' );

		$deployed = yac_ocache_dropin_version();
		if ( null === $deployed ) {
			$status[] = array( 'dropin_version', 'warn', 'Could not determine the deployed drop-in version.' );
		} elseif ( version_compare( YAC_OCACHE_VERSION, $deployed, '>' ) ) {
			$status[] = array( 'dropin_version', 'warn', sprintf(
				'A newer drop-in is available: plugin v%1$s, deployed v%2$s. Update it from the actions below.',
				YAC_OCACHE_VERSION,
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

	if ( defined( 'YAC_OCACHE_KEY_PREFIX' ) && strlen( YAC_OCACHE_KEY_PREFIX ) > 6 ) {
		$status[] = array( 'prefix', 'warn', sprintf(
			'%1$s is %2$d chars long; only the first %3$d count. Shorter is better: every byte shrinks the room left for the logical key.',
			'<code>YAC_OCACHE_KEY_PREFIX</code>',
			strlen( YAC_OCACHE_KEY_PREFIX ),
			6
		) );
	}

	return $status;
}

/* true when the shared backend is fully wired up */
function yac_ocache_is_operational() {
	foreach ( yac_ocache_status() as $row ) {
		if ( in_array( $row[0], array( 'dropin', 'wp_cache', 'extension' ), true ) && 'ok' !== $row[1] ) {
			return false;
		}
	}

	return true;
}

/* true when the extension is loaded AND enabled (yac.enable=1);
   new Yac() throws when it isn't, so probe once per request */
function yac_ocache_backend_usable() {
	static $usable = null;

	if ( null === $usable ) {
		if ( ! extension_loaded( 'yac' ) ) {
			$usable = false;
		} else {
			try {
				new Yac( yac_ocache_key_prefix() );
				$usable = true;
			} catch ( Throwable $e ) {
				$usable = false;
			}
		}
	}

	return $usable;
}

/* storage metrics straight from the Yac extension (null when unavailable) */
function yac_ocache_storage_info() {
	if ( isset( $GLOBALS['yac_ocache_test_storage_info'] ) ) {
		return $GLOBALS['yac_ocache_test_storage_info'];
	}

	if ( ! yac_ocache_backend_usable() ) {
		return null;
	}

	$info = ( new Yac() )->info();

	return is_array( $info ) ? $info : null;
}

/* human readable byte size */
function yac_ocache_format_bytes( $bytes ) {
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
function yac_ocache_health( $info, $snapshot ) {
	$keys_total = (int) $info['slots_size'];
	$keys_used  = (int) $info['slots_used'];
	$keys_pct   = $keys_total > 0 ? $keys_used / $keys_total * 100 : 0;

	$values_total = (int) $info['values_memory_size'];
	$occupied     = $snapshot ? (float) $snapshot['occupied'] : 0;
	$vals_pct     = $values_total > 0 ? $occupied / $values_total * 100 : 0;

	$lookups = (int) $info['hits'] + (int) $info['miss'];

	/* cold cache: the first reads are compulsory misses while the working
	   set loads, so any verdict would be a false alarm; report a neutral
	   warm-up state until enough lookups have passed */
	if ( $lookups < YAC_OCACHE_WARMUP_LOOKUPS ) {
		return array(
			'verdict'     => 'warmup',
			'rate'        => null,
			'lookups'     => $lookups,
			'keys_pct'    => $keys_pct,
			'vals_pct'    => $vals_pct,
			'kick_obs'    => 0,
			'kick_exp'    => 0,
			'foreign_pct' => 0,
			'causes'      => array(),
			'advice'      => '',
		);
	}

	/* yac builds with window counters report the hit rate of the last
	   completed ~20K-lookup window; older builds fall back to the
	   since-FPM-start aggregate */
	if ( isset( $info['win_rate'], $info['win_reset_tv'] ) && (int) $info['win_reset_tv'] > time() - 3600 ) {
		$rate = (float) $info['win_rate'] / 10;
	} else {
		$rate = $lookups > 0 ? (int) $info['hits'] / $lookups * 100 : 0;
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
		'lookups'     => $lookups,
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
function yac_ocache_pie( $slices ) {
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
	$svg    = '<svg class="yac-ocache-pie" viewBox="0 0 140 140" role="img">';

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
function yac_ocache_health_donut( $pct, $color, $center = null, $sub = 'hit rate' ) {
	$ratio = max( 0, min( 1, (float) $pct / 100 ) );
	$r     = 80;
	$circ  = 2 * M_PI * $r;
	$label = null === $center ? round( (float) $pct, 1 ) . '%' : $center;

	return sprintf(
		'<svg class="yac-ocache-health-donut" viewBox="0 0 200 200" role="img" aria-label="%1$s">'
		. '<circle class="yac-ocache-donut-track" cx="100" cy="100" r="%2$d"/>'
		. '<circle class="yac-ocache-donut-fill" cx="100" cy="100" r="%2$d" stroke="%3$s" stroke-dasharray="%4$s %5$s" transform="rotate(-90 100 100)"/>'
		. '<text class="yac-ocache-donut-pct" x="100" y="98">%1$s</text>'
		. '<text class="yac-ocache-donut-sub" x="100" y="122">%6$s</text>'
		. '</svg>',
		esc_attr( $label ),
		$r,
		esc_attr( $color ),
		esc_attr( round( $circ * $ratio, 2 ) ),
		esc_attr( round( $circ, 2 ) ),
		esc_attr( $sub )
	);
}

/* rows of [ directive, current value, scope, description ] for the config table */
function yac_ocache_config_rows() {
	return array(
		array(
			"define( 'WP_CACHE', true )",
			defined( 'WP_CACHE' ) && WP_CACHE ? 'enabled' : 'missing',
			'wp-config.php',
			'loads the object-cache.php drop-in',
		),
		array(
			"define( 'YAC_OCACHE_KEY_PREFIX', '…' )",
			sprintf(
				'%s%s',
				defined( 'YAC_OCACHE_KEY_PREFIX' ) ? YAC_OCACHE_KEY_PREFIX : '',
				defined( 'YAC_OCACHE_KEY_PREFIX' ) ? '' : ' (' . 'default' . ')'
			),
			'wp-config.php',
			'storage key prefix, 0-6 chars; the only isolation between installs sharing one PHP pool — use a different one per site; multisite blogs share it',
		),
		array(
			"define( 'YAC_OCACHE_SKIP_EMPTY', false )",
			defined( 'YAC_OCACHE_SKIP_EMPTY' ) && ! YAC_OCACHE_SKIP_EMPTY ? 'disabled' : 'enabled (default)',
			'wp-config.php',
			'the single pollution filter: empty get_page_by_path negatives (bot 404 probes, never re-read) stay request-local',
		),
		array(
			"define( 'YAC_OCACHE_DISABLE', true )",
			defined( 'YAC_OCACHE_DISABLE' ) && YAC_OCACHE_DISABLE ? 'enabled' : 'not set',
			'wp-config.php',
			'escape hatch: force runtime-only mode',
		),
	);
}

function yac_ocache_admin_menu() {
	add_management_page(
		'Yac Object Cache',
		'Yac Object Cache',
		'manage_options',
		YAC_OCACHE_ADMIN_PAGE,
		'yac_ocache_render_admin_page'
	);
}

function yac_ocache_admin_enqueue_scripts( $hook ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	wp_enqueue_script( 'yac-ocache-notice', YAC_OCACHE_PLUGIN_URL . 'assets/yac-ocache-notice.js', array(), YAC_OCACHE_VERSION, true );

	/* dashboard widget + every admin notice share these two pieces of data;
	   localize must run after the script is registered/enqueued, otherwise
	   WP silently drops the inline data */
	wp_localize_script( 'yac-ocache-notice', 'YAC_OCACHE_CONFIG', array(
		'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
		'noticeNonce' => wp_create_nonce( 'yac_ocache_dismiss_notice' ),
		'entryNonce'  => wp_create_nonce( 'yac_ocache_entry' ),
	) );

	if ( $screen && false !== strpos( $screen->id, YAC_OCACHE_ADMIN_PAGE ) ) {
		wp_enqueue_style( 'yac-ocache-admin', YAC_OCACHE_PLUGIN_URL . 'assets/yac-ocache.css', array(), YAC_OCACHE_VERSION );
		wp_enqueue_script( 'yac-ocache-admin', YAC_OCACHE_PLUGIN_URL . 'assets/yac-ocache-admin.js', array(), YAC_OCACHE_VERSION, true );
	}
}

/* the notice is rendered by admin_notices (priority 10), after
   admin_enqueue_scripts has fired, so the deferred enqueue of the
   dismiss script registers the file at print time instead */
function yac_ocache_admin_notice_script() {
	if ( ! yac_ocache_show_status_notice() ) {
		return;
	}
	wp_enqueue_script( 'yac-ocache-notice', YAC_OCACHE_PLUGIN_URL . 'assets/yac-ocache-notice.js', array(), YAC_OCACHE_VERSION, true );
	wp_print_scripts( 'yac-ocache-notice' );
}

/* compact health summary on the WP admin dashboard; the snapshot is
   shared-memory cached for 60s so the frequent dashboard loads do not
   re-walk the slot table */
function yac_ocache_register_dashboard_widget() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	wp_add_dashboard_widget( 'yac_ocache_health_widget', 'Yac Object Cache', 'yac_ocache_render_dashboard_widget' );
}

function yac_ocache_render_dashboard_widget() {
	$info = yac_ocache_storage_info();

	if ( null === $info ) {
		echo '<p>' . esc_html( 'Yac extension not loaded — the object cache runs runtime-only.' )
			. ' <a href="' . esc_url( admin_url( 'tools.php?page=' . YAC_OCACHE_ADMIN_PAGE ) ) . '">' . esc_html( 'Details' ) . '</a></p>';
		return;
	}

	$health = yac_ocache_health( $info, yac_ocache_memory_snapshot( 10, yac_ocache_key_prefix(), 60 ) );

	$colors = array( 'green' => '#00a32a', 'yellow' => '#dba617', 'red' => '#d63638', 'warmup' => '#8c8f94' );
	$color  = $colors[ $health['verdict'] ];
	$chips  = array( 'green' => '✓ Healthy', 'yellow' => '⚠ Attention', 'red' => '✗ Critical', 'warmup' => '… Warming up' );

	$warmup     = 'warmup' === $health['verdict'];
	$ring_label = $warmup ? 'N/A' : round( $health['rate'], 1 ) . '%';
	$ratio      = $warmup ? 0 : max( 0, min( 1, $health['rate'] / 100 ) );
	$r          = 45;
	$circ       = 2 * M_PI * $r;

	$keys_total   = (int) $info['slots_size'];
	$keys_used    = (int) $info['slots_used'];
	$values_total = (int) $info['values_memory_size'];
	?>
	<div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
		<div style="display: flex; flex-direction: column; align-items: center;">
			<svg width="120" height="120" viewBox="0 0 120 120" role="img" aria-label="<?php echo esc_attr( $ring_label ); ?>">
				<circle cx="60" cy="60" r="<?php echo esc_attr( $r ); ?>" stroke="#f0f0f1" stroke-width="12" fill="none"/>
				<circle cx="60" cy="60" r="<?php echo esc_attr( $r ); ?>" stroke="<?php echo esc_attr( $color ); ?>" stroke-width="12" fill="none" stroke-linecap="round" stroke-dasharray="<?php echo esc_attr( round( $circ * $ratio, 2 ) ); ?> <?php echo esc_attr( round( $circ, 2 ) ); ?>" transform="rotate(-90 60 60)"/>
				<text x="60" y="58" font-size="21" font-weight="600" fill="#1d2327" text-anchor="middle"><?php echo esc_html( $ring_label ); ?></text>
				<text x="60" y="76" font-size="10" fill="#646970" text-anchor="middle"><?php echo esc_html( $warmup ? 'warming up' : 'hit rate' ); ?></text>
			</svg>
			<div style="margin-top: 6px; border-radius: 20px; padding: 3px 12px; font-size: 12px; font-weight: 600; color: <?php echo esc_attr( $color ); ?>; background: <?php echo 'green' === $health['verdict'] ? '#edfaef' : ( 'yellow' === $health['verdict'] ? '#fcf9e8' : ( 'warmup' === $health['verdict'] ? '#f0f0f1' : '#fcf0f1' ) ); ?>;"><?php echo esc_html( $chips[ $health['verdict'] ] ); ?></div>
		</div>
		<div style="flex: 1; min-width: 220px; font-size: 13px; color: #50575e;">
			<div style="display: flex; justify-content: space-between; padding: 3px 0;"><span><?php echo esc_html( 'Keys' ); ?></span><strong style="color: #1d2327;"><?php echo esc_html( number_format_i18n( $keys_used ) . ' / ' . number_format_i18n( $keys_total ) ); ?></strong></div>
			<div style="display: flex; justify-content: space-between; padding: 3px 0;"><span><?php echo esc_html( 'Values occupied' ); ?></span><strong style="color: #1d2327;"><?php echo esc_html( yac_ocache_format_bytes( $health['vals_pct'] / 100 * $values_total ) . ' / ' . yac_ocache_format_bytes( $values_total ) ); ?></strong></div>
			<div style="display: flex; justify-content: space-between; padding: 3px 0;"><span><?php echo esc_html( 'Hits / Misses' ); ?></span><strong style="color: #1d2327;"><?php echo esc_html( number_format_i18n( (int) $info['hits'] ) . ' / ' . number_format_i18n( (int) $info['miss'] ) ); ?></strong></div>
			<div style="display: flex; justify-content: space-between; padding: 3px 0;"><span><?php echo esc_html( 'Kicks / Recycles' ); ?></span><strong style="color: #1d2327;"><?php echo esc_html( number_format_i18n( (int) $info['kicks'] ) . ' / ' . number_format_i18n( (int) $info['recycles'] ) ); ?></strong></div>
			<p style="margin: 8px 0 0;"><a href="<?php echo esc_url( admin_url( 'tools.php?page=' . YAC_OCACHE_ADMIN_PAGE ) ); ?>"><?php echo esc_html( 'Full dashboard' ); ?></a></p>
		</div>
	</div>
	<?php
}

function yac_ocache_admin_notices() {
	/* only right after activation, when WP redirects to plugins.php with
	   activate=1; a miss on this transient is otherwise paid on every
	   admin page load */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display; the actions that set it were nonce-verified
	if ( isset( $_GET['activate'] ) && get_transient( 'yac_ocache_activated' ) ) {
		delete_transient( 'yac_ocache_activated' );

		$url = '<a href="' . esc_url( admin_url( 'tools.php?page=' . YAC_OCACHE_ADMIN_PAGE ) ) . '">' . esc_html( 'Tools → Yac Object Cache' ) . '</a>';

		if ( yac_ocache_dropin_is_ours() ) {
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

	if ( ! yac_ocache_is_operational() ) {
		yac_ocache_status_notice();
	}
}

/* one combined dismissible notice for error-level status rows; kept off the
   plugin's own page (that page shows the same diagnostics in full) */
function yac_ocache_show_status_notice() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && false !== strpos( $screen->id, YAC_OCACHE_ADMIN_PAGE ) ) {
		return false;
	}

	$errors = array();
	foreach ( yac_ocache_status() as $row ) {
		if ( 'err' === $row[1] ) {
			$errors[] = $row[2];
		}
	}

	if ( ! $errors ) {
		return false;
	}

	/* the dismissal sticks to this exact set of errors: a different
	   fingerprint means the situation changed, so show it again */
	if ( get_user_meta( get_current_user_id(), 'yac_ocache_notice_dismissed', true ) === md5( implode( "\n", $errors ) ) ) {
		return false;
	}

	return true;
}

function yac_ocache_status_notice() {
	if ( ! yac_ocache_show_status_notice() ) {
		return;
	}

	$errors = array();
	foreach ( yac_ocache_status() as $row ) {
		if ( 'err' === $row[1] ) {
			$errors[] = $row[2];
		}
	}

	echo '<div class="notice notice-error is-dismissible" id="yac-ocache-status-notice"><p><strong>' . esc_html( 'Yac:' ) . '</strong> ' . wp_kses_post( implode( '<br>', $errors ) ) . '</p>'
		. '<p><a href="' . esc_url( admin_url( 'tools.php?page=' . YAC_OCACHE_ADMIN_PAGE ) ) . '">' . esc_html( 'Open the status page for details and fixes' ) . '</a></p>'
		. '</div>';
}

/* persist the dismissal; no privilege escalation: only the current user's
   meta is touched, and a dismissed notice stays dismissed until the set of
   errors changes */
function yac_ocache_ajax_dismiss_status_notice() {
	check_ajax_referer( 'yac_ocache_dismiss_notice' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error();
	}

	$errors = array();
	foreach ( yac_ocache_status() as $row ) {
		if ( 'err' === $row[1] ) {
			$errors[] = $row[2];
		}
	}

	update_user_meta( get_current_user_id(), 'yac_ocache_notice_dismissed', md5( implode( "\n", $errors ) ) );
	wp_send_json_success();
}

/* entry inspector: per-entry metadata from Yac::dump(), the deserialized
   value via get(). atime/hits/embedded only exist in newer yac builds —
   detected at runtime, shown as unavailable otherwise. ttl is an absolute
   expiry timestamp in every build (0 = never). */
function yac_ocache_entry_detail( $yac, $key ) {
	$meta = null;
	$dump = yac_ocache_dump_all( $yac );
	if ( is_array( $dump ) ) {
		foreach ( $dump as $it ) {
			if ( isset( $it['key'] ) && $it['key'] === $key ) {
				$meta = $it;
				break;
			}
		}
	}

	$value = $yac->get( $key );
	if ( is_array( $value ) && 1 === count( $value ) && array_key_exists( 'v', $value ) ) {
		$value = $value['v']; // unwrap the drop-in's miss-vs-false wrapper
	}

	/* released Yac 2.4.0 still returns false on a miss (older builds too);
	   the new get($key, $default) parameter only changes what a miss
	   returns when a default is asked for, not this sentinel */
	$gone = false === $value;

	$content = is_string( $value ) ? $value : json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $content ) ) {
		$content = print_r( $value, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- fallback renderer when json_encode() fails; returns a string, never printed
	}
	$len       = strlen( $content );
	$truncated = $len > 131072;
	if ( $truncated ) {
		$content = substr( $content, 0, 131072 );
	}

	return array(
		'key'         => $key,
		'v_len'       => $meta ? yac_ocache_format_bytes( $meta['v_len'] ) : '—',
		/* c_len: the compressed payload actually stored, present only for
		   compressed entries (Yac >= 2.4.0 dumps); on those v_len is the
		   original uncompressed length */
		'c_len'       => ( $meta && array_key_exists( 'c_len', $meta ) ) ? yac_ocache_format_bytes( $meta['c_len'] ) : null,
		'size'        => $meta ? yac_ocache_format_bytes( $meta['size'] ) : '—',
		'ttl'         => $meta ? (int) $meta['ttl'] : 0,
		'atime'       => ( $meta && array_key_exists( 'atime', $meta ) ) ? (int) $meta['atime'] : null,
		/* per-entry hits/embedded only exist in newer Yac builds (the
		   same ones that expose atime); null = not supported here */
		'hits'        => ( $meta && array_key_exists( 'hits', $meta ) ) ? (int) $meta['hits'] : null,
		'embedded'    => ( $meta && array_key_exists( 'embedded', $meta ) ) ? (bool) $meta['embedded'] : null,
		'gone'        => $gone,
		'content'     => $content,
		'content_len' => $len,
		'truncated'   => $truncated,
	);
}

function yac_ocache_entry_delete( $yac, $key ) {
	return (bool) $yac->delete( $key );
}

function yac_ocache_ajax_entry() {
	check_ajax_referer( 'yac_ocache_entry' );

	if ( ! current_user_can( 'manage_options' ) || ! yac_ocache_backend_usable() ) {
		wp_send_json_error();
	}
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) {
		wp_send_json_error();
	}

	wp_send_json_success( yac_ocache_entry_detail( new Yac(), $key ) );
}

function yac_ocache_ajax_entry_delete() {
	check_ajax_referer( 'yac_ocache_entry' );

	if ( ! current_user_can( 'manage_options' ) || ! yac_ocache_backend_usable() ) {
		wp_send_json_error();
	}
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	if ( '' === $key ) {
		wp_send_json_error();
	}

	wp_send_json_success( array( 'deleted' => yac_ocache_entry_delete( new Yac(), $key ) ) );
}

/* flush / redeploy / remove via admin-post */
function yac_ocache_admin_init() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified in the action handler below before any state change
	if ( ! isset( $_POST['yac_ocache_action'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'yac_ocache_admin' );

	$redirect = admin_url( 'tools.php?page=' . YAC_OCACHE_ADMIN_PAGE );

	switch ( $_POST['yac_ocache_action'] ) {
		case 'redeploy':
			$ok = yac_ocache_deploy_dropin();
			wp_safe_redirect( add_query_arg( 'yac_ocache_notice', $ok ? 'deployed' : 'deploy_failed', $redirect ) );
			exit;

		case 'update_dropin':
			$ok = yac_ocache_deploy_dropin( true );
			wp_safe_redirect( add_query_arg( 'yac_ocache_notice', $ok ? 'deployed' : 'deploy_failed', $redirect ) );
			exit;

		case 'flush':
			if ( yac_ocache_is_operational() && function_exists( 'wp_cache_flush' ) ) {
				$result = wp_cache_flush();
			} else {
				$result = false;
			}
			wp_safe_redirect( add_query_arg( 'yac_ocache_notice', $result ? 'flushed' : 'flush_failed', $redirect ) );
			exit;

		case 'remove':
			$removed = false;
			if ( yac_ocache_dropin_is_ours() ) {
				$removed = wp_delete_file( YAC_OCACHE_DROPIN_DEST ) && ! file_exists( YAC_OCACHE_DROPIN_DEST );
			}
			wp_safe_redirect( add_query_arg( 'yac_ocache_notice', $removed ? 'removed' : 'remove_failed', $redirect ) );
			exit;
	}
}

function yac_ocache_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'You do not have permission to access this page.' ) );
	}

	$info = yac_ocache_storage_info();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice display; the actions that set it were nonce-verified
	$yac_ocache_notice_key = isset( $_GET['yac_ocache_notice'] ) ? sanitize_key( wp_unslash( $_GET['yac_ocache_notice'] ) ) : '';
	if ( $yac_ocache_notice_key ) {
		$notices = array(
			'deployed'      => array( 'success', 'Drop-in deployed.' ),
			'deploy_failed' => array( 'error', 'Deployment failed: a foreign object-cache.php exists or wp-content is not writable.' ),
			'flushed'       => array( 'success', 'Object cache flushed: the entire Yac shared memory on this machine was cleared.' ),
			'flush_failed'  => array( 'error', 'Flush failed: the cache backend is not operational.' ),
			'removed'       => array( 'success', 'Drop-in removed.' ),
			'remove_failed' => array( 'error', 'Could not remove the drop-in (permissions, or it is not owned by Yac).' ),
		);
		$notice = isset( $notices[ $yac_ocache_notice_key ] ) ? $notices[ $yac_ocache_notice_key ] : null;
		if ( $notice ) {
			echo '<div class="notice notice-' . esc_attr( $notice[0] ) . ' is-dismissible"><p>' . esc_html( $notice[1] ) . '</p></div>';
		}
	}
	?>
	<div class="wrap yac-ocache-wrap">
		<h1><?php echo esc_html( 'Yac Object Cache' ); ?></h1>

		<?php if ( null === $info ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( 'Yac extension is not loaded. The charts below need a running Yac backend.' ); ?></p></div>
		<?php endif; ?>

		<h2><?php echo esc_html( 'Status' ); ?></h2>
		<?php
		$yac_ocache_problems = array_values( array_filter( yac_ocache_status(), function ( $row ) {
			return 'ok' !== $row[1];
		} ) );
		?>
		<?php $self_test = yac_ocache_self_test(); ?>
		<?php if ( empty( $yac_ocache_problems ) ) : ?>
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
				<?php foreach ( $yac_ocache_problems as $row ) : ?>
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
			$snapshot = yac_ocache_memory_snapshot( 10, yac_ocache_key_prefix() );
			$health   = yac_ocache_health( $info, $snapshot );

			$health_colors = array( 'green' => '#00a32a', 'yellow' => '#dba617', 'red' => '#d63638', 'warmup' => '#8c8f94' );
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
			   cause of the verdict, green otherwise (neutral gray while
			   the cache is still warming up and nothing is judged) */
			$yac_ocache_bar = function ( $label, $width, $color, $value_html ) {
				return '<li><span class="lbl">' . esc_html( $label ) . '</span>'
					. '<span class="track"><span class="fill" style="width: ' . esc_attr( round( min( 100, max( 0, $width ) ), 1 ) ) . '%; background: ' . esc_attr( $color ) . '"></span></span>'
					. '<span class="val">' . $value_html . '</span></li>';
			};
			$yac_ocache_idle  = 'warmup' === $health['verdict'] ? '#8c8f94' : '#00a32a';
			$yac_ocache_cause = function ( $key ) use ( $health, $health_color, $yac_ocache_idle ) {
				return in_array( $key, $health['causes'], true ) ? $health_color : $yac_ocache_idle;
			};

			$yac_ocache_bars  = $yac_ocache_bar( 'Keys', $health['keys_pct'], $yac_ocache_cause( 'keys' ), number_format_i18n( $keys_used ) . ' <small>/ ' . number_format_i18n( $keys_total ) . '</small>' );
			$yac_ocache_bars .= $yac_ocache_bar( 'Values', $health['vals_pct'], $yac_ocache_cause( 'values' ), esc_html( yac_ocache_format_bytes( $occupied ) ) . ' <small>/ ' . esc_html( yac_ocache_format_bytes( $values_total ) ) . '</small>' );
			$yac_ocache_bars .= $yac_ocache_bar( 'Hits', $lookups > 0 ? $hits / $lookups * 100 : 0, $yac_ocache_idle, number_format_i18n( $hits ) );
			$yac_ocache_bars .= $yac_ocache_bar( 'Misses', $lookups > 0 ? $misses / $lookups * 100 : 0, $yac_ocache_cause( 'misses' ), number_format_i18n( $misses ) );
			$yac_ocache_bars .= $yac_ocache_bar( 'Kicks', $kicks / max( 1, $ops_total ) * 100, $yac_ocache_cause( 'kicks' ), number_format_i18n( $kicks ) . ( $kicks > 0 && $misses > 0 ? ' <small>(≈' . round( $kicks / $misses * 100 ) . '% of misses)</small>' : '' ) );
			$yac_ocache_bars .= $yac_ocache_bar( 'Recycles', $recycles / max( 1, $ops_total ) * 100, $yac_ocache_cause( 'recycles' ), number_format_i18n( $recycles ) );

			$yac_ocache_chips = array(
				'green'  => array( 'yac-ocache-chip-good', '✓ Healthy' ),
				'yellow' => array( 'yac-ocache-chip-warn', '⚠ Attention' ),
				'red'    => array( 'yac-ocache-chip-err', '✗ Critical' ),
				'warmup' => array( 'yac-ocache-chip-warmup', '… Warming up' ),
			);
			$yac_ocache_chip = $yac_ocache_chips[ $health['verdict'] ];

			if ( 'green' !== $health['verdict'] ) {
				$yac_ocache_advices = array(
					'keys'        => array( 'yac-ocache-advice-warn', '⚠', sprintf( 'Key slots full and hit rate below 90%% — entries are being kicked before re-read. Raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ),
					'keys-strong' => array( 'yac-ocache-advice-err', '✗', sprintf( 'Key slots full and hit rate below 70%% — the cache is thrashing and requests fall through to the database. Strongly raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ),
					'values'      => array( 'yac-ocache-advice-warn', '⚠', sprintf( 'Keys not full but values full — the current entries occupy more than the values pool and get ring-overwritten before re-read. Raise <code>yac.values_memory_size</code> (currently %s).', esc_html( yac_ocache_format_bytes( $values_total ) ) ) ),
					'distribution'=> array( 'yac-ocache-advice-warn', '⚠', sprintf( 'Kicks are %1$.1f%% of inserts vs ≈%2$.1f%% expected under uniform hashing — the placement is unlucky or the slot table too small for the churn. Change <code>YAC_OCACHE_KEY_PREFIX</code> to re-roll the layout (costs one cold start), or raise <code>yac.keys_memory_size</code> (a new mask re-spreads the keys too).', $health['kick_obs'], $health['kick_exp'] ) ),
					'keys-early'  => array( 'yac-ocache-advice-warn', '⚠', sprintf( 'Key slots are not full but the hit rate is under 90%% and a third or more of misses are eviction-driven — slot pressure arrives early. Raise <code>yac.keys_memory_size</code> (currently %s).', esc_html( ini_get( 'yac.keys_memory_size' ) ) ) ),
					'foreign'     => array( 'yac-ocache-advice-warn', '⚠', sprintf( 'Only %1$.0f%% of the slotted entries belong to this install — the slot pressure is shared-pool occupancy from other Yac users on this machine. Raise <code>yac.keys_memory_size</code> or run a separate pool.', 100 - $health['foreign_pct'] ) ),
				);
			}
			?>
			<h2><?php echo esc_html( 'Cache health' ); ?></h2>
			<div class="yac-ocache-panel">
				<div class="yac-ocache-health">
					<div class="yac-ocache-health-ring">
						<?php
						/* no wp_kses_post here: the post kses strip SVG tags,
						   which would eat the ring and leave only the center
						   text; health_donut() escapes every interpolation */
						// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
						echo 'warmup' === $health['verdict']
							? yac_ocache_health_donut( 0, $health_color, 'N/A', 'warming up' )
							: yac_ocache_health_donut( $health['rate'], $health_color );
						// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<span class="yac-ocache-chip <?php echo esc_attr( $yac_ocache_chip[0] ); ?>"><?php echo esc_html( $yac_ocache_chip[1] ); ?></span>
					</div>
					<div class="yac-ocache-health-bars">
						<ul class="yac-ocache-bars"><?php echo $yac_ocache_bars; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled from escaped parts ?></ul>
						<p class="yac-ocache-note"><?php echo esc_html( 'Values bar = Σ entry.size (padded). All bars green when healthy; only the causing metrics take the verdict color.' ); ?></p>
					</div>
				</div>
				<?php if ( 'warmup' === $health['verdict'] ) : ?>
					<div class="yac-ocache-advice yac-ocache-advice-info">
						<span>…</span>
						<div><?php echo esc_html( sprintf( 'Warming up — the cache just started and early lookups are compulsory first reads, so no verdict yet. Health metrics start after the first %s lookups (%s so far).', number_format_i18n( YAC_OCACHE_WARMUP_LOOKUPS ), number_format_i18n( $health['lookups'] ) ) ); ?></div>
					</div>
				<?php elseif ( '' !== $health['advice'] ) : ?>
					<?php $yac_ocache_advice = $yac_ocache_advices[ $health['advice'] ]; ?>
					<div class="yac-ocache-advice <?php echo esc_attr( $yac_ocache_advice[0] ); ?>">
						<span><?php echo esc_html( $yac_ocache_advice[1] ); ?></span>
						<div><?php echo wp_kses_post( $yac_ocache_advice[2] ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<h2><?php echo esc_html( 'Shared memory contents' ); ?></h2>
			<div class="yac-ocache-panel">
				<?php if ( null === $snapshot ) : ?>
					<p class="yac-ocache-note"><?php echo esc_html( 'Requires the Yac extension.' ); ?></p>
				<?php else : ?>
					<div class="yac-ocache-contents">
						<div class="yac-ocache-contents-left">
							<?php if ( ! empty( $snapshot['groups'] ) ) : ?>
								<?php
								$yac_ocache_palette = array( '#2271b1', '#72aee6', '#00a32a', '#dba617', '#d63638', '#787c82', '#996800' );
								$yac_ocache_slices  = array();
								foreach ( $snapshot['groups'] as $yac_ocache_gi => $yac_ocache_g ) {
									$yac_ocache_slices[] = array( $yac_ocache_g['label'], $yac_ocache_g['n'], $yac_ocache_g['bytes'], $yac_ocache_palette[ $yac_ocache_gi % 7 ] );
								}
								?>
								<?php echo yac_ocache_pie( array_map( function ( $s ) { return array( $s[0], $s[1], $s[3] ); }, $yac_ocache_slices ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<ul class="yac-ocache-pie-legend">
								<?php foreach ( $yac_ocache_slices as $yac_ocache_s ) : ?>
									<li>
										<span class="dot" style="background: <?php echo esc_attr( $yac_ocache_s[3] ); ?>"></span>
										<span class="g" title="<?php echo esc_attr( $yac_ocache_s[0] ); ?>"><?php echo esc_html( $yac_ocache_s[0] ); ?> <small><?php echo esc_html( round( $yac_ocache_s[1] / max( 1, $snapshot['entries'] ) * 100 ) . '%' ); ?></small></span>
										<strong><?php echo esc_html( number_format_i18n( $yac_ocache_s[1] ) ); ?> <small>keys &middot; <?php echo esc_html( yac_ocache_format_bytes( $yac_ocache_s[2] ) ); ?></small></strong>
									</li>
								<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
						<div class="yac-ocache-contents-right">
							<ul class="yac-ocache-op-list">
								<li><span><?php echo esc_html( 'Total entries' ); ?></span><strong><?php echo esc_html( number_format_i18n( $snapshot['entries'] ) ); ?></strong></li>
								<li><span><?php echo esc_html( 'Occupied (Σ size, padded)' ); ?></span><strong><?php echo esc_html( yac_ocache_format_bytes( $snapshot['occupied'] ) ); ?></strong></li>
								<li><span><?php echo esc_html( 'Content (Σ v_len)' ); ?></span><strong><?php echo esc_html( yac_ocache_format_bytes( $snapshot['bytes'] ) ); ?></strong></li>
								<li><span><?php echo esc_html( 'Average occupied / entry' ); ?></span><strong><?php echo esc_html( yac_ocache_format_bytes( $snapshot['average'] ) ); ?></strong></li>
							</ul>
							<?php if ( ! empty( $snapshot['largest'] ) ) : ?>
								<?php
								/* tabs only when this Yac build reports per-entry
								   hits; the bar width is normalized to the
								   tab's own axis */
								$yac_ocache_lists = array(
									'largest' => array( 'Largest', 'by content length', $snapshot['largest'], 0, max( 1, (int) $snapshot['largest'][0][0] ) ),
								);
								if ( ! empty( $snapshot['has_meta'] ) ) {
									$yac_ocache_lists['hottest'] = array( 'Hottest', 'by access count', $snapshot['hottest'], 3, max( 1, (int) $snapshot['hits_max'] ) );
								}
								?>
								<h3 style="margin: 14px 0 6px; font-size: 13px"><?php echo esc_html( 'Top entries' ); ?></h3>
								<?php if ( count( $yac_ocache_lists ) > 1 ) : ?>
									<div class="yac-ocache-tabs" role="tablist">
										<?php foreach ( $yac_ocache_lists as $yac_ocache_tab_id => $yac_ocache_tab ) : ?>
											<button type="button" role="tab" id="yac-ocache-tab-<?php echo esc_attr( $yac_ocache_tab_id ); ?>" class="yac-ocache-tab<?php echo 'largest' === $yac_ocache_tab_id ? ' is-active' : ''; ?>" aria-selected="<?php echo 'largest' === $yac_ocache_tab_id ? 'true' : 'false'; ?>" data-yac-ocache-list="<?php echo esc_attr( $yac_ocache_tab_id ); ?>"><?php echo esc_html( $yac_ocache_tab[0] ); ?></button>
										<?php endforeach; ?>
									</div>
								<?php endif; ?>
								<?php foreach ( $yac_ocache_lists as $yac_ocache_tab_id => $yac_ocache_tab ) : ?>
									<?php
									$yac_ocache_caption = esc_html( $yac_ocache_tab[1] );
									if ( 'hottest' === $yac_ocache_tab_id ) {
										$yac_ocache_caption .= ' &middot; top ' . esc_html( number_format_i18n( $yac_ocache_tab[4] ) ) . ' hits';
									}
									?>
									<ul class="yac-ocache-entry-bars yac-ocache-entry-bars-lg yac-ocache-entry-list<?php echo 'largest' === $yac_ocache_tab_id ? '' : ' is-hidden'; ?>" data-yac-ocache-list="<?php echo esc_attr( $yac_ocache_tab_id ); ?>" aria-labelledby="yac-ocache-tab-<?php echo esc_attr( $yac_ocache_tab_id ); ?>">
										<li class="yac-ocache-entry-caption"><?php echo $yac_ocache_caption; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above ?></li>
										<?php foreach ( $yac_ocache_tab[2] as $entry ) : ?>
											<?php
											$yac_ocache_value = 'hottest' === $yac_ocache_tab_id ? $entry[3] : $entry[0];
											$yac_ocache_pct   = round( (int) $yac_ocache_value / $yac_ocache_tab[4] * 100 );
											?>
											<li>
												<button type="button" class="yac-ocache-entry-inspect" data-key="<?php echo esc_attr( $entry[2] ); ?>" title="<?php echo esc_attr( $entry[2] ); ?>"><?php echo esc_html( $entry[2] ); ?></button>
												<span class="yac-ocache-entry-bar-track"><span class="yac-ocache-entry-bar" style="width: <?php echo esc_attr( $yac_ocache_pct ); ?>%"></span></span>
												<strong><?php echo 'hottest' === $yac_ocache_tab_id ? esc_html( number_format_i18n( $yac_ocache_value ) ) : esc_html( yac_ocache_format_bytes( $yac_ocache_value ) ); ?></strong>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endforeach; ?>
							<?php endif; ?>
							<p class="yac-ocache-note"><?php echo esc_html( 'Occupied = Σ entry.size (padded); Content = Σ v_len; overwritten-but-unreclaimed entries slightly overstate. Flush wipes the machine’s entire shared memory.' ); ?></p>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<h2><?php echo esc_html( 'Configuration' ); ?></h2>
			<div class="yac-ocache-panel">
				<table class="yac-ocache-config-table">
					<thead>
						<tr>
							<th><?php echo esc_html( 'Directive' ); ?></th>
							<th><?php echo esc_html( 'Current value' ); ?></th>
							<th><?php echo esc_html( 'Where' ); ?></th>
							<th><?php echo esc_html( 'Description' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( yac_ocache_config_rows() as $row ) : ?>
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
		<div class="yac-ocache-panel">
			<table class="yac-ocache-config-table">
				<thead>
					<tr>
						<th><?php echo esc_html( 'Item' ); ?></th>
						<th><?php echo esc_html( 'Value' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<tr>
					<td><?php echo esc_html( 'Plugin version' ); ?></td>
					<td><code><?php echo esc_html( YAC_OCACHE_VERSION ); ?></code></td>
				</tr>
				<tr>
					<td><?php echo esc_html( 'Drop-in version' ); ?></td>
					<td>
						<?php $dropin_version = yac_ocache_dropin_version(); ?>
						<?php if ( null === $dropin_version ) : ?>
							<code>—</code>
						<?php else : ?>
							<code><?php echo esc_html( $dropin_version ); ?></code>
							<?php if ( version_compare( YAC_OCACHE_VERSION, $dropin_version, '>' ) ) : ?>
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
					<td><code><?php echo esc_html( ( defined( 'YAC_MAX_KEY_LEN' ) ? YAC_MAX_KEY_LEN : 48 ) ); ?> B</code> max, <code><?php echo esc_html( ( defined( 'YAC_MAX_KEY_LEN' ) ? YAC_MAX_KEY_LEN : 48 ) - strlen( yac_ocache_key_prefix() ) ); ?> B</code> logical after prefix <code><?php echo esc_html( yac_ocache_key_prefix() ); ?></code> — <?php echo esc_html( 'longer keys keep the group and hash the rest' ); ?></td>
				</tr>
				<?php if ( null !== $info ) : ?>
					<tr>
						<td><?php echo esc_html( 'Shared memory segments' ); ?></td>
						<td><code><?php echo esc_html( $info['segment_num'] . ' × ' . yac_ocache_format_bytes( $info['segment_size'] ) ); ?></code></td>
					</tr>
					<?php endif; ?>
				<tr>
					<td><?php echo esc_html( 'Drop-in path' ); ?></td>
					<td><code><?php echo esc_html( YAC_OCACHE_DROPIN_DEST ); ?></code></td>
				</tr>
				</tbody>
			</table>
		</div>

		<h2><?php echo esc_html( 'Actions' ); ?></h2>
		<div class="yac-ocache-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( 'Flush the object cache? This wipes the ENTIRE Yac shared memory on this machine, including data of other Yac users sharing the PHP pool.' ); ?>')">
				<?php wp_nonce_field( 'yac_ocache_admin' ); ?>
				<input type="hidden" name="yac_ocache_action" value="flush">
				<button class="button button-primary"><?php echo esc_html( 'Flush object cache' ); ?></button>
			</form>
			<?php if ( ! yac_ocache_dropin_is_ours() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'yac_ocache_admin' ); ?>
					<input type="hidden" name="yac_ocache_action" value="redeploy">
					<button class="button"><?php echo esc_html( 'Deploy drop-in' ); ?></button>
				</form>
			<?php else : ?>
				<?php if ( version_compare( YAC_OCACHE_VERSION, (string) yac_ocache_dropin_version(), '>' ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'yac_ocache_admin' ); ?>
						<input type="hidden" name="yac_ocache_action" value="update_dropin">
						<button class="button button-primary"><?php echo esc_html( 'Update drop-in' ); ?></button>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( 'Remove the Yac drop-in? The object cache will fall back to WordPress default (database/options).' ); ?>')">
					<?php wp_nonce_field( 'yac_ocache_admin' ); ?>
					<input type="hidden" name="yac_ocache_action" value="remove">
					<button class="button button-link-delete"><?php echo esc_html( 'Remove drop-in' ); ?></button>
				</form>
			<?php endif; ?>
		</div>

		<div class="yac-ocache-modal-mask" id="yac-ocache-modal" hidden>
			<div class="yac-ocache-modal" role="dialog" aria-modal="true">
				<div class="yac-ocache-modal-head">
					<code id="yac-ocache-modal-key"></code>
					<button type="button" class="yac-ocache-modal-x" data-yac-ocache-close aria-label="Close">×</button>
				</div>
				<ul class="yac-ocache-op-list" id="yac-ocache-modal-meta"></ul>
				<h3 class="yac-ocache-modal-sub"><?php echo esc_html( 'Deserialized value' ); ?></h3>
				<pre class="yac-ocache-modal-pre" id="yac-ocache-modal-body"></pre>
				<div class="yac-ocache-modal-foot">
					<button type="button" class="button button-link-delete" id="yac-ocache-modal-delete"><?php echo esc_html( 'Delete this entry' ); ?></button>
					<button type="button" class="button" data-yac-ocache-close><?php echo esc_html( 'Close' ); ?></button>
				</div>
			</div>
		</div>
	</div>
	<?php
}

function yac_ocache_site_health_info( $info ) {
	$operational = yac_ocache_is_operational();

	$info['yac_ocache'] = array(
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
				'value' => yac_ocache_dropin_is_ours() ? 'Deployed by Yac' : 'Missing or foreign',
				'debug' => yac_ocache_dropin_is_ours() ? 'ours' : 'missing-or-foreign',
			),
		),
	);

	return $info;
}

if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {

	class YAC_OCACHE_CLI_Command {

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

			foreach ( yac_ocache_status() as $row ) {
				$mark = 'ok' === $row[1] ? 'OK  ' : ( 'warn' === $row[1] ? 'WARN' : 'ERR ' );
				\WP_CLI::log( sprintf( '[%s] %s', $mark, $strip_tags( $row[2] ) ) );
			}
		}
	}

	\WP_CLI::add_command( 'yac', 'YAC_OCACHE_CLI_Command' );
}
