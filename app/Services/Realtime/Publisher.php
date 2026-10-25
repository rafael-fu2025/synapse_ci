<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Closure;
use Config\Realtime;
use Throwable;

final class Publisher
{
    private readonly Realtime $config;
    private readonly ?Closure $logger;

    public function __construct(?Realtime $config = null, ?callable $logger = null)
    {
        $this->config = $config ?? config(Realtime::class);
        $this->logger = $logger === null ? null : Closure::fromCallable($logger);
    }

    /** Disabled is a successful no-op; failures never break the calling transaction. */
    public function publish(string $channel, string $event, array $data): bool
    {
        if (! $this->config->enabled) {
            return true;
        }
        try {
            $this->config->assertValid();
            EventMessage::encode($channel, $event, $data, $this->config->maxPayloadBytes);
            $body = json_encode(['channel' => $channel, 'event' => $event, 'data' => $data], JSON_THROW_ON_ERROR);
            if (strlen($body) > $this->config->maxPayloadBytes) {
                throw new \RuntimeException('Payload too large.');
            }
            $context = stream_context_create(['http' => [
                'method' => 'POST', 'timeout' => $this->config->publishTimeout,
                'ignore_errors' => true, 'follow_location' => 0, 'max_redirects' => 0,
                'header' => "Content-Type: application/json\r\nConnection: close\r\nAuthorization: Bearer "
                    . $this->config->publishKey . "\r\n",
                'content' => $body,
            ]]);
            $stream = @fopen($this->config->publishListen . '/publish', 'rb', false, $context);
            if ($stream === false) {
                throw new \RuntimeException('Publish transport unavailable.');
            }
            try {
                $metadata = stream_get_meta_data($stream);
                $headers = $metadata['wrapper_data'] ?? [];
                if (($metadata['timed_out'] ?? false) || ! isset($headers[0])
                    || preg_match('/\AHTTP\/\d(?:\.\d)? 202(?: |$)/', $headers[0]) !== 1) {
                    throw new \RuntimeException('Publish rejected.');
                }
            } finally {
                fclose($stream);
            }

            return true;
        } catch (Throwable) {
            try {
                if ($this->logger !== null) {
                    ($this->logger)('Realtime publish failed.');
                } elseif (function_exists('log_message')) {
                    log_message('error', 'Realtime publish failed.');
                }
            } catch (Throwable) {
                // Logging availability must not affect business operations either.
            }

            return false;
        }
    }
}
