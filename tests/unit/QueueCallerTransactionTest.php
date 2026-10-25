<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\QueueEntryModel;
use App\Services\Clinic\EncounterService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use App\Services\Scheduling\QueueService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

/** Real queue transitions and owning transaction code, with no database connection. */
final class QueueCallerTransactionTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function invoke(object $object, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    private function fixture(string $unit, string $status = 'called'): object
    {
        CurrentTenant::set(7);
        $state = (object) [
            'depth' => 0, 'commit' => true, 'healthy' => true, 'throwPublish' => false, 'order' => [], 'sent' => [],
            'ticket' => ['id' => 12, 'tenant_id' => 7, 'unit' => $unit, 'queue_date' => '2020-01-02',
                'person_id' => 88, 'appointment_id' => null, 'status' => $status],
        ];
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->willReturnCallback(static fn ($name) => match ($name) {
            'transDepth' => $state->depth,
            'DBDriver' => 'MySQLi',
            'dateFormat' => ['datetime' => 'Y-m-d H:i:s', 'date' => 'Y-m-d'],
            default => null,
        });
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $db->method('transBegin')->willReturnCallback(static function () use ($state) { ++$state->depth; return true; });
        $db->method('transStatus')->willReturnCallback(static fn () => $state->healthy);
        $db->method('transCommit')->willReturnCallback(static function () use ($state) {
            $state->order[] = 'commit';
            if ($state->commit) --$state->depth;
            // Post-commit publication must use captured metadata, not ambient context.
            CurrentTenant::set(99);
            return $state->commit;
        });
        $db->method('transRollback')->willReturnCallback(static function () use ($state) {
            $state->order[] = 'rollback';
            $state->depth = 0;
            return true;
        });
        $db->method('error')->willReturn(['code' => 0]);
        $row = $this->createMock(BaseResult::class);
        $row->method('getRowArray')->willReturnCallback(static fn () => $state->ticket);
        $row->method('getFirstRow')->willReturnCallback(static fn () => $state->ticket);
        $tenant = $this->createMock(BaseResult::class);
        $tenant->method('getRowArray')->willReturn(['id' => 7]);
        $state->clinicalRow = null;
        $state->clinicalBuilder = null;
        $db->method('query')->willReturnCallback(static function ($sql) use ($row, $tenant, $state) {
            if (str_starts_with($sql, 'SET TRANSACTION')) return true;
            if (str_contains($sql, 'FROM clinic_encounters') && $state->clinicalRow !== null) return $state->clinicalRow;
            return str_contains($sql, 'FROM tenants') ? $tenant : $row;
        });
        $builder = $this->createMock(BaseBuilder::class);
        $builder->method('where')->willReturnSelf();
        $builder->method('limit')->willReturnSelf();
        $builder->method('select')->willReturnSelf();
        $builder->method('join')->willReturnSelf();
        $builder->method('orderBy')->willReturnSelf();
        $builder->method('get')->willReturn($row);
        $builder->method('update')->willReturnCallback(static function (array $changes) use ($state) {
            $state->ticket = array_replace($state->ticket, $changes);
            $state->order[] = 'queue-write';
            return true;
        });
        $db->method('table')->willReturnCallback(static fn ($table) => $table === 'clinic_encounters' && $state->clinicalBuilder !== null
            ? $state->clinicalBuilder : $builder);
        $db->method('affectedRows')->willReturn(1);
        $queue = (new ReflectionClass(QueueService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($queue, 'db'))->setValue($queue, $db);
        (new ReflectionProperty($queue, 'queueModel'))->setValue($queue, new QueueEntryModel($db));
        (new ReflectionProperty($queue, 'invalidate'))->setValue($queue, static function (...$hint) use ($state) {
            self::assertSame(0, $state->depth, 'No hint before the outermost commit');
            $state->order[] = 'publish';
            $state->sent[] = $hint;
            if ($state->throwPublish) throw new RuntimeException('transport failed');
        });
        $class = $unit === 'clinic' ? EncounterService::class : GuidanceCaseService::class;
        $owner = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        (new ReflectionProperty($owner, 'db'))->setValue($owner, $db);
        (new ReflectionProperty($owner, 'queueService'))->setValue($owner, $queue);
        $state->db = $db;
        $state->queue = $queue;
        $state->owner = $owner;
        return $state;
    }

    public static function mutations(): iterable
    {
        foreach (['clinic', 'guidance'] as $unit) {
            yield $unit . ' start' => [$unit, 'start', 'called'];
            yield $unit . ' complete' => [$unit, 'complete', 'in_consultation'];
        }
    }

    private function mutate(object $state, string $unit, string $action): array
    {
        return $action === 'start'
            ? $state->queue->startConsultation(12, $unit)
            : $state->queue->completeConsultation(12, $unit);
    }

    #[DataProvider('mutations')]
    public function testOwnerCommitsBeforePublishingCapturedTenantAndDate(string $unit, string $action, string $status): void
    {
        $state = $this->fixture($unit, $status);
        $state->throwPublish = true;
        $result = $this->invoke($state->owner, 'transaction', fn () => $this->mutate($state, $unit, $action));
        self::assertSame($action === 'start' ? 'in_consultation' : 'done', $result['status']);
        self::assertSame(['queue-write', 'commit', 'publish'], $state->order);
        self::assertSame([[7, $unit, '2020-01-02', 88]], $state->sent);
    }

    #[DataProvider('mutations')]
    public function testNoOpDoesNotPublish(string $unit, string $action): void
    {
        $state = $this->fixture($unit, $action === 'start' ? 'in_consultation' : 'done');
        $this->invoke($state->owner, 'transaction', fn () => $this->mutate($state, $unit, $action));
        self::assertSame(['commit'], $state->order);
        self::assertSame([], $state->sent);
    }

    #[DataProvider('mutations')]
    public function testFailedCommitDoesNotLeakHintsIntoNextOperation(string $unit, string $action, string $status): void
    {
        $state = $this->fixture($unit, $status);
        $state->commit = false;
        try {
            $this->invoke($state->owner, 'transaction', fn () => $this->mutate($state, $unit, $action));
            self::fail('Commit should fail');
        } catch (RuntimeException) {
            self::assertSame(['queue-write', 'commit', 'rollback'], $state->order);
        }
        self::assertSame([], $state->sent);
        self::assertNull((new ReflectionProperty($state->queue, 'captureInvalidation'))->getValue($state->queue));
        $state->commit = true;
        CurrentTenant::set(7);
        $this->invoke($state->owner, 'transaction', static fn () => []);
        self::assertSame([], $state->sent, 'Unrelated writes must not flush failed transaction hints');
    }

    #[DataProvider('mutations')]
    public function testLaterMutationFailureDiscardsCapturedHints(string $unit, string $action, string $status): void
    {
        $state = $this->fixture($unit, $status);
        $failure = new RuntimeException('clinical save failed');
        try {
            $this->invoke($state->owner, 'transaction', function () use ($state, $unit, $action, $failure) {
                $this->mutate($state, $unit, $action);
                throw $failure;
            });
            self::fail('Operation should fail');
        } catch (RuntimeException $actual) {
            self::assertSame($failure, $actual);
        }
        self::assertSame(['queue-write', 'rollback'], $state->order);
        self::assertSame([], $state->sent);
        self::assertNull((new ReflectionProperty($state->queue, 'captureInvalidation'))->getValue($state->queue));
        $this->invoke($state->owner, 'transaction', static fn () => []);
        self::assertSame([], $state->sent);
    }

    public static function completionCommits(): iterable
    {
        yield 'legacy success' => [true, false];
        yield 'legacy commit failure' => [false, false];
        yield 'aggregate success' => [true, true];
        yield 'aggregate commit failure' => [false, true];
    }

    #[DataProvider('completionCommits')]
    public function testPublicClinicCompletionHook(bool $commit, bool $aggregate): void
    {
        $state = $this->fixture('clinic', 'in_consultation');
        $state->commit = $commit;
        $state->encounter = ['id' => 3, 'person_id' => 88, 'queue_entry_id' => 12, 'status' => 'in_progress'];
        $row = $this->createMock(BaseResult::class);
        $row->method('getRowArray')->willReturnCallback(static fn () => $state->encounter);
        $row->method('getFirstRow')->willReturnCallback(static fn () => $state->encounter);
        $builder = $this->createMock(BaseBuilder::class);
        $builder->method('where')->willReturnSelf();
        $builder->method('set')->willReturnSelf();
        $builder->method('whereIn')->willReturnSelf();
        $builder->method('getCompiledQBWhere')->willReturn(['fixture constraint']);
        $builder->method('get')->willReturn($row);
        $builder->method('update')->willReturn(true);
        $state->clinicalRow = $row;
        $state->clinicalBuilder = $builder;
        foreach ([
            'encounterModel' => \App\Models\ClinicEncounterModel::class,
            'personModel' => \App\Models\PersonModel::class,
            'vitalsModel' => \App\Models\ClinicVitalsModel::class,
            'treatmentModel' => \App\Models\ClinicTreatmentModel::class,
            'dispensationModel' => \App\Models\ClinicDispensationModel::class,
        ] as $property => $model) {
            (new ReflectionProperty($state->owner, $property))->setValue($state->owner, new $model($state->db));
        }
        try {
            $state->owner->completeEncounter(3, 'discharged', pendingDraft: $aggregate ? ['chief_complaint' => 'Headache'] : null);
            self::assertTrue($commit);
        } catch (RuntimeException $error) {
            self::assertFalse($commit);
            self::assertSame('Could not complete encounter.', $error->getMessage());
        }
        self::assertSame($commit ? [[7, 'clinic', '2020-01-02', 88]] : [], $state->sent);
        self::assertSame($commit ? ['queue-write', 'commit', 'publish'] : ['queue-write', 'commit', 'rollback'], $state->order);
    }

    public function testHintsDeduplicateActualChangesAndKeepEachChangedQueueDate(): void
    {
        $state = $this->fixture('clinic');
        $this->invoke($state->owner, 'transaction', function () use ($state) {
            $state->queue->startConsultation(12, 'clinic');
            $state->queue->completeConsultation(12, 'clinic');
            $state->ticket = array_replace($state->ticket, ['queue_date' => '2020-01-03', 'status' => 'called']);
            return $state->queue->startConsultation(12, 'clinic');
        });
        self::assertSame(['queue-write', 'queue-write', 'queue-write', 'commit', 'publish', 'publish'], $state->order);
        self::assertSame([[7, 'clinic', '2020-01-02', 88], [7, 'clinic', '2020-01-03', 88]], $state->sent);
    }

    public function testNestedGuidanceIntakePublishesOnceOnlyAfterOuterCommit(): void
    {
        $state = $this->fixture('guidance');
        $this->invoke($state->owner, 'transaction', function () use ($state) {
            $this->invoke($state->owner, 'transaction', fn () => $state->queue->startConsultation(12, 'guidance'));
            self::assertSame([], $state->sent);
            self::assertSame(1, $state->depth);
            return $state->queue->startConsultation(12, 'guidance'); // Idempotent resume.
        });
        self::assertSame(['queue-write', 'commit', 'publish'], $state->order);
        self::assertSame([[7, 'guidance', '2020-01-02', 88]], $state->sent);
    }

    public function testNestedGuidanceSuccessFollowedByOuterRollbackNeverPublishes(): void
    {
        $state = $this->fixture('guidance');
        try {
            $this->invoke($state->owner, 'transaction', function () use ($state) {
                $this->invoke($state->owner, 'transaction', fn () => $state->queue->startConsultation(12, 'guidance'));
                throw new RuntimeException('outer failure');
            });
            self::fail('Outer operation should fail');
        } catch (RuntimeException $actual) {
            self::assertSame('outer failure', $actual->getMessage());
        }
        self::assertSame(['queue-write', 'rollback'], $state->order);
        $this->invoke($state->owner, 'transaction', static fn () => []);
        self::assertSame([], $state->sent);
    }

    public function testExternallyOwnedGuidanceTransactionNeverPublishesOrRetainsHints(): void
    {
        $state = $this->fixture('guidance');
        $state->depth = 1;
        $this->invoke($state->owner, 'transaction', fn () => $state->queue->startConsultation(12, 'guidance'));
        self::assertSame(['queue-write'], $state->order);
        self::assertSame([], $state->sent);
        self::assertSame([], (new ReflectionProperty($state->queue, 'invalidations'))->getValue($state->queue));
        $state->depth = 0;
        $this->invoke($state->owner, 'transaction', static fn () => []);
        self::assertSame([], $state->sent);
    }
}
