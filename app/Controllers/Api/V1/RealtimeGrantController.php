<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Realtime\UserTicketIssuer;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Realtime;
use RuntimeException;

/** Bearer-authenticated realtime grant for the signed-in mobile user's own private channel. */
final class RealtimeGrantController extends ApiV1Controller
{
    public function show(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
            $tenantId = (int) ($identity['tenant_id'] ?? 0);
            $userId = (int) ($identity['id'] ?? 0);
            if (! service('throttler')->check(
                'realtime-grant-user-' . $tenantId . '-' . $userId, 60, 60
            )) {
                throw new RuntimeException('Too many realtime requests. Please try again shortly.', 429);
            }

            // Server-derived identity only; request selectors never influence the grant.
            $payload = (new UserTicketIssuer(config(Realtime::class)))->issue(
                $tenantId, $userId, $this->request->getHeaderLine('Origin')
            );

            return $this->ok(['data' => $payload]);
        });
    }
}
