# ephpm/predis-connection

Predis [`Connection`](https://github.com/predis/predis) backend that routes
Redis-shaped calls to [ePHPm](https://ephpm.dev)'s in-process KV store via
the `ephpm_kv_*` SAPI functions. Same Predis API your app already speaks,
zero socket round-trips, zero serialization, zero RESP parsing.

```php
use Predis\Client;
use Ephpm\Predis\KvConnection;

$client = new Client('ephpm:', [
    'connections' => ['ephpm' => KvConnection::class],
]);

$client->set('user:42:name', 'Alice');
$client->expire('user:42:name', 3600);
$client->incrby('hits:home', 1);
$client->get('user:42:name');     // 'Alice'
```

Each call resolves to a direct C function call into the Rust DashMap
backing ePHPm's KV store. There's no Redis daemon and there's no socket
even in-process; this is the same code path PHP would take if it were
written in Rust.

---

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [End-to-end: a fresh PHP project](#end-to-end-a-fresh-php-project)
- [Laravel integration](#laravel-integration)
- [Symfony integration](#symfony-integration)
- [Generic / framework-less use](#generic--framework-less-use)
- [Verifying the connection is live](#verifying-the-connection-is-live)
- [Supported commands](#supported-commands)
- [SET modifier matrix](#set-modifier-matrix)
- [Predis 3 compatibility](#predis-3-compatibility)
- [Testing without ePHPm](#testing-without-ephpm)
- [Troubleshooting](#troubleshooting)
- [How it works](#how-it-works)
- [License](#license)

---

## Requirements

- **PHP 8.2+**
- **`predis/predis` ^2.2** (Composer pulls this in automatically). Predis 3
  is **not** supported yet — see [Predis 3 compatibility](#predis-3-compatibility).
- **The ePHPm runtime** — any tagged release works for the core
  commands (the `ephpm_kv_*` SAPI functions have shipped since ePHPm
  v0.1.0; `FLUSHDB`/`FLUSHALL` need `ephpm_kv_flush_all()`, added in
  v0.1.2; `SETNX` / `SET … NX` need `ephpm_kv_setnx()`; current release:
  v0.10.2). The functions are
  registered by ePHPm's embedded PHP. If you're running your code under
  PHP-FPM, Apache mod_php, or the stock PHP CLI, those functions don't
  exist and `SapiKvOps::__construct()` throws on instantiation. For
  development without ePHPm running, see
  [Testing without ePHPm](#testing-without-ephpm).

You can confirm the SAPI is present from any PHP file with:

```php
var_dump(function_exists('ephpm_kv_get'));   // expect bool(true)
```

If you get `false`, you're not running inside ePHPm and the connector
will refuse to construct.

---

## Install

ePHPm packages are distributed via their GitHub repositories, not
Packagist. Add this repo as a Composer `vcs` repository, then require
the package (`ephpm/predis-connection` is tagged `v0.1.0`, so `^0.1`
resolves):

```bash
composer config repositories.ephpm/predis-connection vcs https://github.com/ephpm/predis-connection
composer require ephpm/predis-connection:^0.1
```

That's it. Composer pulls in `predis/predis` if you don't already have it.

If you're starting a brand-new project from scratch:

```bash
mkdir my-app && cd my-app
composer init --no-interaction --name=acme/my-app --require=php:^8.2
composer config repositories.ephpm/predis-connection vcs https://github.com/ephpm/predis-connection
composer require ephpm/predis-connection:^0.1
```

---

## End-to-end: a fresh PHP project

The shortest possible setup — a single `index.php` you can drop into an
ePHPm document root.

### 1. Project layout

```
my-app/
├── composer.json
├── vendor/
└── public/
    └── index.php
```

### 2. `composer.json`

```json
{
    "name": "acme/my-app",
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ephpm/predis-connection" }
    ],
    "require": {
        "php": "^8.2",
        "ephpm/predis-connection": "^0.1"
    }
}
```

```bash
composer install
```

### 3. `public/index.php`

```php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Ephpm\Predis\KvConnection;
use Predis\Client;

// One-time setup. In a real app this lives in your DI container or a
// service provider — see the framework sections below.
$redis = new Client('ephpm:', [
    'connections' => ['ephpm' => KvConnection::class],
]);

// Use it like Predis with a real Redis behind it. No daemon, no socket.
$redis->incrby('hits:home', 1);
$visits = $redis->get('hits:home');

header('Content-Type: text/plain');
echo "This page has been served {$visits} times.\n";
```

### 4. `ephpm.toml`

Point ePHPm at the docroot:

```toml
[server]
listen = "127.0.0.1:8080"
document_root = "./public"
```

### 5. Run it

```bash
ephpm serve --config ephpm.toml
```

Then `curl http://127.0.0.1:8080/` and watch the counter go up. Every
hit is a single in-process function call into the KV store; no network,
no socket, no RESP parsing.

---

## Laravel integration

Laravel already supports Predis as its `redis.client` driver — you just
register this connection class as a custom scheme.

### `config/database.php`

```php
'redis' => [

    'client' => env('REDIS_CLIENT', 'predis'),

    'options' => [
        'connections' => [
            'ephpm' => \Ephpm\Predis\KvConnection::class,
        ],
        'parameters' => [
            'scheme' => 'ephpm',
        ],
        // Disabling cluster + read/write split keeps Predis from trying
        // to negotiate things ePHPm's KV doesn't speak.
        'cluster' => false,
    ],

    'default' => [
        'scheme' => 'ephpm',
    ],

    'cache' => [
        'scheme' => 'ephpm',
    ],
],
```

### What now works through ePHPm's KV store

The string/counter/TTL parts of Laravel that go through Predis. With the
config above:

| Laravel feature                         | Now backed by ePHPm KV |
|-----------------------------------------|------------------------|
| `Cache::store('redis')`                 | yes                    |
| `Redis::get(...)`, `Redis::set(...)`    | yes                    |
| `Cache::remember('key', 60, fn …)`      | yes                    |
| Session driver = `redis`                | yes                    |
| `Cache::add(...)` (SET NX / SETNX lock) | yes                    |
| `Cache::lock(...)` atomic locks         | yes (uses SET NX)      |

What does **not** work — these throw `CommandNotSupportedException`, so
keep them on a real Redis (or a different transport):

- **Queue workers** on the `redis` connection — the `redis` queue driver
  uses blocking list ops (`BLPOP`) from the `lists` family.
- **Broadcasting via Redis pub/sub** (`SUBSCRIBE` / `PUBLISH`), including
  presence-channel fan-out.
- **`Redis::throttle(...)` / `funnel(...)` rate limiters** and anything else
  that ships a Lua script — they run via `EVAL`, which this connection does
  not implement. (Laravel's *cache-based* limiter — `RateLimiter` / the
  `throttle` middleware — is fine: it only uses counter ops.)
- Any explicit list / hash / set / sorted-set / stream ops you've written.

### Sanity check from artisan

```bash
php artisan tinker
```

```php
>>> Redis::set('hello', 'world');
=> "OK"
>>> Redis::get('hello');
=> "world"
>>> Redis::incrby('counter', 7);
=> 7
```

---

## Symfony integration

Symfony's `symfony/cache` Redis adapter accepts a Predis client, so the
wiring is just "build a Predis client whose connection scheme is
`ephpm`, then hand it to the adapter."

### `config/services.yaml`

```yaml
services:
    Predis\Client:
        arguments:
            - 'ephpm:'
            -
                connections:
                    ephpm: Ephpm\Predis\KvConnection

    # Hand the Predis client to Symfony's Redis cache adapter.
    Symfony\Component\Cache\Adapter\RedisAdapter:
        arguments:
            - '@Predis\Client'
            - 'app'   # namespace prefix
```

### `config/packages/cache.yaml`

```yaml
framework:
    cache:
        app: Symfony\Component\Cache\Adapter\RedisAdapter
```

`Cache::get`, `Cache::delete`, PSR-6 `CacheItemPoolInterface` consumers,
PSR-16 `Cache\SimpleCache\CacheInterface` — anything resolved through
`Symfony\Component\Cache` now talks to the SAPI.

---

## Generic / framework-less use

If you're not on Laravel or Symfony, instantiate Predis directly:

```php
use Predis\Client;
use Ephpm\Predis\KvConnection;

$redis = new Client('ephpm:', [
    'connections' => ['ephpm' => KvConnection::class],
]);

// Counter
$redis->incrby('orders:placed', 1);

// Cache with TTL
$redis->set('user:42', json_encode($user), 'EX', 3600);
$cached = $redis->get('user:42');

// Pipeline
$results = $redis->pipeline(function ($pipe) {
    $pipe->set('a', '1');
    $pipe->set('b', '2');
    $pipe->get('a');
    $pipe->get('b');
});
// $results === ['OK', 'OK', '1', '2']
```

Wrap it in a singleton or pass it through your DI container — it's a
plain Predis client with a custom transport, so anything that takes
a `Predis\Client` (or `Predis\ClientInterface`) keeps working.

---

## Verifying the connection is live

A two-line health check you can hit from any PHP entry point to confirm
the SAPI surface is wired:

```php
$client->set('__healthcheck', '1', 'EX', 5);
assert($client->get('__healthcheck') === '1');
```

If this round-trips successfully you've confirmed:

- ePHPm's SAPI is loaded (`ephpm_kv_*` functions exist)
- The KV store is up
- TTL parsing works
- Predis is correctly routing through your registered connection class

---

## Supported commands

| Command(s)                                       | Behavior                                                                |
| ------------------------------------------------ | ----------------------------------------------------------------------- |
| `GET`, `SET`, `SETEX`, `PSETEX`                  | Strings with optional `EX`/`PX` TTL. `PX`/`PSETEX` round up to seconds. `SET … NX` supported; `XX`/`GET`/`KEEPTTL`/`EXAT`/`PXAT` are not. |
| `SETNX`, `SET … NX`                              | Atomic insert-if-absent via `ephpm_kv_setnx` (Redis lock recipes). `SETNX` returns `1`/`0`; `SET … NX` returns `OK`/nil. See the [OOM note](#oom-and-setnx) below. |
| `DEL`, `UNLINK`, `EXISTS`                        | Multi-key, return count.                                                |
| `INCR`, `DECR`, `INCRBY`, `DECRBY`               | Atomic counter ops via the SAPI's `ephpm_kv_incr_by`.                   |
| `EXPIRE`, `PEXPIRE`, `TTL`, `PTTL`               | TTL management. `PEXPIRE` rounds up.                                    |
| `TYPE`                                           | Returns `string` if the key exists, `none` if not.                      |
| `PING`, `ECHO`                                   | Connection liveness, payload echo.                                      |
| `SELECT`, `AUTH`, `QUIT`                         | Tolerated as no-ops so framework handshakes don't break.                |
| `FLUSHDB`, `FLUSHALL`                            | Clear the entire effective store. ephpm's KV is a single keyspace, so both are equivalent. Needs ePHPm v0.1.2+ (`ephpm_kv_flush_all`); on v0.1.0/v0.1.1 this is a no-op returning `null`. |

Everything else — lists, sets, hashes, sorted sets, streams, scripting
(`EVAL`), pub/sub (`SUBSCRIBE`/`PUBLISH`), `MULTI`/`EXEC` — raises
`Ephpm\Predis\CommandNotSupportedException` with a clear "ephpm KV does not
implement \<CMD\>" message. ePHPm's KV store is intentionally a string +
counter store; if you need anything beyond that, point Predis at a real
Redis for those calls. In particular, **Lua-scripted rate limiters,
pub/sub, and broadcasting do not work through this connection** — they
need `EVAL`/`SUBSCRIBE`/`PUBLISH`.

---

## SET modifier matrix

| Modifier             | Status          |
| -------------------- | --------------- |
| `EX seconds`         | supported       |
| `PX milliseconds`    | supported (rounded up to seconds) |
| `NX`                 | supported (atomic insert-if-absent via `ephpm_kv_setnx()`) |
| `XX`                 | unsupported     |
| `GET`                | unsupported     |
| `KEEPTTL`            | unsupported     |
| `EXAT`, `PXAT`       | unsupported     |

### OOM and SETNX

Two boundary behaviors worth knowing:

- **OOM surfaces as a `Predis\Response\ServerException`.** A plain `SET`,
  `SETEX`, or `PSETEX` always overwrites, so if the SAPI refuses the write
  the only cause is the store hitting `maxmemory` under `noeviction`. This
  connection raises the same `ServerException`
  (`OOM command not allowed when used memory > maxmemory.`, error type
  `OOM`) you'd get over the real RESP wire, rather than silently returning
  a false `OK`.

- **On the NX path, OOM is indistinguishable from "key already exists."**
  `ephpm_kv_setnx` returns a single bool whose false means *either* a live
  entry already exists *or* the write was refused (OOM). This connection
  reports the common case: `SETNX` returns `0` and `SET … NX` returns nil.
  A lock you fail to acquire under memory pressure is therefore reported as
  "already held" — acceptable for lock recipes, but do not rely on the NX
  reply to detect OOM.

---

## Predis 3 compatibility

This package requires **`predis/predis` ^2.2** and is **not compatible with
Predis 3** yet. `KvConnection` implements
`Predis\Connection\NodeConnectionInterface`, and Predis 3 added three abstract
methods to that interface that Predis 2 doesn't declare:

```php
public function getClientId(): ?int;
public function write(string $buffer): void;
public function hasDataToRead(): bool;
```

Loading `KvConnection` under Predis 3 therefore fatals with
*"contains 3 abstract methods and must therefore be declared abstract"*.
Widening the constraint to `^2.2 || ^3.0` is intentionally **not** done —
it would require implementing those three methods (and re-testing the wire
semantics they imply). Track this in the issue tracker if you need Predis 3.

---

## Testing without ePHPm

The connection takes an optional `KvOpsInterface`, so you can swap in a
fake backend that runs anywhere — including standard PHPUnit suites on
plain `php-cli`:

```php
use Ephpm\Predis\InMemoryKvOps;
use Ephpm\Predis\KvConnection;
use Predis\Client;

$client = new Client(new KvConnection(null, new InMemoryKvOps()));
$client->set('foo', 'bar');
assert($client->get('foo') === 'bar');
```

`InMemoryKvOps` is for tests only — values live in PHP arrays, there is
no eviction policy, no memory limit, and TTL is best-effort lazy
expiry. Don't use it in production.

---

## Troubleshooting

### `RuntimeException: ephpm KV SAPI functions are not available`

You're not running under the ePHPm runtime. Either run your code
through the `ephpm` binary (production), or pass an `InMemoryKvOps` as
the second arg of `KvConnection` (tests).

### `CommandNotSupportedException: ephpm KV does not implement 'LPUSH'`

You called a Redis command outside the supported subset (lists/sets/
hashes/streams/scripts/pub-sub). Either change the call to use the
string + counter ops your data model can be expressed with, or keep
that workload on a real Redis (you can wire two clients in your app and
route per-key-pattern).

### `Predis\NotSupportedException: This Predis Connection does not support …`

You hit a Predis feature that requires capabilities our connection
deliberately doesn't expose (subscriber loops, MONITOR, replication
discovery, etc.). Same fix as above.

### Laravel queue worker fails with `BLPOP not implemented`

Laravel's `redis` queue driver uses blocking list ops. Either keep a
real Redis around just for queues (`config/queue.php` can point a
single connection at it) or switch the queue to the database driver.

---

## How it works

ePHPm runs PHP inside the same OS process as the KV store via the embed
SAPI. The store itself is a Rust [`DashMap`](https://docs.rs/dashmap/)
plus TTL management. ePHPm registers a small set of host functions
(`ephpm_kv_get`, `ephpm_kv_set`, `ephpm_kv_setnx`, `ephpm_kv_incr_by`,
`ephpm_kv_expire`, `ephpm_kv_ttl`, `ephpm_kv_pttl`, `ephpm_kv_del`,
`ephpm_kv_exists`) into
PHP's global function table. Calling one is a direct C function call
into Rust — no socket, no protocol parser, no value serialization beyond
what userland code already does.

This package wraps those functions in a `Predis\Connection` so existing
Predis-using code (frameworks, libraries, your own) doesn't need to
know anything has changed. Same `Predis\Client::get/set/incr/...` API,
different transport underneath.

See [ephpm.dev/architecture/kv-store/](https://ephpm.dev/architecture/kv-store/)
for the architecture and [ephpm.dev/guides/kv-from-php/](https://ephpm.dev/guides/kv-from-php/)
for the underlying SAPI surface.

---

## License

MIT — see [LICENSE](LICENSE).
