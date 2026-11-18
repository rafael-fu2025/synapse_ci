<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceEpisodeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/**
 * Synthetic private SQLite only, with no migrations or clinical tables.
 * Run with --no-configuration --bootstrap tests/_support/guidance-read-bootstrap.php.
 */
final class GuidanceEpisodeSummaryTest extends TestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private ?array $identity = ['id' => 1, 'tenant_id' => 1];
    private GuidanceEpisodeService $service;
    private array $readQueries = [];

    private const SUMMARY_KEYS = [
        'id', 'tenant_id', 'person_id', 'case_id', 'intake_referral_id',
        'status', 'opened_at', 'closed_at', 'primary_counselor_user_id',
    ];

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
            'DBPrefix' => 'episode_summary_', 'DBDebug' => true,
            'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        self::assertSame('SQLite3', $this->fixture->DBDriver);
        self::assertSame(':memory:', $this->fixture->database);
        // Service collaborators that construct models without a connection stay private too.
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        CurrentTenant::set(1);
        $this->schema();
        $this->seed();
        $policy = new GuidanceAccessPolicy(identitySource: fn (): ?array => $this->identity, db: $this->fixture);
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
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER, PRIMARY KEY(role, permission_id)',
            'guidance_episodes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, case_id INTEGER, intake_referral_id INTEGER, status TEXT, opened_at TEXT, closed_at TEXT, primary_counselor_user_id INTEGER, closure_reason TEXT, closure_notes_cipher TEXT, created_at TEXT, updated_at TEXT, deleted_at TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, title TEXT, summary_notes TEXT, closure_disposition_cipher TEXT, deleted_at TEXT',
            'guidance_supervision_assignments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, associate_user_id INTEGER, supervisor_user_id INTEGER, status TEXT',
            'guidance_case_transfers' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, to_counsellor_user_id INTEGER, transfer_type TEXT, status TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')');
        }
    }

    private function seed(): void
    {
        foreach ([1, 2] as $tenantId) {
            $this->insert('tenants', ['id' => $tenantId, 'is_active' => 1]);
        }
        foreach ([
            [10, 1, 'student', null], [20, 1, 'employee', null], [30, 1, 'external', null],
            [40, 2, 'student', null], [50, 1, 'student', '2026-01-01'],
            [60, 1, 'student', null], [70, 1, 'employee', null],
            [101, 1, 'employee', null], [102, 1, 'employee', null],
            [103, 1, 'employee', null], [109, 2, 'employee', null],
        ] as [$id, $tenantId, $kind, $deletedAt]) {
            $this->insert('people', ['id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind,
                'first_name' => 'Synthetic', 'last_name' => 'Person ' . $id, 'deleted_at' => $deletedAt]);
        }
        foreach ([[1, 1, 101], [2, 1, 102], [3, 1, 103], [9, 2, 109]] as [$id, $tenantId, $personId]) {
            $this->insert('users', ['id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId,
                'username' => 'synthetic-' . $id, 'role' => 'counsellor', 'status' => 'active',
                'password_hash' => 'SYNTHETIC-PRIVATE-ACCOUNT']);
        }
        $this->insert('permissions', ['id' => 1, 'code' => 'guidance.cases.read']);
        $this->insert('permissions', ['id' => 2, 'code' => 'guidance.cases.read_any']);
        foreach (['counsellor', 'guidance_supervisor', 'guidance_admin', 'superadmin'] as $role) {
            $this->insert('role_permissions', ['role' => $role, 'permission_id' => 1]);
        }
        foreach ([[201, 1, 10, 1], [202, 1, 10, 2], [204, 1, 20, 1],
            [205, 1, 60, 1], [209, 2, 40, 9]] as [$id, $tenantId, $personId, $ownerId]) {
            $this->insert('counselling_cases', ['id' => $id, 'tenant_id' => $tenantId,
                'person_id' => $personId, 'counsellor_user_id' => $ownerId,
                'title' => 'SYNTHETIC-PRIVATE-TITLE', 'summary_notes' => 'SYNTHETIC-PRIVATE-SUMMARY',
                'closure_disposition_cipher' => 'SYNTHETIC-PRIVATE-CASE-CIPHER']);
        }
        foreach ([
            [101, 10, 1, null, 'open', null],
            [102, 10, 1, 201, 'active', 701],
            [103, 10, 2, null, 'open', 702],
            [104, 10, 2, 202, 'closed', 703],
            [105, 10, 2, 201, 'transferred', null],
            [106, 10, 3, 201, 'active', null],
            [107, 10, null, null, 'open', 704],
            [110, 60, 1, null, 'open', null],
            [111, 20, 1, 204, 'closed', null],
            [112, 20, 1, null, 'active', null],
            [113, 30, 1, null, 'open', null],
            [114, 50, 1, null, 'open', null],
        ] as [$id, $personId, $ownerId, $caseId, $status, $intakeId]) {
            $this->episode($id, ['person_id' => $personId, 'primary_counselor_user_id' => $ownerId,
                'case_id' => $caseId, 'status' => $status, 'intake_referral_id' => $intakeId]);
        }
        $this->episode(108, ['deleted_at' => '2026-01-01']);
        // Deliberately inconsistent foreign-tenant episode must not match person 10.
        $this->episode(109, ['tenant_id' => 2]);
        $this->episode(115, ['tenant_id' => 2, 'person_id' => 40, 'primary_counselor_user_id' => 9]);
    }

    private function insert(string $table, array $data): void
    {
        self::assertTrue($this->fixture->table($table)->insert($data));
    }

    private function episode(int $id, array $overrides = []): void
    {
        $this->insert('guidance_episodes', array_replace([
            'id' => $id, 'tenant_id' => 1, 'person_id' => 10,
            'case_id' => null, 'intake_referral_id' => null,
            'status' => 'open', 'opened_at' => '2026-01-01 09:00:00', 'closed_at' => null,
            'primary_counselor_user_id' => 1, 'deleted_at' => null,
            'closure_reason' => 'SYNTHETIC-PRIVATE-CLOSURE-REASON',
            'closure_notes_cipher' => 'SYNTHETIC-PRIVATE-EPISODE-CIPHER',
            'created_at' => '2026-01-01 09:00:00', 'updated_at' => '2026-01-02 09:00:00',
        ], $overrides));
    }

    private function grantReadAny(string $role = 'counsellor'): void
    {
        $this->insert('role_permissions', ['role' => $role, 'permission_id' => 2]);
    }

    private function summaries(int $personId = 10, ?array $actor = null): array
    {
        $changesBefore = $this->fixture->query('SELECT total_changes() AS changes')->getRowArray()['changes'];
        $this->readQueries = [];
        $listener = function ($query): void { $this->readQueries[] = (string) $query; };
        $this->fixture->query('PRAGMA query_only = ON');
        Events::on('DBQuery', $listener);
        try {
            $rows = $this->service->getPersonEpisodeSummaries($personId, $actor);
            foreach ($rows as $row) {
                self::assertSame(self::SUMMARY_KEYS, array_keys($row));
            }
            self::assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode($rows, JSON_THROW_ON_ERROR));
            return $rows;
        } finally {
            Events::removeListener('DBQuery', $listener);
            $this->fixture->query('PRAGMA query_only = OFF');
            self::assertSame($changesBefore,
                $this->fixture->query('SELECT total_changes() AS changes')->getRowArray()['changes'],
                'Summary reads must not change any business or authorization rows.');
            self::assertSame(0, $this->fixture->transDepth, 'Summary reads must not open a transaction.');
            $this->assertMetadataQueriesOnly();
        }
    }

    private function assertMetadataQueriesOnly(): void
    {
        foreach ($this->readQueries as $query) {
            $sql = strtolower($query);
            self::assertMatchesRegularExpression('/^\s*select\b/i', $query, 'Only reads may run.');
            foreach (['counselling_sessions', 'counselling_notes', 'guidance_form_', 'guidance_clinical_',
                'guidance_intake_referrals', 'guidance_appointments', 'guidance_call_slips',
                'guidance_practitioner_profiles', 'audit_events'] as $table) {
                self::assertStringNotContainsString($table, $sql);
            }
            if (str_contains($sql, 'guidance_episodes') || str_contains($sql, 'counselling_cases')) {
                foreach (['*', 'cipher', 'nonce', 'tag', 'closure_reason', 'summary_notes', 'title'] as $content) {
                    self::assertStringNotContainsString($content, $sql);
                }
            }
        }
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    private function denied(int $personId, int $code, ?array $actor = null): void
    {
        try {
            $this->summaries($personId, $actor);
        } catch (DomainException | InvalidArgumentException | RuntimeException $exception) {
            self::assertSame($code, $exception->getCode(), $exception->getMessage());
            return;
        }
        self::fail('Expected the summary reader to reject access.');
    }

    public function testPrimaryCounselorGetsOnlyExactMetadataWithoutCiphertextOrDetailFallback(): void
    {
        $rows = $this->summaries();
        self::assertSame([102, 101], $this->ids($rows));
        self::assertSame(201, (int) $rows[0]['case_id']);
        self::assertSame(701, (int) $rows[0]['intake_referral_id']);
        self::assertNull($rows[1]['case_id'], 'An unlinked episode must not acquire the latest person case.');
        self::assertNull($rows[1]['intake_referral_id'], 'An unlinked episode must not acquire the latest intake.');
        self::assertNull($rows[1]['closed_at']);
        self::assertSame('open', $rows[1]['status']);
        self::assertNotEmpty($this->readQueries, 'SQL observation must be active.');
    }

    public function testEpisodeAndCaseOwnershipAreBothRequiredForOrdinaryCounselors(): void
    {
        $this->identity = ['id' => 2, 'tenant_id' => 1];
        self::assertSame([104, 103], $this->ids($this->summaries()));
        $this->identity = ['id' => 3, 'tenant_id' => 1];
        self::assertSame([], $this->summaries(), 'Episode ownership must not expose another counselor case.');
    }

    public function testStoredReadAnyGrantsOversightButCallerProvidedPermissionsDoNot(): void
    {
        $forged = ['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin',
            'permissions' => ['guidance.cases.read', 'guidance.cases.read_any']];
        self::assertSame([102, 101], $this->ids($this->summaries(10, $forged)));
        $this->grantReadAny();
        self::assertSame([107, 106, 105, 104, 103, 102, 101], $this->ids($this->summaries(10, $forged)));
    }

    public static function oversightRoles(): iterable
    {
        foreach (['guidance_supervisor', 'guidance_admin', 'superadmin'] as $role) {
            yield $role => [$role];
        }
    }

    #[DataProvider('oversightRoles')]
    public function testOversightRolesPreserveEpisodeAccessButDoNotBypassLinkedCaseRead(string $role): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => $role]);
        self::assertSame([107, 106, 105, 103, 102, 101], $this->ids($this->summaries()));
        $this->grantReadAny($role);
        self::assertSame([107, 106, 105, 104, 103, 102, 101], $this->ids($this->summaries()));
    }

    public function testActiveSupervisionIsEpisodeOnlyAndDoesNotLeakRestrictedLinkedCase(): void
    {
        $this->insert('guidance_supervision_assignments', ['id' => 1, 'tenant_id' => 1,
            'associate_user_id' => 2, 'supervisor_user_id' => 1, 'status' => 'active']);
        self::assertSame([105, 103, 102, 101], $this->ids($this->summaries()));
        $this->insert('guidance_case_transfers', ['id' => 1, 'tenant_id' => 1, 'case_id' => 202,
            'to_counsellor_user_id' => 1, 'transfer_type' => 'consultation', 'status' => 'accepted']);
        self::assertSame([105, 104, 103, 102, 101], $this->ids($this->summaries()));
        $this->fixture->table('guidance_supervision_assignments')->where('id', 1)->update(['status' => 'completed']);
        self::assertSame([102, 101], $this->ids($this->summaries()), 'Consultation alone is not episode authorization.');
    }

    public static function invalidSupervisions(): iterable
    {
        yield 'completed' => [['tenant_id' => 1, 'associate_user_id' => 2, 'supervisor_user_id' => 1, 'status' => 'completed']];
        yield 'foreign tenant' => [['tenant_id' => 2, 'associate_user_id' => 2, 'supervisor_user_id' => 1, 'status' => 'active']];
        yield 'other supervisor' => [['tenant_id' => 1, 'associate_user_id' => 2, 'supervisor_user_id' => 3, 'status' => 'active']];
    }

    #[DataProvider('invalidSupervisions')]
    public function testOnlyActiveSameTenantSupervisionForTheActorGrantsEpisodeRead(array $assignment): void
    {
        $this->insert('guidance_supervision_assignments', ['id' => 1] + $assignment);
        self::assertSame([102, 101], $this->ids($this->summaries()));
    }

    public function testMissingSupervisionTableDoesNotAuthorizeOtherCounselorsOrBreakOwnerSummaries(): void
    {
        // Remove the optional table before any tableExists read can cache its presence.
        $this->fixture->query('DROP TABLE ' . $this->fixture->prefixTable('guidance_supervision_assignments'));
        self::assertSame([102, 101], $this->ids($this->summaries()));
        foreach ($this->readQueries as $query) {
            self::assertDoesNotMatchRegularExpression('/from\s+["`]?episode_summary_guidance_supervision_assignments\b/i', $query);
        }
        $this->identity = ['id' => 3, 'tenant_id' => 1];
        self::assertSame([], $this->summaries(), 'Missing supervision schema must not grant cross-owner read access.');
    }

    public function testDeletedForeignMissingAndWrongPersonLinkedCasesAreOmittedEvenForReadAny(): void
    {
        $this->grantReadAny();
        $this->insert('counselling_cases', ['id' => 206, 'tenant_id' => 1,
            'person_id' => 10, 'counsellor_user_id' => 1, 'deleted_at' => '2026-01-01']);
        foreach ([205, 206, 209, 999, 0] as $index => $caseId) {
            $this->episode(120 + $index, ['case_id' => $caseId]);
        }
        self::assertSame([107, 106, 105, 104, 103, 102, 101], $this->ids($this->summaries()));
    }

    public function testEmployeeSubjectUsesTheSameAuthorizationWithoutClinicalOrProfileQueries(): void
    {
        self::assertSame([112, 111], $this->ids($this->summaries(20)));
        foreach ($this->readQueries as $query) {
            self::assertStringNotContainsString('employee_profiles', $query);
            self::assertStringNotContainsString('student_profiles', $query);
        }
    }

    public static function missingPeople(): iterable
    {
        yield 'external' => [30];
        yield 'foreign tenant' => [40];
        yield 'deleted' => [50];
        yield 'missing' => [999];
        yield 'nonpositive' => [0];
    }

    #[DataProvider('missingPeople')]
    public function testInvalidSubjectIsNotFoundBeforeAnyEpisodeMetadataQuery(int $personId): void
    {
        $this->grantReadAny();
        $this->denied($personId, 404);
        foreach ($this->readQueries as $query) {
            self::assertStringNotContainsString('guidance_episodes', $query);
        }
    }

    public function testReadGrantIsRefreshedAndReadAnyNeverReplacesRequiredRead(): void
    {
        $this->grantReadAny();
        self::assertCount(7, $this->summaries());
        $this->fixture->table('role_permissions')->where('role', 'counsellor')->where('permission_id', 1)->delete();
        $this->denied(10, 403, ['id' => 1, 'tenant_id' => 1,
            'permissions' => ['guidance.cases.read', 'guidance.cases.read_any']]);
    }

    public function testTransportIdentityCannotBeReplacedByActorHintsOrTenantContext(): void
    {
        $this->denied(10, 403, ['id' => 2, 'tenant_id' => 1]);
        $this->denied(10, 403, ['id' => 1, 'tenant_id' => 2]);
        CurrentTenant::set(2);
        $this->denied(10, 403);
        CurrentTenant::set(1);
        $this->identity = null;
        $this->denied(10, 401, ['id' => 1, 'tenant_id' => 1]);
    }

    public static function invalidAccounts(): iterable
    {
        yield 'inactive user' => ['users', ['status' => 'inactive']];
        yield 'deleted user' => ['users', ['deleted_at' => '2026-01-01']];
        yield 'wrong department role' => ['users', ['role' => 'clinic_staff']];
        yield 'inactive tenant' => ['tenants', ['is_active' => 0]];
        yield 'deleted tenant' => ['tenants', ['deleted_at' => '2026-01-01']];
    }

    #[DataProvider('invalidAccounts')]
    public function testAccountAndTenantMustRemainActive(string $table, array $changes): void
    {
        $this->fixture->table($table)->where('id', 1)->update($changes);
        $this->denied(10, 403);
    }

    public function testDeletedAccountPersonIsRejected(): void
    {
        $this->fixture->table('people')->where('id', 101)->update(['deleted_at' => '2026-01-01']);
        $this->denied(10, 403);
    }

    public function testAllAuthorizedSummariesAreReturnedWithoutHiddenLimitAndWithStableDateOrdering(): void
    {
        for ($id = 1000; $id < 1060; ++$id) {
            $this->episode($id, ['case_id' => 201, 'opened_at' => '2026-02-01 09:00:00']);
        }
        $this->episode(2000, ['opened_at' => '2025-12-01 09:00:00']);
        $rows = $this->summaries();
        self::assertCount(63, $rows);
        self::assertSame(array_merge(range(1059, 1000), [102, 101, 2000]), $this->ids($rows));
        $caseQueries = array_filter($this->readQueries,
            static fn (string $query): bool => str_contains($query, 'episode_summary_counselling_cases'));
        self::assertCount(1, $caseQueries, 'Linked case authorization metadata is fetched in a single batch.');
    }

    public function testMetadataConstructionAndReadsDoNotRequireEncryptionKeysOrClinicalTables(): void
    {
        $names = ['COUNSELLING_KEY', 'COUNSELLING_KEY_VERSION'];
        $saved = [];
        foreach ($names as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name . '=invalid-synthetic-value');
            $_ENV[$name] = $_SERVER[$name] = 'invalid-synthetic-value';
        }
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        try {
            $this->fixture->query('PRAGMA query_only = ON');
            Events::on('DBQuery', $listener);
            try {
                $policy = new GuidanceAccessPolicy(identitySource: fn (): ?array => $this->identity, db: $this->fixture);
                $this->service = new GuidanceEpisodeService(accessPolicy: $policy, db: $this->fixture);
            } finally {
                Events::removeListener('DBQuery', $listener);
                $this->fixture->query('PRAGMA query_only = OFF');
            }
            self::assertSame([], $queries, 'Metadata reader construction must not query missing business tables.');
            self::assertSame([102, 101], $this->ids($this->summaries()));
            self::assertNull((new ReflectionProperty(GuidanceEpisodeService::class, 'encryptionService'))
                ->getValue($this->service), 'Metadata-only reads must not initialize encryption.');
        } finally {
            foreach ($saved as $name => [$env, $local, $server]) {
                putenv($env === false ? $name : $name . '=' . $env);
                unset($_ENV[$name], $_SERVER[$name]);
                if ($local !== null) $_ENV[$name] = $local;
                if ($server !== null) $_SERVER[$name] = $server;
            }
        }
    }

    public function testLazyEncryptionPreservesClosurePayloadDecryption(): void
    {
        $crypto = new EncryptionService(str_repeat('ab', 32));
        $encrypted = $crypto->encrypt('Synthetic closure plaintext');
        $payload = json_encode([
            'cipher' => base64_encode($encrypted['cipher']),
            'nonce' => base64_encode($encrypted['nonce']),
            'tag' => base64_encode($encrypted['tag']),
            'key_version' => $encrypted['key_version'],
        ], JSON_THROW_ON_ERROR);
        $policy = new GuidanceAccessPolicy(identitySource: fn (): ?array => $this->identity, db: $this->fixture);
        $this->service = new GuidanceEpisodeService(accessPolicy: $policy, db: $this->fixture);
        self::assertSame('Synthetic closure plaintext',
            $this->service->getDecryptedClosureNotes(['closure_notes_cipher' => $payload]));
        self::assertInstanceOf(EncryptionService::class,
            (new ReflectionProperty(GuidanceEpisodeService::class, 'encryptionService'))->getValue($this->service));
    }

    public function testLegacyDetailAndWriteEntryPointsRetainEncryptionConfigurationValidation(): void
    {
        foreach (['openEpisode', 'getEpisodeDetails', 'closeEpisode', 'transferEpisode'] as $method) {
            $reflection = new ReflectionMethod(GuidanceEpisodeService::class, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1));
            self::assertStringContainsString('$this->encryptionService ??= new EncryptionService();', $source);
        }
    }

    public function testEmptyEpisodeHistoryDoesNotCreateBusinessRecords(): void
    {
        self::assertSame([], $this->summaries(70));
    }

    public function testUnavailableEpisodeSchemaDoesNotCreateBusinessRecords(): void
    {
        $this->fixture->query('DROP TABLE ' . $this->fixture->prefixTable('guidance_episodes'));
        self::assertSame([], $this->summaries());
    }
}
