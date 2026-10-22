<?php

declare(strict_types=1);

namespace Ephpm\Predis;

/**
 * Backend that calls the global `ephpm_kv_*` functions registered by
 * the ePHPm SAPI. Refuses to construct if those functions aren't present
 * so we fail fast outside the runtime instead of producing
 * "Call to undefined function" errors at request time.
 *
 * The v0.13 operations ({@see ExtendedKvOpsInterface}) are feature-detected
 * per function: {@see supports()} reports whether the running ePHPm registers
 * the backing `ephpm_kv_*` function, and {@see KvConnection} keeps its older
 * behavior for a command whose function is missing.
 */
final class SapiKvOps implements ExtendedKvOpsInterface
{
    public function __construct()
    {
        if (!\function_exists('ephpm_kv_get')) {
            throw new \RuntimeException(
                'ephpm KV SAPI functions are not available. '
                . 'This connection only works inside the ePHPm runtime; '
                . 'use Ephpm\\Predis\\InMemoryKvOps in tests.'
            );
        }
    }

    public function get(string $key): ?string
    {
        /** @var string|null */
        return \ephpm_kv_get($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_set($key, $value, $ttlSeconds);
    }

    public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        // false = a live entry already exists OR an OOM refusal; the SAPI
        // bool conflates the two and the caller cannot distinguish them.
        return (bool) \ephpm_kv_setnx($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        return (int) \ephpm_kv_del($key);
    }

    public function exists(string $key): bool
    {
        return (bool) \ephpm_kv_exists($key);
    }

    public function incrBy(string $key, int $delta): int
    {
        // ephpm_kv_incr_by returns false when the stored value is not an
        // integer. A blind `(int) false` would collapse that to 0 and silently
        // corrupt the counter, so capture and distinguish the sentinel first.
        $result = \ephpm_kv_incr_by($key, $delta);
        if ($result === false) {
            throw new \RuntimeException(
                "value at key '{$key}' is not an integer"
            );
        }
        return (int) $result;
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        return (bool) \ephpm_kv_expire($key, $ttlSeconds);
    }

    public function ttl(string $key): int
    {
        return (int) \ephpm_kv_ttl($key);
    }

    public function pttl(string $key): int
    {
        return (int) \ephpm_kv_pttl($key);
    }

    public function flush(): bool
    {
        // ephpm_kv_flush_all() was added after the original SAPI surface;
        // guard so this connection still loads on older ePHPm runtimes
        // (flush is simply unavailable there rather than a fatal).
        if (!\function_exists('ephpm_kv_flush_all')) {
            return false;
        }
        return (bool) \ephpm_kv_flush_all();
    }

    // ── ePHPm v0.13+ (call only after supports() returned true) ─────────────

    public function supports(string $op): bool
    {
        return \in_array($op, self::OPS, true) && \function_exists('ephpm_kv_' . $op);
    }

    public function hget(string $key, string $field): ?string
    {
        /** @var string|null */
        return \ephpm_kv_hget($key, $field);
    }

    public function hset(string $key, array $fields): int|false
    {
        /** @var int|false */
        return \ephpm_kv_hset($key, $fields);
    }

    public function hsetnx(string $key, string $field, string $value): bool
    {
        return (bool) \ephpm_kv_hsetnx($key, $field, $value);
    }

    public function hmget(string $key, array $fields): array|false
    {
        /** @var list<string|null>|false */
        return \ephpm_kv_hmget($key, $fields);
    }

    public function hgetall(string $key): array|false
    {
        /** @var array<array-key, string>|false */
        return \ephpm_kv_hgetall($key);
    }

    public function hdel(string $key, array $fields): int
    {
        return (int) \ephpm_kv_hdel($key, $fields);
    }

    public function hexists(string $key, string $field): bool
    {
        return (bool) \ephpm_kv_hexists($key, $field);
    }

    public function hlen(string $key): int
    {
        return (int) \ephpm_kv_hlen($key);
    }

    public function hkeys(string $key): array|false
    {
        /** @var list<string|int>|false */
        return \ephpm_kv_hkeys($key);
    }

    public function hvals(string $key): array|false
    {
        /** @var list<string>|false */
        return \ephpm_kv_hvals($key);
    }

    public function hincrBy(string $key, string $field, int $delta): int|false
    {
        /** @var int|false */
        return \ephpm_kv_hincr_by($key, $field, $delta);
    }

    public function hexpire(string $key, string $field, int $ttlSeconds): int|false
    {
        /** @var int|false */
        return \ephpm_kv_hexpire($key, $field, $ttlSeconds);
    }

    public function httl(string $key, string $field): int
    {
        return (int) \ephpm_kv_httl($key, $field);
    }

    public function hpttl(string $key, string $field): int
    {
        return (int) \ephpm_kv_hpttl($key, $field);
    }

    public function hpersist(string $key, string $field): bool
    {
        return (bool) \ephpm_kv_hpersist($key, $field);
    }

    public function mget(array $keys): array|false
    {
        /** @var list<string|null>|false */
        return \ephpm_kv_mget($keys);
    }

    public function mset(array $values, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_mset($values, $ttlSeconds);
    }

    public function getdel(string $key): ?string
    {
        /** @var string|null */
        return \ephpm_kv_getdel($key);
    }

    public function getex(string $key, int $ttlSeconds): ?string
    {
        /** @var string|null */
        return \ephpm_kv_getex($key, $ttlSeconds);
    }

    public function persist(string $key): bool
    {
        return (bool) \ephpm_kv_persist($key);
    }

    public function setIfEq(string $key, string $expected, string $value, int $ttlSeconds = 0): bool
    {
        return (bool) \ephpm_kv_set_if_eq($key, $expected, $value, $ttlSeconds);
    }

    public function append(string $key, string $value): int|false
    {
        /** @var int|false */
        return \ephpm_kv_append($key, $value);
    }

    public function strlen(string $key): int
    {
        return (int) \ephpm_kv_strlen($key);
    }

    public function type(string $key): string
    {
        return (string) \ephpm_kv_type($key);
    }

    public function scan(int $cursor, ?string $match = null, int $count = 10): array|false
    {
        $page = \ephpm_kv_scan($cursor, $match, $count);
        if (!\is_array($page)) {
            return false;
        }
        /** @var array{0: int, 1: array<string>} $page */
        return [(int) $page[0], \array_values($page[1])];
    }
}
