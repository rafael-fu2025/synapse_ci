<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\QueueController;
use App\Models\AvailabilityExceptionModel;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\QueueService;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/** Private guards and HTTP rejection paths only: no models constructed, migrations or DB fixtures. */
final class SchedulingAuditTest extends CIUnitTestCase
{
    private function invoke(string $class, string $method, mixed ...$args): mixed
    {
        $object = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        return (new ReflectionMethod($class, $method))->invoke($object, ...$args);
    }

    public static function queueStates(): iterable
    {
        foreach (['waiting', 'called', 'skipped', 'in_consultation', 'done', 'no_show'] as $state) {
            yield $state => [$state, in_array($state, ['waiting', 'called', 'skipped'], true)];
        }
    }

    #[DataProvider('queueStates')]
    public function testOnlyUnstartedNonterminalTicketsCanBeNoShow(string $state, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionCode(409);
        }
        $this->invoke(QueueService::class, 'assertNoShowEntry', ['status' => $state, 'queue_date' => SchedulingClock::today()]);
        if ($allowed) {
            $this->addToAssertionCount(1);
        }
    }

    public static function unsafeTickets(): iterable
    {
        yield 'started despite stale status' => [['started_at' => '2020-01-01 08:00:00']];
        yield 'completed despite stale status' => [['completed_at' => '2020-01-01 08:30:00']];
        yield 'future ticket' => [['queue_date' => '9999-12-31']];
    }

    #[DataProvider('unsafeTickets')]
    public function testUnsafeTicketIsRejected(array $changes): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invoke(QueueService::class, 'assertNoShowEntry', array_replace(['status' => 'waiting', 'queue_date' => SchedulingClock::today()], $changes));
    }

    public static function appointmentStates(): iterable
    {
        foreach (['scheduled', 'confirmed', 'checked_in', 'requested', 'cancelled', 'completed', 'no_show'] as $state) {
            yield $state => [$state, in_array($state, ['scheduled', 'confirmed', 'checked_in'], true)];
        }
    }

    #[DataProvider('appointmentStates')]
    public function testAppointmentNoShowStateGuard(string $state, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(\RuntimeException::class);
        }
        $this->invoke(QueueService::class, 'assertNoShowAppointment', ['status' => $state, 'version' => 3,
            'local_date' => '2020-01-01', 'starts_at' => '2020-01-01 08:00:00'], 3);
        if ($allowed) {
            $this->addToAssertionCount(1);
        }
    }

    public static function unsafeAppointments(): iterable
    {
        yield 'stale version' => [['version' => 4], 3];
        yield 'missing version' => [[], 0];
        yield 'future date' => [['local_date' => '9999-12-31'], 3];
        yield 'not yet due today' => [['local_date' => SchedulingClock::today(), 'starts_at' => '9999-12-31 23:59:59'], 3];
    }

    #[DataProvider('unsafeAppointments')]
    public function testNoShowRejectsStaleOrNotDueAppointment(array $changes, int $version): void
    {
        $this->expectException(\RuntimeException::class);
        $this->invoke(QueueService::class, 'assertNoShowAppointment', array_replace(['status' => 'confirmed', 'version' => 3,
            'local_date' => '2020-01-01', 'starts_at' => '2020-01-01 08:00:00'], $changes), $version);
    }

    public static function invalidDates(): iterable
    {
        foreach (['2026-02-30', '2026-13-01', 'tomorrow', '0', '2026-1-01', ['2026-10-19']] as $date) {
            yield [$date];
        }
    }

    #[DataProvider('invalidDates')]
    public function testBadBoardDateReturns422BeforeServiceAccess(mixed $date): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('GET');
        $request->setHeader('HX-Request', 'true');
        $request->setGlobal('get', ['date' => $date]);
        $request->setGlobal('post', []);
        $response = new Response(config('App'));
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        foreach (['index', 'board'] as $method) {
            $controller = (new ReflectionClass(QueueController::class))->newInstanceWithoutConstructor();
            $controller->initController($request, $response, new NullLogger());
            $result = $controller->{$method}('clinic');
            self::assertSame(422, $result->getStatusCode());
            self::assertSame('Invalid queue date.', json_decode($result->getBody(), true)['error']);
        }
    }

    public function testInvalidMutationDateCannotExecuteTheOperation(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setHeader('HX-Request', 'true');
        $request->setGlobal('post', ['date' => ['invalid']]);
        $request->setGlobal('get', []);
        $response = new Response(config('App'));
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        $controller = (new ReflectionClass(QueueController::class))->newInstanceWithoutConstructor();
        $controller->initController($request, $response, new NullLogger());
        $called = false;
        $result = (new ReflectionMethod(QueueController::class, 'mutate'))->invoke($controller, 'clinic', static function () use (&$called): void {
            $called = true;
        });
        self::assertFalse($called);
        self::assertSame(422, $result->getStatusCode());
    }

    public function testExceptionDatesUseStrictManilaCalendarValidation(): void
    {
        $this->invoke(AppointmentService::class, 'validateDate', '2028-02-29');
        self::assertSame('Asia/Manila', SchedulingClock::timezone()->getName());
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke(AppointmentService::class, 'validateDate', '2026-02-29');
    }

    public function testFullDayExceptionsClearCustomHours(): void
    {
        foreach (['leave', 'closed_day'] as $type) {
            $payload = $this->invoke(AppointmentService::class, 'exceptionPayload', ['exception_type' => $type,
                'reason' => 'Department closure', 'custom_start_time' => '08:00', 'custom_end_time' => '09:00']);
            self::assertSame(1, $payload['is_unavailable']);
            self::assertNull($payload['custom_start_time']);
            self::assertNull($payload['custom_end_time']);
        }
    }

    public function testCustomHoursAreValidatedAndStoredAsManilaWallClockTimes(): void
    {
        $payload = $this->invoke(AppointmentService::class, 'exceptionPayload', ['exception_type' => 'custom_hours',
            'reason' => 'Short day', 'custom_start_time' => '08:30', 'custom_end_time' => '09:00']);
        self::assertSame(0, $payload['is_unavailable']);
        self::assertSame('08:30:00', $payload['custom_start_time']);
        self::assertSame('09:00:00', $payload['custom_end_time']);
    }

    public static function invalidExceptions(): iterable
    {
        yield 'unknown type' => [['exception_type' => 'holiday']];
        yield 'missing reason' => [['reason' => ' ']];
        yield 'long reason' => [['reason' => str_repeat('x', 256)]];
        yield 'invalid time' => [['custom_start_time' => '24:00']];
        yield 'overnight' => [['custom_start_time' => '18:00', 'custom_end_time' => '08:00']];
        yield 'too short' => [['custom_end_time' => '08:15']];
    }

    #[DataProvider('invalidExceptions')]
    public function testInvalidExceptionInputIsRejected(array $changes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->invoke(AppointmentService::class, 'exceptionPayload', array_replace(['exception_type' => 'custom_hours',
            'reason' => 'Short day', 'custom_start_time' => '08:00', 'custom_end_time' => '09:00'], $changes));
    }

    public function testSnapshotTokenDetectsEveryPersistedExceptionChange(): void
    {
        $row = ['id' => 1, 'tenant_id' => 2, 'staff_assignment_id' => 3, 'exception_date' => '2026-10-19',
            'is_unavailable' => 1, 'custom_start_time' => null, 'custom_end_time' => null, 'reason' => 'Leave', 'updated_at' => '2026-10-19 08:00:00'];
        $token = AvailabilityExceptionModel::snapshotToken($row);
        foreach ($row as $key => $value) {
            self::assertNotSame($token, AvailabilityExceptionModel::snapshotToken(array_replace($row, [$key => 'changed'])));
        }
        self::assertSame($token, AvailabilityExceptionModel::snapshotToken(array_replace($row, ['id' => '1'])));
    }
}
