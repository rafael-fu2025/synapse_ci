<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ClinicDispensationModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Both chart read paths are exercised without a database or schema changes. */
final class ClinicDispensationReadinessTest extends CIUnitTestCase
{
    public static function schemas(): iterable
    {
        yield 'legacy' => [false];
        yield 'ledger' => [true];
    }

    #[DataProvider('schemas')]
    public function testChartHistoryRetainsTenantEncounterAndMedicineBatchScope(bool $ledger): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->once())->method('fieldExists')->with('operation_id', 'clinic_dispensations')->willReturn($ledger);
        $db->expects($this->never())->method('query');
        $builder = $this->createMock(BaseBuilder::class);
        $db->method('table')->with('clinic_dispensations')->willReturn($builder);
        $builder->expects($this->exactly(2))->method('where')->willReturnCallback(function ($field, $value) use ($builder) {
            $this->assertContains([$field, $value], [
                ['clinic_dispensations.tenant_id', CurrentTenant::id()],
                ['clinic_dispensations.encounter_id', 42],
            ]);
            return $builder;
        });
        $builder->expects($this->once())->method('select')->with($this->callback(function (string $select) use ($ledger): bool {
            $this->assertStringContainsString('AS medicine_name', $select);
            $this->assertStringContainsString('AS unit_of_measure', $select);
            if ($ledger) {
                $this->assertStringContainsString('COALESCE(clinic_dispensations.snapshot_name,m.name)', $select);
                $this->assertStringContainsString('correction.id AS reversed_by_operation_id', $select);
            } else {
                foreach (['NULL AS operation_id', '1 AS snapshot_reconstructed', 'NULL AS reversed_by_operation_id',
                    'NULL AS correction_mode', 'm.unit_of_measure AS unit_of_measure'] as $alias) {
                    $this->assertStringContainsString($alias, $select);
                }
                $this->assertStringNotContainsString('clinic_dispensations.snapshot_', $select);
                $this->assertStringNotContainsString('correction.', $select);
            }
            return true;
        }), false)->willReturnSelf();
        $builder->expects($this->exactly($ledger ? 3 : 2))->method('join')->willReturnCallback(function ($table, $condition) use ($builder, $ledger) {
            $this->assertStringContainsString('tenant_id=clinic_dispensations.tenant_id', $condition);
            if ($table === 'clinic_medicine_batches b') {
                $this->assertStringContainsString('b.medicine_id=clinic_dispensations.medicine_id', $condition);
            } elseif ($table === 'inventory_operations correction') {
                $this->assertTrue($ledger);
                $this->assertStringContainsString('correction.reversal_of=clinic_dispensations.operation_id', $condition);
            } else {
                $this->assertSame('clinic_medicines m', $table);
            }
            return $builder;
        });
        $builder->expects($this->once())->method('orderBy')->with('clinic_dispensations.id', 'ASC')->willReturnSelf();
        $builder->method('limit')->willReturnSelf();
        $rows = $this->createMock(BaseResult::class);
        $expected = [['id' => 1, 'medicine_name' => 'Legacy medicine', 'operation_id' => null, 'snapshot_reconstructed' => 1]];
        $rows->expects($this->once())->method('getResult')->with('array')->willReturn($expected);
        $builder->expects($this->once())->method('get')->willReturn($rows);
        $this->assertSame($expected, (new ClinicDispensationModel($db))->getForEncounter(42));
    }
}
