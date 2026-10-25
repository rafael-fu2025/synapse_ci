<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Realtime\Channel;
use App\Services\Realtime\Publisher;
use App\Services\Realtime\TicketSigner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Realtime;
use Workerman\Worker;

/** Real Spark/Workerman process, real masked WebSocket frames, no browser or curl. */
final class RealtimeTransportTest extends CIUnitTestCase
{
    private const ORIGIN = 'https://portal.example.test';
    private array $sockets = [];
    private Realtime $settings;
    private string $wsAddress;
    private string $httpAddress;

    public function testWindowsForegroundTransportAndBoundedShutdown(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Exercises native Windows foreground process shutdown.');
        }
        $this->assertTrue(class_exists(Worker::class), 'Install the Workerman dependency before this transport test.');
        $this->settings = new Realtime();
        $this->settings->enabled = true;
        $this->settings->signingKey = bin2hex(random_bytes(32));
        $this->settings->publishKey = bin2hex(random_bytes(32));
        $this->settings->allowedOrigins = self::ORIGIN;
        $this->settings->ticketTtl = 4;
        $this->settings->authTimeout = 1;
        $this->settings->maxPayloadBytes = 8192;
        $this->settings->maxConnections = 4;
        $this->settings->maxPublishConnections = 2;
        $this->wsAddress = $this->freeAddress();
        do { $this->httpAddress = $this->freeAddress(); } while ($this->httpAddress === $this->wsAddress);
        $this->settings->listen = 'websocket://' . $this->wsAddress;
        $this->settings->publishListen = 'http://' . $this->httpAddress;
        $this->settings->publicUrl = 'wss://portal.example.test/realtime';
        $environment = getenv();
        foreach (get_object_vars($this->settings) as $key => $value) {
            if (is_scalar($value)) {
                $environment['realtime.' . $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        // Spark's testing environment expects SUPPORTPATH from the PHPUnit bootstrap.
        $environment['CI_ENVIRONMENT'] = 'development';
        // Array argv + bypass_shell means proc_terminate targets PHP, not a shell.
        $process = proc_open([PHP_BINARY, ROOTPATH . 'spark', 'realtime:serve', 'start'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, ROOTPATH, $environment, ['bypass_shell' => true]);
        $this->assertIsResource($process);
        fclose($pipes[0]);
        try {
            $this->waitForServer($process);
            $this->assertHandshakeRejected('https://attacker.test', '/');
            $this->assertHandshakeRejected('', '/');
            $this->assertHandshakeRejected(self::ORIGIN, '/?ticket=must-not-authenticate');

            $first = $this->authenticated(1);
            $second = $this->authenticated(2);
            $publisher = new Publisher($this->settings);
            $this->assertTrue($publisher->publish(Channel::user(1, 1), 'changed', ['id' => 7]));
            $this->assertSame(['type' => 'event', 'channel' => Channel::user(1, 1), 'event' => 'changed', 'data' => ['id' => 7]], $this->readMessage($first));
            $this->sendMessage($second, ['type' => 'ping']);
            $this->assertSame(['type' => 'pong'], $this->readMessage($second), 'Other users must not receive the event.');
            $this->sendFrame($first, str_pad('{"type":"ping"}', 8192));
            $this->assertSame(['type' => 'pong'], $this->readMessage($first), 'Frame overhead must not reduce the application payload limit.');

            $body = json_encode(['channel' => Channel::user(1, 1), 'event' => 'changed', 'data' => []], JSON_THROW_ON_ERROR);
            $this->assertSame(403, $this->http('Bearer wrong', '', $body));
            $this->assertSame(403, $this->http('Bearer ' . $this->settings->publishKey, self::ORIGIN, $body));
            $this->assertSame(422, $this->http('Bearer ' . $this->settings->publishKey, '', '{}'));
            $this->assertSame(413, $this->http('Bearer ' . $this->settings->publishKey, '', str_repeat('x', 8193)));
            $this->sendMessage($first, ['type' => 'ping']);
            $this->assertSame(['type' => 'pong'], $this->readMessage($first), 'Rejected publishes must not produce events.');

            $forbidden = $this->authenticated(3);
            $this->sendMessage($forbidden, ['type' => 'subscribe', 'channel' => Channel::user(1, 2)]);
            $this->assertClosed($forbidden, 2.0);
            $oversize = $this->authenticated(3);
            $this->sendFrame($oversize, str_repeat('x', 8193));
            $this->assertClosed($oversize, 2.0);
            $pending = $this->websocket();
            $this->assertClosed($pending, 2.0);

            // No heartbeat or publish may extend a ticket; no queued stale event is returned.
            $this->assertClosed($first, 5.0);
            $this->assertClosed($second, 2.0);
            $this->assertTrue($publisher->publish(Channel::user(1, 1), 'after.expiry', ['stale' => true]));
            $fresh = $this->authenticated(1);
            $this->sendMessage($fresh, ['type' => 'ping']);
            $this->assertSame(['type' => 'pong'], $this->readMessage($fresh), 'Reconnect must not replay expired-session data.');
            fclose($fresh);
            usleep(100000);

            $waiting = [];
            for ($i = 0; $i < 4; $i++) { $waiting[] = $this->socket($this->wsAddress); }
            $excess = $this->socket($this->wsAddress);
            $this->assertClosed($excess, 0.75);
            foreach ($waiting as $socket) { $this->assertClosed($socket, 2.0); }
            $httpWaiting = [$this->socket($this->httpAddress), $this->socket($this->httpAddress)];
            $this->assertClosed($this->socket($this->httpAddress), 0.75);
            foreach ($httpWaiting as $socket) { $this->assertClosed($socket, 2.0); }
        } finally {
            foreach ($this->sockets as $socket) {
                if (is_resource($socket)) { fclose($socket); }
            }
            proc_terminate($process);
            $deadline = microtime(true) + 3.0;
            do {
                $running = proc_get_status($process)['running'];
                if (! $running) { break; }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if ($running) {
                proc_terminate($process);
                throw new \RuntimeException('Windows realtime process did not stop within three seconds.');
            }
            // Pipes are read only after exit; Windows anonymous pipes can block while live.
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->assertStringNotContainsString('TypeError', $output);
            $this->assertStringNotContainsString('multi workers', $output);
            $this->assertStringNotContainsString('Fatal error', $output);
            $this->assertStringNotContainsString('Warning:', $output);
            $this->assertStringNotContainsString('Exception:', $output);
            $this->assertStringContainsString('Workerman/' . Worker::VERSION, $output);
        }
        foreach ([$this->wsAddress, $this->httpAddress] as $address) {
            $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.2);
            if (is_resource($probe)) { fclose($probe); }
            $this->assertFalse($probe, 'Server listener must be gone after native process termination.');
        }
    }

    private function freeAddress(): string
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        return $address;
    }

    private function waitForServer($process): void
    {
        $deadline = microtime(true) + 8.0;
        do {
            $this->assertTrue(proc_get_status($process)['running'], 'Spark realtime process exited before startup.');
            $socket = @stream_socket_client('tcp://' . $this->httpAddress, $errno, $error, 0.1);
            if ($socket !== false) { fclose($socket); usleep(100000); return; }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('Realtime HTTP listener did not start within eight seconds.');
    }

    private function socket(string $address)
    {
        $socket = stream_socket_client('tcp://' . $address, $errno, $error, 0.5);
        $this->assertIsResource($socket, $error);
        stream_set_timeout($socket, 1);
        $this->sockets[] = $socket;
        return $socket;
    }

    private function handshake($socket, string $origin, string $uri): string
    {
        $key = base64_encode(random_bytes(16));
        $headers = "GET {$uri} HTTP/1.1\r\nHost: {$this->wsAddress}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {$key}\r\n";
        if ($origin !== '') { $headers .= "Origin: {$origin}\r\n"; }
        fwrite($socket, $headers . "\r\n");
        $response = $this->readHeaders($socket);
        if (str_contains($response, ' 101 ')) {
            $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
            $this->assertStringContainsString('Sec-WebSocket-Accept: ' . $accept, $response);
        }
        return $response;
    }

    private function assertHandshakeRejected(string $origin, string $uri): void
    {
        $socket = $this->socket($this->wsAddress);
        $this->assertStringContainsString(' 403 ', $this->handshake($socket, $origin, $uri));
        $this->assertClosed($socket, 1.0);
    }

    private function websocket()
    {
        $socket = $this->socket($this->wsAddress);
        $this->assertStringContainsString(' 101 ', $this->handshake($socket, self::ORIGIN, '/'));
        return $socket;
    }

    private function authenticated(int $userId)
    {
        $socket = $this->websocket();
        $ticket = (new TicketSigner($this->settings->signingKey, $this->settings->ticketTtl))
            ->issue([Channel::user(1, $userId)], self::ORIGIN);
        $this->sendMessage($socket, ['type' => 'authenticate', 'ticket' => $ticket]);
        $this->assertSame(['type' => 'ready'], $this->readMessage($socket));
        return $socket;
    }

    private function sendMessage($socket, array $message): void
    {
        $this->sendFrame($socket, json_encode($message, JSON_THROW_ON_ERROR));
    }

    private function sendFrame($socket, string $payload): void
    {
        $length = strlen($payload);
        $mask = random_bytes(4);
        $header = $length < 126 ? chr(0x80 | $length) : chr(0xfe) . pack('n', $length);
        $masked = $payload ^ substr(str_repeat($mask, (int) ceil($length / 4)), 0, $length);
        $frame = "\x81" . $header . $mask . $masked;
        $this->assertSame(strlen($frame), fwrite($socket, $frame));
    }

    private function readMessage($socket): array
    {
        $header = $this->readBytes($socket, 2);
        $this->assertSame(1, ord($header[0]) & 15);
        $length = ord($header[1]) & 127;
        if ($length === 126) { $length = unpack('n', $this->readBytes($socket, 2))[1]; }
        $this->assertLessThan(8193, $length);
        return json_decode($this->readBytes($socket, $length), true, 32, JSON_THROW_ON_ERROR);
    }

    private function readBytes($socket, int $length): string
    {
        $data = '';
        $deadline = microtime(true) + 1.5;
        while (strlen($data) < $length && microtime(true) < $deadline) {
            $chunk = fread($socket, $length - strlen($data));
            $this->assertNotFalse($chunk);
            $this->assertNotSame('', $chunk, 'Socket closed or timed out before the expected response.');
            $data .= $chunk;
        }
        $this->assertSame($length, strlen($data));
        return $data;
    }

    private function readHeaders($socket): string
    {
        $headers = '';
        $deadline = microtime(true) + 1.5;
        while (! str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 16384 && microtime(true) < $deadline) {
            $line = fgets($socket, 16385 - strlen($headers));
            $this->assertNotFalse($line, 'Socket closed or timed out while reading HTTP headers.');
            $headers .= $line;
        }
        $this->assertStringEndsWith("\r\n\r\n", $headers);
        return $headers;
    }

    private function assertClosed($socket, float $timeout): void
    {
        $deadline = microtime(true) + $timeout;
        stream_set_timeout($socket, 0, 100000);
        do {
            $data = fread($socket, 1024);
            if ($data === false && (stream_get_meta_data($socket)['timed_out'] ?? false)) {
                continue;
            }
            $this->assertSame('', $data, 'Closed/expired connection must not deliver queued data.');
            if (feof($socket)) { return; }
        } while (microtime(true) < $deadline);
        $this->fail('Connection did not close within its bounded timeout.');
    }

    private function http(string $authorization, string $origin, string $body): int
    {
        $socket = $this->socket($this->httpAddress);
        $headers = "POST /publish HTTP/1.1\r\nHost: {$this->httpAddress}\r\nAuthorization: {$authorization}\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\nConnection: close\r\n";
        if ($origin !== '') { $headers .= "Origin: {$origin}\r\n"; }
        fwrite($socket, $headers . "\r\n" . $body);
        $response = $this->readHeaders($socket);
        fclose($socket);
        preg_match('/\AHTTP\/1\.[01] (\d{3}) /', $response, $matches);
        return (int) ($matches[1] ?? 0);
    }
}
