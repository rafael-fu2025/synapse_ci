<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Config\Realtime;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bearer-authenticated mobile grant for exactly the caller's own private user channel.
 * The tenant and user come only from the freshly DB-validated API identity, never from
 * request selectors; no AI-insight or department topics are granted on mobile.
 */
final class UserTicketIssuer
{
    public function __construct(private readonly Realtime $config)
    {
    }

    public function issue(int $tenantId, int $userId, string $origin): array
    {
        if ($tenantId < 1 || $userId < 1) {
            throw new RuntimeException('Realtime is unavailable.', 503);
        }
        if (! $this->config->enabled) {
            return ['enabled' => false];
        }
        try {
            $this->config->assertValid();
        } catch (InvalidArgumentException) {
            // Configuration errors must not expose keys, endpoints, or validation details.
            throw new RuntimeException('Realtime is unavailable.', 503);
        }
        if (! in_array($origin, $this->config->origins(), true)) {
            throw new RuntimeException('Realtime origin not allowed.', 403);
        }
        $url = $this->config->socketUrlFor($origin);
        if (str_starts_with($origin, 'https://') && ! str_starts_with($url, 'wss://')) {
            throw new RuntimeException('Realtime is unavailable.', 503);
        }

        $now = time();
        $channel = Channel::user($tenantId, $userId);

        return [
            'enabled' => true,
            'url' => $url,
            'origin' => $origin,
            'channel' => $channel,
            'ticket' => (new TicketSigner($this->config->signingKey, $this->config->ticketTtl))->issue([$channel], $origin, $now),
            'expires_at' => $now + $this->config->ticketTtl,
        ];
    }
}
