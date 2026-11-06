<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\SchedulingClock;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class SchedulingClockTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    public function testManilaMidnightBoundaryIsIndependentOfDefaultTimezone(): void
    {
        foreach (['UTC', 'America/Los_Angeles', 'Pacific/Apia'] as $timezone) {
            date_default_timezone_set($timezone);
            foreach ([
                ['2026-10-19T15:59:59Z', '2026-10-19 23:59:59'],
                ['2026-10-19T16:00:00Z', '2026-10-20 00:00:00'],
                ['2026-12-31T16:00:00Z', '2027-01-01 00:00:00'],
            ] as [$utc, $expected]) {
                $instant = new DateTimeImmutable($utc);
                $local = SchedulingClock::now($instant);
                self::assertSame('Asia/Manila', $local->getTimezone()->getName());
                self::assertSame($instant->getTimestamp(), $local->getTimestamp());
                self::assertSame($expected, SchedulingClock::timestamp($instant));
                self::assertSame(substr($expected, 0, 10), SchedulingClock::today($instant));
                self::assertSame($utc, $instant->format('Y-m-d\TH:i:s\Z'));
                self::assertSame($timezone, date_default_timezone_get());
            }
        }
    }

    public function testClockWithoutInstantUsesRealTimeWithoutChangingDefaultTimezone(): void
    {
        date_default_timezone_set('UTC');
        $before = time();
        $now = SchedulingClock::now();
        self::assertGreaterThanOrEqual($before, $now->getTimestamp());
        self::assertLessThanOrEqual(time(), $now->getTimestamp());
        self::assertSame('Asia/Manila', $now->getTimezone()->getName());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/D', SchedulingClock::today());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', SchedulingClock::timestamp());
        self::assertSame('UTC', date_default_timezone_get());
    }

    public function testAppointmentParsingAndSlotGridIgnoreDefaultTimezone(): void
    {
        // Apia skipped this date entirely; Manila wall-clock parsing must still accept it.
        foreach (['UTC', 'America/New_York', 'Pacific/Apia'] as $timezone) {
            date_default_timezone_set($timezone);
            $service = $this->serviceWithoutDatabase();
            $service->validateDate('2011-12-30');
            $interval = new ReflectionMethod(AppointmentService::class, 'interval');
            self::assertSame(['2011-12-30 08:00:00', '2011-12-30 09:00:00'],
                $interval->invoke($service, '2011-12-30T08:00', '2011-12-30T09:00'));
            $slots = new ReflectionMethod(AppointmentService::class, 'slotIntervals');
            $grid = iterator_to_array($slots->invoke($service, '2011-12-30', [
                'start_time' => '08:00:00', 'end_time' => '09:00:00', 'slot_duration_minutes' => 30,
            ]));
            self::assertCount(2, $grid);
            self::assertSame('2011-12-30 08:00:00', $grid[0][0]->format('Y-m-d H:i:s'));
            self::assertSame('Asia/Manila', $grid[0][0]->getTimezone()->getName());
            self::assertSame('5', $grid[0][0]->format('w'));
            self::assertSame('2011-12-30 09:00:00', $grid[1][1]->format('Y-m-d H:i:s'));
        }
    }

    public function testIntervalValidationStillRejectsInvalidOrOffsetBearingValues(): void
    {
        $service = $this->serviceWithoutDatabase();
        $interval = new ReflectionMethod(AppointmentService::class, 'interval');
        foreach ([
            ['2026-02-30 08:00:00', '2026-03-01 09:00:00'],
            ['2026-10-19 24:00:00', '2026-10-20 09:00:00'],
            ['2026-10-19T08:00:00Z', '2026-10-19T09:00:00Z'],
            ['2026-10-19 08:00:00+08:00', '2026-10-19 09:00:00+08:00'],
            ['2026-10-19 09:00:00', '2026-10-19 09:00:00'],
            ['2026-10-19 09:00:00', '2026-10-19 08:00:00'],
        ] as [$start, $end]) {
            try {
                $interval->invoke($service, $start, $end);
                self::fail('Invalid interval was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testFormsCaptureOneSchedulingDayForDefaultsAndMinimums(): void
    {
        $viewDirectory = dirname(__DIR__, 2) . '/app/Views/appointments/';
        $create = file_get_contents($viewDirectory . 'create.php');
        $confirm = file_get_contents($viewDirectory . 'confirm.php');
        foreach ([$create, $confirm] as $source) {
            self::assertSame(1, substr_count($source, 'SchedulingClock::today()'));
            self::assertStringContainsString('$today = \\App\\Services\\Scheduling\\SchedulingClock::today();', $source);
            self::assertStringContainsString('data-min="<?= esc($today) ?>"', $source);
            self::assertStringNotContainsString("date('Y-m-d')", $source);
        }
        self::assertStringContainsString('data-initial-date="<?= esc($today) ?>"', $create);
        self::assertStringContainsString('value="<?= esc($today) ?>"', $create);
        self::assertStringContainsString('data-initial-date="<?= esc($appointment[\'local_date\']) ?>"', $confirm);
        self::assertStringContainsString('value="<?= esc($appointment[\'local_date\']) ?>"', $confirm);
    }

    public function testQueueHandoffPortalTicketAndCheckInViewShareTheSchedulingDay(): void
    {
        $root = dirname(__DIR__, 2) . '/app/';
        $filter = file_get_contents($root . 'Filters/WebAccessFilter.php');
        $portal = file_get_contents($root . 'Controllers/PortalController.php');
        $view = file_get_contents($root . 'Views/appointments/index.php');
        self::assertStringContainsString("\$ticket['queue_date'] !== SchedulingClock::today()", $filter);
        self::assertStringContainsString('(new PortalQueueService())->snapshot()', $portal);
        $portalQueue = file_get_contents($root . 'Services/PortalQueueService.php');
        self::assertStringContainsString('$date = SchedulingClock::today();', $portalQueue);
        self::assertStringContainsString("->where('queue_date', \$date)", $portalQueue);
        self::assertStringContainsString('$today = \\App\\Services\\Scheduling\\SchedulingClock::today();', $view);
        self::assertStringContainsString("\$appt['local_date'] === \$today", $view);
        foreach ([$filter, $portal, $view] as $source) self::assertStringNotContainsString("date('Y-m-d')", $source);
    }

    public function testMalformedDatesIncludingNullBytesAreRejectedBeforeParsing(): void
    {
        $service = $this->serviceWithoutDatabase();
        foreach (["2026-10-19\0", "2026-10-\0" . '19', "2026-10-19\n", '2026-1-19', '2026-02-30'] as $date) {
            try {
                $service->validateDate($date);
                self::fail('Malformed date was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertSame('Use a valid appointment date (YYYY-MM-DD).', $e->getMessage());
            }
        }
    }

    public function testNullBytesInEitherIntervalEndpointAreRejectedBeforeParsing(): void
    {
        $service = $this->serviceWithoutDatabase();
        $interval = new ReflectionMethod(AppointmentService::class, 'interval');
        foreach (["2026-10-19 08:00:00\0", "2026-10-19\0 08:00:00", "2026-10-19T08:00\0"] as $malformed) {
            foreach ([[$malformed, '2026-10-19 09:00:00'], ['2026-10-19 07:00:00', $malformed]] as [$start, $end]) {
                try {
                    $interval->invoke($service, $start, $end);
                    self::fail('Malformed interval was accepted.');
                } catch (InvalidArgumentException $e) {
                    self::assertSame('Use valid appointment start and end timestamps.', $e->getMessage());
                }
            }
        }
    }

    public function testPastTimeGuardUsesManilaRatherThanDefaultTimezone(): void
    {
        date_default_timezone_set('America/Los_Angeles');
        $guard = new ReflectionMethod(AppointmentService::class, 'assertNotPast');
        $service = $this->serviceWithoutDatabase();
        $guard->invoke($service, SchedulingClock::now()->modify('+1 hour')->format('Y-m-d H:i:s'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Appointment time must not be in the past.');
        $guard->invoke($service, SchedulingClock::now()->modify('-1 hour')->format('Y-m-d H:i:s'));
    }

    public function testBusinessDaysAreOldestFirstAndEndOnTheManilaDay(): void
    {
        $instant = new DateTimeImmutable('2026-11-03T05:00:00Z');
        self::assertSame('2026-11-03', SchedulingClock::today($instant));
        self::assertSame([
            '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31',
            '2026-11-01', '2026-11-02', '2026-11-03',
        ], SchedulingClock::businessDays(7, $instant));
        self::assertSame(['2026-11-03'], SchedulingClock::businessDays(1, $instant));
    }

    public function testBusinessDayCountIsBounded(): void
    {
        foreach ([0, -1, 32] as $count) {
            try {
                SchedulingClock::businessDays($count);
                self::fail('Out-of-range business day count was accepted.');
            } catch (InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testManilaDayMapsOntoTheMatchingUtcInterval(): void
    {
        // Manila is UTC+8 year-round, so a Manila day is a fixed 16:00-to-16:00 UTC window.
        self::assertSame(['2026-11-02 16:00:00', '2026-11-03 16:00:00'], SchedulingClock::dayRangeUtc('2026-11-03'));
        self::assertSame(['2026-02-28 16:00:00', '2026-03-01 16:00:00'], SchedulingClock::dayRangeUtc('2026-03-01'));
        self::assertSame(['2028-02-28 16:00:00', '2028-02-29 16:00:00'], SchedulingClock::dayRangeUtc('2028-02-29'));
        self::assertSame(['2026-12-31 16:00:00', '2027-01-01 16:00:00'], SchedulingClock::dayRangeUtc('2027-01-01'));
    }

    public function testBusinessDayRejectsDatesThatDoNotExist(): void
    {
        foreach (['2026-02-30', '2026-13-01', 'not-a-date', '2026-1-9', ''] as $invalid) {
            try {
                SchedulingClock::dayRangeUtc($invalid);
                self::fail('Malformed business day was accepted: ' . $invalid);
            } catch (InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testBusinessDayIndexLocatesTheContainingDay(): void
    {
        $ranges = SchedulingClock::businessDayRanges(2, new DateTimeImmutable('2026-11-03T05:00:00Z'));
        self::assertSame('2026-11-02', array_key_first($ranges));
        self::assertSame('2026-11-03', array_key_last($ranges));
        self::assertSame(0, SchedulingClock::businessDayIndex('2026-11-01 16:00:00', $ranges));
        self::assertSame(0, SchedulingClock::businessDayIndex('2026-11-02 15:59:59', $ranges));
        self::assertSame(1, SchedulingClock::businessDayIndex('2026-11-02 16:00:00', $ranges));
        self::assertSame(1, SchedulingClock::businessDayIndex('2026-11-03 15:59:59', $ranges));
        self::assertNull(SchedulingClock::businessDayIndex('2026-11-01 15:59:59', $ranges));
        self::assertNull(SchedulingClock::businessDayIndex('2026-11-03 16:00:00', $ranges));
    }

    public function testStoredUtcTimestampsRenderInManilaWallClock(): void
    {
        self::assertSame('2026-11-03 00:30:00', SchedulingClock::formatManila('2026-11-02 16:30:00', 'Y-m-d H:i:s'));
        self::assertSame('2026-11-02 23:30:00', SchedulingClock::formatManila('2026-11-02 15:30:00', 'Y-m-d H:i:s'));
        self::assertSame('Nov 3', SchedulingClock::formatManila('2026-11-02 16:30:00', 'M j'));
    }

    private function serviceWithoutDatabase(): AppointmentService
    {
        return (new ReflectionClass(AppointmentService::class))->newInstanceWithoutConstructor();
    }
}
