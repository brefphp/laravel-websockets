<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Store;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Result\QueryOutput;

/**
 * The table of Lift's `database/dynamodb-single-table` construct, which imposes the attribute names:
 * one item per channel (`PK`) and connection (`SK`), an index by connection (`GSI-1`), and DynamoDB's TTL.
 */
class DynamoDbStore implements SubscriptionStore
{
    public function __construct(
        private readonly DynamoDbClient $client,
        private readonly string $table,
    ) {}

    public function subscribe(string $channel, string $connectionId, ?string $member = null): void
    {
        $item = [
            'PK' => ['S' => $channel],
            'SK' => ['S' => $connectionId],
            'GSI-1-PK' => ['S' => $connectionId],
            'GSI-1-SK' => ['S' => $channel],
            'TimeToLive' => ['N' => (string) (time() + self::TTL_SECONDS)],
        ];

        if ($member !== null) {
            $item['member'] = ['S' => $member];
        }

        $this->client->putItem(['TableName' => $this->table, 'Item' => $item])->resolve();
    }

    public function unsubscribe(string $channel, string $connectionId): void
    {
        $this->client->deleteItem([
            'TableName' => $this->table,
            'Key' => ['PK' => ['S' => $channel], 'SK' => ['S' => $connectionId]],
        ])->resolve();
    }

    public function subscribers(string $channel): array
    {
        return $this->subscriptions($this->client->query([
            'TableName' => $this->table,
            // Who listens must include who has just subscribed
            'ConsistentRead' => true,
            'KeyConditionExpression' => 'PK = :channel',
            // DynamoDB deletes expired items up to days later
            'FilterExpression' => 'TimeToLive > :now',
            'ExpressionAttributeValues' => [':channel' => ['S' => $channel], ':now' => ['N' => (string) time()]],
        ]));
    }

    public function subscriptionsOf(string $connectionId): array
    {
        return $this->subscriptions($this->client->query([
            'TableName' => $this->table,
            'IndexName' => 'GSI-1',
            'KeyConditionExpression' => '#connection = :id',
            'ExpressionAttributeNames' => ['#connection' => 'GSI-1-PK'],
            'ExpressionAttributeValues' => [':id' => ['S' => $connectionId]],
        ]));
    }

    /**
     * @return list<Subscription>
     */
    private function subscriptions(QueryOutput $result): array
    {
        $subscriptions = [];

        // getItems() goes through every result page
        foreach ($result->getItems() as $item) {
            $subscriptions[] = new Subscription(
                (string) $item['PK']->getS(),
                (string) $item['SK']->getS(),
                isset($item['member']) ? $item['member']->getS() : null,
            );
        }

        return $subscriptions;
    }
}
