<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Closure;
use Throwable;

/** A best-effort hint, never a result transport. Call only after an independent commit. */
final class AiInsightInvalidation
{
    private readonly Closure $send;

    public function __construct(?callable $send = null)
    {
        $this->send = Closure::fromCallable($send ?? static function (string $channel, string $event, array $data): bool {
            $config = clone config(\Config\Realtime::class);
            $config->publishTimeout = min($config->publishTimeout, 0.25);
            return (new Publisher($config))->publish($channel, $event, $data);
        });
    }

    public function changed(int $tenantId, string $unit): void
    {
        try {
            ($this->send)(AiInsightTopics::department($tenantId, $unit), 'ai.insights.changed', ['unit' => $unit]);
        } catch (Throwable) {
            // Polling reconciles missed hints; transport failure must not undo committed work.
        }
    }
}
