<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets\Test;

use Bref\LaravelWebsockets\Connections;
use Bref\LaravelWebsockets\SendResult;

class FakeConnections extends Connections
{
    /** @var list<array{connectionId: string, message: array<string, mixed>}> */
    public array $sent = [];

    /** @var list<string> */
    public array $gone = [];

    /** @var list<string> */
    public array $failing = [];

    public function __construct() {}

    public function send(array $connectionIds, string $message): SendResult
    {
        $gone = array_values(array_intersect($connectionIds, $this->gone));
        $failed = array_values(array_intersect($connectionIds, $this->failing));

        foreach (array_diff($connectionIds, $gone, $failed) as $connectionId) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
            $this->sent[] = ['connectionId' => $connectionId, 'message' => $decoded];
        }

        return new SendResult(count($connectionIds), $gone, $failed);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sentTo(string $connectionId): array
    {
        return array_values(array_map(
            fn(array $sent) => $sent['message'],
            array_filter($this->sent, fn(array $sent) => $sent['connectionId'] === $connectionId),
        ));
    }
}
