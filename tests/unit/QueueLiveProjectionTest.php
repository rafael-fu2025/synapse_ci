<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PortalQueueService;
use App\Services\QueueDashboardService;
use App\Services\Scheduling\PersonalQueueInvalidation;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;

/** Private SQLite data and fake identities/transport only; no application DB writes. */
final class QueueLiveProjectionTest extends CIUnitTestCase
{
    private function db(): \CodeIgniter\Database\BaseConnection
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $db->query('CREATE TABLE queue_entries (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, queue_date TEXT, ticket_code TEXT, status TEXT, priority TEXT)');
        return $db;
    }

    private function actor(): array
    {
        return ['id' => 3, 'tenant_id' => 7, 'person_id' => 88, 'role' => 'student', 'logged_in' => true,
            'permissions' => ['portal.records.read', 'portal.appointments.read']];
    }

    public function testOwnProjectionExcludesOtherPeopleTenantsDatesAndTerminalTickets(): void
    {
        $db = $this->db();
        $base = ['tenant_id' => 7, 'person_id' => 88, 'unit' => 'clinic', 'queue_date' => SchedulingClock::today(), 'ticket_code' => 'C-001', 'status' => 'waiting', 'priority' => 'routine'];
        foreach ([[], ['person_id' => 89], ['tenant_id' => 8], ['queue_date' => '2000-01-01'], ['status' => 'done'], ['status' => 'no_show'], ['unit' => 'guidance', 'status' => 'skipped', 'ticket_code' => 'G-001']] as $change) {
            $db->table('queue_entries')->insert(array_replace($base, $change));
        }
        $snapshot = (new PortalQueueService($db, fn () => $this->actor(), static fn () => 7))->snapshot();
        self::assertSame(SchedulingClock::today(), $snapshot['date']);
        self::assertSame(['G-001', 'C-001'], array_column($snapshot['tickets'], 'ticket_code'));
        foreach ($snapshot['tickets'] as $ticket) self::assertSame(['unit', 'ticket_code', 'status', 'priority'], array_keys($ticket));
        $db->close();
    }

    public function testPersonalSnapshotRequiresFreshPersonTenantAndBothPortalPermissions(): void
    {
        foreach ([null, ['person_id' => null], ['tenant_id' => 8], ['logged_in' => false], ['role' => 'outsider'], ['permissions' => ['portal.appointments.read']], ['permissions' => ['portal.records.read']]] as $change) {
            $actor = $change === null ? null : array_replace($this->actor(), $change);
            try {
                (new PortalQueueService(null, static fn () => $actor, static fn () => 7))->snapshot();
                self::fail('Invalid identity reached data access');
            } catch (RuntimeException $failure) {
                self::assertSame($actor === null ? 401 : 403, $failure->getCode());
            }
        }
    }

    public function testDashboardProjectionKeepsExistingTodayAndStatusSemantics(): void
    {
        $db = $this->db();
        $base = ['tenant_id' => 7, 'person_id' => 88, 'unit' => 'clinic', 'queue_date' => SchedulingClock::today(), 'ticket_code' => 'C-001', 'status' => 'waiting', 'priority' => 'routine'];
        foreach ([[], ['status' => 'called'], ['status' => 'done'], ['status' => 'in_consultation'], ['status' => 'skipped'], ['tenant_id' => 8], ['queue_date' => '2000-01-01'], ['unit' => 'guidance']] as $change) {
            $db->table('queue_entries')->insert(array_replace($base, $change));
        }
        $actor = array_replace($this->actor(), ['role' => 'superadmin']);
        $service = new QueueDashboardService($db, static fn () => $actor, static fn () => 7);
        self::assertSame(['queueWaiting' => 1, 'queueCalled' => 1, 'queueCompleted' => 1, 'queueToday' => 2], $service->snapshot('clinic')['counts']);
        self::assertSame(['queueToday' => 1], $service->snapshot('guidance')['counts']);
        $db->close();
    }

    public function testDashboardCannotBypassCombinedDatasetPermissionsOrDepartment(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $permissions = $unit === 'clinic' ? ['clinic.queue.read', 'clinic.encounters.read', 'clinic.appointments.read', 'clinic.inventory.read'] : ['guidance.cases.read', 'guidance.queue.read', 'referrals.read'];
            $actor = array_replace($this->actor(), ['role' => $unit === 'clinic' ? 'clinic_staff' : 'counsellor', 'permissions' => $permissions]);
            foreach (array_merge([null, ['tenant_id' => 8], ['role' => 'student'], ['logged_in' => false]], array_map(static fn ($permission) => ['permissions' => array_values(array_diff($permissions, [$permission]))], $permissions)) as $change) {
                $identity = $change === null ? null : array_replace($actor, $change);
                try {
                    (new QueueDashboardService(null, static fn () => $identity, static fn () => 7))->snapshot($unit);
                    self::fail('Invalid dashboard identity reached data access');
                } catch (RuntimeException $failure) {
                    self::assertSame($identity === null ? 401 : 403, $failure->getCode());
                }
            }
        }
    }

    public function testPrivateHintsOnlyReachTheChangedPersonsFreshAuthorizedAccounts(): void
    {
        $valid = $this->actor();
        $identities = [3 => $valid, 4 => array_replace($valid, ['id' => 4, 'person_id' => 89]), 5 => array_replace($valid, ['id' => 5, 'tenant_id' => 8]), 6 => array_replace($valid, ['id' => 6, 'permissions' => []])];
        $sent = [];
        $service = new PersonalQueueInvalidation(static function ($tenant, $person) {
            self::assertSame([7, 88], [$tenant, $person]);
            return [['id' => 3], ['id' => 4], ['id' => 5], ['id' => 6]];
        }, static fn ($id) => $identities[$id], static function (...$args) use (&$sent) { $sent[] = $args; }, true);
        $service->publish(7, 88);
        self::assertSame([['private.tenant.7.user.3', 'portal.queue.changed', []]], $sent);
    }

    public function testAppointmentListHintUsesItsOwnEventAndRejectsUnknownEvents(): void
    {
        $actor = $this->actor();
        $sent = [];
        $service = new PersonalQueueInvalidation(static fn () => [['id' => 3]], static fn () => $actor,
            static function (...$args) use (&$sent) { $sent[] = $args; return true; }, true);
        $service->publish(7, 88, PersonalQueueInvalidation::APPOINTMENTS_EVENT);
        $service->publish(7, 88, 'notifications.changed');
        self::assertSame([['private.tenant.7.user.3', 'portal.appointments.changed', []]], $sent);
    }

    public function testDisabledInvalidAndFailedPersonalFanoutNeverEscapesOrLoops(): void
    {
        $unexpected = static function () { self::fail('No audience expected'); };
        (new PersonalQueueInvalidation($unexpected, $unexpected, $unexpected, false))->publish(7, 88);
        $service = new PersonalQueueInvalidation($unexpected, $unexpected, $unexpected, true);
        $service->publish(0, 88); $service->publish(7, 0);
        $actor = $this->actor();
        $calls = 0;
        (new PersonalQueueInvalidation(static fn () => [['id' => 3], ['id' => 3]], static fn () => $actor,
            static function () use (&$calls) { ++$calls; return false; }, true))->publish(7, 88);
        self::assertSame(1, $calls);
        (new PersonalQueueInvalidation(static function () { throw new RuntimeException('unavailable'); }, $unexpected, $unexpected, true))->publish(7, 88);
    }

    public function testPrivateFanoutBudgetIncludesFreshIdentityWork(): void
    {
        $now = 0.0;
        $sent = [];
        $actor = $this->actor();
        $service = new PersonalQueueInvalidation(
            static fn () => array_map(static fn ($id) => ['id' => $id], range(1, 100)),
            static function ($id) use (&$now, $actor) { $now += 0.4; return array_replace($actor, ['id' => $id]); },
            static function ($channel) use (&$sent, &$now) { $sent[] = $channel; $now += 0.25; return true; },
            true, static function () use (&$now) { return $now; },
        );
        $service->publish(7, 88);
        self::assertSame(['private.tenant.7.user.1'], $sent);
    }

    public function testPersonalCardEscapesInitialTicketAndAlwaysKeepsAnEmptyLiveTarget(): void
    {
        $empty = view('portal/_queue_tickets', ['activeTickets' => []]);
        self::assertStringContainsString('data-personal-queue-tickets', $empty);
        self::assertStringContainsString('No active waiting room tickets today.', $empty);
        $html = view('portal/_queue_tickets', ['activeTickets' => [['unit' => 'clinic', 'ticket_code' => '<script>bad()</script>', 'status' => 'skipped', 'priority' => 'routine']]]);
        self::assertStringNotContainsString('<script>bad()', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Skipped', $html);
    }

    public function testRoutesAndViewsHaveReadOnlyScopedRefreshHooks(): void
    {
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        self::assertStringContainsString("get('portal/queue', 'PortalQueueController::index')", $routes);
        self::assertStringContainsString("'/dashboard/queue-counts', 'QueueDashboardController::'", $routes);
        self::assertStringContainsString('portal_access:', $routes);
        foreach (['clinic/dashboard', 'guidance/dashboard'] as $view) {
            $html = file_get_contents(APPPATH . 'Views/' . $view . '.php');
            self::assertStringContainsString('data-queue-dashboard=', $html);
            self::assertStringContainsString('assets/js/queue-live.js', $html);
        }
        self::assertStringContainsString('data-personal-queue', file_get_contents(APPPATH . 'Views/portal/_queue_tickets.php'));
    }
}
