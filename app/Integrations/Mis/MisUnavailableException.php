<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

use RuntimeException;
use Throwable;

final class MisUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'MIS is temporarily unavailable. Please try again later.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
