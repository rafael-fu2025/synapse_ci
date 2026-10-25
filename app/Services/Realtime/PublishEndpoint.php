<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Config\Realtime;
use Throwable;

final class PublishEndpoint
{
    public function __construct(private readonly Realtime $config, private readonly Hub $hub)
    {
        $config->assertValid();
    }

    public function handle(string $method, string $uri, string $authorization, string $origin, string $body): int
    {
        if ($method !== 'POST' || $uri !== '/publish') {
            return 404;
        }
        if ($origin !== '' || ! hash_equals('Bearer ' . $this->config->publishKey, $authorization)) {
            return 403;
        }
        if (strlen($body) > $this->config->maxPayloadBytes) {
            return 413;
        }
        try {
            $message = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($message) || count($message) !== 3 || ! is_string($message['channel'] ?? null)
                || ! is_string($message['event'] ?? null) || ! is_array($message['data'] ?? null)) {
                return 422;
            }
            $this->hub->publish($message['channel'], $message['event'], $message['data']);

            return 202;
        } catch (Throwable) {
            return 422;
        }
    }
}
