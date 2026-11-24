<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\CreateSystemArchiveGovernance;
use App\Models\AuditEventModel;
use App\Services\Archive\ArchiveAccessPolicy;
use App\Services\Archive\ArchiveEntityRegistry;
use App\Services\Archive\ArchiveService;
use App\Services\Archive\LegalHoldService;
use App\Services\Archive\RetentionPolicyService;
use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;

require_once APPPATH . 'Database/Migrations/2026-11-25-000001_CreateSystemArchiveGovernance.php';

/**
 * Isolated SQLite :memory: unit suite for system-wide Archive Governance.
 */
final class ArchiveServiceTest extends CIUnitTestCase
{
    protected $db = null;

    /** @var array<string, mixed> */
    private array $actor = [
        'id'          => 1,
        'tenant_id'   => 1,
        'role'        => 'superadmin',
        'status'      => 'active',
        'permissions' => [
            'archive.read',
            'archive.manage',
            'archive.policies.manage',
            'archive.legal_hold.manage',
            'facilities.configure',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = Database::connect([
            'DSN'        => '',
            'hostname'   => '',
            'username'   => '',
            'password'   => '',
            'database'   => ':memory:',
            'DBDriver'   => 'SQLite3',
            'DBPrefix'   => '',
            'pConnect'   => false,
            'DBDebug'    => true,
            'charset'    => 'utf8',
            'DBCollat'   => 'utf8_general_ci',
            'swapPre'    => '',
            'encrypt'    => false,
            'compress'   => false,
            'strictOn'   => false,
            'failover'   => [],
            'port'       => 0,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $this->db->initialize();
        CurrentTenant::set(1);

        $this->installBaseSchema();
    }

    protected function tearDown(): void
    {
        try {
            $this->db?->close();
            $this->db = null;
            CurrentTenant::reset();
        } finally {
            parent::tearDown();
        }
    }

    public function testMigrationUpBackfillAndDown(): void
    {
        $now = '2026-11-01 08:00:00';
        $this->db->table('announcements')->insert([
            'id' => 91, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Legacy Clinic Notice',
            'audience' => 'all', 'status' => 'archived', 'published_at' => null, 'version' => 2,
            'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('survey_question_bank')->insert([
            'id' => 92, 'tenant_id' => 1, 'status' => 'archived', 'current_revision' => 3,
            'version' => 2, 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_medicines')->insert([
            'id' => 93, 'tenant_id' => 1, 'name' => 'Amoxicillin', 'strength' => '500mg',
            'is_active' => 0, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('bmg_units')->insert([
            'id' => 94, 'tenant_id' => 1, 'unit_code' => 'DRUM-OLD', 'name' => 'Legacy Drum',
            'status' => 'offline', 'deleted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $migration = new CreateSystemArchiveGovernance(Database::forge($this->db));
        $migration->up();

        $this->assertTrue($this->db->tableExists('archive_retention_policies'));
        $this->assertTrue($this->db->tableExists('archive_records'));
        $this->assertTrue($this->db->tableExists('archive_legal_holds'));

        foreach (['surveys', 'team_tasks', 'team_events', 'carousel_slides', 'guidance_form_instances'] as $table) {
            $this->assertTrue($this->db->fieldExists('deleted_at', $table), "Missing deleted_at on {$table}");
        }

        $policies = $this->db->table('archive_retention_policies')->where('tenant_id', 1)->get()->getResultArray();
        $this->assertCount(5, $policies);
        foreach ($policies as $policy) {
            $this->assertSame(0, (int) $policy['purge_enabled']);
        }

        $permCount = $this->db->table('permissions')
            ->whereIn('code', ['archive.read', 'archive.manage', 'archive.policies.manage', 'archive.legal_hold.manage'])
            ->countAllResults();
        $this->assertSame(4, $permCount);

        $backfilled = $this->db->table('archive_records')
            ->where('tenant_id', 1)
            ->where('state', 'archived')
            ->orderBy('entity_id', 'ASC')
            ->get()
            ->getResultArray();
        $this->assertCount(4, $backfilled);
        $this->assertSame(['announcement', 'question_bank', 'clinic_medicine', 'bmg_unit'], array_column($backfilled, 'entity_type'));

        // Running up() a second time must be idempotent without duplicating policies or backfills.
        $migration->up();
        $this->assertSame(5, $this->db->table('archive_retention_policies')->where('tenant_id', 1)->countAllResults());
        $this->assertSame(4, $this->db->table('archive_records')->where('tenant_id', 1)->where('state', 'archived')->countAllResults());

        $migration->down();
        $this->assertFalse($this->db->tableExists('archive_legal_holds'));
        $this->assertFalse($this->db->tableExists('archive_records'));
        $this->assertFalse($this->db->tableExists('archive_retention_policies'));
        foreach (['surveys', 'team_tasks', 'team_events', 'carousel_slides', 'guidance_form_instances'] as $table) {
            $this->assertFalse($this->db->fieldExists('deleted_at', $table), "deleted_at remained on {$table}");
        }
        $this->assertSame(0, $this->db->table('permissions')->whereIn('code', ['archive.read', 'archive.manage'])->countAllResults());
    }

    public function testAllFourteenEntitiesArchiveAndRestoreRoundTrips(): void
    {
        $this->runArchiveMigration();
        $this->seedAllArchivableFixtures();

        // Bind a device, rotation schedule, and pending rotation command to BMG drum #11 to verify safety cleanup.
        $this->db->table('bmg_devices')->insert([
            'id' => 50, 'tenant_id' => 1, 'unit_id' => 11, 'status' => 'active',
            'revoked_at' => null, 'updated_at' => '2026-11-24 00:00:00',
        ]);
        $this->db->table('bmg_rotation_schedules')->insert([
            'id' => 51, 'tenant_id' => 1, 'unit_id' => 11, 'enabled' => 1,
            'version' => 2, 'updated_by_user_id' => 1, 'updated_at' => '2026-11-24 00:00:00',
        ]);
        $this->db->table('bmg_rotation_commands')->insert([
            'id' => 52, 'tenant_id' => 1, 'unit_id' => 11, 'status' => 'pending',
            'cancelled_at' => null, 'updated_at' => '2026-11-24 00:00:00',
        ]);

        $services = $this->makeServices();
        $archive = $services['archive'];
        $audit = $services['audit'];

        $targets = [
            'announcement'           => 1,
            'survey'                 => 2,
            'question_bank'          => 3,
            'clinic_medicine'        => 4,
            'clinic_encounter'       => 5,
            'clinic_isolation'       => 6,
            'clinic_clearance'       => 7,
            'counselling_case'       => 8,
            'guidance_episode'       => 9,
            'guidance_form_instance' => 10,
            'bmg_unit'               => 11,
            'team_task'              => 12,
            'team_event'             => 13,
            'carousel_slide'         => 14,
        ];
        $this->assertCount(14, $targets);
        $this->assertSame(array_keys(ArchiveEntityRegistry::ENTITIES), array_keys($targets));

        foreach ($targets as $entityType => $entityId) {
            $archivedRecord = $archive->archive($entityType, $entityId, 'Retention schedule archival test');
            $this->assertSame('archived', $archivedRecord['state'], "{$entityType} archive state mismatch");

            $table = ArchiveEntityRegistry::ENTITIES[$entityType]['table'];
            $row = $this->db->table($table)->where('id', $entityId)->get()->getRowArray();
            $this->assertTrue(ArchiveEntityRegistry::isNativelyArchived($entityType, $row), "{$entityType} must be natively archived");
        }

        // Verify BMG retirement side effects on devices, schedules, and commands.
        $device = $this->db->table('bmg_devices')->where('id', 50)->get()->getRowArray();
        $schedule = $this->db->table('bmg_rotation_schedules')->where('id', 51)->get()->getRowArray();
        $command = $this->db->table('bmg_rotation_commands')->where('id', 52)->get()->getRowArray();
        $this->assertSame('revoked', $device['status']);
        $this->assertNull($device['unit_id']);
        $this->assertSame(0, (int) $schedule['enabled']);
        $this->assertSame('cancelled', $command['status']);

        foreach ($targets as $entityType => $entityId) {
            $restoredRecord = $archive->restore($entityType, $entityId, 'Audit inspection recovery');
            $this->assertSame('restored', $restoredRecord['state'], "{$entityType} restore state mismatch");
            $this->assertSame('Audit inspection recovery', $restoredRecord['restore_reason']);

            $table = ArchiveEntityRegistry::ENTITIES[$entityType]['table'];
            $row = $this->db->table($table)->where('id', $entityId)->get()->getRowArray();
            $this->assertFalse(ArchiveEntityRegistry::isNativelyArchived($entityType, $row), "{$entityType} must no longer be archived after restore");
        }

        // Verify safe restore defaults.
        $ann = $this->db->table('announcements')->where('id', 1)->get()->getRowArray();
        $this->assertSame('draft', $ann['status']);
        $this->assertNull($ann['published_at']);

        $slide = $this->db->table('carousel_slides')->where('id', 14)->get()->getRowArray();
        $this->assertSame(0, (int) $slide['is_active']);

        $drum = $this->db->table('bmg_units')->where('id', 11)->get()->getRowArray();
        $this->assertSame('offline', $drum['status']);

        $verification = $audit->verifyChain(1);
        $this->assertTrue($verification['valid'], 'SHA-256 audit hash chain must verify cleanly');
        $this->assertNull($verification['broken_id']);
        $this->assertGreaterThanOrEqual(28, $verification['verified_count']);
    }

    public function testBusinessStateGuardsAndImmutableLedgersReturn409(): void
    {
        $this->runArchiveMigration();
        $now = gmdate('Y-m-d H:i:s');
        $future = gmdate('Y-m-d H:i:s', time() + 86400);

        $this->db->table('surveys')->insert([
            'id' => 101, 'tenant_id' => 1, 'title' => 'Active Survey', 'status' => 'published',
            'published_version' => 1, 'version' => 1, 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_encounters')->insert([
            'id' => 102, 'tenant_id' => 1, 'person_id' => 1, 'status' => 'in_progress',
            'visited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_isolation_cases')->insert([
            'id' => 103, 'tenant_id' => 1, 'person_id' => 1, 'condition_code' => 'dengue',
            'clearance_status' => 'active_isolation', 'isolation_start_date' => '2026-11-20',
            'isolation_end_date' => '2026-11-27', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('counselling_cases')->insert([
            'id' => 104, 'tenant_id' => 1, 'person_id' => 1, 'counsellor_user_id' => 1,
            'case_number' => 'GC-OPEN-104', 'category' => 'personal', 'status' => 'open',
            'title' => 'Active Case', 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_episodes')->insert([
            'id' => 105, 'tenant_id' => 1, 'person_id' => 1, 'primary_counselor_user_id' => 1,
            'status' => 'open', 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_form_instances')->insert([
            'id' => 106, 'tenant_id' => 1, 'instance_number' => 'GF-106', 'person_id' => 1,
            'status' => 'draft', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('bmg_units')->insert([
            'id' => 107, 'tenant_id' => 1, 'unit_code' => 'DRUM-BUSY', 'name' => 'Busy Drum',
            'status' => 'online', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('bmg_batches')->insert([
            'id' => 201, 'tenant_id' => 1, 'unit_id' => 107, 'status' => 'processing',
        ]);
        $this->db->table('team_tasks')->insert([
            'id' => 108, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Open Work',
            'status' => 'in_progress', 'priority' => 'normal', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('team_events')->insert([
            'id' => 109, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Upcoming Briefing',
            'status' => 'scheduled', 'starts_at' => $future, 'ends_at' => $future, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $archive = $this->makeServices()['archive'];

        foreach ([
            'survey'                 => 101,
            'clinic_encounter'       => 102,
            'clinic_isolation'       => 103,
            'counselling_case'       => 104,
            'guidance_episode'       => 105,
            'guidance_form_instance' => 106,
            'bmg_unit'               => 107,
            'team_task'              => 108,
            'team_event'             => 109,
        ] as $type => $id) {
            try {
                $archive->archive($type, $id, 'Premature archive attempt');
                $this->fail("Expected 409 guard when archiving active {$type}");
            } catch (RuntimeException $e) {
                $this->assertSame(409, $e->getCode(), "Unexpected code for {$type}: {$e->getMessage()}");
            }
        }

        foreach (array_keys(ArchiveEntityRegistry::IMMUTABLE_ENTITIES) as $immutableEntity) {
            try {
                $archive->archive($immutableEntity, 1, 'Attempt to archive append-only ledger');
                $this->fail("Expected 409 when targeting immutable ledger {$immutableEntity}");
            } catch (RuntimeException $e) {
                $this->assertSame(409, $e->getCode(), "Unexpected code for {$immutableEntity}");
            }
        }
    }

    public function testLegalHoldBlocksArchiveAcrossBothHoldTablesAndAllowsAfterLift(): void
    {
        $this->runArchiveMigration();
        $this->seedAllArchivableFixtures();
        $services = $this->makeServices();
        $archive = $services['archive'];
        $holds = $services['holds'];

        // 1. Archive Legal Hold lifecycle on closed team_task #12
        $hold = $holds->placeHold('team_task', 12, 'Pending internal audit review', 'HOLD-TASK-01');
        $this->assertSame(1, (int) $hold['is_active']);

        try {
            $archive->archive('team_task', 12, 'Should be blocked by active legal hold');
            $this->fail('Expected legal hold to block archiving');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $lifted = $holds->liftHold((int) $hold['id'], 'Audit investigation cleared');
        $this->assertSame(0, (int) $lifted['is_active']);

        $archivedTask = $archive->archive('team_task', 12, 'Allowed after hold lifted');
        $this->assertSame('archived', $archivedTask['state']);

        // 2. Cross-check legacy guidance_legal_holds table on closed counselling_case #8
        $this->db->table('guidance_legal_holds')->insert([
            'id' => 301, 'tenant_id' => 1, 'entity_type' => 'case', 'entity_id' => 8, 'is_active' => 1,
        ]);

        try {
            $archive->archive('counselling_case', 8, 'Blocked by guidance_legal_holds');
            $this->fail('Expected active guidance_legal_holds entry to block archiving');
        } catch (RuntimeException $e) {
            $this->assertSame(409, $e->getCode());
        }

        $this->db->table('guidance_legal_holds')->where('id', 301)->update(['is_active' => 0]);
        $archivedCase = $archive->archive('counselling_case', 8, 'Allowed after guidance hold released');
        $this->assertSame('archived', $archivedCase['state']);
    }

    public function testRetentionPolicyResolutionAndNoPurgeInvariant(): void
    {
        $this->runArchiveMigration();
        $retention = $this->makeServices()['retention'];

        // Seeded default RET-OPS-001 (all|*) covers modules without a module-specific policy (e.g., admin).
        $this->assertSame('RET-OPS-001', $retention->resolvePolicy(1, 'admin', 'carousel_slide')['policy_code']);

        // Module-level wildcard policy beats global fallback (score 2 > 0)
        $moduleWildcard = $retention->savePolicy(null, [
            'policy_code'     => 'RET-ADMIN-ALL',
            'module'          => 'admin',
            'entity_type'     => '*',
            'title'           => 'Admin Wildcard Policy',
            'duration_months' => 36,
            'legal_basis'     => 'Internal Governance Standard',
            'purge_enabled'   => 1, // Must always coerce to 0
            'is_active'       => 1,
        ]);
        $this->assertSame(0, (int) $moduleWildcard['purge_enabled']);
        $this->assertSame('RET-ADMIN-ALL', $retention->resolvePolicy(1, 'admin', 'carousel_slide')['policy_code']);

        // Exact module+entity_type beats module+* (score 3 > 2)
        $exactPolicy = $retention->savePolicy(null, [
            'policy_code'     => 'RET-ADMIN-SLIDE',
            'module'          => 'admin',
            'entity_type'     => 'carousel_slide',
            'title'           => 'Carousel Slide Retention',
            'duration_months' => 24,
            'legal_basis'     => 'Brand Asset Audit Rule',
            'purge_enabled'   => 1,
            'is_active'       => 1,
        ]);
        $this->assertSame(0, (int) $exactPolicy['purge_enabled']);
        $this->assertSame('RET-ADMIN-SLIDE', $retention->resolvePolicy(1, 'admin', 'carousel_slide')['policy_code']);

        $toggled = $retention->togglePolicy((int) $exactPolicy['id']);
        $this->assertSame(0, (int) $toggled['is_active']);
        $this->assertSame('RET-ADMIN-ALL', $retention->resolvePolicy(1, 'admin', 'carousel_slide')['policy_code']);
    }

    public function testConfidentialEntitiesMaskClinicalContentInArchiveAndAudit(): void
    {
        $this->runArchiveMigration();
        $now = gmdate('Y-m-d H:i:s');

        $canaries = [
            'CONFIDENTIAL_COMPLAINT_CANARY',
            'CONFIDENTIAL_ENCOUNTER_NOTES_CANARY',
            'CONFIDENTIAL_ISOLATION_NOTES_CANARY',
            'CONFIDENTIAL_CASE_TITLE_CANARY',
            'CONFIDENTIAL_CASE_NOTES_CANARY',
            'CONFIDENTIAL_EPISODE_NOTES_CANARY',
            'CONFIDENTIAL_FORM_NOTES_CANARY',
        ];

        $this->db->table('clinic_encounters')->insert([
            'id' => 205, 'tenant_id' => 1, 'person_id' => 1, 'status' => 'completed',
            'visited_at' => $now, 'chief_complaint' => $canaries[0], 'clinical_notes' => $canaries[1],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_isolation_cases')->insert([
            'id' => 206, 'tenant_id' => 1, 'person_id' => 1, 'condition_code' => 'measles',
            'clearance_status' => 'cleared', 'isolation_start_date' => '2026-10-01',
            'isolation_end_date' => '2026-10-08', 'notes' => $canaries[2],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('counselling_cases')->insert([
            'id' => 208, 'tenant_id' => 1, 'person_id' => 1, 'counsellor_user_id' => 1,
            'case_number' => 'GC-CANARY-208', 'category' => 'personal', 'status' => 'closed',
            'title' => $canaries[3], 'confidential_notes' => $canaries[4],
            'opened_at' => $now, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_episodes')->insert([
            'id' => 209, 'tenant_id' => 1, 'person_id' => 1, 'primary_counselor_user_id' => 1,
            'status' => 'closed', 'summary_notes' => $canaries[5],
            'opened_at' => $now, 'closed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_form_instances')->insert([
            'id' => 210, 'tenant_id' => 1, 'instance_number' => 'GF-CANARY-210', 'person_id' => 1,
            'status' => 'finalized', 'payload_notes' => $canaries[6], 'finalized_at' => $now,
            'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $services = $this->makeServices();
        $holds = $services['holds'];
        $archive = $services['archive'];

        $h = $holds->placeHold('counselling_case', 208, 'Legal docket HOLD-208', 'HOLD-208');
        $holds->liftHold((int) $h['id'], 'Docket cleared');

        foreach ([
            'clinic_encounter'       => 205,
            'clinic_isolation'       => 206,
            'counselling_case'       => 208,
            'guidance_episode'       => 209,
            'guidance_form_instance' => 210,
        ] as $type => $id) {
            $archive->archive($type, $id, 'Routine archive');
        }

        $serializedArchive = json_encode($this->db->table('archive_records')->get()->getResultArray()) ?: '';
        $serializedHolds   = json_encode($this->db->table('archive_legal_holds')->get()->getResultArray()) ?: '';
        $serializedAudit   = json_encode($this->db->table('audit_events')->get()->getResultArray()) ?: '';
        $combined          = $serializedArchive . "\n" . $serializedHolds . "\n" . $serializedAudit;

        foreach ($canaries as $canary) {
            $this->assertStringNotContainsString($canary, $combined, "Canary {$canary} leaked into archive/audit tables!");
        }
    }

    private function runArchiveMigration(): void
    {
        $migration = new CreateSystemArchiveGovernance(Database::forge($this->db));
        $migration->up();
    }

    /** @return array{policy:ArchiveAccessPolicy,audit:AuditService,retention:RetentionPolicyService,holds:LegalHoldService,archive:ArchiveService} */
    private function makeServices(): array
    {
        $policy = new ArchiveAccessPolicy(fn (): array => $this->actor);
        $audit = new AuditService(new AuditEventModel($this->db), db: $this->db);
        $retention = new RetentionPolicyService($this->db, $audit, $policy);
        $holds = new LegalHoldService($this->db, $audit, $policy);
        $archive = new ArchiveService($this->db, $audit, $policy, $retention, $holds);

        return compact('policy', 'audit', 'retention', 'holds', 'archive');
    }

    private function installBaseSchema(): void
    {
        $queries = [
            'CREATE TABLE tenants (id INTEGER PRIMARY KEY, is_active INTEGER DEFAULT 1, deleted_at TEXT NULL)',
            'CREATE TABLE users (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, username TEXT NOT NULL, role TEXT NOT NULL, status TEXT NOT NULL)',
            'CREATE TABLE permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL, name TEXT NULL, module TEXT NULL)',
            'CREATE TABLE role_permissions (role TEXT NOT NULL, permission_id INTEGER NOT NULL)',
            'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, event_type TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT NOT NULL, actor_user_id INTEGER NULL, actor_role TEXT NULL, actor_ip TEXT NULL, action TEXT NOT NULL, payload_json TEXT NULL, prev_hash TEXT NOT NULL, curr_hash TEXT NOT NULL, hash_version INTEGER NOT NULL DEFAULT 2, occurred_at TEXT NOT NULL)',
            'CREATE TABLE announcements (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, title TEXT NOT NULL, audience TEXT NULL, status TEXT NOT NULL, published_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE surveys (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, title TEXT NOT NULL, audience TEXT NULL, status TEXT NOT NULL, published_version INTEGER NULL, version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE survey_question_bank (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, status TEXT NOT NULL, current_revision INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_by INTEGER NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_medicines (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT NOT NULL, strength TEXT NULL, category TEXT NULL, dosage_form TEXT NULL, is_active INTEGER NOT NULL DEFAULT 1, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_encounters (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, status TEXT NOT NULL, visited_at TEXT NULL, chief_complaint TEXT NULL, clinical_notes TEXT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_isolation_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, condition_code TEXT NOT NULL, clearance_status TEXT NOT NULL, isolation_start_date TEXT NOT NULL, isolation_end_date TEXT NOT NULL, notes TEXT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE clinic_enrollment_clearances (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, school_year TEXT NULL, semester TEXT NULL, clearance_status TEXT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE counselling_cases (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, counsellor_user_id INTEGER NOT NULL, case_number TEXT NOT NULL, category TEXT NULL, status TEXT NOT NULL, title TEXT NULL, confidential_notes TEXT NULL, deleted_at TEXT NULL, opened_at TEXT NULL, closed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE guidance_episodes (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, person_id INTEGER NOT NULL, primary_counselor_user_id INTEGER NULL, status TEXT NOT NULL, summary_notes TEXT NULL, deleted_at TEXT NULL, opened_at TEXT NULL, closed_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE guidance_form_instances (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, instance_number TEXT NULL, person_id INTEGER NOT NULL, status TEXT NOT NULL, payload_notes TEXT NULL, finalized_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE guidance_legal_holds (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)',
            'CREATE TABLE bmg_units (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_code TEXT NOT NULL, name TEXT NOT NULL, status TEXT NOT NULL, deleted_at TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE bmg_batches (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, status TEXT NOT NULL)',
            'CREATE TABLE bmg_devices (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NULL, status TEXT NOT NULL, revoked_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE bmg_rotation_schedules (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, enabled INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, updated_by_user_id INTEGER NULL, updated_at TEXT NULL)',
            'CREATE TABLE bmg_rotation_commands (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit_id INTEGER NOT NULL, status TEXT NOT NULL, cancelled_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE team_tasks (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, title TEXT NOT NULL, status TEXT NOT NULL, priority TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE team_events (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, title TEXT NOT NULL, status TEXT NOT NULL, starts_at TEXT NULL, ends_at TEXT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NULL, updated_at TEXT NULL)',
            'CREATE TABLE carousel_slides (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, title TEXT NOT NULL, is_default INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 0, fit_mode TEXT NULL, created_at TEXT NULL, updated_at TEXT NULL)',
        ];

        foreach ($queries as $sql) {
            $this->db->query($sql);
        }

        $this->db->table('tenants')->insert(['id' => 1, 'is_active' => 1, 'deleted_at' => null]);
        $this->db->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'username' => 'superadmin', 'role' => 'superadmin', 'status' => 'active']);
    }

    private function seedAllArchivableFixtures(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $past = gmdate('Y-m-d H:i:s', time() - 86400);

        $this->db->table('announcements')->insert([
            'id' => 1, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Clinic Advisory',
            'audience' => 'all', 'status' => 'published', 'published_at' => $now, 'version' => 1,
            'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('surveys')->insert([
            'id' => 2, 'tenant_id' => 1, 'title' => 'Wellness Survey', 'audience' => 'students',
            'status' => 'closed', 'published_version' => 1, 'version' => 1, 'created_by' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('survey_question_bank')->insert([
            'id' => 3, 'tenant_id' => 1, 'status' => 'active', 'current_revision' => 1,
            'version' => 1, 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_medicines')->insert([
            'id' => 4, 'tenant_id' => 1, 'name' => 'Paracetamol', 'strength' => '500mg',
            'is_active' => 1, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_encounters')->insert([
            'id' => 5, 'tenant_id' => 1, 'person_id' => 1, 'status' => 'completed',
            'visited_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_isolation_cases')->insert([
            'id' => 6, 'tenant_id' => 1, 'person_id' => 1, 'condition_code' => 'influenza',
            'clearance_status' => 'cleared', 'isolation_start_date' => '2026-10-10',
            'isolation_end_date' => '2026-10-15', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('clinic_enrollment_clearances')->insert([
            'id' => 7, 'tenant_id' => 1, 'person_id' => 1, 'school_year' => '2026-2027',
            'semester' => '1st', 'clearance_status' => 'fit', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('counselling_cases')->insert([
            'id' => 8, 'tenant_id' => 1, 'person_id' => 1, 'counsellor_user_id' => 1,
            'case_number' => 'GC-2026-0008', 'category' => 'academic', 'status' => 'closed',
            'title' => 'Closed Consultation', 'opened_at' => $past, 'closed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_episodes')->insert([
            'id' => 9, 'tenant_id' => 1, 'person_id' => 1, 'primary_counselor_user_id' => 1,
            'status' => 'closed', 'opened_at' => $past, 'closed_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('guidance_form_instances')->insert([
            'id' => 10, 'tenant_id' => 1, 'instance_number' => 'GF-2026-0010', 'person_id' => 1,
            'status' => 'finalized', 'finalized_at' => $now, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('bmg_units')->insert([
            'id' => 11, 'tenant_id' => 1, 'unit_code' => 'DRUM-11', 'name' => 'North Bay Drum',
            'status' => 'online', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('team_tasks')->insert([
            'id' => 12, 'tenant_id' => 1, 'unit' => 'clinic', 'title' => 'Stock Reconciliation',
            'status' => 'done', 'priority' => 'normal', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('team_events')->insert([
            'id' => 13, 'tenant_id' => 1, 'unit' => 'guidance', 'title' => 'Completed Workshop',
            'status' => 'scheduled', 'starts_at' => $past, 'ends_at' => $past, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->table('carousel_slides')->insert([
            'id' => 14, 'tenant_id' => 1, 'title' => 'Campus Orientation', 'is_default' => 0,
            'is_active' => 1, 'fit_mode' => 'cover', 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
