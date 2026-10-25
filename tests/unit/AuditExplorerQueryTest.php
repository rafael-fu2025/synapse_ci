<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\AuditExplorerQuery;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuditExplorerQueryTest extends CIUnitTestCase
{
    public function testDefaultsAndExactFiltersPreserveLiteralValues(): void
    {
        $defaults = AuditExplorerQuery::fromQuery([]);
        $this->assertSame([], $defaults->errors);
        $this->assertNull($defaults->cursor);
        $this->assertSame('older', $defaults->direction);
        $this->assertSame(50, $defaults->limit);
        $this->assertSame(['per_page' => '50'], $defaults->parameters());

        $filters = [
            'event_type' => 'clinic.visit', 'action' => 'UPDATE',
            'entity_type' => 'patient', 'entity_id' => "O'Neil%_0",
            'actor_user_id' => '18446744073709551615',
            'from' => '2024-02-29', 'to' => '2024-02-29', 'per_page' => '25',
        ];
        $query = AuditExplorerQuery::fromQuery(array_map(static fn ($value) => ' ' . $value . ' ', $filters));
        $this->assertSame([], $query->errors);
        $this->assertSame($filters, $query->filters);
        $this->assertSame($filters, $query->parameters());
    }

    public function testEveryInputRejectsArraysAndOversizeValues(): void
    {
        foreach (['event_type' => 64, 'action' => 32, 'entity_type' => 64, 'entity_id' => 64,
            'actor_user_id' => 20, 'from' => 10, 'to' => 10, 'per_page' => 3,
            'cursor' => 20, 'direction' => 5] as $key => $max) {
            $array = AuditExplorerQuery::fromQuery([$key => ['value']]);
            $this->assertContains("Invalid {$key}: a single value is required.", $array->errors, $key);
            $oversize = AuditExplorerQuery::fromQuery([$key => str_repeat('x', $max + 1)]);
            $this->assertContains("Invalid {$key}: value is too long.", $oversize->errors, $key);
        }
    }

    public function testExactTextFiltersAcceptTheirMaximumLength(): void
    {
        foreach (['event_type' => 64, 'action' => 32, 'entity_type' => 64, 'entity_id' => 64] as $key => $max) {
            $value = str_repeat('x', $max);
            $query = AuditExplorerQuery::fromQuery([$key => $value]);
            $this->assertSame([], $query->errors, $key);
            $this->assertSame($value, $query->filters[$key]);
        }
    }

    public function testDatesRequireRealCalendarDatesAndAscendingRange(): void
    {
        foreach (['2023-02-29', '2024-02-30', '2024-13-01', '2024-00-01', '2024-01-00',
            '0000-01-01', '2024-2-01', '01/02/2024', 'tomorrow'] as $date) {
            foreach (['from', 'to'] as $key) {
                $query = AuditExplorerQuery::fromQuery([$key => $date]);
                $this->assertContains("Invalid {$key} date: use YYYY-MM-DD.", $query->errors, $date);
            }
        }
        $query = AuditExplorerQuery::fromQuery(['from' => '2024-03-01', 'to' => '2024-02-29']);
        $this->assertContains('Start date must not be after end date.', $query->errors);
        foreach ([['from' => '2024-02-29'], ['to' => '2024-02-29'],
            ['from' => '2024-02-29', 'to' => '2024-03-01']] as $range) {
            $this->assertSame([], AuditExplorerQuery::fromQuery($range)->errors);
        }
    }

    public function testPageSizeAllowsOnlySupportedValuesAndDefaultsToFifty(): void
    {
        foreach (['' => 50, '25' => 25, '50' => 50, '100' => 100] as $value => $limit) {
            $query = AuditExplorerQuery::fromQuery(['per_page' => $value]);
            $this->assertSame([], $query->errors);
            $this->assertSame($limit, $query->limit);
            $this->assertSame((string) $limit, $query->parameters()['per_page']);
        }
        foreach (['0', '1', '24', '26', '101', '-25', '025', 'abc'] as $value) {
            $query = AuditExplorerQuery::fromQuery(['per_page' => $value]);
            $this->assertContains('Page size must be 25, 50, or 100.', $query->errors, $value);
        }
    }

    public function testCursorAndActorPreserveUnsignedBigintStrings(): void
    {
        foreach (['1', '9223372036854775808', '18446744073709551615'] as $id) {
            $query = AuditExplorerQuery::fromQuery(['cursor' => $id, 'actor_user_id' => $id]);
            $this->assertSame([], $query->errors);
            $this->assertSame($id, $query->cursor);
            $this->assertSame($id, $query->filters['actor_user_id']);
            $this->assertSame($id, $query->parameters($query->cursor)['cursor']);
        }
        $query = AuditExplorerQuery::fromQuery(['cursor' => 123, 'actor_user_id' => 456]);
        $this->assertSame([], $query->errors);
        $this->assertSame('123', $query->cursor);
        $this->assertSame('456', $query->filters['actor_user_id']);
    }

    #[DataProvider('invalidIds')]
    public function testCursorAndActorRejectInvalidUnsignedBigints(string $id): void
    {
        $query = AuditExplorerQuery::fromQuery(['cursor' => $id, 'actor_user_id' => $id]);
        $this->assertContains('Invalid pagination cursor.', $query->errors);
        $this->assertContains('Actor ID must be a positive BIGINT UNSIGNED decimal.', $query->errors);
        $this->assertNull($query->cursor);
    }

    public static function invalidIds(): iterable
    {
        foreach (['0', '-1', '+1', '01', '1.0', '1e3', 'abc', '18446744073709551616', '99999999999999999999'] as $id) {
            yield $id => [$id];
        }
    }

    public function testDirectionValidationAndNewerRequiresCursor(): void
    {
        foreach (['next', 'OLDER', 'NEWER', '0'] as $direction) {
            $query = AuditExplorerQuery::fromQuery(['direction' => $direction, 'cursor' => '10']);
            $this->assertContains('Invalid pagination direction.', $query->errors);
        }
        $this->assertContains('Newer navigation requires a cursor.', AuditExplorerQuery::fromQuery(['direction' => 'newer'])->errors);
        foreach (['older', 'newer', ''] as $direction) {
            $query = AuditExplorerQuery::fromQuery(['direction' => $direction, 'cursor' => '10']);
            $this->assertSame([], $query->errors);
            $this->assertSame($direction === '' ? 'older' : $direction, $query->direction);
        }
    }

    public function testParametersResetNavigationAndOmitEmptyButNotZeroFilters(): void
    {
        $query = AuditExplorerQuery::fromQuery([
            'event_type' => 'login', 'entity_id' => '0', 'action' => ' ',
            'cursor' => '18446744073709551615', 'direction' => 'newer', 'per_page' => '100',
            'unknown' => 'ignored',
        ]);
        $this->assertSame([], $query->errors);
        $filters = ['event_type' => 'login', 'entity_id' => '0', 'per_page' => '100'];
        $this->assertSame($filters, $query->parameters());
        $this->assertSame($filters, $query->parameters(null, 'newer'));
        $this->assertSame($filters + ['cursor' => '9223372036854775808', 'direction' => 'older'], $query->parameters('9223372036854775808'));
        $this->assertSame($filters + ['cursor' => '18446744073709551615', 'direction' => 'newer'], $query->parameters($query->cursor, 'newer'));
    }
}
