<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test\Unit;

use Bref\LaravelWebsockets\ApiGatewayClient;
use Bref\LaravelWebsockets\Connections;
use Bref\LaravelWebsockets\Test\TestCase;
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

        $result = $connections->send(['L0SM9cOFvHcCIhw=', 'conn-2'], 'hello');

        $this->assertSame([], $result->gone);
        $this->assertSame([], $result->failed);
        $this->assertSame([
            'POST https://abc123.execute-api.eu-west-3.amazonaws.com/prod/@connections/L0SM9cOFvHcCIhw%3D',
            'POST https://abc123.execute-api.eu-west-3.amazonaws.com/prod/@connections/conn-2',
        ], $urls);
    }

    #[Test]
    public function it_reports_gone_and_failed_connections_without_stopping(): void
    {
        $connections = $this->connectionsWith(fn(string $method, string $url) => match (true) {
            str_contains($url, 'gone') => new MockResponse('{"message":"Gone"}', ['http_code' => 410]),
            str_contains($url, 'denied') => new MockResponse('{"message":"Forbidden"}', ['http_code' => 403]),
            default => new MockResponse('', ['http_code' => 200]),
        });

        $result = $connections->send(['gone', 'denied', 'ok'], 'hello');

        $this->assertSame(3, $result->total);
        $this->assertSame(['gone'], $result->gone);
        $this->assertSame(['denied'], $result->failed);
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

    private function connectionsWith(callable $responses): Connections
    {
        return new Connections(new ApiGatewayClient(
            [
                'region' => 'eu-west-3',
                'endpoint' => 'https://abc123.execute-api.eu-west-3.amazonaws.com',
                'accessKeyId' => 'test',
                'accessKeySecret' => 'test',
            ],
            null,
            new MockHttpClient($responses),
        ), 'prod');
    }
}
