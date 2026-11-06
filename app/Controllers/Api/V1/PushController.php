<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Services\Api\ApiContext;
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
            $installationId = $body['installation_id'] ?? null;
            $capabilities = $body['capabilities'] ?? [];
            if (($installationId !== null && !is_string($installationId))
                || !is_array($capabilities) || !array_is_list($capabilities)
                || array_diff($capabilities, ['team_workspace']) !== []
                || ($installationId === null && $capabilities !== [])) {
                throw new InvalidArgumentException('A valid installation and capabilities list are required.', 422);
            }
            $familyId = null;
            if ($installationId !== null) {
                $familyId = (new \App\Models\ApiTokenModel())->where('token_hash', ApiContext::tokenHash())
                    ->select('family_id')->first()['family_id'] ?? null;
                if (!is_string($familyId)) {
                    throw new InvalidArgumentException('The API token family is unavailable.', 401);
                }
            }
            (new PushTokenService())->register(
                $this->identity(),
                $token,
                $platform,
                $label,
                $this->request->getIPAddress(),
                $installationId,
                $familyId,
                in_array('team_workspace', $capabilities, true),
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
