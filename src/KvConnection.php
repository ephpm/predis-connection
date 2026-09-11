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
 * connection-management no-ops). Everything else throws
 * {@see CommandNotSupportedException} with a clear "ephpm KV does not
 * implement <CMD>" message — that surface is intentional, so callers
 * who reach for hashes/lists/streams/scripts know to keep that workload
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
            default           => throw new CommandNotSupportedException($id),
        };
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
        $key = (string) $args[0];
        $value = (string) $args[1];
        $ttl = 0;
        $nx = false;

        $i = 2;
        $count = \count($args);
        while ($i < $count) {
            $modifier = \strtoupper((string) $args[$i]);
            switch ($modifier) {
                case 'EX':
                    $ttl = (int) $args[$i + 1];
                    $i += 2;
                    break;
                case 'PX':
                    // Round up so PX 1500 → 2 s rather than truncating to 1.
                    $ms = (int) $args[$i + 1];
                    $ttl = (int) \ceil($ms / 1000);
                    $i += 2;
                    break;
                case 'NX':
                    $nx = true;
                    $i += 1;
                    break;
                case 'XX':
                case 'GET':
                case 'KEEPTTL':
                case 'EXAT':
                case 'PXAT':
                    throw new CommandNotSupportedException("SET {$modifier}");
                default:
                    throw new \InvalidArgumentException("unknown SET modifier: {$modifier}");
            }
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
     * TYPE only ever returns "string" or "none" since the store has no
     * other shapes to report — that's the whole point of the connection.
     *
     * @param list<mixed> $args
     */
    private function doType(array $args): string
    {
        return $this->ops->exists((string) $args[0]) ? 'string' : 'none';
    }
}
