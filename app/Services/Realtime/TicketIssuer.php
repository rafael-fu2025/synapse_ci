<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Config\Realtime;
use RuntimeException;

/** Only call with the freshly DB-validated identity from AuthenticatedUser. */
final class TicketIssuer
{
    public function __construct(private readonly Realtime $config)
    {
    }

    public function issue(array $actor, string $origin): array
    {
        if (($actor['logged_in'] ?? null) !== true
            || ! is_int($actor['id'] ?? null) || $actor['id'] < 1
            || ! is_int($actor['tenant_id'] ?? null) || $actor['tenant_id'] < 1) {
            throw new RuntimeException('Authentication required.', 401);
        }
        if (! $this->config->enabled) {
            return ['enabled' => false];
        }
        $this->config->assertValid();
        if (! in_array($origin, $this->config->origins(), true)) {
            throw new RuntimeException('Origin not allowed.', 403);
        }
        $url = $this->config->socketUrlFor($origin);
        if (str_starts_with($origin, 'https://') && ! str_starts_with($url, 'wss://')) {
            throw new RuntimeException('Secure realtime transport is required.', 503);
        }
        $now = time();
        // No role wildcard or client-selected channels, including for superadmin.
        $channels = [Channel::user($actor['tenant_id'], $actor['id']), ...AiInsightTopics::grants($actor)];
        return [
            'enabled' => true,
            'url' => $url,
            'ticket' => (new TicketSigner($this->config->signingKey, $this->config->ticketTtl))->issue($channels, $origin, $now),
            'expires_at' => $now + $this->config->ticketTtl,
        ];
    }
}
