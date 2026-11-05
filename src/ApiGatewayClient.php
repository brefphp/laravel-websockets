<?php

declare(strict_types=1);

namespace Bref\LaravelWebsockets;

use AsyncAws\Core\AbstractApi;
use AsyncAws\Core\Request;
use AsyncAws\Core\Response;
use AsyncAws\Core\Stream\StreamFactory;

/**
 * The API Gateway Management API, which AsyncAws does not provide.
 */
class ApiGatewayClient extends AbstractApi
{
    public function postToConnection(string $stage, string $connectionId, string $body): Response
    {
        $path = sprintf('/%s/@connections/%s', $stage, rawurlencode($connectionId));

        return $this->getResponse(new Request('POST', $path, [], [], StreamFactory::create($body)));
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
}
