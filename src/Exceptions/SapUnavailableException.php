<?php

declare(strict_types=1);

namespace Phattarachai\SapProxy\Exceptions;

use Illuminate\Http\Client\Response;

/**
 * The proxy's circuit breaker is open: SAP refused its login (`auth`) or could not be reached
 * (`connect`), so the proxy answered a flagged 503 without touching HANA. Retrying straight away
 * cannot help, so it is never retried. $retryAfter is the proxy's cooldown in seconds, or null
 * when the breaker has latched and waits for a manual reset.
 */
class SapUnavailableException extends SapProxyException
{
    public function __construct(
        string $message,
        string $connectionName,
        string $sql,
        public readonly string $reason,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $connectionName, $sql);
    }

    public static function fromResponse(string $connectionName, string $sql, Response $response): self
    {
        $reason = (string) $response->json('reason', 'connect');
        $retryAfter = $response->json('retry_after');

        return new self(
            "SAP [{$connectionName}] unavailable ({$reason}): {$response->json('error', $response->body())}",
            $connectionName,
            $sql,
            $reason,
            is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * True for the proxy's flagged 503, as opposed to a 503 from a web server in front of a restarting proxy.
     */
    public static function isFlagged(Response $response): bool
    {
        return $response->status() === 503 && $response->json('sap_unavailable') === true;
    }

    /**
     * @return array{connection: string, sql: string, reason: string, retry_after: int|null}
     */
    public function context(): array
    {
        return [
            ...parent::context(),
            'reason' => $this->reason,
            'retry_after' => $this->retryAfter,
        ];
    }
}
