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
 *
 * It also models the ePHPm v0.13 operations ({@see ExtendedKvOpsInterface}):
 * hashes live in their own namespace beside strings (as in ePHPm, a name can
 * be both), with per-field TTLs. Pass a list of operation names to the
 * constructor to emulate an older ePHPm that lacks some of them — `[]`
 * behaves like a pre-v0.13 runtime. Whole-key TTLs (`expire`/`ttl`/`pttl`/
 * `persist`) apply to string keys only in this fake.
 */
final class InMemoryKvOps implements ExtendedKvOpsInterface
{
    /** @var array<string, string> */
    private array $values = [];

    /** @var array<string, int> deadline in milliseconds since epoch */
    private array $deadlines = [];

    /** @var array<string, array<array-key, string>> */
    private array $hashes = [];

    /** @var array<string, array<array-key, int>> field deadline in ms since epoch */
    private array $fieldDeadlines = [];

    /** @var list<string> */
    private array $ops;

    /**
     * @param list<string>|null $ops the v0.13 operations to report as
     *                               available (see {@see ExtendedKvOpsInterface::OPS});
     *                               null = all of them, [] = a pre-v0.13 runtime
     */
    public function __construct(?array $ops = null)
    {
        $this->ops = $ops ?? self::OPS;
    }

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
        // Like ephpm_kv_del, deletes a hash of that name too.
        $hadHash = $this->liveHash($key) !== [];
        unset($this->hashes[$key], $this->fieldDeadlines[$key]);
        if ($this->liveValue($key) === null) {
            return $hadHash ? 1 : 0;
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
        $this->hashes = [];
        $this->fieldDeadlines = [];
        return true;
    }

    // ── v0.13 operations ─────────────────────────────────────────────────────

    public function supports(string $op): bool
    {
        return \in_array($op, $this->ops, true);
    }

    public function hget(string $key, string $field): ?string
    {
        return $this->liveHash($key)[$field] ?? null;
    }

    public function hset(string $key, array $fields): int|false
    {
        if ($this->oom) {
            return false;
        }
        $hash = $this->liveHash($key);
        $created = 0;
        foreach ($fields as $field => $value) {
            if (!\array_key_exists($field, $hash)) {
                $created++;
            }
            $hash[$field] = (string) $value;
            // Writing a field clears its TTL.
            unset($this->fieldDeadlines[$key][$field]);
        }
        $this->hashes[$key] = $hash;
        return $created;
    }

    public function hsetnx(string $key, string $field, string $value): bool
    {
        if ($this->oom || \array_key_exists($field, $this->liveHash($key))) {
            return false;
        }
        return $this->hset($key, [$field => $value]) !== false;
    }

    public function hmget(string $key, array $fields): array|false
    {
        $hash = $this->liveHash($key);
        $out = [];
        foreach ($fields as $field) {
            $out[] = $hash[$field] ?? null;
        }
        return $out;
    }

    public function hgetall(string $key): array|false
    {
        return $this->liveHash($key);
    }

    public function hdel(string $key, array $fields): int
    {
        $hash = $this->liveHash($key);
        $removed = 0;
        foreach (\array_unique($fields) as $field) {
            if (\array_key_exists($field, $hash)) {
                unset($hash[$field], $this->fieldDeadlines[$key][$field]);
                $removed++;
            }
        }
        $this->storeHash($key, $hash);
        return $removed;
    }

    public function hexists(string $key, string $field): bool
    {
        return \array_key_exists($field, $this->liveHash($key));
    }

    public function hlen(string $key): int
    {
        return \count($this->liveHash($key));
    }

    public function hkeys(string $key): array|false
    {
        return \array_keys($this->liveHash($key));
    }

    public function hvals(string $key): array|false
    {
        return \array_values($this->liveHash($key));
    }

    public function hincrBy(string $key, string $field, int $delta): int|false
    {
        $current = $this->liveHash($key)[$field] ?? '0';
        if (\preg_match('/^-?\d+$/', $current) !== 1) {
            return false;
        }
        $next = ((int) $current) + $delta;
        // hincr_by keeps the field's TTL, so write around hset().
        $this->hashes[$key][$field] = (string) $next;
        return $next;
    }

    public function hexpire(string $key, string $field, int $ttlSeconds): int|false
    {
        if ($ttlSeconds <= 0) {
            return false;
        }
        if (!\array_key_exists($field, $this->liveHash($key))) {
            return 0;
        }
        $this->fieldDeadlines[$key][$field] = $this->nowMs() + ($ttlSeconds * 1000);
        return 1;
    }

    public function httl(string $key, string $field): int
    {
        $ms = $this->hpttl($key, $field);
        return $ms < 0 ? $ms : (int) (($ms + 999) / 1000);
    }

