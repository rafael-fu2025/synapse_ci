<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Push\PushTokenService;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;

/**
 * Device token registration for mobile push delivery.
 *
 * The device token is a routable address, not a credential: it is accepted, stored
 * against the signed-in identity, and never returned by any endpoint.
 */
final class PushController extends ApiV1Controller
{
    public function register(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $body = $this->jsonBody();
            $token = $body['token'] ?? null;
            $platform = $body['platform'] ?? null;
            if (!is_string($token) || trim($token) === '' || !is_string($platform) || trim($platform) === '') {
                throw new InvalidArgumentException('A device token and platform are required.', 422);
            }
            $label = $body['device_label'] ?? null;
            if (!is_string($label)) {
                $label = null;
            } else {
                $label = trim(mb_substr($label, 0, 120));
                if ($label === '') {
                    $label = null;
                }
            }
            (new PushTokenService())->register(
                $this->identity(),
                $token,
                $platform,
                $label,
                $this->request->getIPAddress(),
            );
            return $this->ok(['data' => ['registered' => true]]);
        });
    }

    public function revoke(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $body = $this->jsonBody();
            $token = $body['token'] ?? null;
            if (!is_string($token) || trim($token) === '') {
                throw new InvalidArgumentException('A device token is required.', 422);
            }
            (new PushTokenService())->revoke($this->identity(), $token);
            return $this->noContent();
        });
    }
}
