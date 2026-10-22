<?php

declare(strict_types=1);

namespace Ephpm\Predis;

use Predis\PredisException;

/**
 * Thrown when client code dispatches a Predis command that ePHPm's KV
 * store does not implement (lists, sets, sorted sets, streams, scripting,
 * pub/sub, MULTI/EXEC, … — and hashes / the other v0.13 commands when the
 * running ePHPm predates them). Surfaces the unsupported command
 * name in the message so callers can switch their code over to the
 * supported subset or move that workload to a real Redis.
 */
final class CommandNotSupportedException extends PredisException
{
    /**
     * @param list<string>|null $supported the commands the connection does
     *                                     execute (defaults to the base set,
     *                                     {@see KvConnection::SUPPORTED_COMMANDS})
     */
    public function __construct(string $commandId, ?array $supported = null)
    {
        parent::__construct(
            "ephpm KV does not implement the Redis command '{$commandId}'. "
            . 'Supported commands: ' . \implode(', ', $supported ?? KvConnection::SUPPORTED_COMMANDS) . '.'
        );
    }
}
