<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Session\Handlers\FileHandler;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\Mock\MockSession;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use ReflectionProperty;

/** Real application routes, filters, authentication and HTML; private SQLite, no migrations. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PortalVisitHistoryRoutesTest extends CIUnitTestCase
{
    use FeatureTestTrait { setupRequest as private setupBaseRequest; }

    private int $sourceId = 810000;
    private int $caseId = 820000;
    private array $tables = [];

    protected function setupRequest(string $method, ?string $path = null): IncomingRequest
    {
        $request = $this->setupBaseRequest($method, $path);
        $request->setGlobal('server', ['HTTP_HOST' => 'localhost:8080']);
        $request->getUri()->setHost('localhost');
        $request->getUri()->setPort(8080);
        return $request;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $settings = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'visit_http_', 'DBDebug' => true];
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = config('Database')->default = $settings;
        $this->db = new Connection($settings);
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
            ->setValue(null, ['tests' => $this->db, 'default' => $this->db]);
        $this->db->initialize();
        self::assertSame($this->db, Database::connect());
        self::assertSame($this->db, Database::connect('default'));
        self::assertSame(':memory:', $this->db->database);

        $sessionConfig = config('Session');
        $sessionConfig->driver = FileHandler::class;
        $handler = new FileHandler($sessionConfig, '127.0.0.1');
        Services::injectMock('session', new MockSession($handler, $sessionConfig));
        $_SESSION = [];
        CurrentTenant::reset();
        helper(['form', 'url']);
        $this->schema();
        $this->seed();
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function schema(): void
    {
        // Same visit projection schema as the isolated service tests, plus current-auth tables.
        $definitions = [
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'clinic_encounters' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, attending_user_id INTEGER, appointment_id INTEGER,
                status TEXT, started_at TEXT, completed_at TEXT, deleted_at TEXT, chief_complaint TEXT, assessment_notes TEXT, disposition TEXT, disposition_notes TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, deleted_at TEXT, title TEXT, case_number TEXT, summary_notes TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, appointment_id INTEGER,
                status TEXT, started_at TEXT, ended_at TEXT, deleted_at TEXT',
            'counselling_notes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, session_id INTEGER, notes_cipher TEXT, risk_level TEXT',
        ];
        $this->tables = array_keys($definitions);
        foreach ($definitions as $table => $columns) {
            $this->db->query('CREATE TABLE ' . $this->db->prefixTable($table) . ' (' . $columns . ')');
        }
    }

    private function seed(): void
    {
        $this->db->table('tenants')->insertBatch([['id' => 7, 'is_active' => 1], ['id' => 8, 'is_active' => 1]]);
        foreach ([[1, 88, 7, 'student'], [2, 89, 7, 'employee'], [3, 91, 7, 'clinic_staff'],
            [4, 90, 8, 'student'], [5, 92, 7, 'employee'], [10, 100, 7, 'counsellor']] as [$id, $person, $tenant, $role]) {
            $this->db->table('people')->insert(['id' => $person, 'tenant_id' => $tenant,
                'first_name' => $id === 10 ? 'Care' : 'Patient', 'last_name' => $id === 10 ? 'Provider' : (string) $person]);
            $this->db->table('users')->insert(['id' => $id, 'person_id' => $person, 'tenant_id' => $tenant,
                'username' => 'visit-user-' . $id, 'role' => $role, 'status' => 'active', 'auth_version' => 1]);
        }
        $this->db->table('permissions')->insert(['id' => 1, 'code' => 'portal.records.read']);
        // No appointment permission: the route must require records.read only.
        foreach (['student', 'employee'] as $role) {
            $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => 1]);
        }
    }

    private function identity(int $id): array
    {
        $tenant = $id === 4 ? 8 : 7;
        // Deliberately stale cached claims: real filters must refresh person/role/permissions.
        return ['tenant_id' => $tenant, 'user' => ['id' => $id, 'tenant_id' => $tenant,
            'person_id' => 99999, 'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['*', 'portal.records.read']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function requestAs(?int $id, array $query = [], array $headers = []): TestResponse
    {
        foreach (['renderer', 'response', 'redirectresponse'] as $service) Services::resetSingle($service);
        AuthenticatedUser::clear();
        return $this->withSession($id === null ? [] : $this->identity($id))->withHeaders($headers)
            ->get('portal/visits' . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    private function visit(string $unit, int $person = 88, int $tenant = 7, array $changes = []): int
    {
        $id = ++$this->sourceId;
        $common = ['id' => $id, 'tenant_id' => $tenant, 'appointment_id' => null, 'status' => 'completed',
            'started_at' => '2026-10-20 09:00:00'];
        if ($unit === 'clinic') {
            $this->db->table('clinic_encounters')->insert(array_replace($common + [
                'person_id' => $person, 'attending_user_id' => 10, 'completed_at' => '2026-10-20 09:30:00',
                'chief_complaint' => 'SECRET-COMPLAINT', 'assessment_notes' => 'SECRET-DIAGNOSIS',
                'disposition' => 'SECRET-DISPOSITION', 'disposition_notes' => 'SECRET-NOTES',
            ], $changes));
        } else {
            $case = ++$this->caseId;
            $this->db->table('counselling_cases')->insert(['id' => $case, 'tenant_id' => $tenant, 'person_id' => $person,
                'status' => 'active', 'title' => 'SECRET-TITLE', 'case_number' => 'SECRET-CASE-NUMBER', 'summary_notes' => 'SECRET-SUMMARY']);
            $this->db->table('counselling_sessions')->insert(array_replace($common + [
                'case_id' => $case, 'counsellor_user_id' => 10, 'ended_at' => '2026-10-20 10:00:00',
            ], $changes));
            $this->db->table('counselling_notes')->insert(['id' => $id, 'tenant_id' => $tenant, 'session_id' => $id,
                'notes_cipher' => 'SECRET-CIPHERTEXT', 'risk_level' => 'SECRET-RISK']);
        }
        return $id;
    }

    private function html(TestResponse $result, int $status = 200): string
    {
        $result->assertStatus($status);
        $cache = $result->response()->getHeaderLine('Cache-Control');
        self::assertStringContainsString('private', $cache);
        self::assertStringContainsString('no-store', $cache);
        return (string) $result->response()->getBody();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $original = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($original);
        }
        return new DOMXPath($document);
    }

    private function assertRows(string $html, int $count): void
    {
        self::assertSame($count, $this->xpath($html)->query('//tr[@data-visit-row]')->length);
    }

    private function visitText(string $html): string
    {
        $text = '';
        foreach ($this->xpath($html)->query('//tr[@data-visit-row]') as $row) $text .= $row->textContent;
        return $text;
    }

    public function testStudentsAndEmployeesSeeOnlyTheirOwnCompletedWalkInsAndAppointments(): void
    {
        foreach ([88, 89] as $person) {
            $day = $person === 88 ? '20' : '21';
            $this->visit('clinic', $person, 7, ['status' => 'referred', 'appointment_id' => 840001,
                'started_at' => '2026-10-' . $day . ' 09:00:00', 'completed_at' => '2026-10-' . $day . ' 09:30:00']);
            $this->visit('guidance', $person, 7, ['started_at' => '2026-10-' . $day . ' 09:00:00', 'ended_at' => '2026-10-' . $day . ' 10:00:00']);
            $this->visit('clinic', $person, 7, ['status' => 'in_progress']);
            $this->visit('guidance', $person, 7, ['status' => 'cancelled']);
        }
        $this->visit('clinic', 92);
        foreach ([1 => 'student', 2 => 'employee'] as $id => $role) {
            $html = $this->html($this->requestAs($id));
            $this->assertRows($html, 2);
            self::assertStringContainsString('Completed clinic visit', $this->visitText($html));
            self::assertStringContainsString('Completed guidance session', $this->visitText($html));
            self::assertStringContainsString('By appointment', $html);
            self::assertStringContainsString('Walk-in', $html);
            self::assertStringContainsString('Care Provider', $html);
            self::assertStringContainsString($id === 1 ? 'Oct 20, 2026 · 9:00 AM' : 'Oct 21, 2026 · 9:00 AM', $this->visitText($html));
            self::assertStringNotContainsString($id === 1 ? 'Oct 21, 2026' : 'Oct 20, 2026', $this->visitText($html));
            self::assertStringContainsString('Showing 1–2 of 2 visits', $html);
            self::assertSame($role, session()->get('user')['role']);
            self::assertNotContains('portal.appointments.read', session()->get('user')['permissions']);
            self::assertStringNotContainsString('SECRET-', $html);
            foreach (['810001', '820001', '840001'] as $internalId) self::assertStringNotContainsString($internalId, $html);
            $links = $this->xpath($html)->query('//section[@data-filter-key="personal-visit-history"]//a[@href]');
            foreach ($links as $link) {
                self::assertDoesNotMatchRegularExpression('~/(encounters|cases|sessions|records)/[0-9]+|(?:source_id|case_id|person_id)=~', $link->getAttribute('href'));
            }
        }
    }

    public function testStaffUsePersonalOwnershipRatherThanDepartmentReadAuthority(): void
    {
        $this->visit('clinic', 91);
        $this->visit('guidance', 91);
        for ($i = 0; $i < 4; $i++) $this->visit('clinic', 88);
        $html = $this->html($this->requestAs(3, ['person_id' => 88, 'tenant_id' => 7]));
        $this->assertRows($html, 2);
        self::assertStringContainsString('Showing 1–2 of 2 visits', $html);
        self::assertSame('clinic_staff', session()->get('user')['role']);
        self::assertSame(91, session()->get('user')['person_id']);
        self::assertStringNotContainsString('SECRET-', $html);
    }

    public function testQueryIdentityAndSourceSelectorsCannotExposeOtherPeopleOrTenants(): void
    {
        $this->visit('clinic');
        $foreign = $this->visit('guidance', 90, 8);
        $this->visit('clinic', 89);
        // Deliberately malformed cross-tenant rows sharing the caller's person ID.
        $this->visit('clinic', 88, 8);
        $this->visit('guidance', 88, 8);
        $html = $this->html($this->requestAs(1, ['person_id' => 90, 'tenant_id' => 8, 'user_id' => 4,
            'id' => $foreign, 'source_id' => $foreign, 'case_id' => $this->caseId, 'appointment_id' => 840001,
            'fragment' => '1', '_live' => '1']));
        $this->assertRows($html, 1);
        self::assertStringContainsString('Completed clinic visit', $this->visitText($html));
        self::assertStringNotContainsString('Completed guidance session', $this->visitText($html));
        self::assertStringNotContainsString((string) $foreign, $html);
        $other = $this->html($this->requestAs(4, ['person_id' => 88, 'tenant_id' => 7]));
        $this->assertRows($other, 1);
        self::assertStringContainsString('Completed guidance session', $this->visitText($other));
        self::assertStringNotContainsString('Completed clinic visit', $this->visitText($other));
    }

    public function testServiceLiveFilteringAndTwentyFiveRowPaginationRetainOnlySafeParameters(): void
    {
        for ($i = 0; $i < 28; $i++) $this->visit('clinic', 88, 7, ['appointment_id' => 840001]);
        for ($i = 0; $i < 3; $i++) $this->visit('guidance');
        $first = $this->html($this->requestAs(1, ['service' => 'all', 'person_id' => 90]));
        $this->assertRows($first, 25);
        self::assertStringContainsString('Showing 1–25 of 31 visits', $first);
        self::assertSame(1, $this->xpath($first)->query('//section[@data-live-filters and @data-filter-key="personal-visit-history"]')->length);
        self::assertSame(1, $this->xpath($first)->query('//form[@data-filter-form]//select[@name="service"]')->length);
        foreach ($this->xpath($first)->query('//a[@data-filter-link]') as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame(['service' => 'all', 'page' => '2'], $query);
        }
        $second = $this->html($this->requestAs(1, ['service' => 'all', 'page' => 2]));
        $this->assertRows($second, 6);
        self::assertStringContainsString('Showing 26–31 of 31 visits', $second);
        $clinic = $this->html($this->requestAs(1, ['service' => 'clinic', 'page' => 999]));
        $this->assertRows($clinic, 3);
        self::assertStringContainsString('Showing 26–28 of 28 visits', $clinic);
        self::assertStringNotContainsString('Completed guidance session', $this->visitText($clinic));
        $guidance = $this->html($this->requestAs(1, ['service' => 'guidance'], ['X-Requested-With' => 'XMLHttpRequest']));
        $this->assertRows($guidance, 3);
        self::assertStringNotContainsString('Completed clinic visit', $this->visitText($guidance));
        self::assertSame(1, $this->xpath($guidance)->query('//select[@name="service"]/option[@value="guidance" and @selected]')->length);
        $empty = $this->html($this->requestAs(5));
        $this->assertRows($empty, 0);
        self::assertStringContainsString('No completed visits', $empty);
        self::assertStringContainsString('Showing 0–0 of 0 visits', $empty);
    }

    public function testMissingOrRevokedPermissionIsForbiddenDespiteCachedClaims(): void
    {
        $this->html($this->requestAs(1));
        $this->db->table('role_permissions')->where('role', 'student')->delete();
        $result = $this->requestAs(1, [], ['Accept' => 'application/json']);
        $body = (string) $result->response()->getBody();
        $result->assertStatus(403);
        self::assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('portal.records.read', $body);
        self::assertStringNotContainsString('SECRET-', $body);
        self::assertNotContains('portal.records.read', session()->get('user')['permissions']);
    }

    public function testAnonymousExpiredAndDeletedIdentitiesAreDenied(): void
    {
        $anonymous = $this->requestAs(null, [], ['Accept' => 'application/json']);
        $anonymous->assertStatus(401);
        self::assertStringContainsString('no-store', $anonymous->response()->getHeaderLine('Cache-Control'));
        $this->db->table('people')->where('id', 88)->update(['deleted_at' => '2026-10-23 00:00:00']);
        $deleted = $this->requestAs(1, [], ['Accept' => 'application/json']);
        $deleted->assertStatus(401);
        self::assertNull(session()->get('user'));
        $this->db->table('people')->where('id', 88)->update(['deleted_at' => null]);
        $this->db->table('users')->where('id', 1)->update(['auth_version' => 2]);
        $expired = $this->requestAs(1, [], ['Accept' => 'application/json']);
        $expired->assertStatus(401);
        self::assertStringNotContainsString('SECRET-', (string) $expired->response()->getBody());
    }

    public function testInvalidServiceAndCanonicalPageValidationReturn422(): void
    {
        foreach ([['service' => 'Clinic'], ['service' => 'unknown'], ['service' => ['clinic']],
            ['page' => '0'], ['page' => '01'], ['page' => '-1'], ['page' => '1.0'], ['page' => '1000001'], ['page' => ['1']]] as $query) {
            $body = $this->html($this->requestAs(1, $query, ['Accept' => 'application/json']), 422);
            self::assertSame(['message' => 'Invalid visit history filters.'], json_decode($body, true));
        }
    }

    public function testStorageFailureIsGeneric503WithoutSqlOrClinicalDetails(): void
    {
        $this->db->query('DROP TABLE ' . $this->db->prefixTable('clinic_encounters'));
        $body = $this->html($this->requestAs(1, [], ['Accept' => 'application/json']), 503);
        self::assertSame(['message' => 'Visit history is temporarily unavailable. Please try again later.'], json_decode($body, true));
        foreach (['SELECT', 'clinic_encounters', 'visit_http_', 'SECRET-', 'SQLite'] as $detail) {
            self::assertStringNotContainsString($detail, $body);
        }
    }

    public function testRequestsAreReadOnlyAndStaffNamesAreHtmlEscaped(): void
    {
        $this->visit('clinic');
        $this->visit('guidance');
        $this->db->table('people')->where('id', 100)->update(['first_name' => '<script>staffAttack()</script>', 'last_name' => 'Provider']);
        $before = [];
        foreach ($this->tables as $table) $before[$table] = $this->db->table($table)->get()->getResultArray();
        $html = $this->html($this->requestAs(1));
        self::assertStringNotContainsString('<script>staffAttack()</script>', $html);
        self::assertStringContainsString('&lt;script&gt;staffAttack()&lt;/script&gt;', $html);
        $this->html($this->requestAs(1, ['service' => 'guidance', 'page' => 1000000]));
        foreach ($this->tables as $table) self::assertSame($before[$table], $this->db->table($table)->get()->getResultArray(), $table . ' changed on GET');
    }
}
