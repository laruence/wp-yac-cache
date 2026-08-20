# Yac Object Cache

[![CI](https://github.com/laruence/wordpress-yac-cache/actions/workflows/ci.yml/badge.svg)](https://github.com/laruence/wordpress-yac-cache/actions/workflows/ci.yml)

A [Yac](https://github.com/laruence/yac) backed object cache for WordPress.

Yac stores the cache in lock-free shared memory inherited by all PHP-FPM
workers on the machine: no cache server, no socket, no network round trips.
A `get()` is one hash lookup in local memory.

Benchmarked against the classic Memcached drop-in on a real WordPress site
(PHP 8.1 FPM, 8 cores): **~19% higher throughput and ~15% lower latency**
across 20/50/100 concurrent users — see [Benchmarks](#benchmarks).

Best fit is single-node (or few-node) WordPress installs.

## Features

- **Self-deploying drop-in** — activation copies `object-cache.php` to
  `wp-content/`; the admin page tracks drop-in version and updates it
- **Readable storage keys** — keys are stored verbatim while they fit Yac's
  48-byte limit (salt carried by the instance prefix); only oversized keys
  are hashed (crc32b). Configurable prefix via `WP_YAC_KEY_PREFIX`
- **False-safe** — values are stored as `array('v' => $data)` to resolve
  Yac's miss-vs-stored-false ambiguity
- **Admin dashboard** — live stats, health advice and self-test, see
  [Dashboard](#dashboard)
- **Multisite aware** — site-scoped and global groups keyed separately,
  `switch_to_blog()` supported
- **Graceful degradation** — without the Yac extension the drop-in falls back
  to a per-request cache and WordPress keeps working
- **WP-CLI commands** — `wp yac status`, `wp yac flush`

## Dashboard

![Yac dashboard](docs/assets/dashboard.png)

Tools → Yac Object Cache: key-slot usage, hit rate, counters, value-memory
health with capacity advice, a self-test, and a snapshot of the largest
entries. When a pool is undersized, the health panel points at the `php.ini`
knob to raise — for the values pool it even names a concrete size.

## Requirements

- PHP 7.2+
- WordPress 5.6+
- The Yac extension, installed by any of the three ways below

### Installing Yac

**PECL**

```bash
pecl install yac
```

**PIE** (the PHP Foundation's PECL successor)

```bash
pie install laruence/yac
```

**From source**

```bash
git clone https://github.com/laruence/yac.git
cd yac
phpize
./configure
make
sudo make install
```

Then enable it in `php.ini`:

```ini
extension=yac.so
```

## Installation

### Via WP-CLI

```bash
wp plugin install https://github.com/laruence/wordpress-yac-cache/releases/latest/download/wp-yac-cache.zip --activate
```

### Via the WordPress admin

Download `wp-yac-cache.zip` from the [releases page](https://github.com/laruence/wordpress-yac-cache/releases),
then Plugins → Add New → Upload Plugin.

(The plugin has been submitted to the WordPress.org directory and will be
installable from the plugin repository once it passes review.)

### Configuration

In `wp-config.php`, above the "That's all, stop editing!" line:

```php
define( 'WP_CACHE', true );
define( 'WP_CACHE_KEY_SALT', 'a long random string, unique per install' );
```

Check **Tools → Yac Object Cache** for status, stats and flush actions.

## Tuning

```ini
; php.ini
yac.enable = 1
yac.keys_memory_size = 4M      ; ~32K slots
yac.values_memory_size = 64M   ; raise for large sites (alloptions!)
```

```php
; wp-config.php escape hatches
define( 'WP_YAC_DISABLE', true );     // force runtime-only mode
define( 'WP_YAC_KEY_PREFIX', 'wp_' ); // cosmetic key prefix, 0-6 chars, shorter is better
```

## Benchmarks

Real-site benchmark on laruence.com (WordPress, PHP 8.1 FPM, 8 cores),
full homepage renders, `ab -t 30 -c <concurrency>`, each run starting from a
flushed cache and restarted php-fpm. Only the drop-in differs between the
two configurations.

| Concurrency | Yac RPS | Memcached RPS | Gain   | Yac p50 | Memcached p50 |
|-------------|---------|---------------|--------|---------|---------------|
| 20          | 141.6   | 118.7         | +19.3% | 139ms   | 167ms         |
| 50          | 140.6   | 118.5         | +18.6% | 353ms   | 420ms         |
| 100         | 142.1   | 118.4         | +20.1% | 699ms   | 840ms         |

Zero failed requests in both configurations. Cross-checked with a fixed
request-count run (`ab -n 10000 -c 100`): 141.8 vs 120.6 RPS (+17.6%).

Note that `wp_cache_flush()` calls `Yac::flush()` and wipes the **entire**
shared memory on the machine, including data written by other Yac users
sharing the same PHP pool. The admin page asks for confirmation. Decide
before you flush.

## Tests

```bash
php -d yac.enable_cli=1 tests/smoke.php   # shared-memory path
php -n tests/smoke.php                    # runtime-only fallback path
php tests/shell.php                       # plugin shell (deploy/status)
php tests/render.php                      # admin page render (charts/advice)
```

## License

GPLv2 or later.
