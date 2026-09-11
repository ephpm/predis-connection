<?php

declare(strict_types=1);

namespace Ephpm\Predis;

/**
 * Backend that calls the global `ephpm_kv_*` functions registered by
 * the ePHPm SAPI. Refuses to construct if those functions aren't present
 * so we fail fast outside the runtime instead of producing
 * "Call to undefined function" errors at request time.
 */
final class SapiKvOps implements KvOpsInterface
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
}
