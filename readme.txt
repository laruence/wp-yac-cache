=== Yac Object Cache ===
Contributors: laruence
Tags: cache, object cache, yac, shared memory, performance
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.0
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Yac (lock-free shared memory) backed object cache for WordPress. Zero external servers, zero network round trips.

== Description ==

[Yac](https://github.com/laruence/yac) keeps the cache in a shared memory segment accessible to all PHP workers, so a read is a hash lookup in local memory — no cache server, no socket, no network.

**Highlights**

* **Fast.** No socket, no network, no global lock, so throughput scales with workers. On the author's own site ([www.laruence.com](https://www.laruence.com)) page renders came out 19% faster than with the Memcached drop-in.
* **Nothing to operate.** No cache server to install, secure, monitor or restart.
* **Health you can read.** Hit rate, hits and misses charted over Today / Yesterday / Last 7 days, with a diagnosis that also says when *not* to add memory.
* **Fails soft.** No extension, or `yac.enable=0`, degrades to a per-request cache and the site keeps serving.

**Best fit**

Single-node or few-node installs where all PHP workers share a machine. For multi-server clusters needing cross-node consistency, a network cache (Memcached/Redis) suits better.

== Screenshots ==

1. Dashboard widget: hit rate, uptime, key slots, values occupied and the 24-hour counters.
2. Tools → Yac Object Cache: the status bar, with the live shared-memory round trip.
3. Tools → Yac Object Cache: cache status — hit rate, hits and misses over Today / Yesterday / Last 7 days, with the capacity diagnosis on hover.
4. Tools → Yac Object Cache: shared memory contents — keys-by-group pie, occupancy totals and the largest (or hottest) entries, each clickable for the entry inspector.

== Installation ==

1. Install the Yac extension, any of three ways (then enable `extension=yac.so` in php.ini):

`
# PECL
pecl install yac

# PIE (the PHP Foundation's PECL successor; yac is on Packagist)
pie install laruence/yac
`

Or from source: download the latest [release](https://github.com/laruence/yac/releases), unzip it, then inside:

`
phpize && ./configure && make && make install
`

2. Install and activate Yac Object Cache. Activation deploys `wp-content/object-cache.php`.

That is it — no wp-config edit needed. Visit **Tools → Yac Object Cache** to verify status.

**Optional php.ini tuning**

`
yac.enable = 1
yac.keys_memory_size = 16M      ; ~128K slots (~32K per 4M)
yac.values_memory_size = 64M    ; raise for large sites (big alloptions)
`

**Optional wp-config switches**

`
define( 'YAC_OCACHE_KEY_PREFIX', 'ab' ); // default wp; give each install its own when sites share one PHP pool
define( 'YAC_OCACHE_EMPTY_TTL', 0 );     // default 21600s: lifetime cap on empty negative results; 0 disables
define( 'YAC_OCACHE_DISABLE', true );    // escape hatch: force runtime-only mode
`

== Frequently Asked Questions ==

= Do I need a Memcached or Redis server? =

No. That is the point — the cache lives in shared memory on the machine.

= What happens if the Yac extension is not installed? =

The drop-in degrades to a per-request cache: the site keeps working, you lose cross-request persistence, and the status page flags the missing extension.

= How are keys stored, given Yac's 48-byte key limit? =

As `<YAC_OCACHE_KEY_PREFIX>:<group>:<key>` (default prefix `wp`), verbatim while they fit the 48-byte budget; over-long keys keep the group verbatim and hash (crc32b) only the key part.

= Does flush() clear other Yac users on the same machine? =

Yes. `wp_cache_flush()` calls `Yac::flush()`, which wipes the entire shared memory on the machine, including data written by other Yac users sharing the PHP pool. The admin page asks for confirmation first.

= Multisite? =

Yes, with a caveat: keys carry no per-blog prefix, so blogs of one install share the namespace and `switch_to_blog()` does not re-namespace. Give each install its own `YAC_OCACHE_KEY_PREFIX` when sites sharing a PHP pool must not see each other's entries.

= What about wp_cache_flush_group()? =

Yac cannot delete entries by prefix, so a group flush clears the request-level copy of that group; shared entries then expire via TTL. The plugin reports `flush_group` as unsupported so core does not rely on it.

== Changelog ==

= 1.3.0 =
* No wp-config edit is needed any more: `WP_CACHE` is no longer required or reported. WordPress loads `object-cache.php` regardless of it — that constant only gates `advanced-cache.php` (page caching), which this plugin does not provide. The live CI run now boots WordPress without it to prove the point.
* The Cache health panel became a client-side trend chart: hit rate, hits and misses over Today / Yesterday / Last 7 days, with the window's counters and the capacity diagnosis on hover. Counters are sampled every 15 minutes into a ~7-day ring.
* Fixed the entry inspector exhausting the PHP memory limit on a busy cache: it now pages through `Yac::dump()` instead of accumulating the whole dump.
* Fixed the dashboard widget reporting "0 bytes" for values occupied. The Active bar now also shows shared-memory uptime.
* Suggested tuning raised to `yac.keys_memory_size = 16M` (~128K slots); 4M gives only ~32K.

= 1.2.2 =
* Fixed stale shared-memory writes: `wp_cache_set()` on a key already written by `wp_cache_add()` in the same request skipped the shared write, so other requests kept reading the value the add stored (the `update_option()` pattern left the old option behind).
* Replaced `YAC_OCACHE_SKIP_EMPTY` with `YAC_OCACHE_EMPTY_TTL` (default 21600s): empty results now share as usual but expire instead of occupying a slot forever.
* The health panel's "keys used" reports live entries rather than the `slots_used` high-water mark.

= 1.2.1 =
* Moved the PHPCS EscapeOutput annotations so WordPress.org Plugin Check recognizes the exemption; annotated `pre_wp_cache_get` as a core hook.

= 1.2.0 =
* Fixed the health-ring donut disappearing: `wp_kses_post()` strips SVG elements.
* Renamed every plugin-owned symbol to the `yac_ocache_`/`YAC_OCACHE_`/`yac-ocache-` prefixes for WordPress.org policy (`wp_` is reserved for core). The `wp_cache_*` functions and `$wp_object_cache` keep their standard names; WP-CLI stays `wp yac status` / `wp yac flush`.

= 1.1.1 =
* A stored `false` is now written as `0`: Yac's `get()` returns `false` for both a miss and a stored `false`, so a stored false never survived past the request.
* Top entries grew a Hottest tab (by access count) alongside Largest; the entry inspector gained Hits, Embedded-in-slot and `c_len`.
* Entry listings page through `Yac::dump()` instead of one `dump(-1)`, which could exhaust the memory limit.

= 1.1.0 =
* Dashboard rebuilt around a cache-health verdict, keys-by-group pie, largest entries, configuration reference and diagnostics.
* Key format rework: `<prefix>:<group>:<key>` with no per-blog prefix; over-long keys hash only the key part.
* `YAC_OCACHE_DISABLE` escape hatch forces runtime-only mode.

= 1.0.0 =
* Initial release. Yac-backed object cache with self-deploying drop-in, verbatim keys with hashed fallback, multisite support, graceful runtime-only fallback, and the admin dashboard.
