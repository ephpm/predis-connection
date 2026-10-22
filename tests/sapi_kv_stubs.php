<?php

declare(strict_types=1);

/**
 * Test-only shims for the global `ephpm_kv_*` SAPI functions.
 *
 * Autoloaded via composer's `autoload-dev.files` so {@see \Ephpm\Predis\SapiKvOps}
 * — which refuses to construct unless these functions exist — can be unit
 * tested off the ePHPm runtime. State lives in {@see \Ephpm\Predis\Tests\SapiStubKv};
 * call ::reset() in a test's setUp to isolate cases.
 *
 * The stubs are guarded by function_exists so a real ePHPm runtime (where these
 * functions are already registered) always wins and these are inert.
 */

namespace Ephpm\Predis\Tests {
    final class SapiStubKv
    {
        /** @var array<string, string> */
        public static array $values = [];

        /**
         * When set, ephpm_kv_incr_by returns this exact value (e.g. `false`
         * to model the "stored value is not an integer" sentinel).
         *
         * @var mixed
         */
        public static mixed $incrByReturn = null;

        /** When true, ephpm_kv_set / ephpm_kv_setnx refuse the write (OOM). */
        public static bool $oom = false;

        /** @var array<string, array<array-key, string>> */
        public static array $hashes = [];

        public static function reset(): void
        {
            self::$values = [];
            self::$hashes = [];
            self::$incrByReturn = null;
            self::$oom = false;
        }
    }
}

namespace {
    use Ephpm\Predis\Tests\SapiStubKv;

    if (!\function_exists('ephpm_kv_get')) {
        function ephpm_kv_get(string $key): ?string
        {
            return SapiStubKv::$values[$key] ?? null;
        }

        function ephpm_kv_set(string $key, string $value, int $ttlSeconds = 0): bool
        {
            if (SapiStubKv::$oom) {
                return false;
            }
            SapiStubKv::$values[$key] = $value;
            return true;
        }

        function ephpm_kv_setnx(string $key, string $value, int $ttlSeconds = 0): bool
        {
            if (SapiStubKv::$oom) {
                return false;
            }
            if (isset(SapiStubKv::$values[$key])) {
                return false;
            }
            SapiStubKv::$values[$key] = $value;
            return true;
        }

        function ephpm_kv_incr_by(string $key, int $delta): int|false
        {
            if (SapiStubKv::$incrByReturn !== null) {
                /** @var int|false */
                return SapiStubKv::$incrByReturn;
            }
            $current = (int) (SapiStubKv::$values[$key] ?? 0);
            $next = $current + $delta;
            SapiStubKv::$values[$key] = (string) $next;
            return $next;
        }
    }

    // A subset of the ePHPm v0.13 functions — enough to check SapiKvOps'
    // pass-through and shape normalisation. The rest (e.g. ephpm_kv_hpersist)
    // are deliberately NOT stubbed, so supports() can be seen reporting false.
    if (!\function_exists('ephpm_kv_hget')) {
        function ephpm_kv_hget(string $key, string $field): ?string
        {
            return SapiStubKv::$hashes[$key][$field] ?? null;
        }

        function ephpm_kv_hset(string $key, string|array $field, ?string $value = null): int|false
        {
            $fields = \is_array($field) ? $field : [$field => $value];
            $created = 0;
            foreach ($fields as $f => $v) {
                $created += isset(SapiStubKv::$hashes[$key][$f]) ? 0 : 1;
                SapiStubKv::$hashes[$key][$f] = (string) $v;
            }
            return $created;
        }

        function ephpm_kv_hgetall(string $key): array|false
        {
            return SapiStubKv::$hashes[$key] ?? [];
        }

        function ephpm_kv_mget(array $keys): array|false
        {
            return \array_map(static fn ($k) => SapiStubKv::$values[$k] ?? null, \array_values($keys));
        }

        function ephpm_kv_mset(array $values, int $ttl = 0): bool
        {
            foreach ($values as $k => $v) {
                SapiStubKv::$values[(string) $k] = (string) $v;
            }
            return true;
        }

        function ephpm_kv_scan(int $cursor, ?string $match = null, int $count = 10): array|false
        {
            // Returns keys keyed by position, to prove SapiKvOps re-lists them.
            return [0, [5 => 'a', 9 => 'b']];
        }
    }
}
