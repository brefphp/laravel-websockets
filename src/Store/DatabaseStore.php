<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Store;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

class DatabaseStore implements SubscriptionStore
{
    /** Chances that a subscription purges expired rows, like Laravel's database session driver. */
    private const PRUNE_LOTTERY = [2, 100];

    public function __construct(
        private readonly ConnectionInterface $connection,
    ) {}

    public function subscribe(string $channel, string $connectionId, ?string $member = null): void
    {
        $this->query()->upsert(
            [[
                'channel' => $channel,
                'connection_id' => $connectionId,
                'member' => $member,
                'expires_at' => time() + self::TTL_SECONDS,
            ]],
            ['channel', 'connection_id'],
            ['member', 'expires_at'],
        );

        if (random_int(1, self::PRUNE_LOTTERY[1]) <= self::PRUNE_LOTTERY[0]) {
            $this->prune();
        }
    }

    public function unsubscribe(string $channel, string $connectionId): void
    {
        $this->query()->where('channel', $channel)->where('connection_id', $connectionId)->delete();
    }

    public function subscribers(string $channel): array
    {
        return $this->subscriptions($this->query()->where('channel', $channel)->where('expires_at', '>', time()));
    }

    public function subscriptionsOf(string $connectionId): array
    {
        return $this->subscriptions($this->query()->where('connection_id', $connectionId));
    }

    public function prune(): void
    {
        $this->query()->where('expires_at', '<=', time())->delete();
    }

    /**
     * @return list<Subscription>
     */
    private function subscriptions(Builder $query): array
    {
        $subscriptions = [];

        /** @var object{channel: string, connection_id: string, member: ?string} $row */
        foreach ($query->get(['channel', 'connection_id', 'member']) as $row) {
            $subscriptions[] = new Subscription($row->channel, $row->connection_id, $row->member);
        }

        return $subscriptions;
    }

    private function query(): Builder
    {
        return $this->connection->table('websocket_subscriptions');
    }
}
