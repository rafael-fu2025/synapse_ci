<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TeamCalendarService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class TeamCalendarServiceTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        $service = (new ReflectionClass(TeamCalendarService::class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod(TeamCalendarService::class, $method))->invoke($service, ...$args);
    }

    public static function invalidInputs(): iterable
    {
        foreach ([['title' => ' '], ['title' => []], ['title' => str_repeat('日', 161)], ['title' => "\xFF"],
            ['description' => null], ['description' => str_repeat('x', 4001)], ['description' => "bad\0text"],
            ['location' => str_repeat('x', 161)], ['location' => false],
            ['starts_at' => '2026-02-29T10:00'], ['starts_at' => '2026-04-31T10:00'],
            ['starts_at' => '0000-01-01T10:00'], ['starts_at' => '2026-10-01T24:00'],
            ['starts_at' => '2026-10-01T10:60'], ['starts_at' => '2026-1-01T10:00'],
            ['starts_at' => '2026-10-01 10:00'], ['starts_at' => '2026-10-01T10:00:00'],
            ['starts_at' => '2026-10-01T10:00Z'], ['starts_at' => "2026-10-01T10:00\n"],
            ['starts_at' => null], ['ends_at' => []], ['ends_at' => '2026-10-01T10:00'],
            ['ends_at' => '2026-10-01T09:59'], ['ends_at' => '2026-11-01T10:01'],
            ['participant_ids' => null], ['participant_ids' => '[]'], ['participant_ids' => [1 => 2]],
            ['participant_ids' => [true]], ['participant_ids' => [1.0]], ['participant_ids' => [0]],
            ['participant_ids' => ['01']], ['participant_ids' => [' 1']], ['participant_ids' => ['+1']],
            ['participant_ids' => ['1e2']], ['participant_ids' => [PHP_INT_MAX]],
            ['participant_ids' => [1, '1']], ['participant_ids' => range(1, 101)],
        ] as $index => $input) { yield (string) $index => [$input]; }
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputFails422(array $input): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(422);
        $this->invoke('editable', $input + ['title' => 'Valid', 'starts_at' => '2026-10-01T10:00', 'ends_at' => '2026-10-01T11:00'], false);
    }

    public function testDefaultsUnicodeBoundariesAndMaximumDuration(): void
    {
        $data = $this->invoke('editable', ['title' => str_repeat('日', 160), 'description' => str_repeat('😀', 4000),
            'location' => str_repeat('日', 160), 'starts_at' => '2028-02-29T00:00', 'ends_at' => '2028-03-31T00:00',
            'participant_ids' => array_reverse(range(1, 100)), 'status' => 'cancelled', 'tenant_id' => 999], false);
        $this->assertSame('2028-02-28 16:00:00', $data['starts_at']);
        $this->assertSame('2028-03-30 16:00:00', $data['ends_at']);
        $this->assertSame(range(1, 100), $data['participant_ids']);
        $this->assertArrayNotHasKey('status', $data);
        $this->assertArrayNotHasKey('tenant_id', $data);
        $data = $this->invoke('editable', ['title' => ' Trim ', 'starts_at' => '2026-10-01T00:00', 'ends_at' => '2026-10-01T00:01'], false);
        $this->assertSame('Trim', $data['title']);
        $this->assertSame('', $data['description']);
        $this->assertSame('', $data['location']);
        $this->assertSame([], $data['participant_ids']);
    }

    public function testUpdateRequiresAllEditableFields(): void
    {
        $this->expectExceptionCode(422);
        $this->invoke('editable', ['title' => 'Only title', 'starts_at' => '2026-10-01T10:00', 'ends_at' => '2026-10-01T11:00'], true);
    }

    public static function ranges(): iterable
    {
        yield 'six weeks and month-end navigation' => ['2026-08-31', 'month', '2026-07-27', '2026-09-07', '2026-07-01', '2026-09-01'];
        yield 'four complete weeks' => ['2027-02-28', 'month', '2027-02-01', '2027-03-01', '2027-01-01', '2027-03-01'];
        yield 'year handoff week' => ['2027-01-01', 'week', '2026-12-28', '2027-01-04', '2026-12-25', '2027-01-08'];
        yield 'agenda leap year' => ['2028-02-15', 'agenda', '2028-02-15', '2028-03-16', '2028-01-16', '2028-03-16'];
    }

    #[DataProvider('ranges')]
    public function testRanges(string $date, string $view, string $start, string $end, string $prev, string $next): void
    {
        $this->assertSame(['date' => $date, 'view' => $view, 'mine' => '', 'startDate' => $start, 'endDate' => $end,
            'prevDate' => $prev, 'nextDate' => $next], $this->invoke('range', compact('date', 'view')));
    }

    public function testEveryMonthIsACompleteMondayGridWithAtMost42Days(): void
    {
        for ($year = 2024; $year <= 2030; $year++) {
            for ($month = 1; $month <= 12; $month++) {
                $range = $this->invoke('range', ['date' => sprintf('%04d-%02d-15', $year, $month)]);
                $start = new DateTimeImmutable($range['startDate']);
                $end = new DateTimeImmutable($range['endDate']);
                $this->assertSame('1', $start->format('N'));
                $this->assertSame('1', $end->format('N'));
                $this->assertContains((int) $start->diff($end)->days, [28, 35, 42]);
            }
        }
    }

    public static function invalidRanges(): iterable
    {
        foreach ([['date' => '2026-02-29'], ['date' => '2026-1-01'], ['date' => '0000-01-01'], ['date' => []],
            ['date' => '9999-12-31'], ['date' => '0001-01-01'], ['view' => 'day'], ['view' => []],
            ['date' => null], ['view' => null], ['mine' => null],
                        ['mine' => true], ['mine' => 1], ['mine' => '0'], ['mine' => []]] as $i => $filters) {
            yield (string) $i => [$filters];
        }
    }

    #[DataProvider('invalidRanges')]
    public function testInvalidRangeIsRejected(array $filters): void
    {
        $this->expectExceptionCode(422);
        $this->invoke('range', $filters);
    }

    public function testDefaultDateUsesManilaRatherThanServerTimezone(): void
    {
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Los_Angeles');
            $before = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $result = $this->invoke('range', []);
            $after = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            $this->assertContains($result['date'], [$before, $after]);
            $this->assertSame('month', $result['view']);
        } finally { date_default_timezone_set($previous); }
    }
}