    public function hpttl(string $key, string $field): int
    {
        if (!\array_key_exists($field, $this->liveHash($key))) {
            return -2;
        }
        if (!isset($this->fieldDeadlines[$key][$field])) {
            return -1;
        }
        return \max(0, $this->fieldDeadlines[$key][$field] - $this->nowMs());
    }

    public function hpersist(string $key, string $field): bool
    {
        if ($this->hpttl($key, $field) < 0) {
            return false;
        }
        unset($this->fieldDeadlines[$key][$field]);
        return true;
    }

    public function mget(array $keys): array|false
    {
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->liveValue((string) $key);
        }
        return $out;
    }

    public function mset(array $values, int $ttlSeconds = 0): bool
    {
        foreach ($values as $key => $value) {
            if (!$this->set((string) $key, (string) $value, $ttlSeconds)) {
                return false;
            }
        }
        return true;
    }

    public function getdel(string $key): ?string
    {
        $value = $this->liveValue($key);
        if ($value !== null) {
            unset($this->values[$key], $this->deadlines[$key]);
        }
        return $value;
    }

    public function getex(string $key, int $ttlSeconds): ?string
    {
        if ($ttlSeconds < 0) {
            return null;
        }
        $value = $this->liveValue($key);
        if ($value === null) {
            return null;
        }
        if ($ttlSeconds === 0) {
            unset($this->deadlines[$key]);
        } else {
            $this->deadlines[$key] = $this->nowMs() + ($ttlSeconds * 1000);
        }
        return $value;
    }

    public function persist(string $key): bool
    {
        if ($this->liveValue($key) === null || !isset($this->deadlines[$key])) {
            return false;
        }
        unset($this->deadlines[$key]);
        return true;
    }

    public function setIfEq(string $key, string $expected, string $value, int $ttlSeconds = 0): bool
    {
        if ($this->oom || $this->liveValue($key) !== $expected) {
            return false;
        }
        $this->values[$key] = $value;
        if ($ttlSeconds > 0) {
            $this->deadlines[$key] = $this->nowMs() + ($ttlSeconds * 1000);
        } elseif ($ttlSeconds === 0) {
            unset($this->deadlines[$key]);
        }
        return true;
    }

    public function append(string $key, string $value): int|false
    {
        if ($this->oom) {
            return false;
        }
        $next = ($this->liveValue($key) ?? '') . $value;
        $this->values[$key] = $next;
        return \strlen($next);
    }

    public function strlen(string $key): int
    {
        return \strlen($this->liveValue($key) ?? '');
    }

    public function type(string $key): string
    {
        if ($this->liveValue($key) !== null) {
            return 'string';
        }
        return $this->liveHash($key) !== [] ? 'hash' : 'none';
    }

    /**
     * The cursor is an offset into a sorted snapshot of every live name (a
     * name that is both a string and a hash appears once per type), which is
     * enough for a single-threaded test double.
     */
    public function scan(int $cursor, ?string $match = null, int $count = 10): array|false
    {
        if ($cursor < 0 || $count < 1) {
            return false;
        }
        $names = [];
        foreach (\array_keys($this->values) as $key) {
            if ($this->liveValue((string) $key) !== null) {
                $names[] = (string) $key;
            }
        }
        foreach (\array_keys($this->hashes) as $key) {
            if ($this->liveHash((string) $key) !== []) {
                $names[] = (string) $key;
            }
        }
        \sort($names, \SORT_STRING);

        $page = \array_slice($names, $cursor, $count);
        $next = $cursor + $count >= \count($names) ? 0 : $cursor + $count;
        if ($match !== null) {
            $page = \array_values(\array_filter(
                $page,
                static fn (string $name): bool => \fnmatch($match, $name),
            ));
        }
        return [$next, $page];
    }

    /**
     * A hash's live fields, dropping (lazily) any whose TTL has passed.
     *
     * @return array<array-key, string>
     */
    private function liveHash(string $key): array
    {
        $hash = $this->hashes[$key] ?? [];
        $now = $this->nowMs();
        foreach ($this->fieldDeadlines[$key] ?? [] as $field => $deadline) {
            if ($deadline <= $now) {
                unset($hash[$field], $this->fieldDeadlines[$key][$field]);
            }
        }
        $this->storeHash($key, $hash);
        return $hash;
    }

    /**
     * @param array<array-key, string> $hash
     */
    private function storeHash(string $key, array $hash): void
    {
        if ($hash === []) {
            unset($this->hashes[$key], $this->fieldDeadlines[$key]);
        } else {
            $this->hashes[$key] = $hash;
        }
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
