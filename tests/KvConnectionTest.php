<?php

declare(strict_types=1);

namespace Ephpm\Predis\Tests;

use Ephpm\Predis\CommandNotSupportedException;
use Ephpm\Predis\InMemoryKvOps;
use Ephpm\Predis\KvConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Command\RawCommand;
use Predis\Response\ServerException;

#[CoversClass(KvConnection::class)]
#[CoversClass(CommandNotSupportedException::class)]
final class KvConnectionTest extends TestCase
{
    private function client(): Client
    {
        // Pin a fresh InMemoryKvOps per test so state doesn't leak between cases.
        $ops = new InMemoryKvOps();
        $connection = new KvConnection(null, $ops);

        return new Client($connection);
    }

    // ── string ops ────────────────────────────────────────────────────────────

    public function test_set_returns_ok_and_get_returns_value(): void
    {
        $client = $this->client();
        self::assertSame('OK', $client->set('foo', 'bar'));
        self::assertSame('bar', $client->get('foo'));
    }

    public function test_get_missing_key_returns_null(): void
    {
        self::assertNull($this->client()->get('nope'));
    }

    public function test_set_with_ex_modifier_applies_ttl(): void
    {
        $client = $this->client();
        $client->set('foo', 'bar', 'EX', 60);
        $pttl = $client->pttl('foo');
        self::assertGreaterThan(0, $pttl);
        self::assertLessThanOrEqual(60_000, $pttl);
    }

    public function test_set_with_px_rounds_up_to_seconds(): void
    {
        $client = $this->client();
        // PX 1500 ms must round UP to 2 s, never truncate to 1.
        $client->set('foo', 'bar', 'PX', 1500);
        $ttl = $client->ttl('foo');
        self::assertGreaterThanOrEqual(1, $ttl);
        self::assertLessThanOrEqual(2, $ttl);
    }

    public function test_set_with_unsupported_modifier_throws(): void
    {
        $client = $this->client();
        // XX (and GET/KEEPTTL/EXAT/PXAT) stay unsupported; only NX is wired.
        $this->expectException(CommandNotSupportedException::class);
        $this->expectExceptionMessageMatches('/SET XX/');
        $client->set('foo', 'bar', 'XX');
    }

    // ── NX / SETNX (locking primitives) ───────────────────────────────────────

    public function test_setnx_returns_one_when_inserted_zero_when_present(): void
    {
        $client = $this->client();
        self::assertSame(1, $client->setnx('lock', 'a'));
        self::assertSame(0, $client->setnx('lock', 'b'));
        // First writer wins; value is not overwritten.
        self::assertSame('a', $client->get('lock'));
    }

    public function test_set_nx_returns_ok_when_absent_nil_when_present(): void
    {
        $client = $this->client();
        // SET key value NX → OK on first write, nil when the key already exists.
        self::assertSame('OK', $client->set('lock', 'a', 'NX'));
        self::assertNull($client->set('lock', 'b', 'NX'));
        self::assertSame('a', $client->get('lock'));
    }

    public function test_set_nx_with_ex_applies_ttl_on_insert(): void
    {
        $client = $this->client();
        self::assertSame('OK', $client->set('lock', 'a', 'EX', 30, 'NX'));
        $ttl = $client->ttl('lock');
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(30, $ttl);
    }

    // ── OOM handling ──────────────────────────────────────────────────────────

    public function test_plain_set_throws_server_exception_on_oom(): void
    {
        $ops = new InMemoryKvOps();
        $ops->simulateOom(true);
        $client = new Client(new KvConnection(null, $ops));

        try {
            $client->set('k', 'v');
            self::fail('expected ServerException on OOM');
        } catch (ServerException $e) {
            self::assertSame('OOM', $e->getErrorType());
            self::assertStringContainsString('maxmemory', $e->getMessage());
        }
    }

    public function test_setex_throws_server_exception_on_oom(): void
    {
        $ops = new InMemoryKvOps();
        $ops->simulateOom(true);
        $client = new Client(new KvConnection(null, $ops));

        $this->expectException(ServerException::class);
        $client->setex('k', 30, 'v');
    }

    public function test_psetex_throws_server_exception_on_oom(): void
    {
        $ops = new InMemoryKvOps();
        $ops->simulateOom(true);
        $client = new Client(new KvConnection(null, $ops));

        $this->expectException(ServerException::class);
        $psetex = $client->createCommand('PSETEX', ['k', 1000, 'v']);
        $client->executeCommand($psetex);
    }

    public function test_setex_writes_key_with_ttl(): void
    {
        $client = $this->client();
        self::assertSame('OK', $client->setex('foo', 30, 'bar'));
        self::assertSame('bar', $client->get('foo'));
        self::assertGreaterThan(0, $client->ttl('foo'));
    }

    // ── delete + exists ──────────────────────────────────────────────────────

    public function test_del_returns_count_of_actually_removed(): void
    {
        $client = $this->client();
        $client->set('a', '1');
        $client->set('b', '2');
        // c never existed.
        self::assertSame(2, $client->del(['a', 'b', 'c']));
    }

    public function test_unlink_is_aliased_to_del(): void
    {
        $client = $this->client();
        $client->set('a', '1');
        // Use RawCommand rather than createCommand('UNLINK'): older Predis
        // factories don't register an UNLINK command class, which would throw
        // before the request ever reaches our connection. RawCommand bypasses
        // the factory and exercises KvConnection's UNLINK->DEL aliasing directly.
        $unlink = new RawCommand('UNLINK', ['a']);
        self::assertSame(1, $client->executeCommand($unlink));
        self::assertNull($client->get('a'));
    }

