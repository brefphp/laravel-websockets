<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Integration;

use AsyncAws\DynamoDb\DynamoDbClient;
use Bref\LaravelWebsockets\Store\DynamoDbStore;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Bref\LaravelWebsockets\Test\StoreContract;
use PHPUnit\Framework\TestCase;

/**
 * Runs against DynamoDB Local at TEST_DYNAMODB_ENDPOINT, see the README.
 */
class DynamoDbStoreTest extends TestCase
{
    use StoreContract;

    private DynamoDbClient $client;
    private string $table;

    protected function setUp(): void
    {
        $endpoint = getenv('TEST_DYNAMODB_ENDPOINT');

        if (! is_string($endpoint)) {
            $this->markTestSkipped('TEST_DYNAMODB_ENDPOINT is not set');
        }

        $this->client = new DynamoDbClient([
            'endpoint' => $endpoint,
            'region' => 'eu-west-3',
            'accessKeyId' => 'test',
            'accessKeySecret' => 'test',
        ]);
        $this->table = 'subscriptions-' . bin2hex(random_bytes(4));
        // Same definition as in the README
        $this->client->createTable([
            'TableName' => $this->table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [
                ['AttributeName' => 'channel', 'AttributeType' => 'S'],
                ['AttributeName' => 'connectionId', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'channel', 'KeyType' => 'HASH'],
                ['AttributeName' => 'connectionId', 'KeyType' => 'RANGE'],
            ],
            'GlobalSecondaryIndexes' => [[
                'IndexName' => 'by-connection',
                'KeySchema' => [['AttributeName' => 'connectionId', 'KeyType' => 'HASH']],
                'Projection' => ['ProjectionType' => 'ALL'],
            ]],
        ])->resolve();
    }

    protected function tearDown(): void
    {
        if (isset($this->table)) {
            $this->client->deleteTable(['TableName' => $this->table])->resolve();
        }
    }

    protected function store(): SubscriptionStore
    {
        return new DynamoDbStore($this->client, $this->table);
    }
}
