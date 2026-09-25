<?php

declare(strict_types=1);

namespace Phattarachai\SapProxy\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The proxy could not be reached, or failed with something other than a SQL error, after every
 * retry. The original transport exception is the previous one; the SQL that was in flight is in
 * context(), which Laravel logs and Sentry-compatible trackers attach as `exception_context`.
 */
class SapProxyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $connectionName,
        public readonly string $sql,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function from(string $connectionName, string $sql, Throwable $previous): self
    {
        return new self(
            "SAP proxy [{$connectionName}] request failed: {$previous->getMessage()}",
            $connectionName,
            $sql,
            $previous,
        );
    }

    /**
     * @return array{connection: string, sql: string}
     */
    public function context(): array
    {
        return [
            'connection' => $this->connectionName,
            'sql' => Str::limit($this->sql, 4000),
        ];
    }
}
