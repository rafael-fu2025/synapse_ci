<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Stable Team API conflict reasons without coupling controllers to message text. */
final class TeamConflictException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message, 409);
    }

    public static function stale(string $message): self
    {
        return new self('stale_version', $message);
    }

    public static function idempotency(): self
    {
        return new self('idempotency_conflict', 'client_nonce was already used for different contents.');
    }
}
