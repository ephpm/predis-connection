<?php

declare(strict_types=1);

namespace Ephpm\Predis\Tests;

use Ephpm\Predis\SapiKvOps;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see SapiKvOps} driven by the global `ephpm_kv_*` stubs in
 * tests/sapi_kv_stubs.php (autoloaded via composer autoload-dev.files), so the
 * production backend can be exercised off the ePHPm runtime.
 */
#[CoversClass(SapiKvOps::class)]
final class SapiKvOpsTest extends TestCase
{
    protected function setUp(): void
    {
        SapiStubKv::reset();
    }

    public function test_incr_by_returns_new_integer_value(): void
    {
        $ops = new SapiKvOps();
        self::assertSame(5, $ops->incrBy('counter', 5));
        self::assertSame(7, $ops->incrBy('counter', 2));
    }

    public function test_incr_by_throws_when_sapi_signals_non_integer(): void
    {
        // The SAPI returns false when the stored value is not an integer.
        // A blind (int) cast would collapse that to 0 and silently corrupt the
        // counter — the bug this test guards against.
        SapiStubKv::$incrByReturn = false;
        $ops = new SapiKvOps();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/is not an integer/");
        $ops->incrBy('label', 1);
    }

    public function test_incr_by_does_not_swallow_a_legitimate_zero(): void
    {
        // Distinguish false from a real 0 result: a counter that lands on 0
        // must return 0, not be mistaken for the non-integer sentinel.
        SapiStubKv::$incrByReturn = 0;
        self::assertSame(0, (new SapiKvOps())->incrBy('counter', 0));
    }

    public function test_setnx_inserts_when_absent_and_refuses_when_present(): void
    {
        $ops = new SapiKvOps();
        self::assertTrue($ops->setnx('lock', '1'));
        self::assertFalse($ops->setnx('lock', '2'));
        self::assertSame('1', $ops->get('lock'));
    }

    public function test_setnx_reports_false_under_oom(): void
    {
        SapiStubKv::$oom = true;
        self::assertFalse((new SapiKvOps())->setnx('lock', '1'));
    }

    public function test_set_reports_false_under_oom(): void
    {
        SapiStubKv::$oom = true;
        self::assertFalse((new SapiKvOps())->set('k', 'v'));
    }
}
