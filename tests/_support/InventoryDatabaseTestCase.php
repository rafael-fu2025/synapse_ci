<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Clinic\InventoryService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

abstract class InventoryDatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected InventoryService $inventory;
    protected int $tenantId;
    protected int $actorId;
    protected int $medicineId;
    protected int $encounterId;
    protected int $patientId;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        parent::setUp();
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug'=>uniqid('inventory_'), 'name'=>'Inventory test', 'is_active'=>1, 'created_at'=>$now, 'updated_at'=>$now]);
        $this->tenantId = (int) $this->db->insertID();
        CurrentTenant::set($this->tenantId);
        $this->actorId = $this->user('superadmin');
        $this->db->table('people')->insert(['tenant_id'=>$this->tenantId, 'kind'=>'student', 'mis_id'=>uniqid('inventory_'), 'first_name'=>'Ledger', 'last_name'=>'Patient', 'created_at'=>$now, 'updated_at'=>$now]);
        $this->patientId = (int) $this->db->insertID();
        $this->db->table('clinic_encounters')->insert(['tenant_id'=>$this->tenantId, 'person_id'=>$this->patientId, 'attending_user_id'=>$this->actorId, 'status'=>'in_progress', 'started_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now]);
        $this->encounterId = (int) $this->db->insertID();
        $this->inventory = new InventoryService($this->db);
        $medicine = $this->inventory->saveMedicine(null, ['name'=>'Ledger medicine', 'generic_name'=>'Generic', 'strength'=>'500mg', 'category'=>'Analgesic', 'dosage_form'=>'Tablet', 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>5], $this->actorId);
        $this->medicineId = (int) $medicine['id'];
    }

    protected function tearDown(): void
    {
        CurrentTenant::reset();
        // Deliberately retain history in this isolated test DB; never disable append-only triggers to clean fixtures.
        parent::tearDown();
    }

    protected function user(string $role, ?int $tenantId = null): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('users')->insert(['tenant_id'=>$tenantId ?? $this->tenantId, 'username'=>uniqid('inventory_'), 'role'=>$role, 'status'=>'active', 'created_at'=>$now, 'updated_at'=>$now]);
        return (int) $this->db->insertID();
    }

    protected function receipt(int $quantity = 10, string $lot = 'LOT-A', string $expiry = '+1 year', ?string $key = null): array
    {
        return $this->inventory->receive($this->medicineId, ['batch_number'=>$lot, 'expiration_date'=>date('Y-m-d', strtotime($expiry)), 'quantity'=>$quantity], $this->actorId, $key ?? uniqid('receipt-'));
    }

    protected function dispense(int $quantity = 3, ?string $key = null): array
    {
        return $this->inventory->dispense($this->encounterId, $this->medicineId, $quantity, 'After meals', $this->actorId, $key ?? uniqid('dispense-'));
    }

    protected function rejected(callable $work, int $code): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
            return;
        }
        $this->fail('Expected operation rejection.');
    }

    protected function assertBalanced(int $expected): void
    {
        $medicine = $this->db->table('clinic_medicines')->where('id', $this->medicineId)->get()->getRowArray();
        $physical = $this->db->table('clinic_medicine_batches')->selectSum('remaining_quantity', 'qty')->where('medicine_id', $this->medicineId)->get()->getRow()->qty;
        $ledger = $this->db->table('stock_movements')->selectSum('delta', 'qty')->where('medicine_id', $this->medicineId)->get()->getRow()->qty;
        $this->assertSame($expected, (int) $medicine['total_stock']);
        $this->assertSame($expected, (int) $physical);
        $this->assertSame($expected, (int) $ledger);
    }
}
