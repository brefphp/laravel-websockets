<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

/**
 * Proves that the application authorized a connection to join a private or presence channel.
 *
 * Same format as Pusher: "key:hmac(socket_id:channel[:channel_data])". Echo does not read it, it
 * only hands what /broadcasting/auth returned over to the WebSocket handler.
 */
class ChannelSignature
{
    public function __construct(
        private readonly string $appKey,
    ) {}

    public function sign(string $socketId, string $channel, ?string $channelData = null): string
    {
        $signed = $socketId . ':' . $channel . ($channelData !== null ? ':' . $channelData : '');

        return 'bref:' . hash_hmac('sha256', $signed, $this->secret());
    }

    public function verify(string $signature, string $socketId, string $channel, ?string $channelData = null): bool
    {
        return hash_equals($this->sign($socketId, $channel, $channelData), $signature);
    }

    /**
     * Do not use APP_KEY directly for something else than encryption.
     */
    private function secret(): string
    {
        return hash_hmac('sha256', 'bref-websockets-channel-signature', $this->appKey);
    }
}
