<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClinicDispensationModel;
use App\Models\ClinicMedicineModel;
use App\Services\Clinic\EncounterService;
use App\Services\CurrentTenant;
use Tests\Support\InventoryDatabaseTestCase;

final class InventoryServiceTest extends InventoryDatabaseTestCase
{
    private function catalogData(): array
    {
        return ['name'=>'Keyed catalog', 'generic_name'=>null, 'category'=>'Analgesic',
            'dosage_form'=>'Tablet', 'strength'=>null, 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>0];
    }

    public function testCatalogCreateReplayUsesNormalizedPayloadWithoutDuplicateRows(): void
    {
        $data = $this->catalogData();
        $first = $this->inventory->saveMedicine(null, array_replace($data, [
            'name'=>' Keyed catalog ', 'generic_name'=>'', 'strength'=>' ', 'minimum_stock_level'=>'0',
        ]), $this->actorId, 'catalog-create');
        $before = $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults();
        $retry = $this->inventory->saveMedicine(null, array_reverse($data, true) + ['is_active'=>'1'], $this->actorId, 'catalog-create');
        $this->assertSame($first, $retry);
        $this->assertSame($before, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $operation = $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->where('idempotency_key', 'catalog-create')->get()->getRowArray();
        $this->assertSame('catalog', $operation['type']);
        $this->assertSame((int) $first['id'], (int) $operation['medicine_id']);
        $this->assertNull($operation['encounter_id']);
        $this->assertSame(1, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(0, $this->db->table('stock_movements')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(0, $this->db->table('clinic_medicine_batches')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(0, (int) $retry['total_stock']);
    }

    public function testCatalogKeyConflictsOnDifferentPayloadActorAndStockOperationType(): void
    {
        $data = $this->catalogData();
        $this->inventory->saveMedicine(null, $data, $this->actorId, 'catalog-create');
        $before = $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults();
        $this->rejected(fn () => $this->inventory->saveMedicine(null, array_replace($data, ['name'=>'Different medicine']), $this->actorId, 'catalog-create'), 409);
        $otherActor = $this->user('superadmin');
        $this->rejected(fn () => $this->inventory->saveMedicine(null, $data, $otherActor, 'catalog-create'), 409);
        $this->rejected(fn () => $this->receipt(1, key: 'catalog-create'), 409);
        $this->receipt(1, key: 'stock-receipt');
        $this->rejected(fn () => $this->inventory->saveMedicine(null, $data, $this->actorId, 'stock-receipt'), 409);
        $this->assertSame($before, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(2, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertBalanced(1);
    }

    public function testCatalogOperationKeyConflictRollsBackTheNewMedicine(): void
    {
        $this->receipt(1, key: 'occupied');
        $before = $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults();
        $table = $this->db->protectIdentifiers($this->db->prefixTable('inventory_operations'));
        $trigger = $this->db->protectIdentifiers($this->db->prefixTable('inventory_test_catalog_' . $this->tenantId));
        // Simulate a key collision discovered only after the new medicine row has been inserted.
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.tenant_id=" . $this->tenantId . " AND NEW.type='catalog' THEN SET NEW.idempotency_key='occupied'; END IF; END");
        try {
            $this->rejected(fn () => $this->inventory->saveMedicine(null, $this->catalogData(), $this->actorId, 'new-catalog'), 409);
        } finally {
            $this->db->query('DROP TRIGGER ' . $trigger);
        }
        $this->assertSame($before, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(1, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertBalanced(1);
        $this->inventory->saveMedicine(null, $this->catalogData(), $this->actorId, 'new-catalog');
        $this->assertSame($before + 1, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
    }

    public function testCatalogReplayRechecksCurrentActorStateAndManagerRole(): void
    {
        $data = $this->catalogData();
        $this->inventory->saveMedicine(null, $data, $this->actorId, 'catalog-create');
        try {
            $this->db->table('users')->where('id', $this->actorId)->update(['status'=>'suspended']);
            $this->rejected(fn () => $this->inventory->saveMedicine(null, $data, $this->actorId, 'catalog-create'), 403);
            $this->db->table('users')->where('id', $this->actorId)->update(['status'=>'active', 'role'=>'clinic_staff']);
            $this->rejected(fn () => $this->inventory->saveMedicine(null, $data, $this->actorId, 'catalog-create'), 403);
        } finally {
            $this->db->table('users')->where('id', $this->actorId)->update(['status'=>'active', 'role'=>'superadmin']);
        }
        $this->assertSame(2, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(1, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->countAllResults());
    }

    public function testReceiptReplayAndLotArithmetic(): void
    {
        $first = $this->receipt(10, key: 'same-receipt');
        $retry = $this->receipt(10, key: 'same-receipt');
        $this->assertTrue($retry['replayed']);
        $this->assertSame((int) $first['operation']['id'], (int) $retry['operation']['id']);
        $this->receipt(5);
        $batch = $this->inventory->details($this->medicineId)['batches'];
        $this->assertCount(1, $batch);
        $this->assertSame(15, (int) $batch[0]['initial_quantity']);
        $this->rejected(fn () => $this->receipt(11, key: 'same-receipt'), 409);
        $this->rejected(fn () => $this->receipt(1, expiry: '+2 years'), 409);
        $this->assertBalanced(15);
    }

    public function testFefoIncludesTodayAndUsesIdForTiesButSkipsExpiredAndQuarantined(): void
    {
        $expired = $this->receipt(50, 'EXPIRED');
        $this->db->table('clinic_medicine_batches')->where('id', $expired['movements'][0]['batch_id'])->update(['expiration_date'=>date('Y-m-d', strtotime('-1 day'))]);
        $quarantine = $this->receipt(50, 'QUARANTINE', '+1 day');
        $this->inventory->setBatchStatus((int) $quarantine['movements'][0]['batch_id'], 'quarantined', 'Recall', $this->actorId, 'quarantine');
        $first = $this->receipt(2, 'TODAY-A', 'today');
        $second = $this->receipt(3, 'TODAY-B', 'today');
        $this->receipt(10, 'LATER', '+2 days');
        $result = $this->dispense(4);
        $this->assertSame([(int) $first['movements'][0]['batch_id'], (int) $second['movements'][0]['batch_id']], array_map('intval', array_column($result['allocations'], 'batch_id')));
        $this->assertSame([2,2], array_map('intval', array_column($result['allocations'], 'quantity')));
        $summary = $this->inventory->details($this->medicineId)['medicine'];
        $this->assertSame(11, $summary['usable_stock']);
        $this->assertSame(50, $summary['expired_stock']);
        $this->assertSame(50, $summary['quarantined_stock']);
        $this->assertSame(11, $summary['near_expiry_stock']);
        $this->assertBalanced(111);
    }

    public function testPickerUsesUsableStockAndArchivedSummariesPreservePhysicalOverlap(): void
    {
        $this->receipt(10, 'USABLE', 'today');
        $expired = $this->receipt(4, 'EXPIRED');
        $quarantined = $this->receipt(7, 'QUARANTINED');
        $this->inventory->setBatchStatus((int) $quarantined['movements'][0]['batch_id'], 'quarantined', 'Recall', $this->actorId, 'quarantine');
        foreach ([$expired, $quarantined] as $receipt) {
            $this->db->table('clinic_medicine_batches')->where('id', $receipt['movements'][0]['batch_id'])->update(['expiration_date'=>date('Y-m-d', strtotime('-1 day'))]);
        }
        $picker = (new ClinicMedicineModel())->getInStockList();
        $this->assertCount(1, $picker);
        $this->assertSame($this->medicineId, (int) $picker[0]['id']);
        $this->assertSame(10, (int) $picker[0]['usable_stock']);
        $this->assertSame(21, (int) $picker[0]['total_stock']);
        $listed = $this->inventory->listMedicines();
        $this->assertCount(1, $listed);
        $this->assertSame($this->inventory->details($this->medicineId)['medicine'], $listed[0]);
        $this->assertSame(10, $listed[0]['usable_stock']);
        $this->assertSame(10, $listed[0]['near_expiry_stock']);
        $this->assertFalse($listed[0]['low_stock']);

        $this->inventory->saveMedicine($this->medicineId, ['version'=>$listed[0]['version'], 'is_active'=>'0'], $this->actorId);
        $archived = $this->inventory->listMedicines('', 'archived');
        $this->assertCount(1, $archived);
        $summary = $archived[0];
        $this->assertSame($this->inventory->details($this->medicineId)['medicine'], $summary);
        $this->assertSame(0, $summary['usable_stock']);
        $this->assertSame(0, $summary['near_expiry_stock']);
        $this->assertSame(21, $summary['total_stock']);
        $this->assertSame(11, $summary['expired_stock']);
        $this->assertSame(7, $summary['quarantined_stock']);
        $this->assertTrue($summary['low_stock']);
        $this->assertSame([], (new ClinicMedicineModel())->getInStockList());
        $this->assertSame([], $this->inventory->listMedicines('', 'active'));
        $this->assertSame($archived, $this->inventory->listMedicines('', 'low_stock'));
        $this->assertBalanced(21);
    }

    public function testPickerExcludesActiveCatalogWithNoUsableBatches(): void
    {
        $this->assertSame([], (new ClinicMedicineModel())->getInStockList());
        $receipt = $this->receipt();
        $this->inventory->setBatchStatus((int) $receipt['movements'][0]['batch_id'], 'quarantined', 'Recall', $this->actorId, 'quarantine');
        $this->assertSame([], (new ClinicMedicineModel())->getInStockList());
        $summary = $this->inventory->listMedicines()[0];
        $this->assertSame(10, $summary['total_stock']);
        $this->assertSame(0, $summary['usable_stock']);
        $this->assertSame(0, $summary['near_expiry_stock']);
    }

    public function testClosedEncounterAllowsReplayButRejectsNewDispensation(): void
    {
        $this->receipt();
        $first = $this->dispense(3, 'first-dispense');
        (new EncounterService())->completeEncounter($this->encounterId, 'discharged');
        $retry = $this->dispense(3, 'first-dispense');
        $this->assertTrue($retry['replayed']);
        $this->assertSame($first['allocations'], $retry['allocations']);
        $this->rejected(fn () => $this->dispense(1), 409);
        $this->assertBalanced(7);
    }

    public function testSnapshotPreservesLabelsAndCatalogIdentityIsFrozen(): void
    {
        $this->receipt();
        $this->dispense();
        $medicine = $this->inventory->details($this->medicineId)['medicine'];
        $this->inventory->saveMedicine($this->medicineId, ['name'=>'Renamed display', 'version'=>$medicine['version']], $this->actorId);
        $allocations = (new ClinicDispensationModel())->getForEncounter($this->encounterId);
        $this->assertSame('Ledger medicine', $allocations[0]['medicine_name']);
        $this->assertSame(0, (int) $allocations[0]['snapshot_reconstructed']);
        $medicine = $this->inventory->details($this->medicineId)['medicine'];
        foreach (['unit_of_measure'=>'bottle', 'strength'=>'100mg', 'generic_name'=>'Other', 'dosage_form'=>'Syrup'] as $field => $value) {
            $this->rejected(fn () => $this->inventory->saveMedicine($this->medicineId, [$field=>$value, 'version'=>$medicine['version']], $this->actorId), 409);
        }
        $this->rejected(fn () => $this->inventory->saveMedicine($this->medicineId, ['name'=>'Stale', 'version'=>1], $this->actorId), 409);
        $this->rejected(fn () => $this->inventory->saveMedicine($this->medicineId, ['total_stock'=>100, 'version'=>$medicine['version']], $this->actorId), 422);
    }

    public function testLegacyBlankNullableIdentityDoesNotBlockMetadataEditsAfterStock(): void
    {
        $this->db->table('clinic_medicines')->where('id', $this->medicineId)->update(['generic_name'=>'', 'strength'=>'   ']);
        $this->receipt();
        $medicine = $this->inventory->details($this->medicineId)['medicine'];
        $saved = $this->inventory->saveMedicine($this->medicineId, [
            'name'=>'Display edit only', 'generic_name'=>null, 'strength'=>'', 'version'=>$medicine['version'],
        ], $this->actorId);
        $this->assertSame('Display edit only', $saved['name']);
        $this->assertNull($saved['generic_name']);
        $this->assertNull($saved['strength']);
        $saved = $this->inventory->saveMedicine($this->medicineId, [
            'generic_name'=>' ', 'strength'=>null, 'version'=>$saved['version'],
        ], $this->actorId);
        $this->assertNull($saved['generic_name']);
        $this->assertNull($saved['strength']);
        foreach (['generic_name'=>'New generic', 'strength'=>'100mg', 'unit_of_measure'=>'bottle'] as $field => $value) {
            $this->rejected(fn () => $this->inventory->saveMedicine($this->medicineId, [$field=>$value, 'version'=>$saved['version']], $this->actorId), 409);
        }
        $this->assertBalanced(10);
    }

    public function testRemovalWorksForExpiredQuarantineAndReleaseNeverChangesPhysicalStock(): void
    {
        $receipt = $this->receipt();
        $batchId = (int) $receipt['movements'][0]['batch_id'];
        $status = $this->inventory->setBatchStatus($batchId, 'quarantined', 'Inspection', $this->actorId, 'q');
        $this->assertSame(0, (int) $status['movements'][0]['delta']);
        $this->assertBalanced(10);
        $this->rejected(fn () => $this->dispense(), 409);
        $this->inventory->setBatchStatus($batchId, 'active', 'Inspection passed', $this->actorId, 'release');
        $this->assertBalanced(10);
        $this->inventory->setBatchStatus($batchId, 'quarantined', 'Recall', $this->actorId, 'q-again');
        $this->db->table('clinic_medicine_batches')->where('id', $batchId)->update(['expiration_date'=>date('Y-m-d', strtotime('-1 day'))]);
        $this->rejected(fn () => $this->inventory->setBatchStatus($batchId, 'active', 'Unsafe release', $this->actorId, 'bad-release'), 409);
        $this->inventory->adjust($batchId, ['quantity'=>'4', 'reason'=>'expired', 'note'=>'Destroyed under supervision'], $this->actorId, 'dispose');
        $this->rejected(fn () => $this->inventory->adjust($batchId, ['quantity'=>7, 'reason'=>'count_correction', 'note'=>'Too much'], $this->actorId, 'excess'), 409);
        $this->assertBalanced(6);
    }

    public function testFullUnissuedReversalPreservesOriginalAndCannotRepeat(): void
    {
        $this->receipt(2, 'A');
        $this->receipt(8, 'B');
        $dispensed = $this->dispense(5);
        $id = (int) $dispensed['operation']['id'];
        $reversal = $this->inventory->reverse($id, 'restore_unissued', 'Never issued; canceled order', $this->actorId, 'reverse');
        $this->assertSame($id, (int) $reversal['operation']['reversal_of']);
        $this->assertSame(5, array_sum(array_column($reversal['movements'], 'delta')));
        $this->assertCount(2, $reversal['movements']);
        $this->assertTrue($this->inventory->reverse($id, 'restore_unissued', 'Never issued; canceled order', $this->actorId, 'reverse')['replayed']);
        $this->rejected(fn () => $this->inventory->reverse($id, 'record_only', 'Again', $this->actorId, 'reverse-twice'), 409);
        $original = $this->db->table('clinic_dispensations')->where('operation_id', $id)->orderBy('id')->get()->getResultArray();
        $this->assertSame($dispensed['allocations'], $original);
        $view = (new ClinicDispensationModel())->getForEncounter($this->encounterId);
        $this->assertSame((int) $reversal['operation']['id'], (int) $view[0]['reversed_by_operation_id']);
        $this->assertBalanced(10);
    }

    public function testRecordOnlyDoesNotPutPatientReturnsIntoStock(): void
    {
        $this->receipt();
        $dispensed = $this->dispense(5);
        $reversed = $this->inventory->reverse((int) $dispensed['operation']['id'], 'record_only', 'Chart correction; stock already issued', $this->actorId, 'record');
        $this->assertSame(0, (int) $reversed['movements'][0]['delta']);
        $this->assertBalanced(5);
        $this->rejected(fn () => $this->inventory->reverse((int) $dispensed['operation']['id'], 'patient_return', 'Returned', $this->actorId, 'return'), 422);
    }

    public function testRestorationRejectsQuarantinedOrExpiredLots(): void
    {
        $receipt = $this->receipt();
        $batchId = (int) $receipt['movements'][0]['batch_id'];
        $dispensed = $this->dispense();
        $id = (int) $dispensed['operation']['id'];
        $this->inventory->setBatchStatus($batchId, 'quarantined', 'Recall', $this->actorId, 'q');
        $this->rejected(fn () => $this->inventory->reverse($id, 'restore_unissued', 'Never issued', $this->actorId, 'reverse-q'), 409);
        $this->inventory->setBatchStatus($batchId, 'active', 'Cleared', $this->actorId, 'release');
        $this->db->table('clinic_medicine_batches')->where('id', $batchId)->update(['expiration_date'=>date('Y-m-d', strtotime('-1 day'))]);
        $this->rejected(fn () => $this->inventory->reverse($id, 'restore_unissued', 'Never issued', $this->actorId, 'reverse-expired'), 409);
        $this->assertBalanced(7);
    }

    public function testCrossTenantActorsRecordsAndReplayActorsAreRejected(): void
    {
        $this->receipt(10, key: 'receipt');
        $otherActor = $this->user('superadmin', 1);
        $this->rejected(fn () => $this->inventory->receive($this->medicineId, ['batch_number'=>'OTHER', 'expiration_date'=>date('Y-m-d'), 'quantity'=>1], $otherActor, 'other-actor'), 403);
        $sameTenantActor = $this->user('superadmin');
        $this->rejected(fn () => $this->inventory->receive($this->medicineId, ['batch_number'=>'LOT-A', 'expiration_date'=>date('Y-m-d', strtotime('+1 year')), 'quantity'=>10], $sameTenantActor, 'receipt'), 409);
        CurrentTenant::set(1);
        try {
            $this->rejected(fn () => $this->inventory->details($this->medicineId), 404);
            $this->rejected(fn () => (new EncounterService())->getEncounterDetails($this->encounterId), 0);
        } finally {
            CurrentTenant::set($this->tenantId);
        }
        $this->db->table('people')->where('id', $this->patientId)->update(['deleted_at'=>date('Y-m-d H:i:s')]);
        $this->rejected(fn () => $this->dispense(), 409);
        $this->assertBalanced(10);
    }

    public function testFreshRolePermissionsAndManagerBoundary(): void
    {
        $staff = $this->user('clinic_staff');
        $grants = [];
        foreach (['clinic.inventory.read','clinic.inventory.write'] as $code) {
            $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
            if (!$permission) {
                $this->db->table('permissions')->insert(['code'=>$code,'name'=>$code,'module'=>'clinic']);
                $permission = ['id'=>$this->db->insertID()];
            }
            $permissionId = (int) $permission['id'];
            $existed = $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $permissionId)->countAllResults() > 0;
            $grants[$permissionId] = $existed;
            if (!$existed) {
                $this->db->table('role_permissions')->insert(['role'=>'clinic_staff','permission_id'=>$permissionId]);
            }
        }
        try {
            $this->inventory->assertAccess($staff, true);
            $this->rejected(fn () => $this->inventory->saveMedicine($this->medicineId, ['name'=>'Not manager','version'=>1], $staff), 403);
            $this->receipt();
            $this->inventory->dispense($this->encounterId, $this->medicineId, 1, null, $staff, 'staff-dispense');
            $writeId = (int) $this->db->table('permissions')->where('code', 'clinic.inventory.write')->get()->getRow()->id;
            $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $writeId)->delete();
            $this->rejected(fn () => $this->inventory->dispense($this->encounterId, $this->medicineId, 1, null, $staff, 'staff-dispense'), 403);
            $this->db->table('users')->where('id', $this->actorId)->update(['status'=>'suspended']);
            $this->rejected(fn () => $this->inventory->assertAccess($this->actorId, true), 403);
        } finally {
            foreach ($grants as $id => $existed) {
                $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $id)->delete();
                if ($existed) {
                    $this->db->table('role_permissions')->insert(['role'=>'clinic_staff','permission_id'=>$id]);
                }
            }
        }
    }

    public function testInsertionFailureRollsBackAllocationsLedgerBatchAndHeader(): void
    {
        $this->receipt(2, 'A');
        $this->receipt(8, 'B');
        $table = $this->db->protectIdentifiers($this->db->prefixTable('stock_movements'));
        $trigger = $this->db->protectIdentifiers($this->db->prefixTable('inventory_test_fail_' . $this->tenantId));
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.tenant_id=" . $this->tenantId . " AND NEW.reason='dispense' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected ledger failure'; END IF; END");
        try {
            try {
                $this->dispense(5, 'failing');
                $this->fail('Injected ledger insert should fail.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('inventory', strtolower($e->getMessage()));
            }
        } finally {
            $this->db->query('DROP TRIGGER ' . $trigger);
        }
        $this->assertSame(0, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->where('idempotency_key', 'failing')->countAllResults());
        $this->assertSame(0, $this->db->table('clinic_dispensations')->where('encounter_id', $this->encounterId)->countAllResults());
        $this->assertBalanced(10);
        $this->dispense(5, 'failing');
        $this->assertBalanced(5);
    }

    public function testReceiptPostconditionRollsBackAnIncorrectLedgerDelta(): void
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('stock_movements'));
        $trigger = $this->db->protectIdentifiers($this->db->prefixTable('inventory_test_delta_' . $this->tenantId));
        $this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NEW.tenant_id=" . $this->tenantId . " AND NEW.reason='receipt' THEN SET NEW.delta=0; END IF; END");
        try {
            $before = $this->inventory->details($this->medicineId);
            $this->rejected(fn () => $this->receipt(10, key: 'incorrect-delta'), 409);
            $this->assertSame($before, $this->inventory->details($this->medicineId));
        } finally {
            $this->db->query('DROP TRIGGER ' . $trigger);
        }
        $this->assertBalanced(0);
        $this->receipt(10, key: 'incorrect-delta');
        $this->assertBalanced(10);
    }

    public function testCacheDriftStopsWritesAndMigrationWithoutRepair(): void
    {
        $this->receipt();
        $this->db->table('clinic_medicines')->where('id', $this->medicineId)->update(['total_stock'=>11]);
        try {
            $this->rejected(fn () => $this->dispense(), 409);
            require_once APPPATH . 'Database/Migrations/2026-10-15-000002_AddClinicInventoryLedger.php';
            $migration = new \App\Database\Migrations\AddClinicInventoryLedger(\Config\Database::forge('tests'));
            try {
                $migration->up();
                $this->fail('Preflight must refuse drift.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('preflight STOP', $e->getMessage());
            }
            $this->assertSame(11, (int) $this->db->table('clinic_medicines')->where('id', $this->medicineId)->get()->getRow()->total_stock);
        } finally {
            $this->db->table('clinic_medicines')->where('id', $this->medicineId)->update(['total_stock'=>10]);
        }
    }

    public function testOffsettingBatchLedgerDriftBlocksAllNewWritesWithoutMutation(): void
    {
        $first = $this->receipt(10, 'A');
        $second = $this->receipt(10, 'B');
        $dispensed = $this->dispense(2);
        $firstId = (int) $first['movements'][0]['batch_id'];
        $secondId = (int) $second['movements'][0]['batch_id'];
        // Physical total, medicine cache and aggregate ledger still all equal 18.
        $this->db->table('clinic_medicine_batches')->where('id', $firstId)->update(['remaining_quantity'=>9]);
        $this->db->table('clinic_medicine_batches')->where('id', $secondId)->update(['remaining_quantity'=>9]);
        try {
            $this->assertBalanced(18);
            $before = $this->inventory->details($this->medicineId);
            $this->rejected(fn () => $this->receipt(1, key: 'drift-receipt'), 409);
            $this->rejected(fn () => $this->dispense(1, 'drift-dispense'), 409);
            $this->rejected(fn () => $this->inventory->adjust($firstId, ['quantity'=>1, 'reason'=>'count_correction', 'note'=>'Removal'], $this->actorId, 'drift-adjust'), 409);
            $this->rejected(fn () => $this->inventory->setBatchStatus($firstId, 'quarantined', 'Inspection', $this->actorId, 'drift-quarantine'), 409);
            $this->rejected(fn () => $this->inventory->reverse((int) $dispensed['operation']['id'], 'restore_unissued', 'Never issued', $this->actorId, 'drift-reverse'), 409);
            $this->rejected(fn () => $this->inventory->saveMedicine($this->medicineId, ['name'=>'No change allowed', 'version'=>$before['medicine']['version']], $this->actorId), 409);
            $this->assertSame($before, $this->inventory->details($this->medicineId));
        } finally {
            $this->db->table('clinic_medicine_batches')->where('id', $firstId)->update(['remaining_quantity'=>8]);
            $this->db->table('clinic_medicine_batches')->where('id', $secondId)->update(['remaining_quantity'=>10]);
        }
        $this->assertBalanced(18);
    }

    public function testUnbackedLegacyStockIsNotGivenAFabricatedOpeningBalance(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('clinic_medicine_batches')->insert([
            'tenant_id'=>$this->tenantId, 'medicine_id'=>$this->medicineId, 'batch_number'=>'UNBACKED',
            'expiration_date'=>date('Y-m-d', strtotime('+1 year')), 'initial_quantity'=>4,
            'remaining_quantity'=>4, 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now,
        ]);
        $batchId = (int) $this->db->insertID();
        $this->db->table('clinic_medicines')->where('id', $this->medicineId)->update(['total_stock'=>4]);
        try {
            $before = $this->inventory->details($this->medicineId);
            $this->rejected(fn () => $this->receipt(1, 'UNBACKED', key: 'unbacked-receipt'), 409);
            $this->rejected(fn () => $this->dispense(1, 'unbacked-dispense'), 409);
            $this->assertSame($before, $this->inventory->details($this->medicineId));
            $this->assertSame([], $before['movements']);
            $this->assertSame([], $before['operations']);
        } finally {
            // Restore only the deliberately corrupted fixture; do not invent or delete ledger history.
            $this->db->table('clinic_medicine_batches')->where('id', $batchId)->update(['remaining_quantity'=>0]);
            $this->db->table('clinic_medicines')->where('id', $this->medicineId)->update(['total_stock'=>0]);
        }
    }

    public function testDatabaseHistoryIsImmutableAndDowngradeRefusesHistory(): void
    {
        $receipt = $this->receipt();
        $this->dispense();
        foreach (['inventory_operations','stock_movements','clinic_dispensations'] as $table) {
            foreach (['UPDATE','DELETE'] as $verb) {
                try {
                    $sql = $verb === 'UPDATE' ? 'UPDATE ' . $this->db->protectIdentifiers($this->db->prefixTable($table)) . ' SET created_at=created_at WHERE tenant_id=?' : 'DELETE FROM ' . $this->db->protectIdentifiers($this->db->prefixTable($table)) . ' WHERE tenant_id=?';
                    $result = $this->db->query($sql, [$this->tenantId]);
                    $this->assertFalse($result);
                } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
                    $this->assertStringContainsString('append-only', $e->getMessage());
                }
            }
        }
        require_once APPPATH . 'Database/Migrations/2026-10-15-000002_AddClinicInventoryLedger.php';
        $migration = new \App\Database\Migrations\AddClinicInventoryLedger(\Config\Database::forge('tests'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live inventory history');
        $migration->down();
    }

    public function testHistoricalParentsCannotBeDeletedAndCompositeBatchReferenceIsEnforced(): void
    {
        $receipt = $this->receipt();
        $this->dispense();
        $batchId = (int) $receipt['movements'][0]['batch_id'];
        foreach (['clinic_medicines'=>$this->medicineId, 'clinic_medicine_batches'=>$batchId, 'users'=>$this->actorId, 'people'=>$this->patientId, 'clinic_encounters'=>$this->encounterId] as $table => $id) {
            try {
                $result = $this->db->table($table)->where('id', $id)->delete();
                $this->assertFalse($result, 'Historical parent deletion must be rejected.');
            } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
                $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
            }
        }
        try {
            $result = $this->db->table('clinic_dispensations')->insert([
                'tenant_id'=>1, 'encounter_id'=>$this->encounterId, 'medicine_id'=>$this->medicineId,
                'batch_id'=>$batchId, 'quantity'=>1, 'dispensed_by_user_id'=>$this->actorId,
                'created_at'=>date('Y-m-d H:i:s'),
            ]);
            $this->assertFalse($result, 'Cross-tenant allocation must be rejected by the database.');
        } catch (\CodeIgniter\Database\Exceptions\DatabaseException $e) {
            $this->assertStringContainsString('foreign key constraint fails', strtolower($e->getMessage()));
        }
        $this->assertBalanced(7);
    }

    public function testArchiveAndNestedTransactionsCannotBypassInventory(): void
    {
        $this->receipt();
        $medicine = $this->inventory->details($this->medicineId)['medicine'];
        $this->inventory->saveMedicine($this->medicineId, ['version'=>$medicine['version'], 'is_active'=>'0'], $this->actorId);
        $this->rejected(fn () => $this->dispense(), 409);
        $this->rejected(fn () => $this->receipt(1), 409);
        $this->db->transBegin();
        try {
            $this->rejected(fn () => $this->dispense(), 0);
            $this->rejected(fn () => (new EncounterService())->completeEncounter($this->encounterId, 'discharged'), 0);
        } finally {
            $this->db->transRollback();
        }
        $this->assertBalanced(10);
    }
}
