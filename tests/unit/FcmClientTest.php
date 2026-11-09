<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Push\FcmClient;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Push;

/** No network access: the transport is a double and the key is generated in-process. */
final class FcmClientTest extends CIUnitTestCase
{
    private string $keyPath = '';
    private string $configPath = '';
    private mixed $generatedKey = null;

    protected function tearDown(): void
    {
        foreach ([$this->keyPath, $this->configPath] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
        $this->keyPath = '';
        $this->configPath = '';
        parent::tearDown();
    }

    /**
     * A minimal OpenSSL configuration so key generation works on hosts whose
     * default configuration file is missing.
     */
    private function opensslConfig(): string
    {
        if ($this->configPath === '') {
            $this->configPath = (string) tempnam(sys_get_temp_dir(), 'push-openssl-');
            file_put_contents($this->configPath, "openssl_conf = openssl_init\n\n[openssl_init]\n"
                . "providers = provider_sect\n\n[provider_sect]\ndefault = default_sect\n\n[default_sect]\nactivate = 1\n");
        }
        return $this->configPath;
    }

    /** @return array{0: Push, 1: string} */
    private function configuration(bool $configured = true): array
    {
        $options = ['config' => $this->opensslConfig(), 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        self::assertNotFalse($key);
        $this->generatedKey = $key;
        $pem = '';
        self::assertTrue(openssl_pkey_export($key, $pem, null, ['config' => $this->opensslConfig()]));
        $this->keyPath = (string) tempnam(sys_get_temp_dir(), 'push-account-');
        file_put_contents($this->keyPath, json_encode([
            'client_email' => 'push@example.test',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        $config = new Push();
        $config->enabled = $configured;
        $config->projectId = 'synapse-test';
        $config->serviceAccountPath = $configured ? $this->keyPath : '';
        return [$config, $this->keyPath];
    }

    public function testTheAssertionIsASignedRs256BearerToken(): void
    {
        [$config] = $this->configuration();
        $client = new FcmClient($config, null, static fn (): int => 1700000000);
        $assertion = $client->assertion();
        $parts = explode('.', $assertion);
        self::assertCount(3, $parts);
        $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/'), true), true);
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertSame('RS256', $header['alg']);
        self::assertSame('push@example.test', $claims['iss']);
        self::assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        self::assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
        self::assertSame(1700000000, $claims['iat']);
        self::assertSame(1700003600, $claims['exp']);
        $publicKey = openssl_pkey_get_details($this->generatedKey)['key'];
        self::assertSame(1, openssl_verify(
            $parts[0] . '.' . $parts[1],
            (string) base64_decode(strtr($parts[2], '-_', '+/'), true),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        ));
    }

    public function testTheAccessTokenIsFetchedOnceAndReused(): void
    {
        [$config] = $this->configuration();
        $calls = [];
        $transport = static function (string $url, array $headers, string $body) use (&$calls): array {
            $calls[] = [$url, $headers, $body];
            return ['status' => 200, 'body' => json_encode(['access_token' => 'access-1', 'expires_in' => 3600])];
        };
        $client = new FcmClient($config, $transport, static fn (): int => 1700000000);
        self::assertSame('access-1', $client->accessToken());
        self::assertSame('access-1', $client->accessToken());
        self::assertCount(1, $calls);
        self::assertSame('https://oauth2.googleapis.com/token', $calls[0][0]);
        self::assertStringContainsString('grant_type=urn%3Aietf%3Aparams%3Aoauth%3Agrant-type%3Ajwt-bearer', $calls[0][2]);
    }

    public function testDeliveryOutcomesFollowTheServiceResponse(): void
    {
        [$config] = $this->configuration();
        $responses = [
            ['status' => 200, 'body' => '{"name":"projects/synapse-test/messages/1"}'],
            ['status' => 404, 'body' => '{"error":{"status":"NOT_FOUND","details":[{"errorCode":"UNREGISTERED"}]}}'],
            ['status' => 429, 'body' => '{"error":{"status":"RESOURCE_EXHAUSTED"}}'],
            ['status' => 500, 'body' => '{"error":{"status":"INTERNAL"}}'],
            ['status' => 403, 'body' => '{"error":{"status":"PERMISSION_DENIED"}}'],
        ];
        $index = 0;
        $sent = [];
        $transport = static function (string $url, array $headers, string $body) use (&$responses, &$index, &$sent): array {
            $sent[] = [$url, $headers, $body];
            if (str_contains($url, 'oauth2.googleapis.com')) {
                return ['status' => 200, 'body' => json_encode(['access_token' => 'access-1', 'expires_in' => 3600])];
            }
            $response = $responses[$index] ?? ['status' => 200, 'body' => '{}'];
            ++$index;
            return $response;
        };
        $client = new FcmClient($config, $transport, static fn (): int => 1700000000);
        $message = ['title' => 'Appointment confirmed', 'body' => 'Your appointment has been confirmed.'];
        self::assertSame(FcmClient::SENT, $client->send('device-token-1', $message));
        self::assertSame(FcmClient::UNREGISTERED, $client->send('device-token-2', $message));
        self::assertSame(FcmClient::TRANSIENT, $client->send('device-token-3', $message));
        self::assertSame(FcmClient::TRANSIENT, $client->send('device-token-4', $message));
        self::assertSame(FcmClient::FAILED, $client->send('device-token-5', $message));

        $deliveries = array_values(array_filter($sent, static fn (array $call): bool => !str_contains($call[0], 'oauth2')));
        self::assertCount(5, $deliveries);
        self::assertSame('https://fcm.googleapis.com/v1/projects/synapse-test/messages:send', $deliveries[0][0]);
        self::assertSame('Bearer access-1', $deliveries[0][1]['Authorization']);
        $payload = json_decode($deliveries[0][2], true);
        self::assertSame('device-token-1', $payload['message']['token']);
        self::assertSame('Appointment confirmed', $payload['message']['notification']['title']);
    }

    public function testAnUnconfiguredClientNeverSends(): void
    {
        [$config] = $this->configuration(false);
        $transport = static function (): array { self::fail('No transport expected'); };
        $client = new FcmClient($config, $transport);
        self::assertSame(FcmClient::FAILED, $client->send('device-token-1', ['title' => 'x', 'body' => 'y']));
    }
}