    public function test_exists_counts_present_keys(): void
    {
        $client = $this->client();
        $client->set('a', '1');
        $client->set('b', '2');
        self::assertSame(2, $client->exists('a', 'b'));
        self::assertSame(0, $client->exists('nope1', 'nope2'));
    }

    // ── counters ─────────────────────────────────────────────────────────────

    public function test_incr_creates_then_increments(): void
    {
        $client = $this->client();
        self::assertSame(1, $client->incr('hits'));
        self::assertSame(2, $client->incr('hits'));
    }

    public function test_incrby_accepts_arbitrary_delta(): void
    {
        $client = $this->client();
        self::assertSame(5, $client->incrby('counter', 5));
        self::assertSame(15, $client->incrby('counter', 10));
    }

    public function test_decr_and_decrby_subtract(): void
    {
        $client = $this->client();
        $client->set('counter', '10');
        self::assertSame(9, $client->decr('counter'));
        self::assertSame(4, $client->decrby('counter', 5));
    }

    // ── ttl, expire, type ────────────────────────────────────────────────────

    public function test_ttl_minus_two_for_missing_minus_one_for_persistent(): void
    {
        $client = $this->client();
        self::assertSame(-2, $client->ttl('nope'));
        $client->set('persistent', 'v');
        self::assertSame(-1, $client->ttl('persistent'));
    }

    public function test_expire_returns_one_for_existing_zero_for_missing(): void
    {
        $client = $this->client();
        $client->set('foo', 'v');
        self::assertSame(1, $client->expire('foo', 30));
        self::assertSame(0, $client->expire('missing', 30));
    }

    public function test_pexpire_rounds_up_to_seconds(): void
    {
        $client = $this->client();
        $client->set('foo', 'v');
        // 100 ms TTL — must NOT silently round down to 0 seconds, which
        // would leave the key persistent forever. Round up to 1 s.
        $pexpire = $client->createCommand('PEXPIRE', ['foo', 100]);
        self::assertSame(1, $client->executeCommand($pexpire));
        self::assertGreaterThanOrEqual(1, $client->ttl('foo'));
    }

    public function test_type_returns_string_or_none(): void
    {
        $client = $this->client();
        $client->set('foo', 'v');
        self::assertSame('string', $client->type('foo'));
        self::assertSame('none', $client->type('nope'));
    }

    // ── connection management ────────────────────────────────────────────────

    public function test_ping_returns_pong_or_echoes_payload(): void
    {
        $client = $this->client();
        self::assertSame('PONG', $client->ping());
        self::assertSame('hi', $client->ping('hi'));
    }

    public function test_echo_returns_argument(): void
    {
        self::assertSame('hello', $this->client()->echo('hello'));
    }

    public function test_select_auth_quit_are_silent_no_ops(): void
    {
        $client = $this->client();
        // Frameworks routinely emit SELECT 0 / AUTH on connect; tolerating
        // them as no-ops keeps that wiring boring.
        $select = $client->createCommand('SELECT', [0]);
        $auth = $client->createCommand('AUTH', ['ignored']);
        $quit = $client->createCommand('QUIT', []);
        self::assertSame('OK', $client->executeCommand($select));
        self::assertSame('OK', $client->executeCommand($auth));
        self::assertSame('OK', $client->executeCommand($quit));
    }

    // ── unsupported commands ─────────────────────────────────────────────────

    public function test_unsupported_command_throws_with_helpful_message(): void
    {
        $client = $this->client();
        $this->expectException(CommandNotSupportedException::class);
        // LPUSH is the canonical "this needs a real Redis" example.
        $client->lpush('list', 'x');
    }

    public function test_unsupported_command_message_lists_supported_set(): void
    {
        $client = $this->client();
        try {
            $cmd = $client->createCommand('XADD', ['stream', '*', 'k', 'v']);
            $client->executeCommand($cmd);
            self::fail('expected CommandNotSupportedException');
        } catch (CommandNotSupportedException $e) {
            self::assertStringContainsString('XADD', $e->getMessage());
            self::assertStringContainsString('GET', $e->getMessage());
            self::assertStringContainsString('SET', $e->getMessage());
        }
    }

    // ── pipeline ─────────────────────────────────────────────────────────────

    public function test_pipeline_executes_in_order(): void
    {
        $client = $this->client();
        $responses = $client->pipeline(static function ($pipe): void {
            $pipe->set('a', '1');
            $pipe->set('b', '2');
            $pipe->incr('counter');
            $pipe->incr('counter');
            $pipe->get('a');
            $pipe->get('b');
            $pipe->get('counter');
        });
        self::assertSame(['OK', 'OK', 1, 2, '1', '2', '2'], $responses);
    }

    // ── flush ────────────────────────────────────────────────────────────────

    public function test_flushdb_clears_all_keys(): void
    {
        $client = $this->client();
        $client->set('a', '1');
        $client->set('b', '2');
        self::assertSame(2, $client->exists('a', 'b'));

        self::assertSame('OK', $client->flushdb());

        self::assertSame(0, $client->exists('a', 'b'));
        self::assertNull($client->get('a'));

        // Usable after flush.
        $client->set('c', '3');
        self::assertSame('3', $client->get('c'));
    }

    public function test_flushall_clears_all_keys(): void
    {
        $client = $this->client();
        $client->set('a', '1');
        self::assertSame('OK', $client->flushall());
        self::assertNull($client->get('a'));
    }

    // ── connection lifecycle ─────────────────────────────────────────────────

    public function test_connection_reports_always_connected(): void
    {
        $connection = new KvConnection(null, new InMemoryKvOps());
        self::assertTrue($connection->isConnected());
        $connection->connect();
        self::assertTrue($connection->isConnected());
        $connection->disconnect();
        // disconnect is a no-op — we're "connected" via FFI, not a socket.
        self::assertTrue($connection->isConnected());
    }
}
