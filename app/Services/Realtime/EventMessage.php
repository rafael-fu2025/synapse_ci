<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use InvalidArgumentException;

final class EventMessage
{
    public static function encode(string $channel, string $event, array $data, int $maxBytes): string
    {
        if (! Channel::isValid($channel) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,95}\z/D', $event) !== 1) {
            throw new InvalidArgumentException('Invalid realtime channel or event.');
        }
        $json = json_encode(['type' => 'event', 'channel' => $channel, 'event' => $event, 'data' => $data], JSON_THROW_ON_ERROR);
        if (strlen($json) > $maxBytes) {
            throw new InvalidArgumentException('Realtime event exceeds payload limit.');
        }

        return $json;
    }
}
