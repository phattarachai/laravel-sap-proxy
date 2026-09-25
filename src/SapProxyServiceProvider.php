<?php

declare(strict_types=1);

namespace Phattarachai\SapProxy;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

class SapProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving('db', function (DatabaseManager $db): void {
            $db->extend('proxy', fn (array $config, string $name): ProxyConnection => new ProxyConnection([...$config, 'name' => $name]));
        });
    }
}
