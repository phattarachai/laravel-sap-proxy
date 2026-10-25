<?php

declare(strict_types=1);

namespace Phattarachai\SapProxy;

use BackedEnum;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Phattarachai\SapProxy\Exceptions\SapProxyException;
use Phattarachai\SapProxy\Exceptions\SapQueryException;
use Phattarachai\SapProxy\Exceptions\SapUnavailableException;
use Throwable;

/**
 * A read-only database connection whose every statement is POSTed to an HTTP SQL proxy
 * (`{"sql_b64": ..., "connection": ...}` → `{"data": [...]}`) instead of running over PDO.
 */
class ProxyConnection extends Connection
{
    public const int DEFAULT_TIMEOUT = 120;

    /** Milliseconds to wait before each retry — covers a proxy restart (deploy, crash + systemd restart). */
    public const array DEFAULT_RETRY = [500, 2000, 5000];

    private readonly string $proxyUrl;

    private readonly string $proxyConnection;

    private readonly int $timeout;

    /** @var list<int> */
    private readonly array $retryBackoff;

    private readonly bool $encodeSql;

    /**
     * @param  array{
     *     proxy_url?: string|null,
     *     proxy_connection?: string,
     *     database?: string,
     *     timeout?: int|string,
     *     proxy_timeout?: int|string,
     *     retry?: list<int>,
     *     encode_sql?: bool,
     *     name?: string,
     *     prefix?: string,
     * }  $config
     */
    public function __construct(array $config)
    {
        if (blank($config['proxy_url'] ?? null)) {
            throw new InvalidArgumentException('SAP proxy connection ['.($config['name'] ?? 'sap').'] has no proxy_url configured.');
        }

        $this->proxyUrl = (string) $config['proxy_url'];
        $this->proxyConnection = (string) ($config['proxy_connection'] ?? $config['database'] ?? 'sap');
        $this->timeout = (int) ($config['timeout'] ?? $config['proxy_timeout'] ?? self::DEFAULT_TIMEOUT);
        $this->retryBackoff = array_map(intval(...), $config['retry'] ?? self::DEFAULT_RETRY);
        $this->encodeSql = (bool) ($config['encode_sql'] ?? true);

        parent::__construct(
            static fn (): never => throw new LogicException('The SAP proxy connection has no PDO; it only runs SQL through the proxy.'),
            $this->proxyConnection,
            $config['prefix'] ?? '',
            $config,
        );
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     * @param  bool  $useReadPdo
     * @param  array<array-key, mixed>  $fetchUsing
     * @return list<array<string, mixed>>
     */
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->send($query, $bindings)->json('data', []);
    }

    /**
     * Run a query capped at $maxRows, returning the rows plus the true total. The proxy slices
     * the result before serializing, so the full row set never crosses the wire.
     *
     * @param  array<array-key, mixed>  $bindings
     * @return array{data: list<array<string, mixed>>, total: int, truncated: bool}
     */
    public function selectWithLimit(string $query, int $maxRows, array $bindings = []): array
    {
        $response = $this->send($query, $bindings, ['maxRows' => $maxRows]);

        return [
            'data' => $response->json('data', []),
            'total' => (int) $response->json('total', 0),
            'truncated' => (bool) $response->json('truncated', false),
        ];
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        $this->send($query, $bindings);

        return true;
    }

    /**
     * @param  string  $query
     * @param  array<array-key, mixed>  $bindings
     */
    public function affectingStatement($query, $bindings = []): int
    {
        $this->send($query, $bindings);

        return 0;
    }

    public function getDatabaseName(): string
    {
        return $this->proxyConnection;
    }

    /**
     * Interpolate the bindings (the proxy takes one SQL string, no placeholders), POST it with
     * retry on a dropped connection, and fire QueryExecuted so the SQL lands in query listeners
     * and error-tracker breadcrumbs like any PDO query.
     *
     * @param  array<array-key, mixed>  $bindings
     * @param  array<string, mixed>  $extra
     *
     * @throws SapQueryException the proxy answered 422 — HANA rejected the SQL
     * @throws SapUnavailableException the proxy's breaker is open (flagged 503) — thrown on the first answer, never retried
     * @throws SapProxyException the proxy was unreachable or failed after every retry
     */
    private function send(string $query, array $bindings, array $extra = []): Response
    {
        $sql = $this->interpolateBindings($query, $this->prepareBindings($bindings));
        $payload = [
            ...($this->encodeSql ? ['sql_b64' => base64_encode($sql)] : ['sql' => $sql]),
            'connection' => $this->proxyConnection,
            ...$extra,
        ];
        $start = microtime(true);

        try {
            $response = $this->request()->post($this->proxyUrl, $payload);
        } catch (ConnectionException $e) {
            throw SapProxyException::from($this->getName() ?? $this->proxyConnection, $sql, $e);
        }

        if ($response->status() === 422) {
            throw new SapQueryException((string) $response->json('error', $response->body()), $sql);
        }

        if (SapUnavailableException::isFlagged($response)) {
            throw SapUnavailableException::fromResponse($this->getName() ?? $this->proxyConnection, $sql, $response);
        }

        if ($response->failed()) {
            throw SapProxyException::from($this->getName() ?? $this->proxyConnection, $sql, $response->toException());
        }

        $this->logQuery($sql, [], round((microtime(true) - $start) * 1000, 2));

        return $response;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout);

        if ($this->retryBackoff === []) {
            return $request;
        }

        return $request->retry($this->retryBackoff, when: self::isRetryable(...), throw: false);
    }

    /**
     * Retry only what a restarting proxy produces: refused / reset / empty reply, or a 502/503
     * from a web server in front of it. A cURL 28 timeout is a slow query, not a restart —
     * retrying it would multiply the wait past any job timeout. The proxy's own flagged 503
     * (SAP unavailable) is not a restart either: its breaker answers the same until the cooldown ends.
     */
    private static function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return ! str_contains($e->getMessage(), 'cURL error 28');
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), [502, 503], true)
            && ! SapUnavailableException::isFlagged($e->response);
    }

    /**
     * Positional `?` are replaced left to right, skipping past each inserted literal so a `?`
     * inside a bound value is never substituted again; named `:key` match whole words only.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    private function interpolateBindings(string $query, array $bindings): string
    {
        $offset = 0;

        foreach ($bindings as $key => $value) {
            if (is_string($key)) {
                continue;
            }

            $position = strpos($query, '?', $offset);

            if ($position === false) {
                break;
            }

            $literal = $this->escapeValue($value);
            $query = substr_replace($query, $literal, $position, 1);
            $offset = $position + strlen($literal);
        }

        foreach ($bindings as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $query = (string) preg_replace_callback(
                '/:'.preg_quote(ltrim($key, ':'), '/').'\b/',
                fn (): string => $this->escapeValue($value),
                $query,
            );
        }

        return $query;
    }

    private function escapeValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum => $this->escapeValue($value->value),
            default => "'".str_replace("'", "''", (string) $value)."'",
        };
    }
}
