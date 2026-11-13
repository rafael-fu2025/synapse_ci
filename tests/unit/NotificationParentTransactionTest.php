<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnnouncementService;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyService;
use App\Services\NotificationInvalidation;
use App\Services\Push\PushPublisher;
use App\Services\Referrals\ReferralService;
use App\Services\Scheduling\AppointmentService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;


/** Exercise the real parent transaction wrappers with mocked storage and transport only. */
final class NotificationParentTransactionTest extends CIUnitTestCase
{
    private array $connections;
    private array $savedSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connections = (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->getValue();
        $this->savedSession = session()->get();
        session()->set([
            'tenant_id' => 7,
            'user' => ['id' => 1, 'tenant_id' => 7, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()],
        ]);
        CurrentTenant::set(7);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        session()->remove(array_keys(session()->get()));
        session()->set($this->savedSession);
        CurrentTenant::reset();
        parent::tearDown();
    }

    public static function owners(): array
    {
        return [
            'appointment confirmation' => [AppointmentService::class],
            'referral fanout' => [ReferralService::class],
            'announcement publication' => [AnnouncementService::class],
            'survey publication' => [SurveyService::class],
        ];
    }

    private function databaseResult(?array $row, array $rows = []): BaseResult
    {
        $result = $this->createMock(BaseResult::class);
        $result->method('getRowArray')->willReturn($row);
        $result->method('getResultArray')->willReturn($rows);
        return $result;
    }

    private function fixture(string $owner): array
    {
        $state = (object) ['depth' => 0, 'healthy' => true, 'commit' => true, 'begin' => true, 'order' => [], 'ids' => [],
            'pushBatches' => [], 'pushSent' => [], 'authorized' => []];
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->willReturnCallback(static fn ($name) => match ($name) {
            'transDepth' => $state->depth, 'DBDriver' => 'MySQLi', default => null,
        });
        $db->method('transBegin')->willReturnCallback(static function () use ($state) {
            if ($state->begin) ++$state->depth;
            return $state->begin;
        });
        $db->method('transStatus')->willReturnCallback(static fn () => $state->healthy);
        $db->method('transCommit')->willReturnCallback(static function () use ($state) {
            $state->order[] = 'commit';
            if ($state->commit) --$state->depth;
            return $state->commit;
        });
        $db->method('transRollback')->willReturnCallback(static function () use ($state) {
            $state->order[] = 'rollback'; $state->depth = 0; return true;
        });
        $db->method('resetTransStatus')->willReturnCallback(static function () use ($state, $db) {
            $state->healthy = true; return $db;
        });
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $tenant = $this->databaseResult(['id' => 7]);
        $db->method('query')->willReturnCallback(static function ($sql) use ($tenant) {
            if ($sql === 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED') return true;
            if ($sql === 'SELECT id FROM tenants WHERE id = ? FOR UPDATE') return $tenant;
            throw new RuntimeException('Unexpected query: ' . $sql);
        });
        // Real EngagementAccess/AuthenticatedUser checks still run for the publication owners.
        $rows = [
            'tenants' => ['id' => 7],
            'users' => ['id' => 1, 'tenant_id' => 7, 'person_id' => null, 'role' => 'superadmin',
                'username' => 'synthetic-owner', 'email' => null, 'auth_version' => 1],
            'role_permissions rp' => null,
        ];
        $builders = [];
        foreach ($rows as $table => $row) {
            $builder = $this->createMock(BaseBuilder::class);
            foreach (['where', 'select', 'join'] as $method) $builder->method($method)->willReturnSelf();
            $builder->method('get')->willReturn($this->databaseResult($row));
            $builders[$table] = $builder;
        }
        $db->method('table')->willReturnCallback(static function ($table) use ($builders) {
            if (!isset($builders[$table])) throw new RuntimeException('Unexpected table: ' . $table);
            return $builders[$table];
        });
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, ['tests' => $db]);
        $class = new ReflectionClass($owner);
        $service = $class->newInstanceWithoutConstructor();
        if ($owner === AnnouncementService::class) $class->getProperty('db')->setValue($service, $db);
        $invalidation = new NotificationInvalidation($db,
            static function ($tenant, $ids) use ($state) {
                self::assertSame(0, $state->depth, 'Audience resolution must happen after the outer commit');
                self::assertSame(7, $tenant);
                $state->ids[] = $ids;
                return [['id' => 5, 'tenant_id' => 7]];
            },
            static fn () => true,
            static function (...$hint) use ($state) {
                $state->order[] = $hint;
                throw new RuntimeException('Synthetic publisher outage after commit');
            }, true,
        );
        return [$service, $invalidation, $state, $db];
    }

