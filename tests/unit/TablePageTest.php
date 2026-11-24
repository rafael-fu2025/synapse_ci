<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Libraries\TablePage;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class TablePageTest extends CIUnitTestCase
{
    public function testDefaultsAndEmptyRange(): void
    {
        $page = TablePage::fromQuery([]);
        $this->assertSame('', $page->q);
        $this->assertSame('all', $page->kind);
        $this->assertSame('all', $page->status);
        $this->assertFalse($page->fragment);
        $this->assertSame(['page'=>1, 'per_page'=>25, 'total'=>0, 'filtered'=>0, 'pages'=>1, 'from'=>0, 'to'=>0], $page->metadata(0, 0));
    }

    public function testTrimmingUnicodeLimitsAndClamping(): void
    {
        $page = TablePage::fromQuery(['q'=>'  First Last  ', 'page'=>'1000000', 'per_page'=>'50', 'kind'=>'guest', 'status'=>'low_stock', 'fragment'=>'1']);
        $this->assertSame('First Last', $page->q);
        $this->assertSame(1000000, $page->page);
        $this->assertSame(50, $page->perPage);
        $this->assertTrue($page->fragment);
        $this->assertSame(['page'=>3, 'per_page'=>50, 'total'=>300, 'filtered'=>101, 'pages'=>3, 'from'=>101, 'to'=>101], $page->metadata(300, 101));
        $this->assertSame(1, $page->metadata(300, 0)['page']);
        $this->assertSame(str_repeat('é', 150), TablePage::fromQuery(['q'=>str_repeat('é', 150)])->q);
        foreach ([25, 50, 100] as $size) {
            $this->assertSame($size, TablePage::fromQuery(['per_page'=>(string) $size])->perPage);
        }
    }

    public function testExpiryStatusesAreAccepted(): void
    {
        foreach (['expiring_soon', 'expired'] as $status) {
            $this->assertSame($status, TablePage::fromQuery(['status' => $status])->status);
        }
    }

    public function testIndependentListsUseTheirOwnSizesAndDefaults(): void
    {
        $query = ['drafts_page' => '4', 'drafts_per_page' => '20', 'jobs_page' => '2', 'jobs_per_page' => '50', 'per_page' => '100'];
        $drafts = TablePage::forList($query, 'drafts_page', 'drafts_per_page', 20);
        $jobs = TablePage::forList($query, 'jobs_page', 'jobs_per_page', 20);
        $this->assertSame(['page' => 4, 'per_page' => 20, 'total' => 75, 'filtered' => 75, 'pages' => 4, 'from' => 61, 'to' => 75], $drafts->metadata(75, 75));
        $this->assertSame(50, $jobs->perPage);
        $this->assertSame(2, $jobs->page);
        $this->assertSame(20, TablePage::forList([], 'jobs_page', 'jobs_per_page', 20)->perPage);
        $this->assertSame(25, TablePage::forList([])->perPage);
    }

    public function testMalformedNamedPaginationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        TablePage::forList(['jobs_per_page' => ['20']], 'jobs_page', 'jobs_per_page', 20);
    }

    public static function invalidQueries(): iterable
    {
        foreach (['q', 'page', 'per_page', 'kind', 'status', 'fragment'] as $key) {
            foreach ([[], ['nested'], null, true, false, new \stdClass()] as $value) {
                yield $key . '-' . get_debug_type($value) . '-' . serialize($value) => [[$key=>$value]];
            }
        }
        foreach (['', '0', '-1', '01', '+1', '1.5', '1e2', ' 2', '2 ', '1000001', '999999999999999999', 1.2] as $value) {
            yield [['page'=>$value]];
        }
        foreach (['', '26', '0', '250', '025', '25.0', 'all'] as $value) {
            yield [['per_page'=>$value]];
        }
        yield [['q'=>str_repeat('x', 151)]];
        yield [['q'=>"\xFF"]];
        yield [['q'=>123]];
        yield [['kind'=>'staff']];
        yield [['status'=>'unsupported_status']];
        yield [['fragment'=>'yes']];
    }

    #[DataProvider('invalidQueries')]
    public function testMalformedParametersAre422(array $query): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        TablePage::fromQuery($query);
    }
}
