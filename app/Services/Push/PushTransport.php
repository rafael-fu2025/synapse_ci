<?php

declare(strict_types=1);

namespace App\Services\Push;

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
    ) {
    }

    private function client(): FcmClient
    {
        return $this->client ?? new FcmClient();
    }

    private function tokens(): PushTokenService
    {
        return $this->tokens ?? new PushTokenService();
    }

    /**
     * @param array{title: string, body: string, data?: array<string, string>} $message
     *
     * @return bool false when at least one device refused the message.
     */
    public function send(int $tenant, int $user, array $message): bool
    {
        $devices = $this->tokens()->activeTokens($tenant, $user);
        if ($devices === []) {
            // Nobody registered a device: there is nothing to deliver to.
            return true;
        }
        $delivered = true;
        foreach ($devices as $device) {
            $result = $this->client()->send($device['token'], $message);
            if ($result === FcmClient::UNREGISTERED) {
                $this->tokens()->forget($device['token']);
                continue;
            }
            if ($result !== FcmClient::SENT) {
                $delivered = false;
            }
        }
        return $delivered;
    }
}
