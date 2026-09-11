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

        public static function reset(): void
        {
            self::$values = [];
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
}