    private function runTransaction(object $service, NotificationInvalidation $invalidation, callable $operation, ?PushPublisher $push = null): mixed
    {
        $transaction = new ReflectionMethod($service, 'transaction');
        if ($service instanceof SurveyService) {
            // Never construct the deployment-configured publisher in these synthetic parent tests.
            $push ??= new PushPublisher(\Config\Database::connect(), static fn (): array => [],
                static fn (): bool => false, static fn (): bool => true, false, static fn (): float => 0.0);
            return $transaction->invoke($service, $operation, $invalidation, $push);
        }
        return $transaction->invoke($service, $operation, $invalidation);
    }

    #[DataProvider('owners')]
    public function testOnlyNewIdsPublishAfterCommitAndTransportFailurePreservesResult(string $owner): void
    {
        [$service, $invalidation, $state] = $this->fixture($owner);
        $result = $this->runTransaction($service, $invalidation, static function (array &$notifications) use ($state) {
            self::assertSame(1, $state->depth);
            self::assertSame([], $state->order);
            $notifications[7] = [31, 32, 31];
            return ['unchanged' => 'business result'];
        });
        self::assertSame(['unchanged' => 'business result'], $result);
        self::assertSame([[31, 32]], $state->ids);
        self::assertSame(['commit', ['private.tenant.7.user.5', 'notifications.changed', []]], $state->order);
        // Reusing the same service and publisher must not replay the previous IDs.
        $state->order = []; $state->ids = [];
        $this->runTransaction($service, $invalidation, static function (array &$notifications): array {
            self::assertSame([], $notifications); $notifications[7] = []; return [];
        });
        self::assertSame(['commit'], $state->order);
        self::assertSame([], $state->ids);
    }

