<?php
/*
 * Yac Object Cache: Yac-backed drop-in for the WordPress object cache.
 * Install this file to wp-content/object-cache.php.
 *
 * Yac keeps the cache in shared memory inherited by all PHP-FPM workers,
 * so a get() is one hash lookup: no server, no socket, no network.
 *
 * - Keys are stored verbatim as "<prefix><group>:<key>" as long as they
 *   fit Yac's 48-byte limit (instance prefix included); over-long keys
 *   keep the group verbatim and hash (crc32b) only the key part, so
 *   dumps and the dashboard pie chart stay attributable by group.
 * - WP_YAC_SKIP_EMPTY (default on, the single pollution filter): bots
 *   probe unbounded one-off URLs, and each 404 path mints a
 *   get_page_by_path:<md5> key whose value is an empty negative result
 *   never re-read, yet each occupies a slot — those empty values stay
 *   request-local. Stable per-entity negative caches (comment children,
 *   term relationships, adjacent posts...) are re-read on every view
 *   and keep being shared; when their working set outgrows the slot
 *   table, the remedy is a bigger yac.keys_memory_size, not skipping.
 * - Keys carry no per-blog prefix: single-node installs are the target
 *   and per-site isolation lives in WP_YAC_KEY_PREFIX instead. Installs
 *   sharing one PHP pool must use different prefixes; multisite blogs
 *   share the namespace by design.
 * - wp_cache_flush() calls Yac::flush() and clears the ENTIRE shared
 *   memory on this machine, including data of other Yac users.
 * - Values are stored raw so Yac can embed small scalars (null,
 *   bool, int, string <= 7 bytes, empty array) in the slot itself
 *   without allocating a value block. false is coerced to 0 before
 *   writing: Yac::get() returns false for both a miss and a stored
 *   false, so false cannot round-trip through shared memory; 0 does.
 *   Readers that compare by value see 0 instead of false — WP core's
 *   loose checks make no difference. null reads back as a found
 *   negative result and stays the shared negative cache. Upgrading
 *   from the old wrapped format needs one flush (old array('v' => ...)
 *   entries would read back raw).
 * - Without Yac it degrades to a per-request cache; WP keeps working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_YAC_KEY_PREFIX' ) ) {
	/* human readable part of the storage key; 0-6 chars, shorter is
	   better: every byte here shrinks the room for the logical key */
	define( 'WP_YAC_KEY_PREFIX', 'wp' );
}

if ( ! defined( 'WP_YAC_DROPIN_VERSION' ) ) {
	define( 'WP_YAC_DROPIN_VERSION', '1.1.1' );
}

