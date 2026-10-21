<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\InventoryService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;

/** Query count and summary mapping without a database connection or migrations. */
final class InventorySummaryTest extends CIUnitTestCase
{
    private function service(array $medicines, array $totals): InventoryService
    {
        $db = $this->createMock(BaseConnection::class);
        $catalog = $this->createMock(BaseBuilder::class);
        $batches = $this->createMock(BaseBuilder::class);
        $catalogRows = $this->createMock(BaseResult::class);
        $batchRows = $this->createMock(BaseResult::class);
        $catalogRows->method('getResultArray')->willReturn($medicines);
        $batchRows->method('getResultArray')->willReturn($totals);
        $catalog->expects($this->once())->method('where')->with('tenant_id', CurrentTenant::id())->willReturnSelf();
        $catalog->method('orderBy')->willReturnSelf();
        $catalog->expects($this->once())->method('get')->willReturn($catalogRows);
        $batches->method('select')->willReturnSelf();
        $batches->expects($medicines === [] ? $this->never() : $this->once())->method('where')->with('tenant_id', CurrentTenant::id())->willReturnSelf();
        $batches->method('groupBy')->willReturnSelf();
        $batches->expects($medicines === [] ? $this->never() : $this->once())->method('get')->willReturn($batchRows);
        $db->method('escape')->willReturnCallback(static fn ($value) => "'" . $value . "'");
        $db->expects($this->exactly($medicines === [] ? 1 : 2))->method('table')->willReturnCallback(static fn ($table) => match ($table) {
            'clinic_medicines' => $catalog,
            'clinic_medicine_batches' => $batches,
        });
        return new InventoryService($db);
    }

    private function medicines(): array
    {
        return [
            ['id'=>'1', 'name'=>'Active', 'is_active'=>'1', 'minimum_stock_level'=>'5', 'total_stock'=>'999'],
            ['id'=>'2', 'name'=>'Archived', 'is_active'=>'0', 'minimum_stock_level'=>'5', 'total_stock'=>'999'],
            ['id'=>'3', 'name'=>'No batches', 'is_active'=>'1', 'minimum_stock_level'=>'0', 'total_stock'=>'0'],
        ];
    }

    private function totals(): array
    {
        return [
            ['medicine_id'=>'1', 'total_stock'=>'21', 'usable_stock'=>'10', 'near_expiry_stock'=>'10', 'expired_stock'=>'11', 'quarantined_stock'=>'7'],
            ['medicine_id'=>'2', 'total_stock'=>'21', 'usable_stock'=>'10', 'near_expiry_stock'=>'10', 'expired_stock'=>'11', 'quarantined_stock'=>'7'],
        ];
    }

    public function testListUsesTwoQueriesAndPreservesPhysicalOverlapForArchivedMedicines(): void
    {
        $rows = $this->service($this->medicines(), $this->totals())->listMedicines();
        $this->assertCount(3, $rows);
        $this->assertSame(21, $rows[0]['total_stock']);
        $this->assertSame(10, $rows[0]['usable_stock']);
        $this->assertSame(10, $rows[0]['near_expiry_stock']);
        $this->assertFalse($rows[0]['low_stock']);
        $this->assertSame(21, $rows[1]['total_stock']);
        $this->assertSame(11, $rows[1]['expired_stock']);
        $this->assertSame(7, $rows[1]['quarantined_stock']);
        $this->assertSame(0, $rows[1]['usable_stock']);
        $this->assertSame(0, $rows[1]['near_expiry_stock']);
        $this->assertTrue($rows[1]['low_stock']);
        foreach (['total_stock','usable_stock','near_expiry_stock','expired_stock','quarantined_stock'] as $field) {
            $this->assertSame(0, $rows[2][$field]);
        }
        $this->assertTrue($rows[2]['low_stock']);
    }

    public function testLowStockFilterUsesDispensableStockAfterArchiving(): void
    {
        $rows = $this->service($this->medicines(), $this->totals())->listMedicines('', 'low_stock');
        $this->assertSame(['2','3'], array_column($rows, 'id'));
    }

    public function testEmptyCatalogDoesNotQueryBatches(): void
    {
        $this->assertSame([], $this->service([], [])->listMedicines());
    }
}
