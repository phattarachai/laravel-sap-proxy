# Laravel SAP Proxy

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/laravel-sap-proxy.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-sap-proxy)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-sap-proxy/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/laravel-sap-proxy/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-sap-proxy/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/laravel-sap-proxy/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/laravel-sap-proxy/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/laravel-sap-proxy)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/laravel-sap-proxy.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-sap-proxy)

A Laravel database driver that runs SAP HANA queries through a small HTTP SQL proxy instead of a
local ODBC driver. Your app keeps using Eloquent and the query builder; every statement is sent as
one HTTP request to a proxy that holds the HANA connection.

It is built for hosts where installing the HANA ODBC client in every PHP app is impractical, and it
handles what a proxy in the middle adds:

- **Retries a restarting proxy.** A refused, reset or empty connection (the proxy was restarted by a
  deploy or systemd) and a 502/503 from a fronting web server are retried with backoff. A timeout is
  *not* retried: it means a slow query, and retrying it would stretch the wait past any job timeout.
- **Encodes SQL in base64**, so a network IPS/DPI that resets connections carrying SQL-injection
  signatures (`union … select`) never sees the SQL text.
- **Reports the failing SQL.** Successful statements fire `QueryExecuted`, so they show up in
  `DB::listen`, Pulse and Sentry-style breadcrumbs. Failures carry the SQL in `context()`, which
  Laravel logs and Sentry-compatible trackers attach as `exception_context`.
- **Escapes bound values** (`'` doubled, `?` inside a value never re-substituted, dates, bools,
  enums), because the proxy takes one plain SQL string.

## Installation

```bash
composer require phattarachai/laravel-sap-proxy
```

The service provider is auto-discovered and registers the `proxy` database driver.

## Configuration

Add a connection to `config/database.php`:

```php
'sap' => [
    'driver' => 'proxy',
    'proxy_url' => env('SAP_PROXY_URL'),        // e.g. http://127.0.0.1:3100/api/query
    'database' => 'sap',                        // connection name sent to the proxy
    'timeout' => (int) env('SAP_PROXY_TIMEOUT', 120),
    // 'retry' => [500, 2000, 5000],            // ms before each retry; [] disables retry
    // 'encode_sql' => true,                    // false sends plain `sql` for proxies that can't decode `sql_b64`
],
```

| Key | Default | Meaning |
|---|---|---|
| `proxy_url` | — (required) | The proxy's query endpoint. |
| `database` / `proxy_connection` | `sap` | Which connection the proxy should run the SQL on. |
| `timeout` | `120` | Seconds per attempt. `proxy_timeout` is read as a fallback. |
| `retry` | `[500, 2000, 5000]` | Milliseconds to wait before each retry. The default makes up to 4 attempts over about 7.5s. |
| `encode_sql` | `true` | Send `sql_b64` instead of `sql`. |

Then query it like any other connection:

```php
DB::connection('sap')->table('OINV')->where('DocNum', 455065)->first();

class OINV extends Model
{
    protected $connection = 'sap';
    protected $table = 'OINV';
}
```

`selectWithLimit()` asks the proxy to cap the rows before it serializes them, which is useful for
interactive query editors:

```php
['data' => $rows, 'total' => $total, 'truncated' => $truncated]
    = DB::connection('sap')->selectWithLimit($sql, 5000);
```

## The proxy protocol

The driver POSTs JSON and expects JSON back:

```jsonc
// request
{ "sql_b64": "U0VMRUNUIC4uLg==", "connection": "sap", "maxRows": 5000 }   // maxRows only from selectWithLimit

// 200
{ "data": [ { "DocNum": 455065 } ], "total": 1, "truncated": false }

// 422: the database rejected the SQL
{ "error": "sql syntax error: incorrect syntax near \"FROM\"" }
```

A Node.js proxy that speaks this protocol over the [`hdb`](https://www.npmjs.com/package/hdb)
client is about 150 lines of code. Give it a SIGTERM handler that drains in-flight requests, so a
restart does not cut off queries that are still running.

## Exceptions

| Exception | When | Notes |
|---|---|---|
| `Phattarachai\SapProxy\Exceptions\SapQueryException` | The proxy answered 422 | The message is the database's error text, safe to show to whoever wrote the SQL. `->sql` holds the statement. |
| `Phattarachai\SapProxy\Exceptions\SapProxyException` | Unreachable, timed out, or a non-422 failure after every retry | The previous exception is the underlying `ConnectionException` / `RequestException`. `->sql` and `context()` hold the statement that was running. |

The connection is read-only by nature: it has no PDO, so transactions and `cursor()` throw a
`LogicException`.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
