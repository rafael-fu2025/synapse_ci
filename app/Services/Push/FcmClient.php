<?php

declare(strict_types=1);

namespace App\Services\Push;

use Config\Push;
use JsonException;
use RuntimeException;

/**
 * Firebase Cloud Messaging HTTP v1 client.
 *
 * The service account key never leaves the server. Access tokens are minted with
 * the standard OAuth2 JWT bearer flow and reused until they are close to expiry.
 */
final class FcmClient
{
    public const SENT = 'sent';
    public const UNREGISTERED = 'unregistered';
    public const TRANSIENT = 'transient';
    public const FAILED = 'failed';

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    private ?string $accessToken = null;
    private int $accessTokenExpiresAt = 0;
    private ?array $account = null;

    /** @var null|callable(string, array<string, string>, string): array{status: int, body: string} */
    private $transport;

    /** @var null|callable(): int */
    private $clock;

    public function __construct(
        private readonly ?Push $configuration = null,
        ?callable $transport = null,
        ?callable $clock = null,
    ) {
        $this->transport = $transport;
        $this->clock = $clock;
    }

    private function config(): Push
    {
        return $this->configuration ?? config(Push::class);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    /**
     * Deliver one message to one device.
     *
     * @param array{title: string, body: string, data?: array<string, string>} $message
     */
    public function send(string $deviceToken, array $message): string
    {
        if (!$this->config()->isConfigured()) {
            return self::FAILED;
        }
        try {
            $accessToken = $this->accessToken();
        } catch (RuntimeException) {
            return self::TRANSIENT;
        }
        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => ['title' => $message['title'], 'body' => $message['body']],
                'data' => $message['data'] ?? [],
                'android' => ['priority' => 'high', 'notification' => ['channel_id' => 'synapse-notifications']],
                'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
            ],
        ];
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::FAILED;
        }
        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', rawurlencode($this->config()->projectId));
        $response = $this->request($url, ['Authorization' => 'Bearer ' . $accessToken, 'Content-Type' => 'application/json'], $body);
        if ($response['status'] === 200) {
            return self::SENT;
        }
        if ($response['status'] === 404 || str_contains($response['body'], 'UNREGISTERED')) {
            return self::UNREGISTERED;
        }
        if ($response['status'] === 429 || $response['status'] >= 500) {
            return self::TRANSIENT;
        }
        if ($response['status'] === 401 || $response['status'] === 403) {
            // The access token or the service account is rejected; stop using it.
            $this->accessToken = null;
            $this->accessTokenExpiresAt = 0;
        }
        return self::FAILED;
    }

    /** The OAuth2 access token for the messaging scope, reused until it is close to expiry. */
    public function accessToken(): string
    {
        $now = $this->now();
        if ($this->accessToken !== null && $now < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }
        $account = $this->serviceAccount();
        $uri = (string) ($account['token_uri'] ?? self::TOKEN_URI);
        $body = http_build_query(['grant_type' => self::GRANT_TYPE, 'assertion' => $this->assertion()]);
        $response = $this->request($uri, ['Content-Type' => 'application/x-www-form-urlencoded'], $body);
        if ($response['status'] !== 200) {
            throw new RuntimeException('Push authentication failed.');
        }
        $decoded = json_decode($response['body'], true);
        $token = is_array($decoded) ? (string) ($decoded['access_token'] ?? '') : '';
        if ($token === '') {
            throw new RuntimeException('Push authentication returned no access token.');
        }
        $expiresIn = is_array($decoded) ? (int) ($decoded['expires_in'] ?? 3600) : 3600;
        $this->accessToken = $token;
        // Renew early so a token never expires mid-request.
        $this->accessTokenExpiresAt = $now + max(60, $expiresIn - 60);
        return $token;
    }

    /** The signed JWT bearer assertion used for the token exchange. */
    public function assertion(): string
    {
        $account = $this->serviceAccount();
        $now = $this->now();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => (string) ($account['client_email'] ?? ''),
            'scope' => $this->config()->tokenScope,
            'aud' => (string) ($account['token_uri'] ?? self::TOKEN_URI),
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $signingInput = $this->base64Url($this->json($header)) . '.' . $this->base64Url($this->json($claims));
        $key = openssl_pkey_get_private((string) ($account['private_key'] ?? ''));
        if ($key === false) {
            throw new RuntimeException('Push service account key is unusable.');
        }
        $signature = '';
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Push service account key could not sign the assertion.');
        }
        return $signingInput . '.' . $this->base64Url($signature);
    }

    /** @return array<string, mixed> */
    private function serviceAccount(): array
    {
        if ($this->account !== null) {
            return $this->account;
        }
        $path = $this->config()->serviceAccountPath;
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Push service account key is not readable.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Push service account key is not readable.');
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded) || !isset($decoded['client_email'], $decoded['private_key'])) {
            throw new RuntimeException('Push service account key is malformed.');
        }
        $this->account = $decoded;
        return $this->account;
    }

    /** @return array{status: int, body: string} */
    private function request(string $url, array $headers, string $body): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($url, $headers, $body);
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The push transport requires the cURL extension.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('The push transport could not be opened.');
        }
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->config()->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->config()->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            throw new RuntimeException('The push transport failed: ' . ($error !== '' ? $error : 'unknown error'));
        }
        return ['status' => $status, 'body' => (string) $response];
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Push payload could not be encoded.', 0, $exception);
        }
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
