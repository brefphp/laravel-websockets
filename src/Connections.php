<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\Core\Exception\Http\NetworkException;
use AsyncAws\Core\Request;
use AsyncAws\Core\Response;
use AsyncAws\Core\Stream\StreamFactory;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Facades\Log;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends messages to API Gateway connections, through the API Gateway Management API that AsyncAws does not provide.
 */
class Connections extends AbstractApi
{
    // One by one, reaching 1000 connections takes about 16 seconds. With 50 requests at a time, about 1.3 seconds.
    private const CONCURRENCY = 50;

    /**
     * @param  array{region: string, endpoint: string, accessKeyId?: string, accessKeySecret?: string}  $configuration
     */
    public function __construct(
        private readonly string $stage,
        array $configuration,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct($configuration, null, $httpClient);
    }

    /**
     * @param  list<string>  $connectionIds
     * @return list<string> The connections that no longer exist
     *
     * @throws BroadcastException No connection could be reached, most likely a configuration problem like missing IAM permissions
     */
    public function send(array $connectionIds, string $message): array
    {
        $gone = [];
        $failed = 0;

        foreach (array_chunk($connectionIds, self::CONCURRENCY) as $batch) {
            $responses = [];

            foreach ($batch as $connectionId) {
                $responses[$connectionId] = $this->postToConnection($connectionId, $message);
            }

            // All the requests are already running, this waits for them
            foreach ($responses as $connectionId => $response) {
                try {
                    $response->resolve();
                } catch (HttpException $exception) {
                    if ($exception->getResponse()->getStatusCode() === 410) {
                        $gone[] = (string) $connectionId;
                    } else {
                        $failed++;
                        Log::warning('Could not send a WebSocket message: ' . $exception->getMessage());
                    }
                } catch (NetworkException $exception) {
                    $failed++;
                    Log::warning('Could not send a WebSocket message: ' . $exception->getMessage());
                }
            }
        }

        if ($failed > 0 && $failed === count($connectionIds)) {
            throw new BroadcastException(sprintf('Could not send a WebSocket message to any of the %d connections, see the logs.', $failed));
        }

        return $gone;
    }

    protected function getEndpointMetadata(?string $region): array
    {
        return [
            'endpoint' => (string) $this->getConfiguration()->get('endpoint'),
            'signRegion' => $region ?? (string) $this->getConfiguration()->get('region'),
            'signService' => 'execute-api',
            'signVersions' => ['v4'],
        ];
    }

    private function postToConnection(string $connectionId, string $message): Response
    {
        $path = sprintf('/%s/@connections/%s', $this->stage, rawurlencode($connectionId));

        return $this->getResponse(new Request('POST', $path, [], [], StreamFactory::create($message)));
    }
}
