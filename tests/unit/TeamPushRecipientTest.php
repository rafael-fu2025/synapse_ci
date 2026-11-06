<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Push\PushPublisher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamPushRecipientTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'pushfixture_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        $this->db->query('CREATE TABLE pushfixture_users (id INTEGER PRIMARY KEY,tenant_id INTEGER,person_id INTEGER,status TEXT,deleted_at TEXT)');
        // Deliberately old schema: no Team/chat columns. Personal push must still work.
        $this->db->query('CREATE TABLE pushfixture_notifications (id INTEGER PRIMARY KEY,tenant_id INTEGER,
            recipient_user_id INTEGER,recipient_person_id INTEGER,type TEXT,appointment_id INTEGER,
            announcement_id INTEGER,survey_id INTEGER)');
        $this->db->table('users')->insertBatch([
            ['id' => 7, 'tenant_id' => 3, 'person_id' => 70, 'status' => 'active'],
            ['id' => 8, 'tenant_id' => 3, 'person_id' => 80, 'status' => 'suspended'],
        ]);
        $this->db->table('notifications')->insertBatch([
            ['id' => 1, 'tenant_id' => 3, 'recipient_person_id' => 70, 'type' => 'appointment_confirmed', 'appointment_id' => 41],
            ['id' => 2, 'tenant_id' => 3, 'recipient_person_id' => 80, 'type' => 'appointment_confirmed', 'appointment_id' => 42],
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testOldSchemaPersonalPushResolvesOnlyActivePersonAccount(): void
    {
        $sent = [];
        $publisher = new PushPublisher($this->db, null, static fn (int $tenant, int $user): bool => $user === 7,
            static function (int $tenant, int $user, array $message) use (&$sent): bool {
                $sent[] = [$tenant, $user, $message];
                return true;
            }, true);
        $publisher->publishCommitted(3, [1, 2]);
        $this->assertCount(1, $sent);
        $this->assertSame([3, 7], array_slice($sent[0], 0, 2));
        $this->assertSame(['type' => 'appointment_confirmed', 'id' => '41'], $sent[0][2]['data']);
    }
}