if ( ! defined( 'WP_YAC_SKIP_EMPTY' ) ) {
	define( 'WP_YAC_SKIP_EMPTY', true );
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_incr( $key, $n = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->incr( $key, $n, $group );
}

function wp_cache_decr( $key, $n = 1, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->decr( $key, $n, $group );
}

function wp_cache_close() {
	return true; /* nothing to close: shared memory, no connection */
}

function wp_cache_delete( $key, $group = '' ) {
	global $wp_object_cache;

	return $wp_object_cache->delete( $key, $group );
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	global $wp_object_cache;

	$value = apply_filters( 'pre_wp_cache_get', false, $key, $group, $force, $found );
	if ( false !== $value ) {
		$found = true;
		return $value;
	}

	return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_get_multi( $groups ) {
	global $wp_object_cache;

	return $wp_object_cache->get_multi( $groups );
}

function wp_cache_init() {
	global $wp_object_cache;

	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	return $wp_object_cache->replace( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	global $wp_object_cache;

	if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING ) {
		return $wp_object_cache->delete( $key, $group );
	}

	return $wp_object_cache->set( $key, $data, $group, $expire );
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;

	return $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_flush_runtime() {
	global $wp_object_cache;

	return $wp_object_cache->flush_runtime( '' );
}

function wp_cache_supports( $feature ) {
	switch ( $feature ) {
		case 'add_multiple':
		case 'set_multiple':
		case 'get_multiple':
		case 'delete_multiple':
		case 'flush_runtime':
		case 'get_with_multiple':
			return true;

		default:
			return false;
	}
}

/* WP 6.1+; guarded in case another drop-in got loaded first */
if ( ! function_exists( 'wp_cache_add_multiple' ) ) {
	function wp_cache_add_multiple( array $data, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->add_multiple( $data, $group );
	}
}

if ( ! function_exists( 'wp_cache_set_multiple' ) ) {
	function wp_cache_set_multiple( array $data, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->set_multiple( $data, $group );
	}
}

if ( ! function_exists( 'wp_cache_get_multiple' ) ) {
	function wp_cache_get_multiple( array $keys, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->get_multiple( $keys, $group );
	}
}

if ( ! function_exists( 'wp_cache_delete_multiple' ) ) {
	function wp_cache_delete_multiple( array $keys, $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->delete_multiple( $keys, $group );
	}
}

if ( ! function_exists( 'wp_cache_flush_group' ) ) {
	function wp_cache_flush_group( $group = '' ) {
		global $wp_object_cache;

		return $wp_object_cache->flush_group( $group );
	}
}

class WP_Object_Cache {

	private $yac = null; /* data store; null => runtime-only mode */

	public $yac_available = false;

	private $cache = array(); /* request-level: key => value/found/group */

	private $non_persistent_groups = array();
	private $global_groups = array();

	private $storage_prefix = ''; /* Yac instance prefix; the only per-site isolation */
	private $logical_key_budget = 34; /* bytes left after the instance prefix */

	public $cache_hits = 0;
	public $cache_misses = 0;

	public $group_ops = array();
	private $stats = array();
	private $time_start = 0;
	private $time_total = 0;

	private $slow_op_microseconds = 0.005;

	/* keys written by this request; Yac::set() may return false on CAS
	   contention even though the value landed, so don't rewrite those */
	private $written = array();

	public function __construct() {
		$this->stats = array(
			'get'          => 0,
			'get_local'    => 0,
			'set'          => 0,
			'add'          => 0,
			'delete'       => 0,
			'delete_local' => 0,
			'slow-ops'     => 0,
		);

		/* the prefix is the only isolation between installs sharing one
		   PHP pool — use a different WP_YAC_KEY_PREFIX per site. Keys
		   carry no per-blog prefix; multisite blogs share the namespace
		   by design. Budget = key bytes left inside YAC_MAX_KEY_LEN
		   (48 incl. prefix) */
		$this->storage_prefix = substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) WP_YAC_KEY_PREFIX ), 0, 6 ) . ':';

		if ( defined( 'YAC_MAX_KEY_LEN' ) ) {
			$this->logical_key_budget = max( 8, YAC_MAX_KEY_LEN - strlen( $this->storage_prefix ) );
		}

		$this->init_yac();
	}

	private function init_yac() {
		if ( defined( 'WP_YAC_DISABLE' ) && WP_YAC_DISABLE ) {
			return;
		}
		if ( ! extension_loaded( 'yac' ) || ! class_exists( 'Yac' ) ) {
			return;
		}

		try {
			$this->yac           = new Yac( $this->storage_prefix );
			$this->yac_available = true;
		} catch ( \Throwable $e ) {
			/* Yac constructor throws when disabled, e.g. yac.enable_cli=0 */
			$this->yac           = null;
			$this->yac_available = false;
		}
	}

	public function add( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		if ( false === $data ) {
			$data = 0; /* Yac::get() cannot tell a stored false from a miss */
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->yac_available ) {
			if ( isset( $this->cache[ $key ]['found'] ) && $this->cache[ $key ]['found'] ) {
				return false;
			}

			$this->add_to_internal_cache( $key, $data, $group );
			return true;
		}

		if ( isset( $this->written[ $key ] ) ) {
			return false;
		}

		$existing = $this->yac->get( $key );

		if ( false !== $existing ) {
			/* a stored 0 may have been false before coercion: get() cannot
			   tell, so check the request-level cache; absent there, it is
			   an ambiguous entry this request didn't write — assume taken */
			if ( 0 !== $existing || ! isset( $this->cache[ $key ] ) || $this->cache[ $key ]['found'] ) {
				return false;
			}
		}

		if ( $this->shm_write_skip( $id, $group, $data ) ) {
			if ( isset( $this->cache[ $key ]['found'] ) && $this->cache[ $key ]['found'] ) {
				return false;
			}

			$this->add_to_internal_cache( $key, $data, $group );
			return true;
		}

		$ttl = $this->sanitize_ttl( $expire );

		/* Yac::add() can return false on CAS contention even for a free
		   slot, so retry a couple of times */
		$result = false;
		for ( $try = 0; $try < 3; $try++ ) {
			$result = $this->yac->add( $key, $data, $ttl );
			if ( false !== $result ) {
				break;
			}
		}

		if ( false !== $result ) {
			$this->written[ $key ] = true;
			$this->add_to_internal_cache( $key, $data, $group );
			return true;
		}

		/* someone else won the race; keep a local copy so this request
		   stops hitting the DB for it (alloptions!), but report failure */
		if ( ! isset( $this->cache[ $key ] ) ) {
			$this->add_to_internal_cache( $key, $data, $group );
		}

		return false;
	}

	public function add_multiple( array $data, $group = '' ) {
		$values = array();

		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->add( $key, $value, $group );
		}

		return $values;
	}

	public function incr( $id, $n = 1, $group = 'default' ) {
		$key   = $this->key( $id, $group );
		$value = $this->get( $id, $group, false, $found );

		if ( ! $found ) {
			return false;
		}
		if ( ! is_numeric( $value ) ) {
			$value = 0;
		}

		$result = (int) $value + (int) $n;
		if ( $result < 0 ) {
			$result = 0;
		}

		$this->set( $id, $result, $group );
		$this->cache[ $key ]['value'] = $result;

		return $result;
	}

	public function decr( $id, $n = 1, $group = 'default' ) {
		$key   = $this->key( $id, $group );
		$value = $this->get( $id, $group, false, $found );

		if ( ! $found ) {
			return false;
		}
		if ( ! is_numeric( $value ) ) {
			$value = 0;
		}

		$result = (int) $value - (int) $n;
		if ( $result < 0 ) {
			$result = 0;
		}

		$this->set( $id, $result, $group );
		$this->cache[ $key ]['value'] = $result;

		return $result;
	}

	public function close() {
		return true;
	}

	public function delete( $id, $group = 'default' ) {
		$key = $this->key( $id, $group );

		unset( $this->cache[ $key ] );

		if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->yac_available ) {
			return true;
		}

		$result = (bool) $this->yac->delete( $key );
		unset( $this->written[ $key ] );

		return $result;
	}

	public function delete_multiple( array $keys, $group = '' ) {
		$values = array();

		foreach ( $keys as $key ) {
			$values[ $key ] = $this->delete( $key, $group );
		}

		return $values;
	}

	/* flush clears the ENTIRE Yac shared memory on this machine,
	   including data written by other Yac users */
	public function flush() {
		$this->cache = array();

		if ( ! $this->yac_available ) {
			return true;
		}

		return (bool) $this->yac->flush();
	}

	public function flush_runtime( $groups = '' ) {
		if ( empty( $groups ) ) {
			$this->cache = array();
			return true;
		}

		$groups = (array) $groups;

		foreach ( $this->cache as $key => $entry ) {
			if ( isset( $entry['group'] ) && in_array( $entry['group'], $groups, true ) ) {
				unset( $this->cache[ $key ] );
			}
		}

		return true;
	}

	/* Yac can't delete by group, so this only clears the request-level
	   copy; shared entries die by TTL. wp_cache_supports() says no. */
	public function flush_group( $group = '' ) {
		return $this->flush_runtime( $group );
	}

	public function get( $id, $group = 'default', $force = false, &$found = null ) {
		$key   = $this->key( $id, $group );
		$found = true;

		if ( isset( $this->cache[ $key ] ) && ( ! $force || in_array( $group, $this->non_persistent_groups, true ) ) ) {
			if ( isset( $this->cache[ $key ]['value'] ) && is_object( $this->cache[ $key ]['value'] ) ) {
				$value = clone $this->cache[ $key ]['value'];
			} else {
				$value = $this->cache[ $key ]['value'];
			}
			$found = $this->cache[ $key ]['found'];

			$this->group_ops_stats( 'get_local', $key, $group, null, null, 'local' );

			return $value;
		}

		if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->yac_available ) {
			$this->cache[ $key ] = array( 'value' => false, 'found' => false, 'group' => $this->sanitize_group( $group ) );
			$found = false;

			$this->group_ops_stats( 'get_local', $key, $group, null, null, 'not_in_local' );

			return false;
		}

		$this->timer_start();
		$raw = $this->yac->get( $key );
		$elapsed = $this->timer_stop();

		if ( false === $raw ) {
			$found = false;
			$value = false;
		} else {
			$found = true;
			$value = $raw;
		}

		/* NULL is a cached negative result: found, but false */
		if ( null === $value ) {
			$found = true;
			$value = false;
		}

		$this->cache[ $key ] = array( 'value' => $value, 'found' => $found, 'group' => $this->sanitize_group( $group ) );

		if ( ! $found ) {
			$this->group_ops_stats( 'get', $key, $group, null, $elapsed, 'not_in_yac' );
		} else {
			$size = $this->get_data_size( $value );
			$this->group_ops_stats( 'get', $key, $group, $size, $elapsed, 'yac' );
		}

		return $value;
	}

	public function get_multi( $groups ) {
		$return = array();

		foreach ( $groups as $group => $ids ) {
			foreach ( (array) $ids as $id ) {
				$key = $this->key( $id, $group );

				if ( isset( $this->cache[ $key ] ) ) {
					$value = $this->cache[ $key ]['value'];
					$return[ $key ] = is_object( $value ) ? clone $value : $value;
					continue;
				}

				if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->yac_available ) {
					$return[ $key ] = false;
					$this->cache[ $key ] = array( 'value' => false, 'found' => false, 'group' => $this->sanitize_group( $group ) );
					continue;
				}

				$this->timer_start();
				$raw = $this->yac->get( $key );
				$elapsed = $this->timer_stop();

				if ( false === $raw ) {
					$return[ $key ] = false;
					$this->cache[ $key ] = array( 'value' => false, 'found' => false, 'group' => $this->sanitize_group( $group ) );
					$this->group_ops_stats( 'get', $key, $group, null, $elapsed, 'not_in_yac' );
				} else {
					$value = $raw;
					if ( null === $value ) {
						$value = false;
					}
					$return[ $key ] = is_object( $value ) ? clone $value : $value;
					$this->cache[ $key ] = array( 'value' => $value, 'found' => true, 'group' => $this->sanitize_group( $group ) );
					$this->group_ops_stats( 'get', $key, $group, $this->get_data_size( $value ), $elapsed, 'yac' );
				}
			}
		}

		$this->increment_stat( 'get_multi' );

		return $return;
	}

	public function get_multiple( array $keys, $group = '' ) {
		$values = array();

		foreach ( $keys as $key ) {
			$values[ $key ] = $this->get( $key, $group );
		}

		return $values;
	}

	public function replace( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );

		if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->yac_available ) {
			if ( ! isset( $this->cache[ $key ] ) ) {
				return false;
			}

			if ( is_object( $data ) ) {
				$data = clone $data;
			}

			$this->cache[ $key ]['value'] = $data;
			$this->cache[ $key ]['found'] = true;

			return true;
		}

		/* no native replace in Yac: check existence, then set(). There is a
		   small TOCTOU window, which is just Yac's lock-free nature. */
		$existing = $this->yac->get( $key );
		if ( false === $existing ) {
			/* get() also returns false for a stored 0 (coerced from false
			   before writing). What this request set/found is trusted via
			   the request-level cache; a false from anywhere else stays
			   ambiguous and is reported as missing. */
			if ( ! isset( $this->cache[ $key ] ) || ! $this->cache[ $key ]['found'] ) {
				$this->cache[ $key ] = array( 'value' => false, 'found' => false, 'group' => $this->sanitize_group( $group ) );
				return false;
			}
		}

		return $this->set( $id, $data, $group, $expire );
	}

	public function set( $id, $data, $group = 'default', $expire = 0 ) {
		$key = $this->key( $id, $group );

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		if ( false === $data ) {
			$data = 0; /* Yac::get() cannot tell a stored false from a miss */
		}

		$this->cache[ $key ] = array( 'value' => $data, 'found' => false, 'group' => $this->sanitize_group( $group ) );

		if ( in_array( $group, $this->non_persistent_groups, true ) || ! $this->yac_available ) {
			$this->cache[ $key ]['found'] = true;
			$this->group_ops_stats( 'set_local', $key, $group, null, null );

			return true;
		}

		if ( isset( $this->written[ $key ] ) ) {
			$this->cache[ $key ]['found'] = true;
			return true;
		}

		if ( $this->shm_write_skip( $id, $group, $data ) ) {
			$this->cache[ $key ]['found'] = true;
			return true;
		}

		$ttl = $this->sanitize_ttl( $expire );

		/* store raw (no wrapper) so small scalars hit Yac's embedded
		   path and live inside the slot itself, no value block; false
		   arrived already coerced to 0 (a stored false would read back
		   like a miss) */
		$this->timer_start();
		$this->yac->set( $key, $data, $ttl );
		$elapsed = $this->timer_stop();

		/* even if set() returned false (CAS contention), the value is very
		   likely in there; treat it as written for this request */
		$this->written[ $key ] = true;
		$this->cache[ $key ]['found'] = true;

		$size = $this->get_data_size( $data );
		$this->group_ops_stats( 'set', $key, $group, $size, $elapsed );

		return true;
	}

	public function set_multiple( array $data, $group = '' ) {
		$values = array();

		foreach ( $data as $key => $value ) {
			$values[ $key ] = $this->set( $key, $value, $group );
		}

		return $values;
	}

	/* keys carry no blog prefix anymore; blogs intentionally share the
	   namespace (use separate installs/prefixes when they must not) */
	public function switch_to_blog( $blog_id ) {
		return true;
	}

	public function add_global_groups( $groups ) {
		$groups = (array) $groups;

		$this->global_groups = array_merge( $this->global_groups, $groups );
		$this->global_groups = array_unique( $this->global_groups );

		return true;
	}

	public function add_non_persistent_groups( $groups ) {
		$groups = (array) $groups;

		$this->non_persistent_groups = array_merge( $this->non_persistent_groups, $groups );
		$this->non_persistent_groups = array_unique( $this->non_persistent_groups );

		return true;
	}

	/* storage key = "<prefix><group>:<key>"; while it fits the byte
	   budget it is stored verbatim; over-long keys keep the group
	   verbatim (attribution for dumps / the dashboard pie) and hash
	   only the key part */
	public function key( $key, $group ) {
		list( $logical, $over, $head ) = $this->logical_key( $key, $group );

		if ( ! $over ) {
			return $logical;
		}

		if ( strlen( $head ) + 8 <= $this->logical_key_budget ) {
			return $head . hash( 'crc32b', $key );
		}

		return hash( 'crc32b', $logical );
	}

	private function logical_key( $key, $group ) {
		$group   = $this->sanitize_group( $group );
		$logical = $group . ':' . $key;

		return array( $logical, strlen( $logical ) > $this->logical_key_budget, $group . ':' );
	}

	/* the single pollution filter: empty get_page_by_path negatives
	   (bot 404 probes, never re-read) stay request-local; stable
	   per-entity negative caches keep sharing — when those outgrow the
	   table, raise keys_memory_size */
	private function shm_write_skip( $id, $group, $data ) {
		if ( ! WP_YAC_SKIP_EMPTY ) {
			return false;
		}

		if ( null !== $data && ! ( is_array( $data ) && empty( $data ) ) ) {
			return false;
		}

		list( $logical ) = $this->logical_key( $id, $group );

		return false !== strpos( $logical, 'get_page_by_path:' );
	}

	private function sanitize_group( $group ) {
		return empty( $group ) ? 'default' : (string) $group;
	}

	private function sanitize_ttl( $expire ) {
		$expire = (int) $expire;

		return $expire < 0 ? 0 : $expire;
	}

	private function add_to_internal_cache( $key, $value, $group = 'default' ) {
		if ( is_object( $value ) ) {
			$value = clone $value;
		}

		$this->cache[ $key ] = array(
			'value' => $value,
			'found' => true,
			'group' => $group,
		);
	}

	public function stats() {
		echo '<h3>This request</h3>';
		echo '<ul class="wp-yac-op-list">';
		echo '<li><span>Backend</span><strong>' . ( $this->yac_available ? 'shared memory' : 'runtime-only fallback' ) . '</strong></li>';
		echo '<li><span>Query time</span><strong>' . esc_html( number_format( $this->time_total * 1000, 1 ) ) . ' ms</strong></li>';
		echo '</ul>';

		echo '<h3>Operations</h3>';
		echo '<ul class="wp-yac-op-list">';
		foreach ( $this->stats as $stat => $n ) {
			if ( empty( $n ) || 'slow-ops' === $stat ) {
				continue;
			}
			echo '<li><span>' . esc_html( str_replace( '_', ' ', $stat ) ) . '</span><strong>' . esc_html( number_format_i18n( $n ) ) . '</strong></li>';
		}
		echo '</ul>';

		if ( ! empty( $this->stats['slow-ops'] ) ) {
			echo '<p class="wp-yac-note">' . esc_html( sprintf( '%d slow operations (> 5 ms)', $this->stats['slow-ops'] ) ) . '</p>';
		}

		if ( $this->yac_available ) {
			$info = $this->yac->info();
			if ( is_array( $info ) ) {
				echo '<h3>Shared storage (all workers)</h3>';
				echo '<ul class="wp-yac-op-list">';
				echo '<li><span>Slots in use</span><strong>' . esc_html( number_format_i18n( $info['slots_used'] ) . ' / ' . number_format_i18n( $info['slots_size'] ) ) . '</strong></li>';
				echo '<li><span>Values memory pool</span><strong>' . esc_html( size_format( $info['values_memory_size'], 2 ) ) . '</strong></li>';
				echo '</ul>';
			}
		}

		if ( ! empty( $this->group_ops ) ) {
			$max = 1;
			foreach ( $this->group_ops as $ops ) {
				$max = max( $max, count( $ops ) );
			}

			echo '<h3>Operations by group</h3>';
			echo '<p class="wp-yac-note">' . esc_html( 'Cache operations of this request, grouped by WordPress cache group (options, posts, users, …). The bar shows each group’s share of the shared-memory traffic; long bars point at the groups this page load touched the most.' ) . '</p>';
			echo '<ul class="wp-yac-group-bars">';
			foreach ( $this->group_ops as $group => $ops ) {
				echo '<li><span class="wp-yac-group-name" title="' . esc_attr( $group ) . '">' . esc_html( $group ) . '</span>'
					. '<span class="wp-yac-group-bar-track"><span class="wp-yac-group-bar" style="width:' . esc_attr( round( count( $ops ) / $max * 100 ) ) . '%"></span></span>'
					. '<strong>' . esc_html( number_format_i18n( count( $ops ) ) ) . '</strong></li>';
			}
			echo '</ul>';
		}
	}

	public function increment_stat( $field, $num = 1 ) {
		if ( ! isset( $this->stats[ $field ] ) ) {
			$this->stats[ $field ] = $num;
		} else {
			$this->stats[ $field ] += $num;
		}
	}

	private function group_ops_stats( $op, $keys, $group, $size, $time, $comment = '' ) {
		$this->increment_stat( $op );

		if ( strpos( $op, '_local' ) !== false ) {
			return;
		}

		if ( $time > $this->slow_op_microseconds && 'get_multi' !== $op ) {
			$this->increment_stat( 'slow-ops' );
		}

		$this->group_ops[ $group ][] = array( $op, $keys, $size, $time, $comment );
	}

	private function timer_start() {
		$this->time_start = microtime( true );
		return true;
	}

	private function timer_stop() {
		$time_total = microtime( true ) - $this->time_start;
		$this->time_total += $time_total;
		return $time_total;
	}

	private function get_data_size( $data ) {
		if ( is_string( $data ) ) {
			return strlen( $data );
		}

		return strlen( serialize( $data ) );
	}
}
