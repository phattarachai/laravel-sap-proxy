<?php

declare(strict_types=1);

namespace Phattarachai\SapProxy\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\SapProxy\SapProxyServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [SapProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.connections.sap', [
            'driver' => 'proxy',
            'proxy_url' => 'http://proxy.test/api/query',
            'database' => 'sap',
            'retry' => [0, 0],
        ]);
    }
}
