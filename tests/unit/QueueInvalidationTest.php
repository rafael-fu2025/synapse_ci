<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\QueueInvalidation;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/** No database connections: audience and transaction boundaries use explicit doubles. */
final class QueueInvalidationTest extends CIUnitTestCase
{
    public function testAudienceUsesFreshSameTenantBoardReadAuthorityAndMetadataOnly(): void
    {
        foreach (['clinic' => 'clinic_staff', 'guidance' => 'counsellor'] as $unit => $role) {
            $actor = ['id' => 1, 'tenant_id' => 7, 'logged_in' => true, 'role' => $role, 'permissions' => [$unit . '.queue.read']];
            $actors = [
                1 => $actor,
                2 => array_replace($actor, ['id' => 2, 'permissions' => [$unit . '.queue.manage']]),
                3 => array_replace($actor, ['id' => 3, 'role' => 'student']),
                4 => null, // Disabled/deleted/unlinked identity rejected by AuthenticatedUser.
                5 => array_replace($actor, ['id' => 5, 'tenant_id' => 8]),
                6 => array_replace($actor, ['id' => 6, 'role' => 'superadmin', 'permissions' => []]),
                7 => array_replace($actor, ['id' => 7, 'logged_in' => false]),
            ];
            $sent = [];
            $service = new QueueInvalidation(
                static function (int $tenant, array $roles) use ($unit) {
                    self::assertSame(7, $tenant);
                    self::assertSame(\App\Services\PortalContext::allowedRoles($unit), $roles);
                    return array_map(static fn ($id) => ['id' => $id], [1, 2, 3, 4, 5, 6, 7, 1]);
                },
                static function (int $id, int $tenant) use ($actors) {
                    self::assertSame(7, $tenant);
                    return $actors[$id];
                },
                static function (...$args) use (&$sent) { $sent[] = $args; }, true,
            );
            $service->publish(7, $unit, '2026-10-22');
            self::assertSame([
                ['private.tenant.7.user.1', 'queue.invalidated', ['unit' => $unit, 'date' => '2026-10-22']],
                ['private.tenant.7.user.6', 'queue.invalidated', ['unit' => $unit, 'date' => '2026-10-22']],
            ], $sent);
        }
    }

    public function testDisabledAndInvalidHintsDoNotReadAudience(): void
    {
        $unexpected = static function () { self::fail('Audience must not be queried'); };
        (new QueueInvalidation($unexpected, $unexpected, $unexpected, false))->publish(7, 'clinic', '2026-10-22');
        $enabled = new QueueInvalidation($unexpected, $unexpected, $unexpected, true);
        $enabled->publish(0, 'clinic', '2026-10-22');
        $enabled->publish(7, 'other', '2026-10-22');
        $enabled->publish(7, 'clinic', 'invalid');
        self::assertTrue(true);
    }

