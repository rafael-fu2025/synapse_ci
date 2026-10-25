<?php

declare(strict_types=1);

namespace Config;

use App\Services\Realtime\Origin;
use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

final class Realtime extends BaseConfig
{
    public bool $enabled = false;
    public string $signingKey = '';
    public string $publishKey = '';
    public string $allowedOrigins = '';
    public string $listen = 'websocket://127.0.0.1:8081';
    public string $publishListen = 'http://127.0.0.1:8082';
    /**
     * Browser-facing socket URL. Leave empty to derive it per allowed (http) origin as
     * ws://{origin host}:{listen port}, so LAN/dev hosts work without hardcoding an IP.
     * HTTPS portals must set an explicit wss:// URL (typically a reverse-proxy path).
     */
    public string $publicUrl = '';
    public int $ticketTtl = 60;
    public int $authTimeout = 5;
    public int $maxPayloadBytes = 65536;
    public int $maxConnections = 512;
    public int $maxPublishConnections = 32;
    public float $publishTimeout = 2.0;

    public function __construct()
    {
        parent::__construct();
        // BaseConfig otherwise coerces arbitrary nonempty strings to boolean true.
        $enabled = $this->getEnvValue('enabled', self::class, 'realtime');
        if ($enabled !== false && $enabled !== null) {
            $this->enabled = in_array($enabled, [true, 1, 'true', '1'], true);
        }
    }

    public function origins(): array
    {
        return array_values(array_unique(array_map('trim', explode(',', $this->allowedOrigins))));
    }

    /** Socket URL the browser on this exact (already allow-listed) origin should connect to. */
    public function socketUrlFor(string $origin): string
    {
        if ($this->publicUrl !== '') {
            return $this->publicUrl;
        }
        $parts = parse_url($origin);
        $port = parse_url($this->listen, PHP_URL_PORT);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'http' || empty($parts['host']) || ! is_int($port)) {
            throw new InvalidArgumentException('Realtime cannot derive a socket URL for this origin.');
        }

        return 'ws://' . $parts['host'] . ':' . $port;
    }

    /** Every socket origin a browser may be told to use; feeds the CSP connect-src list. */
    public function socketOrigins(): array
    {
        $urls = $this->publicUrl !== '' ? [$this->publicUrl]
            : array_map(fn (string $origin): string => $this->socketUrlFor($origin), $this->origins());
        $sources = [];
        foreach ($urls as $url) {
            $endpoint = parse_url($url);
            $sources[] = $endpoint['scheme'] . '://' . $endpoint['host'] . (isset($endpoint['port']) ? ':' . $endpoint['port'] : '');
        }

        return array_values(array_unique($sources));
    }

    public function assertValid(): void
    {
        if (! $this->enabled) {
            throw new InvalidArgumentException('Realtime is disabled.');
        }
        if (strlen($this->signingKey) < 32 || strlen($this->publishKey) < 32
            || preg_match('/[\x00-\x20\x7f]/', $this->publishKey)
            || hash_equals($this->signingKey, $this->publishKey)) {
            throw new InvalidArgumentException('Realtime requires separate keys of at least 32 bytes.');
        }
        foreach ($this->origins() as $origin) {
            if (! Origin::isValid($origin)) {
                throw new InvalidArgumentException('Realtime requires exact HTTP allowed origins.');
            }
        }
        $this->validateEndpoint($this->listen, ['websocket']);
        if ($this->publicUrl === '') {
            foreach ($this->origins() as $origin) {
                if (! str_starts_with($origin, 'http://')) {
                    throw new InvalidArgumentException('HTTPS origins require an explicit wss:// realtime public URL.');
                }
            }
        } else {
            $this->validatePublicUrl();
        }
        $publisher = $this->validateEndpoint($this->publishListen, ['http']);
        if (! in_array($publisher['host'], ['127.0.0.1', '[::1]', 'localhost'], true)) {
            throw new InvalidArgumentException('Realtime publisher must listen on loopback.');
        }
        $socket = parse_url($this->listen);
        if ($socket['port'] === $publisher['port']) {
            throw new InvalidArgumentException('Realtime listeners require distinct ports.');
        }
        if ($this->ticketTtl < 1 || $this->ticketTtl > 300 || $this->authTimeout < 1 || $this->authTimeout > 30
            || $this->maxPayloadBytes < 8192 || $this->maxPayloadBytes > 1048576
            || $this->maxConnections < 1 || $this->maxConnections > 10000
            || $this->maxPublishConnections < 1 || $this->maxPublishConnections > 256
            || ! is_finite($this->publishTimeout) || $this->publishTimeout <= 0 || $this->publishTimeout > 5) {
            throw new InvalidArgumentException('Realtime limits are invalid.');
        }
    }

    private function validatePublicUrl(): void
    {
        $parts = parse_url($this->publicUrl);
        if (strlen($this->publicUrl) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $this->publicUrl)
            || ! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['ws', 'wss'], true)
            || empty($parts['host']) || array_diff(array_keys($parts), ['scheme', 'host', 'port', 'path']) !== []
            || ! Origin::isValid('http://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''))) {
            throw new InvalidArgumentException('Invalid realtime public URL.');
        }
    }

    private function validateEndpoint(string $endpoint, array $schemes): array
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', $schemes, true)
            || ! isset($parts['host'], $parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || ! Origin::isValid('http://' . $parts['host'] . ':' . $parts['port'])) {
            throw new InvalidArgumentException('Invalid realtime endpoint.');
        }

        return $parts;
    }
}