    #[DataProvider('owners')]
    public function testWriteAndCommitFailuresDiscardIdsWithoutLeakingIntoNextOperation(string $owner): void
    {
        foreach (['write', 'status', 'commit'] as $failure) {
            [$service, $invalidation, $state] = $this->fixture($owner);
            $state->commit = $failure !== 'commit';
            try {
                $this->runTransaction($service, $invalidation, static function (array &$notifications) use ($state, $failure): array {
                    $notifications[7] = [99];
                    if ($failure === 'write') throw new RuntimeException('Synthetic write failure');
                    if ($failure === 'status') $state->healthy = false;
                    return [];
                });
                self::fail('Transaction must fail');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertSame($failure === 'commit' ? ['commit', 'rollback'] : ['rollback'], $state->order);
            self::assertSame([], $state->ids);
            $state->commit = true; $state->order = [];
            $this->runTransaction($service, $invalidation, static function (array &$notifications): array {
                self::assertSame([], $notifications); return [];
            });
            self::assertSame(['commit'], $state->order);
            self::assertSame([], $state->ids);
        }
    }

    #[DataProvider('owners')]
    public function testOperationsWithoutNotificationsKeepTheirOriginalCallbackAndReturnContract(string $owner): void
    {
        [$service, $invalidation, $state] = $this->fixture($owner);
        // Existing operations accept no collector, and survey callbacks can return void.
        $operation = $owner === SurveyService::class ? static function (): void {} : static fn (): array => ['saved' => true];
        $result = $this->runTransaction($service, $invalidation, $operation);
        self::assertSame($owner === SurveyService::class ? null : ['saved' => true], $result);
        self::assertSame(['commit'], $state->order);
        self::assertSame([], $state->ids);
    }

    #[DataProvider('owners')]
    public function testNestedAndFailedBeginNeverRunBusinessOperationOrPublish(string $owner): void
    {
        foreach (['nested', 'begin'] as $failure) {
            [$service, $invalidation, $state] = $this->fixture($owner);
            $state->depth = $failure === 'nested' ? 1 : 0;
            $state->begin = false;
            try {
                $this->runTransaction($service, $invalidation, static function (): array {
                    self::fail('Business operation must not run');
                });
                self::fail('Transaction must fail');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertSame([], $state->order);
            self::assertSame([], $state->ids);
        }
    }

    private function surveyPush(BaseConnection $db, object $state, string $failure = '', ?array $rows = null, ?array $allowedUsers = null): PushPublisher
    {
        if ($failure === 'escaping error') {
            // An initialization error escapes the publisher's internal best-effort guards.
            return (new ReflectionClass(PushPublisher::class))->newInstanceWithoutConstructor();
        }
        return new PushPublisher($db,
            static function ($tenant, $ids) use ($state, $failure, $rows): array {
                $state->order[] = 'push.resolve';
                $state->pushBatches[] = [$tenant, $ids, $state->depth, $state->healthy];
                if ($failure === 'audience exception') throw new RuntimeException('Synthetic push audience failure');
                return $rows ?? array_map(static fn ($id): array => [
                    'id' => $id, 'tenant_id' => $tenant, 'recipient_user_id' => $id - 26,
                    'type' => 'survey_published', 'survey_id' => 42,
                    'title' => 'PRIVATE synthetic survey title', 'message' => 'PRIVATE synthetic survey details',
                ], $ids);
            },
            static function ($tenant, $user, $ids) use ($state, $allowedUsers): bool {
                $state->authorized[] = [$tenant, $user, $ids, $state->depth];
                return $allowedUsers === null || in_array($user, $allowedUsers, true);
            },
            static function ($tenant, $user, $message) use ($state, $failure): bool {
                $state->order[] = 'push.send';
                $state->pushSent[] = [$tenant, $user, $message, $state->depth, $state->healthy];
                if ($failure === 'transport exception') throw new RuntimeException('Synthetic push transport failure');
                return $failure !== 'transport rejection';
            }, true, static fn (): float => 0.0,
        );
    }

    private function surveyRealtime(BaseConnection $db, object $state, string $failure = ''): NotificationInvalidation
    {
        if ($failure === 'escaping error') {
            return (new ReflectionClass(NotificationInvalidation::class))->newInstanceWithoutConstructor();
        }
        return new NotificationInvalidation($db,
            static function ($tenant, $ids) use ($state, $failure): array {
                $state->order[] = 'realtime.resolve';
                $state->ids[] = [$tenant, $ids, $state->depth, $state->healthy];
                if ($failure === 'audience exception') throw new RuntimeException('Synthetic realtime audience failure');
                return [['id' => 5, 'tenant_id' => $tenant]];
            }, static fn (): bool => true,
            static function () use ($state, $failure): bool {
                $state->order[] = 'realtime.send';
                if ($failure === 'transport exception') throw new RuntimeException('Synthetic realtime transport failure');
                return true;
            }, true, static fn (): float => 0.0,
        );
    }

    private function clearSurveyEmissions(object $state): void
    {
        foreach (['order', 'ids', 'pushBatches', 'authorized', 'pushSent'] as $field) $state->{$field} = [];
    }

    public function testSurveyPushOnlyUsesNewIdsAfterOuterCommitAndNeverReplaysThem(): void
    {
        [$service, , $state, $db] = $this->fixture(SurveyService::class);
        $invalidation = $this->surveyRealtime($db, $state);
        $push = $this->surveyPush($db, $state);
        $result = $this->runTransaction($service, $invalidation, static function (array &$notifications) use ($state): array {
            self::assertSame(1, $state->depth);
            self::assertSame([], $state->order);
            $notifications[7] = [31, 32, 31];
            return ['survey_id' => 42, 'status' => 'published'];
        }, $push);
        self::assertSame(['survey_id' => 42, 'status' => 'published'], $result);
        self::assertSame([[7, [31, 32], 0, true]], $state->ids);
        self::assertSame([[7, [31, 32], 0, true]], $state->pushBatches);
        self::assertSame([[7, 5, [31], 0], [7, 6, [32], 0]], $state->authorized);
        self::assertSame(['commit', 'realtime.resolve', 'realtime.send', 'push.resolve', 'push.send', 'push.send'], $state->order);
        self::assertSame([5, 6], array_column($state->pushSent, 1));
        self::assertSame([0, 0], array_column($state->pushSent, 3));
        self::assertSame([true, true], array_column($state->pushSent, 4));

        // Reuse the service and both successful publishers: a no-op must not replay previous IDs.
        $this->clearSurveyEmissions($state);
        self::assertSame([], $this->runTransaction($service, $invalidation, static function (array &$notifications): array {
            self::assertSame([], $notifications);
            $notifications[7] = [];
            return [];
        }, $push));
        self::assertSame(['commit'], $state->order);
        self::assertSame([], $state->ids);
        self::assertSame([], $state->pushBatches);
        self::assertSame([], $state->pushSent);

        $this->clearSurveyEmissions($state);
        self::assertNull($this->runTransaction($service, $invalidation, static function (): void {}, $push));
        self::assertSame(['commit'], $state->order);
        self::assertSame([], $state->ids);
        self::assertSame([], $state->pushBatches);
        self::assertSame([], $state->pushSent);
    }

    public static function surveyTransactionFailures(): array
    {
        return ['write failure' => ['write'], 'failed transaction status' => ['status'], 'failed outer commit' => ['commit']];
    }

    #[DataProvider('surveyTransactionFailures')]
    public function testSurveyRollbackDiscardsPushIdsAndNextTransactionStartsEmpty(string $failure): void
    {
        [$service, $invalidation, $state, $db] = $this->fixture(SurveyService::class);
        $push = $this->surveyPush($db, $state);
        $state->commit = $failure !== 'commit';
        try {
            $this->runTransaction($service, $invalidation, static function (array &$notifications) use ($state, $failure): array {
                $notifications[7] = [99];
                if ($failure === 'write') throw new RuntimeException('Synthetic survey write failure');
                if ($failure === 'status') $state->healthy = false;
                return [];
            }, $push);
            self::fail('The survey transaction must fail.');
        } catch (RuntimeException $error) {
            self::assertSame($failure === 'write' ? 'Synthetic survey write failure' : 'Unable to save survey.', $error->getMessage());
        }
        self::assertSame($failure === 'commit' ? ['commit', 'rollback'] : ['rollback'], $state->order);
        self::assertSame([], $state->pushBatches);
        self::assertSame([], $state->pushSent);
        self::assertSame([], $state->ids);
        self::assertSame(0, $state->depth);
        self::assertTrue($state->healthy);

        $state->commit = true;
        $this->clearSurveyEmissions($state);
        $this->runTransaction($service, $invalidation, static function (array &$notifications): void {
            self::assertSame([], $notifications);
        }, $push);
        self::assertSame(['commit'], $state->order);
        self::assertSame([], $state->pushBatches);
        self::assertSame([], $state->pushSent);

        // A subsequent real batch must contain only its own IDs, not those collected before rollback.
        $this->clearSurveyEmissions($state);
        $this->runTransaction($service, $invalidation, static function (array &$notifications): void {
            self::assertSame([], $notifications);
            $notifications[7] = [31];
        }, $push);
        self::assertSame([[7, [31], 0, true]], $state->pushBatches);
        self::assertCount(1, $state->pushSent);
        self::assertNotContains('rollback', $state->order);
    }

    public static function rejectedSurveyTransactions(): array
    {
        return ['caller-owned transaction' => ['nested'], 'failed begin' => ['begin']];
    }

    #[DataProvider('rejectedSurveyTransactions')]
    public function testSurveyRejectedTransactionNeverRunsOperationOrDispatchesPush(string $failure): void
    {
        [$service, $invalidation, $state, $db] = $this->fixture(SurveyService::class);
        $push = $this->surveyPush($db, $state);
        $state->depth = $failure === 'nested' ? 1 : 0;
        $state->begin = $failure !== 'begin';
        try {
            $this->runTransaction($service, $invalidation, static function (): void {
                self::fail('Rejected transaction must not run its operation.');
            }, $push);
            self::fail('The survey transaction must be rejected.');
        } catch (RuntimeException $error) {
            self::assertSame($failure === 'nested' ? 409 : 503, $error->getCode());
        }
        self::assertSame([], $state->order);
        self::assertSame([], $state->ids);
        self::assertSame([], $state->pushBatches);
        self::assertSame([], $state->pushSent);
        self::assertSame($failure === 'nested' ? 1 : 0, $state->depth);
    }

    public static function surveyRealtimeFailures(): array
    {
        return ['audience exception' => ['audience exception'], 'transport exception' => ['transport exception'],
            'escaping initialization error' => ['escaping error']];
    }

    #[DataProvider('surveyRealtimeFailures')]
    public function testSurveyRealtimeFailureDoesNotPreventCommittedPush(string $failure): void
    {
        [$service, , $state, $db] = $this->fixture(SurveyService::class);
        $result = $this->runTransaction($service, $this->surveyRealtime($db, $state, $failure),
            static function (array &$notifications): string {
                $notifications[7] = [31];
                return 'survey business result';
            }, $this->surveyPush($db, $state));
        self::assertSame('survey business result', $result);
        self::assertSame('commit', $state->order[0]);
        self::assertNotContains('rollback', $state->order);
        self::assertSame([[7, [31], 0, true]], $state->pushBatches);
        self::assertCount(1, $state->pushSent);
        self::assertSame(0, $state->pushSent[0][3]);
        self::assertSame(0, $state->depth);
        self::assertTrue($state->healthy);
    }

    public static function surveyPushFailures(): array
    {
        return ['audience exception' => ['audience exception'], 'transport rejection' => ['transport rejection'],
            'transport exception' => ['transport exception'], 'escaping initialization error' => ['escaping error']];
    }

    #[DataProvider('surveyPushFailures')]
    public function testSurveyPushFailureCannotChangeCommittedBusinessResult(string $failure): void
    {
        [$service, , $state, $db] = $this->fixture(SurveyService::class);
        $result = $this->runTransaction($service, $this->surveyRealtime($db, $state),
            static function (array &$notifications): array {
                $notifications[7] = [31, 32];
                return ['survey_id' => 42, 'status' => 'published'];
            }, $this->surveyPush($db, $state, $failure));
        self::assertSame(['survey_id' => 42, 'status' => 'published'], $result);
        self::assertSame('commit', $state->order[0]);
        self::assertNotContains('rollback', $state->order);
        self::assertContains('realtime.send', $state->order);
        self::assertSame($failure === 'escaping error' ? [] : [[7, [31, 32], 0, true]], $state->pushBatches);
        self::assertCount(str_starts_with($failure, 'transport') ? 1 : 0, $state->pushSent);
        self::assertSame(0, $state->depth);
        self::assertTrue($state->healthy);
    }

    public function testSurveyCommittedPushRetainsRecipientAuthorityAndGenericPayload(): void
    {
        [$service, , $state, $db] = $this->fixture(SurveyService::class);
        $row = ['tenant_id' => 7, 'type' => 'survey_published', 'survey_id' => 42,
            'title' => 'PRIVATE synthetic survey title', 'message' => 'PRIVATE synthetic survey details'];
        $rows = [
            ['id' => 31, 'recipient_user_id' => 5] + $row,
            ['id' => 32, 'recipient_user_id' => 6] + $row,
            ['id' => 33, 'recipient_user_id' => 5, 'tenant_id' => 8] + $row,
            ['id' => 34, 'recipient_user_id' => 0] + $row,
        ];
        $this->runTransaction($service, $this->surveyRealtime($db, $state), static function (array &$notifications): void {
            $notifications[7] = [31, 32, 33, 34];
        }, $this->surveyPush($db, $state, '', $rows, [5]));
        self::assertSame([[7, [31, 32, 33, 34], 0, true]], $state->pushBatches);
        self::assertSame([[7, 5, [31], 0], [7, 6, [32], 0]], $state->authorized);
        self::assertSame([[7, 5, [
            'title' => 'New survey',
            'body' => 'An identified Guidance survey is available in your portal.',
            'data' => ['type' => 'survey_published', 'id' => '42'],
        ], 0, true]], $state->pushSent);
    }
}
