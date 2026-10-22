<?php

declare(strict_types=1);

namespace Ephpm\Predis;

use Predis\Command\CommandInterface;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\Parameters;
use Predis\Connection\ParametersInterface;
use Predis\Response\ServerException;

/**
 * Predis Connection that routes Redis-shaped commands directly to the
 * ePHPm KV SAPI functions (`ephpm_kv_*`), bypassing every socket /
 * RESP-parser layer.
 *
 * Register with a Predis client by mapping a scheme to this class:
 *
 *     $client = new \Predis\Client('ephpm:', [
 *         'connections' => ['ephpm' => \Ephpm\Predis\KvConnection::class],
 *     ]);
 *     $client->set('foo', 'bar');
 *     $client->get('foo');                  // 'bar'
 *     $client->incrby('hits', 5);           // 5
 *     $client->expire('foo', 30);
 *
 * Only the subset of Redis that ephpm's KV store implements is wired
 * through (string GET/SET, counters, TTL, EXISTS, DEL, plus a couple
 * connection-management no-ops; on ePHPm v0.13+ also hashes, MGET/MSET,
 * GETDEL/GETEX, PERSIST, APPEND/STRLEN, SCAN and SET … IFEQ — see
 * {@see EXTENDED_COMMANDS}, each feature-detected). Everything else throws
 * {@see CommandNotSupportedException} with a clear "ephpm KV does not
 * implement <CMD>" message — that surface is intentional, so callers
 * who reach for lists/sets/streams/scripts know to keep that workload
 * on a real Redis.
 */
final class KvConnection implements NodeConnectionInterface
{
    /**
     * Commands this connection actually executes. Listed in the
     * exception message thrown for everything else, so callers see
     * the whole supported surface up-front.
     */
    public const SUPPORTED_COMMANDS = [
        'GET',
        'SET',
        'SETNX',
        'SETEX',
        'PSETEX',
        'DEL',
        'UNLINK',
        'EXISTS',
        'INCR',
        'DECR',
        'INCRBY',
        'DECRBY',
        'EXPIRE',
        'PEXPIRE',
        'TTL',
        'PTTL',
        'TYPE',
        'PING',
        'ECHO',
        'SELECT',
        'AUTH',
        'QUIT',
        'FLUSHDB',
        'FLUSHALL',
    ];

    /**
     * Commands wired to the `ephpm_kv_*` functions added in ePHPm v0.13.0,
     * mapped to the backend operations ({@see ExtendedKvOpsInterface::OPS})
     * each one needs. A command is executed only when the backend supports
     * every listed operation; otherwise it raises
     * {@see CommandNotSupportedException} exactly as before v0.13.
     * (`TYPE` is in {@see SUPPORTED_COMMANDS} and upgrades itself to
     * `ephpm_kv_type` when available; `SET … IFEQ` needs `set_if_eq`.)
     */
    public const EXTENDED_COMMANDS = [
        'HSET'     => ['hset'],
        'HMSET'    => ['hset'],
        'HSETNX'   => ['hsetnx'],
        'HGET'     => ['hget'],
        'HMGET'    => ['hmget'],
        'HGETALL'  => ['hgetall'],
        'HDEL'     => ['hdel'],
        'HEXISTS'  => ['hexists'],
        'HLEN'     => ['hlen'],
        'HKEYS'    => ['hkeys'],
        'HVALS'    => ['hvals'],
        'HINCRBY'  => ['hincr_by'],
        'HEXPIRE'  => ['hexpire', 'hdel'],
        'HTTL'     => ['httl'],
        'HPTTL'    => ['hpttl'],
        'HPERSIST' => ['hpersist', 'hpttl'],
        'MGET'     => ['mget'],
        'MSET'     => ['mset'],
        'GETDEL'   => ['getdel'],
        'GETEX'    => ['getex'],
        'PERSIST'  => ['persist'],
        'APPEND'   => ['append'],
        'STRLEN'   => ['strlen'],
        'SCAN'     => ['scan'],
    ];

    private ParametersInterface $parameters;
    private KvOpsInterface $ops;

