<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use ReflectionMethod;
use ReflectionProperty;

/** Private SQLite only: real seeded grants, synthetic ciphertext, no application migrations. */
final class GuidanceReadAuthorizationTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private array $savedVersion = [];
    private GuidanceCaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', ENVIRONMENT);
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        }
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->fixture = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        AuthenticatedUser::clear();
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, summary_notes TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, session_number INTEGER, status TEXT, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, due_date TEXT',
            'counselling_notes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, session_id INTEGER, version INTEGER, is_draft INTEGER, notes_cipher BLOB, notes_nonce BLOB, notes_tag BLOB, notes_key_version INTEGER, created_at TEXT, created_by_user_id INTEGER',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        (new PermissionsAndRolesSeeder(config('Database'), $this->fixture))->run();
        foreach ([1, 2] as $tenant) {
            $this->fixture->table('tenants')->insert(['id' => $tenant, 'is_active' => 1]);
        }
        foreach ([[1, 1], [2, 1], [3, 2]] as [$id, $tenant]) {
            $this->fixture->table('people')->insert(['id' => $id, 'tenant_id' => $tenant,
                'kind' => 'student', 'mis_id' => 'READ-' . $id, 'first_name' => 'Synthetic', 'last_name' => 'Person']);
            $this->fixture->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'read-' . $id, 'role' => 'counsellor', 'status' => 'active']);
        }
        foreach ([[11, 1, 1, 1], [12, 1, 2, 2], [13, 2, 3, 3], [14, 1, 2, 2]] as [$id, $tenant, $person, $owner]) {
            $this->fixture->table('counselling_cases')->insert(['id' => $id, 'tenant_id' => $tenant,
                'person_id' => $person, 'counsellor_user_id' => $owner, 'status' => 'open', 'summary_notes' => 'Private summary']);
            $this->fixture->table('counselling_sessions')->insert(['id' => $id, 'tenant_id' => $tenant,
                'case_id' => $id, 'counsellor_user_id' => $owner, 'session_number' => 1, 'status' => 'in_progress']);
            $this->fixture->table('guidance_followups')->insert(['id' => $id, 'tenant_id' => $tenant, 'case_id' => $id]);
        }
        // Pin the version as well as the explicit key: never resolve a key from .env.
        $this->savedVersion = [getenv('COUNSELLING_KEY_VERSION'), $_ENV['COUNSELLING_KEY_VERSION'] ?? null,
            $_SERVER['COUNSELLING_KEY_VERSION'] ?? null];
        putenv('COUNSELLING_KEY_VERSION=1');
        $_ENV['COUNSELLING_KEY_VERSION'] = $_SERVER['COUNSELLING_KEY_VERSION'] = '1';
        $crypto = new EncryptionService(str_repeat('ab', 32));
        foreach ([[11, 1], [12, 1], [13, 2]] as [$id, $tenant]) {
            foreach ([0, 1] as $draft) {
                $encrypted = $crypto->encrypt($draft ? 'Synthetic draft' : 'Synthetic final');
                $this->fixture->table('counselling_notes')->insert([
                    'tenant_id' => $tenant, 'session_id' => $id, 'version' => $draft ? 0 : 1, 'is_draft' => $draft,
                    'notes_key_version' => 1,
                    'created_at' => '2026-01-01 00:00:00', 'created_by_user_id' => 1,
                ]);
                // SQLite string escaping is not binary-safe for random GCM bytes.
                $this->fixture->query("UPDATE counselling_notes SET notes_cipher = X'" . bin2hex($encrypted['cipher'])
                    . "', notes_nonce = X'" . bin2hex($encrypted['nonce']) . "', notes_tag = X'"
                    . bin2hex($encrypted['tag']) . "' WHERE id = ?", [$this->fixture->insertID()]);
            }
        }
        $this->service = new GuidanceCaseService(encryptionService: $crypto, db: $this->fixture);
        $this->signIn();
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        if ($this->savedVersion !== []) {
            [$env, $local, $server] = $this->savedVersion;
            putenv($env === false ? 'COUNSELLING_KEY_VERSION' : 'COUNSELLING_KEY_VERSION=' . $env);
            unset($_ENV['COUNSELLING_KEY_VERSION'], $_SERVER['COUNSELLING_KEY_VERSION']);
            if ($local !== null) $_ENV['COUNSELLING_KEY_VERSION'] = $local;
            if ($server !== null) $_SERVER['COUNSELLING_KEY_VERSION'] = $server;
        }
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->savedConnections);
        }
        parent::tearDown();
    }

    private function signIn(string $role = 'counsellor'): void
    {
        $this->fixture->table('users')->where('id', 1)->update(['role' => $role]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->fixture->table('permissions')->where('code', $code)->get()->getRowArray();
        self::assertNotNull($permission);
        $this->fixture->table('role_permissions')->where('role', $role)->where('permission_id', $permission['id'])->delete();
    }

    private function assertDenied(string $method, int $id, int $code): void
    {
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            try {
                $this->service->{$method}($id);
                self::fail($method . ' unexpectedly allowed access');
            } catch (\InvalidArgumentException | \RuntimeException | \DomainException $exception) {
                self::assertSame($code, $exception->getCode(), $exception->getMessage());
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertNotEmpty($queries, 'Query observation must be active.');
        foreach ($queries as $query) {
            self::assertStringNotContainsString('counselling_notes', strtolower($query));
            if ($method === 'getCaseDetails') {
                foreach (['counselling_sessions', 'guidance_followups', 'student_profiles'] as $table) {
                    self::assertStringNotContainsString($table, strtolower($query));
                }
            }
        }
    }

    public function testOwnerCanReadPackageDraftFinalAndEmptyNote(): void
    {
        $package = $this->service->getCaseDetails(11);
        self::assertSame(11, (int) $package['case']['id']);
        self::assertSame('Private summary', $package['case']['summary_notes']);
        self::assertCount(1, $package['sessions']);
        self::assertCount(1, $package['followups']);
        self::assertSame('Synthetic draft', $this->service->getDecryptedSessionNote(11)['text']);
        $this->fixture->table('counselling_notes')->where('session_id', 11)->where('is_draft', 1)->delete();
        self::assertSame('Synthetic final', $this->service->getDecryptedSessionNote(11)['text']);
        $this->fixture->table('counselling_notes')->where('session_id', 11)->delete();
        self::assertNull($this->service->getDecryptedSessionNote(11));
    }

    public function testOtherCounsellorCannotReadEvenWhenNoNoteExists(): void
    {
        foreach (['getCaseDetails', 'getDecryptedSessionNote', 'getDecryptedSessionNoteHistory'] as $method) {
            foreach ([12, 14] as $id) $this->assertDenied($method, $id, 403);
        }
    }

    public function testOversightAndSuperadminRemainTenantBound(): void
    {
        foreach (['guidance_admin', 'guidance_supervisor', 'superadmin'] as $role) {
            $this->signIn($role);
            self::assertSame(12, (int) $this->service->getCaseDetails(12)['case']['id']);
            self::assertSame('Synthetic draft', $this->service->getDecryptedSessionNote(12)['text']);
            foreach (['getCaseDetails', 'getDecryptedSessionNote', 'getDecryptedSessionNoteHistory'] as $method) {
                $this->assertDenied($method, 13, 404);
            }
        }
    }

    public function testRevokedReadIsRefreshedEvenForOwnerWithCachedIdentity(): void
    {
        self::assertNotNull($this->service->getDecryptedSessionNote(11));
        self::assertContains('guidance.cases.read', session()->get('user')['permissions']);
        $this->revoke('counsellor', 'guidance.cases.read');
        foreach (['getCaseDetails', 'getDecryptedSessionNote', 'getDecryptedSessionNoteHistory'] as $method) {
            $this->assertDenied($method, 11, 403);
        }
        self::assertNotContains('guidance.cases.read', session()->get('user')['permissions']);
        $this->fixture->table('counselling_notes')->where('session_id', 11)->delete();
        $this->assertDenied('getDecryptedSessionNote', 11, 403);
    }

    public function testReadAnyDoesNotReplaceReadAndRevocationRemovesOversight(): void
    {
        $this->signIn('guidance_supervisor');
        self::assertNotNull($this->service->getDecryptedSessionNote(12));
        $this->revoke('guidance_supervisor', 'guidance.cases.read_any');
        $this->assertDenied('getCaseDetails', 12, 403);
        $this->assertDenied('getDecryptedSessionNote', 12, 403);
        $this->signIn('guidance_admin');
        $this->revoke('guidance_admin', 'guidance.cases.read');
        $this->assertDenied('getCaseDetails', 12, 403);
        $this->assertDenied('getDecryptedSessionNote', 12, 403);
    }

    public function testInvalidPatientLinkAndDeletedRecordsRemainNotFound(): void
    {
        $this->fixture->table('counselling_cases')->where('id', 11)->update(['person_id' => 3]);
        $this->assertDenied('getCaseDetails', 11, 404);
        $this->assertDenied('getDecryptedSessionNote', 11, 404);
        $this->fixture->table('counselling_cases')->where('id', 11)->update(['person_id' => 1]);
        $this->fixture->table('people')->where('id', 2)->update(['deleted_at' => '2026-01-01']);
        $this->signIn('guidance_admin');
        $this->assertDenied('getCaseDetails', 12, 404);
        $this->assertDenied('getDecryptedSessionNote', 12, 404);
        $this->fixture->table('counselling_cases')->where('id', 11)->update(['deleted_at' => '2026-01-01']);
        $this->assertDenied('getCaseDetails', 11, 404);
        $this->assertDenied('getDecryptedSessionNote', 11, 404);
    }

    public function testMissingAndDeletedSessionsCannotReachNotes(): void
    {
        foreach (['getDecryptedSessionNote', 'getDecryptedSessionNoteHistory'] as $method) {
            $this->assertDenied($method, 999, 404);
        }
        $this->fixture->table('counselling_sessions')->where('id', 11)->update(['deleted_at' => '2026-01-01']);
        foreach (['getDecryptedSessionNote', 'getDecryptedSessionNoteHistory'] as $method) {
            $this->assertDenied($method, 11, 404);
        }
    }

    public function testNoSessionCannotReadWithStaleTenantContext(): void
    {
        AuthenticatedUser::clear();
        CurrentTenant::set(1);
        // No SQL is needed to reject these reads, unlike the authenticated denial cases.
        foreach (['getCaseDetails', 'getDecryptedSessionNote'] as $method) {
            try {
                $this->service->{$method}(11);
                self::fail('Unauthenticated read unexpectedly allowed');
            } catch (\RuntimeException $exception) {
                self::assertSame(401, $exception->getCode());
            }
        }
    }

    public function testWrongDepartmentAndDisabledAccountCannotRead(): void
    {
        $this->signIn('clinic_staff');
        $this->assertDenied('getCaseDetails', 11, 403);
        $this->assertDenied('getDecryptedSessionNote', 11, 403);
        $this->signIn();
        $this->fixture->table('users')->where('id', 1)->update(['status' => 'inactive']);
        $this->assertDenied('getDecryptedSessionNote', 11, 401);
    }

    public function testInternalWriteReturnsDoNotAcquireReadPermissionDependency(): void
    {
        // SQLite cannot execute the service's MySQL-only writes; check their return boundaries.
        foreach (['createCase', 'closeCase', 'monitorCase'] as $method) {
            $reflection = new ReflectionMethod(GuidanceCaseService::class, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1));
            self::assertStringNotContainsString('getCaseDetails(', $source);
            self::assertStringContainsString('$this->caseDetails(', $source);
        }
        self::assertTrue((new ReflectionMethod(GuidanceCaseService::class, 'caseDetails'))->isPrivate());
        $this->revoke('counsellor', 'guidance.cases.read');
        $actor = \App\Services\EngagementAccess::actor('guidance.cases.create', 'guidance');
        self::assertContains('guidance.cases.write', $actor['permissions']);
        self::assertNotContains('guidance.cases.read', $actor['permissions']);
        self::assertSame(11, (int) $this->service->getSession(11)['id']);
        $this->assertDenied('getCaseDetails', 11, 403);
    }
}
