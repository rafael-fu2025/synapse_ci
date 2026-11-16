<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\Audit\AuditService;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceEpisodeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/**
 * Synthetic private SQLite only; audit failures never touch a shared fixture ledger.
 * Run individually with --no-configuration --bootstrap tests/_support/guidance-read-bootstrap.php.
 */
final class GuidanceEpisodeDetailsAuthorizationTest extends TestCase
{
    private const CLOSURE_PLAINTEXT = 'Synthetic confidential episode closure';
    private const AUDIT_COLUMNS = 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT';

    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private array $domainTables = [];
    private array $readQueries = [];
    private GuidanceEpisodeService $service;
    private string $closureCipher;

    protected function setUp(): void
    {
        parent::setUp();
        $bootstrap = realpath(dirname(__DIR__) . '/_support/guidance-read-bootstrap.php');
        if (!defined('ENVIRONMENT') || ENVIRONMENT !== 'testing'
            || !in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
            $this->markTestSkipped('The no-dotenv, private SQLite guidance-read bootstrap is required.');
        }
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        }
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->savedTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'episode_details_', 'DBDebug' => true,
            'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        self::assertSame('SQLite3', $this->fixture->DBDriver);
        self::assertSame(':memory:', $this->fixture->database);
        // Even implicitly constructed collaborators must stay on this private connection.
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        CurrentTenant::set(1);
        $this->schema();
        $this->seed();
        $policy = new GuidanceAccessPolicy(identitySource: static fn (): array => ['id' => 1, 'tenant_id' => 1], db: $this->fixture);
        $this->service = new GuidanceEpisodeService(
            accessPolicy: $policy,
            encryptionService: new EncryptionService(str_repeat('ab', 32)),
            db: $this->fixture
        );
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
                ->setValue(null, $this->savedConnections);
            CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function schema(): void
    {
        $tables = [
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER, PRIMARY KEY(role, permission_id)',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER',
            'guidance_episodes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, case_id INTEGER, intake_referral_id INTEGER, status TEXT, opened_at TEXT, closed_at TEXT, primary_counselor_user_id INTEGER, closure_reason TEXT, closure_notes_cipher TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, case_number TEXT, title TEXT, category TEXT, status TEXT, opened_at TEXT, closed_at TEXT, summary_notes TEXT, closure_disposition_cipher BLOB, closure_disposition_nonce BLOB, closure_disposition_tag BLOB, closure_disposition_key_version INTEGER, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, session_number INTEGER, session_type TEXT, status TEXT, started_at TEXT, ended_at TEXT, appointment_id INTEGER, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, due_date TEXT, status TEXT',
            'guidance_intake_referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, subject_person_id INTEGER, linked_case_id INTEGER, referral_number TEXT, source_type TEXT, priority_hint TEXT, status TEXT, submitted_at TEXT, created_at TEXT',
            'guidance_appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, episode_id INTEGER, person_id INTEGER, counselor_user_id INTEGER, appointment_number TEXT, scheduled_date TEXT, scheduled_time TEXT, purpose TEXT, status TEXT, form_instance_id INTEGER, deleted_at TEXT',
            'guidance_call_slips' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, episode_id INTEGER, person_id INTEGER, issuing_counselor_user_id INTEGER, call_slip_number TEXT, call_date TEXT, call_time TEXT, purpose TEXT, attendance_status TEXT, parent_guardian_delivery INTEGER, form_instance_id INTEGER, deleted_at TEXT',
            'guidance_form_instances' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, episode_id INTEGER, case_id INTEGER, session_id INTEGER, form_definition_id INTEGER, definition_revision_id INTEGER, created_by_user_id INTEGER, instance_number TEXT, status TEXT, created_at TEXT, finalized_at TEXT',
        ];
        foreach ($tables as $table => $columns) {
            self::assertTrue($this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')'));
        }
        $this->domainTables = array_keys($tables);
        self::assertTrue($this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable('audit_events') . ' (' . self::AUDIT_COLUMNS . ')'));
    }

    private function seed(): void
    {
        (new PermissionsAndRolesSeeder(config('Database'), $this->fixture))->run();
        $this->insert('tenants', ['id' => 1, 'is_active' => 1]);
        foreach ([[10, 'student'], [101, 'employee'], [102, 'employee']] as [$id, $kind]) {
            $this->insert('people', ['id' => $id, 'tenant_id' => 1, 'kind' => $kind,
                'first_name' => 'Synthetic', 'last_name' => 'Person ' . $id]);
        }
        foreach ([[1, 101], [2, 102]] as [$id, $personId]) {
            $this->insert('users', ['id' => $id, 'tenant_id' => 1, 'person_id' => $personId,
                'username' => 'synthetic-episode-' . $id, 'role' => 'counsellor', 'status' => 'active']);
        }
        foreach ([[201, 1], [202, 2]] as [$caseId, $ownerId]) {
            $this->insert('counselling_cases', ['id' => $caseId, 'tenant_id' => 1, 'person_id' => 10,
                'counsellor_user_id' => $ownerId, 'case_number' => 'SYNTH-CASE-' . $caseId,
                'title' => 'Synthetic authorized case', 'category' => 'personal', 'status' => 'open',
                'opened_at' => '2026-01-01 09:00:00']);
            $this->insert('counselling_sessions', ['id' => $caseId + 100, 'tenant_id' => 1,
                'case_id' => $caseId, 'counsellor_user_id' => $ownerId, 'session_number' => 1,
                'session_type' => 'regular', 'status' => 'in_progress', 'started_at' => '2026-01-01 09:00:00']);
        }
        $encrypted = (new EncryptionService(str_repeat('ab', 32)))->encrypt(self::CLOSURE_PLAINTEXT);
        $this->closureCipher = json_encode([
            'cipher' => base64_encode($encrypted['cipher']),
            'nonce' => base64_encode($encrypted['nonce']),
            'tag' => base64_encode($encrypted['tag']),
            'key_version' => $encrypted['key_version'],
        ], JSON_THROW_ON_ERROR);
        $this->insert('guidance_episodes', ['id' => 101, 'tenant_id' => 1, 'person_id' => 10,
            'primary_counselor_user_id' => 1, 'status' => 'closed', 'opened_at' => '2026-01-01 09:00:00',
            'closed_at' => '2026-02-01 09:00:00', 'closure_reason' => 'Synthetic completion',
            'closure_notes_cipher' => $this->closureCipher, 'created_at' => '2026-01-01 09:00:00',
            'updated_at' => '2026-02-01 09:00:00']);
    }

    private function insert(string $table, array $row): void
    {
        self::assertTrue($this->fixture->table($table)->insert($row));
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->domainTables as $table) {
            $rows = array_map('serialize', $this->fixture->table($table)->get()->getResultArray());
            sort($rows, SORT_STRING);
            $snapshot[$table] = $rows;
        }
        return $snapshot;
    }

    private function protectDomain(): void
    {
        foreach ($this->domainTables as $table) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $operation) {
                // SQLite audit locking performs only this deliberate tenant no-op.
                $condition = $table === 'tenants' && $operation === 'UPDATE'
                    ? ' WHEN NEW.id IS NOT OLD.id OR NEW.is_active IS NOT OLD.is_active OR NEW.deleted_at IS NOT OLD.deleted_at' : '';
                $trigger = $this->fixture->prefixTable('deny_read_' . strtolower($operation) . '_' . $table);
                self::assertTrue($this->fixture->query('CREATE TRIGGER ' . $trigger . ' BEFORE ' . $operation
                    . ' ON ' . $this->fixture->prefixTable($table) . $condition
                    . " BEGIN SELECT RAISE(ABORT, 'Synthetic episode read domain write forbidden'); END"));
            }
        }
    }

    private function read(string $method = 'getEpisodeDetails'): array|string|null
    {
        $before = $this->snapshot();
        $this->protectDomain();
        $this->readQueries = [];
        $listener = function ($query): void { $this->readQueries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            return $method === 'getEpisodeDetails'
                ? $this->service->getEpisodeDetails(101, 1, ['id' => 1, 'tenant_id' => 1])
                : $this->service->getDecryptedClosureNotes(101, ['id' => 1, 'tenant_id' => 1], 1);
        } finally {
            Events::removeListener('DBQuery', $listener);
            foreach ($this->domainTables as $table) {
                foreach (['insert', 'update', 'delete'] as $operation) {
                    self::assertTrue($this->fixture->query('DROP TRIGGER '
                        . $this->fixture->prefixTable('deny_read_' . $operation . '_' . $table)));
                }
            }
            self::assertSame($before, $this->snapshot(), 'Successful and rejected episode reads must not mutate domain rows.');
            self::assertSame(0, $this->fixture->transDepth, 'Audit failures must not leave a transaction open.');
            self::assertTrue($this->fixture->transStatus(), 'The private connection must recover after audit failure.');
            $this->assertAuditOnlyWrites();
        }
    }

    private function assertAuditOnlyWrites(): void
    {
        self::assertNotEmpty($this->readQueries, 'Read-query observation must be active.');
        foreach ($this->readQueries as $query) {
            if (!preg_match('/^\s*(?:insert|update|delete|replace|create|alter|drop)\b/i', $query)) continue;
            self::assertTrue(preg_match('/^\s*insert\s+into\s+["`]?episode_details_audit_events\b/i', $query) === 1
                || preg_match('/^\s*update\s+["`]?episode_details_tenants["`]?\s+set\s+id\s*=\s*id\b/i', $query) === 1,
                'Only an audit append and its tenant no-op mutex may write during an episode read.');
        }
    }

    private function auditRows(): array
    {
        return $this->fixture->table('audit_events')->orderBy('id', 'ASC')->get()->getResultArray();
    }

    private function breakAudit(string $failure): void
    {
        if ($failure === 'missing_table') {
            self::assertTrue($this->fixture->query('DROP TABLE ' . $this->fixture->prefixTable('audit_events')));
        } elseif ($failure === 'bad_head') {
            (new AuditService(null, $this->fixture))->log('synthetic.fixture', 'synthetic_fixture', '1', 'fixture', [], 1, 'counsellor');
            self::assertTrue($this->fixture->table('audit_events')->update(['curr_hash' => str_repeat('f', 64)]));
        } else {
            $condition = $failure === 'episode_append_failure' ? " WHEN NEW.event_type = 'guidance.episode.read'" : '';
            self::assertTrue($this->fixture->query('CREATE TRIGGER ' . $this->fixture->prefixTable('refuse_audit')
                . ' BEFORE INSERT ON ' . $this->fixture->prefixTable('audit_events') . $condition
                . " BEGIN SELECT RAISE(ABORT, 'Synthetic episode audit append failure'); END"));
        }
    }

    private function assertAuditFailure(string $failure, string $method): void
    {
        $result = null;
        $caught = null;
        try {
            $result = $this->read($method);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }
        self::assertNull($result, 'No episode package or closure plaintext may escape a failed read audit.');
        self::assertNotNull($caught, 'Episode reads must fail closed when their audit cannot persist.');
        self::assertSame(503, $caught->getCode());
        self::assertSame(match ($failure) {
            'bad_head' => 'Audit head integrity check failed. Append refused.',
            'missing_table' => 'Unable to read audit head.',
            default => 'Unable to persist audit event.',
        }, $caught->getMessage());
        self::assertStringNotContainsString(self::CLOSURE_PLAINTEXT, $caught->getMessage());
        self::assertStringNotContainsString($this->closureCipher, $caught->getMessage());
    }

    private function assertReadAudits(array $types): void
    {
        $events = $this->auditRows();
        self::assertSame($types, array_column($events, 'event_type'));
        foreach ($events as $event) {
            self::assertSame(1, (int) $event['tenant_id']);
            self::assertSame(1, (int) $event['actor_user_id']);
            self::assertSame(2, (int) $event['hash_version']);
            self::assertNull($event['payload_json'], 'Read audit payloads must contain no closure or clinical data.');
            if ($event['event_type'] === 'guidance.episode.read') {
                self::assertSame('guidance_episodes', $event['entity_type']);
                self::assertSame('101', $event['entity_id']);
                self::assertSame('read', $event['action']);
            } else {
                self::assertSame('counselling_cases', $event['entity_type']);
                self::assertSame('202', $event['entity_id']);
                self::assertSame('oversight_read', $event['action']);
            }
        }
        self::assertTrue((new AuditService(null, $this->fixture))->verifyChain(1)['valid']);
    }

    public static function readMethods(): iterable
    {
        yield 'episode package' => ['getEpisodeDetails'];
        yield 'closure getter by ID' => ['getDecryptedClosureNotes'];
    }

    #[DataProvider('readMethods')]
    public function testClosureReadRequiresOnePersistedMetadataOnlyEpisodeAudit(string $method): void
    {
        $result = $this->read($method);
        if ($method === 'getEpisodeDetails') {
            self::assertIsArray($result);
            self::assertSame(self::CLOSURE_PLAINTEXT, $result['closure_notes']);
            self::assertArrayNotHasKey('closure_notes_cipher', $result);
            self::assertArrayNotHasKey('closure_notes_cipher', $result['episode']);
            self::assertStringNotContainsString($this->closureCipher, json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            self::assertSame(self::CLOSURE_PLAINTEXT, $result);
        }
        $this->assertReadAudits(['guidance.episode.read']);
        self::assertSame('counsellor', $this->auditRows()[0]['actor_role']);
    }

    public static function auditFailures(): iterable
    {
        foreach (['missing_table', 'bad_head', 'append_failure'] as $failure) {
            foreach (['getEpisodeDetails', 'getDecryptedClosureNotes'] as $method) {
                yield $failure . '-' . $method => [$failure, $method];
            }
        }
    }

    #[DataProvider('auditFailures')]
    public function testMissingCorruptAndRejectedAuditAppendsNeverReleaseClosurePlaintext(string $failure, string $method): void
    {
        $this->breakAudit($failure);
        $before = $failure === 'missing_table' ? null : $this->auditRows();

        $this->assertAuditFailure($failure, $method);

        if ($before !== null) self::assertSame($before, $this->auditRows(), 'Failed appends must preserve the private audit ledger.');
        self::assertSame($this->closureCipher, $this->fixture->table('guidance_episodes')->where('id', 101)->get()->getRowArray()['closure_notes_cipher']);
    }

    public static function metadataAuditFailures(): iterable
    {
        foreach (['missing_table', 'bad_head', 'append_failure'] as $failure) yield $failure => [$failure];
    }

    #[DataProvider('metadataAuditFailures')]
    public function testAnEpisodeWithoutClosureNotesStillCannotReturnAnUnauditedPackage(string $failure): void
    {
        self::assertTrue($this->fixture->table('guidance_episodes')->where('id', 101)->update(['closure_notes_cipher' => null]));
        $this->breakAudit($failure);
        $before = $failure === 'missing_table' ? null : $this->auditRows();

        $this->assertAuditFailure($failure, 'getEpisodeDetails');

        if ($before !== null) self::assertSame($before, $this->auditRows());
    }

    public function testLinkedOversightCaseAndEpisodeEachPersistTheirOwnReadAudit(): void
    {
        self::assertTrue($this->fixture->table('users')->where('id', 1)->update(['role' => 'guidance_supervisor']));
        self::assertTrue($this->fixture->table('guidance_episodes')->where('id', 101)->update(['case_id' => 202]));

        $result = $this->read();

        self::assertIsArray($result);
        self::assertSame(202, (int) $result['case']['id']);
        self::assertSame([302], array_map('intval', array_column($result['sessions'], 'id')));
        self::assertSame(self::CLOSURE_PLAINTEXT, $result['closure_notes']);
        self::assertArrayNotHasKey('closure_notes_cipher', $result);
        self::assertArrayNotHasKey('closure_notes_cipher', $result['episode']);
        $this->assertReadAudits(['guidance.case.oversight_read', 'guidance.episode.read']);
        self::assertSame(['guidance_supervisor', 'guidance_supervisor'], array_column($this->auditRows(), 'actor_role'));
    }

    public function testPersistedCaseOversightAuditCannotReplaceTheRequiredEpisodeReadAudit(): void
    {
        self::assertTrue($this->fixture->table('users')->where('id', 1)->update(['role' => 'guidance_supervisor']));
        self::assertTrue($this->fixture->table('guidance_episodes')->where('id', 101)->update(['case_id' => 202]));
        $this->breakAudit('episode_append_failure');

        $this->assertAuditFailure('episode_append_failure', 'getEpisodeDetails');

        $this->assertReadAudits(['guidance.case.oversight_read']);
        self::assertSame('guidance_supervisor', $this->auditRows()[0]['actor_role']);
    }
}
