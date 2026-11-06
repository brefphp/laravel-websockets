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
        // Same keys and index as Lift's `database/dynamodb-single-table` construct with `gsiCount: 1`
        $this->client->createTable([
            'TableName' => $this->table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [
                ['AttributeName' => 'PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'SK', 'AttributeType' => 'S'],
                ['AttributeName' => 'GSI-1-PK', 'AttributeType' => 'S'],
                ['AttributeName' => 'GSI-1-SK', 'AttributeType' => 'S'],
            ],
            'KeySchema' => [
                ['AttributeName' => 'PK', 'KeyType' => 'HASH'],
                ['AttributeName' => 'SK', 'KeyType' => 'RANGE'],
            ],
            'GlobalSecondaryIndexes' => [[
                'IndexName' => 'GSI-1',
                'KeySchema' => [
                    ['AttributeName' => 'GSI-1-PK', 'KeyType' => 'HASH'],
                    ['AttributeName' => 'GSI-1-SK', 'KeyType' => 'RANGE'],
                ],
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
