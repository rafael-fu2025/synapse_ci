<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/** Explicit private :memory: connection only; never uses configured database groups. */
final class GuidanceAuditModelTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        }
        $this->fixture = \Config\Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        CurrentTenant::set(1);
        foreach ([
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, kind TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, username TEXT',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, department TEXT, position TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, case_number TEXT, title TEXT, status TEXT, category TEXT, opened_at TEXT, deleted_at TEXT',
            'counselling_notes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, session_id INTEGER, version INTEGER, is_draft INTEGER, created_by_user_id INTEGER, notes_cipher BLOB',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
    }

    protected function tearDown(): void
    {
        $this->fixture?->close();
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testRegistryPaginationCountsAndPagesUseSameOwnershipAndTenantScope(): void
    {
        foreach ([[1, 1, null], [2, 2, null], [3, 1, '2026-01-01']] as [$id, $tenant, $deleted]) {
            $this->fixture->table('people')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'first_name' => 'Private', 'last_name' => 'Fixture',
                'mis_id' => 'FIXTURE-' . $id, 'kind' => 'student', 'deleted_at' => $deleted,
            ]);
        }
        foreach ([[7, 1], [8, 1], [9, 2]] as [$id, $tenant]) {
            $this->fixture->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'username' => 'fixture-' . $id]);
        }
        for ($id = 1; $id <= 66; ++$id) {
            $this->fixture->table('counselling_cases')->insert([
                'id' => $id, 'tenant_id' => $id === 65 ? 2 : 1,
                'person_id' => $id === 65 ? 2 : ($id === 66 ? 3 : 1),
                'counsellor_user_id' => $id === 64 ? 8 : ($id === 65 ? 9 : 7),
                'case_number' => 'FIXTURE-' . $id, 'title' => 'Synthetic case',
                'status' => $id === 63 ? 'closed' : 'active', 'category' => 'personal',
                'opened_at' => '2026-01-01 12:00:00', 'deleted_at' => $id === 62 ? '2026-01-02' : null,
            ]);
        }
        $model = new CounsellingCaseModel($this->fixture);
        $all = [];
        foreach ([1, 2, 3] as $page) {
            $model->where('counselling_cases.counsellor_user_id', 7);
            $rows = $model->getListWithPatients('active', 'personal', 25, $page);
            self::assertSame(61, $model->pager->getTotal());
            self::assertCount($page === 3 ? 11 : 25, $rows);
            $all = array_merge($all, array_column($rows, 'id'));
        }
        self::assertSame(range(61, 1), array_map('intval', $all));
        $model->where('counselling_cases.counsellor_user_id', 8);
        self::assertCount(1, $model->getListWithPatients('active', 'personal', 25, 1));
        self::assertSame(1, $model->pager->getTotal());
    }

    public function testHistoryExcludesDraftOtherSessionsAndTenantsButKeepsMissingAuthors(): void
    {
        $this->fixture->table('users')->insert(['id' => 7, 'tenant_id' => 1, 'username' => 'Fixture author']);
        foreach ([
            [1, 1, 10, 2, 0, 99], [2, 1, 10, 1, 0, 7], [3, 1, 10, 0, 1, 7],
            [4, 2, 10, 3, 0, 7], [5, 1, 11, 1, 0, 7],
        ] as [$id, $tenant, $session, $version, $draft, $author]) {
            $this->fixture->table('counselling_notes')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'session_id' => $session, 'version' => $version,
                'is_draft' => $draft, 'created_by_user_id' => $author, 'notes_cipher' => 'synthetic-ciphertext',
            ]);
        }
        $rows = (new CounsellingNoteModel($this->fixture))->getVersionHistory(10);
        self::assertSame([1, 2], array_map('intval', array_column($rows, 'version')));
        self::assertSame('Fixture author', $rows[0]['author_name']);
        self::assertNull($rows[1]['author_name']);
        self::assertSame('synthetic-ciphertext', $rows[1]['notes_cipher']);
    }
}
