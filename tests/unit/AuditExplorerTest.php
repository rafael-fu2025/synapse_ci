<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\AuditExplorer;
use App\Services\Audit\AuditExplorerQuery;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuditExplorerTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    public function testAllFiltersAndTenantScopeApplyToPageAndOppositeNavigationQuery(): void
    {
        CurrentTenant::set(73);
        $query = AuditExplorerQuery::fromQuery([
            'event_type' => 'clinic.visit', 'action' => 'UPDATE', 'entity_type' => 'patient',
            'entity_id' => "O'Neil%_0", 'actor_user_id' => '18446744073709551615',
            'from' => '2024-02-29', 'to' => '2024-03-01',
            'cursor' => '18446744073709551615', 'per_page' => '25',
        ]);
        $this->assertSame([], $query->errors);
        $filters = [
            ['ae.tenant_id', 73], ['ae.event_type', 'clinic.visit'], ['ae.action', 'UPDATE'],
            ['ae.entity_type', 'patient'], ['ae.entity_id', "O'Neil%_0"],
            ['ae.actor_user_id', '18446744073709551615'],
            ['ae.occurred_at >=', '2024-02-29 00:00:00'],
            ['ae.occurred_at <=', '2024-03-01 23:59:59'],
        ];
        $rows = [['id' => '9223372036854775809', 'actor_name' => 'Alice'], ['id' => '9223372036854775808', 'actor_name' => null]];
        $page = $this->pageBuilder(array_merge($filters, [['ae.id <', '18446744073709551615']]), 'DESC', 26, $rows);
        $opposite = $this->oppositeBuilder(array_merge($filters, [['ae.id >', '9223372036854775809']]), true);
        $result = (new AuditExplorer($this->connection([$page, $opposite])))->page($query);
        $this->assertSame(['events' => $rows, 'older_cursor' => null, 'newer_cursor' => '9223372036854775809'], $result);
    }

    #[DataProvider('navigationPages')]
    public function testLookaheadOrderingAndNavigationBoundaries(
        array $input,
        array $ids,
        bool $oppositeExists,
        array $expectedIds,
        ?string $olderCursor,
        ?string $newerCursor
    ): void {
        CurrentTenant::set(42);
        $query = AuditExplorerQuery::fromQuery($input + ['per_page' => '25']);
        $this->assertSame([], $query->errors);
        $newer = $query->direction === 'newer';
        $where = [['ae.tenant_id', 42]];
        if ($query->cursor !== null) {
            $where[] = [$newer ? 'ae.id >' : 'ae.id <', $query->cursor];
        }
        $rows = array_map(static fn ($id) => ['id' => $id, 'actor_name' => 'Actor ' . $id], $ids);
        $builders = [$this->pageBuilder($where, $newer ? 'ASC' : 'DESC', 26, $rows)];
        if ($expectedIds !== []) {
            $boundary = $newer ? $expectedIds[count($expectedIds) - 1] : $expectedIds[0];
            $builders[] = $this->oppositeBuilder([
                ['ae.tenant_id', 42], [$newer ? 'ae.id <' : 'ae.id >', $boundary],
            ], $oppositeExists);
        }
        $result = (new AuditExplorer($this->connection($builders)))->page($query);
        $expectedRows = array_map(static fn ($id) => ['id' => $id, 'actor_name' => 'Actor ' . $id], $expectedIds);
        $this->assertSame(['events' => $expectedRows, 'older_cursor' => $olderCursor, 'newer_cursor' => $newerCursor], $result);
    }

    public static function navigationPages(): iterable
    {
        $descending = array_map('strval', range(100, 75));
        $ascending = array_map('strval', range(101, 126));
        yield 'first page discards older lookahead' => [[], $descending, false, array_slice($descending, 0, 25), '76', null];
        yield 'older middle page has both links' => [['cursor' => '101'], $descending, true, array_slice($descending, 0, 25), '76', '100'];
        yield 'last older page has only newer link' => [['cursor' => '4'], ['3', '2', '1'], true, ['3', '2', '1'], null, '3'];
        yield 'single partial page has no links' => [[], ['3', '2', '1'], false, ['3', '2', '1'], null, null];
        yield 'exact full page is not lookahead' => [[], array_slice($descending, 0, 25), false, array_slice($descending, 0, 25), null, null];
        yield 'newer middle trims before reversing' => [['cursor' => '100', 'direction' => 'newer'], $ascending, true, array_map('strval', range(125, 101)), '101', '125'];
        yield 'newer reaches first page' => [['cursor' => '100', 'direction' => 'newer'], ['101', '102'], true, ['102', '101'], '101', null];
        yield 'newer reaches oldest boundary' => [['cursor' => '100', 'direction' => 'newer'], $ascending, false, array_map('strval', range(125, 101)), null, '125'];
        yield 'newer single page has no links' => [['cursor' => '100', 'direction' => 'newer'], ['101', '102'], false, ['102', '101'], null, null];
        yield 'empty first page skips existence query' => [[], [], false, [], null, null];
        yield 'empty older page skips existence query' => [['cursor' => '1'], [], false, [], null, null];
        yield 'empty newer page skips existence query' => [['cursor' => '18446744073709551615', 'direction' => 'newer'], [], false, [], null, null];
        yield 'uint64 max survives reversal and cursor output' => [['cursor' => '18446744073709551613', 'direction' => 'newer'], ['18446744073709551614', '18446744073709551615'], true, ['18446744073709551615', '18446744073709551614'], '18446744073709551614', null];
    }

    public function testSupportedPageSizesFetchExactlyOneLookaheadRow(): void
    {
        CurrentTenant::set(42);
        foreach ([25, 50, 100] as $limit) {
            $query = AuditExplorerQuery::fromQuery(['per_page' => $limit]);
            $page = $this->pageBuilder([['ae.tenant_id', 42]], 'DESC', $limit + 1, []);
            $result = (new AuditExplorer($this->connection([$page])))->page($query);
            $this->assertSame(['events' => [], 'older_cursor' => null, 'newer_cursor' => null], $result);
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueriesAreRefusedBeforeAnyDatabaseAccess(array $input): void
    {
        $query = AuditExplorerQuery::fromQuery($input);
        $this->assertNotSame([], $query->errors);
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('table');
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('initialize');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot query audit events with invalid filters.');
        (new AuditExplorer($db))->page($query);
    }

    public static function invalidQueries(): iterable
    {
        yield 'array filter' => [['event_type' => ['login']]];
        yield 'oversize filter' => [['entity_id' => str_repeat('x', 65)]];
        yield 'invalid date' => [['from' => '2023-02-29']];
        yield 'inverted dates' => [['from' => '2024-03-01', 'to' => '2024-02-29']];
        yield 'invalid page size' => [['per_page' => '26']];
        yield 'overflow cursor' => [['cursor' => '18446744073709551616']];
        yield 'invalid actor' => [['actor_user_id' => '0']];
        yield 'invalid direction' => [['direction' => 'next']];
        yield 'newer without cursor' => [['direction' => 'newer']];
    }

    private function connection(array $builders): BaseConnection
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('initialize');
        $db->expects($this->never())->method('query');
        $db->expects($this->exactly(count($builders)))->method('table')
            ->with('audit_events ae')->willReturnOnConsecutiveCalls(...$builders);
        return $db;
    }

    private function filteredBuilder(array $expectedWhere): BaseBuilder
    {
        $builder = $this->createMock(BaseBuilder::class);
        $index = 0;
        $builder->expects($this->exactly(count($expectedWhere)))->method('where')
            ->willReturnCallback(function ($key, $value) use ($builder, $expectedWhere, &$index) {
                $this->assertSame($expectedWhere[$index++], [$key, $value]);
                return $builder;
            });
        $builder->expects($this->never())->method('like');
        $builder->expects($this->never())->method('orWhere');
        $builder->expects($this->never())->method('countAllResults');
        return $builder;
    }

    private function pageBuilder(array $where, string $order, int $limit, array $rows): BaseBuilder
    {
        $builder = $this->filteredBuilder($where);
        $builder->expects($this->once())->method('select')->with('ae.*, u.username AS actor_name')->willReturnSelf();
        $builder->expects($this->once())->method('join')
            ->with('users u', 'u.id = ae.actor_user_id AND u.tenant_id = ae.tenant_id', 'left')->willReturnSelf();
        $builder->expects($this->once())->method('orderBy')->with('ae.id', $order)->willReturnSelf();
        $result = $this->createMock(BaseResult::class);
        $result->expects($this->once())->method('getResultArray')->willReturn($rows);
        $builder->expects($this->once())->method('get')->with($limit)->willReturn($result);
        return $builder;
    }

    private function oppositeBuilder(array $where, bool $exists): BaseBuilder
    {
        $builder = $this->filteredBuilder($where);
        $builder->expects($this->once())->method('select')->with('ae.id')->willReturnSelf();
        $builder->expects($this->never())->method('join');
        $builder->expects($this->never())->method('orderBy');
        $result = $this->createMock(BaseResult::class);
        $result->expects($this->once())->method('getRowArray')->willReturn($exists ? ['id' => '1'] : null);
        $builder->expects($this->once())->method('get')->with(1)->willReturn($result);
        return $builder;
    }
}