    /**
     * Pre-computed responses for commands queued via writeRequest().
     * Predis uses writeRequest()+readResponse() pairs for pipelines.
     * We don't have a network to write to, so we execute eagerly in
     * writeRequest() and hand the buffered result to readResponse() —
     * the visible ordering is identical.
     *
     * @var list<mixed>
     */
    private array $responses = [];

    public function __construct(?ParametersInterface $parameters = null, ?KvOpsInterface $ops = null)
    {
        $this->parameters = $parameters ?? new Parameters();
        $this->ops = $ops ?? new SapiKvOps();
    }

    // ── connection lifecycle (all no-ops — we're always "connected") ─────────

    public function connect(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function getResource(): self
    {
        // Predis treats the resource as opaque; we hand it ourselves.
        return $this;
    }

    public function getParameters(): ParametersInterface
    {
        return $this->parameters;
    }

    public function addConnectCommand(CommandInterface $command): void
    {
        // No AUTH / SELECT handshake to replay — KV access is in-process.
    }

    /**
     * Raw socket read. There's no socket. Predis only calls this from
     * paths the higher-level executeCommand/readResponse don't cover
     * (subscriber, monitor, etc.) and we don't implement any of those.
     */
    public function read(): mixed
    {
        throw new CommandNotSupportedException('raw read');
    }

    public function __toString(): string
    {
        return 'ephpm-kv';
    }

    // ── command dispatch ─────────────────────────────────────────────────────

    public function writeRequest(CommandInterface $command): void
    {
        $this->responses[] = $this->dispatch($command);
    }

    public function readResponse(CommandInterface $command): mixed
    {
        if ($this->responses === []) {
            // Defensive: a stray readResponse without a paired writeRequest.
            return $this->dispatch($command);
        }
        return \array_shift($this->responses);
    }

    public function executeCommand(CommandInterface $command): mixed
    {
        return $this->dispatch($command);
    }

    /**
     * Map a Predis command to the equivalent ephpm_kv_* call (or a
     * tolerated no-op for connection management). Unsupported commands
     * raise {@see CommandNotSupportedException}.
     */
    private function dispatch(CommandInterface $command): mixed
    {
        $id = \strtoupper($command->getId());
        $args = $command->getArguments();

        return match ($id) {
            'GET'             => $this->ops->get((string) $args[0]),
            'SET'             => $this->doSet($args),
            'SETNX'           => $this->doSetnx($args),
            'SETEX'           => $this->doSetex($args),
            'PSETEX'          => $this->doPsetex($args),
            'DEL', 'UNLINK'   => $this->doDel($args),
            'EXISTS'          => $this->doExists($args),
            'INCR'            => $this->ops->incrBy((string) $args[0], 1),
            'DECR'            => $this->ops->incrBy((string) $args[0], -1),
            'INCRBY'          => $this->ops->incrBy((string) $args[0], (int) $args[1]),
            'DECRBY'          => $this->ops->incrBy((string) $args[0], -((int) $args[1])),
            'EXPIRE'          => $this->ops->expire((string) $args[0], (int) $args[1]) ? 1 : 0,
            'PEXPIRE'         => $this->doPExpire($args),
            'TTL'             => $this->ops->ttl((string) $args[0]),
            'PTTL'            => $this->ops->pttl((string) $args[0]),
            'TYPE'            => $this->doType($args),
            'PING'            => $args[0] ?? 'PONG',
            'ECHO'            => (string) $args[0],
            'SELECT', 'AUTH', 'QUIT' => 'OK',
            // ephpm KV is a single keyspace, so FLUSHDB and FLUSHALL are
            // equivalent — both clear the whole effective store.
            'FLUSHDB', 'FLUSHALL' => $this->ops->flush() ? 'OK' : null,
            default           => $this->dispatchExtended($id, $args),
        };
    }

    /**
     * Every command this connection executes against the current backend:
     * {@see SUPPORTED_COMMANDS} plus whichever {@see EXTENDED_COMMANDS} the
     * backend (i.e. the running ePHPm) supports.
     *
     * @return list<string>
     */
    public function supportedCommands(): array
    {
        $commands = self::SUPPORTED_COMMANDS;
        foreach (self::EXTENDED_COMMANDS as $id => $ops) {
            if ($this->supportsAll($ops)) {
                $commands[] = $id;
            }
        }
        return $commands;
    }

    /**
     * @param list<string> $ops
     */
    private function supportsAll(array $ops): bool
    {
        if (!$this->ops instanceof ExtendedKvOpsInterface) {
            return false;
        }
        foreach ($ops as $op) {
            if (!$this->ops->supports($op)) {
                return false;
            }
        }
        return true;
    }

    private function unsupported(string $what): CommandNotSupportedException
    {
        return new CommandNotSupportedException($what, $this->supportedCommands());
    }

    /**
     * The v0.13 commands. Anything not wired — or wired but missing from the
     * running ePHPm — raises {@see CommandNotSupportedException}, the
     * pre-v0.13 behavior for all of these.
     *
     * @param list<mixed> $args
     */
    private function dispatchExtended(string $id, array $args): mixed
    {
        $ops = $this->ops;
        if (!isset(self::EXTENDED_COMMANDS[$id])
            || !$ops instanceof ExtendedKvOpsInterface
            || !$this->supportsAll(self::EXTENDED_COMMANDS[$id])
        ) {
            throw $this->unsupported($id);
        }
        $key = (string) ($args[0] ?? '');

        return match ($id) {
            'HSET'     => $this->doHset($ops, $args, false),
            'HMSET'    => $this->doHset($ops, $args, true),
            'HSETNX'   => $ops->hsetnx($key, (string) $args[1], (string) $args[2]) ? 1 : 0,
            'HGET'     => $ops->hget($key, (string) $args[1]),
            'HMGET'    => $this->orFail($ops->hmget($key, self::strings(\array_slice($args, 1))), 'ephpm_kv_hmget'),
            'HGETALL'  => $this->doHgetall($ops, $key),
            'HDEL'     => $ops->hdel($key, self::strings(\array_slice($args, 1))),
            'HEXISTS'  => $ops->hexists($key, (string) $args[1]) ? 1 : 0,
            'HLEN'     => $ops->hlen($key),
            'HKEYS'    => self::strings($this->orFail($ops->hkeys($key), 'ephpm_kv_hkeys')),
            'HVALS'    => self::strings($this->orFail($ops->hvals($key), 'ephpm_kv_hvals')),
            'HINCRBY'  => $this->doHincrby($ops, $args),
            'HEXPIRE'  => $this->doHexpire($ops, $args),
            'HTTL'     => \array_map(static fn (string $f): int => $ops->httl($key, $f), self::fieldsArg($args, 1, $id)),
            'HPTTL'    => \array_map(static fn (string $f): int => $ops->hpttl($key, $f), self::fieldsArg($args, 1, $id)),
            'HPERSIST' => $this->doHpersist($ops, $args),
            'MGET'     => $this->orFail($ops->mget(self::strings($args)), 'ephpm_kv_mget'),
            'MSET'     => $this->doMset($ops, $args),
            'GETDEL'   => $ops->getdel($key),
            'GETEX'    => $this->doGetex($ops, $args),
            'PERSIST'  => $ops->persist($key) ? 1 : 0,
            'APPEND'   => $this->orFail($ops->append($key, (string) $args[1]), 'ephpm_kv_append'),
            'STRLEN'   => $ops->strlen($key),
            'SCAN'     => $this->doScan($ops, $args),
        };
    }

    /**
     * HSET key field value [field value …] → integer (fields created).
     * HMSET (deprecated alias) → OK. Both write through the array form of
     * `ephpm_kv_hset`; a refused write (memory limit, clustered field value
     * over the size limit) raises a {@see ServerException}.
     *
     * @param list<mixed> $args
     */
    private function doHset(ExtendedKvOpsInterface $ops, array $args, bool $hmset): int|string
    {
        $pairs = \array_slice($args, 1);
        if ($pairs === [] || \count($pairs) % 2 !== 0) {
            $cmd = $hmset ? 'hmset' : 'hset';
            throw new ServerException("ERR wrong number of arguments for '{$cmd}' command");
        }
        $fields = [];
        for ($i = 0, $n = \count($pairs); $i < $n; $i += 2) {
            $fields[(string) $pairs[$i]] = (string) $pairs[$i + 1];
        }
        $created = $this->orFail($ops->hset((string) $args[0], $fields), 'ephpm_kv_hset');
        return $hmset ? 'OK' : $created;
    }

    /**
     * HGETALL — Predis' `HGETALL::parseResponse()` expects the raw RESP
     * shape, a flat `[field, value, field, value, …]` list, and folds it into
     * an associative array itself.
     *
     * @return list<string>
     */
    private function doHgetall(ExtendedKvOpsInterface $ops, string $key): array
    {
        $flat = [];
        foreach ($this->orFail($ops->hgetall($key), 'ephpm_kv_hgetall') as $field => $value) {
            $flat[] = (string) $field;
            $flat[] = (string) $value;
        }
        return $flat;
    }

    /**
     * @param list<mixed> $args
     */
    private function doHincrby(ExtendedKvOpsInterface $ops, array $args): int
    {
        $result = $ops->hincrBy((string) $args[0], (string) $args[1], (int) $args[2]);
        if ($result === false) {
            throw new ServerException('ERR hash value is not an integer or out of range');
        }
        return $result;
    }

    /**
     * HEXPIRE key seconds FIELDS numfields field … → one integer per field:
     * 1 TTL set, -2 no such field, 2 field deleted (seconds = 0, i.e. the
     * expiry is already due — `ephpm_kv_hexpire` refuses a TTL <= 0, so this
     * deletes via `ephpm_kv_hdel` the way Redis does). Each field is handled
     * on its own, so the reply is per field as Redis gives it.
     *
     * The NX/XX/GT/LT conditions would need a read-then-write the store
     * cannot do atomically, so they stay unsupported rather than being
     * ignored. (HPEXPIRE/HEXPIREAT/HPEXPIREAT are not wired at all.)
     *
     * @param list<mixed> $args
     *
     * @return list<int>
     */
    private function doHexpire(ExtendedKvOpsInterface $ops, array $args): array
    {
        $key = (string) $args[0];
        $seconds = self::intArg($args[1] ?? null);
        $at = 2;
        if (isset($args[$at]) && \strtoupper((string) $args[$at]) !== 'FIELDS') {
            $flag = \strtoupper((string) $args[$at]);
            if (\in_array($flag, ['NX', 'XX', 'GT', 'LT'], true)) {
                throw $this->unsupported("HEXPIRE {$flag}");
            }
            throw new ServerException('ERR syntax error');
        }
        $fields = self::fieldsArg($args, $at, 'HEXPIRE');
        if ($seconds < 0) {
            throw new ServerException("ERR invalid expire time in 'hexpire' command");
        }

        $reply = [];
        foreach ($fields as $field) {
            if ($seconds === 0) {
                $reply[] = $ops->hdel($key, [$field]) === 1 ? 2 : -2;
                continue;
            }
            $set = $ops->hexpire($key, $field, $seconds);
            if ($set === false) {
                throw new ServerException("ERR invalid expire time in 'hexpire' command");
            }
            $reply[] = $set === 1 ? 1 : -2;
        }
        return $reply;
    }

    /**
     * HPERSIST key FIELDS numfields field … → per field: 1 TTL removed,
     * -1 field has no TTL, -2 no such field.
     *
     * @param list<mixed> $args
     *
     * @return list<int>
     */
    private function doHpersist(ExtendedKvOpsInterface $ops, array $args): array
    {
        $key = (string) $args[0];
        $reply = [];
        foreach (self::fieldsArg($args, 1, 'HPERSIST') as $field) {
            if ($ops->hpersist($key, $field)) {
                $reply[] = 1;
                continue;
            }
            // false = no field or no TTL; tell the two apart.
            $reply[] = $ops->hpttl($key, $field) === -2 ? -2 : -1;
        }
        return $reply;
    }

    /**
     * MSET key value [key value …] → OK, via `ephpm_kv_mset` (no TTL).
     *
     * @param list<mixed> $args
     */
    private function doMset(ExtendedKvOpsInterface $ops, array $args): string
    {
        if ($args === [] || \count($args) % 2 !== 0) {
            throw new ServerException("ERR wrong number of arguments for 'mset' command");
        }
        $values = [];
        for ($i = 0, $n = \count($args); $i < $n; $i += 2) {
            $values[(string) $args[$i]] = (string) $args[$i + 1];
        }
        if (!$ops->mset($values, 0)) {
            throw new ServerException(
                'ERR ephpm_kv_mset refused the write (memory limit or invalid key); '
                . 'keys before the refused one may already be stored'
            );
        }
        return 'OK';
    }

    /**
     * GETEX key [EX seconds | PX milliseconds | PERSIST]. PX is accepted only
     * when it is a whole number of seconds (the SAPI takes seconds and
     * rounding would change the expiry); EXAT/PXAT and sub-second PX stay
     * unsupported.
     *
     * @param list<mixed> $args
     */
    private function doGetex(ExtendedKvOpsInterface $ops, array $args): ?string
    {
        $key = (string) $args[0];
        if (\count($args) === 1) {
            return $this->ops->get($key);
        }
        $modifier = \strtoupper((string) $args[1]);
        if ($modifier === 'PERSIST' && \count($args) === 2) {
            return $ops->getex($key, 0);
        }
        if (($modifier === 'EX' || $modifier === 'PX') && \count($args) === 3) {
            $amount = self::intArg($args[2]);
            if ($amount <= 0) {
                throw new ServerException("ERR invalid expire time in 'getex' command");
            }
            if ($modifier === 'PX') {
                if ($amount % 1000 !== 0) {
                    throw $this->unsupported('GETEX PX (sub-second)');
                }
                $amount = \intdiv($amount, 1000);
            }
            return $ops->getex($key, $amount);
        }
        if ($modifier === 'EXAT' || $modifier === 'PXAT') {
            throw $this->unsupported("GETEX {$modifier}");
        }
        throw new ServerException('ERR syntax error');
    }

    /**
     * SCAN cursor [MATCH pattern] [COUNT count] → `[cursor-as-string, keys]`,
     * the RESP shape Predis (and its `Keyspace` iterator) expects. In a
     * cluster this lists this node's keyspace only — see the README. The
     * TYPE filter is not supported.
     *
     * @param list<mixed> $args
     *
     * @return array{0: string, 1: list<string>}
     */
    private function doScan(ExtendedKvOpsInterface $ops, array $args): array
    {
        $cursor = (string) ($args[0] ?? '');
        if (\preg_match('/^\d+$/', $cursor) !== 1) {
            throw new ServerException('ERR invalid cursor');
        }
        $match = null;
        $count = 10;
        for ($i = 1, $n = \count($args); $i < $n; $i += 2) {
            $option = \strtoupper((string) $args[$i]);
            if (!\array_key_exists($i + 1, $args)) {
                throw new ServerException('ERR syntax error');
            }
            switch ($option) {
                case 'MATCH':
                    $match = (string) $args[$i + 1];
                    break;
                case 'COUNT':
                    $count = self::intArg($args[$i + 1]);
                    if ($count < 1) {
                        throw new ServerException('ERR syntax error');
                    }
                    break;
                case 'TYPE':
                    throw $this->unsupported('SCAN TYPE');
                default:
                    throw new ServerException('ERR syntax error');
            }
        }
        [$next, $keys] = $this->orFail($ops->scan((int) $cursor, $match, $count), 'ephpm_kv_scan');
        return [(string) $next, self::strings($keys)];
    }

    /**
     * Parse a `FIELDS numfields field …` block starting at `$at`.
     *
     * @param list<mixed> $args
     *
     * @return list<string>
     */
    private static function fieldsArg(array $args, int $at, string $cmd): array
    {
        if (\strtoupper((string) ($args[$at] ?? '')) !== 'FIELDS') {
            throw new ServerException("ERR Mandatory argument FIELDS is missing or not at the right position for '" . \strtolower($cmd) . "'");
        }
        $num = self::intArg($args[$at + 1] ?? null);
        $fields = self::strings(\array_slice($args, $at + 2));
        if ($num < 1 || $num !== \count($fields)) {
            throw new ServerException('ERR The `numfields` parameter must match the number of arguments');
        }
        return $fields;
    }

    private static function intArg(mixed $value): int
    {
        if (\is_int($value)) {
            return $value;
        }
        if (\is_string($value) && \preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        throw new ServerException('ERR value is not an integer or out of range');
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        return \array_map(static fn (mixed $v): string => (string) $v, \array_values($values));
    }

    /**
     * Turn a SAPI failure value (`false`) into a {@see ServerException}.
     *
     * @template T
     *
     * @param T|false $result
     *
     * @return T
     */
    private function orFail(mixed $result, string $function): mixed
    {
        if ($result === false) {
            throw new ServerException(
                "ERR {$function} failed (memory limit, oversized clustered hash field, invalid key, or no KV store)"
            );
        }
        return $result;
    }

    /**
     * SET key value [EX seconds | PX milliseconds] [NX] — the TTL modifiers
     * map directly to the SAPI's TTL parameter, and NX routes to the atomic
     * `ephpm_kv_setnx` primitive. XX/GET/KEEPTTL/EXAT/PXAT need features the
     * store doesn't expose, so they raise rather than silently degrading.
     *
     * Return shape matches Redis: `OK` when the value is stored, and — on the
     * NX path only — `null` (nil) when it isn't. Because `setnx`'s false
     * conflates "key already exists" with an OOM refusal, the nil we return on
     * that path is correct for the common (key-exists) case but cannot single
     * out OOM; this indistinguishability is documented in the README.
     *
     * @param list<mixed> $args
     */
    private function doSet(array $args): ?string
    {
        // Predis' SET::setArguments() drops null/false modifiers without
        // re-indexing; walk a dense list.
        $args = \array_values($args);
        $key = (string) $args[0];
        $value = (string) $args[1];
        $ttl = 0;
        $ttlGiven = false;
        $nx = false;
        $keepTtl = false;
        $ifeq = null;

        $i = 2;
        $count = \count($args);
        while ($i < $count) {
            $modifier = \strtoupper((string) $args[$i]);
            switch ($modifier) {
                case 'EX':
                    $ttl = (int) $args[$i + 1];
                    $ttlGiven = true;
                    $i += 2;
                    break;
                case 'PX':
                    // Round up so PX 1500 → 2 s rather than truncating to 1.
                    $ms = (int) $args[$i + 1];
                    $ttl = (int) \ceil($ms / 1000);
                    $ttlGiven = true;
                    $i += 2;
                    break;
                case 'NX':
                    $nx = true;
                    $i += 1;
                    break;
                case 'KEEPTTL':
                    // Only expressible together with IFEQ (checked below).
                    $keepTtl = true;
                    $i += 1;
                    break;
                case 'IFEQ':
                    if (!\array_key_exists($i + 1, $args)) {
                        throw new ServerException('ERR syntax error');
                    }
                    $ifeq = (string) $args[$i + 1];
                    $i += 2;
                    break;
                case 'XX':
                case 'GET':
                case 'EXAT':
                case 'PXAT':
                    throw $this->unsupported("SET {$modifier}");
                default:
                    throw new \InvalidArgumentException("unknown SET modifier: {$modifier}");
            }
        }

        if ($ifeq !== null) {
            return $this->doSetIfEq($key, $value, $ifeq, $ttl, $ttlGiven, $keepTtl, $nx);
        }
        if ($keepTtl) {
            throw $this->unsupported('SET KEEPTTL');
        }

        if ($nx) {
            // nil when not set (key already existed — the common case);
            // OOM is indistinguishable here and also surfaces as nil.
            return $this->ops->setnx($key, $value, $ttl) ? 'OK' : null;
        }

        // Plain SET always overwrites, so a false is unambiguously an OOM
        // refusal — surface it the way the real RESP wire would.
        if (!$this->ops->set($key, $value, $ttl)) {
            throw $this->oomException();
        }
        return 'OK';
    }

    /**
     * SET key value IFEQ expected [EX s | PX ms | KEEPTTL] — Valkey's
     * compare-and-set, via `ephpm_kv_set_if_eq` (ePHPm v0.13+). `OK` when
     * the value matched and was replaced, nil otherwise (no such key, a
     * different value, or — in a cluster — an unreachable key owner). As in
     * Valkey, the key is never created and, without EX/PX/KEEPTTL, the TTL is
     * cleared. PX rounds up to whole seconds exactly as for plain SET.
     */
    private function doSetIfEq(
        string $key,
        string $value,
        string $expected,
        int $ttl,
        bool $ttlGiven,
        bool $keepTtl,
        bool $nx,
    ): ?string {
        if (!$this->ops instanceof ExtendedKvOpsInterface || !$this->ops->supports('set_if_eq')) {
            throw $this->unsupported('SET IFEQ');
        }
        if ($nx || ($keepTtl && $ttlGiven)) {
            throw new ServerException('ERR syntax error');
        }
        if ($ttlGiven && $ttl <= 0) {
            throw new ServerException("ERR invalid expire time in 'set' command");
        }
        $ttlArg = $keepTtl ? -1 : $ttl;
        return $this->ops->setIfEq($key, $expected, $value, $ttlArg) ? 'OK' : null;
    }

    /**
     * SETNX key value — insert only if absent. Redis integer reply: 1 when
     * inserted, 0 when the key already existed. A refused write (OOM) is also
     * reported as 0, since `setnx`'s false conflates the two outcomes.
     *
     * @param list<mixed> $args
     */
    private function doSetnx(array $args): int
    {
        return $this->ops->setnx((string) $args[0], (string) $args[1], 0) ? 1 : 0;
    }

    /**
     * SETEX key seconds value. `set` false is unambiguously OOM (SETEX always
     * overwrites), so raise the server error rather than reporting a false OK.
     *
     * @param list<mixed> $args
     */
    private function doSetex(array $args): string
    {
        if (!$this->ops->set((string) $args[0], (string) $args[2], (int) $args[1])) {
            throw $this->oomException();
        }
        return 'OK';
    }

    /**
     * PSETEX key milliseconds value — TTL rounded up to whole seconds
     * because the SAPI takes seconds. OOM raises, as for SETEX.
     *
     * @param list<mixed> $args
     */
    private function doPsetex(array $args): string
    {
        $ms = (int) $args[1];
        if (!$this->ops->set((string) $args[0], (string) $args[2], (int) \ceil($ms / 1000))) {
            throw $this->oomException();
        }
        return 'OK';
    }

    /**
     * The server-side error a real Redis raises when a write is rejected for
     * `maxmemory` — reproduced verbatim so callers see the identical
     * {@see ServerException} they would over the RESP wire (its `getErrorType()`
     * is `OOM`).
     */
    private function oomException(): ServerException
    {
        return new ServerException('OOM command not allowed when used memory > maxmemory.');
    }

    /**
     * DEL/UNLINK can take any number of keys; Redis returns the total
     * removed count.
     *
     * @param list<mixed> $args
     */
    private function doDel(array $args): int
    {
        $removed = 0;
        foreach ($args as $key) {
            $removed += $this->ops->del((string) $key);
        }
        return $removed;
    }

    /**
     * EXISTS counts keys present (with duplicates honoured, like Redis).
     *
     * @param list<mixed> $args
     */
    private function doExists(array $args): int
    {
        $present = 0;
        foreach ($args as $key) {
            if ($this->ops->exists((string) $key)) {
                $present++;
            }
        }
        return $present;
    }

    /**
     * PEXPIRE key milliseconds — collapse to the SAPI's seconds API by
     * rounding up so a 100 ms TTL doesn't silently become 0.
     *
     * @param list<mixed> $args
     */
    private function doPExpire(array $args): int
    {
        $ms = (int) $args[1];
        $seconds = (int) \ceil($ms / 1000);
        return $this->ops->expire((string) $args[0], $seconds) ? 1 : 0;
    }

    /**
     * TYPE — `ephpm_kv_type` ("string" | "hash" | "none") on ePHPm v0.13+.
     * Older runtimes have no hashes, so there it is "string" if the key
     * exists and "none" otherwise.
     *
     * @param list<mixed> $args
     */
    private function doType(array $args): string
    {
        if ($this->ops instanceof ExtendedKvOpsInterface && $this->ops->supports('type')) {
            return $this->ops->type((string) $args[0]);
        }
        return $this->ops->exists((string) $args[0]) ? 'string' : 'none';
    }
}
