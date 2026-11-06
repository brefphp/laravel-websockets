<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ApiGatewayBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    public function __construct(
        private readonly Publisher $publisher,
        private readonly ChannelSignature $signature,
    ) {}

    /**
     * @param  Request  $request
     */
    public function auth($request)
    {
        $channel = $request->string('channel_name')->value();
        $name = $this->normalizeChannelName($channel);

        if ($channel === '' || ($this->isGuardedChannel($channel) && ! $this->retrieveUser($request, $name))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $name);
    }

    /**
     * @param  Request  $request
     * @return array{auth: string, channel_data?: string}
     */
    public function validAuthenticationResponse($request, $result)
    {
        $channel = $request->string('channel_name')->value();
        $socketId = $request->string('socket_id')->value();

        if (str_starts_with($channel, 'private')) {
            return ['auth' => $this->signature->sign($socketId, $channel)];
        }

        $user = $this->retrieveUser($request, $this->normalizeChannelName($channel));

        if (! $user instanceof Authenticatable) {
            throw new AccessDeniedHttpException;
        }

        $channelData = json_encode([
            'user_id' => method_exists($user, 'getAuthIdentifierForBroadcasting')
                ? $user->getAuthIdentifierForBroadcasting()
                : $user->getAuthIdentifier(),
            'user_info' => $result,
        ], JSON_THROW_ON_ERROR);

        return [
            'auth' => $this->signature->sign($socketId, $channel, $channelData),
            'channel_data' => $channelData,
        ];
    }

    /**
     * @param  array<int, mixed>  $channels
     * @param  string  $event
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $socket = Arr::pull($payload, 'socket');
        $data = json_encode($payload, JSON_THROW_ON_ERROR);

        foreach ($this->formatChannels($channels) as $channel) {
            if (str_starts_with($channel, 'private-encrypted-')) {
                throw new BroadcastException('Encrypted private channels are not supported.');
            }

            $this->publisher->publish($channel, $event, $data, is_string($socket) ? $socket : null);
        }
    }
}
