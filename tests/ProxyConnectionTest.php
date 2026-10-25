<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Phattarachai\SapProxy\Exceptions\SapProxyException;
use Phattarachai\SapProxy\Exceptions\SapQueryException;
use Phattarachai\SapProxy\Exceptions\SapUnavailableException;
use Phattarachai\SapProxy\ProxyConnection;

function sentSql(Request $request): string
{
    return isset($request['sql_b64']) ? base64_decode($request['sql_b64']) : $request['sql'];
}

it('sends SQL base64-encoded so an IPS never sees a UNION pattern on the wire', function () {
    Http::fake(['proxy.test/*' => Http::response(['data' => [['N' => 1], ['N' => 2]]])]);
    $sql = 'SELECT 1 AS N FROM DUMMY UNION ALL SELECT 2 AS N FROM DUMMY';

    expect(DB::connection('sap')->select($sql))->toBe([['N' => 1], ['N' => 2]]);

    Http::assertSent(fn (Request $request) => $request['sql_b64'] === base64_encode($sql)
        && ! isset($request['sql'])
        && $request['connection'] === 'sap'
        && ! str_contains(json_encode($request->data()), 'UNION'));
});

it('sends plain sql when encode_sql is off, for endpoints that only read `sql`', function () {
    config()->set('database.connections.sap.encode_sql', false);
    Http::fake(['proxy.test/*' => Http::response(['data' => []])]);

    DB::connection('sap')->select('SELECT 1 FROM DUMMY');

    Http::assertSent(fn (Request $request) => $request['sql'] === 'SELECT 1 FROM DUMMY' && ! isset($request['sql_b64']));
});

it('compiles query builder calls with quoted identifiers and escaped literals', function () {
    Http::fake(['proxy.test/*' => Http::response(['data' => []])]);

    DB::connection('sap')->table('OCRD')
        ->where('CardName', "O'Brien?")
        ->where('CardCode', 'C001')
        ->whereNull('U_Tag')
        ->get();

    Http::assertSent(fn (Request $request) => sentSql($request)
        === 'select * from "OCRD" where "CardName" = \'O\'\'Brien?\' and "CardCode" = \'C001\' and "U_Tag" is null');
});

it('interpolates positional, named, date, bool and enum bindings', function () {
    Http::fake(['proxy.test/*' => Http::response(['data' => []])]);

    DB::connection('sap')->select('SELECT * FROM T WHERE A = ? AND B = ? AND C = ? AND D = ?', [
        'x?', 5, true, new DateTimeImmutable('2026-09-25 08:40:12'),
    ]);
    DB::connection('sap')->select('SELECT * FROM T WHERE A = :doc AND B = :docType', ['doc' => 7, 'docType' => ProxyTestDocType::Invoice]);

    Http::assertSent(fn (Request $request) => sentSql($request)
        === "SELECT * FROM T WHERE A = 'x?' AND B = 5 AND C = 1 AND D = '2026-09-25 08:40:12'");
    Http::assertSent(fn (Request $request) => sentSql($request)
        === "SELECT * FROM T WHERE A = 7 AND B = 'invoice'");
});

it('forwards maxRows and returns the proxy metadata from selectWithLimit', function () {
    Http::fake(['proxy.test/*' => Http::response(['data' => [['N' => 1]], 'total' => 5, 'truncated' => true])]);

    $result = DB::connection('sap')->selectWithLimit('SELECT N FROM T', 1);

    expect($result)->toBe(['data' => [['N' => 1]], 'total' => 5, 'truncated' => true]);
    Http::assertSent(fn (Request $request) => $request['maxRows'] === 1);
});

it('fires QueryExecuted with the interpolated SQL so trackers breadcrumb it', function () {
    Event::fake([QueryExecuted::class]);
    Http::fake(['proxy.test/*' => Http::response(['data' => []])]);

    DB::connection('sap')->select('SELECT * FROM OINV WHERE "DocNum" = ?', [455065]);

    Event::assertDispatched(QueryExecuted::class, fn (QueryExecuted $event) => $event->connectionName === 'sap'
        && $event->sql === 'SELECT * FROM OINV WHERE "DocNum" = 455065');
});

