<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnnouncementService;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyService;
use App\Services\NotificationInvalidation;
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
        $state = (object) ['depth' => 0, 'healthy' => true, 'commit' => true, 'begin' => true, 'order' => [], 'ids' => []];
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
        return [$service, $invalidation, $state];
    }

    private function runTransaction(object $service, NotificationInvalidation $invalidation, callable $operation): mixed
    {
        return (new ReflectionMethod($service, 'transaction'))->invoke($service, $operation, $invalidation);
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
}
