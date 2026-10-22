<?php

declare(strict_types=1);

namespace Ephpm\Predis\Tests;

use Ephpm\Predis\CommandNotSupportedException;
use Ephpm\Predis\ExtendedKvOpsInterface;
use Ephpm\Predis\InMemoryKvOps;
use Ephpm\Predis\KvConnection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Response\ServerException;

/**
 * The commands backed by the ePHPm v0.13 `ephpm_kv_*` functions, run through
 * a real Predis client so each reply is checked after Predis' own response
 * parsing — plus the fallback when the running ePHPm lacks them.
 */
#[CoversClass(KvConnection::class)]
#[CoversClass(InMemoryKvOps::class)]
#[CoversClass(CommandNotSupportedException::class)]
final class KvConnectionExtendedTest extends TestCase
{
    private InMemoryKvOps $ops;

    /**
     * @param list<string>|null $ops
     */
    private function client(?array $ops = null): Client
    {
        $this->ops = new InMemoryKvOps($ops);
        return new Client(new KvConnection(null, $this->ops));
    }

    /**
     * @param list<mixed> $args
     */
    private function raw(Client $client, string $id, array $args): mixed
    {
        return $client->executeCommand($client->createCommand($id, $args));
    }

    // ── hashes ───────────────────────────────────────────────────────────────

    public function test_hset_returns_created_count_and_hget_reads_back(): void
    {
        $client = $this->client();
        self::assertSame(1, $client->hset('h', 'a', '1'));
        self::assertSame(0, $client->hset('h', 'a', '2'));
        self::assertSame(1, $this->raw($client, 'HSET', ['h', 'a', '3', 'b', '4']));
        self::assertSame('3', $client->hget('h', 'a'));
        self::assertNull($client->hget('h', 'missing'));
        self::assertNull($client->hget('nohash', 'a'));
    }

    public function test_hset_with_odd_field_value_list_is_an_error(): void
    {
        $this->expectException(ServerException::class);
        $this->raw($this->client(), 'HSET', ['h', 'a']);
    }

    public function test_hset_refused_write_raises(): void
    {
        $client = $this->client();
        $this->ops->simulateOom();
        $this->expectException(ServerException::class);
        $this->expectExceptionMessageMatches('/ephpm_kv_hset failed/');
        $client->hset('h', 'a', '1');
    }

    public function test_hmset_array_form_returns_ok(): void
    {
        $client = $this->client();
        self::assertSame('OK', $client->hmset('h', ['a' => '1', 'b' => '2']));
        self::assertSame(['1', null, '2'], $client->hmget('h', ['a', 'zz', 'b']));
        self::assertSame([null, null], $client->hmget('nohash', 'a', 'b'));
    }

    public function test_hgetall_is_parsed_by_predis_into_an_assoc_array(): void
    {
        $client = $this->client();
        $client->hmset('h', ['a' => '1', 'b' => '2', '7' => 'seven']);
        self::assertEquals(['a' => '1', 'b' => '2', '7' => 'seven'], $client->hgetall('h'));
        self::assertSame([], $client->hgetall('nohash'));
    }

    public function test_hdel_hexists_hlen_hkeys_hvals(): void
    {
        $client = $this->client();
        $client->hmset('h', ['a' => '1', 'b' => '2', '3' => 'c']);
        self::assertSame(1, $client->hexists('h', 'a'));
        self::assertSame(0, $client->hexists('h', 'zz'));
        self::assertSame(3, $client->hlen('h'));
        self::assertSame(['a', 'b', '3'], $client->hkeys('h'));
        self::assertSame(['1', '2', 'c'], $client->hvals('h'));
        self::assertSame(2, $client->hdel('h', ['a', 'b', 'zz']));
        self::assertSame(1, $client->hlen('h'));
        self::assertSame(0, $client->hlen('nohash'));
        self::assertSame([], $client->hkeys('nohash'));
    }

    public function test_hsetnx_returns_one_then_zero(): void
    {
        $client = $this->client();
        self::assertSame(1, $client->hsetnx('h', 'f', 'a'));
        self::assertSame(0, $client->hsetnx('h', 'f', 'b'));
        self::assertSame('a', $client->hget('h', 'f'));
    }

    public function test_hincrby_returns_new_value_and_rejects_non_integer(): void
    {
        $client = $this->client();
        self::assertSame(5, $client->hincrby('h', 'n', 5));
        self::assertSame(3, $client->hincrby('h', 'n', -2));
        $client->hset('h', 's', 'abc');
        $this->expectException(ServerException::class);
        $client->hincrby('h', 's', 1);
    }

