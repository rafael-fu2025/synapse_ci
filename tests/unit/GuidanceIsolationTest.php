<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\HardenGuidanceNumbering;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/_support/queue-race-worker.php';
require_once dirname(__DIR__, 2) . '/app/Database/Migrations/2026-10-19-000004_HardenGuidanceNumbering.php';

/** Disposable real MariaDB fixtures only; no DatabaseTestTrait or operational migrations. */
final class GuidanceIsolationTest extends TestCase
{
    private ?BaseConnection $db = null;
    private GuidanceCaseService $service;
    private array $savedVersion = [];
    private const TABLES = ['guidance_followups', 'counselling_notes', 'counselling_sessions',
        'counselling_cases', 'queue_entries', 'appointments', 'employee_profiles', 'student_profiles', 'users', 'people', 'tenants'];

    protected function setUp(): void
    {
        parent::setUp();
        try {
            \QueueIsolatedSafety::environment();
            if (!in_array(realpath(dirname(__DIR__) . '/_support/queue-isolated-bootstrap.php'),
                array_map('realpath', get_included_files()), true)) {
                throw new \RuntimeException('Isolated bootstrap required.');
            }
            \QueueIsolatedSafety::configuration();
        } catch (\RuntimeException $e) {
            $this->markTestSkipped($e->getMessage());
        }
        $this->db = \Config\Database::connect();
        \QueueIsolatedSafety::connection($this->db);
        $this->savedVersion = [getenv('COUNSELLING_KEY_VERSION'), $_ENV['COUNSELLING_KEY_VERSION'] ?? null,
            $_SERVER['COUNSELLING_KEY_VERSION'] ?? null];
        putenv('COUNSELLING_KEY_VERSION=1');
        $_ENV['COUNSELLING_KEY_VERSION'] = $_SERVER['COUNSELLING_KEY_VERSION'] = '1';
        CurrentTenant::set(1);
        $this->schema();
        $this->migration()->up();
        $this->service = new GuidanceCaseService(encryptionService: new EncryptionService(str_repeat('ab', 32)), db: $this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            while ($this->db->transDepth > 0) {
                $this->db->transRollback();
            }
            $this->db->resetTransStatus();
            foreach (['guidance_fail_insert', 'guidance_fail_delete'] as $trigger) {
                $this->db->query('DROP TRIGGER IF EXISTS ' . $trigger);
            }
            foreach (self::TABLES as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $table);
            }
            [$env, $local, $server] = $this->savedVersion;
            putenv($env === false ? 'COUNSELLING_KEY_VERSION' : 'COUNSELLING_KEY_VERSION=' . $env);
            if ($local === null) { unset($_ENV['COUNSELLING_KEY_VERSION']); } else { $_ENV['COUNSELLING_KEY_VERSION'] = $local; }
            if ($server === null) { unset($_SERVER['COUNSELLING_KEY_VERSION']); } else { $_SERVER['COUNSELLING_KEY_VERSION'] = $server; }
            CurrentTenant::reset();
        }
        parent::tearDown();
    }

    private function migration(): HardenGuidanceNumbering
    {
        return new HardenGuidanceNumbering(\Config\Database::forge($this->db));
    }

    private function schema(): void
    {
        $definitions = [
            'tenants' => 'id INT UNSIGNED PRIMARY KEY, name VARCHAR(100) NOT NULL',
            'people' => "id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
                kind ENUM('student','employee','external') NOT NULL, mis_id VARCHAR(64) NOT NULL,
                first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id)",
            'users' => "id BIGINT UNSIGNED PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
                person_id BIGINT UNSIGNED NULL, username VARCHAR(100) NOT NULL, role VARCHAR(40) NOT NULL,
                status VARCHAR(20) NOT NULL, deleted_at DATETIME NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (person_id) REFERENCES people(id)",
            'student_profiles' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                person_id BIGINT UNSIGNED NOT NULL, program VARCHAR(100), year_level INT, section VARCHAR(50),
                department VARCHAR(100), enrollment_type VARCHAR(30), is_graduating TINYINT DEFAULT 0,
                created_at DATETIME, updated_at DATETIME, FOREIGN KEY (tenant_id) REFERENCES tenants(id),
                FOREIGN KEY (person_id) REFERENCES people(id)',
            'employee_profiles' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                person_id BIGINT UNSIGNED NOT NULL, department VARCHAR(100), position VARCHAR(100),
                created_at DATETIME, updated_at DATETIME, FOREIGN KEY (tenant_id) REFERENCES tenants(id),
                FOREIGN KEY (person_id) REFERENCES people(id)',
            'appointments' => "id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                unit VARCHAR(20) NOT NULL, person_id BIGINT UNSIGNED NOT NULL, provider_user_id BIGINT UNSIGNED NULL,
                staff_assignment_id BIGINT UNSIGNED NULL, starts_at DATETIME, ends_at DATETIME, local_date DATE,
                status VARCHAR(30) NOT NULL, type VARCHAR(30) DEFAULT 'routine', version INT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME, updated_at DATETIME, deleted_at DATETIME,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (person_id) REFERENCES people(id)",
            'queue_entries' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                unit VARCHAR(20) NOT NULL, person_id BIGINT UNSIGNED NOT NULL, appointment_id BIGINT UNSIGNED NULL,
                queue_date DATE NOT NULL, ticket_no INT UNSIGNED NOT NULL, ticket_code VARCHAR(30) NOT NULL,
                status VARCHAR(30) NOT NULL, priority VARCHAR(20) NOT NULL,
                called_at DATETIME, called_by_user_id BIGINT UNSIGNED NULL, started_at DATETIME,
                completed_at DATETIME, skip_until DATETIME, created_at DATETIME, updated_at DATETIME,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (person_id) REFERENCES people(id),
                FOREIGN KEY (appointment_id) REFERENCES appointments(id)',
            'counselling_cases' => "id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                person_id BIGINT UNSIGNED NOT NULL, counsellor_user_id BIGINT UNSIGNED NOT NULL,
                case_number VARCHAR(32) NOT NULL, title VARCHAR(255) NOT NULL,
                category ENUM('academic','personal','career','crisis','behavioral','family') NOT NULL DEFAULT 'personal',
                status ENUM('open','active','monitoring','closed') NOT NULL DEFAULT 'open',
                opened_at DATETIME NOT NULL, closed_at DATETIME, summary_notes TEXT,
                created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deleted_at DATETIME,
                UNIQUE KEY uniq_tenant_case_num (tenant_id, case_number),
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (person_id) REFERENCES people(id),
                FOREIGN KEY (counsellor_user_id) REFERENCES users(id)",
            'counselling_sessions' => "id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                case_id BIGINT UNSIGNED NOT NULL, appointment_id BIGINT UNSIGNED NULL, queue_entry_id BIGINT UNSIGNED NULL,
                session_number INT UNSIGNED NOT NULL DEFAULT 1,
                session_type ENUM('intake','regular','crisis','follow_up','termination') NOT NULL DEFAULT 'regular',
                counsellor_user_id BIGINT UNSIGNED NOT NULL,
                status ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
                started_at DATETIME NOT NULL, ended_at DATETIME, created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL, deleted_at DATETIME,
                INDEX idx_session_case (tenant_id, case_id, session_number),
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (case_id) REFERENCES counselling_cases(id),
                FOREIGN KEY (counsellor_user_id) REFERENCES users(id)",
            'counselling_notes' => 'id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                session_id BIGINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1,
                supersedes_note_id BIGINT UNSIGNED NULL, is_draft TINYINT NOT NULL DEFAULT 0,
                notes_cipher VARBINARY(8192) NOT NULL, notes_nonce BINARY(12) NOT NULL, notes_tag BINARY(16) NOT NULL,
                notes_key_version TINYINT UNSIGNED NOT NULL DEFAULT 1, created_by_user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL, INDEX idx_notes_session_ver (tenant_id, session_id, version),
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (session_id) REFERENCES counselling_sessions(id),
                FOREIGN KEY (created_by_user_id) REFERENCES users(id)',
            'guidance_followups' => "id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                case_id BIGINT UNSIGNED NOT NULL, session_id BIGINT UNSIGNED NULL, due_date DATE NOT NULL,
                action_plan TEXT NOT NULL, status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
                completed_at DATETIME, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                FOREIGN KEY (tenant_id) REFERENCES tenants(id), FOREIGN KEY (case_id) REFERENCES counselling_cases(id),
                FOREIGN KEY (session_id) REFERENCES counselling_sessions(id)",
        ];
        foreach ($definitions as $table => $columns) {
            $this->db->query('CREATE TABLE ' . $table . ' (' . $columns . ') ENGINE=InnoDB');
        }
        foreach ([1, 2] as $tenant) {
            $this->insert('tenants', ['id' => $tenant, 'name' => 'Synthetic tenant ' . $tenant]);
        }
        foreach ([[1, 1, 'student'], [2, 1, 'employee'], [3, 2, 'student']] as [$id, $tenant, $kind]) {
            $this->insert('people', ['id' => $id, 'tenant_id' => $tenant, 'kind' => $kind,
                'mis_id' => 'SYNTH-' . $id, 'first_name' => 'Fixture', 'last_name' => 'Person ' . $id]);
            $this->insert($kind . '_profiles', ['tenant_id' => $tenant, 'person_id' => $id]);
        }
        foreach ([[7, 1, 'counsellor', 'active'], [8, 2, 'counsellor', 'active'],
            [9, 1, 'student', 'active'], [10, 1, 'counsellor', 'inactive']] as [$id, $tenant, $role, $status]) {
            $this->insert('users', ['id' => $id, 'tenant_id' => $tenant, 'username' => 'synthetic-' . $id,
                'role' => $role, 'status' => $status]);
        }
    }

    private function insert(string $table, array $data): int
    {
        $this->assertTrue($this->db->table($table)->insert($data));
        return (int) $this->db->insertID();
    }

    private function row(string $table, int $id): array
    {
        return $this->db->table($table)->where('id', $id)->get()->getRowArray();
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->db->table($table)->orderBy('id')->get()->getResultArray();
        }
        return $rows;
    }

    private function rejected(callable $operation, ?int $code = null): void
    {
        try { $operation(); } catch (\Throwable $e) {
            if ($code !== null) { $this->assertSame($code, $e->getCode(), $e->getMessage()); }
            return;
        }
        $this->fail('Expected guidance operation to fail.');
    }

    private function caseId(int $person = 1, int $actor = 7): int
    {
        return (int) $this->service->createCase($person, $actor, 'Synthetic guidance case')['case']['id'];
    }

    private function sessionId(?int $case = null): int
    {
        return (int) $this->service->openSession($case ?? $this->caseId(), 7)['id'];
    }

    private function ticket(array $overrides = []): int
    {
        return $this->insert('queue_entries', array_replace(['tenant_id' => 1, 'unit' => 'guidance',
            'person_id' => 1, 'queue_date' => date('Y-m-d'), 'ticket_no' => 1, 'ticket_code' => 'G-001',
            'status' => 'called', 'priority' => 'routine', 'called_by_user_id' => 7], $overrides));
    }

    private function appointment(array $overrides = []): int
    {
        return $this->insert('appointments', array_replace(['tenant_id' => 1, 'unit' => 'guidance',
            'person_id' => 1, 'status' => 'checked_in', 'version' => 11], $overrides));
    }

    public function testCaseProfilesSessionAndEncryptedFullHistory(): void
    {
        $case = $this->caseId();
        $employee = $this->caseId(2);
        $this->assertNotNull($this->service->getCaseDetails($case)['student']);
        $this->assertNotNull($this->service->getCaseDetails($employee)['employee']);
        $this->assertNotSame($this->row('counselling_cases', $case)['case_number'], $this->row('counselling_cases', $employee)['case_number']);
        $session = $this->sessionId($case);
        $this->assertSame('active', $this->row('counselling_cases', $case)['status']);
        $this->assertSame(1, (int) $this->service->getSession($session)['session_number']);
        $draft = $this->service->saveDraftNotes($session, 'Draft — synthetic 日本語', 7);
        $this->assertSame(0, (int) $draft['version']);
        $this->assertSame('Draft — synthetic 日本語', $this->service->getDecryptedSessionNote($session)['text']);
        $this->assertNotSame('Draft — synthetic 日本語', $draft['notes_cipher']);
        $previous = null;
        $crypto = new EncryptionService(str_repeat('ab', 32));
        for ($version = 1; $version <= 3; ++$version) {
            $text = 'Final ' . $version . ' — synthetic 日本語';
            $note = $this->service->finalizeSessionNotes($session, $text, 7);
            $this->assertSame($version, (int) $note['version']);
            $this->assertSame($previous, $note['supersedes_note_id'] === null ? null : (int) $note['supersedes_note_id']);
            $this->assertSame($text, $crypto->decrypt($note['notes_cipher'], $note['notes_nonce'], $note['notes_tag'], (int) $note['notes_key_version']));
            $this->assertSame($text, $this->service->getDecryptedSessionNote($session)['text']);
            $this->assertFalse($this->service->getDecryptedSessionNote($session)['is_draft']);
            $this->assertSame(12, strlen($note['notes_nonce']));
            $this->assertSame(16, strlen($note['notes_tag']));
            $previous = (int) $note['id'];
        }
        $this->assertSame(0, $this->db->table('counselling_notes')->where('is_draft', 1)->countAllResults());
        $followup = $this->service->addFollowup($case, $session, '2037-04-23', 'Synthetic plan');
        $this->assertSame('completed', $this->service->completeFollowup((int) $followup['id'])['status']);
        $this->assertSame('completed', $this->service->closeSession($session)['status']);
        $this->assertSame('closed', $this->service->closeCase($case)['case']['status']);
    }

    public function testDeletedSessionRetainsItsNumberAndDatabaseUniqueness(): void
    {
        $case = $this->caseId();
        $id = $this->sessionId($case);
        $this->db->table('counselling_sessions')->where('id', $id)->update(['deleted_at' => '2001-01-01 00:00:00']);
        $next = $this->service->openSession($case, 7);
        $this->assertSame(2, (int) $next['session_number']);
        $this->rejected(fn () => $this->db->table('counselling_sessions')->where('id', $next['id'])->update(['session_number' => 1]));
        $this->assertSame(2, (int) $this->row('counselling_sessions', (int) $next['id'])['session_number']);
    }

    public function testFinalizeDraftDeletionFailureRollsBackInsertedFinal(): void
    {
        $session = $this->sessionId();
        $this->service->finalizeSessionNotes($session, 'Existing final', 7);
        $this->service->saveDraftNotes($session, 'Preserved draft', 7);
        $before = $this->snapshot();
        $this->db->query("CREATE TRIGGER guidance_fail_delete BEFORE DELETE ON counselling_notes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic draft deletion failure'");
        $this->rejected(fn () => $this->service->finalizeSessionNotes($session, 'Must roll back', 7));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('Preserved draft', $this->service->getDecryptedSessionNote($session)['text']);
        $this->assertSame(1, $this->db->table('counselling_notes')->where('is_draft', 0)->countAllResults());
        $this->assertSame(0, $this->db->transDepth);
    }

    public function testCrossTenantOperationsReturn404WithoutChanges(): void
    {
        $case = $this->caseId();
        $session = $this->sessionId($case);
        $this->service->saveDraftNotes($session, 'Tenant one private', 7);
        $followup = (int) $this->service->addFollowup($case, $session, '2037-04-23', 'Synthetic')['id'];
        $ticket = $this->ticket();
        CurrentTenant::set(2);
        $before = $this->snapshot();
        foreach ([fn () => $this->service->getCaseDetails($case), fn () => $this->service->getSession($session),
            fn () => $this->service->getDecryptedSessionNote($session),
            fn () => $this->service->saveDraftNotes($session, 'Forbidden', 8),
            fn () => $this->service->finalizeSessionNotes($session, 'Forbidden', 8),
            fn () => $this->service->openSession($case, 8), fn () => $this->service->closeSession($session),
            fn () => $this->service->closeCase($case),
            fn () => $this->service->addFollowup($case, null, '2037-04-23', 'Forbidden'),
            fn () => $this->service->completeFollowup($followup),
            fn () => $this->service->openSessionForTicket($ticket, 8, true)] as $operation) {
            $this->rejected($operation, 404);
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function testInvalidActorsAndRelatedPatientSessionOrClinicQueueAreRejected(): void
    {
        $case = $this->caseId();
        $session = $this->sessionId($case);
        $otherCase = $this->caseId(2);
        $otherSession = $this->sessionId($otherCase);
        $wrongPatient = $this->ticket(['person_id' => 2]);
        $clinic = $this->ticket(['unit' => 'clinic']);
        $wrongAppointment = $this->appointment(['person_id' => 2]);
        $before = $this->snapshot();
        foreach ([8, 9, 10] as $actor) {
            $this->rejected(fn () => $this->service->createCase(1, $actor, 'Forbidden'), 403);
            $this->rejected(fn () => $this->service->openSession($case, $actor), 403);
            $this->rejected(fn () => $this->service->saveDraftNotes($session, 'Forbidden', $actor), 403);
            $this->rejected(fn () => $this->service->finalizeSessionNotes($session, 'Forbidden', $actor), 403);
        }
        $this->rejected(fn () => $this->service->createCase(3, 7, 'Foreign patient'), 404);
        $this->rejected(fn () => $this->service->addFollowup($case, $otherSession, '2037-04-23', 'Wrong session'), 422);
        $this->rejected(fn () => $this->service->openSession($case, 7, 'regular', $wrongPatient), 422);
        $this->rejected(fn () => $this->service->openSession($case, 7, 'regular', $clinic), 404);
        $this->rejected(fn () => $this->service->openSessionForTicket($clinic, 7), 404);
        $this->rejected(fn () => $this->service->openSession($case, 7, 'regular', null, $wrongAppointment), 422);
        $this->assertSame($before, $this->snapshot());
    }

    public function testQueueIntakeFailureLeavesNoOrphanThenRetryResumesSameSession(): void
    {
        $ticket = $this->ticket();
        $before = $this->snapshot();
        $this->db->query("CREATE TRIGGER guidance_fail_insert BEFORE INSERT ON counselling_sessions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='synthetic session insert failure'");
        $this->rejected(fn () => $this->service->openSessionForTicket($ticket, 7));
        $this->assertSame($before, $this->snapshot());
        $this->db->query('DROP TRIGGER guidance_fail_insert');
        $session = $this->service->openSessionForTicket($ticket, 7);
        $this->assertSame((int) $session['id'], (int) $this->service->openSessionForTicket($ticket, 7)['id']);
        $this->assertSame(1, $this->db->table('counselling_cases')->countAllResults());
        $this->assertSame(1, $this->db->table('counselling_sessions')->countAllResults());
        $this->assertSame('in_consultation', $this->row('queue_entries', $ticket)['status']);
    }

    public function testCloseCompletesLinkedAppointmentExactlyOnce(): void
    {
        $appointment = $this->appointment();
        $ticket = $this->ticket(['appointment_id' => $appointment]);
        $session = $this->service->openSessionForTicket($ticket, 7);
        $id = (int) $session['id'];
        $this->assertSame('completed', $this->service->closeSession($id)['status']);
        $this->assertSame('done', $this->row('queue_entries', $ticket)['status']);
        $this->assertSame('completed', $this->row('appointments', $appointment)['status']);
        $this->assertSame(12, (int) $this->row('appointments', $appointment)['version']);
        $before = $this->snapshot();
        $this->service->closeSession($id);
        $this->assertSame($before, $this->snapshot());
    }

    public function testCorruptQueueOrAppointmentLinkageCannotCloseSession(): void
    {
        $appointment = $this->appointment();
        $ticket = $this->ticket(['appointment_id' => $appointment]);
        $id = (int) $this->service->openSessionForTicket($ticket, 7)['id'];
        $this->db->table('queue_entries')->where('id', $ticket)->update(['person_id' => 2]);
        $before = $this->snapshot();
        $this->rejected(fn () => $this->service->closeSession($id), 409);
        $this->assertSame($before, $this->snapshot());
        $this->db->table('queue_entries')->where('id', $ticket)->update(['person_id' => 1]);
        $this->db->table('counselling_sessions')->where('id', $id)->update(['appointment_id' => null]);
        $before = $this->snapshot();
        $this->rejected(fn () => $this->service->closeSession($id), 409);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame('in_progress', $this->row('counselling_sessions', $id)['status']);
    }

    public function testCallerOwnsTransactionAndCanRollBackEntireChain(): void
    {
        $before = $this->snapshot();
        $this->db->transBegin();
        $case = $this->caseId();
        $session = $this->sessionId($case);
        $this->service->saveDraftNotes($session, 'Caller draft', 7);
        $this->service->finalizeSessionNotes($session, 'Caller final', 7);
        $followup = $this->service->addFollowup($case, $session, '2037-04-23', 'Caller followup');
        $this->service->completeFollowup((int) $followup['id']);
        $this->service->closeSession($session);
        $this->service->closeCase($case);
        $this->assertSame(1, $this->db->transDepth);
        \QueueIsolatedSafety::configuration();
        $observer = \Config\Database::connect('tests', false);
        try {
            \QueueIsolatedSafety::connection($observer);
            $this->assertSame(0, $observer->table('counselling_cases')->countAllResults());
            $this->assertSame(0, $observer->table('counselling_notes')->countAllResults());
        } finally { $observer->close(); }
        $this->db->transRollback();
        $this->assertSame($before, $this->snapshot());
    }

    public function testMigrationPreflightRejectsDirtyLegacyDataWithoutRewritesOrIndexes(): void
    {
        $session = $this->sessionId();
        $note = $this->service->saveDraftNotes($session, 'Synthetic legacy bytes', 7);
        $this->migration()->down();
        $scenarios = ['session', 'note', 'draft-positive', 'final-zero', 'invalid-flag'];
        foreach ($scenarios as $scenario) {
            $extra = null;
            if ($scenario === 'session') {
                $row = $this->row('counselling_sessions', $session);
                unset($row['id']);
                $row['deleted_at'] = '2001-01-01 00:00:00';
                $extra = $this->insert('counselling_sessions', $row);
            } elseif ($scenario === 'note') {
                $row = $note;
                unset($row['id']);
                $extra = $this->insert('counselling_notes', $row);
            } else {
                $changes = match ($scenario) {
                    'draft-positive' => ['version' => 1], 'final-zero' => ['is_draft' => 0],
                    'invalid-flag' => ['is_draft' => 2],
                };
                $this->db->table('counselling_notes')->where('id', $note['id'])->update($changes);
            }
            $before = $this->snapshot();
            $this->rejected(fn () => $this->migration()->up());
            $this->assertSame($before, $this->snapshot(), $scenario . ' rewrote legacy data');
            $this->assertSame(0, (int) $this->db->query("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('uniq_guidance_session_number','uniq_guidance_note_version')")->getRowArray()['n']);
            if ($extra !== null) {
                $this->db->table($scenario === 'session' ? 'counselling_sessions' : 'counselling_notes')->where('id', $extra)->delete();
            } else {
                $this->db->table('counselling_notes')->where('id', $note['id'])->update(['version' => 0, 'is_draft' => 1]);
            }
        }
        $before = $this->snapshot();
        // Emulate an interrupted DDL deployment; retry must keep the existing index.
        $this->db->query('ALTER TABLE counselling_sessions ADD UNIQUE INDEX uniq_guidance_session_number (tenant_id, case_id, session_number)');
        $this->migration()->up();
        $this->migration()->up();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(6, (int) $this->db->query("SELECT COUNT(*) AS n FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME IN ('uniq_guidance_session_number','uniq_guidance_note_version') AND NON_UNIQUE=0")->getRowArray()['n']);
    }

    private function workerMessage(array $worker): array
    {
        for ($i = 0; $i < 8; ++$i) {
            $line = fgets($worker['pipes'][1]);
            if ($line === false) { break; }
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) { return $decoded; }
        }
        $this->fail('Invalid guidance worker response: ' . substr((string) stream_get_contents($worker['pipes'][2]), 0, 2000));
    }

    /** Both children must be blocked by the owner's tenant mutex before either may proceed. */
    private function race(string $operation, int $id): array
    {
        $workers = [];
        try {
            $this->db->transBegin();
            $this->db->query('SELECT id FROM tenants WHERE id = 1 FOR UPDATE');
            for ($i = 0; $i < 2; ++$i) {
                $command = [PHP_BINARY, '-n', '-d', 'extension_dir=' . ini_get('extension_dir')];
                foreach (['mysqli', 'mbstring', 'intl', 'openssl'] as $extension) {
                    $command[] = '-d'; $command[] = 'extension=' . $extension;
                }
                $command[] = dirname(__DIR__) . '/_support/guidance-race-worker.php';
                $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[$i] = ['process' => $process, 'pipes' => $pipes];
                stream_set_timeout($pipes[1], 10);
                $message = $this->workerMessage($workers[$i]);
                $this->assertArrayHasKey('ready', $message);
                $workers[$i]['connection'] = (int) $message['ready'];
            }
            $this->assertNotSame($workers[0]['connection'], $workers[1]['connection']);
            foreach ($workers as $i => $worker) {
                fwrite($worker['pipes'][0], json_encode(['operation' => $operation, 'id' => $id,
                    'label' => $i === 0 ? 'worker-a' : 'worker-b'], JSON_THROW_ON_ERROR) . "\n");
                fflush($worker['pipes'][0]);
            }
            $deadline = microtime(true) + 3;
            do {
                $rows = $this->db->query('SELECT INFO FROM information_schema.PROCESSLIST WHERE ID IN (?, ?)',
                    [$workers[0]['connection'], $workers[1]['connection']])->getResultArray();
                $blocked = count(array_filter($rows, static fn ($row) => str_contains((string) $row['INFO'], 'tenants')
                    && str_contains((string) $row['INFO'], 'FOR UPDATE')));
                if ($blocked === 2) { break; }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both workers must overlap at the tenant mutex');
            $this->db->transCommit();
            $ids = [];
            foreach ($workers as &$worker) {
                $message = $this->workerMessage($worker);
                $this->assertArrayHasKey('id', $message);
                $ids[] = (int) $message['id'];
                foreach ($worker['pipes'] as $pipe) { fclose($pipe); }
                $code = proc_close($worker['process']);
                $worker['closed'] = true;
                $this->assertSame(0, $code);
            }
            unset($worker);
            return $ids;
        } finally {
            if ($this->db->transDepth > 0) { $this->db->transRollback(); }
            foreach ($workers as $worker) {
                if (!empty($worker['closed'])) { continue; }
                proc_terminate($worker['process']);
                foreach ($worker['pipes'] as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                proc_close($worker['process']);
            }
        }
    }

    public function testConcurrentSessionOpensAllocateOneAndTwo(): void
    {
        $case = $this->caseId();
        $ids = $this->race('open', $case);
        $this->assertCount(2, array_unique($ids));
        $rows = $this->db->table('counselling_sessions')->where('case_id', $case)->orderBy('session_number')->get()->getResultArray();
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'session_number')));
    }

    public function testConcurrentFinalNotesHaveOrderedVersionsAndOneSupersedesLink(): void
    {
        $session = $this->sessionId();
        $this->service->saveDraftNotes($session, 'Draft before race', 7);
        $ids = $this->race('final', $session);
        $this->assertCount(2, array_unique($ids));
        $rows = $this->db->table('counselling_notes')->where('session_id', $session)->orderBy('version')->get()->getResultArray();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'version')));
        $this->assertNull($rows[0]['supersedes_note_id']);
        $this->assertSame((int) $rows[0]['id'], (int) $rows[1]['supersedes_note_id']);
        $crypto = new EncryptionService(str_repeat('ab', 32));
        $texts = array_map(fn ($row) => $crypto->decrypt($row['notes_cipher'], $row['notes_nonce'], $row['notes_tag'], (int) $row['notes_key_version']), $rows);
        sort($texts);
        $this->assertSame(['worker-a', 'worker-b'], $texts);
    }

    public function testConcurrentDraftsKeepOneVersionZeroRow(): void
    {
        $session = $this->sessionId();
        $ids = $this->race('draft', $session);
        $this->assertSame($ids[0], $ids[1]);
        $rows = $this->db->table('counselling_notes')->where('session_id', $session)->get()->getResultArray();
        $this->assertCount(1, $rows);
        $this->assertSame(0, (int) $rows[0]['version']);
        $this->assertSame(1, (int) $rows[0]['is_draft']);
        $this->assertContains($this->service->getDecryptedSessionNote($session)['text'], ['worker-a', 'worker-b']);
    }
}
