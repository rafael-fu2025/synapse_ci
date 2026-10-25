<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrentTenant;
use App\Services\WorkflowNotificationScanner;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\WorkflowAlerts;
use DateTimeImmutable;
use RuntimeException;

/** Private in-memory schema only; never connects to the application's configured database. */
final class WorkflowNotificationScannerTest extends CIUnitTestCase
{
    protected $db;
    private WorkflowAlerts $alerts;
    private const NOW = '2026-10-23T16:00:00Z'; // Manila is already October 24.

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $definitions = [
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER DEFAULT 1, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, deleted_at TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER DEFAULT 1, status TEXT DEFAULT "open", deleted_at TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER DEFAULT 1, unit TEXT DEFAULT "clinic", status TEXT DEFAULT "confirmed", starts_at TEXT, version INTEGER DEFAULT 1, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER DEFAULT 1, due_date TEXT, status TEXT DEFAULT "pending"',
            'clinic_medicines' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, minimum_stock_level INTEGER DEFAULT 5, is_active INTEGER DEFAULT 1',
            'clinic_medicine_batches' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, medicine_id INTEGER, remaining_quantity INTEGER DEFAULT 1, status TEXT DEFAULT "active", expiration_date TEXT',
            'bmg_units' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT DEFAULT "active", deleted_at TEXT',
            'bmg_devices' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit_id INTEGER, status TEXT DEFAULT "active", revoked_at TEXT, rotation_bound_at TEXT DEFAULT "2026-10-23 22:00:00", created_at TEXT DEFAULT "2026-10-23 22:00:00", last_seen_at TEXT, rtc_valid INTEGER, rtc_reported_at TEXT',
            'bmg_rotation_commands' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT DEFAULT "pending", expires_at TEXT',
            'workflow_alert_states' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_kind TEXT, subject_id INTEGER, active INTEGER, episode_number INTEGER, started_at TEXT, resolved_at TEXT, UNIQUE(tenant_id,event_kind,subject_id)',
            'workflow_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_kind TEXT, unit TEXT, subject_id INTEGER, occurrence_key TEXT, event_key TEXT UNIQUE',
        ];
        foreach ($definitions as $table => $columns) $this->db->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        $this->db->table('tenants')->insertBatch([['id' => 1], ['id' => 2]]);
        CurrentTenant::set(1);
        $this->put('people', 1);
        $this->put('counselling_cases', 1);
        $this->alerts = new WorkflowAlerts();
        $this->alerts->batchLimit = 2;
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        $this->db->close();
        parent::tearDown();
    }

    private function put(string $table, int $id, array $values = []): void
    {
        $this->db->table($table)->insert(['id' => $id, 'tenant_id' => 1] + $values);
    }

    private function scanner(string $now = self::NOW, bool $fail = false): WorkflowNotificationScanner
    {
        return new WorkflowNotificationScanner($this->db, $this->alerts, new DateTimeImmutable($now),
            static function (BaseConnection $db, string $kind, string $unit, int $id, string $occurrence) use ($fail): void {
                $tenant = CurrentTenant::id();
                $key = hash('sha256', implode('|', [$tenant, $kind, $unit, $id, $occurrence]));
                if ($db->table('workflow_events')->where('event_key', $key)->countAllResults() === 0) {
                    $db->table('workflow_events')->insert(['tenant_id' => $tenant, 'event_kind' => $kind, 'unit' => $unit,
                        'subject_id' => $id, 'occurrence_key' => $occurrence, 'event_key' => $key]);
                }
                if ($fail) throw new RuntimeException('PRIVATE medical details must never escape');
            });
    }

    private function events(?string $kind = null): array
    {
        $builder = $this->db->table('workflow_events')->orderBy('id');
        if ($kind !== null) $builder->where('event_kind', $kind);
        return $builder->get()->getResultArray();
    }

    public function testAllScheduledKindsUseManilaDatesAndStableOccurrences(): void
    {
        $this->put('appointments', 1, ['starts_at' => '2026-10-25 00:00:00']); // inclusive 24h
        $this->put('guidance_followups', 1, ['due_date' => '2026-10-24']);
        $this->put('guidance_followups', 2, ['due_date' => '2026-10-23']);
        $this->put('clinic_medicines', 1);
        $this->put('clinic_medicine_batches', 1, ['medicine_id' => 1, 'expiration_date' => '2026-10-24']);
        $this->put('clinic_medicine_batches', 2, ['medicine_id' => 1, 'expiration_date' => '2026-10-23']);
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1]);
        $this->put('bmg_units', 2);
        $this->put('bmg_devices', 2, ['unit_id' => 2, 'last_seen_at' => '2026-10-23 23:59:59',
            'rtc_valid' => 0, 'rtc_reported_at' => '2026-10-23 23:59:59']);
        $this->put('bmg_rotation_commands', 1, ['expires_at' => '2026-10-23 23:59:59']);
        self::assertSame(0, $this->scanner()->scan()['failed']);
        self::assertCount(9, $this->events());
        $this->scanner()->scan();
        self::assertCount(9, $this->events());
        self::assertSame('v1_2026-10-25T00:00:00', $this->events('appointment_reminder')[0]['occurrence_key']);
        self::assertSame('2026-10-24', $this->events('guidance_followup_due')[0]['occurrence_key']);
        self::assertSame('episode_1', $this->events('inventory_low_stock')[0]['occurrence_key']);
        $this->scanner('2026-10-24T16:00:00Z')->scan();
        self::assertCount(1, $this->events('guidance_followup_due'));
        self::assertCount(2, $this->events('guidance_followup_overdue')); // each due date once, not each scan day
        $this->db->table('appointments')->where('id', 1)->update(['version' => 2]);
        $this->scanner()->scan();
        self::assertCount(2, $this->events('appointment_reminder'));
    }

    public function testLowStockRearmsDurablyAndUsableExcludesQuarantineAndExpired(): void
    {
        $this->put('clinic_medicines', 1);
        $this->put('clinic_medicine_batches', 1, ['medicine_id' => 1, 'expiration_date' => '2026-12-01', 'remaining_quantity' => 5]);
        $this->put('clinic_medicine_batches', 2, ['medicine_id' => 1, 'expiration_date' => '2026-10-23', 'remaining_quantity' => 100]);
        $this->put('clinic_medicine_batches', 3, ['medicine_id' => 1, 'expiration_date' => '2026-12-01', 'remaining_quantity' => 100, 'status' => 'quarantined']);
        $this->scanner()->scan();
        $this->scanner()->scan();
        self::assertCount(1, $this->events('inventory_low_stock'));
        $this->db->table('clinic_medicine_batches')->where('id', 1)->update(['remaining_quantity' => 6]);
        $this->scanner()->scan();
        $this->db->table('clinic_medicine_batches')->where('id', 1)->update(['remaining_quantity' => 5]);
        $this->scanner()->scan();
        self::assertSame(['episode_1', 'episode_2'], array_column($this->events('inventory_low_stock'), 'occurrence_key'));
        self::assertCount(1, $this->events('inventory_near_expiry'));
    }

    public function testDeviceRecoveriesRequireAnOpeningEpisodeAndNewExplicitReports(): void
    {
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1, 'last_seen_at' => '2026-10-23 23:59:59', 'rtc_valid' => 1, 'rtc_reported_at' => '2026-10-23 23:59:59']);
        $this->scanner()->scan();
        $this->put('bmg_units', 2);
        $this->put('bmg_devices', 2, ['unit_id' => 2, 'last_seen_at' => '2026-10-23 23:59:59', 'rtc_valid' => 1,
            'rtc_reported_at' => '2026-10-23 23:59:59']);
        self::assertSame([], $this->events());
        $this->db->table('bmg_devices')->where('id', 1)->update(['last_seen_at' => '2026-10-23 23:00:00']);
        $this->db->table('bmg_devices')->where('id', 2)->update(['rtc_valid' => 0]);
        $this->scanner()->scan();
        self::assertCount(2, $this->events());
        $this->db->table('bmg_devices')->where('id', 2)->update(['rtc_valid' => null]);
        $this->scanner('2026-10-23T16:01:00Z')->scan();
        self::assertCount(2, $this->events());
        $this->db->table('bmg_devices')->whereIn('id', [1, 2])->update(['last_seen_at' => '2026-10-24 00:01:00', 'rtc_valid' => 1, 'rtc_reported_at' => '2026-10-24 00:01:00']);
        $this->scanner('2026-10-23T16:01:01Z')->scan();
        $this->scanner('2026-10-23T16:01:02Z')->scan();
        self::assertCount(1, $this->events('bmg_device_recovered'));
        self::assertCount(1, $this->events('bmg_rtc_recovered'));
        $this->scanner('2026-10-23T16:17:00Z')->scan();
        self::assertSame(['episode_1', 'episode_2', 'episode_1'], array_column($this->events('bmg_device_offline'), 'occurrence_key'));
    }

    public function testStaleRtcReportCannotRecoverOrRearmAnEpisode(): void
    {
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1, 'last_seen_at' => '2026-10-23 23:59:59',
            'rtc_valid' => 0, 'rtc_reported_at' => '2026-10-23 23:59:59']);
        $this->scanner()->scan();
        $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_valid' => 1]);
        $this->scanner('2026-10-23T16:01:00Z')->scan();
        self::assertCount(0, $this->events('bmg_rtc_recovered'));
        $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_valid' => 0]);
        $this->scanner('2026-10-23T16:02:00Z')->scan();
        self::assertCount(1, $this->events('bmg_rtc_invalid'));
    }

    public function testRebindingRetiresOldEpisodesWithoutManufacturingRecovery(): void
    {
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1]);
        $this->put('bmg_units', 2);
        $this->put('bmg_devices', 2, ['unit_id' => 2, 'last_seen_at' => '2026-10-23 23:59:59',
            'rtc_valid' => 0, 'rtc_reported_at' => '2026-10-23 23:59:59']);
        $this->scanner()->scan();
        self::assertCount(1, $this->events('bmg_device_offline'));
        self::assertCount(1, $this->events('bmg_rtc_invalid'));
        $this->db->table('bmg_devices')->whereIn('id', [1, 2])->update(['rotation_bound_at'  => '2026-10-24 00:01:00',
            'last_seen_at' => '2026-10-24 00:01:00', 'rtc_valid' => 1, 'rtc_reported_at' => '2026-10-24 00:01:00']);
        $this->scanner('2026-10-23T16:01:01Z')->scan();
        self::assertCount(0, $this->events('bmg_device_recovered'));
        self::assertCount(0, $this->events('bmg_rtc_recovered'));
        $this->scanner('2026-10-23T16:17:00Z')->scan();
        self::assertSame(['episode_1', 'episode_2', 'episode_1'], array_column($this->events('bmg_device_offline'), 'occurrence_key'));
    }

    public function testRtcReportsMustBeFreshCurrentBindingAndBackedByContact(): void
    {
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1, 'last_seen_at' => '2026-10-23 23:59:59', 'rtc_valid' => 0]);
        foreach ([null, '2026-10-23 21:59:59', '2026-10-23 23:44:59', '2026-10-24 00:00:01'] as $report) {
            $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_reported_at' => $report]);
            self::assertSame(0, $this->scanner()->scan()['failed']);
            self::assertCount(0, $this->events('bmg_rtc_invalid'));
        }
        $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_reported_at' => '2026-10-23 23:45:00']);
        $this->scanner()->scan();
        self::assertCount(1, $this->events('bmg_rtc_invalid')); // inclusive freshness boundary
        $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_valid' => 1, 'rtc_reported_at' => '2026-10-24 00:01:00', 'last_seen_at' => '2026-10-24 00:01:00']);
        $this->scanner('2026-10-23T16:01:01Z')->scan();
        self::assertCount(1, $this->events('bmg_rtc_recovered'));
        // A regressed false report is still within the age window but predates resolution.
        $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_valid' => 0, 'rtc_reported_at' => '2026-10-24 00:00:30']);
        $this->scanner('2026-10-23T16:02:00Z')->scan();
        self::assertCount(1, $this->events('bmg_rtc_invalid'));
        $this->db->table('bmg_devices')->where('id', 1)->update(['rtc_reported_at' => '2026-10-24 00:02:00', 'last_seen_at' => '2026-10-24 00:02:00']);
        $this->scanner('2026-10-23T16:02:01Z')->scan();
        self::assertSame(['episode_1', 'episode_2'], array_column($this->events('bmg_rtc_invalid'), 'occurrence_key'));
    }

    public function testIneligibleDeviceCannotRecoverOrRearmAndOldContactGetsBindingGrace(): void
    {
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1]);
        $this->scanner()->scan();
        foreach ([['status' => 'inactive'], ['status' => 'active', 'revoked_at' => '2026-10-24 00:01:00'],
            ['revoked_at' => null, 'rotation_bound_at' => null]] as $values) {
            $this->db->table('bmg_devices')->where('id', 1)->update($values + ['last_seen_at' => '2026-10-24 00:01:00']);
            $this->scanner('2026-10-23T16:01:01Z')->scan();
            self::assertCount(0, $this->events('bmg_device_recovered'));
            self::assertCount(1, $this->events('bmg_device_offline'));
        }
        $this->db->table('bmg_devices')->where('id', 1)->update(['rotation_bound_at' => '2026-10-24 00:02:00']);
        $this->scanner('2026-10-23T16:02:01Z')->scan(); // cached contact predates new binding
        self::assertCount(0, $this->events('bmg_device_recovered'));
        self::assertCount(1, $this->events('bmg_device_offline'));
        $this->db->table('bmg_units')->where('id', 1)->update(['deleted_at' => '2026-10-24 00:02:00']);
        $this->scanner('2026-10-23T16:18:00Z')->scan();
        self::assertCount(1, $this->events('bmg_device_offline'));
        $this->db->table('bmg_units')->where('id', 1)->update(['deleted_at' => null]);
        $this->scanner('2026-10-23T16:18:01Z')->scan();
        self::assertSame(['episode_1', 'episode_2'], array_column($this->events('bmg_device_offline'), 'occurrence_key'));
    }

    public function testClosedDeletedAndCrossTenantCasesAndPatientsAreSuppressed(): void
    {
        $this->put('guidance_followups', 1, ['due_date' => '2026-10-24']);
        $this->put('appointments', 1, ['starts_at' => '2026-10-24 01:00:00']);
        foreach ([['status' => 'closed'], ['status' => 'open', 'deleted_at' => '2026-10-23'],
            ['deleted_at' => null, 'tenant_id' => 2], ['tenant_id' => 1, 'person_id' => 99]] as $values) {
            $this->db->table('counselling_cases')->where('id', 1)->update($values);
            $this->scanner()->scan();
            self::assertCount(0, $this->events('guidance_followup_due'));
        }
        $this->db->table('counselling_cases')->where('id', 1)->update(['person_id' => 1]);
        $this->db->table('workflow_events')->where('id >', 0)->delete();
        foreach ([['deleted_at' => '2026-10-23'], ['deleted_at' => null, 'tenant_id' => 2]] as $values) {
            $this->db->table('people')->where('id', 1)->update($values);
            $this->scanner()->scan();
            self::assertCount(0, $this->events());
        }
        $this->db->table('people')->where('id', 1)->update(['tenant_id' => 1]);
        $this->scanner()->scan();
        self::assertCount(2, $this->events());
    }

    public function testThresholdsAndExclusions(): void
    {
        $this->put('appointments', 1, ['starts_at' => '2026-10-25 00:00:01']);
        $this->put('appointments', 2, ['starts_at' => '2026-10-23 23:59:59']);
        $this->put('appointments', 3, ['starts_at' => '2026-10-24 01:00:00', 'status' => 'requested']);
        $this->put('appointments', 4, ['starts_at' => '2026-10-24 01:00:00', 'deleted_at' => '2026-10-23']);
        $this->put('guidance_followups', 1, ['due_date' => '2026-10-24', 'status' => 'completed']);
        $this->put('guidance_followups', 2, ['due_date' => '2026-10-25']);
        $this->put('clinic_medicines', 1, ['is_active' => 0]);
        $this->put('clinic_medicine_batches', 1, ['medicine_id' => 1, 'expiration_date' => '2026-10-24']);
        $this->put('clinic_medicines', 2, ['minimum_stock_level' => 0]);
        $this->put('clinic_medicine_batches', 2, ['medicine_id' => 2, 'expiration_date' => '2027-01-22']); // 90 days
        $this->put('clinic_medicine_batches', 3, ['medicine_id' => 2, 'expiration_date' => '2027-01-23']);
        $this->put('clinic_medicine_batches', 4, ['medicine_id' => 2, 'expiration_date' => '2026-10-24', 'remaining_quantity' => 0]);
        $this->put('bmg_units', 1);
        $this->put('bmg_devices', 1, ['unit_id' => 1, 'last_seen_at' => '2026-10-23 23:45:00']); // exact 15m not older
        $this->put('bmg_units', 2);
        $this->put('bmg_devices', 2, ['unit_id' => 2, 'created_at' => '2026-10-23 23:45:00', 'rotation_bound_at' => '2026-10-23 23:45:00']);
        $this->put('bmg_units', 3, ['status' => 'maintenance']);
        $this->put('bmg_devices', 3, ['unit_id' => 3, 'rtc_valid' => 0]);
        $this->put('bmg_units', 4);
        $this->put('bmg_devices', 4, ['unit_id' => 4, 'revoked_at' => '2026-10-23 23:00:00', 'rtc_valid' => 0]);
        $this->put('bmg_rotation_commands', 1, ['expires_at' => '2026-10-24 00:00:00']);
        $this->put('bmg_rotation_commands', 2, ['expires_at' => '2026-10-23 23:00:00', 'status' => 'acknowledged']);
        $this->scanner()->scan();
        self::assertCount(1, $this->events());
        self::assertSame('inventory_near_expiry', $this->events()[0]['event_kind']);
        $this->scanner('2026-10-23T16:00:01Z')->scan();
        self::assertCount(2, $this->events('bmg_device_offline'));
        self::assertCount(1, $this->events('bmg_command_expired'));
        self::assertSame('pending', $this->db->table('bmg_rotation_commands')->where('id', 1)->get()->getRow('status'));
    }

    public function testChunkSweepDoesNotStarveAfterDedupedRowsAndIsTenantScoped(): void
    {
        for ($i = 1; $i <= 7; $i++) $this->put('appointments', $i, ['starts_at' => '2026-10-24 01:00:00']);
        $this->db->table('appointments')->insert(['id' => 8, 'tenant_id' => 2, 'starts_at' => '2026-10-24 01:00:00']);
        $this->scanner()->scan();
        $this->put('appointments', 9, ['starts_at' => '2026-10-24 01:00:00']);
        $this->scanner()->scan();
        self::assertCount(8, $this->events());
        self::assertSame([1], array_values(array_unique(array_column($this->events(), 'tenant_id'))));
        $this->db->table('tenants')->where('id', 1)->update(['is_active' => 0]);
        self::assertSame(['scanned' => 0, 'recorded' => 0, 'failed' => 0], $this->scanner()->scan());
        $this->db->table('tenants')->where('id', 1)->update(['is_active' => 1, 'deleted_at' => '2026-10-23']);
        self::assertSame(0, $this->scanner()->scan()['scanned']);
    }

    public function testRecordFailureRollsBackEpisodeAndEventAndCanRetry(): void
    {
        $this->put('clinic_medicines', 1);
        $result = $this->scanner(self::NOW, true)->scan();
        self::assertSame(1, $result['failed']);
        self::assertSame(0, $result['recorded']);
        self::assertSame([], $this->events());
        self::assertSame(0, $this->db->table('workflow_alert_states')->countAllResults());
        self::assertSame(0, $this->db->transDepth);
        self::assertSame(0, $this->scanner()->scan()['failed']);
        self::assertSame('episode_1', $this->events()[0]['occurrence_key']);
    }

    public function testEnableFlagsDoNotCreateHiddenEpisodesAndNestedTransactionIsRejected(): void
    {
        $this->put('clinic_medicines', 1);
        $this->alerts->kinds['inventory_low_stock'] = false;
        $this->scanner()->scan();
        self::assertSame(0, $this->db->table('workflow_alert_states')->countAllResults());
        $this->alerts->enabled = false;
        self::assertSame(0, $this->scanner()->scan()['scanned']);
        $this->alerts->enabled = true;
        $this->db->transBegin();
        try {
            $this->scanner()->scan();
            self::fail('Nested transaction was accepted.');
        } catch (RuntimeException $e) {
            self::assertSame(1, $this->db->transDepth);
        } finally {
            $this->db->transRollback();
        }
    }
}
