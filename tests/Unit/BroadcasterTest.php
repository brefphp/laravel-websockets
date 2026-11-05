<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Unit;

use Bref\LaravelWebsockets\ApiGatewayBroadcaster;
use Bref\LaravelWebsockets\ChannelSignature;
use Bref\LaravelWebsockets\Store\SubscriptionStore;
use Bref\LaravelWebsockets\Test\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BroadcasterTest extends TestCase
{
    private SubscriptionStore $store;
    private ApiGatewayBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = app(SubscriptionStore::class);
        $broadcaster = Broadcast::connection('api-gateway');
        $this->assertInstanceOf(ApiGatewayBroadcaster::class, $broadcaster);
        $this->broadcaster = $broadcaster;
    }

    #[Test]
    public function it_sends_events_in_the_pusher_format(): void
    {
        $this->store->subscribe('news', 'conn-1');
        $this->store->subscribe('private-orders.1', 'conn-2');

        $this->broadcaster->broadcast([new Channel('news'), new PrivateChannel('orders.1')], 'shipped', ['id' => 1]);

        $this->assertSame([['event' => 'shipped', 'channel' => 'news', 'data' => '{"id":1}']], $this->connections->sentTo('conn-1'));
        $this->assertSame([['event' => 'shipped', 'channel' => 'private-orders.1', 'data' => '{"id":1}']], $this->connections->sentTo('conn-2'));
    }

    #[Test]
    public function only_to_others_excludes_the_sender(): void
    {
        $this->store->subscribe('news', 'sender');
        $this->store->subscribe('news', 'other');

        $this->broadcaster->broadcast([new Channel('news')], 'shipped', ['id' => 1, 'socket' => 'sender']);

        $this->assertSame([], $this->connections->sentTo('sender'));
        $this->assertSame('{"id":1}', $this->connections->sentTo('other')[0]['data']);
    }

    #[Test]
    public function connections_that_are_gone_are_removed(): void
    {
        $this->store->subscribe('news', 'alive');
        $this->store->subscribe('news', 'dead');
        $this->connections->gone = ['dead'];

        $this->broadcaster->broadcast([new Channel('news')], 'shipped');

        $this->assertNotContains('dead', $this->subscribersOf('news'));
        $this->assertContains('alive', $this->subscribersOf('news'));
    }

    #[Test]
    public function it_fails_when_no_connection_can_be_reached(): void
    {
        $this->store->subscribe('news', 'conn-1');
        $this->connections->failing = ['conn-1'];

        $this->expectException(BroadcastException::class);

        $this->broadcaster->broadcast([new Channel('news')], 'shipped');
    }

    #[Test]
    public function it_succeeds_when_some_connections_can_be_reached(): void
    {
        $this->store->subscribe('news', 'conn-1');
        $this->store->subscribe('news', 'conn-2');
        $this->connections->failing = ['conn-1'];

        $this->broadcaster->broadcast([new Channel('news')], 'shipped');

        $this->assertCount(1, $this->connections->sentTo('conn-2'));
    }

    #[Test]
    public function it_succeeds_without_subscribers(): void
    {
        $this->broadcaster->broadcast([new Channel('news')], 'shipped');

        $this->assertSame([], $this->connections->sent);
    }

    #[Test]
    public function it_refuses_events_too_large_for_api_gateway(): void
    {
        $this->store->subscribe('news', 'conn-1');

        $this->expectException(BroadcastException::class);
        $this->expectExceptionMessage('too large');

        $this->broadcaster->broadcast([new Channel('news')], 'shipped', ['text' => str_repeat('x', 128 * 1024)]);
    }

    #[Test]
    public function it_refuses_encrypted_channels(): void
    {
        $this->expectException(BroadcastException::class);

        $this->broadcaster->broadcast([new Channel('private-encrypted-orders')], 'shipped');
    }

    #[Test]
    public function it_signs_private_channels_for_authorized_users(): void
    {
        $this->broadcaster->channel('orders.{id}', fn(GenericUser $user, string $id) => $user->getAuthIdentifier() === (int) $id);

        $response = $this->authResponse('private-orders.1');

        $signature = app(ChannelSignature::class);
        $this->assertTrue($signature->verify($response['auth'], 'socket-1', 'private-orders.1'));
    }

    #[Test]
    public function it_refuses_unauthorized_users(): void
    {
        $this->broadcaster->channel('orders.{id}', fn(GenericUser $user, string $id) => $user->getAuthIdentifier() === (int) $id);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest('private-orders.2'));
    }

    #[Test]
    public function it_refuses_guests_on_private_channels(): void
    {
        $this->broadcaster->channel('orders.{id}', fn() => true);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth(Request::create('/broadcasting/auth', 'POST', ['channel_name' => 'private-orders.1', 'socket_id' => 'socket-1']));
    }

    #[Test]
    public function it_signs_presence_channels_with_the_member(): void
    {
        $this->broadcaster->channel('room.{id}', fn(GenericUser $user) => ['name' => 'Alice']);

        $response = $this->authResponse('presence-room.1');

        $channelData = $response['channel_data'] ?? null;
        $this->assertSame('{"user_id":1,"user_info":{"name":"Alice"}}', $channelData);
        $signature = app(ChannelSignature::class);
        $this->assertTrue($signature->verify($response['auth'], 'socket-1', 'presence-room.1', $channelData));
    }

    /**
     * @return array{auth: string, channel_data?: string}
     */
    private function authResponse(string $channel): array
    {
        /** @var array{auth: string, channel_data?: string} */
        return $this->broadcaster->auth($this->authRequest($channel));
    }

    private function authRequest(string $channel): Request
    {
        $request = Request::create('/broadcasting/auth', 'POST', ['channel_name' => $channel, 'socket_id' => 'socket-1']);
        $request->setUserResolver(fn() => new GenericUser(['id' => 1]));

        return $request;
    }
}
