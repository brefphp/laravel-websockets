<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test;

use Bref\LaravelWebsockets\Connections;
use Bref\LaravelWebsockets\WebsocketsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected FakeConnections $connections;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connections = new FakeConnections;
        app()->instance(Connections::class, $this->connections);
    }

    protected function getPackageProviders($app): array
    {
        return [WebsocketsServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:KkKRtJBHF/+oIwpf0daCIn7Jt97BTKNsSbTM7TrOKHI=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('bref-websockets.api_id', 'abc123');
        $app['config']->set('bref-websockets.stage', 'prod');
        $app['config']->set('bref-websockets.region', 'eu-west-3');
    }
}
