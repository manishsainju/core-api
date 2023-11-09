<?php

namespace Fleetbase\Webhook\Events;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;

abstract class WebhookCallEvent
{
    public function __construct(
        string $httpVerb,
        string $webhookUrl,
        array $payload,
        array $headers,
        array $meta,
        array $tags,
        int $attempt,
        ?Response $response,
        ?string $errorType,
        ?string $errorMessage,
        string $uuid,
        ?TransferStats $transferStats
    ) {
    }
}
