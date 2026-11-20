<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

use RuntimeException;
use Throwable;

/**
 * The MIS employee directory does not list this identifier as an active employee.
 *
 * The live MIS systems answer the same condition with "Your account is INACTIVE . Please
 * contact the HR Department regarding this issue." The MIS API sandbox cannot express that
 * state: its employee login endpoint answers HTTP 500 while resolving an inactive account,
 * and its employee directory omits the identifier. This exception carries that finding to the
 * sign-in surface so the actor is given the same guidance the university systems give.
 */
final class MisInactiveAccountException extends RuntimeException
{
    public function __construct(
        string $message = 'Your MIS account is not active. Please contact the HR Department regarding this issue.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
