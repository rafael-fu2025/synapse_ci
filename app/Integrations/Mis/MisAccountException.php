<?php

declare(strict_types=1);

namespace App\Integrations\Mis;

use RuntimeException;
use Throwable;

/**
 * The MIS sign-in request reached the upstream and the upstream could not verify the account.
 *
 * This is neither a rejected credential nor a transport outage. The request was delivered and
 * answered, but the upstream failed while resolving the account: the live employee login
 * endpoint answers HTTP 500 for accounts whose state it cannot handle, so the submitted
 * credential cannot be judged from that response. Callers report the account as unverified
 * instead of claiming the network is at fault.
 */
final class MisAccountException extends RuntimeException
{
    public function __construct(
        string $message = 'MIS could not verify this account.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