it('turns a proxy 422 into a SapQueryException carrying HANA\'s message and the SQL', function () {
    Http::fake(['proxy.test/*' => Http::response(['error' => 'sql syntax error: incorrect syntax near "DISTINCT"'], 422)]);

    try {
        DB::connection('sap')->select('SELECT DISTINCT FROM DUMMY');
        $this->fail('Expected a SapQueryException.');
    } catch (SapQueryException $e) {
        expect($e->getMessage())->toBe('sql syntax error: incorrect syntax near "DISTINCT"')
            ->and($e->context())->toBe(['sql' => 'SELECT DISTINCT FROM DUMMY']);
    }

    Http::assertSentCount(1);
});

it('retries a connection reset from a restarting proxy and then succeeds', function () {
    Http::fake(['proxy.test/*' => Http::sequence()
        ->pushFailedConnection('cURL error 56: Recv failure: Connection reset by peer')
        ->pushFailedConnection('cURL error 7: Failed to connect to 127.0.0.1 port 3100')
        ->push(['data' => [['N' => 1]]]),
    ]);

    expect(DB::connection('sap')->select('SELECT 1 AS N FROM DUMMY'))->toBe([['N' => 1]]);
    Http::assertSentCount(3);
});

it('throws a SapProxyException carrying the SQL once every retry fails', function () {
    Http::fake(['proxy.test/*' => Http::failedConnection('cURL error 52: Empty reply from server')]);

    try {
        DB::connection('sap')->select('SELECT * FROM OINV');
        $this->fail('Expected a SapProxyException.');
    } catch (SapProxyException $e) {
        expect($e->getMessage())->toContain('SAP proxy [sap] request failed: cURL error 52')
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class)
            ->and($e->context())->toBe(['connection' => 'sap', 'sql' => 'SELECT * FROM OINV']);
    }

    Http::assertSentCount(3);
});

it('does not retry a timeout, which is a slow query rather than a restart', function () {
    Http::fake(['proxy.test/*' => Http::failedConnection('cURL error 28: Operation timed out after 120001 milliseconds')]);

    expect(fn () => DB::connection('sap')->select('SELECT * FROM OINV'))->toThrow(SapProxyException::class);
    Http::assertSentCount(1);
});

it('does not retry a proxy 500 and keeps the RequestException as previous', function () {
    Http::fake(['proxy.test/*' => Http::response(['error' => 'boom'], 500)]);

    try {
        DB::connection('sap')->select('SELECT 1 FROM DUMMY');
        $this->fail('Expected a SapProxyException.');
    } catch (SapProxyException $e) {
        expect($e->getPrevious())->toBeInstanceOf(RequestException::class);
    }

    Http::assertSentCount(1);
});

it('retries a 503 from a web server fronting the proxy', function () {
    Http::fake(['proxy.test/*' => Http::sequence()->push('', 503)->push(['data' => []])]);

    DB::connection('sap')->select('SELECT 1 FROM DUMMY');

    Http::assertSentCount(2);
});

it('throws a SapUnavailableException on the proxy\'s flagged 503 without retrying', function () {
    Http::fake(['proxy.test/*' => Http::response([
        'error' => 'authentication failed',
        'sap_unavailable' => true,
        'reason' => 'auth',
        'retry_after' => 300,
    ], 503, ['Retry-After' => '300'])]);

    try {
        DB::connection('sap')->select('SELECT * FROM OINV');
        $this->fail('Expected a SapUnavailableException.');
    } catch (SapUnavailableException $e) {
        expect($e)->toBeInstanceOf(SapProxyException::class)
            ->and($e->getMessage())->toBe('SAP [sap] unavailable (auth): authentication failed')
            ->and($e->reason)->toBe('auth')
            ->and($e->retryAfter)->toBe(300)
            ->and($e->context())->toBe([
                'connection' => 'sap',
                'sql' => 'SELECT * FROM OINV',
                'reason' => 'auth',
                'retry_after' => 300,
            ]);
    }

    Http::assertSentCount(1);
});

