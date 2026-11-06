<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use AsyncAws\Lambda\LambdaClient;
use Bref\Context\Context;
use Bref\Event\ApiGateway\WebsocketEvent;
use Bref\Event\ApiGateway\WebsocketHandler;
use Bref\Event\Http\HttpResponse;
use Bref\LaravelWebsockets\Store\Subscription;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The server side of the Pusher protocol on API Gateway WebSockets, what Echo and pusher-js talk to.
 *
 * @see https://pusher.com/docs/channels/library_auth_reference/pusher-websockets-protocol/
 */
class Handler extends WebsocketHandler
{
    // API Gateway closes connections after 10 idle minutes: pusher-js must ping before that
    private const ACTIVITY_TIMEOUT_SECONDS = 540;
    private const ESTABLISH_ATTEMPTS = 50;
    private const ESTABLISH_RETRY_MICROSECONDS = 20_000;

    /**
     * @param  list<string>  $allowedOrigins
     */
    public function __construct(
        private readonly SubscriptionStore $store,
        private readonly Connections $connections,
        private readonly Publisher $publisher,
        private readonly ChannelSignature $signature,
        private readonly LambdaClient $lambda,
        private readonly array $allowedOrigins,
    ) {}

    public function handle($event, Context $context)
    {
        if (is_array($event) && is_string($event['establish'] ?? null)) {
            $this->establish($event['establish']);

            return null;
        }

        return parent::handle($event, $context);
    }

    public function handleWebsocket(WebsocketEvent $event, Context $context): HttpResponse
    {
        $connectionId = $event->getConnectionId();

        switch ($event->getEventType()) {
            case 'CONNECT':
                if (! $this->isOriginAllowed($event)) {
                    return new HttpResponse('Origin not allowed', [], 403);
                }

                $this->onConnect($connectionId, $context);
                break;
            case 'DISCONNECT':
                foreach ($this->store->subscriptionsOf($connectionId) as $subscription) {
                    $this->leave($subscription);
                }

                break;
            default:
                $body = $event->getBody();
                $this->onMessage($connectionId, is_string($body) ? $body : '');
        }

        return new HttpResponse('');
    }

    /**
     * API Gateway refuses to send messages until $connect has returned, and pusher-js does nothing
     * until it receives pusher:connection_established. So the hello comes from a second invocation.
     */
    private function onConnect(string $connectionId, Context $context): void
    {
        $this->lambda->invoke([
            'FunctionName' => $context->getInvokedFunctionArn(),
            'InvocationType' => 'Event',
            'Payload' => json_encode(['establish' => $connectionId], JSON_THROW_ON_ERROR),
        ])->resolve();
    }

