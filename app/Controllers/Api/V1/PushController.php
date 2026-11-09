<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Models\ApiTokenModel;
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
    public function __construct(
        private readonly ?PushTokenService $tokenRegistry = null,
        private readonly ?ApiTokenModel $apiTokens = null,
    ) {
    }

    private function tokens(): PushTokenService
    {
        return $this->tokenRegistry ?? new PushTokenService();
    }

    /** Authenticated discovery must precede sending a LAN FCM token to this server. */
    public function capabilities(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $this->identity();
            return $this->ok(['data' => $this->tokens()->capabilities()]);
        });
    }

    public function register(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
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
            [$audience, $registrationId, $deploymentId] = $this->scope($body);
            $installationId = $body['installation_id'] ?? null;
            $capabilities = $body['capabilities'] ?? [];
            if (($installationId !== null && !is_string($installationId))
                || !is_array($capabilities) || !array_is_list($capabilities)
                || array_diff($capabilities, ['team_workspace']) !== []
                || ($installationId === null && $capabilities !== [])
                || ($audience !== null && $installationId === null)) {
                throw new InvalidArgumentException('A valid installation and capabilities list are required.', 422);
            }
            $familyId = $installationId !== null ? $this->familyId($identity) : null;
            $result = $this->tokens()->register(
                $identity,
                $token,
                $platform,
                $label,
                $this->request->getIPAddress(),
                $installationId,
                $familyId,
                in_array('team_workspace', $capabilities, true),
                $audience,
                $registrationId,
                $deploymentId,
            );
            return $this->ok(['data' => $result]);
        });
    }

    public function revoke(): ResponseInterface
    {
        return $this->run(function (): ResponseInterface {
            $identity = $this->identity();
            $body = $this->jsonBody();
            $token = $body['token'] ?? null;
            if (!is_string($token) || trim($token) === '') {
                throw new InvalidArgumentException('A device token is required.', 422);
            }
            [$audience, $registrationId] = $this->scope($body);
            $this->tokens()->revoke($identity, $token, $audience, $registrationId,
                $audience !== null ? $this->familyId($identity) : null);
            return $this->noContent();
        });
    }

    /** The family is resolved from the authenticated bearer, never from request body metadata. */
    private function familyId(array $identity): string
    {
        $row = ($this->apiTokens ?? new ApiTokenModel())->where('token_hash', ApiContext::tokenHash())
            ->where('tenant_id', (int) ($identity['tenant_id'] ?? 0))->where('user_id', (int) ($identity['id'] ?? 0))
            ->where('revoked_at', null)->where('rotated_at', null)->select('family_id')->first();
        $family = $row['family_id'] ?? null;
        if (!is_string($family) || preg_match('/\A[0-9a-f]{32}\z/iD', $family) !== 1) {
            throw new InvalidArgumentException('The API token family is unavailable.', 401);
        }
        return strtolower($family);
    }

    /** Reject partial/null/unknown scopes rather than downgrading a LAN request to the legacy API. */
    private function scope(array $body): array
    {
        $present = array_key_exists('audience', $body) || array_key_exists('registration_id', $body)
            || array_key_exists('deployment_id', $body);
        if (!$present) {
            return [null, null, null];
        }
        $audience = $body['audience'] ?? null;
        $registration = $body['registration_id'] ?? null;
        $deployment = $body['deployment_id'] ?? null;
        if ($audience !== PushTokenService::LAN_AUDIENCE || !is_string($registration)
            || preg_match(PushTokenService::UUID_V4_PATTERN, $registration) !== 1
            || (array_key_exists('deployment_id', $body) && (!is_string($deployment) || $deployment === ''))) {
            throw new InvalidArgumentException('A valid lan-test audience and registration_id are required.', 422);
        }
        return [$audience, strtolower($registration), $deployment];
    }
}
