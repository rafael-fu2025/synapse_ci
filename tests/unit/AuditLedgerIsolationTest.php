<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\HardenAuditLedger;
use App\Services\Audit\AuditHash;
use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/_support/queue-race-worker.php';

/** Synthetic InnoDB fixtures only; deliberately no DatabaseTestTrait or migration runner. */
final class AuditLedgerIsolationTest extends TestCase
{
    private ?BaseConnection $db = null;
    private HardenAuditLedger $migration;
    private array $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        try {
            \QueueIsolatedSafety::environment();
            $bootstrap = realpath(dirname(__DIR__) . '/_support/queue-isolated-bootstrap.php');
            if (!in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
                throw new \RuntimeException('The isolated queue bootstrap must already have run.');
            }
            \QueueIsolatedSafety::configuration();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        $db = \Config\Database::connect();
        \QueueIsolatedSafety::connection($db);
        $this->db = $db;
        CurrentTenant::set(1);
        // Exact legacy production types, defaults, indexes and FK; no production seed/migration execution.
        $db->query("CREATE TABLE tenants (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL, name VARCHAR(150) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME NULL,
            UNIQUE KEY slug (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query("CREATE TABLE audit_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL DEFAULT 1,
            event_type VARCHAR(64) NOT NULL COMMENT 'e.g. encounter.created, batch.released, notes.finalized',
            entity_type VARCHAR(64) NOT NULL, entity_id VARCHAR(64) NOT NULL,
            actor_user_id BIGINT UNSIGNED NULL, actor_role VARCHAR(50) NULL,
            actor_ip VARCHAR(45) NOT NULL DEFAULT '127.0.0.1', action VARCHAR(32) NOT NULL,
            payload_json TEXT NULL,
            prev_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hash of previous row; genesis is 64 zeros',
            curr_hash CHAR(64) NOT NULL COMMENT 'SHA-256(prev_hash . event_type . entity_id . actor . payload . time)',
            occurred_at DATETIME NOT NULL,
            KEY idx_audit_tenant_time (tenant_id, occurred_at),
            KEY idx_audit_entity (tenant_id, entity_type, entity_id),
            CONSTRAINT audit_events_tenant_id_foreign FOREIGN KEY (tenant_id) REFERENCES tenants(id)
                ON DELETE RESTRICT ON UPDATE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        foreach ([1, 2] as $id) {
            $this->assertTrue($db->table('tenants')->insert([
                'id' => $id, 'slug' => 'synthetic-' . $id, 'name' => 'Synthetic tenant ' . $id,
                'created_at' => '2037-04-23 01:02:03', 'updated_at' => '2037-04-23 01:02:03',
            ]));
        }
        $fixture = [
            'tenant_id' => 1, 'event_type' => 'legacy.created', 'entity_type' => 'synthetic',
            'entity_id' => 'legacy|boundary', 'actor_user_id' => null, 'actor_role' => null,
            'actor_ip' => '127.0.0.1', 'action' => 'create',
            'payload_json' => "{ \"label\": \"café/土|fixture\", \"n\": 1 }\n",
            'prev_hash' => AuditHash::GENESIS, 'occurred_at' => '2001-02-03 04:05:06',
        ];
        // Independent pre-cutover digest: do not let the implementation define its own v1 oracle.
        $fixture['curr_hash'] = hash('sha256', implode('|', [
            $fixture['prev_hash'], $fixture['event_type'], $fixture['entity_type'], $fixture['entity_id'],
            'system', $fixture['occurred_at'], $fixture['payload_json'],
        ]));
        $this->assertTrue($db->table('audit_events')->insert($fixture));
        $this->legacy = $this->rows(1)[0];
        require_once APPPATH . 'Database/Migrations/2026-10-19-000003_HardenAuditLedger.php';
        $forge = \Config\Database::forge($db);
        $this->assertSame($db, $forge->getConnection());
        $this->migration = new HardenAuditLedger($forge);
        $this->migration->up();
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            while ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            $this->db->resetTransStatus();
            $this->db->query('DROP TRIGGER IF EXISTS audit_isolation_tamper_hash');
            // DROP only our synthetic tables, after identity validation in setUp; never downgrade to clean up.
            $this->db->query('DROP TABLE IF EXISTS audit_isolation_business');
            $this->db->query('DROP TABLE IF EXISTS role_permissions');
            $this->db->query('DROP TABLE IF EXISTS permissions');
            $this->db->query('DROP TABLE IF EXISTS users');
            $this->db->query('DROP TABLE IF EXISTS audit_events');
            $this->db->query('DROP TABLE IF EXISTS tenants');
            CurrentTenant::reset();
        }
        parent::tearDown();
    }

    private function rows(int $tenant): array
    {
        return $this->db->query('SELECT * FROM audit_events WHERE tenant_id=? ORDER BY id', [$tenant])->getResultArray();
    }

    private function observer(): BaseConnection
    {
        \QueueIsolatedSafety::configuration();
        $db = \Config\Database::connect('tests', false);
        \QueueIsolatedSafety::connection($db);
        $db->query('SET SESSION innodb_lock_wait_timeout=5');
        return $db;
    }

    private function append(?BaseConnection $db = null, string $label = 'parent'): array
    {
        return (new AuditService(null, $db ?? $this->db))->log(
            'isolation.created', 'synthetic', $label, 'create', ['label' => $label, 'unicode' => '土'],
            17, 'synthetic-role', '2001:db8::1'
        );
    }

    private function rejected(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (\Throwable $e) {
            $this->assertStringContainsString($message, $e->getMessage());
            return;
        }
        $this->fail('Expected rejection: ' . $message);
    }

    private function assertChain(int $tenant, int $count): void
    {
        $expected = ['valid' => true, 'verified_count' => $count, 'broken_id' => null];
        $this->assertSame($expected, AuditHash::verify($this->rows($tenant), $tenant));
        $this->assertSame($expected, (new AuditService(null, $this->db))->verifyChain($tenant));
    }

    public function testMigrationPreservesLegacyBytesAndRetryUpIsIdempotent(): void
    {
        $this->migration->up();
        $row = $this->rows(1)[0];
        $this->assertSame(1, (int) $row['hash_version']);
        unset($row['hash_version']);
        $this->assertSame($this->legacy, $row);
        $hex = $this->db->query('SELECT HEX(payload_json) AS payload, HEX(curr_hash) AS digest FROM audit_events')->getRowArray();
        $this->assertSame(strtoupper(bin2hex($this->legacy['payload_json'])), $hex['payload']);
        $this->assertSame(strtoupper(bin2hex($this->legacy['curr_hash'])), $hex['digest']);
        $column = $this->db->query("SHOW COLUMNS FROM audit_events LIKE 'hash_version'")->getRowArray();
        $this->assertStringContainsString('smallint', $column['Type']);
        $this->assertStringContainsString('unsigned', $column['Type']);
        $this->assertSame('NO', $column['Null']);
        $this->assertSame('1', (string) $column['Default']);
        $triggers = $this->db->query('SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->getResultArray();
        $this->assertCount(3, $triggers);
        foreach ($triggers as $trigger) {
            $this->assertSame('BEFORE', $trigger['ACTION_TIMING']);
            $this->assertSame('audit_audit_events_' . strtolower($trigger['EVENT_MANIPULATION']), $trigger['TRIGGER_NAME']);
        }
        $this->assertChain(1, 1);
    }

    public function testServiceAppendsV2AndVerifiesMixedTenantScopedChain(): void
    {
        $event = $this->append();
        $this->assertSame(2, (int) $event['hash_version']);
        $this->assertSame($this->legacy['curr_hash'], $event['prev_hash']);
        $this->assertSame(AuditHash::compute($event), $event['curr_hash']);
        $this->assertChain(1, 2);
        CurrentTenant::set(2);
        $other = $this->append(null, 'other-tenant');
        $this->assertSame(AuditHash::GENESIS, $other['prev_hash']);
        $this->assertChain(2, 1);
        $this->assertChain(1, 2);
    }

    public function testDatabaseRejectsUpdateDeleteAndNewLegacyInserts(): void
    {
        $this->append();
        $before = $this->rows(1);
        $this->rejected(fn () => $this->db->query("UPDATE audit_events SET action='tamper' WHERE tenant_id=1"), 'Audit history is append-only');
        $this->rejected(fn () => $this->db->query('DELETE FROM audit_events WHERE tenant_id=1'), 'Audit history is append-only');
        $fixture = $this->legacy;
        unset($fixture['id']);
        foreach ([null, 1] as $version) {
            $insert = $fixture;
            if ($version !== null) {
                $insert['hash_version'] = $version;
            }
            $this->rejected(fn () => $this->db->table('audit_events')->insert($insert), 'New audit events require hash_version 2');
        }
        $this->assertSame($before, $this->rows(1));
        $this->assertChain(1, 2);
    }

    public function testDownRefusesV2WithoutRemovingProtections(): void
    {
        $this->append();
        $before = $this->rows(1);
        $this->rejected(fn () => $this->migration->down(), 'Refusing audit downgrade');
        $this->assertSame($before, $this->rows(1));
        $this->migration->up();
        $this->rejected(fn () => $this->db->query('DELETE FROM audit_events'), 'Audit history is append-only');
        $this->assertChain(1, 2);
    }

    public function testCallerRollbackRemovesAuditAndServiceDoesNotCommit(): void
    {
        $observer = $this->observer();
        try {
            $this->assertTrue($this->db->transBegin());
            $this->db->query("UPDATE tenants SET name='Uncommitted synthetic' WHERE id=1");
            $this->append();
            $this->assertSame(1, $this->db->transDepth);
            $this->assertCount(2, $this->rows(1));
            $this->assertSame(1, (int) $observer->query('SELECT COUNT(*) AS n FROM audit_events WHERE tenant_id=1')->getRowArray()['n']);
            $this->assertSame('Synthetic tenant 1', $observer->query('SELECT name FROM tenants WHERE id=1')->getRowArray()['name']);
            $this->assertTrue($this->db->transRollback());
            $this->assertCount(1, $this->rows(1));
            $this->assertSame('Synthetic tenant 1', $this->db->query('SELECT name FROM tenants WHERE id=1')->getRowArray()['name']);
        } finally {
            $observer->close();
        }
    }

    public function testSqlFailureRollsBackOwnedTransactionAndCallerCanRollback(): void
    {
        $this->db->query("CREATE TRIGGER audit_isolation_failure AFTER INSERT ON audit_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic audit SQL failure'");
        $before = $this->rows(1);
        $this->rejected(fn () => $this->append(), 'Unable to persist audit event');
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame($before, $this->rows(1));
        $observer = $this->observer();
        try {
            $this->assertTrue($observer->transBegin());
            $this->assertNotFalse($observer->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE'));
            $observer->transRollback();
            $this->assertTrue($this->db->transBegin());
            $this->db->query("UPDATE tenants SET name='Must rollback' WHERE id=1");
            $this->rejected(fn () => $this->append(), 'Unable to persist audit event');
            $this->assertSame(1, $this->db->transDepth);
            $this->db->transRollback();
            $this->db->resetTransStatus();
            $this->assertSame($before, $this->rows(1));
            $this->assertSame('Synthetic tenant 1', $observer->query('SELECT name FROM tenants WHERE id=1')->getRowArray()['name']);
        } finally {
            $observer->close();
            $this->db->query('DROP TRIGGER audit_isolation_failure');
        }
        $this->append();
        $this->assertChain(1, 2);
    }

    public function testBeforeInsertHashTamperingIsRefusedAndRolledBack(): void
    {
        $before = $this->rows(1);
        $this->db->query("CREATE TRIGGER audit_isolation_tamper_hash BEFORE INSERT ON audit_events
            FOR EACH ROW SET NEW.curr_hash = REPEAT('f', 64)");
        try {
            $this->rejected(fn () => $this->append(), 'Stored audit event differs from its hash. Append refused.');
            $this->assertSame(0, $this->db->transDepth);
            $this->assertTrue($this->db->transStatus());
            $this->assertSame($before, $this->rows(1));
            $observer = $this->observer();
            try {
                $this->assertSame($before, $observer->query('SELECT * FROM audit_events WHERE tenant_id=1 ORDER BY id')->getResultArray());
                $this->assertTrue($observer->transBegin());
                $this->assertNotFalse($observer->query('SELECT id FROM tenants WHERE id=1 FOR UPDATE'));
                $this->assertTrue($observer->transRollback());
            } finally {
                $observer->close();
            }
            $this->assertChain(1, 1);
        } finally {
            $this->db->query('DROP TRIGGER IF EXISTS audit_isolation_tamper_hash');
        }
        $event = $this->append();
        $this->assertSame($this->legacy['curr_hash'], $event['prev_hash']);
        $this->assertSame(AuditHash::compute($event), $event['curr_hash']);
        $this->assertChain(1, 2);
    }

    public function testRepeatableReadSnapshotUsesCurrentTailForAppend(): void
    {
        $writer = $this->observer();
        try {
            $this->db->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->assertTrue($this->db->transBegin());
            $snapshot = $this->rows(1);
            $committed = $this->append($writer, 'outside-snapshot');
            $this->assertSame($snapshot, $this->rows(1), 'Prove the caller really holds a stale consistent snapshot');
            $event = $this->append(null, 'inside-snapshot');
            $this->assertSame($committed['curr_hash'], $event['prev_hash']);
            $this->assertSame(1, $this->db->transDepth);
            $this->assertTrue($this->db->transCommit());
            $this->assertChain(1, 3);
        } finally {
            $writer->close();
        }
    }

    public function testTwoWorkersSerializeBehindTenantLockOnExistingChain(): void
    {
        $this->race(1, 1);
    }

    public function testTwoWorkersSerializeGenesisOnEmptyTenant(): void
    {
        $this->race(2, 0);
    }

    public function testAnnouncementWrappersLockBeforeBusinessCallback(): void
    {
        $this->engagementFixtures();
        $this->race(1, 1, ['announcement', 'announcement']);
    }

    public function testSurveyWrappersLockBeforeBusinessCallback(): void
    {
        $this->engagementFixtures();
        $this->race(1, 1, ['survey', 'survey']);
    }

    public function testAnnouncementAndSurveyShareTenantFirstLockOrder(): void
    {
        $this->engagementFixtures();
        $this->race(1, 1, ['announcement', 'survey']);
    }

    private function engagementFixtures(): void
    {
        $this->db->query("CREATE TABLE users (
            id BIGINT UNSIGNED NOT NULL PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
            person_id BIGINT UNSIGNED NULL, username VARCHAR(100) NOT NULL, email VARCHAR(254) NULL,
            role VARCHAR(50) NOT NULL, status VARCHAR(20) NOT NULL, auth_version INT NOT NULL,
            deleted_at DATETIME NULL
        ) ENGINE=InnoDB");
        $this->db->query('CREATE TABLE permissions (id INT UNSIGNED NOT NULL PRIMARY KEY, code VARCHAR(100) NOT NULL) ENGINE=InnoDB');
        $this->db->query('CREATE TABLE role_permissions (role VARCHAR(50) NOT NULL, permission_id INT UNSIGNED NOT NULL, PRIMARY KEY(role, permission_id)) ENGINE=InnoDB');
        $this->db->query("INSERT INTO users (id, tenant_id, person_id, username, role, status, auth_version)
            VALUES (7, 1, NULL, 'synthetic-superadmin', 'superadmin', 'active', 3)");
        $this->db->query("INSERT INTO permissions VALUES (1, 'isolation.authoritative')");
        $this->db->query("INSERT INTO role_permissions VALUES ('superadmin', 1)");
        $this->db->query('CREATE TABLE audit_isolation_business (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_id INT UNSIGNED NOT NULL, label VARCHAR(32) NOT NULL, wrapper VARCHAR(32) NOT NULL,
            FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT
        ) ENGINE=InnoDB');
    }

    public function testPublicTenantLockRequiresTransactionAndLeavesOwnershipWithCaller(): void
    {
        $audit = new AuditService(null, $this->db);
        $this->rejected(fn () => $audit->lockTenant(1), 'requires an active supported transaction');
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transBegin());
        $audit->lockTenant(1);
        $this->assertSame(1, $this->db->transDepth);
        $this->append();
        $this->assertSame(1, $this->db->transDepth);
        $this->assertTrue($this->db->transRollback());
        $this->assertChain(1, 1);
    }

    public function testUnsignedBigintActorsRoundtripWithoutPrecisionLoss(): void
    {
        $actors = ['9223372036854775808', '9223372036854775809', '18446744073709551615'];
        $ids = ['2', '3', '4'];
        $audit = new AuditService(null, $this->db);
        $events = [];
        foreach ($actors as $i => $actor) {
            $event = $audit->log('isolation.bigint', 'synthetic', 'same-entity', 'create', [], $actor,
                'synthetic-role', '127.0.0.1');
            $this->assertSame($actor, (string) $event['actor_user_id']);
            $this->assertSame($ids[$i], (string) $event['id']);
            $stored = $this->db->query('SELECT * FROM audit_events WHERE id=?', [$ids[$i]])->getRowArray();
            $this->assertSame($actor, (string) $stored['actor_user_id']);
            $this->assertSame(AuditHash::compute($stored), $stored['curr_hash']);
            $events[] = $stored;
        }
        // Hold every other field fixed: adjacent actors above PHP_INT_MAX must hash distinctly.
        $digests = [];
        foreach ($actors as $actor) {
            $event = array_replace($events[0], ['actor_user_id' => $actor]);
            $expected = hash('sha256', json_encode([
                'format' => 'humus.audit.v2', 'hash_version' => 2, 'tenant_id' => 1,
                'prev_hash' => $event['prev_hash'], 'event_type' => 'isolation.bigint',
                'entity_type' => 'synthetic', 'entity_id' => 'same-entity', 'actor_user_id' => $actor,
                'actor_role' => 'synthetic-role', 'actor_ip' => '127.0.0.1', 'action' => 'create',
                'occurred_at' => $event['occurred_at'], 'payload_json' => null,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $digests[] = AuditHash::compute($event);
            $this->assertSame($expected, end($digests));
            $this->assertSame($expected, AuditHash::compute(array_replace($event, ['actor_user_id' => '00' . $actor])));
        }
        $this->assertCount(3, array_unique($digests));
        $this->assertSame(AuditHash::compute(array_replace($events[0], ['actor_user_id' => 17])),
            AuditHash::compute(array_replace($events[0], ['actor_user_id' => '00017'])));
        $this->assertChain(1, 4);
        $tampered = $this->rows(1);
        $tampered[2]['actor_user_id'] = $actors[0];
        $this->assertSame(['valid' => false, 'verified_count' => 2, 'broken_id' => (int) $ids[1]], AuditHash::verify($tampered, 1));
    }

    public function testFullUnsignedRowIdsVerifyInDatabaseOrderAndReportExactBrokenId(): void
    {
        $template = $this->append();
        $previous = AuditHash::GENESIS;
        $ids = ['9223372036854775808', '9223372036854775809', '18446744073709551615'];
        foreach ($ids as $id) {
            $event = array_replace($template, ['id' => $id, 'tenant_id' => 2, 'prev_hash' => $previous,
                'actor_user_id' => '18446744073709551615']);
            $event['curr_hash'] = AuditHash::compute($event);
            // Builder insert avoids the framework's int-only insertID() API; verify reads real BIGINTs.
            $this->assertTrue($this->db->table('audit_events')->insert($event));
            $previous = $event['curr_hash'];
        }
        $this->assertChain(2, 3);
        $stored = $this->rows(2);
        $this->assertSame($ids, array_map(static fn ($event) => (string) $event['id'], $stored));
        $stored[2]['actor_user_id'] = '18446744073709551614';
        $this->assertSame(['valid' => false, 'verified_count' => 2, 'broken_id' => $ids[2]],
            AuditHash::verify($stored, 2));
        $outOfOrder = [$stored[1], $stored[0]];
        $outOfOrder[0]['prev_hash'] = AuditHash::GENESIS;
        $outOfOrder[0]['curr_hash'] = AuditHash::compute($outOfOrder[0]);
        $outOfOrder[1]['prev_hash'] = $outOfOrder[0]['curr_hash'];
        $outOfOrder[1]['curr_hash'] = AuditHash::compute($outOfOrder[1]);
        $this->assertSame(['valid' => false, 'verified_count' => 1, 'broken_id' => $ids[0]],
            AuditHash::verify($outOfOrder, 2));
    }

    public function testServiceAppendSupportsRowIdAboveSignedIntegerRange(): void
    {
        $this->db->query('ALTER TABLE audit_events AUTO_INCREMENT=9223372036854775808');
        $event = $this->append();
        $this->assertSame('9223372036854775808', (string) $event['id']);
        $this->assertChain(1, 2);
    }

    private function workerMessage(array $worker): array
    {
        $line = fgets($worker['pipes'][1]);
        $message = json_decode((string) $line, true);
        if (is_array($message)) {
            return $message;
        }
        stream_set_blocking($worker['pipes'][1], false);
        stream_set_blocking($worker['pipes'][2], false);
        $this->fail('Invalid audit worker response: ' . var_export($line, true)
            . stream_get_contents($worker['pipes'][1]) . '; stderr: ' . stream_get_contents($worker['pipes'][2]));
    }

    private function race(int $tenant, int $initialCount, array $modes = ['append', 'append']): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open required for real audit concurrency tests.');
        }
        $workers = [];
        try {
            $this->assertCount($initialCount, $this->rows($tenant));
            $this->assertTrue($this->db->transBegin());
            $this->db->query('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$tenant]);
            for ($i = 0; $i < 2; ++$i) {
                $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
                foreach (['mysqli', 'mbstring', 'intl'] as $extension) {
                    $command[] = '-d';
                    $command[] = 'extension=' . $extension;
                }
                $command[] = dirname(__DIR__) . '/_support/audit-race-worker.php';
                $command[] = $modes[$i];
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = ['process' => $process, 'pipes' => $pipes];
                stream_set_timeout($pipes[1], 10);
                $ready = $this->workerMessage($workers[$i]);
                $this->assertArrayHasKey('ready', $ready);
                $workers[$i]['connection'] = (int) $ready['ready'];
            }
            $this->assertNotSame($workers[0]['connection'], $workers[1]['connection']);
            foreach ($workers as $i => $worker) {
                fwrite($worker['pipes'][0], json_encode(['operation' => 'append', 'tenant' => $tenant,
                    'label' => $i === 0 ? 'worker-a' : 'worker-b'], JSON_THROW_ON_ERROR) . "\n");
                fflush($worker['pipes'][0]);
            }
            $deadline = microtime(true) + 3;
            do {
                $queries = $this->db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)',
                    [$workers[0]['connection'], $workers[1]['connection']])->getResultArray();
                $blocked = count(array_filter($queries, static fn ($row) =>
                    preg_match('/SELECT id FROM `?tenants`? WHERE id = .*FOR UPDATE/i', (string) $row['INFO']) === 1));
                if ($blocked === 2) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both children must be blocked at the tenant mutex, before reading the tail');
            $this->assertCount($initialCount, $this->rows($tenant));
            if ($modes !== ['append', 'append']) {
                $identity = \QueueIsolatedSafety::environment();
                foreach ($workers as $worker) {
                    $marker = dirname($identity['directory']) . '/audit-callback-' . $worker['connection'];
                    clearstatcache(true, $marker);
                    $this->assertFileDoesNotExist($marker,
                        'Neither wrapper may enter its callback before acquiring the tenant mutex');
                }
                $this->assertSame(0, (int) $this->db->table('audit_isolation_business')->countAllResults());
            }
            $this->assertTrue($this->db->transCommit());
            $events = [];
            foreach ($workers as $i => &$worker) {
                if ($modes[$i] !== 'append') {
                    $entry = $this->workerMessage($worker);
                    $this->assertSame(['callback_entered' => $modes[$i], 'actor' => 7], $entry);
                    $marker = dirname($identity['directory']) . '/audit-callback-' . $worker['connection'];
                    $this->assertSame($modes[$i], file_get_contents($marker));
                }
                $response = $this->workerMessage($worker);
                $this->assertArrayHasKey('event', $response);
                $events[] = $response['event'];
                foreach ($worker['pipes'] as $pipe) {
                    fclose($pipe);
                }
                $exit = proc_close($worker['process']);
                $worker['closed'] = true;
                $this->assertSame(0, $exit);
            }
            unset($worker);
            usort($events, static fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);
            $this->assertNotSame($events[0]['id'], $events[1]['id']);
            $this->assertNotSame($events[0]['prev_hash'], $events[1]['prev_hash']);
            $this->assertSame($initialCount === 0 ? AuditHash::GENESIS : $this->legacy['curr_hash'], $events[0]['prev_hash']);
            $this->assertSame($events[0]['curr_hash'], $events[1]['prev_hash']);
            foreach ($events as $event) {
                $this->assertSame($tenant, (int) $event['tenant_id']);
                $this->assertSame(2, (int) $event['hash_version']);
                $this->assertSame(AuditHash::compute($event), $event['curr_hash']);
            }
            $this->assertChain($tenant, $initialCount + 2);
            if ($modes !== ['append', 'append']) {
                $business = $this->db->table('audit_isolation_business')->orderBy('label')->get()->getResultArray();
                $this->assertCount(2, $business);
                foreach ($business as $i => $row) {
                    $this->assertSame($tenant, (int) $row['tenant_id']);
                    $this->assertSame($i === 0 ? 'worker-a' : 'worker-b', $row['label']);
                    $this->assertSame($modes[$i], $row['wrapper']);
                }
                foreach ($events as $event) {
                    $this->assertSame('7', (string) $event['actor_user_id']);
                }
            }
        } finally {
            if ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            foreach ($workers as $worker) {
                if (!empty($worker['closed'])) {
                    continue;
                }
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                proc_close($worker['process']);
            }
        }
    }
}
