<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Store;

interface SubscriptionStore
{
    // API Gateway closes every connection after 2 hours
    public const TTL_SECONDS = 7260;

    public function subscribe(string $channel, string $connectionId, ?string $member = null): void;

    public function unsubscribe(string $channel, string $connectionId): void;

    public function isSubscribed(string $channel, string $connectionId): bool;

    /**
     * @return list<Subscription>
     */
    public function subscribers(string $channel): array;

    /**
     * @return list<Subscription>
     */
    public function subscriptionsOf(string $connectionId): array;
}
