=== Yac Object Cache ===
Contributors: laruence
Tags: cache, object cache, yac, shared memory, performance
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Yac (lock-free shared memory) backed object cache for WordPress. Zero external servers, zero network round trips.

== Description ==

Yac uses the [Yac](https://github.com/laruence/yac) PHP extension as the backing store for the WordPress object cache.

Unlike Memcached or Redis, Yac stores data in **shared memory inherited by every PHP-FPM worker** on the machine. There is no cache server to install, no socket to configure, and every cache read is a hash lookup in local memory — typically microseconds, with no network round trip and no global lock.

**Highlights**

* **No external server.** The cache lives in shared memory (`mmap`/SysV), fork-inherited by FPM workers.
* **Lock-free.** Per-slot CAS arbitration; throughput scales with worker count. No global lock.
* **Self-deploying.** Activating the plugin writes `object-cache.php` to `wp-content/`.
* **Simple keys, simple flush.** Keys are stored verbatim while they fit Yac's 48-byte limit; `wp_cache_flush()` calls `Yac::flush()` and wipes the entire shared memory on the machine — know that before you flush.
* **Graceful degradation.** If Yac is unavailable (extension missing), the drop-in falls back to a per-request in-memory cache and WordPress keeps working.
* **Single-node focus.** Keys carry no per-blog prefix; installs sharing one PHP pool isolate via `YAC_OCACHE_KEY_PREFIX`. Multisite blogs share one namespace.
* **Entry inspector.** On the admin page, click any top-entry key to see the deserialized value, padded size, expiry and — on newer yac builds — last access, hit count and whether the value is embedded in its slot; or delete the entry.

**Best fit**

Yac is a *local* cache. It is ideal for single-node or few-node WordPress installs where all PHP workers run on one machine. On large multi-server clusters with strict cross-node consistency needs, a network cache (Memcached/Redis) may suit better.

== Installation ==

1. Install the Yac extension, any of three ways (then enable `extension=yac.so` in php.ini):

`
# PECL
pecl install yac

# PIE (the PHP Foundation's PECL successor; yac is on Packagist)
pie install laruence/yac

# Build from source
git clone https://github.com/laruence/yac.git && cd yac
phpize && ./configure && make && sudo make install
`

2. Install and activate Yac Object Cache. Activation deploys `wp-content/object-cache.php`.
3. In `wp-config.php`, above the "That's all, stop editing!" line, add:

`
define( 'WP_CACHE', true );
define( 'YAC_OCACHE_KEY_PREFIX', 'ab_' ); // unique per install when sites share one PHP pool
`

That is it. Visit **Tools → Yac Object Cache** to verify status.

**Optional php.ini tuning**

`
yac.enable = 1
yac.keys_memory_size = 4M       ; ~32K slots
yac.values_memory_size = 64M    ; raise for large sites (big alloptions)
`

**Optional wp-config switches**

`
define( 'YAC_OCACHE_SKIP_EMPTY', false ); // filter is on by default; set false to also store empty get_page_by_path negatives
define( 'YAC_OCACHE_DISABLE', true );     // emergency escape hatch: force runtime-only mode
`

== Frequently Asked Questions ==

= Do I need a Memcached or Redis server? =

No. That is the point — the cache lives in shared memory on the machine.

= What happens if the Yac extension is not installed? =

The drop-in degrades to a per-request in-memory cache. Your site keeps working; you just lose cross-request persistence. The status page flags the missing extension.

= How are keys stored, given Yac's 48-byte key limit? =

Storage keys are `<YAC_OCACHE_KEY_PREFIX>:<group>:<key>` (default prefix `wp`), kept verbatim while they fit the 48-byte budget; over-long keys keep the group verbatim and hash (crc32b) only the key part. Keys carry no per-blog prefix: use a different YAC_OCACHE_KEY_PREFIX per install when sites share one PHP pool; multisite blogs share the namespace.

= Does flush() clear other Yac users on the same machine? =

Yes. `wp_cache_flush()` calls `Yac::flush()`, which wipes the entire shared memory on the machine, including data written by other Yac users sharing the PHP pool. The admin page asks for confirmation before flushing.

= Multisite? =

Yes, with a caveat: blogs of one install share the cache namespace (keys carry no blog prefix), which is fine for most sites; `switch_to_blog()` does not re-namespace keys. Run separate installs with different `YAC_OCACHE_KEY_PREFIX` values when blogs must not share entries.

= What about wp_cache_flush_group()? =

Yac cannot delete entries by prefix, so a group flush clears the request-level copy of that group; shared entries then expire via TTL. The plugin reports `flush_group` as unsupported so WordPress core does not rely on it.

== Changelog ==

= 1.2.0 =
* Fixed the health-ring donut on the admin dashboard disappearing: the SVG was piped through `wp_kses_post()`, whose allowed-tag list strips SVG elements and left only the center text visible.
* Renamed every plugin-owned symbol from the `wp_yac_`/`WP_YAC_`/`wp-yac-` prefixes to `yac_ocache_`/`YAC_OCACHE_`/`yac-ocache-` for WordPress.org prefix policy (the `wp_` prefix is reserved for WordPress core). The wp-config switches were renamed accordingly: `YAC_OCACHE_KEY_PREFIX`, `YAC_OCACHE_SKIP_EMPTY`, `YAC_OCACHE_DISABLE`, `YAC_OCACHE_WARMUP_LOOKUPS`.
* The `wp_cache_*` functions and the `$wp_object_cache` global are the standard WordPress object cache API and keep their names.
* WP-CLI: the commands stay `wp yac status` / `wp yac flush`; the command class is now `YAC_OCACHE_CLI_Command`.

= 1.1.1 =
* A stored `false` is now written to shared memory as `0`. Yac's `get()` returns `false` for both a miss and a stored `false`, so false negatives never survived past the request; with `0` they persist like any other value (readers comparing by value see `0` instead of `false`).
* Admin page: top entries grew a Hottest tab (by access count) alongside Largest, shown whenever this yac build reports per-entry hits; the entry inspector gains Hits, Embedded-in-slot and compressed-payload (`c_len`) metadata.
* Admin page: entry listings now page through `Yac::dump()` 1000 entries at a time instead of one `dump(-1)`, which could exhaust the PHP memory limit on a busy cache; builds older than 2.4.0 fall back to the single full dump.

= 1.1.0 =
* Dashboard rebuilt around a cache-health verdict: hit-rate ring, cause-attributed metric bars, keys-by-group pie, largest entries by content length, configuration reference and runtime diagnostics.
* Key format rework: `<prefix>:<group>:<key>` with no per-blog prefix (default prefix `wp`); over-long keys keep the group verbatim and hash only the key part; `switch_to_blog()` no longer re-namespaces keys.
* `YAC_OCACHE_SKIP_EMPTY` (on by default): empty `get_page_by_path:` negatives (bot 404 probes) stay request-local instead of filling slots.
* `YAC_OCACHE_DISABLE` escape hatch forces runtime-only mode.

= 1.0.0 =
* Initial release. Yac-backed object cache with self-deploying drop-in, verbatim keys with hashed fallback, multisite support, and graceful runtime-only fallback.
* Storage key prefix configurable via `YAC_OCACHE_KEY_PREFIX` (0-6 chars, default `wp_`); shorter is better.
* `wp_cache_flush()` calls `Yac::flush()` and clears the entire shared memory on the machine — the admin page asks for confirmation and states the scope.
* Admin dashboard: status bar, key-slot donut, counters, memory-health advice, diagnostics, self-test, memory-contents snapshot, and the configuration reference inside the Environment panel.