    private function establish(string $connectionId): void
    {
        $message = json_encode([
            'event' => 'pusher:connection_established',
            'data' => json_encode(['socket_id' => $connectionId, 'activity_timeout' => self::ACTIVITY_TIMEOUT_SECONDS], JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR);

        for ($attempt = 1; $attempt <= self::ESTABLISH_ATTEMPTS; $attempt++) {
            // Gone until $connect has returned, or forever if the client has already left
            if ($this->connections->send([$connectionId], $message) === []) {
                return;
            }

            usleep(self::ESTABLISH_RETRY_MICROSECONDS);
        }
    }

    private function onMessage(string $connectionId, string $body): void
    {
        $message = json_decode($body, true);

        if (! is_array($message) || ! is_string($message['event'] ?? null)) {
            return;
        }

        $event = $message['event'];
        $data = $message['data'] ?? [];

        if ($event === 'pusher:ping') {
            $this->connections->send([$connectionId], '{"event":"pusher:pong","data":"{}"}');
        } elseif ($event === 'pusher:subscribe') {
            $this->subscribe($connectionId, is_array($data) ? $data : []);
        } elseif ($event === 'pusher:unsubscribe') {
            $this->unsubscribe($connectionId, is_array($data) && is_string($data['channel'] ?? null) ? $data['channel'] : '');
        } elseif (str_starts_with($event, 'client-')) {
            $this->clientEvent($connectionId, $event, is_string($message['channel'] ?? null) ? $message['channel'] : '', $data);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function subscribe(string $connectionId, array $data): void
    {
        $channel = is_string($data['channel'] ?? null) ? $data['channel'] : '';
        $channelData = is_string($data['channel_data'] ?? null) ? $data['channel_data'] : null;
        $auth = is_string($data['auth'] ?? null) ? $data['auth'] : '';

        // Pusher's rule for channel names, and the size of the database column
        if (preg_match('/\A[-a-zA-Z0-9_=@,.;]{1,164}\z/', $channel) !== 1) {
            $this->sendError($connectionId, 4009, 'Invalid channel name');

            return;
        }

        if ($this->isGuarded($channel) && ! $this->signature->verify($auth, $connectionId, $channel, $channelData)) {
            $this->sendError($connectionId, 4009, 'Connection is unauthorized');

            return;
        }

        if (! str_starts_with($channel, 'presence-')) {
            $this->store->subscribe($channel, $connectionId);
            $this->sendSubscriptionSucceeded($connectionId, $channel, '{}');

            return;
        }

        $this->joinPresenceChannel($connectionId, $channel, (string) $channelData);
    }

    private function joinPresenceChannel(string $connectionId, string $channel, string $channelData): void
    {
        // Write before reading: of two members joining at the same time, at least one sees the other and tells it
        $this->store->subscribe($channel, $connectionId, $channelData);

        $others = array_values(array_filter(
            $this->store->subscribers($channel),
            fn(Subscription $subscription) => $subscription->connectionId !== $connectionId,
        ));

        $members = $this->members($others);
        $me = $this->decodeMember($channelData);
        $isNewMember = ! array_key_exists($me['id'], $members);
        $members[$me['id']] = $me['info'];

        $this->sendSubscriptionSucceeded($connectionId, $channel, json_encode(['presence' => [
            'ids' => array_map(strval(...), array_keys($members)),
            'hash' => (object) $members,
            'count' => count($members),
        ]], JSON_THROW_ON_ERROR));

        // A user can have several connections, e.g. tabs
        if ($isNewMember) {
            $this->publisher->publishTo($others, $channel, 'pusher_internal:member_added', $channelData);
        }
    }

    private function unsubscribe(string $connectionId, string $channel): void
    {
        foreach ($this->store->subscriptionsOf($connectionId) as $subscription) {
            if ($subscription->channel === $channel) {
                $this->leave($subscription);
            }
        }
    }

    private function leave(Subscription $subscription): void
    {
        $this->store->unsubscribe($subscription->channel, $subscription->connectionId);

        if ($subscription->member === null) {
            return;
        }

        $remaining = $this->store->subscribers($subscription->channel);
        $me = $this->decodeMember($subscription->member);

        if (! array_key_exists($me['id'], $this->members($remaining))) {
            $this->publisher->publishTo(
                $remaining,
                $subscription->channel,
                'pusher_internal:member_removed',
                json_encode(['user_id' => $me['id']], JSON_THROW_ON_ERROR),
            );
        }
    }

    /**
     * Whispers: like Pusher, only for private and presence channels the sender has joined.
     */
    private function clientEvent(string $connectionId, string $event, string $channel, mixed $data): void
    {
        $subscribers = $this->isGuarded($channel) ? $this->store->subscribers($channel) : [];

        if (! in_array($connectionId, array_column($subscribers, 'connectionId'), true)) {
            $this->sendError($connectionId, 4301, 'Client events require joining a private or presence channel');

            return;
        }

        $this->publisher->publishTo($subscribers, $channel, $event, $data, $connectionId);
    }

    private function isOriginAllowed(WebsocketEvent $event): bool
    {
        if (in_array('*', $this->allowedOrigins, true)) {
            return true;
        }

        $headers = $event->toArray()['headers'] ?? [];
        $origin = is_array($headers) ? (array_change_key_case($headers)['origin'] ?? null) : null;
        $host = is_string($origin) ? parse_url($origin, PHP_URL_HOST) : null;

        if (! is_string($host)) {
            Log::info('WebSocket connection refused: no Origin header');

            return false;
        }

        return Str::is($this->allowedOrigins, $host);
    }

    private function isGuarded(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    /**
     * @param  list<Subscription>  $subscriptions
     * @return array<string, mixed>
     */
    private function members(array $subscriptions): array
    {
        $members = [];

        foreach ($subscriptions as $subscription) {
            if ($subscription->member !== null) {
                $member = $this->decodeMember($subscription->member);
                $members[$member['id']] = $member['info'];
            }
        }

        return $members;
    }

    /**
     * @return array{id: string, info: mixed}
     */
    private function decodeMember(string $member): array
    {
        $decoded = json_decode($member, true);
        $id = is_array($decoded) ? ($decoded['user_id'] ?? '') : '';

        return [
            'id' => is_scalar($id) ? (string) $id : '',
            'info' => is_array($decoded) ? ($decoded['user_info'] ?? null) : null,
        ];
    }

    private function sendSubscriptionSucceeded(string $connectionId, string $channel, string $data): void
    {
        $this->connections->send([$connectionId], json_encode([
            'event' => 'pusher_internal:subscription_succeeded',
            'channel' => $channel,
            'data' => $data,
        ], JSON_THROW_ON_ERROR));
    }

    private function sendError(string $connectionId, int $code, string $message): void
    {
        $this->connections->send([$connectionId], json_encode([
            'event' => 'pusher:error',
            'data' => ['code' => $code, 'message' => $message],
        ], JSON_THROW_ON_ERROR));
    }
}
