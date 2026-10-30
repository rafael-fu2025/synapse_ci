<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Realtime\Publisher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Realtime;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class RealtimeConfigTest extends CIUnitTestCase
{
    private function settings(): Realtime
    {
        $config = new Realtime();
        $config->enabled = true;
        $config->signingKey = str_repeat('s', 32);
        $config->publishKey = str_repeat('p', 32);
        $config->allowedOrigins = 'https://portal.example.test, http://localhost:8080';
        $config->publicUrl = 'wss://portal.example.test/realtime';
        return $config;
    }

    public function testOptInDefaultsAndExactCommaSeparatedOrigins(): void
    {
        // Declared defaults must not depend on a developer's local environment overrides.
        $config = (new \ReflectionClass(Realtime::class))->newInstanceWithoutConstructor();
        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->signingKey);
        $this->assertSame('', $config->publishKey);
        $this->assertSame('websocket://127.0.0.1:8081', $config->listen);
        $this->assertSame('http://127.0.0.1:8082', $config->publishListen);
        $this->assertSame('', $config->publicUrl);
        $this->assertSame(60, $config->ticketTtl);
        $valid = $this->settings();
        $valid->assertValid();
        $this->assertSame(['https://portal.example.test', 'http://localhost:8080'], $valid->origins());
    }

    public function testEmptyPublicUrlDerivesSocketFromEachHttpOriginAndListenPort(): void
    {
        $config = $this->settings();
        $config->allowedOrigins = 'http://192.168.1.8:8080, http://localhost:8080';
        $config->publicUrl = '';
        $config->listen = 'websocket://0.0.0.0:8081';
        $config->assertValid();
        $this->assertSame('ws://192.168.1.8:8081', $config->socketUrlFor('http://192.168.1.8:8080'));
        $this->assertSame('ws://localhost:8081', $config->socketUrlFor('http://localhost:8080'));
        $this->assertSame(['ws://192.168.1.8:8081', 'ws://localhost:8081'], $config->socketOrigins());
    }

    public function testEmptyPublicUrlRequiresExplicitWssForHttpsOrigins(): void
    {
        $config = $this->settings(); // includes an https origin
        $config->publicUrl = '';
        $this->expectException(InvalidArgumentException::class);
        $config->assertValid();
    }

    public function testExplicitPublicUrlIsReturnedUnchangedForEveryOrigin(): void
    {
        $config = $this->settings();
        $config->publicUrl = 'wss://portal.example.test/realtime';
        $this->assertSame($config->publicUrl, $config->socketUrlFor('http://localhost:8080'));
        $this->assertSame(['wss://portal.example.test'], $config->socketOrigins());
    }

    public static function invalidConfig(): iterable
    {
        foreach (['enabled' => [false], 'signingKey' => ['', 'short'], 'publishKey' => ['', 'short', str_repeat('s', 32), str_repeat('p', 32) . "\r\n"],
            'allowedOrigins' => ['', '*', 'null', 'https://example.test/', 'https://example.test,', 'https://example.test/path'],
            'publishListen' => ['http://0.0.0.0:8082', 'http://example.test:8082', 'https://127.0.0.1:8082', 'http://127.0.0.1:8082/path', 'http://127.0.0.1:8081'],
            'listen' => ['http://127.0.0.1:8081', 'websocket://127.0.0.1:8081?ticket=x'],
            'publicUrl' => ['https://example.test:443', 'ws://user:pass@example.test:8081', 'wss://example.test/realtime?ticket=x',
                'wss://example.test/realtime#fragment', 'wss://example.test:0/realtime', 'wss://example.test:65536/realtime',
                "wss://example.test/real\ntime", 'wss://example.test/realtime\\evil', 'wss:///realtime'],
            'maxConnections' => [0, 10001], 'maxPublishConnections' => [0, 257],
            'ticketTtl' => [0, 301], 'authTimeout' => [0, 31], 'maxPayloadBytes' => [1, 1048577],
            'publishTimeout' => [0.0, 5.1, INF, NAN]] as $property => $values) {
            foreach ($values as $value) { yield [$property, $value]; }
        }
    }

    #[DataProvider('invalidConfig')]
    public function testInvalidConfigurationFailsClosed(string $property, mixed $value): void
    {
        $config = $this->settings();
        $config->$property = $value;
        $this->expectException(InvalidArgumentException::class);
        $config->assertValid();
    }

    public function testPublicUrlSupportsReverseProxyWithoutWeakeningListeners(): void
    {
        $config = $this->settings();
        foreach (['wss://portal.example.test/realtime', 'wss://portal.example.test', 'ws://localhost/realtime',
            'wss://portal.example.test:8443/realtime/socket', 'wss://[::1]/realtime'] as $url) {
            $config->publicUrl = $url;
            $config->assertValid();
            $this->assertSame($url, $config->publicUrl);
        }
        $config->listen = 'websocket://127.0.0.1:8081/realtime';
        $this->expectException(InvalidArgumentException::class);
        $config->assertValid();
    }

    public function testEnvironmentRequiresExplicitOptIn(): void
    {
        $saved = $_ENV['realtime.enabled'] ?? null;
        try {
            foreach (['false' => false, 'true' => true, '1' => true, '0' => false, 'yes' => false, 'invalid' => false] as $value => $expected) {
                $_ENV['realtime.enabled'] = (string) $value;
                $this->assertSame($expected, (new Realtime())->enabled);
            }
        } finally {
            if ($saved === null) {
                unset($_ENV['realtime.enabled']);
            } else {
                $_ENV['realtime.enabled'] = $saved;
            }
        }
    }

    public function testPublisherTransportTimeoutIsBounded(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        try {
            $config = $this->settings();
            $config->publishListen = 'http://' . stream_socket_get_name($socket, false);
            $config->publishTimeout = 0.1;
            $publisher = new Publisher($config, static function (): void {});
            $start = microtime(true);
            // The listening socket deliberately never sends HTTP response headers.
            $this->assertFalse($publisher->publish('private.user.1', 'changed', []));
            $this->assertLessThan(3.0, microtime(true) - $start);
        } finally {
            fclose($socket);
        }
    }

    public function testDisabledPublisherIsSilentNoOp(): void
    {
        $config = new Realtime();
        $config->enabled = false;
        $logs = [];
        $publisher = new Publisher($config, static function (string $message) use (&$logs): void { $logs[] = $message; });
        $this->assertTrue($publisher->publish('ignored', 'ignored', []));
        $this->assertSame([], $logs);
    }

    public function testPublisherFailsWithoutThrowingOrLoggingSecrets(): void
    {
        $config = $this->settings();
        $config->signingKey = 'secret-invalid';
        $logs = [];
        $publisher = new Publisher($config, static function (string $message) use (&$logs): void { $logs[] = $message; });
        $this->assertFalse($publisher->publish('private.user.1', 'changed', ['secret' => 'sensitive']));
        $this->assertSame(['Realtime publish failed.'], $logs);
        $publisher = new Publisher($config, static function (): void { throw new \RuntimeException('logger failed'); });
        $this->assertFalse($publisher->publish('private.user.1', 'changed', []));
    }
}
