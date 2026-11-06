<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Api\ApiContext;
use App\Services\AuthenticatedUser;
use App\Services\TeamAccess;
use App\Services\TeamEntitlements;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamAccessTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'access_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        $this->db->query('CREATE TABLE access_tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE access_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT,
            last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE access_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE access_users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER,
            username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE access_role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->table('tenants')->insertBatch([
            ['id' => 1, 'is_active' => 1],
            ['id' => 2, 'is_active' => 1],
        ]);
        $this->db->table('people')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'kind' => 'employee', 'first_name' => 'Clinic', 'last_name' => 'Member'],
            ['id' => 2, 'tenant_id' => 1, 'kind' => 'employee', 'first_name' => 'Guidance', 'last_name' => 'Member'],
        ]);
        $this->db->table('users')->insertBatch([
            ['id' => 1, 'tenant_id' => 1, 'person_id' => 1, 'username' => 'clinic', 'role' => 'clinic_staff', 'status' => 'active'],
            ['id' => 2, 'tenant_id' => 1, 'person_id' => 2, 'username' => 'guidance', 'role' => 'counsellor', 'status' => 'active'],
            ['id' => 3, 'tenant_id' => 1, 'person_id' => null, 'username' => 'root', 'role' => 'superadmin', 'status' => 'active'],
            ['id' => 4, 'tenant_id' => 1, 'person_id' => null, 'username' => 'orphan', 'role' => 'clinic_staff', 'status' => 'active'],
            ['id' => 5, 'tenant_id' => 2, 'person_id' => null, 'username' => 'other-root', 'role' => 'superadmin', 'status' => 'active'],
        ]);
    }

    protected function tearDown(): void
    {
        ApiContext::clear();
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    public function testDatabaseActorOverridesForgedSourceGrants(): void
    {
        $access = new TeamAccess(static fn (): array => [
            'id' => 1,
            'tenant_id' => 1,
            'role' => 'superadmin',
            'permissions' => ['*'],
            'name' => 'Forged',
        ], $this->db);

        $actor = $access->actor('clinic');
        $this->assertSame('clinic_staff', $actor['role']);
        $this->assertSame('Clinic Member', $actor['name']);
        $this->assertFalse(TeamAccess::managesRole('clinic', $actor['role']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(403);
        $access->actor('guidance');
    }

    public function testInvalidUnitIsRejectedAndSuperadminPersonPolicyIsPreserved(): void
    {
        $access = new TeamAccess(static fn (): array => ['id' => 3, 'tenant_id' => 1], $this->db);
        $this->assertSame('superadmin', $access->actor('facilities')['role']);

        try {
            $access->actor('bmg');
            $this->fail('Expected invalid unit rejection.');
        } catch (RuntimeException $error) {
            $this->assertSame(422, $error->getCode());
        }

        $orphan = new TeamAccess(static fn (): array => ['id' => 4, 'tenant_id' => 1], $this->db);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $orphan->actor('clinic');
    }

    public function testBearerReadsApiContextRatherThanBrowserSession(): void
    {
        session()->set([
            'tenant_id' => 1,
            'user' => ['id' => 2, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()],
        ]);
        ApiContext::set([
            'id' => 1,
            'tenant_id' => 1,
            'role' => 'guidance_admin',
            'permissions' => ['forged'],
        ], str_repeat('a', 64));

        $actor = TeamAccess::bearer($this->db)->actor('clinic');
        $this->assertSame(1, $actor['id']);
        $this->assertSame('clinic_staff', $actor['role']);

        ApiContext::clear();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        TeamAccess::bearer($this->db)->actor('clinic');
    }

    public function testEntitlementsRespectRolloutRolesLabelsAndPersonalIdentity(): void
    {
        $clinicAccess = new TeamAccess(static fn (): array => [
            'id' => 1, 'tenant_id' => 1, 'role' => 'superadmin', 'permissions' => ['forged'],
        ], $this->db);
        $off = (new TeamEntitlements($clinicAccess, false, $this->db))->forIdentity(['person_id' => 1, 'tenant_id' => 1]);
        $this->assertTrue($off['personal']['enabled']);
        $this->assertSame(['enabled' => false, 'units' => []], $off['work']);

        $clinic = (new TeamEntitlements($clinicAccess, true, $this->db))->forIdentity(['person_id' => 1, 'tenant_id' => 1]);
        $this->assertTrue($clinic['work']['enabled']);
        $this->assertSame(['clinic'], array_column($clinic['work']['units'], 'unit'));
        $this->assertSame('Clinic', $clinic['work']['units'][0]['label']);
        $this->assertFalse($clinic['work']['units'][0]['can_manage']);
        $this->assertSame([
            'team_board' => true, 'my_tasks' => true, 'team_chat' => true, 'team_calendar' => true,
        ], $clinic['work']['units'][0]['features']);

        $guidanceAccess = new TeamAccess(static fn (): array => ['id' => 2, 'tenant_id' => 1], $this->db);
        $guidance = (new TeamEntitlements($guidanceAccess, true, $this->db))->forIdentity(['person_id' => 2, 'tenant_id' => 1]);
        $this->assertSame(['guidance'], array_column($guidance['work']['units'], 'unit'));

        $rootAccess = new TeamAccess(static fn (): array => ['id' => 3, 'tenant_id' => 1], $this->db);
        $root = (new TeamEntitlements($rootAccess, true, $this->db))->forIdentity(['person_id' => null, 'tenant_id' => 1]);
        $this->assertFalse($root['personal']['enabled']);
        $this->assertSame(['clinic', 'guidance', 'facilities'], array_column($root['work']['units'], 'unit'));
        $this->assertSame(['Clinic', 'Guidance', 'BMG Facilities'], array_column($root['work']['units'], 'label'));
        $this->assertSame([true, true, true], array_column($root['work']['units'], 'can_manage'));
    }

    public function testTenantAlwaysComesFromValidatedIdentity(): void
    {
        $access = new TeamAccess(static fn (): array => ['id' => 5, 'tenant_id' => 1, 'role' => 'superadmin'], $this->db);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(401);
        $access->actor('clinic');
    }
}
