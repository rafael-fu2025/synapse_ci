<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\Support\InventoryDatabaseTestCase;

/** Actual independent database connections/processes, not sequential mocked transactions. */
final class InventoryConcurrencyTest extends InventoryDatabaseTestCase
{
    private function race(array $first, array $second): array
    {
        $workers = [];
        try {
            foreach ([$first, $second] as $job) {
                $process = proc_open([PHP_BINARY, ROOTPATH . 'tests/_support/inventory-race-worker.php'], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                stream_set_timeout($pipes[2], 20);
                fwrite($pipes[0], json_encode($job + ['database'=>config('Database')->tests, 'tenant_id'=>$this->tenantId, 'medicine_id'=>$this->medicineId, 'encounter_id'=>$this->encounterId, 'actor_id'=>$this->actorId], JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
                $ready = fgets($pipes[1]);
                $this->assertSame("READY\n", $ready, 'Worker did not reach its verified-test-database barrier.');
            }
            foreach ($workers as [$process, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fflush($pipes[0]);
                fclose($pipes[0]);
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $line = fgets($pipes[1]);
                $this->assertNotFalse($line, 'Worker timed out or exited without a result.');
                $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
            return $results;
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
        }
    }

    public function testSimultaneousCatalogCreatesNeverLeaveADuplicateMedicine(): void
    {
        $data = ['name'=>'Concurrent catalog', 'generic_name'=>null, 'category'=>'Analgesic',
            'dosage_form'=>'Tablet', 'strength'=>null, 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>0];
        $job = ['operation'=>'catalog', 'key'=>'same-catalog', 'data'=>$data];
        $results = $this->race($job, $job);
        $successful = array_values(array_filter($results, fn ($result) => $result['code'] === 200));
        $this->assertNotEmpty($successful);
        $id = (int) $successful[0]['result']['id'];
        foreach ($results as $result) {
            $this->assertContains($result['code'], [200,409]);
            if ($result['code'] === 200) {
                $this->assertSame($id, (int) $result['result']['id']);
            }
        }
        $retry = $this->inventory->saveMedicine(null, $data, $this->actorId, 'same-catalog');
        $this->assertSame($id, (int) $retry['id']);
        $this->assertSame(2, $this->db->table('clinic_medicines')->where('tenant_id', $this->tenantId)->countAllResults());
        $this->assertSame(1, $this->db->table('inventory_operations')->where('tenant_id', $this->tenantId)->where('type', 'catalog')->countAllResults());
        $this->assertSame(0, $this->db->table('stock_movements')->where('tenant_id', $this->tenantId)->countAllResults());
    }

    public function testSimultaneousIdenticalReceiptCreatesExactlyOneOperation(): void
    {
        $job = ['operation'=>'receive', 'key'=>'same', 'data'=>['batch_number'=>'RACE', 'expiration_date'=>date('Y-m-d', strtotime('+1 year')), 'quantity'=>'10']];
        $results = $this->race($job, $job);
        $this->assertSame([200,200], array_column($results, 'code'));
        $this->assertSame((int) $results[0]['result']['operation']['id'], (int) $results[1]['result']['operation']['id']);
        $this->assertSame(1, array_sum(array_map(fn ($row) => (int) $row['result']['replayed'], $results)));
        $this->assertBalanced(10);
    }

    public function testCompetingDispensationsNeverOversell(): void
    {
        $this->receipt(10);
        $results = $this->race(['operation'=>'dispense','quantity'=>7,'key'=>'first'], ['operation'=>'dispense','quantity'=>7,'key'=>'second']);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200,409], $codes);
        $this->assertBalanced(3);
    }

    public function testSimultaneousDispenseReplayIsAtomic(): void
    {
        $this->receipt(10);
        $job = ['operation'=>'dispense','quantity'=>7,'key'=>'same-dispense'];
        $results = $this->race($job, $job);
        $this->assertSame([200,200], array_column($results, 'code'));
        $this->assertSame((int) $results[0]['result']['operation']['id'], (int) $results[1]['result']['operation']['id']);
        $this->assertBalanced(3);
    }

    public function testCompletionAndDispensingUseOneEncounterMutex(): void
    {
        $this->receipt(10);
        $results = $this->race(['operation'=>'complete'], ['operation'=>'dispense','quantity'=>3,'key'=>'dispense']);
        $this->assertSame(200, $results[0]['code']);
        $this->assertContains($results[1]['code'], [200,409]);
        $this->assertBalanced($results[1]['code'] === 200 ? 7 : 10);
        $this->assertSame('completed', $this->db->table('clinic_encounters')->where('id', $this->encounterId)->get()->getRow()->status);
    }

    public function testCompetingReversalsRestoreOnlyOnce(): void
    {
        $this->receipt(10);
        $dispensed = $this->dispense(7);
        $id = (int) $dispensed['operation']['id'];
        $results = $this->race(['operation'=>'reverse','operation_id'=>$id,'key'=>'reverse-a'], ['operation'=>'reverse','operation_id'=>$id,'key'=>'reverse-b']);
        $codes = array_column($results, 'code');
        sort($codes);
        $this->assertSame([200,409], $codes);
        $this->assertBalanced(10);
    }
}
