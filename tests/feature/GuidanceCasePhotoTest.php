<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\ProfileAvatar;
use App\Services\ProfilePhotoService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Real routes and controller, with private SQLite fixtures and no operational migrations. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GuidanceCasePhotoTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private int $tenantId;
    private ?string $photoFile = null;
    private ?string $photoDirectory = null;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'gphoto_', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        // Never share a private photo namespace with a real tenant or another test run.
        do {
            $this->tenantId = random_int(100000000, 2000000000);
            $folder = dirname((new ProfilePhotoService())->path($this->tenantId, 11));
        } while (file_exists($folder));

        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'student_profiles' => 'id INTEGER PRIMARY KEY, person_id INTEGER, tenant_id INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, session_number INTEGER, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, due_date TEXT',
        ] as $table => $columns) {
            $this->db->query('CREATE TABLE gphoto_' . $table . ' (' . $columns . ')');
        }
        $this->db->table('tenants')->insertBatch([
            ['id' => $this->tenantId, 'is_active' => 1], ['id' => $this->tenantId + 1, 'is_active' => 1],
        ]);
        foreach ([1 => 'counsellor', 2 => 'counsellor', 3 => 'guidance_supervisor',
            4 => 'superadmin', 5 => 'clinic_staff', 6 => 'student'] as $id => $role) {
            $this->person($id, 'Staff', 'Viewer');
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $this->tenantId, 'person_id' => $id,
                'username' => 'staff' . $id, 'role' => $role, 'status' => 'active']);
        }
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        $this->person(11, 'Alice', 'Subject');
        $this->person(12, 'Edgar', 'Client', ['kind' => 'employee']);
        $this->person(13, 'Deleted', 'Subject', ['deleted_at' => '2026-01-01']);
        $this->person(14, 'Foreign', 'Subject', ['tenant_id' => $this->tenantId + 1]);
        foreach ([1 => [11, 1], 2 => [12, 2], 3 => [13, 1], 4 => [14, 1], 5 => [11, 1], 6 => [14, 1]] as $id => [$person, $owner]) {
            $this->db->table('counselling_cases')->insert(['id' => $id,
                'tenant_id' => $id === 6 ? $this->tenantId + 1 : $this->tenantId,
                'person_id' => $person, 'counsellor_user_id' => $owner, 'status' => 'open',
                'deleted_at' => $id === 5 ? '2026-01-01' : null]);
        }
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        if ($this->photoFile !== null) unlink($this->photoFile);
        if ($this->photoDirectory !== null) rmdir($this->photoDirectory);
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function person(int $id, string $first, string $last, array $extra = []): void
    {
        $this->db->table('people')->insert($extra + ['id' => $id, 'tenant_id' => $this->tenantId,
            'kind' => 'student', 'first_name' => $first, 'last_name' => $last, 'mis_id' => 'PHOTO-TEST-' . $id]);
    }

    private function identity(int $id): array
    {
        // Stale/forged session privileges must be replaced by the database grants.
        return ['tenant_id' => $this->tenantId, 'user' => ['id' => $id, 'tenant_id' => $this->tenantId,
            'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['guidance.cases.read', 'guidance.cases.read_any']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function photo(?int $actor, int $case = 1)
    {
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        AuthenticatedUser::clear();
        return $this->withSession($actor === null ? [] : $this->identity($actor))
            ->withHeaders(['Accept' => 'application/json'])->get('guidance/cases/' . $case . '/photo');
    }

    private function assertPrivateImage($result, string $type): void
    {
        $result->assertStatus(200);
        $response = $result->response();
        $this->assertStringStartsWith($type, $response->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        $this->assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Resource-Policy'));
    }

    public static function allowedSubjects(): iterable
    {
        yield 'assigned counselor, student subject' => [1, 1, 'Alice', 'Subject', 'AS'];
        yield 'assigned counselor, employee subject' => [2, 2, 'Edgar', 'Client', 'EC'];
        yield 'supervisor can read another counselors subject' => [3, 1, 'Alice', 'Subject', 'AS'];
        yield 'superadmin can read another counselors subject' => [4, 2, 'Edgar', 'Client', 'EC'];
    }

    #[DataProvider('allowedSubjects')]
    public function testMissingPhotoReturnsSubjectInitialsNotStaffInitials(int $actor, int $case, string $first, string $last, string $initials): void
    {
        $result = $this->photo($actor, $case);
        $this->assertPrivateImage($result, 'image/svg+xml');
        $this->assertSame(ProfileAvatar::svg(['first_name' => $first, 'last_name' => $last]), $result->getBody());
        $this->assertStringContainsString('>' . $initials . '</text>', $result->getBody());
        $this->assertStringNotContainsString('>SV</text>', $result->getBody());
    }

    public static function deniedPhotos(): iterable
    {
        yield 'unauthenticated' => [null, 1, 401];
        yield 'other counselors case despite forged oversight' => [2, 1, 403];
        yield 'clinic staff' => [5, 1, 403];
        yield 'student account' => [6, 1, 403];
        foreach ([1, 3, 4] as $actor) {
            yield 'deleted subject for actor ' . $actor => [$actor, 3, 404];
            yield 'foreign subject linked to local case for actor ' . $actor => [$actor, 4, 404];
            yield 'deleted case for actor ' . $actor => [$actor, 5, 404];
            yield 'foreign case for actor ' . $actor => [$actor, 6, 404];
            yield 'missing case for actor ' . $actor => [$actor, 999, 404];
        }
    }

    #[DataProvider('deniedPhotos')]
    public function testPhotoRouteEnforcesCaseAuthorization(?int $actor, int $case, int $status): void
    {
        $result = $this->photo($actor, $case);
        $result->assertStatus($status);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringNotContainsString('<svg', $result->getBody());
        $this->assertStringNotContainsString('image/webp', $result->response()->getHeaderLine('Content-Type'));
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        $this->assertNotNull($permission);
        $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $permission['id'])->delete();
    }

    public function testReadPermissionIsRequiredEvenForAssignedCounselor(): void
    {
        $this->revoke('counsellor', 'guidance.cases.read');
        $this->photo(1)->assertStatus(403);
    }

    public function testReadAnyDoesNotReplaceReadPermission(): void
    {
        $this->revoke('guidance_supervisor', 'guidance.cases.read');
        $this->photo(3)->assertStatus(403);
    }

    public function testRevokingReadAnyImmediatelyDeniesAnotherCounselorsPhoto(): void
    {
        $this->photo(3)->assertStatus(200);
        $this->revoke('guidance_supervisor', 'guidance.cases.read_any');
        $this->photo(3)->assertStatus(403);
    }

    public function testSavedSubjectPhotoIsReturnedByteForByteFromDefaultPrivateStorage(): void
    {
        $path = (new ProfilePhotoService())->path($this->tenantId, 11);
        $directory = dirname($path);
        $root = dirname($directory);
        if (!is_dir($root)) $this->assertTrue(mkdir($root, 0700));
        // Exclusive directory and file creation: never overwrite or remove an existing photo.
        $this->assertTrue(mkdir($directory, 0700));
        $this->photoDirectory = $directory;
        $handle = fopen($path, 'xb');
        $this->assertIsResource($handle);
        $this->photoFile = $path;
        $bytes = base64_decode('UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA', true);
        try {
            $this->assertSame(strlen($bytes), fwrite($handle, $bytes));
        } finally {
            fclose($handle);
        }
        $result = $this->photo(1);
        $this->assertPrivateImage($result, 'image/webp');
        $this->assertSame($bytes, $result->getBody());
        $this->photo(2)->assertStatus(403);
    }
}
