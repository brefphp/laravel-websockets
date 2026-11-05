<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Integration;

use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Bref\LaravelWebsockets\Test\StoreContract;
use Bref\LaravelWebsockets\Test\TestCase;

/**
 * Runs against the database in TEST_DB_DRIVER (mysql, mariadb or pgsql), see the README.
 */
class DatabaseStoreTest extends TestCase
{
    use StoreContract;

    protected function setUp(): void
    {
        if (! is_string(getenv('TEST_DB_DRIVER'))) {
            $this->markTestSkipped('TEST_DB_DRIVER is not set');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $driver = (string) getenv('TEST_DB_DRIVER');
        $app['config']->set('database.default', $driver);
        foreach (['host' => '127.0.0.1', 'port' => (string) getenv('TEST_DB_PORT'), 'database' => 'test', 'username' => 'test', 'password' => 'test'] as $key => $value) {
            $app['config']->set("database.connections.{$driver}.{$key}", $value);
        }
    }

    protected function store(): SubscriptionStore
    {
        return app(SubscriptionStore::class);
    }
}
