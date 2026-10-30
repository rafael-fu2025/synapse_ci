<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Migrations\AddAiAnalysisScope;
use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiFailure;
use App\Services\Ai\DisclosurePolicy;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use App\Services\Realtime\AiInsightInvalidation;
use App\Services\Realtime\AiInsightTopics;
use App\Services\Realtime\Publisher;
use App\Services\Realtime\TicketIssuer;
use App\Services\Realtime\TicketSigner;
use Config\Database;
use ReflectionProperty;

/** Runs inside RealtimeTransportTest's real, bounded Spark/Workerman transport harness. */
trait AiRealtimeTransportScenario
{
    private function assertAiPostCommitTransport(): void
    {
        $this->assertTrue(extension_loaded('sqlite3'), 'AI transport fixture requires disposable SQLite.');
        $db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_socket_',
            'DBDebug' => true, 'foreignKeys' => true,
        ], false);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $previous = $instances->getValue();
        $clients = [];
        try {
            require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
            require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
            (new CreateAiInsights(Database::forge($db)))->up();
            (new AddAiAnalysisScope(Database::forge($db)))->up();
            $instances->setValue(null, ['tests' => $db]);
            $this->assertTrue($db->table('ai_department_access')->insert([
                'tenant_id' => 7, 'unit' => 'clinic', 'role' => 'clinic_staff',
                'permission' => 'ai.insights.read', 'created_by' => 8, 'created_at' => '2026-10-30 00:00:00',
            ]));
            $actors = [
                ['id' => 8, 'tenant_id' => 7, 'logged_in' => true, 'role' => 'clinic_staff', 'permissions' => ['clinic.encounters.read']],
                ['id' => 9, 'tenant_id' => 9, 'logged_in' => true, 'role' => 'clinic_staff', 'permissions' => ['ai.insights.read', 'clinic.encounters.read']],
                ['id' => 10, 'tenant_id' => 7, 'logged_in' => true, 'role' => 'guidance_admin', 'permissions' => ['ai.insights.read', 'guidance.cases.read_any']],
                ['id' => 11, 'tenant_id' => 7, 'logged_in' => true, 'role' => 'clinic_admin', 'permissions' => ['clinic.encounters.read']],
            ];
            $topic = AiInsightTopics::department(7, 'clinic');
            foreach ($actors as $index => $actor) {
                $ticket = (new TicketIssuer($this->settings))->issue($actor, self::ORIGIN);
                $claims = (new TicketSigner($this->settings->signingKey, $this->settings->ticketTtl))->verify($ticket['ticket'], self::ORIGIN);
                if ($index === 0) {
                    $this->assertContains($topic, $claims['channels'], 'Fresh department grant must authorize the AI topic.');
                } else {
                    $this->assertNotContains($topic, $claims['channels'], 'Other tenant, department or missing AI permission must not authorize the topic.');
                }
                $client = $this->websocket();
                $clients[] = $client;
                $this->sendMessage($client, ['type' => 'authenticate', 'ticket' => $ticket['ticket']]);
                $this->assertSame(['type' => 'ready'], $this->readMessage($client));
            }

            $publisher = new Publisher($this->settings);
            $sends = [];
            $invalidation = new AiInsightInvalidation(function (string $channel, string $event, array $data) use ($db, $publisher, &$sends): bool {
                // Capture assertions outside this best-effort callback, which deliberately swallows failures.
                $sends[] = [
                    'depth' => $db->transDepth,
                    'jobs' => array_column($db->table('ai_jobs')->orderBy('id')->get()->getResultArray(), 'status'),
                    'channel' => $channel, 'event' => $event, 'data' => $data,
                    'published' => $publisher->publish($channel, $event, $data),
                ];
                return $sends[array_key_last($sends)]['published'];
            });
            $store = new InsightStore($db, new PayloadCipher(base64_encode(str_repeat('k', 32))),
                static fn (): int => strtotime('2026-10-30 12:00:00 UTC'), 30, $invalidation->changed(...));
            $receive = function () use (&$sends, $clients, $topic): void {
                $send = $sends[array_key_last($sends)];
                $this->assertSame(0, $send['depth'], 'HTTP publish must happen after the independent commit.');
                $this->assertTrue($send['published'], 'Actual loopback HTTP publisher must accept the post-commit hint.');
                $this->assertSame([
                    'type' => 'event', 'channel' => $topic, 'event' => 'ai.insights.changed', 'data' => ['unit' => 'clinic'],
                ], $this->readMessage($clients[0]), 'Actual WebSocket frame must contain metadata only.');
            };
            $payload = DisclosurePolicy::release(
                ['count' => 240, 'contributors' => 20, 'unlinked' => 0],
                ['count' => 200, 'contributors' => 20, 'unlinked' => 0], '2026-09', '2026-08');
            $snapshot = $store->saveSnapshot(7, 'clinic', $payload);
            $receive();
            $job = $store->enqueue(7, 'clinic', $snapshot['id'], 8, 'synthetic-r1', 'synthetic/model', 10);
            $receive();
            $this->assertSame(['queued'], $sends[1]['jobs']);
            $store->claim(7, 'clinic', 'synthetic-r1');
            $receive();
            $this->assertSame(['running'], $sends[2]['jobs']);
            // Synthetic already-validated output: no provider, generator or credential is involved.
            $draft = ['summary' => 'Synthetic validated result; never sent over the socket.'];
            $store->complete(7, 'clinic', $job['id'], $draft);
            $receive();
            $this->assertSame(['succeeded'], $sends[3]['jobs']);
            $insight = $store->listInsights(7, 'clinic')[0];
            $this->assertSame($draft, $insight['draft'], 'Result is available by scoped refetch, not in the event.');
            $store->review(7, 'clinic', $insight['id'], 8, 1, 'accept');
            $receive();
            try {
                $store->review(7, 'clinic', $insight['id'], 8, 1, 'reject');
                $this->fail('Stale review must roll back.');
            } catch (AiFailure $error) {
                $this->assertSame('review_conflict', $error->reason);
            }
            $this->assertCount(5, $sends, 'Rolled-back mutation and reads must not publish.');
            $this->sendMessage($clients[0], ['type' => 'ping']);
            $this->assertSame(['type' => 'pong'], $this->readMessage($clients[0]), 'No event may be queued by the rollback.');

            $failed = $store->enqueue(7, 'clinic', $snapshot['id'], 8, 'synthetic-r2', 'synthetic/model', 10);
            $receive();
            $store->claim(7, 'clinic', 'synthetic-r2');
            $receive();
            $store->fail(7, 'clinic', $failed['id'], 'invalid_output');
            $receive();
            $this->assertSame(['succeeded', 'failed'], $sends[7]['jobs']);
            $this->assertCount(8, $sends);
            foreach (array_slice($clients, 1) as $client) {
                $this->sendMessage($client, ['type' => 'ping']);
                $this->assertSame(['type' => 'pong'], $this->readMessage($client), 'Other tenant/department and missing AI permission must receive no AI event.');
            }
        } finally {
            foreach ($clients as $client) {
                if (is_resource($client)) { fclose($client); }
            }
            $instances->setValue(null, $previous);
            $db->close();
            // Allow the existing four-connection cap to observe closed scenario clients.
            usleep(100000);
        }
    }
}
