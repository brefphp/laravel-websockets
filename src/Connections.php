<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Exception\Http\NetworkException;
use Illuminate\Support\Facades\Log;

/**
 * Sends messages to API Gateway connections.
 */
class Connections
{
    // One by one, reaching 1000 connections takes about 16 seconds. With 50 requests at a time, about 1.3 seconds.
    private const CONCURRENCY = 50;

    public function __construct(
        private readonly ApiGatewayClient $client,
        private readonly string $stage,
    ) {}

    /**
     * @param  list<string>  $connectionIds
     */
    public function send(array $connectionIds, string $message): SendResult
    {
        $gone = [];
        $failed = [];

        foreach (array_chunk($connectionIds, self::CONCURRENCY) as $batch) {
            $responses = [];

            foreach ($batch as $connectionId) {
                $responses[$connectionId] = $this->client->postToConnection($this->stage, $connectionId, $message);
            }

            // All the requests are already running, this waits for them
            foreach ($responses as $connectionId => $response) {
                try {
                    $response->resolve();
                } catch (HttpException $exception) {
                    if ($exception->getResponse()->getStatusCode() === 410) {
                        $gone[] = (string) $connectionId;
                    } else {
                        $failed[] = (string) $connectionId;
                        Log::warning('Could not send a WebSocket message: ' . $exception->getMessage());
                    }
                } catch (NetworkException $exception) {
                    $failed[] = (string) $connectionId;
                    Log::warning('Could not send a WebSocket message: ' . $exception->getMessage());
                }
            }
        }

        return new SendResult(count($connectionIds), $gone, $failed);
    }
}
