<?php

declare(strict_types=1);

namespace App\Services\Push;

use Config\Push;

/**
 * Delivers one message to every active device of one identity.
 *
 * A device token the delivery service no longer recognises is retired instead of
 * being retried forever.
 */
final class PushTransport
{
    public function __construct(
        private readonly ?FcmClient $client = null,
        private readonly ?PushTokenService $tokens = null,
        private readonly ?Push $configuration = null,
    ) {
    }

    private function client(): FcmClient
    {
        return $this->client ?? new FcmClient($this->configuration);
    }

    private function tokens(): PushTokenService
    {
        return $this->tokens ?? new PushTokenService(null, $this->configuration);
    }

    public function sendTeam(int $tenant, int $user, array $message): bool
    {
        return $this->sendToDevices($tenant, $this->tokens()->activeTokens($tenant, $user, true), $message);
    }

    public function teamCapable(int $tenant, int $user): bool
    {
        return $this->tokens()->hasTeamCapableToken($tenant, $user);
    }

    /**
     * @param array{title: string, body: string, data?: array<string, string>} $message
     *
     * @return bool false when at least one device refused the message.
     */
    public function send(int $tenant, int $user, array $message): bool
    {
        return $this->sendToDevices($tenant, $this->tokens()->activeTokens($tenant, $user), $message);
    }

    /** @param list<array<string, mixed>> $devices */
    private function sendToDevices(int $tenant, array $devices, array $message): bool
    {
        if ($devices === []) {
            // Nobody registered a device: there is nothing to deliver to.
            return true;
        }
        $delivered = true;
        foreach ($devices as $device) {
            $payload = $this->messageForDevice($device, $message);
            if ($payload === null) {
                // Never fall back to a legacy payload when the scoped binding is incomplete or stale.
                $delivered = false;
                continue;
            }
            $result = $this->client()->send($device['token'], $payload);
            if ($result === FcmClient::UNREGISTERED) {
                $this->tokens()->forget($tenant, $device['token'], $device['audience'] ?? null, $device['registration_id'] ?? null);
                continue;
            }
            if ($result !== FcmClient::SENT) {
                $delivered = false;
            }
        }
        return $delivered;
    }

    /** Enforce privacy at the common fanout boundary, including push:test CLI overrides. */
    private function messageForDevice(array $device, array $message): ?array
    {
        $scoped = false;
        foreach (['audience', 'registration_id', 'scope_version', 'deployment_id', 'active_lan_installation_id'] as $field) {
            if (array_key_exists($field, $device) && $device[$field] !== null) {
                $scoped = true;
                break;
            }
        }
        if (!$scoped) {
            return $message;
        }
        $config = $this->configuration ?? config(Push::class);
        $registration = $device['registration_id'] ?? null;
        $installation = $device['installation_id'] ?? null;
        $family = $device['api_family_id'] ?? null;
        if (!$config->hasDeploymentId() || ($device['audience'] ?? null) !== PushTokenService::LAN_AUDIENCE
            || !in_array($device['scope_version'] ?? null, [PushTokenService::SCOPE_VERSION, '1'], true)
            || ($device['deployment_id'] ?? null) !== $config->deploymentId
            || !is_string($registration) || preg_match(PushTokenService::UUID_V4_PATTERN, $registration) !== 1
            || !is_string($installation) || preg_match(PushTokenService::UUID_V4_PATTERN, $installation) !== 1
            || ($device['active_lan_installation_id'] ?? null) !== $installation
            || !is_string($family) || preg_match('/\A[0-9a-f]{32}\z/iD', $family) !== 1
            || (isset($message['data']) && !is_array($message['data']))) {
            return null;
        }
        $message['title'] = 'Synapse LAN Test';
        $message['body'] = 'Open the app to check for updates';
        // Overwrite supplied scope keys; callers never choose the deployment or activation envelope.
        $message['data'] = array_replace($message['data'] ?? [], [
            'scope_version' => (string) PushTokenService::SCOPE_VERSION,
            'deployment_id' => $config->deploymentId,
            'registration_id' => strtolower($registration),
        ]);
        return $message;
    }
}
