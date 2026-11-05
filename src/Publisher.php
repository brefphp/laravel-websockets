<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use Bref\LaravelWebsockets\Store\Subscription;
use Bref\LaravelWebsockets\Store\SubscriptionStore;

/**
 * Sends a Pusher protocol event to the subscribers of a channel.
 */
class Publisher
{
    public function __construct(
        private readonly SubscriptionStore $store,
        private readonly Connections $connections,
    ) {}

    public function publish(string $channel, string $event, mixed $data, ?string $exceptConnectionId = null): SendResult
    {
        return $this->publishTo($this->store->subscribers($channel), $channel, $event, $data, $exceptConnectionId);
    }

    /**
     * @param  list<Subscription>  $subscribers
     */
    public function publishTo(array $subscribers, string $channel, string $event, mixed $data, ?string $exceptConnectionId = null): SendResult
    {
        $connectionIds = [];

        foreach ($subscribers as $subscriber) {
            if ($subscriber->connectionId !== $exceptConnectionId) {
                $connectionIds[] = $subscriber->connectionId;
            }
        }

        $message = json_encode(['event' => $event, 'channel' => $channel, 'data' => $data], JSON_THROW_ON_ERROR);

        $result = $this->connections->send($connectionIds, $message);

        // $disconnect is not guaranteed: this is what removes the connections it missed
        foreach ($result->gone as $connectionId) {
            $this->store->unsubscribe($channel, $connectionId);
        }

        return $result;
    }
}
