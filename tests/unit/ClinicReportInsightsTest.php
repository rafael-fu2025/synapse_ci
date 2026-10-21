<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Reporting\ClinicReportInsights;
use App\Services\Reporting\ExportRange;
use App\Services\Reporting\OperationalExportService;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** Private SQLite only: no migrations, default connections, operational data or service writes. */
final class ClinicReportInsightsTest extends CIUnitTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'insights_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $this->createTable('clinic_encounters', [
            'id' => 'INTEGER PRIMARY KEY', 'tenant_id' => 'INTEGER', 'status' => 'TEXT',
            'started_at' => 'TEXT', 'chief_complaint' => 'TEXT', 'disposition' => 'TEXT', 'deleted_at' => 'TEXT',
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        parent::tearDown();
    }

    private function createTable(string $table, array $columns): void
    {
        $definitions = [];
        foreach ($columns as $name => $type) {
            $definitions[] = $this->db->escapeIdentifiers($name) . ' ' . $type;
        }
        $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' (' . implode(', ', $definitions) . ')');
    }

    private function encounter(array $changes = []): int
    {
        $this->db->table('clinic_encounters')->insert(array_replace([
            'tenant_id' => 1, 'status' => 'completed', 'started_at' => '2026-10-19 16:00:00',
            'chief_complaint' => null, 'disposition' => null,
        ], $changes));
        return (int) $this->db->insertID();
    }

    private function collect(string $timezone = 'UTC'): array
    {
        return ClinicReportInsights::collect($this->db, 1, $this->range(), $timezone);
    }

    private function range(): ExportRange
    {
        return ExportRange::fromInput(['from' => '2026-10-20', 'to' => '2026-10-20']);
    }

    /** Minimum private fixture columns for InventoryService's real readiness preflight. */
    private function ledger(bool $historyMarker = true): void
    {
        $tables = [
            'clinic_medicines' => 'id tenant_id name generic_name category dosage_form strength unit_of_measure minimum_stock_level total_stock is_active version created_at updated_at',
            'clinic_medicine_batches' => 'id tenant_id medicine_id batch_number expiration_date initial_quantity remaining_quantity status created_at updated_at',
            'clinic_dispensations' => 'id tenant_id encounter_id medicine_id batch_id quantity instructions dispensed_by_user_id created_at operation_id snapshot_name snapshot_generic_name snapshot_strength snapshot_dosage_form snapshot_unit snapshot_batch_number snapshot_expiration_date snapshot_reconstructed',
            'inventory_operations' => 'id tenant_id medicine_id encounter_id actor_id idempotency_key payload_hash type reason supplier reference reversal_of reversal_mode created_at',
            'stock_movements' => 'id tenant_id medicine_id batch_id operation_id actor_id delta reason original_movement_id created_at',
        ];
        $integerColumns = ['tenant_id', 'medicine_id', 'encounter_id', 'batch_id', 'quantity', 'operation_id', 'reversal_of'];
        foreach ($tables as $table => $names) {
            $columns = [];
            foreach (explode(' ', $names) as $name) {
                $columns[$name] = $name === 'id' ? 'INTEGER PRIMARY KEY' : (in_array($name, $integerColumns, true) ? 'INTEGER' : 'TEXT');
            }
            $this->createTable($table, $columns);
        }
        $this->createTable(config('Migrations')->table, ['version' => 'TEXT', 'namespace' => 'TEXT']);
        if ($historyMarker) {
            $this->db->table(config('Migrations')->table)->insert(['version' => '2026-10-15-000002', 'namespace' => 'App']);
        }
    }

    private function medicine(int $id, array $changes = []): void
    {
        $this->db->table('clinic_medicines')->insert(array_replace([
            'id' => $id, 'tenant_id' => 1, 'name' => 'Medicine ' . $id,
            'strength' => '500 mg', 'dosage_form' => 'Tablet', 'is_active' => 0,
        ], $changes));
    }

    private function dispense(int $encounter, int $medicine, array $operation = [], array $allocation = []): int
    {
        $this->db->table('inventory_operations')->insert(array_replace([
            'tenant_id' => 1, 'encounter_id' => $encounter, 'medicine_id' => $medicine,
            'type' => 'dispense', 'created_at' => '2026-10-25 00:00:00',
        ], $operation));
        $id = (int) $this->db->insertID();
        $this->db->table('clinic_dispensations')->insert(array_replace([
            'tenant_id' => 1, 'encounter_id' => $encounter, 'medicine_id' => $medicine,
            'operation_id' => $id, 'quantity' => 1,
        ], $allocation));
        return $id;
    }

    private function counts(array $rows): array
    {
        return array_column($rows, 'count', 'label');
    }

    public function testCohortMatchesDailyIncludingSoftDeletesAndManilaBounds(): void
    {
        $this->encounter(['chief_complaint' => ' HEADACHE ', 'disposition' => 'discharged']);
        $this->encounter(['started_at' => '2026-10-20 15:59:59', 'deleted_at' => '2026-10-21', 'chief_complaint' => 'fever']);
        foreach ([
            ['tenant_id' => 2], ['status' => 'in_progress'], ['status' => 'referred'],
            ['started_at' => '2026-10-19 15:59:59'], ['started_at' => '2026-10-20 16:00:00'],
        ] as $changes) {
            $this->encounter($changes);
        }
        $result = $this->collect();
        $daily = (new OperationalExportService())->aggregate($this->db, 1, $this->range(), 'UTC', ['clinic']);
        $this->assertSame(2, $result['encounter_total']);
        $this->assertSame($daily[1][3], $result['encounter_total']);
        $this->assertSame(['Fever' => 1, 'Headache' => 1, 'Not recorded' => 0], $this->counts($result['complaints']));
    }

    public function testStorageTimezoneIsRespected(): void
    {
        $this->encounter(['started_at' => '2026-10-20 00:00:00']);
        $this->encounter(['started_at' => '2026-10-20 23:59:59']);
        $this->encounter(['started_at' => '2026-10-21 00:00:00']);
        $this->assertSame(2, $this->collect('Asia/Manila')['encounter_total']);
    }

    public function testOnlyExactComplaintVocabularyLeavesSqlAndOverflowBecomesOther(): void
    {
        foreach (['headache', 'fever', 'dizziness', 'abdominal pain', 'cough', 'colds', 'toothache', 'injury', 'nausea', 'vomiting', 'menstrual cramps', 'checkup'] as $complaint) {
            $this->encounter(['chief_complaint' => strtoupper($complaint)]);
        }
        foreach (['Patient Alice has headache', 'headaches', 'abdominal  pain', 'check-up'] as $narrative) {
            $this->encounter(['chief_complaint' => $narrative, 'disposition' => 'PRIVATE OUTCOME']);
        }
        $this->encounter(['chief_complaint' => '   ']);
        $this->encounter();
        $result = $this->collect();
        $this->assertSame([
            ['label' => 'Abdominal Pain', 'count' => 1], ['label' => 'Checkup', 'count' => 1],
            ['label' => 'Colds', 'count' => 1], ['label' => 'Cough', 'count' => 1],
            ['label' => 'Dizziness', 'count' => 1], ['label' => 'Other recorded', 'count' => 11],
            ['label' => 'Not recorded', 'count' => 2],
        ], $result['complaints']);
        $this->assertSame(18, array_sum(array_column($result['complaints'], 'count')));
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Alice', $encoded);
        $this->assertStringNotContainsString('PRIVATE', $encoded);
        $this->assertStringNotContainsString('abdominal  pain', $encoded);
    }

    public function testDispositionVocabularyAndMissingLabelsAreSafeAndStable(): void
    {
        foreach (['discharged', 'admitted', 'referred_to_guidance', 'sent_home', 'referred_to_hospital', 'untrusted narrative', '', null] as $disposition) {
            $this->encounter(['disposition' => $disposition]);
        }
        $this->assertSame([
            'Discharged' => 1, 'Admitted' => 1, 'Referred to Guidance' => 1,
            'Sent Home' => 1, 'Referred to Hospital' => 1, 'Other recorded' => 1, 'Not recorded' => 2,
        ], $this->counts($this->collect()['dispositions']));
    }

    public function testMissingLedgerAndIncompleteCutoverAreUnavailableNotReadyZeros(): void
    {
        $this->encounter();
        $result = $this->collect();
        $this->assertSame('unavailable', $result['medicine_status']);
        $this->assertSame([], $result['medicines']);
        $this->assertSame(0, $result['legacy_medicine_encounters']);
        $this->ledger(false);
        $this->assertSame('unavailable', $this->collect()['medicine_status']);
    }

    public function testLegacySchemaWithoutOperationColumnRemainsUnavailable(): void
    {
        $this->createTable('clinic_dispensations', [
            'id' => 'INTEGER PRIMARY KEY', 'tenant_id' => 'INTEGER',
            'encounter_id' => 'INTEGER', 'medicine_id' => 'INTEGER', 'quantity' => 'INTEGER',
        ]);
        $encounter = $this->encounter();
        $this->db->table('clinic_dispensations')->insert([
            'tenant_id' => 1, 'encounter_id' => $encounter, 'medicine_id' => 1, 'quantity' => 3,
        ]);
        $result = $this->collect();
        $this->assertSame('unavailable', $result['medicine_status']);
        $this->assertSame(1, $result['encounter_total']);
        $this->assertSame(0, $result['legacy_medicine_encounters']);
    }

    public function testEmptyReadyLedgerDiffersFromUnavailableAndIncludesMissingComplaints(): void
    {
        $this->ledger();
        $result = $this->collect();
        $this->assertSame('ready', $result['medicine_status']);
        $this->assertSame(0, $result['medicine_encounters']);
        $this->assertSame([['label' => 'Not recorded', 'count' => 0]], $result['complaints']);
        $this->assertCount(6, $result['dispositions']);
        $this->assertSame(0, $result['encounter_total']);
    }

    public function testMedicineRankingDeduplicatesAllocationsUsesEncounterCountsAndStableIds(): void
    {
        $this->ledger();
        $first = $this->encounter(['deleted_at' => '2026-10-21']);
        $second = $this->encounter();
        for ($id = 1; $id <= 7; $id++) {
            $this->medicine($id);
            $this->dispense($first, $id, [], ['quantity' => $id === 1 ? 9999 : 1]);
        }
        $op = $this->dispense($second, 7);
        $this->db->table('clinic_dispensations')->insert([
            'tenant_id' => 1, 'encounter_id' => $second, 'medicine_id' => 7, 'operation_id' => $op, 'quantity' => 5,
        ]);
        $this->dispense($second, 7);
        $result = $this->collect();
        $this->assertSame('ready', $result['medicine_status']);
        $this->assertSame(2, $result['medicine_encounters']);
        $this->assertSame([
            'Medicine 7 / 500 mg / Tablet' => 2, 'Medicine 1 / 500 mg / Tablet' => 1,
            'Medicine 2 / 500 mg / Tablet' => 1, 'Medicine 3 / 500 mg / Tablet' => 1,
            'Medicine 4 / 500 mg / Tablet' => 1,
        ], $this->counts($result['medicines']));
    }

    public function testReversalsBothModesOutsidePeriodAndTenantSafeLedgerLinks(): void
    {
        $this->ledger();
        $this->medicine(1);
        $this->medicine(2, ['tenant_id' => 2, 'name' => 'Foreign master']);
        $valid = $this->encounter();
        $this->dispense($valid, 1);
        foreach (['restore_unissued', 'record_only'] as $mode) {
            $id = $this->dispense($this->encounter(), 1);
            $this->db->table('inventory_operations')->insert([
                'tenant_id' => 1, 'type' => 'reversal', 'reversal_of' => $id,
                'reversal_mode' => $mode, 'created_at' => '2027-01-01 00:00:00',
            ]);
        }
        $foreignReversal = $this->dispense($valid, 1);
        $this->db->table('inventory_operations')->insert(['tenant_id' => 2, 'type' => 'reversal', 'reversal_of' => $foreignReversal]);
        $this->dispense($this->encounter(), 2);
        $this->dispense($this->encounter(['tenant_id' => 2]), 1);
        $this->dispense($this->encounter(['status' => 'cancelled']), 1);
        $this->dispense($this->encounter(['started_at' => '2026-10-20 16:00:00']), 1);
        $bad = $this->encounter();
        foreach ([['tenant_id' => 2], ['encounter_id' => $valid], ['medicine_id' => 2], ['type' => 'receipt'], ['reversal_of' => 1]] as $operation) {
            $this->dispense($bad, 1, $operation);
        }
        $this->dispense($bad, 1, [], ['tenant_id' => 2]);
        $this->dispense($bad, 1, [], ['quantity' => 0]);
        $this->dispense($bad, 1, [], ['quantity' => -1]);
        $this->db->table('clinic_dispensations')->insert(['tenant_id' => 1, 'encounter_id' => $bad, 'medicine_id' => 1, 'operation_id' => 99999, 'quantity' => 1]);
        foreach ([$valid, $valid, $bad] as $encounter) {
            $this->db->table('clinic_dispensations')->insert(['tenant_id' => 1, 'encounter_id' => $encounter, 'medicine_id' => 1, 'operation_id' => null, 'quantity' => 2]);
        }
        $this->db->table('clinic_dispensations')->insert(['tenant_id' => 2, 'encounter_id' => $this->encounter(), 'medicine_id' => 1, 'quantity' => 2]);
        $result = $this->collect();
        $this->assertSame(1, $result['medicine_encounters']);
        $this->assertSame([['label' => 'Medicine 1 / 500 mg / Tablet', 'count' => 1]], $result['medicines']);
        $this->assertSame(2, $result['legacy_medicine_encounters']);
    }

    public function testCatalogLabelsAreBoundedUtf8WithoutControlsOrSnapshotNotes(): void
    {
        $this->ledger();
        $this->medicine(1, ['name' => "  Café\x01\x1B  medicine\n ", 'strength' => str_repeat('é', 150), 'dosage_form' => str_repeat('錠', 150)]);
        $this->dispense($this->encounter(), 1, [], ['instructions' => 'Private patient instructions', 'snapshot_name' => 'Private snapshot']);
        $label = $this->collect()['medicines'][0]['label'];
        $this->assertTrue(mb_check_encoding($label, 'UTF-8'));
        $this->assertLessThanOrEqual(240, mb_strlen($label, 'UTF-8'));
        $this->assertSame(0, preg_match('/[\p{Cc}\p{Cf}]/u', $label));
        $this->assertStringStartsWith('Café medicine / ', $label);
        $this->assertStringNotContainsString('Private', $label);
    }
}