    public function test_hexpire_replies_per_field_and_httl_hpttl_hpersist(): void
    {
        $client = $this->client();
        $client->hmset('h', ['a' => '1', 'b' => '2']);
        self::assertSame([1, -2], $client->hexpire('h', 60, ['a', 'missing']));
        self::assertSame([60, -1, -2], $client->httl('h', ['a', 'b', 'missing']));
        [$ms] = $client->hpttl('h', ['a']);
        self::assertGreaterThan(59_000, $ms);
        self::assertSame([1, -1, -2], $client->hpersist('h', ['a', 'b', 'missing']));
        self::assertSame([-1], $client->httl('h', ['a']));
    }

    public function test_hexpire_zero_deletes_the_field_like_redis(): void
    {
        $client = $this->client();
        $client->hset('h', 'a', '1');
        self::assertSame([2, -2], $client->hexpire('h', 0, ['a', 'missing']));
        self::assertSame(0, $client->hexists('h', 'a'));
    }

    public function test_hexpire_negative_ttl_is_an_error(): void
    {
        $client = $this->client();
        $client->hset('h', 'a', '1');
        $this->expectException(ServerException::class);
        $client->hexpire('h', -1, ['a']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hexpireConditions(): iterable
    {
        foreach (['NX', 'XX', 'GT', 'LT'] as $flag) {
            yield $flag => [$flag];
        }
    }

    #[DataProvider('hexpireConditions')]
    public function test_hexpire_conditions_are_rejected_not_ignored(string $flag): void
    {
        $client = $this->client();
        $client->hset('h', 'a', '1');
        $this->expectException(CommandNotSupportedException::class);
        $this->expectExceptionMessageMatches("/HEXPIRE {$flag}/");
        $client->hexpire('h', 60, ['a'], $flag);
    }

    public function test_hpexpire_stays_unsupported(): void
    {
        $this->expectException(CommandNotSupportedException::class);
        $this->client()->hpexpire('h', 1500, ['a']);
    }

    public function test_type_reports_hash(): void
    {
        $client = $this->client();
        $client->hset('h', 'a', '1');
        $client->set('s', 'v');
        self::assertSame('hash', $client->type('h'));
        self::assertSame('string', $client->type('s'));
        self::assertSame('none', $client->type('nope'));
    }

    public function test_del_removes_a_hash(): void
    {
        $client = $this->client();
        $client->hset('h', 'a', '1');
        self::assertSame(1, $client->del('h'));
        self::assertSame(0, $client->hlen('h'));
    }

    // ── strings ──────────────────────────────────────────────────────────────

    public function test_mset_and_mget(): void
    {
        $client = $this->client();
        self::assertSame('OK', $client->mset(['a' => '1', 'b' => '2', '10' => 'ten']));
        self::assertSame(['1', null, '2', 'ten'], $client->mget(['a', 'zz', 'b', '10']));
        self::assertSame(['1', '2'], $client->mget('a', 'b'));
        self::assertSame(-1, $client->ttl('a'));
    }

    public function test_mset_refused_write_raises(): void
    {
        $client = $this->client();
        $this->ops->simulateOom();
        $this->expectException(ServerException::class);
        $client->mset(['a' => '1']);
    }

    public function test_getdel_returns_value_and_deletes(): void
    {
        $client = $this->client();
        $client->set('k', 'v');
        self::assertSame('v', $client->getdel('k'));
        self::assertNull($client->get('k'));
        self::assertNull($client->getdel('k'));
    }

    public function test_getex_ex_px_persist_and_plain(): void
    {
        $client = $this->client();
        $client->set('k', 'v');
        self::assertSame('v', $client->getex('k'));
        self::assertSame(-1, $client->ttl('k'));
        self::assertSame('v', $client->getex('k', 'ex', 60));
        self::assertSame(60, $client->ttl('k'));
        self::assertSame('v', $client->getex('k', 'px', 120_000));
        self::assertSame(120, $client->ttl('k'));
        self::assertSame('v', $client->getex('k', 'persist'));
        self::assertSame(-1, $client->ttl('k'));
        self::assertNull($client->getex('missing', 'ex', 60));
    }

    public function test_getex_sub_second_px_is_not_rounded(): void
    {
        $client = $this->client();
        $client->set('k', 'v');
        $this->expectException(CommandNotSupportedException::class);
        $this->expectExceptionMessageMatches('/GETEX PX/');
        $client->getex('k', 'px', 1500);
    }

    public function test_getex_zero_ex_is_an_error_not_a_persist(): void
    {
        $client = $this->client();
        $client->set('k', 'v', 'EX', 60);
        try {
            $client->getex('k', 'ex', 0);
            self::fail('expected ServerException');
        } catch (ServerException) {
            self::assertSame(60, $client->ttl('k'));
        }
    }

    public function test_getex_exat_stays_unsupported(): void
    {
        $this->expectException(CommandNotSupportedException::class);
        $this->client()->getex('k', 'exat', 9_999_999_999);
    }

    public function test_persist(): void
    {
        $client = $this->client();
        $client->set('k', 'v', 'EX', 60);
        self::assertSame(1, $client->persist('k'));
        self::assertSame(0, $client->persist('k'));
        self::assertSame(-1, $client->ttl('k'));
    }

    public function test_append_and_strlen(): void
    {
        $client = $this->client();
        self::assertSame(3, $client->append('k', 'abc'));
        self::assertSame(5, $client->append('k', 'de'));
        self::assertSame('abcde', $client->get('k'));
        self::assertSame(5, $client->strlen('k'));
        self::assertSame(0, $client->strlen('missing'));
    }

    // ── SCAN ─────────────────────────────────────────────────────────────────

    public function test_scan_returns_string_cursor_and_iterates_every_key(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 25; $i++) {
            $client->set("user:{$i}", 'x');
        }
        $client->set('other', 'x');
        $client->hset('user:hash', 'f', 'v');

        $seen = [];
        $cursor = '0';
        do {
            $page = $client->scan($cursor, ['MATCH' => 'user:*', 'COUNT' => 7]);
            self::assertIsString($page[0]);
            [$cursor, $keys] = $page;
            \array_push($seen, ...$keys);
        } while ($cursor !== '0');

        \sort($seen);
        self::assertCount(26, $seen);
        self::assertNotContains('other', $seen);
        self::assertContains('user:hash', $seen);
    }

    public function test_scan_works_with_the_predis_keyspace_iterator(): void
    {
        $client = $this->client();
        $client->mset(['a:1' => 'x', 'a:2' => 'x', 'b:1' => 'x']);
        $keys = \iterator_to_array(new \Predis\Collection\Iterator\Keyspace($client, 'a:*', 1), false);
        \sort($keys);
        self::assertSame(['a:1', 'a:2'], $keys);
    }

    public function test_scan_type_filter_is_unsupported(): void
    {
        $this->expectException(CommandNotSupportedException::class);
        $this->raw($this->client(), 'SCAN', ['0', 'TYPE', 'hash']);
    }

    public function test_scan_rejects_a_bad_cursor(): void
    {
        $this->expectException(ServerException::class);
        $this->raw($this->client(), 'SCAN', ['abc']);
    }

    // ── SET … IFEQ ───────────────────────────────────────────────────────────

    public function test_set_ifeq_replaces_only_on_match_and_never_creates(): void
    {
        $client = $this->client();
        self::assertNull($client->set('k', 'new', 'IFEQ', 'x'));
        self::assertNull($client->get('k'));

        $client->set('k', 'tok');
        self::assertNull($client->set('k', 'new', 'IFEQ', 'other'));
        self::assertSame('tok', $client->get('k'));
        self::assertSame('OK', $client->set('k', 'new', 'IFEQ', 'tok', 'EX', 30));
        self::assertSame('new', $client->get('k'));
        self::assertSame(30, $client->ttl('k'));
    }

    public function test_set_ifeq_keepttl_keeps_and_plain_clears_the_ttl(): void
    {
        $client = $this->client();
        $client->set('k', 'a', 'EX', 60);
        self::assertSame('OK', $client->set('k', 'b', 'IFEQ', 'a', 'KEEPTTL'));
        self::assertSame(60, $client->ttl('k'));
        self::assertSame('OK', $client->set('k', 'c', 'IFEQ', 'b'));
        self::assertSame(-1, $client->ttl('k'));
    }

    public function test_set_ifeq_with_nx_is_a_syntax_error(): void
    {
        $client = $this->client();
        $client->set('k', 'a');
        $this->expectException(ServerException::class);
        $client->set('k', 'b', 'IFEQ', 'a', 'NX');
    }

    public function test_keepttl_without_ifeq_stays_unsupported(): void
    {
        $this->expectException(CommandNotSupportedException::class);
        $this->expectExceptionMessageMatches('/SET KEEPTTL/');
        $this->client()->set('k', 'v', 'KEEPTTL');
    }

    // ── fallback: an ePHPm without the v0.13 functions ───────────────────────

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function extendedCommands(): iterable
    {
        yield 'HSET' => ['HSET', ['h', 'f', 'v']];
        yield 'HMSET' => ['HMSET', ['h', 'f', 'v']];
        yield 'HSETNX' => ['HSETNX', ['h', 'f', 'v']];
        yield 'HGET' => ['HGET', ['h', 'f']];
        yield 'HMGET' => ['HMGET', ['h', 'f']];
        yield 'HGETALL' => ['HGETALL', ['h']];
        yield 'HDEL' => ['HDEL', ['h', 'f']];
        yield 'HEXISTS' => ['HEXISTS', ['h', 'f']];
        yield 'HLEN' => ['HLEN', ['h']];
        yield 'HKEYS' => ['HKEYS', ['h']];
        yield 'HVALS' => ['HVALS', ['h']];
        yield 'HINCRBY' => ['HINCRBY', ['h', 'f', 1]];
        yield 'HEXPIRE' => ['HEXPIRE', ['h', 60, ['f']]];
        yield 'HTTL' => ['HTTL', ['h', ['f']]];
        yield 'HPTTL' => ['HPTTL', ['h', ['f']]];
        yield 'HPERSIST' => ['HPERSIST', ['h', ['f']]];
        yield 'MGET' => ['MGET', ['a', 'b']];
        yield 'MSET' => ['MSET', ['a', '1']];
        yield 'GETDEL' => ['GETDEL', ['a']];
        yield 'GETEX' => ['GETEX', ['a', 'ex', 5]];
        yield 'PERSIST' => ['PERSIST', ['a']];
        yield 'APPEND' => ['APPEND', ['a', 'x']];
        yield 'STRLEN' => ['STRLEN', ['a']];
        yield 'SCAN' => ['SCAN', [0]];
    }

    /**
     * @param list<mixed> $args
     */
    #[DataProvider('extendedCommands')]
    public function test_without_v013_functions_the_command_stays_unsupported(string $id, array $args): void
    {
        $client = $this->client([]);
        try {
            $this->raw($client, $id, $args);
            self::fail("expected CommandNotSupportedException for {$id}");
        } catch (CommandNotSupportedException $e) {
            self::assertStringContainsString("'{$id}'", $e->getMessage());
        }
    }

    public function test_every_extended_command_has_a_fallback_case(): void
    {
        self::assertSame(
            \array_keys(KvConnection::EXTENDED_COMMANDS),
            \array_keys(\iterator_to_array(self::extendedCommands())),
        );
    }

    public function test_one_missing_function_disables_only_its_command(): void
    {
        $ops = \array_values(\array_diff(ExtendedKvOpsInterface::OPS, ['hdel']));
        $client = $this->client($ops);
        self::assertSame(1, $client->hset('h', 'f', 'v'));
        $this->expectException(CommandNotSupportedException::class);
        // HEXPIRE needs hdel too (for the seconds = 0 case).
        $client->hexpire('h', 60, ['f']);
    }

    public function test_without_v013_functions_type_falls_back_to_exists(): void
    {
        $client = $this->client([]);
        $client->set('s', 'v');
        self::assertSame('string', $client->type('s'));
        self::assertSame('none', $client->type('nope'));
    }

    public function test_without_v013_functions_set_ifeq_is_unsupported(): void
    {
        $client = $this->client([]);
        $client->set('k', 'a');
        $this->expectException(CommandNotSupportedException::class);
        $this->expectExceptionMessageMatches('/SET IFEQ/');
        $client->set('k', 'b', 'IFEQ', 'a');
    }

    public function test_unsupported_message_lists_the_commands_actually_available(): void
    {
        try {
            $this->raw($this->client(), 'LPUSH', ['l', 'x']);
            self::fail('expected CommandNotSupportedException');
        } catch (CommandNotSupportedException $e) {
            self::assertStringContainsString('HGETALL', $e->getMessage());
        }
        try {
            $this->raw($this->client([]), 'LPUSH', ['l', 'x']);
            self::fail('expected CommandNotSupportedException');
        } catch (CommandNotSupportedException $e) {
            self::assertStringNotContainsString('HGETALL', $e->getMessage());
        }
    }
}
