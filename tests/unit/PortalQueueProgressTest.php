<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalQueueService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** Every fixture uses its own in-memory SQLite connection, never the application DB. */
final class PortalQueueProgressTest extends CIUnitTestCase
{
    private BaseConnection $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $this->fixture->query('CREATE TABLE queue_entries (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, queue_date TEXT, ticket_no INTEGER, ticket_code TEXT, status TEXT, priority TEXT, started_at TEXT, completed_at TEXT)');
    }

    protected function tearDown(): void
    {
        $this->fixture->close();
        parent::tearDown();
    }

    private function insert(array $changes = []): int
    {
        $this->fixture->table('queue_entries')->insert(array_replace([
            'tenant_id' => 7, 'person_id' => 99, 'unit' => 'clinic', 'queue_date' => SchedulingClock::today(),
            'ticket_no' => 1, 'ticket_code' => 'PRIVATE-PEER', 'status' => 'waiting', 'priority' => 'routine',
        ], $changes));
        return (int) $this->fixture->insertID();
    }

    private function snapshot(string $role = 'student'): array
    {
        $actor = ['id' => 3, 'tenant_id' => 7, 'person_id' => 88, 'role' => $role, 'logged_in' => true,
            'permissions' => ['portal.records.read', 'portal.appointments.read']];
        return (new PortalQueueService($this->fixture, static fn () => $actor, static fn () => 7))->snapshot();
    }

    private function progress(?int $position, ?int $ahead, ?int $eta, string $role = 'student'): void
    {
        $snapshot = $this->snapshot($role);
        self::assertSame(['date', 'tickets'], array_keys($snapshot));
        self::assertCount(1, $snapshot['tickets']);
        $ticket = $snapshot['tickets'][0];
        self::assertSame(['unit', 'ticket_code', 'status', 'priority', 'position', 'people_ahead', 'estimated_wait_minutes'], array_keys($ticket));
        self::assertSame([$position, $ahead, $eta], [$ticket['position'], $ticket['people_ahead'], $ticket['estimated_wait_minutes']]);
        self::assertStringNotContainsString('PRIVATE-PEER', json_encode($snapshot));
    }

    private function history(array $durations = [5, 10, 20], array $changes = []): void
    {
        $end = SchedulingClock::now()->modify('-1 hour');
        foreach ($durations as $minutes) {
            $this->insert(array_replace(['status' => 'done', 'queue_date' => $end->format('Y-m-d'),
                'started_at' => $end->modify('-' . $minutes . ' minutes')->format('Y-m-d H:i:s'),
                'completed_at' => $end->format('Y-m-d H:i:s')], $changes));
        }
    }

    public function testExactPriorityNumberAndIdOrderIgnoresTicketGapsAndUnrelatedEntries(): void
    {
        $this->insert(['priority' => 'emergency', 'ticket_no' => 999]);
        $this->insert(['priority' => 'urgent', 'ticket_no' => 999]);
        $this->insert(['ticket_no' => 2]);
        $this->insert(['ticket_no' => 50]); // Equal number, earlier id.
        $own = $this->insert(['person_id' => 88, 'ticket_code' => 'OWN', 'ticket_no' => 50]);
        $this->insert(['ticket_no' => 50]); // Equal number, later id.
        $this->insert(['ticket_no' => 900]);
        $this->insert(['status' => 'called']);
        $this->insert(['status' => 'in_consultation']);
        foreach ([['tenant_id' => 8], ['unit' => 'guidance'], ['queue_date' => '2000-01-01'],
            ['queue_date' => '2999-01-01'], ['status' => 'skipped'], ['status' => 'done'], ['status' => 'no_show']] as $change) {
            $this->insert(array_replace(['priority' => 'emergency'], $change));
        }
        $this->progress(7, 6, null);
        $this->fixture->table('queue_entries')->where('id', $own)->update(['priority' => 'urgent']);
        $this->progress(4, 3, null);
        $this->fixture->table('queue_entries')->where('id', $own)->update(['priority' => 'emergency']);
        $this->progress(3, 2, null);
    }

    public function testCalledConsultingSkippedAndTerminalStatusesPreserveOwnScopeAndLimit(): void
    {
        $own = $this->insert(['person_id' => 88, 'ticket_code' => 'OWN']);
        $this->insert();
        foreach (['student', 'employee'] as $role) {
            foreach (['called', 'in_consultation', 'skipped'] as $status) {
                $this->fixture->table('queue_entries')->where('id', $own)->update(['status' => $status]);
                $this->progress(null, $status === 'skipped' ? null : 0, $status === 'skipped' ? null : 0, $role);
            }
        }
        foreach (['done', 'no_show'] as $status) {
            $this->fixture->table('queue_entries')->where('id', $own)->update(['status' => $status]);
            self::assertSame([], $this->snapshot()['tickets']);
        }
        foreach (['OLD', 'NEW', 'NEWEST'] as $code) $this->insert(['person_id' => 88, 'ticket_code' => $code]);
        $this->insert(['person_id' => 88, 'unit' => 'other']);
        $this->insert(['person_id' => 88, 'tenant_id' => 8]);
        $this->insert(['person_id' => 88, 'queue_date' => '2000-01-01']);
        self::assertSame(['NEWEST', 'NEW'], array_column($this->snapshot()['tickets'], 'ticket_code'));
    }

    public function testChangingCountsUseMedianAndEffectiveLanesWithoutFixedFallback(): void
    {
        $this->insert(['person_id' => 88, 'ticket_code' => 'OWN', 'ticket_no' => 50]);
        $this->progress(1, 0, 0, 'employee');
        $peer = $this->insert();
        $this->progress(2, 1, null);
        $this->history([5, 10]);
        $this->progress(2, 1, null);
        $this->history([20]);
        $this->progress(2, 1, 10);
        $first = $this->insert(['status' => 'in_consultation']);
        $second = $this->insert(['status' => 'in_consultation']);
        $this->progress(4, 3, 15);
        $this->fixture->table('queue_entries')->where('id', $peer)->update(['status' => 'skipped']);
        $this->progress(3, 2, 10);
        $this->fixture->table('queue_entries')->whereIn('id', [$first, $second])->update(['status' => 'no_show']);
        $this->progress(1, 0, 0);
    }

    public function testHistoryIsScopedRecentStrictLocalPositiveAndAtMostFourHours(): void
    {
        $this->insert(['person_id' => 88, 'ticket_code' => 'OWN', 'ticket_no' => 50]);
        $this->insert();
        $this->history([5, 10], ['tenant_id' => 8]);
        $this->history([5, 10], ['unit' => 'guidance']);
        $this->history([5, 10], ['status' => 'skipped']);
        $this->history([5, 10], ['started_at' => '2000-01-01 00:00:00', 'completed_at' => '2000-01-01 01:00:00']);
        $this->history([5, 10], ['started_at' => '2999-01-01 00:00:00', 'completed_at' => '2999-01-01 01:00:00']);
        $this->history([0, 241]);
        $this->history([10], ['started_at' => null]);
        $this->history([10], ['started_at' => '2026-02-30 00:00:00']);
        $this->history([10], ['completed_at' => null]);
        $this->history([10], ['started_at' => SchedulingClock::timestamp()]); // Negative duration.
        $this->progress(2, 1, null);
        $this->history([1, 240]);
        $this->progress(2, 1, null);
        $this->history([2]);
        $original = date_default_timezone_get();
        try {
            date_default_timezone_set('America/New_York');
            $this->progress(2, 1, 2);
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function testEvenMedianKeepsFractionalMinutesUntilFinalCeiling(): void
    {
        $this->insert(['person_id' => 88, 'ticket_code' => 'OWN', 'ticket_no' => 50]);
        $this->insert();
        $this->history([1, 2, 3, 100]);
        $this->progress(2, 1, 3);
    }

    public function testEachOwnUnitUsesItsOwnHistoryAndCountsWithoutWriting(): void
    {
        $this->insert(['person_id' => 88, 'ticket_code' => 'CLINIC', 'ticket_no' => 50]);
        $this->insert(['person_id' => 88, 'ticket_code' => 'GUIDANCE', 'unit' => 'guidance', 'ticket_no' => 50]);
        $this->insert();
        $this->insert(['unit' => 'guidance', 'status' => 'called']);
        $this->history([5, 10, 20]);
        $this->history([20, 30, 40], ['unit' => 'guidance']);
        $before = $this->fixture->table('queue_entries')->orderBy('id')->get()->getResultArray();
        $tickets = $this->snapshot('employee')['tickets'];
        self::assertSame(['GUIDANCE', 'CLINIC'], array_column($tickets, 'ticket_code'));
        self::assertSame([2, 2], array_column($tickets, 'position'));
        self::assertSame([1, 1], array_column($tickets, 'people_ahead'));
        self::assertSame([30, 10], array_column($tickets, 'estimated_wait_minutes'));
        self::assertSame($before, $this->fixture->table('queue_entries')->orderBy('id')->get()->getResultArray());
    }

    public function testOnlyLatestFiftyCandidatesAreUsedAndInvalidSamplesDoNotBackfill(): void
    {
        $this->insert(['person_id' => 88, 'ticket_code' => 'OWN', 'ticket_no' => 50]);
        $this->insert();
        $this->history(array_fill(0, 51, 100), ['completed_at' => SchedulingClock::now()->modify('-2 hours')->format('Y-m-d H:i:s'),
            'started_at' => SchedulingClock::now()->modify('-200 minutes')->format('Y-m-d H:i:s')]);
        $end = SchedulingClock::now()->modify('-1 hour');
        $this->history(array_fill(0, 50, 7), ['completed_at' => $end->format('Y-m-d H:i:s'),
            'started_at' => $end->modify('-7 minutes')->format('Y-m-d H:i:s')]);
        $this->progress(2, 1, 7);
        $this->history(array_fill(0, 48, 0), ['started_at' => $end->format('Y-m-d H:i:s'),
            'completed_at' => $end->format('Y-m-d H:i:s')]); // Same completion time: id breaks ties, leaving just two valid samples.
        $this->progress(2, 1, null);
    }
}
