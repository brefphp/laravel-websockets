<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Unit;

use Bref\LaravelWebsockets\Connections;
use Bref\LaravelWebsockets\Test\TestCase;
use Illuminate\Broadcasting\BroadcastException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectionsTest extends TestCase
{
    #[Test]
    public function it_posts_to_each_connection(): void
    {
        $urls = [];
        $connections = $this->connectionsWith(function (string $method, string $url, array $options) use (&$urls) {
            $urls[] = $method . ' ' . $url;
            $this->assertSame('hello', $options['body']);

            return new MockResponse('', ['http_code' => 200]);
        });

        $gone = $connections->send(['L0SM9cOFvHcCIhw=', 'conn-2'], 'hello');

        $this->assertSame([], $gone);
        $this->assertSame([
            'POST https://abc123.execute-api.eu-west-3.amazonaws.com/prod/@connections/L0SM9cOFvHcCIhw%3D',
            'POST https://abc123.execute-api.eu-west-3.amazonaws.com/prod/@connections/conn-2',
        ], $urls);
    }

    #[Test]
    public function it_reports_gone_connections_and_goes_on_after_failures(): void
    {
        $connections = $this->connectionsWith(fn(string $method, string $url) => match (true) {
            str_contains($url, 'gone') => new MockResponse('{"message":"Gone"}', ['http_code' => 410]),
            str_contains($url, 'denied') => new MockResponse('{"message":"Forbidden"}', ['http_code' => 403]),
            default => new MockResponse('', ['http_code' => 200]),
        });

        $this->assertSame(['gone'], $connections->send(['gone', 'denied', 'ok'], 'hello'));
    }

    #[Test]
    public function it_handles_more_connections_than_it_sends_at_a_time(): void
    {
        $count = 0;
        $connections = $this->connectionsWith(function () use (&$count) {
            $count++;

            return new MockResponse('', ['http_code' => 200]);
        });

        $connections->send(array_map(fn(int $i) => "conn-{$i}", range(1, 120)), 'hello');

        $this->assertSame(120, $count);
    }

    #[Test]
    public function it_fails_when_no_connection_can_be_reached(): void
    {
        $connections = $this->connectionsWith(fn() => new MockResponse('{"message":"Forbidden"}', ['http_code' => 403]));

        $this->expectException(BroadcastException::class);

        $connections->send(['conn-1', 'conn-2'], 'hello');
    }

    private function connectionsWith(callable $responses): Connections
    {
        return new Connections(
            'prod',
            [
                'region' => 'eu-west-3',
                'endpoint' => 'https://abc123.execute-api.eu-west-3.amazonaws.com',
                'accessKeyId' => 'test',
                'accessKeySecret' => 'test',
            ],
            new MockHttpClient($responses),
        );
    }
}