    public function testAudienceAndPerRecipientFailuresAreBestEffort(): void
    {
        $fail = static function () { throw new RuntimeException('unavailable'); };
        (new QueueInvalidation($fail, $fail, $fail, true))->publish(7, 'clinic', '2026-10-22');
        $attempts = [];
        (new QueueInvalidation(
            static fn () => [['id' => 1], ['id' => 2], ['id' => 3]],
            static function ($id, $tenant) {
                if ($id === 1) throw new RuntimeException('identity unavailable');
                return ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'superadmin'];
            },
            static function ($channel) use (&$attempts) { $attempts[] = $channel; throw new RuntimeException('transport unavailable'); }, true,
        ))->publish(7, 'clinic', '2026-10-22');
        self::assertSame(['private.tenant.7.user.2'], $attempts);
    }

    public function testExplicitFalseStopsFanoutButVoidSendRemainsCompatible(): void
    {
        foreach ([false, null] as $result) {
            $sent = [];
            $service = new QueueInvalidation(
                static fn () => [['id' => 1], ['id' => 2], ['id' => 3]],
                static fn ($id, $tenant) => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'superadmin'],
                static function ($channel) use (&$sent, $result) { $sent[] = $channel; return $result; }, true,
            );
            $service->publish(7, 'clinic', '2026-10-22');
            self::assertCount($result === false ? 1 : 3, $sent);
        }
    }

    public function testElapsedBudgetIncludesAudienceAndFreshIdentityWork(): void
    {
        $now = 0.0;
        $checked = $sent = [];
        $service = new QueueInvalidation(
            static function () use (&$now) { $now += 0.2; return [['id' => 1], ['id' => 2], ['id' => 3]]; },
            static function ($id, $tenant) use (&$now, &$checked) {
                $now += 0.2;
                $checked[] = $id;
                return ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'superadmin'];
            },
            static function ($channel) use (&$now, &$sent) { $now += 0.25; $sent[] = $channel; return true; }, true,
            static function () use (&$now) { return $now; },
        );
        $service->publish(7, 'clinic', '2026-10-22');
        self::assertSame([1, 2], $checked);
        self::assertSame(['private.tenant.7.user.1'], $sent, 'Do not start a send without a full 250ms remaining');
        self::assertLessThanOrEqual(1.0, $now);
    }

    public function testAudienceExhaustingBudgetDoesNotRefreshOrPublish(): void
    {
        $now = 0.0;
        $checks = $sends = 0;
        (new QueueInvalidation(
            static function () use (&$now) { $now += 1.1; return [['id' => 1]]; },
            static function () use (&$checks) { ++$checks; return null; },
            static function () use (&$sends) { ++$sends; }, true,
            static function () use (&$now) { return $now; },
        ))->publish(7, 'clinic', '2026-10-22');
        self::assertSame(0, $checks);
        self::assertSame(0, $sends);
    }

    public function testAttemptAndCandidateBudgetsBoundFastFanout(): void
    {
        $rows = array_map(static fn ($id) => ['id' => $id], range(1, 100));
        foreach ([true, false] as $authorized) {
            $checks = $sends = 0;
            (new QueueInvalidation(
                static fn () => $rows,
                static function ($id, $tenant) use ($authorized, &$checks) {
                    ++$checks;
                    return $authorized ? ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'superadmin'] : null;
                },
                static function () use (&$sends) { ++$sends; return true; }, true, static fn () => 0.0,
            ))->publish(7, 'guidance', '2026-10-22');
            self::assertSame($authorized ? 4 : 64, $checks);
            self::assertSame($authorized ? 4 : 0, $sends);
        }
    }

    public function testDefaultPublisherUsesPrivateClonedConfigAndShortTimeout(): void
    {
        $shared = config(\Config\Realtime::class);
        $timeout = $shared->publishTimeout;
        $service = new QueueInvalidation(static fn () => [], static fn () => null, enabled: true);
        $send = (new ReflectionClass($service))->getProperty('send')->getValue($service);
        $publisher = (new \ReflectionFunction($send))->getClosureThis();
        $private = (new ReflectionClass($publisher))->getProperty('config')->getValue($publisher);
        self::assertNotSame($shared, $private);
        self::assertSame(min($timeout, 0.25), $private->publishTimeout);
        self::assertSame($timeout, $shared->publishTimeout);
    }

    private function transactionFixture(bool $owns, bool $commit, array &$order, bool $operationFails = false): QueueService
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->willReturnCallback(static fn (string $name) => $name === 'transDepth' ? ($owns ? 0 : 1) : null);
        $db->method('query')->willReturn(true);
        $db->method('transBegin')->willReturn(true);
        $db->method('transStatus')->willReturn(true);
        $db->expects($owns && !$operationFails ? self::once() : self::never())->method('transCommit')->willReturnCallback(
            static function () use (&$order, $commit) { $order[] = 'commit'; return $commit; }
        );
        $db->expects($owns && (!$commit || $operationFails) ? self::once() : self::never())->method('transRollback')->willReturn(true);
        $reflection = new ReflectionClass(QueueService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('db')->setValue($service, $db);
        $reflection->getProperty('invalidate')->setValue($service, static function (...$hint) use (&$order) {
            $order[] = $hint;
            throw new RuntimeException('A failing publisher cannot undo a commit');
        });
        return $service;
    }

    public function testCommitPrecedesDeduplicatedHintAndPublisherFailureDoesNotBreakWrite(): void
    {
        $order = [];
        $service = $this->transactionFixture(true, true, $order);
        $record = new ReflectionMethod(QueueService::class, 'recordInvalidation');
        $result = (new ReflectionMethod(QueueService::class, 'transaction'))->invoke($service, static function () use ($service, $record) {
            $entry = ['tenant_id' => 7, 'unit' => 'clinic', 'queue_date' => '2026-10-22', 'person_id' => 99, 'ticket_code' => 'C-001'];
            $record->invoke($service, $entry);
            $record->invoke($service, $entry);
            return $entry;
        });
        self::assertSame(99, $result['person_id']);
        self::assertSame(['commit', [7, 'clinic', '2026-10-22', 99]], $order);
    }

    public function testPeopleSharingADepartmentAndDateKeepSeparatePrivateHints(): void
    {
        $order = [];
        $service = $this->transactionFixture(true, true, $order);
        (new ReflectionMethod(QueueService::class, 'transaction'))->invoke($service, static function () use ($service) {
            $record = new ReflectionMethod(QueueService::class, 'recordInvalidation');
            foreach ([88, 99, 88] as $person) {
                $record->invoke($service, ['tenant_id' => 7, 'unit' => 'clinic', 'queue_date' => '2026-10-22', 'person_id' => $person]);
            }
            return [];
        });
        self::assertSame(['commit', [7, 'clinic', '2026-10-22', 88], [7, 'clinic', '2026-10-22', 99]], $order);
    }

    public function testFailedCommitDropsHints(): void
    {
        $order = [];
        $service = $this->transactionFixture(true, false, $order);
        try {
            (new ReflectionMethod(QueueService::class, 'transaction'))->invoke($service, static function () use ($service) {
                (new ReflectionMethod(QueueService::class, 'recordInvalidation'))->invoke($service,
                    ['tenant_id' => 7, 'unit' => 'guidance', 'queue_date' => '2026-10-22']);
                return [];
            });
            self::fail('Commit must fail');
        } catch (RuntimeException $error) {
            self::assertSame('Unable to commit queue transaction.', $error->getMessage());
        }
        self::assertSame(['commit'], $order);
        self::assertSame([], (new ReflectionClass($service))->getProperty('invalidations')->getValue($service));
    }

    public function testMutationFailureRollsBackAndDropsHints(): void
    {
        $order = [];
        $service = $this->transactionFixture(true, true, $order, true);
        try {
            (new ReflectionMethod(QueueService::class, 'transaction'))->invoke($service, static function () use ($service) {
                (new ReflectionMethod(QueueService::class, 'recordInvalidation'))->invoke($service,
                    ['tenant_id' => 7, 'unit' => 'clinic', 'queue_date' => '2026-10-22']);
                throw new RuntimeException('write failed');
            });
            self::fail('Mutation must fail');
        } catch (RuntimeException $error) {
            self::assertSame('write failed', $error->getMessage());
        }
        self::assertSame([], $order);
        self::assertSame([], (new ReflectionClass($service))->getProperty('invalidations')->getValue($service));
    }

    public function testCallerOwnedTransactionNeverPublishes(): void
    {
        $order = [];
        $service = $this->transactionFixture(false, true, $order);
        (new ReflectionMethod(QueueService::class, 'transaction'))->invoke($service, static function () use ($service) {
            (new ReflectionMethod(QueueService::class, 'recordInvalidation'))->invoke($service,
                ['tenant_id' => 7, 'unit' => 'guidance', 'queue_date' => '2026-10-22']);
            return [];
        });
        self::assertSame([], $order);
    }

    public function testNoMutationProducesNoHint(): void
    {
        $order = [];
        $service = $this->transactionFixture(true, true, $order);
        self::assertNull((new ReflectionMethod(QueueService::class, 'transaction'))->invoke($service, static fn () => null));
        self::assertSame(['commit'], $order);
    }
}
