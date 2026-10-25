<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Config\Realtime;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;

final class Server
{
    public function __construct(private readonly Realtime $config)
    {
        $config->assertValid();
    }

    public function run(): void
    {
        $hub = new Hub($this->config);
        $endpoint = new PublishEndpoint($this->config, $hub);
        $worker = new Worker($this->config->listen);
        $worker->name = 'humus-realtime';
        $worker->count = 1;
        $worker->onConnect = function (TcpConnection $connection) use ($hub): void {
            if (count($connection->worker->connections) > $this->config->maxConnections) {
                $connection->destroy();
                return;
            }
            $connection->maxPackageSize = $this->config->maxPayloadBytes + 14;
            $connection->maxSendBufferSize = 1048576;
            $hub->open($connection);
        };
        $worker->onWebSocketConnect = function (TcpConnection $connection, Request $request) use ($hub): void {
            if ($request->uri() !== '/' || ! $hub->handshake($connection, (string) $request->header('origin', ''))) {
                $hub->forget($connection);
                $connection->send("HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", true);
                $connection->destroy();
            }
        };
        $worker->onMessage = static function (TcpConnection $connection, string $payload) use ($hub): void {
            $hub->message($connection, $payload);
        };
        $worker->onClose = static function (TcpConnection $connection) use ($hub): void {
            $hub->forget($connection);
        };
        $worker->onBufferFull = static function (TcpConnection $connection) use ($hub): void {
            $hub->forget($connection);
            $connection->destroy();
        };
        $worker->onWorkerStart = function () use ($hub, $endpoint): void {
            Timer::add(0.25, static function () use ($hub): void { $hub->tick(); });

            // A secondary listener in the SAME event loop also works on Windows.
            // Do not increase worker count without replacing in-memory fanout.
            $publisher = new Worker($this->config->publishListen);
            $publisher->name = 'humus-realtime-publisher';
            $publisher->onConnect = function (TcpConnection $connection): void {
                if (count($connection->worker->connections) > $this->config->maxPublishConnections) {
                    $connection->destroy();
                    return;
                }
                $connection->maxPackageSize = $this->config->maxPayloadBytes + 8192;
                $connection->maxSendBufferSize = 8192;
                $timer = Timer::add($this->config->authTimeout, static function () use ($connection): void {
                    $connection->destroy();
                }, [], false);
                $connection->onClose = static function () use ($timer): void { Timer::del($timer); };
            };
            $publisher->onMessage = static function (TcpConnection $connection, Request $request) use ($endpoint): void {
                $status = $endpoint->handle($request->method(), $request->uri(),
                    (string) $request->header('authorization', ''), (string) $request->header('origin', ''), $request->rawBody());
                $connection->close(new Response($status, [
                    'Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'Connection' => 'close',
                ], json_encode(['accepted' => $status === 202], JSON_THROW_ON_ERROR)));
            };
            $publisher->listen();
        };
        Worker::runAll();
    }
}
