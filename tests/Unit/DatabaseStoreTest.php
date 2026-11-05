<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Unit;

use Bref\LaravelWebsockets\Store\DatabaseStore;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Bref\LaravelWebsockets\Test\StoreContract;
use Bref\LaravelWebsockets\Test\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

class DatabaseStoreTest extends TestCase
{
    use StoreContract;

    #[Test]
    public function expired_subscriptions_are_ignored_and_pruned(): void
    {
        $store = new DatabaseStore(DB::connection(), 'websocket_subscriptions');
        $store->subscribe('news', 'conn-1');
        DB::table('websocket_subscriptions')->update(['expires_at' => time() - 1]);
        $store->subscribe('news', 'conn-2');

        $this->assertSame('conn-2', $store->subscribers('news')[0]->connectionId);
        $this->assertCount(1, $store->subscribers('news'));
        $this->assertFalse($store->isSubscribed('news', 'conn-1'));

        $store->prune();

        $this->assertSame(1, DB::table('websocket_subscriptions')->count());
    }

    protected function store(): SubscriptionStore
    {
        return app(SubscriptionStore::class);
    }
}
