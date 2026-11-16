<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Filters\WebAccessFilter;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Guidance\GuidanceCumulativeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use ReflectionMethod;
use ReflectionProperty;

/** Private SQLite only: real seeded grants, synthetic ciphertext, no application migrations. */
final class GuidanceReadAuthorizationTest extends CIUnitTestCase
{
    use \CodeIgniter\Test\FeatureTestTrait;

    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private array $savedVersion = [];
    private array $savedKey = [];
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
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, date_of_birth TEXT, gender TEXT, email TEXT, phone TEXT, address TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER, department TEXT, position TEXT',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level INTEGER, section TEXT, department TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'guidance_case_transfers' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, case_id INTEGER, from_counsellor_user_id INTEGER, to_counsellor_user_id INTEGER, transfer_type TEXT, status TEXT, reason TEXT DEFAULT "Synthetic consultation", requested_at TEXT DEFAULT "2026-01-01 00:00:00", responded_at TEXT, response_notes TEXT, created_at TEXT DEFAULT "2026-01-01 00:00:00"',
            'queue_entries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, status TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, provider_user_id INTEGER, starts_at TEXT, ends_at TEXT, status TEXT, type TEXT, deleted_at TEXT',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, deleted_at TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, summary_notes TEXT, deleted_at TEXT, case_number TEXT DEFAULT "SYNTHETIC-CASE", title TEXT DEFAULT "Synthetic case", category TEXT DEFAULT "personal", opened_at TEXT DEFAULT "2026-01-01", closed_at TEXT, closed_by INTEGER, closure_disposition_cipher BLOB, closure_disposition_nonce BLOB, closure_disposition_tag BLOB, closure_disposition_key_version INTEGER',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, session_number INTEGER, status TEXT, deleted_at TEXT, session_type TEXT DEFAULT "regular", started_at TEXT DEFAULT "2026-01-01", ended_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, due_date TEXT DEFAULT "2026-01-01", status TEXT DEFAULT "pending", action_plan TEXT DEFAULT "Synthetic plan", completed_at TEXT, completed_by INTEGER, completion_notes_cipher BLOB, completion_notes_nonce BLOB, completion_notes_tag BLOB, completion_notes_key_version INTEGER',
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
        foreach ([[11, 1, 1], [12, 1, 2], [13, 2, 3]] as [$id, $tenant, $person]) {
            $this->fixture->table('clinic_encounters')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'person_id' => $person, 'status' => 'in_progress',
            ]);
        }
        // Pin the version as well as the explicit key: never resolve a key from .env.
        // The controller builds its own service, so the key itself must be pinned too,
        // otherwise it decrypts the fixture with the deployment key and the GCM tag check fails.
        $this->savedVersion = [getenv('COUNSELLING_KEY_VERSION'), $_ENV['COUNSELLING_KEY_VERSION'] ?? null,
            $_SERVER['COUNSELLING_KEY_VERSION'] ?? null];
        $this->savedKey = [getenv('COUNSELLING_KEY'), $_ENV['COUNSELLING_KEY'] ?? null,
            $_SERVER['COUNSELLING_KEY'] ?? null];
        putenv('COUNSELLING_KEY_VERSION=1');
        $_ENV['COUNSELLING_KEY_VERSION'] = $_SERVER['COUNSELLING_KEY_VERSION'] = '1';
        $syntheticKey = str_repeat('ab', 32);
        putenv('COUNSELLING_KEY=' . $syntheticKey);
        $_ENV['COUNSELLING_KEY'] = $_SERVER['COUNSELLING_KEY'] = $syntheticKey;
        $crypto = new EncryptionService($syntheticKey);
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
        if ($this->savedKey !== []) {
            [$env, $local, $server] = $this->savedKey;
            putenv($env === false ? 'COUNSELLING_KEY' : 'COUNSELLING_KEY=' . $env);
            unset($_ENV['COUNSELLING_KEY'], $_SERVER['COUNSELLING_KEY']);
            if ($local !== null) $_ENV['COUNSELLING_KEY'] = $local;
            if ($server !== null) $_SERVER['COUNSELLING_KEY'] = $server;
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

    /** Invoke only the HTTP filter, never a live server or a controller/database migration. */
    private function filter(
        string $path,
        string $method = 'GET',
        string $baseURL = 'http://localhost/',
        string $indexPage = ''
    ): ?ResponseInterface {
        $app = clone config('App');
        $app->baseURL = $baseURL;
        $app->indexPage = $indexPage;
        $uri = new SiteURI($app, $path);
        self::assertSame($path, $uri->getRoutePath());
        $request = new IncomingRequest($app, $uri, null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setGlobal('get', []);
        $request->setGlobal('post', []);
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($app));
        $filter = new WebAccessFilter();
        $response = $filter->before($request);
        $filter->after($request, $response ?? service('response'));
        self::assertStringContainsString('no-store', ($response ?? service('response'))->getHeaderLine('Cache-Control'));
        return $response;
    }

    private function consultation(array $overrides = []): int
    {
        $this->fixture->table('guidance_case_transfers')->insert(array_replace([
            'tenant_id' => 1, 'case_id' => 12, 'from_counsellor_user_id' => 2,
            'to_counsellor_user_id' => 1, 'transfer_type' => 'consultation', 'status' => 'accepted',
        ], $overrides));
        return (int) $this->fixture->insertID();
    }

    private static function personReadPaths(int $personId): array
    {
        return ['guidance/clients/' . $personId, 'guidance/students/' . $personId . '/cumulative'];
    }

    private static function workspaceReadPaths(int $caseId): array
    {
        return ['guidance/cases/' . $caseId, 'guidance/cases/' . $caseId . '/photo',
            'guidance/sessions/' . $caseId . '/read', 'guidance/sessions/' . $caseId . '/history'];
    }

    public static function clientReadPaths(): iterable
    {
        yield 'client registry' => ['guidance/clients'];
        foreach (self::personReadPaths(1) as $path) yield $path => [$path];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('clientReadPaths')]
    public function testWebFilterClientReadsRequireFreshReadIncludingSuperadmin(string $path): void
    {
        foreach (['counsellor', 'guidance_supervisor', 'superadmin'] as $role) {
            $this->signIn($role);
            self::assertNull($this->filter($path));
            self::assertContains('guidance.cases.read', session('user')['permissions']);
            $this->revoke($role, 'guidance.cases.read');
            // Cached privilege claims cannot replace the grant removed from the fixture DB.
            $cached = session('user');
            $cached['role'] = 'superadmin';
            $cached['permissions'] = ['guidance.cases.read', 'guidance.cases.read_any'];
            session()->set('user', $cached);
            self::assertSame(403, $this->filter($path)?->getStatusCode());
            self::assertSame($role, session('user')['role']);
            self::assertNotContains('guidance.cases.read', session('user')['permissions']);
        }
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testWebFilterOwnerAndUnsharedWorkspaceLinksAgreeWithCumulative(): void
    {
        self::assertNull($this->filter('guidance/clients'));
        $cumulative = new GuidanceCumulativeService(db: $this->fixture);
        foreach (self::personReadPaths(1) as $path) self::assertNull($this->filter($path));
        $ownRecord = $cumulative->getStudentCumulativeRecord(1, query: ['tab' => 'cases']);
        self::assertSame([11], array_map('intval', array_column($ownRecord['authorizedCases'], 'id')));
        self::assertSame(1, $ownRecord['totals']['authorizedCases']);
        foreach (self::workspaceReadPaths(11) as $path) self::assertNull($this->filter($path));

        // Non-clinical client records remain readable; another owner's private case is not.
        foreach (self::personReadPaths(2) as $path) self::assertNull($this->filter($path));
        $unsharedRecord = $cumulative->getStudentCumulativeRecord(2, query: ['tab' => 'cases']);
        self::assertSame([], $unsharedRecord['authorizedCases']);
        self::assertSame(0, $unsharedRecord['totals']['authorizedCases']);
        foreach ([12, 14] as $id) {
            foreach (self::workspaceReadPaths($id) as $path) self::assertSame(403, $this->filter($path)?->getStatusCode());
        }
    }

    public function testWebFilterAcceptedConsultationAllowsOnlySharedWorkspaceReadsNotWrites(): void
    {
        $this->consultation();
        self::assertNull($this->filter('guidance/clients'));
        foreach (self::personReadPaths(2) as $path) self::assertNull($this->filter($path));
        $record = (new GuidanceCumulativeService(db: $this->fixture))->getStudentCumulativeRecord(2, query: ['tab' => 'cases']);
        self::assertSame([12], array_map('intval', array_column($record['authorizedCases'], 'id')));
        self::assertTrue($record['authorizedCases'][0]['is_consultation']);
        self::assertSame(2, (int) $record['authorizedCases'][0]['counsellor_user_id']);
        self::assertSame(1, $record['totals']['authorizedCases']);
        foreach (self::workspaceReadPaths(12) as $path) self::assertNull($this->filter($path));
        foreach (self::workspaceReadPaths(14) as $path) self::assertSame(403, $this->filter($path)?->getStatusCode());

        self::assertContains('guidance.cases.create', session('user')['permissions']);
        self::assertContains('guidance.cases.write', session('user')['permissions']);
        foreach (['cases/12/session', 'cases/12/followups', 'cases/12/close', 'cases/12/monitor',
            'sessions/12/notes', 'sessions/12/close', 'followups/12/complete', 'followups/12/missed'] as $path) {
            self::assertSame(403, $this->filter('guidance/' . $path, 'POST')?->getStatusCode());
        }
        $case = $this->fixture->table('counselling_cases')->where('id', 12)->get()->getRowArray();
        try {
            (new GuidanceAccessPolicy(db: $this->fixture))->writeCase(null, $case, 'guidance.cases.write', null, false);
            self::fail('Accepted consultation must not acquire domain write authority.');
        } catch (\DomainException $exception) {
            self::assertSame(403, $exception->getCode());
        }
    }

    public function testWebFilterReadAnyIsFreshReadOnlyAndDoesNotReplaceRead(): void
    {
        $this->signIn('guidance_supervisor');
        self::assertNull($this->filter('guidance/clients'));
        foreach (self::personReadPaths(2) as $path) self::assertNull($this->filter($path));
        $cumulative = new GuidanceCumulativeService(db: $this->fixture);
        self::assertCount(2, $cumulative->getStudentCumulativeRecord(2, query: ['tab' => 'cases'])['authorizedCases']);
        foreach (self::workspaceReadPaths(12) as $path) self::assertNull($this->filter($path));
        self::assertSame(403, $this->filter('guidance/sessions/12/notes', 'POST')?->getStatusCode());

        $this->revoke('guidance_supervisor', 'guidance.cases.read_any');
        foreach (self::workspaceReadPaths(12) as $path) self::assertSame(403, $this->filter($path)?->getStatusCode());
        self::assertSame([], $cumulative->getStudentCumulativeRecord(2, query: ['tab' => 'cases'])['authorizedCases']);
        foreach (self::personReadPaths(2) as $path) self::assertNull($this->filter($path));

        $this->signIn('guidance_admin');
        self::assertNull($this->filter('guidance/cases/12'));
        $this->revoke('guidance_admin', 'guidance.cases.read');
        self::assertContains('guidance.cases.read_any', session('user')['permissions']);
        foreach (array_merge(['guidance/clients'], self::personReadPaths(2), self::workspaceReadPaths(12)) as $path) {
            self::assertSame(403, $this->filter($path)?->getStatusCode());
        }
    }

    public static function invalidConsultationShares(): iterable
    {
        yield 'pending' => [['status' => 'pending']];
        yield 'declined' => [['status' => 'declined']];
        yield 'cancelled' => [['status' => 'cancelled']];
        yield 'accepted full transfer without ownership' => [['transfer_type' => 'full_transfer']];
        yield 'different case' => [['case_id' => 14]];
        yield 'different recipient' => [['to_counsellor_user_id' => 2]];
        yield 'different tenant' => [['tenant_id' => 2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConsultationShares')]
    public function testWebFilterNonQualifyingTransferDoesNotExposeWorkspace(array $share): void
    {
        $this->consultation($share);
        foreach (self::personReadPaths(2) as $path) self::assertNull($this->filter($path));
        foreach (self::workspaceReadPaths(12) as $path) self::assertSame(403, $this->filter($path)?->getStatusCode());
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testWebFilterConsultationAndReadRevocationAreRefreshedWithoutResignIn(): void
    {
        $transferId = $this->consultation();
        self::assertNull($this->filter('guidance/cases/12'));
        $this->fixture->table('guidance_case_transfers')->where('id', $transferId)->update(['status' => 'cancelled']);
        foreach (self::workspaceReadPaths(12) as $path) self::assertSame(403, $this->filter($path)?->getStatusCode());
        $this->fixture->table('guidance_case_transfers')->where('id', $transferId)->update(['status' => 'accepted']);
        self::assertNull($this->filter('guidance/cases/12'));
        $this->revoke('counsellor', 'guidance.cases.read');
        foreach (array_merge(['guidance/clients'], self::personReadPaths(2), self::workspaceReadPaths(12)) as $path) {
            self::assertSame(403, $this->filter($path)?->getStatusCode());
        }
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public static function inactiveWebIdentities(): iterable
    {
        yield 'inactive user' => ['users', 'status', 'inactive'];
        yield 'deleted user' => ['users', 'deleted_at', '2026-01-01'];
        yield 'inactive tenant' => ['tenants', 'is_active', 0];
        yield 'deleted tenant' => ['tenants', 'deleted_at', '2026-01-01'];
        yield 'deleted linked actor' => ['people', 'deleted_at', '2026-01-01'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('inactiveWebIdentities')]
    public function testWebFilterInactiveIdentityCannotReadClientsOrRecords(string $table, string $column, mixed $value): void
    {
        $this->consultation();
        self::assertNull($this->filter('guidance/clients'));
        $this->fixture->table($table)->where('id', 1)->update([$column => $value]);
        foreach (array_merge(['guidance/clients'], self::personReadPaths(2), self::workspaceReadPaths(12)) as $path) {
            $this->signIn();
            self::assertSame(401, $this->filter($path)?->getStatusCode());
            self::assertNull(session('user'));
        }
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testWebFilterPersonCaseAndSessionSafeguardsStayTenantBoundAndNonDeleted(): void
    {
        // Even a synthetic accepted share cannot turn a foreign-tenant ID into a local record.
        $this->consultation(['case_id' => 13]);
        foreach (['counsellor', 'guidance_supervisor', 'superadmin'] as $role) {
            $this->signIn($role);
            foreach (array_merge(self::personReadPaths(3), self::personReadPaths(999), self::workspaceReadPaths(13)) as $path) {
                self::assertSame(404, $this->filter($path)?->getStatusCode());
            }
        }
        $this->signIn('guidance_supervisor');
        $this->fixture->table('counselling_cases')->where('id', 12)->update(['person_id' => 3]);
        foreach (self::workspaceReadPaths(12) as $path) self::assertSame(404, $this->filter($path)?->getStatusCode());
        $this->fixture->table('counselling_cases')->where('id', 12)->update(['person_id' => 2]);
        $this->fixture->table('people')->where('id', 2)->update(['deleted_at' => '2026-01-01']);
        foreach (array_merge(self::personReadPaths(2), self::workspaceReadPaths(12)) as $path) {
            self::assertSame(404, $this->filter($path)?->getStatusCode());
        }
        $this->fixture->table('people')->where('id', 2)->update(['deleted_at' => null]);
        $this->fixture->table('counselling_cases')->where('id', 12)->update(['deleted_at' => '2026-01-01']);
        foreach (self::workspaceReadPaths(12) as $path) self::assertSame(404, $this->filter($path)?->getStatusCode());
        $this->fixture->table('counselling_cases')->where('id', 12)->update(['deleted_at' => null]);
        $this->fixture->table('counselling_sessions')->where('id', 12)->update(['deleted_at' => '2026-01-01']);
        self::assertSame(404, $this->filter('guidance/sessions/12/read')?->getStatusCode());
        self::assertSame(404, $this->filter('guidance/sessions/12/history')?->getStatusCode());
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testWebFilterClinicRolesCannotReadGuidanceClientsRecordsOrSharedCases(): void
    {
        $this->consultation();
        foreach (['clinic_admin', 'clinic_physician', 'clinic_staff', 'clinic_intern'] as $role) {
            $this->signIn($role);
            foreach (array_merge(['guidance/clients'], self::personReadPaths(1), self::personReadPaths(2),
                self::workspaceReadPaths(11), self::workspaceReadPaths(12)) as $path) {
                $cached = session('user');
                $cached['role'] = 'counsellor';
                $cached['permissions'] = ['guidance.cases.read', 'guidance.cases.read_any'];
                session()->set('user', $cached);
                self::assertSame(403, $this->filter($path)?->getStatusCode());
                self::assertSame($role, session('user')['role']);
            }
        }
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testWebFilterClinicSharingAndEncounterMutationGuardsAreUnchanged(): void
    {
        foreach (['clinic_staff', 'clinic_admin', 'superadmin'] as $role) {
            $this->signIn($role);
            self::assertNull($this->filter('clinic/patients/2'));
            self::assertNull($this->filter('clinic/encounters/12'));
            self::assertNull($this->filter('clinic/encounters/12/assessment', 'POST'));
            self::assertSame(404, $this->filter('clinic/patients/3')?->getStatusCode());
            self::assertSame(404, $this->filter('clinic/encounters/13')?->getStatusCode());
        }
        $this->signIn('clinic_staff');
        $this->fixture->table('clinic_encounters')->where('id', 12)->update(['status' => 'completed']);
        self::assertSame(409, $this->filter('clinic/encounters/12/assessment', 'POST')?->getStatusCode());
        self::assertNull($this->filter('clinic/encounters/12/addendum', 'POST'));
        $this->revoke('clinic_staff', 'clinic.encounters.read');
        self::assertSame(403, $this->filter('clinic/encounters/12')?->getStatusCode());
        $this->revoke('clinic_staff', 'clinic.patients.read');
        self::assertSame(403, $this->filter('clinic/patients/2')?->getStatusCode());
    }

    public function testWebFilterGuidanceOwnerWritesDoNotAcquireReadGrantDependency(): void
    {
        $this->revoke('counsellor', 'guidance.cases.read');
        self::assertSame(403, $this->filter('guidance/cases/11')?->getStatusCode());
        self::assertNull($this->filter('guidance/sessions/11/notes', 'POST'));
        self::assertNull($this->filter('guidance/cases/11/session', 'POST'));
        $this->revoke('counsellor', 'guidance.cases.write');
        self::assertSame(403, $this->filter('guidance/sessions/11/notes', 'POST')?->getStatusCode());
        self::assertNull($this->filter('guidance/cases/11/session', 'POST'));
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testWebFilterClientAndCaseMappingUsesInstallationRelativePaths(): void
    {
        $this->consultation();
        foreach (['http://localhost/', 'http://localhost/campus/'] as $baseURL) {
            foreach (['', 'index.php'] as $indexPage) {
                foreach (array_merge(['guidance/clients'], self::personReadPaths(2), self::workspaceReadPaths(12)) as $path) {
                    self::assertNull($this->filter($path, 'GET', $baseURL, $indexPage));
                }
                foreach (self::personReadPaths(3) as $path) {
                    self::assertSame(404, $this->filter($path, 'GET', $baseURL, $indexPage)?->getStatusCode());
                }
            }
        }
    }

    public function testHttpConsultationPlaintextReadStillRequiresSuccessfulAuditAppend(): void
    {
        $this->consultation();
        $result = $this->withSession(session()->get())->get('guidance/sessions/12/read?fragment=1');
        $result->assertStatus(200);
        $result->assertSee('Synthetic draft');
        $result->assertSee('Oversight mode');
        $result->assertSee('This access has been audited');
        $events = $this->fixture->table('audit_events')->get()->getResultArray();
        self::assertSame(['guidance.case.oversight_read', 'guidance.session.note_read'], array_column($events, 'event_type'));
        foreach ($events as $event) self::assertNull($event['payload_json']);
        $this->breakAudit('append_failure');
        $result = $this->withSession(session()->get())->get('guidance/sessions/12/read?fragment=1');
        $result->assertStatus(500);
        $result->assertDontSee('Synthetic draft');
        $result->assertDontSee('This access has been audited');
        self::assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public static function metadataClientRoutes(): iterable
    {
        yield 'directory' => ['guidance/clients', 'Guidance Clients &amp; Records'];
        yield 'fragment directory' => ['guidance/clients?fragment=1', 'Guidance Clients'];
        yield 'record' => ['guidance/clients/1', 'Guidance Record'];
        yield 'legacy record' => ['guidance/students/1/cumulative?tab=cases', 'Authorized counseling cases'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('metadataClientRoutes')]
    public function testClientMetadataHttpReadsDoNotRequireClinicalEncryptionKey(string $route, string $heading): void
    {
        putenv('COUNSELLING_KEY=invalid-synthetic-key');
        $_ENV['COUNSELLING_KEY'] = $_SERVER['COUNSELLING_KEY'] = 'invalid-synthetic-key';
        $before = $this->fixture->table('counselling_cases')->orderBy('id')->get()->getResultArray();
        $result = $this->withSession(session()->get())->get($route);
        $result->assertStatus(200);
        $result->assertSee($heading);
        $result->assertDontSee('Synthetic draft');
        $result->assertDontSee('Private summary');
        self::assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        self::assertSame($before, $this->fixture->table('counselling_cases')->orderBy('id')->get()->getResultArray());
    }

    public static function readRoutes(): iterable
    {
        yield 'dialog' => ['guidance/sessions/12/read?fragment=1', 'Synthetic draft'];
        yield 'history' => ['guidance/sessions/12/history?fragment=1', 'Synthetic final'];
        yield 'active case' => ['guidance/cases/12', 'Synthetic draft'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('readRoutes')]
    public function testHttpReadsRenderAuditedOversightOnlyAfterPersistence(string $route, string $plaintext): void
    {
        $this->signIn('guidance_supervisor');
        $result = $this->withSession(session()->get())->get($route);
        $result->assertStatus(200);
        $result->assertSee($plaintext);
        $result->assertSee('This access has been audited');
        $result->assertSee('Oversight mode');
        self::assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $events = $this->fixture->table('audit_events')->orderBy('id')->get()->getResultArray();
        $expected = str_contains($route, '/history')
            ? ['guidance.session.note_history_read']
            : ['guidance.case.oversight_read', 'guidance.session.note_read'];
        self::assertSame($expected, array_column($events, 'event_type'));
        foreach ($events as $event) {
            self::assertNull($event['payload_json']);
            self::assertSame(1, (int) $event['actor_user_id']);
            self::assertSame('guidance_supervisor', $event['actor_role']);
        }
    }

    public static function httpAuditFailures(): iterable
    {
        foreach (self::readRoutes() as $name => [$route, $plaintext]) {
            foreach (['counsellor', 'guidance_supervisor'] as $role) {
                foreach (['missing_table', 'bad_head', 'append_failure'] as $failure) {
                    yield $name . '-' . $role . '-' . $failure => [$route, $plaintext, $role, $failure];
                }
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('httpAuditFailures')]
    public function testHttpAuditAppendFailureReturnsNoPlaintextOrSuccessMarker(string $route, string $plaintext, string $role, string $failure): void
    {
        $this->signIn($role);
        // Owner path proves the active-note audit fails closed independently of case oversight.
        if ($role === 'counsellor') $route = str_replace('/12', '/11', $route);
        $this->breakAudit($failure);
        $result = $this->withSession(session()->get())->get($route);
        $result->assertStatus(500);
        $result->assertDontSee($plaintext);
        $result->assertDontSee('This access has been audited');
        self::assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testEmptyNoteDialogDoesNotClaimDecryptionOrNoteAuditSuccess(): void
    {
        $this->fixture->table('counselling_notes')->where('session_id', 11)->delete();
        $result = $this->withSession(session()->get())->get('guidance/sessions/11/read?fragment=1');
        $result->assertStatus(200);
        $result->assertSee('No counseling notes');
        $result->assertDontSee('AES-256-GCM Decrypted');
        $result->assertDontSee('This access has been audited');
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testCurrentNoteReadPersistsMetadataOnlyOnSameConnection(): void
    {
        self::assertSame('Synthetic draft', $this->service->getDecryptedSessionNote(11)['text']);
        $event = $this->fixture->table('audit_events')->get()->getRowArray();
        self::assertNotNull($event, 'Plaintext must not be returned without a persisted audit event.');
        self::assertSame('guidance.session.note_read', $event['event_type']);
        self::assertSame('counselling_sessions', $event['entity_type']);
        self::assertSame('11', $event['entity_id']);
        self::assertSame(1, (int) $event['actor_user_id']);
        self::assertSame('counsellor', $event['actor_role']);
        self::assertNull($event['payload_json']);
        self::assertTrue((new \App\Services\Audit\AuditService(null, $this->fixture))->verifyChain()['valid']);
        $this->fixture->table('counselling_notes')->where('session_id', 11)->delete();
        self::assertNull($this->service->getDecryptedSessionNote(11));
        self::assertSame(1, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testCrossOwnerCaseReadsAreAuditedWithoutNotesAndOwnerReadsAreNotOversight(): void
    {
        $this->service->getCaseDetails(11);
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
        foreach (['guidance_admin', 'guidance_supervisor', 'superadmin'] as $role) {
            $this->signIn($role);
            $this->service->getCaseDetails(14);
            $event = $this->fixture->table('audit_events')->orderBy('id', 'DESC')->get()->getRowArray();
            self::assertNotNull($event, 'Oversight without a note must still be audited.');
            self::assertSame('guidance.case.oversight_read', $event['event_type']);
            self::assertSame('counselling_cases', $event['entity_type']);
            self::assertSame('14', $event['entity_id']);
            self::assertSame($role, $event['actor_role']);
            self::assertNull($event['payload_json']);
        }
        self::assertSame(3, $this->fixture->table('audit_events')->countAllResults());
    }

    public static function auditFailures(): iterable
    {
        foreach (['missing_table', 'bad_head', 'append_failure'] as $failure) {
            foreach (['getDecryptedSessionNote', 'getDecryptedSessionNoteHistory', 'getCaseDetails'] as $method) {
                yield $failure . '-' . $method => [$failure, $method];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('auditFailures')]
    public function testAuditFailureNeverReturnsSensitiveRead(string $failure, string $method): void
    {
        $this->signIn('guidance_supervisor');
        $this->breakAudit($failure);
        $result = null;
        $failureThrown = null;
        try {
            $result = $this->service->{$method}(12);
        } catch (\Throwable $exception) {
            $failureThrown = $exception;
        }
        self::assertNotNull($failureThrown, 'Read must fail closed when audit append cannot persist.');
        self::assertNull($result, 'No plaintext or case package may escape audit failure.');
    }

    private function breakAudit(string $failure): void
    {
        if ($failure === 'missing_table') {
            $this->fixture->query('DROP TABLE audit_events');
        } elseif ($failure === 'bad_head') {
            (new \App\Services\Audit\AuditService(null, $this->fixture))->log('fixture', 'fixture', '1', 'fixture');
            $this->fixture->table('audit_events')->update(['curr_hash' => str_repeat('f', 64)]);
        } else {
            $this->fixture->query("CREATE TRIGGER refuse_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'Synthetic append failure'); END");
        }
    }

    public function testMutationAuthorizationDoesNotRecordOversightRead(): void
    {
        $this->signIn('guidance_admin');
        $authorize = new ReflectionMethod(GuidanceCaseService::class, 'authorizedCase');
        [$case] = $authorize->invoke($this->service, 12, 'guidance.cases.write');
        self::assertSame(12, (int) $case['id']);
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
        foreach (['addFollowup', 'completeFollowup'] as $method) {
            $reflection = new ReflectionMethod(\App\Controllers\GuidanceController::class, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1));
            self::assertStringNotContainsString('getCaseDetails(', $source);
        }
    }

    public function testFailedDecryptionDoesNotClaimSuccessfulNoteRead(): void
    {
        $this->fixture->table('counselling_notes')->where('session_id', 11)->where('is_draft', 1)
            ->update(['notes_tag' => 'invalid']);
        try {
            $this->service->getDecryptedSessionNote(11);
            self::fail('Invalid GCM tag must not decrypt.');
        } catch (\CodeIgniter\Security\Exceptions\SecurityException $exception) {
            self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
        }
    }

    public function testEmptyOversightDialogAuditsOnlyCaseAccess(): void
    {
        $this->signIn('guidance_supervisor');
        $result = $this->withSession(session()->get())->get('guidance/sessions/14/read?fragment=1');
        $result->assertStatus(200);
        $result->assertSee('No counseling notes');
        $result->assertSee('Oversight mode');
        $result->assertDontSee('AES-256-GCM Decrypted');
        self::assertSame(['guidance.case.oversight_read'], array_column(
            $this->fixture->table('audit_events')->get()->getResultArray(), 'event_type'));
    }

    public function testDeniedReadsNeverAppendAuditEvents(): void
    {
        $this->assertDenied('getDecryptedSessionNote', 12, 403);
        $this->assertDenied('getCaseDetails', 12, 403);
        $this->signIn('guidance_supervisor');
        $this->assertDenied('getDecryptedSessionNote', 13, 404);
        $this->assertDenied('getCaseDetails', 13, 404);
        $this->revoke('guidance_supervisor', 'guidance.cases.read_any');
        $this->assertDenied('getDecryptedSessionNote', 12, 403);
        $this->assertDenied('getCaseDetails', 12, 403);
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
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
