<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Store;

/**
 * A connection subscribed to a channel.
 */
readonly class Subscription
{
    /**
     * @param  string|null  $member  Presence channels: the member's `channel_data`, as JSON.
     */
    public function __construct(
        public string $channel,
        public string $connectionId,
        public ?string $member = null,
    ) {}
}
