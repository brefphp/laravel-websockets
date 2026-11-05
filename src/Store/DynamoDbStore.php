<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Store;

use AsyncAws\DynamoDb\DynamoDbClient;
use AsyncAws\DynamoDb\Result\QueryOutput;

/**
 * One item per channel and connection, a `by-connection` index, and DynamoDB's TTL on `expiresAt`.
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
            'channel' => ['S' => $channel],
            'connectionId' => ['S' => $connectionId],
            'expiresAt' => ['N' => (string) (time() + self::TTL_SECONDS)],
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
            'Key' => ['channel' => ['S' => $channel], 'connectionId' => ['S' => $connectionId]],
        ])->resolve();
    }

    public function subscribers(string $channel): array
    {
        return $this->subscriptions($this->client->query([
            'TableName' => $this->table,
            // Who listens must include who has just subscribed
            'ConsistentRead' => true,
            'KeyConditionExpression' => '#channel = :channel',
            // DynamoDB deletes expired items up to days later
            'FilterExpression' => 'expiresAt > :now',
            'ExpressionAttributeNames' => ['#channel' => 'channel'],
            'ExpressionAttributeValues' => [':channel' => ['S' => $channel], ':now' => ['N' => (string) time()]],
        ]));
    }

    public function subscriptionsOf(string $connectionId): array
    {
        return $this->subscriptions($this->client->query([
            'TableName' => $this->table,
            'IndexName' => 'by-connection',
            'KeyConditionExpression' => 'connectionId = :id',
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
                (string) $item['channel']->getS(),
                (string) $item['connectionId']->getS(),
                isset($item['member']) ? $item['member']->getS() : null,
            );
        }

        return $subscriptions;
    }
}
