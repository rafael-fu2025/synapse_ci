<?php

declare(strict_types=1);

namespace Tests\Database;

use App\Libraries\TablePage;
use App\Services\Clinic\PatientDirectoryService;
use App\Services\CurrentTenant;
use Tests\Support\InventoryDatabaseTestCase;

/** Run only after the parent runner prepares its isolated inventory schema. */
final class DirectoryPaginationDatabaseTest extends InventoryDatabaseTestCase
{
    protected $migrate = false;

    protected function setUp(): void
    {
        if ((int) (config('Database')->tests['port'] ?? 0) !== 33307) {
            $this->markTestSkipped('Requires the parent-owned isolated inventory harness on port 33307.');
        }
        parent::setUp();
    }

    private function otherTenant(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug'=>uniqid('paging_'), 'name'=>'Other pagination tenant', 'is_active'=>1, 'created_at'=>$now, 'updated_at'=>$now]);
        return (int) $this->db->insertID();
    }

    public function testPatientsAreCompleteDistinctTenantScopedAndMinimalAcrossPages(): void
    {
        $now = date('Y-m-d H:i:s');
        $other = $this->otherTenant();
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $this->db->table('people')->insert(['tenant_id'=>$this->tenantId, 'kind'=>'student', 'mis_id'=>'00-ID ' . $i, 'first_name'=>'Paging', 'last_name'=>'Patient', 'created_at'=>$now, 'updated_at'=>$now]);
            $ids[] = (int) $this->db->insertID();
        }
        $this->db->table('people')->insert(['tenant_id'=>$other, 'kind'=>'student', 'mis_id'=>'00-ID 0', 'first_name'=>'Paging', 'last_name'=>'Patient', 'created_at'=>$now, 'updated_at'=>$now]);
        $this->db->table('people')->insert(['tenant_id'=>$this->tenantId, 'kind'=>'student', 'mis_id'=>'deleted', 'first_name'=>'Paging', 'last_name'=>'Patient', 'deleted_at'=>$now, 'created_at'=>$now, 'updated_at'=>$now]);
        foreach ([$this->tenantId, $other] as $tenant) {
            $this->db->table('student_profiles')->insert(['tenant_id'=>$tenant, 'person_id'=>$ids[0], 'program'=>$tenant === $other ? 'SECRET' : 'BSIT', 'year_level'=>2, 'created_at'=>$now, 'updated_at'=>$now]);
            $this->db->table('employee_profiles')->insert(['tenant_id'=>$tenant, 'person_id'=>$ids[0], 'department'=>$tenant === $other ? 'SECRET' : 'Clinic', 'created_at'=>$now, 'updated_at'=>$now]);
            $this->db->table('health_profiles')->insert(['tenant_id'=>$tenant, 'person_id'=>$ids[0], 'blood_type'=>$tenant === $other ? 'AB-' : 'O+', 'created_at'=>$now, 'updated_at'=>$now]);
        }
        $directory = new PatientDirectoryService($this->db);
        $seen = [];
        foreach ([1, 2, 3] as $number) {
            $result = $directory->paginatePatients(TablePage::fromQuery(['q'=>'Paging Patient', 'kind'=>'student', 'page'=>(string) $number]));
            $this->assertSame(61, $result['pagination']['total']);
            $this->assertSame(60, $result['pagination']['filtered']);
            foreach ($result['patients'] as $patient) {
                foreach (['date_of_birth','birth_date','address','phone','tenant_id'] as $field) {
                    $this->assertArrayNotHasKey($field, $patient);
                }
                $this->assertNotContains('SECRET', $patient);
            }
            $seen = array_merge($seen, array_map('intval', array_column($result['patients'], 'id')));
        }
        $this->assertSame($ids, $seen);
        $this->assertCount(60, array_unique($seen));
        $first = $directory->paginatePatients(TablePage::fromQuery(['q'=>'00-ID 0']));
        $this->assertSame('00-ID 0', $first['patients'][0]['mis_id']);
        $this->assertSame('BSIT-2', $first['patients'][0]['program_or_dept']);
        $this->assertSame('O+', $first['patients'][0]['blood_type']);
        $last = $directory->paginatePatients(TablePage::fromQuery(['q'=>'Paging Patient', 'page'=>'1000000']));
        $this->assertSame(3, $last['pagination']['page']);
        $this->assertSame(51, $last['pagination']['from']);
        $this->assertSame(60, $last['pagination']['to']);
        $empty = $directory->paginatePatients(TablePage::fromQuery(['kind'=>'guest', 'page'=>'1000000']));
        $this->assertSame(['page'=>1, 'per_page'=>25, 'total'=>61, 'filtered'=>0, 'pages'=>1, 'from'=>0, 'to'=>0], $empty['pagination']);
    }

    public function testBoundSearchPreservesApostrophesAndTreatsLikeWildcardsLiterally(): void
    {
        $medicine = $this->inventory->saveMedicine(null, ['name'=>"O'Neil 50%_!", 'category'=>'Test',
            'dosage_form'=>'Tablet', 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>5], $this->actorId);
        $this->inventory->saveMedicine(null, ['name'=>"O'Neil 50XX", 'category'=>'Test',
            'dosage_form'=>'Tablet', 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>5], $this->actorId);
        $literal = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>"O'Neil 50%_!"]));
        $this->assertSame(1, $literal['pagination']['filtered']);
        $this->assertSame((int) $medicine['id'], (int) $literal['medicines'][0]['id']);
        $prefix = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>"O'Neil"]));
        $this->assertSame(2, $prefix['pagination']['filtered']);
        $injection = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>"' OR 1=1 --"]));
        $this->assertSame(0, $injection['pagination']['filtered']);

        $this->db->table('people')->where('id', $this->patientId)->update(['mis_id'=>"00-O'Neil %_!"]);
        $directory = new PatientDirectoryService($this->db);
        $person = $directory->paginatePatients(TablePage::fromQuery(['q'=>"00-O'Neil %_!"]));
        $this->assertSame(1, $person['pagination']['filtered']);
        $this->assertSame("00-O'Neil %_!", $person['patients'][0]['mis_id']);
        $this->assertSame(0, $directory->paginatePatients(TablePage::fromQuery(['q'=>"' OR 1=1 --"]))['pagination']['filtered']);
    }

    public function testInventoryStatsCoverAllFilteredPagesWithoutBatchMultiplicationOrTenantLeaks(): void
    {
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $medicine = $this->inventory->saveMedicine(null, ['name'=>'Paging medicine', 'generic_name'=>'Paging generic', 'category'=>'Test', 'dosage_form'=>'Tablet', 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>5], $this->actorId);
            $ids[] = (int) $medicine['id'];
        }
        foreach ([$ids[0], $ids[59]] as $id) {
            $this->inventory->receive($id, ['batch_number'=>'TODAY', 'quantity'=>2, 'expiration_date'=>date('Y-m-d')], $this->actorId, uniqid('paging-'));
            $receipt = $this->inventory->receive($id, ['batch_number'=>'OVERLAP', 'quantity'=>3, 'expiration_date'=>date('Y-m-d', strtotime('+90 days'))], $this->actorId, uniqid('paging-'));
            $batch = (int) $receipt['movements'][0]['batch_id'];
            $this->inventory->setBatchStatus($batch, 'quarantined', 'Test recall', $this->actorId, uniqid('paging-'));
            $this->db->table('clinic_medicine_batches')->where('id', $batch)->update(['expiration_date'=>date('Y-m-d', strtotime('-1 day'))]);
        }
        $medicine = $this->inventory->details($ids[59])['medicine'];
        $this->inventory->saveMedicine($ids[59], ['version'=>$medicine['version'], 'is_active'=>'0'], $this->actorId);
        $other = $this->otherTenant();
        $otherActor = $this->user('superadmin', $other);
        CurrentTenant::set($other);
        try {
            $this->inventory->saveMedicine(null, ['name'=>'Paging medicine', 'category'=>'Test', 'dosage_form'=>'Tablet', 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>5], $otherActor);
        } finally {
            CurrentTenant::set($this->tenantId);
        }
        foreach (['all','active','archived','low_stock'] as $status) {
            $expected = $this->inventory->listMedicines('Paging', $status);
            $expectedCounts = ['low'=>0, 'expired'=>0, 'quarantined'=>0, 'near'=>0];
            foreach ($expected as $row) {
                $expectedCounts['low'] += $row['low_stock'] ? 1 : 0;
                $expectedCounts['expired'] += $row['expired_stock'] > 0 ? 1 : 0;
                $expectedCounts['quarantined'] += $row['quarantined_stock'] > 0 ? 1 : 0;
                $expectedCounts['near'] += $row['near_expiry_stock'] > 0 ? 1 : 0;
            }
            $result = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>'Paging', 'status'=>$status]));
            $this->assertSame(61, $result['pagination']['total']);
            $this->assertSame(count($expected), $result['pagination']['filtered']);
            $this->assertSame($expectedCounts, $result['counts']);
            $this->assertSame(array_slice($expected, 0, 25), $result['medicines']);
        }
        $all = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>'Paging']));
        $this->assertSame(['low'=>60, 'expired'=>2, 'quarantined'=>2, 'near'=>1], $all['counts']);
        $last = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>'Paging', 'page'=>'1000000']));
        $this->assertSame(3, $last['pagination']['page']);
        $this->assertSame(array_slice($ids, 50), array_map('intval', array_column($last['medicines'], 'id')));
        $this->assertSame($all['counts'], $last['counts']);
        $empty = $this->inventory->paginateMedicines(TablePage::fromQuery(['q'=>'not-a-medicine', 'page'=>'1000000']));
        $this->assertSame(['page'=>1, 'per_page'=>25, 'total'=>61, 'filtered'=>0, 'pages'=>1, 'from'=>0, 'to'=>0], $empty['pagination']);
        $this->assertSame(['low'=>0, 'expired'=>0, 'quarantined'=>0, 'near'=>0], $empty['counts']);
    }
}
