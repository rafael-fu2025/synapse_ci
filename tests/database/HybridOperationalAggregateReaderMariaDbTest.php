<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\FreshDatabaseTestCase;

/** Production-driver aggregation smoke test on a verified disposable MariaDB schema. */
final class HybridOperationalAggregateReaderMariaDbTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';

    private int $tenantId;
    private int $personId;
    private int $userId;
    private int $otherPersonId;
    private int $otherUserId;
    private int $caseId;
    private int $unitId;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('MySQLi', $this->db->DBDriver);
        self::assertSame(HUMUS_FRESH_DATABASE_VERIFIED,
            $this->db->query('SELECT DATABASE() AS name')->getRowArray()['name']);

        $now = '2026-10-01 00:00:00';
        self::assertTrue($this->db->table('tenants')->insert([
            'slug' => 'hybrid_reader_' . bin2hex(random_bytes(6)),
            'name' => 'Synthetic Hybrid Reader Tenant',
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->tenantId = (int) $this->db->insertID();
        self::assertTrue($this->db->table('people')->insert([
            'tenant_id' => $this->tenantId,
            'kind' => 'student',
            'mis_id' => 'SYNTHETIC-AGGREGATE-STUDENT',
            'first_name' => 'Synthetic',
            'last_name' => 'Fixture',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->personId = (int) $this->db->insertID();
        self::assertTrue($this->db->table('users')->insert([
            'tenant_id' => $this->tenantId,
            'person_id' => $this->personId,
            'username' => 'synthetic-aggregate-user',
            'role' => 'clinic_physician',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->userId = (int) $this->db->insertID();
        self::assertTrue($this->db->table('people')->insert([
            'tenant_id' => 1,
            'kind' => 'student',
            'mis_id' => 'SYNTHETIC-OTHER-TENANT-STUDENT',
            'first_name' => 'Other',
            'last_name' => 'Fixture',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->otherPersonId = (int) $this->db->insertID();
        self::assertTrue($this->db->table('users')->insert([
            'tenant_id' => 1,
            'person_id' => $this->otherPersonId,
            'username' => 'synthetic-other-tenant-user',
            'role' => 'clinic_physician',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->otherUserId = (int) $this->db->insertID();
        self::assertTrue($this->db->table('counselling_cases')->insert([
            'tenant_id' => $this->tenantId,
            'person_id' => $this->personId,
            'counsellor_user_id' => $this->userId,
            'case_number' => 'SYNTHETIC-AGGREGATE-CASE',
            'title' => 'Synthetic aggregate fixture',
            'category' => 'academic',
            'status' => 'open',
            'opened_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->caseId = (int) $this->db->insertID();
        self::assertTrue($this->db->table('bmg_units')->insert([
            'tenant_id' => $this->tenantId,
            'unit_code' => 'synthetic-aggregate-unit',
            'name' => 'Synthetic aggregate unit',
            'status' => 'active',
            'drum_capacity_kg' => 10,
            'default_category' => 'synthetic',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->unitId = (int) $this->db->insertID();
    }

    public function testMariaDbGroupingPreservesBoundariesTenantScopeAndIndependentCompletionDates(): void
    {
        foreach ([
            [$this->tenantId, $this->personId, $this->userId, 'completed', '2026-09-30 16:00:00'],
            [$this->tenantId, $this->personId, $this->userId, 'completed', '2026-10-01 16:00:00'],
            [$this->tenantId, $this->personId, $this->userId, 'completed', '2026-10-02 16:00:00'],
            [1, $this->otherPersonId, $this->otherUserId, 'completed', '2026-09-30 16:00:00'],
            [$this->tenantId, $this->personId, $this->userId, 'in_progress', '2026-09-30 16:00:00'],
        ] as [$tenant, $personId, $userId, $status, $started]) {
            self::assertTrue($this->db->table('clinic_encounters')->insert([
                'tenant_id' => $tenant,
                'person_id' => $personId,
                'attending_user_id' => $userId,
                'status' => $status,
                'started_at' => $started,
                'created_at' => $started,
                'updated_at' => $started,
            ]));
        }
        foreach (['2026-09-30 16:00:00', '2026-10-01 16:00:00', '2026-10-02 16:00:00'] as $index => $started) {
            self::assertTrue($this->db->table('counselling_sessions')->insert([
                'tenant_id' => $this->tenantId,
                'case_id' => $this->caseId,
                'session_number' => $index + 1,
                'session_type' => 'regular',
                'counsellor_user_id' => $this->userId,
                'status' => 'completed',
                'started_at' => $started,
                'created_at' => $started,
                'updated_at' => $started,
            ]));
        }
        foreach ([
            ['BATCH-SYNTHETIC-1', 'released', '2026-09-30 16:00:00', '2026-10-01 16:00:00'],
            ['BATCH-SYNTHETIC-2', 'voided', '2026-10-01 16:00:00', '2026-10-02 15:59:59'],
            ['BATCH-SYNTHETIC-3', 'processing', '2026-10-02 16:00:00', null],
        ] as [$code, $status, $started, $completed]) {
            self::assertTrue($this->db->table('bmg_batches')->insert([
                'tenant_id' => $this->tenantId,
                'unit_id' => $this->unitId,
                'batch_code' => $code,
                'status' => $status,
                'total_input_weight_kg' => 1,
                'quality_grade' => 'unclassified',
                'started_at' => $started,
                'completed_at' => $completed,
                'created_at' => $started,
                'updated_at' => $started,
            ]));
        }

        $rows = (new OperationalExportService())->aggregate(
            $this->db,
            $this->tenantId,
            ExportRange::fromInput(['from' => '2026-10-01', 'to' => '2026-10-02']),
            'UTC',
            ['clinic', 'guidance', 'bmg']
        );

        self::assertSame([
            ['date_manila', 'unit', 'metric', 'count'],
            ['2026-10-01', 'clinic', 'completed_clinic_encounters_started', 1],
            ['2026-10-01', 'guidance', 'completed_guidance_sessions_started', 1],
            ['2026-10-01', 'bmg', 'bmg_batches_started', 1],
            ['2026-10-01', 'bmg', 'bmg_batches_released', 0],
            ['2026-10-01', 'bmg', 'bmg_batches_voided', 0],
            ['2026-10-02', 'clinic', 'completed_clinic_encounters_started', 1],
            ['2026-10-02', 'guidance', 'completed_guidance_sessions_started', 1],
            ['2026-10-02', 'bmg', 'bmg_batches_started', 1],
            ['2026-10-02', 'bmg', 'bmg_batches_released', 1],
            ['2026-10-02', 'bmg', 'bmg_batches_voided', 1],
        ], $rows);
    }
}
