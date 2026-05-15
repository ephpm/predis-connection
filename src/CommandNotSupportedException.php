<?php

declare(strict_types=1);

namespace Ephpm\Predis;

use Predis\PredisException;

/**
 * Thrown when client code dispatches a Predis command that ePHPm's KV
 * store does not implement (lists, hashes, sets, sorted sets, streams,
 * scripting, pub/sub, MULTI/EXEC, …). Surfaces the unsupported command
 * name in the message so callers can switch their code over to the
 * supported subset or move that workload to a real Redis.
 */
final class CommandNotSupportedException extends PredisException
{
    public function __construct(string $commandId)
    {
        parent::__construct(
            "ephpm KV does not implement the Redis command '{$commandId}'. "
            . 'Supported commands: ' . \implode(', ', KvConnection::SUPPORTED_COMMANDS) . '.'
        );
    }
}
