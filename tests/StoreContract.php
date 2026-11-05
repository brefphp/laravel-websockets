<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test;

use Bref\LaravelWebsockets\Store\Subscription;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use PHPUnit\Framework\Attributes\Test;

/**
 * What every store must do, run on each of them.
 */
trait StoreContract
{
    abstract protected function store(): SubscriptionStore;

    #[Test]
    public function it_stores_subscriptions(): void
    {
        $store = $this->store();
        $store->subscribe('news', 'conn-1');
        $store->subscribe('news', 'conn-2');
        $store->subscribe('sports', 'conn-1');

        $this->assertSame(['conn-1', 'conn-2'], $this->connectionIds($store->subscribers('news')));
        $this->assertSame(['news', 'sports'], $this->channels($store->subscriptionsOf('conn-1')));
        $this->assertTrue($store->isSubscribed('news', 'conn-2'));
        $this->assertFalse($store->isSubscribed('sports', 'conn-2'));
    }

    #[Test]
    public function it_removes_subscriptions(): void
    {
        $store = $this->store();
        $store->subscribe('news', 'conn-1');
        $store->subscribe('news', 'conn-2');

        $store->unsubscribe('news', 'conn-1');

        $this->assertSame(['conn-2'], $this->connectionIds($store->subscribers('news')));
        $this->assertSame([], $store->subscriptionsOf('conn-1'));
    }

    #[Test]
    public function subscribing_twice_keeps_one_subscription_with_the_latest_member(): void
    {
        $store = $this->store();
        $store->subscribe('presence-room', 'conn-1', '{"user_id":1,"user_info":{"name":"A"}}');
        $store->subscribe('presence-room', 'conn-1', '{"user_id":1,"user_info":{"name":"B"}}');

        $subscribers = $store->subscribers('presence-room');

        $this->assertCount(1, $subscribers);
        $this->assertSame('{"user_id":1,"user_info":{"name":"B"}}', $subscribers[0]->member);
    }

    #[Test]
    public function members_are_null_on_channels_without_presence(): void
    {
        $store = $this->store();
        $store->subscribe('news', 'conn-1');

        $this->assertNull($store->subscribers('news')[0]->member);
    }

    #[Test]
    public function channels_and_connections_are_case_sensitive(): void
    {
        $store = $this->store();
        $store->subscribe('private-orders.abc', 'conn-A');
        $store->subscribe('private-orders.abc', 'conn-a');

        $this->assertSame(['conn-A', 'conn-a'], $this->connectionIds($store->subscribers('private-orders.abc')));
        $this->assertSame([], $store->subscribers('private-orders.ABC'));
        $this->assertFalse($store->isSubscribed('private-orders.ABC', 'conn-A'));
    }

    /**
     * @param  list<Subscription>  $subscriptions
     * @return list<string>
     */
    private function connectionIds(array $subscriptions): array
    {
        $ids = array_map(fn(Subscription $subscription) => $subscription->connectionId, $subscriptions);
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<Subscription>  $subscriptions
     * @return list<string>
     */
    private function channels(array $subscriptions): array
    {
        $channels = array_map(fn(Subscription $subscription) => $subscription->channel, $subscriptions);
        sort($channels);

        return $channels;
    }
}
