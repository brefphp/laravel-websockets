<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test;

use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Closure;

/**
 * Runs something else right after the first read, like a concurrent Lambda invocation would.
 */
class InterleavingStore implements SubscriptionStore
{
    private bool $interleaved = false;

    public function __construct(
        private readonly SubscriptionStore $store,
        private readonly Closure $interleave,
    ) {}

    public function subscribe(string $channel, string $connectionId, ?string $member = null): void
    {
        $this->store->subscribe($channel, $connectionId, $member);
    }

    public function unsubscribe(string $channel, string $connectionId): void
    {
        $this->store->unsubscribe($channel, $connectionId);
    }

    public function subscribers(string $channel): array
    {
        $subscribers = $this->store->subscribers($channel);

        if (! $this->interleaved) {
            $this->interleaved = true;
            ($this->interleave)();
        }

        return $subscribers;
    }

    public function subscriptionsOf(string $connectionId): array
    {
        return $this->store->subscriptionsOf($connectionId);
    }
}
