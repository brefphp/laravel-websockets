<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Unit;

use AsyncAws\Lambda\LambdaClient;
use Bref\Context\Context;
use Bref\LaravelWebsockets\ChannelSignature;
use Bref\LaravelWebsockets\Handler;
use Bref\LaravelWebsockets\Publisher;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Bref\LaravelWebsockets\Test\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class HandlerTest extends TestCase
{
    private SubscriptionStore $store;
    private ChannelSignature $signature;

    /** @var list<array{url: string, body: string, headers: list<string>}> */
    private array $lambdaCalls = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(SubscriptionStore::class);
        $this->signature = app(ChannelSignature::class);
    }

    #[Test]
    public function connecting_invokes_the_function_to_send_the_hello(): void
    {
        $response = $this->connect('conn-1');

        $this->assertSame(200, $response['statusCode']);
        $this->assertCount(1, $this->lambdaCalls);
        $this->assertStringContainsString('function%3Aws/invocations', $this->lambdaCalls[0]['url']);
        $this->assertSame('{"establish":"conn-1"}', $this->lambdaCalls[0]['body']);
        $this->assertContains('X-Amz-Invocation-Type: Event', $this->lambdaCalls[0]['headers']);
    }

    #[Test]
    public function the_hello_gives_the_connection_id_as_socket_id(): void
    {
        $this->handler()->handle(['establish' => 'conn-1'], $this->context());

        $hello = $this->connections->sentTo('conn-1')[0];
        $this->assertSame('pusher:connection_established', $hello['event']);
        $this->assertSame('{"socket_id":"conn-1","activity_timeout":540}', $hello['data']);
    }

    #[Test]
    public function the_hello_gives_up_when_the_client_has_left(): void
    {
        $this->connections->gone = ['conn-1'];

        $this->assertNull($this->handler()->handle(['establish' => 'conn-1'], $this->context()));
    }

    #[Test]
    public function origins_can_be_restricted(): void
    {
        $handler = $this->handler(['example.com', '*.example.org']);

        $this->assertSame(200, $this->connect('a', $handler, ['Origin' => 'https://example.com'])['statusCode']);
        $this->assertSame(200, $this->connect('b', $handler, ['origin' => 'https://www.example.org:8080'])['statusCode']);
        $this->assertSame(403, $this->connect('c', $handler, ['Origin' => 'https://evil.com'])['statusCode']);
        $this->assertSame(403, $this->connect('d', $handler)['statusCode']);
        $this->assertCount(2, $this->lambdaCalls);
    }

    #[Test]
    public function it_answers_pings(): void
    {
        $this->message('conn-1', ['event' => 'pusher:ping', 'data' => []]);

        $this->assertSame('pusher:pong', $this->connections->sentTo('conn-1')[0]['event']);
    }

    #[Test]
    public function it_ignores_invalid_messages(): void
    {
        $this->assertSame(200, $this->rawMessage('conn-1', 'not json')['statusCode']);
        $this->assertSame(200, $this->rawMessage('conn-1', '{"event":123}')['statusCode']);
        $this->assertSame(200, $this->message('conn-1', ['event' => 'unknown'])['statusCode']);

        $this->assertSame([], $this->connections->sent);
    }

    #[Test]
    public function public_channels_need_no_signature(): void
    {
        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => ['channel' => 'news.1']]);

        $this->assertTrue($this->store->isSubscribed('news.1', 'conn-1'));
        $this->assertSame([
            'event' => 'pusher_internal:subscription_succeeded',
            'channel' => 'news.1',
            'data' => '{}',
        ], $this->connections->sentTo('conn-1')[0]);
    }

    #[Test]
    public function invalid_channel_names_are_refused(): void
    {
        foreach (['', 'with space', str_repeat('a', 165), "a\nb"] as $channel) {
            $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel]]);
        }

        $this->assertSame([], $this->store->subscriptionsOf('conn-1'));
        $this->assertCount(4, $this->connections->sentTo('conn-1'));
    }

    #[Test]
    public function private_channels_need_a_signature_for_this_connection(): void
    {
        $attempts = [
            null,
            'bref:deadbeef',
            // Signed for another connection, or another channel
            $this->signature->sign('conn-2', 'private-orders.1'),
            $this->signature->sign('conn-1', 'private-orders.2'),
        ];

        foreach ($attempts as $auth) {
            $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => array_filter(['channel' => 'private-orders.1', 'auth' => $auth])]);
        }

        $this->assertFalse($this->store->isSubscribed('private-orders.1', 'conn-1'));
        foreach ($this->connections->sentTo('conn-1') as $sent) {
            $this->assertSame('pusher:error', $sent['event']);
        }

        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => [
            'channel' => 'private-orders.1',
            'auth' => $this->signature->sign('conn-1', 'private-orders.1'),
        ]]);

        $this->assertTrue($this->store->isSubscribed('private-orders.1', 'conn-1'));
    }

    #[Test]
    public function presence_data_cannot_be_forged(): void
    {
        $signed = '{"user_id":1,"user_info":{"name":"Alice"}}';

        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => [
            'channel' => 'presence-room.1',
            'auth' => $this->signature->sign('conn-1', 'presence-room.1', $signed),
            'channel_data' => '{"user_id":2,"user_info":{"name":"Bob"}}',
        ]]);

        $this->assertFalse($this->store->isSubscribed('presence-room.1', 'conn-1'));
    }

    #[Test]
    public function presence_tells_who_is_here_and_who_joins(): void
    {
        $this->joinPresence('conn-1', '{"user_id":1,"user_info":{"name":"Alice"}}');
        $this->joinPresence('conn-2', '{"user_id":2,"user_info":{"name":"Bob"}}');

        $here = $this->connections->sentTo('conn-2')[0];
        $this->assertSame('pusher_internal:subscription_succeeded', $here['event']);
        $this->assertSame(
            '{"presence":{"ids":["1","2"],"hash":{"1":{"name":"Alice"},"2":{"name":"Bob"}},"count":2}}',
            $here['data'],
        );

        $joined = $this->connections->sentTo('conn-1')[1];
        $this->assertSame('pusher_internal:member_added', $joined['event']);
        $this->assertSame('{"user_id":2,"user_info":{"name":"Bob"}}', $joined['data']);
    }

    #[Test]
    public function a_second_connection_of_the_same_user_is_not_a_new_member(): void
    {
        $this->joinPresence('tab-1', '{"user_id":1,"user_info":null}');
        $this->joinPresence('other', '{"user_id":2,"user_info":null}');
        $this->joinPresence('tab-2', '{"user_id":1,"user_info":null}');

        $this->assertCount(1, $this->eventsOf('other', 'pusher_internal:subscription_succeeded'));
        $this->assertSame([], $this->eventsOf('other', 'pusher_internal:member_added'));

        $this->disconnect('tab-1');
        $this->assertSame([], $this->eventsOf('other', 'pusher_internal:member_removed'));

        $this->disconnect('tab-2');
        $removed = $this->eventsOf('other', 'pusher_internal:member_removed');
        $this->assertCount(1, $removed);
        $this->assertSame('{"user_id":"1"}', $removed[0]['data']);
    }

    #[Test]
    public function disconnecting_removes_every_subscription(): void
    {
        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => ['channel' => 'news.1']]);
        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => ['channel' => 'news.2']]);

        $this->disconnect('conn-1');

        $this->assertSame([], $this->store->subscriptionsOf('conn-1'));
    }

    #[Test]
    public function unsubscribing_leaves_only_that_channel(): void
    {
        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => ['channel' => 'news.1']]);
        $this->message('conn-1', ['event' => 'pusher:subscribe', 'data' => ['channel' => 'news.2']]);

        $this->message('conn-1', ['event' => 'pusher:unsubscribe', 'data' => ['channel' => 'news.1']]);

        $this->assertFalse($this->store->isSubscribed('news.1', 'conn-1'));
        $this->assertTrue($this->store->isSubscribed('news.2', 'conn-1'));
    }

    #[Test]
    public function whispers_reach_the_other_members_only(): void
    {
        $this->store->subscribe('private-chat', 'sender');
        $this->store->subscribe('private-chat', 'other');

        $this->message('sender', ['event' => 'client-typing', 'channel' => 'private-chat', 'data' => ['name' => 'Alice']]);

        $this->assertSame([], $this->connections->sentTo('sender'));
        $this->assertSame([
            'event' => 'client-typing',
            'channel' => 'private-chat',
            'data' => ['name' => 'Alice'],
        ], $this->connections->sentTo('other')[0]);
    }

    #[Test]
    public function whispers_are_refused_for_non_members_and_public_channels(): void
    {
        $this->store->subscribe('private-chat', 'member');
        $this->store->subscribe('news', 'reader');
        $this->store->subscribe('news', 'other');

        $this->message('outsider', ['event' => 'client-typing', 'channel' => 'private-chat', 'data' => []]);
        $this->message('reader', ['event' => 'client-typing', 'channel' => 'news', 'data' => []]);

        $this->assertSame([], $this->connections->sentTo('member'));
        $this->assertSame([], $this->connections->sentTo('other'));
        $this->assertSame('pusher:error', $this->connections->sentTo('outsider')[0]['event']);
        $this->assertSame('pusher:error', $this->connections->sentTo('reader')[0]['event']);
    }

    private function joinPresence(string $connectionId, string $channelData): void
    {
        $this->message($connectionId, ['event' => 'pusher:subscribe', 'data' => [
            'channel' => 'presence-room.1',
            'auth' => $this->signature->sign($connectionId, 'presence-room.1', $channelData),
            'channel_data' => $channelData,
        ]]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventsOf(string $connectionId, string $event): array
    {
        return array_values(array_filter(
            $this->connections->sentTo($connectionId),
            fn(array $message) => $message['event'] === $event,
        ));
    }

    /**
     * @param  list<string>  $allowedOrigins
     */
    private function handler(array $allowedOrigins = ['*']): Handler
    {
        $lambda = new LambdaClient(
            ['region' => 'eu-west-3', 'accessKeyId' => 'test', 'accessKeySecret' => 'test'],
            null,
            new MockHttpClient(function (string $method, string $url, array $options) {
                $this->lambdaCalls[] = ['url' => $url, 'body' => $options['body'], 'headers' => $options['normalized_headers']['x-amz-invocation-type'] ?? []];

                return new MockResponse('', ['http_code' => 202]);
            }),
        );

        return new Handler($this->store, $this->connections, app(Publisher::class), $this->signature, $lambda, $allowedOrigins);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function connect(string $connectionId, ?Handler $handler = null, array $headers = []): array
    {
        /** @var array<string, mixed> */
        return ($handler ?? $this->handler())->handle($this->event($connectionId, 'CONNECT', '$connect') + ['headers' => $headers], $this->context());
    }

    private function disconnect(string $connectionId): void
    {
        $this->handler()->handle($this->event($connectionId, 'DISCONNECT', '$disconnect'), $this->context());
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function message(string $connectionId, array $message): array
    {
        return $this->rawMessage($connectionId, json_encode($message, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function rawMessage(string $connectionId, string $body): array
    {
        /** @var array<string, mixed> */
        return $this->handler()->handle($this->event($connectionId, 'MESSAGE', '$default') + ['body' => $body], $this->context());
    }

    /**
     * @return array<string, mixed>
     */
    private function event(string $connectionId, string $type, string $route): array
    {
        return ['requestContext' => [
            'routeKey' => $route,
            'eventType' => $type,
            'connectionId' => $connectionId,
            'domainName' => 'abc123.execute-api.eu-west-3.amazonaws.com',
            'apiId' => 'abc123',
            'stage' => 'prod',
        ]];
    }

    private function context(): Context
    {
        return new Context('request', 0, 'arn:aws:lambda:eu-west-3:123456789012:function:ws', 'trace');
    }
}
