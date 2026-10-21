<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\TablePage;
use App\Services\Clinic\InventoryService;
use App\Services\Clinic\PatientDirectoryService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;

final class DirectoryPaginationQueryTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testInventoryUsesFilteredSqlStatsBeforeClampedLimitAndPreservesSummaryPolicy(): void
    {
        CurrentTenant::set(42);
        $db = $this->createMock(BaseConnection::class);
        $builder = $this->createMock(BaseBuilder::class);
        $builder->expects($this->once())->method('where')->with('tenant_id', 42)->willReturnSelf();
        $builder->expects($this->once())->method('countAllResults')->willReturn(200);
        $db->expects($this->once())->method('table')->with('clinic_medicines')->willReturn($builder);
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $db->method('escape')->willReturnCallback(static fn ($value) => "'" . $value . "'");
        $db->expects($this->never())->method('escapeLikeString');
        $stats = $this->createMock(BaseResult::class);
        $stats->method('getRowArray')->willReturn(['filtered'=>'26', 'low'=>'26', 'expired'=>'20', 'quarantined'=>'11', 'near'=>'7']);
        $rows = $this->createMock(BaseResult::class);
        $rows->method('getResultArray')->willReturn([
            ['id'=>'9', 'is_active'=>'0', 'minimum_stock_level'=>'5', 'total_stock'=>'999', 'batch_total_stock'=>'30', 'usable_stock'=>'0', 'expired_stock'=>'12', 'quarantined_stock'=>'7', 'near_expiry_stock'=>'0'],
        ]);
        $calls = 0;
        $db->expects($this->exactly(2))->method('query')->willReturnCallback(function ($sql, $binds) use (&$calls, $stats, $rows) {
            $this->assertSame([42, 42, "%O'Neil 50!%!_!!%", "%O'Neil 50!%!_!!%"], $binds);
            $this->assertStringContainsString('GROUP BY tenant_id, medicine_id', $sql);
            $this->assertStringContainsString('b.tenant_id=m.tenant_id AND b.medicine_id=m.id', $sql);
            $this->assertStringContainsString('usable_stock <= minimum_stock_level', $sql);
            $this->assertStringContainsString("expiration_date >= '" . date('Y-m-d') . "'", $sql);
            $this->assertStringContainsString("expiration_date <= '" . date('Y-m-d', strtotime('+90 days')) . "'", $sql);
            $this->assertStringContainsString('CASE WHEN m.is_active=1', $sql);
            if ($calls++ === 0) {
                $this->assertStringStartsWith('SELECT COUNT(*) AS filtered', $sql);
                $this->assertStringNotContainsString('LIMIT', $sql);
                return $stats;
            }
            $this->assertStringEndsWith('ORDER BY name ASC, id ASC LIMIT 25 OFFSET 25', $sql);
            return $rows;
        });
        $result = (new InventoryService($db))->paginateMedicines(TablePage::fromQuery(['q'=>"O'Neil 50%_!", 'status'=>'low_stock', 'page'=>'1000000']));
        $this->assertSame(['page'=>2, 'per_page'=>25, 'total'=>200, 'filtered'=>26, 'pages'=>2, 'from'=>26, 'to'=>26], $result['pagination']);
        $this->assertSame(['low'=>26, 'expired'=>20, 'quarantined'=>11, 'near'=>7], $result['counts']);
        $this->assertSame(30, $result['medicines'][0]['total_stock']);
        $this->assertSame(12, $result['medicines'][0]['expired_stock']);
        $this->assertSame(7, $result['medicines'][0]['quarantined_stock']);
        $this->assertSame(0, $result['medicines'][0]['usable_stock']);
        $this->assertTrue($result['medicines'][0]['low_stock']);
        $this->assertArrayNotHasKey('batch_total_stock', $result['medicines'][0]);
    }

    public function testPatientsCountsDoNotJoinProfilesAndPageUsesTenantJoinsMinimalColumnsAndStableOrder(): void
    {
        CurrentTenant::set(73);
        $db = $this->createMock(BaseConnection::class);
        $builder = $this->createMock(BaseBuilder::class);
        $db->expects($this->once())->method('table')->with('people p')->willReturn($builder);
        $builder->expects($this->exactly(3))->method('where')->willReturnCallback(function ($key, $value) use ($builder) {
            $this->assertContains([$key, $value], [['p.tenant_id',73], ['p.deleted_at',null], ['p.kind','student']]);
            return $builder;
        });
        foreach (['groupStart', 'orGroupStart', 'groupEnd', 'orLike'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->expects($this->exactly(3))->method('like')->willReturnCallback(function ($field, $term) use ($builder) {
            $this->assertContains([$field, $term], [['p.mis_id','First Last'], ['p.first_name','First'], ['p.first_name','Last']]);
            return $builder;
        });
        // Clones inherit the callback, so count calls share one external counter.
        $counts = 0;
        $builder->method('countAllResults')->willReturnCallback(static function () use (&$counts) { return $counts++ === 0 ? 100 : 26; });
        $builder->expects($this->once())->method('select')->willReturnCallback(function ($fields) use ($builder, &$counts) {
            $this->assertSame(2, $counts);
            foreach (['p.*', 'date_of_birth', 'address', 'phone'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $fields);
            }
            return $builder;
        });
        $builder->expects($this->exactly(3))->method('join')->willReturnCallback(function ($table, $on, $type) use ($builder) {
            $alias = explode(' ', $table)[1];
            $this->assertSame($alias . '.person_id=p.id AND ' . $alias . '.tenant_id=p.tenant_id', $on);
            $this->assertSame('left', $type);
            return $builder;
        });
        $orders = [];
        $builder->method('orderBy')->willReturnCallback(static function ($column) use (&$orders, $builder) { $orders[] = $column; return $builder; });
        $rows = $this->createMock(BaseResult::class);
        $rows->method('getResultArray')->willReturn([['id'=>'8', 'program'=>'BSIT', 'year_level'=>'2', 'emp_dept'=>null]]);
        $builder->expects($this->once())->method('get')->with(25, 25)->willReturn($rows);
        $result = (new PatientDirectoryService($db))->paginatePatients(TablePage::fromQuery(['q'=>'First Last', 'kind'=>'student', 'page'=>'999']));
        $this->assertSame(['p.last_name','p.first_name','p.id'], $orders);
        $this->assertSame(100, $result['pagination']['total']);
        $this->assertSame(26, $result['pagination']['filtered']);
        $this->assertSame(2, $result['pagination']['page']);
        $this->assertSame('BSIT-2', $result['patients'][0]['program_or_dept']);
        $this->assertSame(['q'=>'First Last', 'kind'=>'student', 'per_page'=>25], $result['filters']);
    }
}
