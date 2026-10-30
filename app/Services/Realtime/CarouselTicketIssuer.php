<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Config\Realtime;
use InvalidArgumentException;
use RuntimeException;

/** Public invalidations only; the tenant must come from trusted server context. */
final class CarouselTicketIssuer
{
    public function __construct(private readonly Realtime $config)
    {
    }

    public function issue(int $tenantId, string $origin): array
    {
        if (! $this->config->enabled) {
            return ['enabled' => false];
        }
        if ($tenantId < 1) {
            throw new RuntimeException('Realtime is unavailable.', 503);
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
        $channel = Channel::loginCarousel($tenantId);

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
