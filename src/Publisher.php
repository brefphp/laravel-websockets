<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use Bref\LaravelWebsockets\Store\Subscription;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Illuminate\Broadcasting\BroadcastException;

/**
 * Sends a Pusher protocol event to the subscribers of a channel.
 */
class Publisher
{
    // API Gateway refuses larger messages
    private const MAX_MESSAGE_BYTES = 128 * 1024;

    public function __construct(
        private readonly SubscriptionStore $store,
        private readonly Connections $connections,
    ) {}

    public function publish(string $channel, string $event, mixed $data, ?string $exceptConnectionId = null): void
    {
        $this->publishTo($this->store->subscribers($channel), $channel, $event, $data, $exceptConnectionId);
    }

    /**
     * @param  list<Subscription>  $subscribers
     */
    public function publishTo(array $subscribers, string $channel, string $event, mixed $data, ?string $exceptConnectionId = null): void
    {
        $connectionIds = [];

        foreach ($subscribers as $subscriber) {
            if ($subscriber->connectionId !== $exceptConnectionId) {
                $connectionIds[] = $subscriber->connectionId;
            }
        }

        // The data of an event is a JSON string inside this JSON: its quotes are escaped, and count twice
        $message = json_encode(['event' => $event, 'channel' => $channel, 'data' => $data], JSON_THROW_ON_ERROR);

        if (strlen($message) > self::MAX_MESSAGE_BYTES) {
            throw new BroadcastException(sprintf('The "%s" event is too large for API Gateway: %d bytes.', $event, strlen($message)));
        }

        // $disconnect is not guaranteed: this is what removes the connections it missed
        foreach ($this->connections->send($connectionIds, $message) as $goneConnectionId) {
            $this->store->unsubscribe($channel, $goneConnectionId);
        }
    }
}
