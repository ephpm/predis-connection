<?php

declare(strict_types=1);

namespace Ephpm\Predis;

/**
 * In-process backend used by the test suite and by anyone who wants to
 * exercise the connection without the ePHPm runtime present. Stores
 * everything in PHP arrays — explicitly NOT a production cache.
 *
 * TTL semantics match the SAPI: TTLs are stored as monotonic deadlines
 * in milliseconds; lazy expiry (a key is only "gone" when it's looked up
 * after its deadline). No background sweeper.
 */
final class InMemoryKvOps implements KvOpsInterface
{
    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, int> deadline in milliseconds since epoch */
    private array $deadlines = [];

    /**
     * Test hook: when true, every write (`set`/`setnx`) refuses like a store
     * that has hit `maxmemory` under `noeviction` — the SAPI returns `false`
     * without storing. Lets the connection's OOM handling be exercised without
     * a real out-of-memory condition.
     */
    private bool $oom = false;

    /**
     * Simulate (or clear) an out-of-memory store for the next writes. Test-only.
     */
    public function simulateOom(bool $on = true): void
    {
        $this->oom = $on;
    }

    public function get(string $key): ?string
    {
        return $this->liveValue($key);
    }

    public function set(string $key, string $value, int $ttlSeconds = 0): bool
    {
        if ($this->oom) {
            return false;
        }
        $this->values[$key] = $value;
        if ($ttlSeconds > 0) {
            $this->deadlines[$key] = $this->nowMs() + ($ttlSeconds * 1000);
        } else {
            unset($this->deadlines[$key]);
        }
        return true;
    }

    public function setnx(string $key, string $value, int $ttlSeconds = 0): bool
    {
        // Mirrors the SAPI: false when a live entry already exists OR the store
        // refuses the write (OOM) — both collapse to the same bool.
        if ($this->oom) {
            return false;
        }
        if ($this->liveValue($key) !== null) {
            return false;
        }
        return $this->set($key, $value, $ttlSeconds);
    }

    public function del(string $key): int
    {
        if ($this->liveValue($key) === null) {
            return 0;
        }
        unset($this->values[$key], $this->deadlines[$key]);
        return 1;
    }

    public function exists(string $key): bool
    {
        return $this->liveValue($key) !== null;
    }

    public function incrBy(string $key, int $delta): int
    {
        $current = $this->liveValue($key);
        if ($current === null) {
            $next = $delta;
        } elseif (\preg_match('/^-?\d+$/', $current) === 1) {
            $next = ((int) $current) + $delta;
        } else {
            throw new \RuntimeException("value at key '{$key}' is not an integer");
        }
        $this->values[$key] = (string) $next;
        // INCR preserves any existing deadline.
        return $next;
    }

    public function expire(string $key, int $ttlSeconds): bool
    {
        if ($this->liveValue($key) === null || $ttlSeconds <= 0) {
            return false;
        }
        $this->deadlines[$key] = $this->nowMs() + ($ttlSeconds * 1000);
        return true;
    }

    public function ttl(string $key): int
    {
        $ms = $this->pttl($key);
        if ($ms < 0) {
            return $ms;
        }
        // Round up so 1..999 ms = 1 s, matching the SAPI's ttl().
        return (int) (($ms + 999) / 1000);
    }

    public function pttl(string $key): int
    {
        if ($this->liveValue($key) === null) {
            return -2;
        }
        if (!isset($this->deadlines[$key])) {
            return -1;
        }
        return \max(0, $this->deadlines[$key] - $this->nowMs());
    }

    public function flush(): bool
    {
        $this->values = [];
        $this->deadlines = [];
        return true;
    }

    /**
     * Resolve a key's value, expiring it lazily if its deadline has passed.
     */
    private function liveValue(string $key): ?string
    {
        if (!\array_key_exists($key, $this->values)) {
            return null;
        }
        if (isset($this->deadlines[$key]) && $this->deadlines[$key] <= $this->nowMs()) {
            unset($this->values[$key], $this->deadlines[$key]);
            return null;
        }
        return $this->values[$key];
    }

    private function nowMs(): int
    {
        return (int) (\microtime(true) * 1000);
    }
}