it('reads a null retry_after when the proxy\'s breaker has latched', function () {
    Http::fake(['proxy.test/*' => Http::response([
        'error' => 'authentication failed',
        'sap_unavailable' => true,
        'reason' => 'auth',
        'retry_after' => null,
    ], 503)]);

    try {
        DB::connection('sap')->select('SELECT 1 FROM DUMMY');
        $this->fail('Expected a SapUnavailableException.');
    } catch (SapUnavailableException $e) {
        expect($e->reason)->toBe('auth')
            ->and($e->retryAfter)->toBeNull()
            ->and($e->context()['retry_after'])->toBeNull();
    }

    Http::assertSentCount(1);
});

it('reports a connect outage with its cooldown', function () {
    Http::fake(['proxy.test/*' => Http::response([
        'error' => 'Could not connect to any host: [ 10.0.0.5:30015 - connect ETIMEDOUT ]',
        'sap_unavailable' => true,
        'reason' => 'connect',
        'retry_after' => 30,
    ], 503, ['Retry-After' => '30'])]);

    expect(fn () => DB::connection('sap')->select('SELECT 1 FROM DUMMY'))
        ->toThrow(fn (SapUnavailableException $e) => expect($e->reason)->toBe('connect')->and($e->retryAfter)->toBe(30));
    Http::assertSentCount(1);
});

it('still retries an unflagged 503 and then throws a plain SapProxyException', function () {
    Http::fake(['proxy.test/*' => Http::response('<html>503 Service Unavailable</html>', 503)]);

    try {
        DB::connection('sap')->select('SELECT 1 FROM DUMMY');
        $this->fail('Expected a SapProxyException.');
    } catch (SapProxyException $e) {
        expect($e)->not->toBeInstanceOf(SapUnavailableException::class)
            ->and($e->getPrevious())->toBeInstanceOf(RequestException::class);
    }

    Http::assertSentCount(3);
});

it('stops retrying as soon as a restarting proxy comes back with a flagged 503', function () {
    Http::fake(['proxy.test/*' => Http::sequence()
        ->push('', 502)
        ->push(['error' => 'authentication failed', 'sap_unavailable' => true, 'reason' => 'auth', 'retry_after' => 300], 503),
    ]);

    expect(fn () => DB::connection('sap')->select('SELECT 1 FROM DUMMY'))->toThrow(SapUnavailableException::class);
    Http::assertSentCount(2);
});

it('makes a single attempt when retry is disabled', function () {
    config()->set('database.connections.sap.retry', []);
    Http::fake(['proxy.test/*' => Http::failedConnection()]);

    expect(fn () => DB::connection('sap')->select('SELECT 1 FROM DUMMY'))->toThrow(SapProxyException::class);
    Http::assertSentCount(1);
});

it('refuses a connection with no proxy_url', function () {
    config()->set('database.connections.sap.proxy_url', null);

    DB::connection('sap');
})->throws(InvalidArgumentException::class, 'SAP proxy connection [sap] has no proxy_url configured.');

it('reads the timeout from `timeout`, falling back to the legacy `proxy_timeout`', function () {
    $timeout = new ReflectionProperty(ProxyConnection::class, 'timeout');

    expect($timeout->getValue(new ProxyConnection(['proxy_url' => 'http://x'])))->toBe(ProxyConnection::DEFAULT_TIMEOUT)
        ->and($timeout->getValue(new ProxyConnection(['proxy_url' => 'http://x', 'timeout' => 75])))->toBe(75)
        ->and($timeout->getValue(new ProxyConnection(['proxy_url' => 'http://x', 'proxy_timeout' => '300'])))->toBe(300);
});

enum ProxyTestDocType: string
{
    case Invoice = 'invoice';
}
