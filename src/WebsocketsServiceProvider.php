<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use AsyncAws\Core\HttpClient\AwsHttpClientFactory;
use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\Lambda\LambdaClient;
use Bref\LaravelWebsockets\Store\DatabaseStore;
use Bref\LaravelWebsockets\Store\DynamoDbStore;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClient;

class WebsocketsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/bref-websockets.php', 'bref-websockets');

        // So that BROADCAST_CONNECTION=api-gateway is all that users have to set
        if (!$this->app instanceof CachesConfiguration || !$this->app->configurationIsCached()) {
            $this->config()->set('broadcasting.connections.api-gateway', ['driver' => 'api-gateway']);
        }

        $this->app->singleton(SubscriptionStore::class, fn() => match ($this->config()->string('bref-websockets.store')) {
            'database' => $this->databaseStore(),
            'dynamodb' => $this->dynamoDbStore(),
            default => throw new InvalidArgumentException('BREF_WEBSOCKET_STORE must be "database" or "dynamodb".'),
        });

        $this->app->singleton(Connections::class, function () {
            $region = $this->config()->string('bref-websockets.region');
            $apiId = $this->config()->string('bref-websockets.api_id');

            return new Connections(
                new ApiGatewayClient(
                    ['region' => $region, 'endpoint' => "https://{$apiId}.execute-api.{$region}.amazonaws.com"],
                    null,
                    // Retries when API Gateway throttles, but not on 410 (connection gone)
                    AwsHttpClientFactory::createRetryableClient(HttpClient::create(['timeout' => 10], 100)),
                ),
                $this->config()->string('bref-websockets.stage'),
            );
        });

        $this->app->singleton(ChannelSignature::class, fn() => new ChannelSignature($this->config()->string('app.key')));

        $this->app->singleton(Publisher::class);

        $this->app->singleton(Handler::class, function (Application $app) {
            /** @var list<string> $allowedOrigins */
            $allowedOrigins = $this->config()->array('bref-websockets.allowed_origins');

            return new Handler(
                $app->make(SubscriptionStore::class),
                $app->make(Connections::class),
                $app->make(Publisher::class),
                $app->make(ChannelSignature::class),
                new LambdaClient(['region' => $this->config()->string('bref-websockets.region')]),
                $allowedOrigins,
            );
        });
    }

    public function boot(): void
    {
        $this->callAfterResolving(BroadcastManager::class, function (BroadcastManager $manager) {
            $manager->extend('api-gateway', fn(Application $app) => $app->make(ApiGatewayBroadcaster::class));
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/bref-websockets.php' => config_path('bref-websockets.php')], 'bref-websockets-config');

            if ($this->config()->get('bref-websockets.store') === 'database') {
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            }
        }
    }

    private function databaseStore(): DatabaseStore
    {
        $connection = $this->config()->get('bref-websockets.database.connection');

        return new DatabaseStore(
            $this->app->make(DatabaseManager::class)->connection(is_string($connection) ? $connection : null),
            $this->config()->string('bref-websockets.database.table'),
        );
    }

    private function dynamoDbStore(): DynamoDbStore
    {
        $endpoint = $this->config()->get('bref-websockets.dynamodb.endpoint');
        $options = ['region' => $this->config()->string('bref-websockets.region')];

        if (is_string($endpoint)) {
            $options['endpoint'] = $endpoint;
        }

        return new DynamoDbStore(new DynamoDbClient($options), $this->config()->string('bref-websockets.dynamodb.table'));
    }

    private function config(): Repository
    {
        return $this->app->make(Repository::class);
    }
}
