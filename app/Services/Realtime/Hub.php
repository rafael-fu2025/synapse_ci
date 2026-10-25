<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use Config\Realtime;
use Throwable;

/** One process owns all subscriptions. Connections expose send() and destroy(). */
final class Hub
{
    private array $clients = [];
    private readonly TicketSigner $signer;

    public function __construct(private readonly Realtime $config)
    {
        $config->assertValid();
        $this->signer = new TicketSigner($config->signingKey, $config->ticketTtl);
    }

    public function open(object $connection, ?int $now = null): void
    {
        if (count($this->clients) >= $this->config->maxConnections) {
            $connection->destroy();
            return;
        }
        $this->clients[spl_object_id($connection)] = [
            'connection' => $connection, 'origin' => null, 'channels' => [],
            'deadline' => ($now ?? time()) + $this->config->authTimeout, 'authenticated' => false,
        ];
    }

    public function handshake(object $connection, string $origin): bool
    {
        $id = spl_object_id($connection);
        if (! isset($this->clients[$id]) || ! in_array($origin, $this->config->origins(), true)) {
            return false;
        }
        $this->clients[$id]['origin'] = $origin;

        return true;
    }

    public function message(object $connection, string $payload, ?int $now = null): void
    {
        $now ??= time();
        $id = spl_object_id($connection);
        $client = $this->clients[$id] ?? null;
        if ($client === null || $client['origin'] === null || $client['deadline'] <= $now
            || strlen($payload) > $this->config->maxPayloadBytes) {
            $this->disconnect($connection);
            return;
        }
        try {
            $message = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($message)) {
                throw new \InvalidArgumentException('Invalid message.');
            }
            if (! $client['authenticated']) {
                if (count($message) !== 2 || ($message['type'] ?? null) !== 'authenticate'
                    || ! is_string($message['ticket'] ?? null)) {
                    throw new \InvalidArgumentException('Authentication required.');
                }
                $claims = $this->signer->verify($message['ticket'], $client['origin'], $now);
                if ($claims === null) {
                    throw new \InvalidArgumentException('Invalid ticket.');
                }
                $this->clients[$id]['authenticated'] = true;
                $this->clients[$id]['channels'] = $claims['channels'];
                $this->clients[$id]['deadline'] = $claims['exp'];
                $connection->send('{"type":"ready"}');
                return;
            }
            if ($message === ['type' => 'ping']) {
                $connection->send('{"type":"pong"}');
                return;
            }
        } catch (Throwable) {
            // Never expose ticket contents or parsing details to the peer.
        }
        $this->disconnect($connection);
    }

    public function publish(string $channel, string $event, array $data, ?int $now = null): void
    {
        $json = EventMessage::encode($channel, $event, $data, $this->config->maxPayloadBytes);
        $this->tick($now);
        foreach ($this->clients as $client) {
            if ($client['deadline'] <= ($now ?? time())) {
                $this->disconnect($client['connection']);
                continue;
            }
            if ($client['authenticated'] && in_array($channel, $client['channels'], true)) {
                try {
                    $client['connection']->send($json);
                } catch (Throwable) {
                    $this->disconnect($client['connection']);
                }
            }
        }
    }

    public function tick(?int $now = null): void
    {
        $now ??= time();
        foreach ($this->clients as $client) {
            if ($client['deadline'] <= $now) {
                $this->disconnect($client['connection']);
            }
        }
    }

    public function forget(object $connection): void
    {
        unset($this->clients[spl_object_id($connection)]);
    }

    private function disconnect(object $connection): void
    {
        $this->forget($connection);
        // close() drains the send buffer; expired grants must discard queued data.
        $connection->destroy();
    }
}
