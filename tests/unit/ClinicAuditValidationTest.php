<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\EncounterService;
use CodeIgniter\Database\BaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Run with --no-configuration --bootstrap vendor/autoload.php; no CI boot or .env. */
final class ClinicAuditValidationTest extends TestCase
{
    private function service(): EncounterService
    {
        $service = (new \ReflectionClass(EncounterService::class))->newInstanceWithoutConstructor();
        $queue = (new \ReflectionClass(\App\Services\Scheduling\QueueService::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($service, 'queueService'))->setValue($service, $queue);
        return $service;
    }

    public static function invalidVitals(): iterable
    {
        foreach (EncounterService::VITAL_BOUNDS as $field => [$min, $max, $integer]) {
            foreach ([$min - 1, $max + 1, 'not numeric', [], true, INF, NAN, '1e999'] as $value) {
                yield [$field, $value];
            }
            if ($integer) {
                yield [$field, $min + 0.5];
            }
        }
        yield ['weight_kg', 500, ['height_cm' => 10]];
    }

    #[DataProvider('invalidVitals')]
    public function testInvalidVitalsAreRejectedBeforeAnyDatabaseAccess(string $field, mixed $value, array $others = []): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->recordVitals(1, [$field => $value] + $others, 1);
    }

    public function testOptionalVitalsAndZeroSaturationAreNotLost(): void
    {
        $method = new \ReflectionMethod(EncounterService::class, 'validateVitals');
        $values = $method->invoke(null, ['spo2_pct' => '0', 'bp_systolic' => '', 'temp_c' => '36.5']);
        $this->assertSame(0, $values['spo2_pct']);
        $this->assertNull($values['bp_systolic']);
        $this->assertSame(36.5, $values['temp_c']);
        $this->assertCount(7, $values);
        foreach (EncounterService::VITAL_BOUNDS as $field => [$min, $max]) {
            $this->assertNotNull($method->invoke(null, [$field => $min])[$field]);
            $this->assertNotNull($method->invoke(null, [$field => $max])[$field]);
        }
    }

    public static function excessiveTexts(): iterable
    {
        foreach (EncounterService::TEXT_LIMITS as $field => $limit) {
            yield [$field, str_repeat('x', $limit + 1)];
            yield [$field, str_repeat('é', $limit + 1)];
        }
    }

    #[DataProvider('excessiveTexts')]
    public function testClinicalStringLimits(string $field, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        (new \ReflectionMethod(EncounterService::class, 'validateTexts'))->invoke(null, [$field => $value]);
    }

    public function testClinicalTextBoundaryIsAccepted(): void
    {
        foreach (EncounterService::TEXT_LIMITS as $field => $limit) {
            (new \ReflectionMethod(EncounterService::class, 'validateTexts'))->invoke(null, [$field => str_repeat('é', $limit)]);
        }
        $this->addToAssertionCount(1);
    }

    public static function invalidOutcomes(): iterable
    {
        foreach (['', 'DISCHARGED', 'discharged ', 'completed', 'unknown'] as $value) {
            yield [$value, null];
        }
        foreach (['2026-02-30', '2026-2-03', 'not a date'] as $date) {
            yield ['discharged', $date];
        }
    }

    #[DataProvider('invalidOutcomes')]
    public function testOutcomeValidationPrecedesDatabaseAccess(string $outcome, ?string $date): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->completeEncounter(1, $outcome, followUpDate: $date);
    }

    public static function invalidDrafts(): iterable
    {
        yield [['chief_complaint' => []]];
        yield [['diagnosis' => false]];
        yield [['disposition_notes' => str_repeat('x', 10001)]];
        yield [['bp_systolic' => '301']];
        yield [['weight_kg' => []]];
        yield [['follow_up_date' => '2026-02-30']];
        yield [['disposition' => 'unknown']];
    }

    #[DataProvider('invalidDrafts')]
    public function testInvalidDraftIsRejectedBeforeDatabaseAccess(array $draft): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->saveDraft(42, $draft, 1);
    }

    #[DataProvider('invalidDrafts')]
    public function testInvalidPendingCompletionIsRejectedBeforeDatabaseAccess(array $draft): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->completeEncounter(42, 'discharged', pendingDraft: $draft, recordedByUserId: 1);
    }

    public function testDraftNormalizationPreservesPresenceAndWhitelistsFields(): void
    {
        $draft = (new \ReflectionMethod(EncounterService::class, 'normalizeDraft'))->invoke(null, [
            'diagnosis' => '', 'chief_complaint' => '  Headache  ', 'spo2_pct' => '0',
            'follow_up_date' => '', 'disposition' => '', 'status' => 'completed',
            'tenant_id' => 999, 'recorded_by_user_id' => 999,
        ]);
        $this->assertSame(['diagnosis' => null, 'chief_complaint' => 'Headache', 'spo2_pct' => 0,
            'follow_up_date' => null, 'disposition' => null], $draft);
        $this->assertArrayNotHasKey('assessment_notes', $draft);
    }

    public function testExplicitBlankChiefComplaintCannotCompleteAggregateVisit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->completeEncounter(42, 'discharged', pendingDraft: ['chief_complaint' => '  ']);
    }

    public function testAggregateVitalsRequireExplicitRecorder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->completeEncounter(42, 'discharged', pendingDraft: ['pulse_bpm' => '72']);
    }

    public function testTransactionRollsBackAndPreservesQueueFailure(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->with('transDepth')->willReturn(0);
        $db->expects($this->once())->method('query')->with('SET TRANSACTION ISOLATION LEVEL READ COMMITTED')->willReturn(true);
        $db->expects($this->once())->method('transBegin')->willReturn(true);
        $db->expects($this->never())->method('transCommit');
        $db->expects($this->once())->method('transRollback')->willReturn(true);
        $db->expects($this->exactly(2))->method('resetTransStatus')->willReturnSelf();
        $service = $this->service();
        (new \ReflectionProperty($service, 'db'))->setValue($service, $db);
        $failure = new \RuntimeException('Queue state changed', 409);
        try {
            (new \ReflectionMethod($service, 'transaction'))->invoke($service, static function () use ($failure): never { throw $failure; });
            $this->fail('Expected rollback');
        } catch (\RuntimeException $actual) {
            $this->assertSame($failure, $actual);
        }
    }

    public function testTransactionDoesNotCommitFailedDatabaseStatus(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->with('transDepth')->willReturn(0);
        $db->method('query')->willReturn(true);
        $db->method('transBegin')->willReturn(true);
        $db->method('transStatus')->willReturn(false);
        $db->expects($this->never())->method('transCommit');
        $db->expects($this->once())->method('transRollback')->willReturn(true);
        $db->method('resetTransStatus')->willReturnSelf();
        $service = $this->service();
        (new \ReflectionProperty($service, 'db'))->setValue($service, $db);
        $this->expectException(\RuntimeException::class);
        (new \ReflectionMethod($service, 'transaction'))->invoke($service, static fn () => 42);
    }

    public static function closedWrites(): iterable
    {
        foreach (['completed', 'referred', 'cancelled'] as $status) {
            foreach (['vitals', 'assessment', 'draft', 'complete'] as $operation) {
                yield [$status, $operation];
            }
        }
    }

    #[DataProvider('closedWrites')]
    public function testClosedWritesLockTenantEncounterAndRollback(string $status, string $operation): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->with('transDepth')->willReturn(0);
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $db->method('transBegin')->willReturn(true);
        $db->method('resetTransStatus')->willReturnSelf();
        $db->expects($this->never())->method('transCommit');
        $db->expects($this->once())->method('transRollback')->willReturn(true);
        $row = $this->createMock(\CodeIgniter\Database\BaseResult::class);
        $row->method('getRowArray')->willReturn(['id' => 42, 'status' => $status]);
        $db->expects($this->exactly(2))->method('query')->willReturnCallback(function ($sql, $params = []) use ($row) {
            if (str_starts_with($sql, 'SET TRANSACTION')) { return true; }
            $this->assertStringContainsString('tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE', $sql);
            $this->assertSame([\App\Services\CurrentTenant::id(), 42], $params);
            return $row;
        });
        $service = $this->service();
        (new \ReflectionProperty($service, 'db'))->setValue($service, $db);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(409);
        match ($operation) {
            'vitals' => $service->recordVitals(42, [], 1),
            'assessment' => $service->recordAssessment(42),
            'draft' => $service->saveDraft(42, ['diagnosis' => 'Draft'], 1),
            'complete' => $service->completeEncounter(42, 'discharged'),
        };
    }

    public function testStartLocksPatientBeforeTicketAndRejectsForeignTicketWithoutInsert(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('__get')->with('transDepth')->willReturn(0);
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $db->method('transBegin')->willReturn(true);
        $db->method('resetTransStatus')->willReturnSelf();
        $db->expects($this->never())->method('transCommit');
        $db->expects($this->once())->method('transRollback')->willReturn(true);
        $patient = $this->createMock(\CodeIgniter\Database\BaseResult::class);
        $patient->method('getRowArray')->willReturn(['id' => 42]);
        $ticket = $this->createMock(\CodeIgniter\Database\BaseResult::class);
        $ticket->method('getRowArray')->willReturn(null);
        $sequence = 0;
        $db->expects($this->exactly(3))->method('query')->willReturnCallback(function ($sql, $params = []) use (&$sequence, $patient, $ticket) {
            $sequence++;
            if ($sequence === 1) { return true; }
            if ($sequence === 2) {
                $this->assertStringContainsString('FOR UPDATE', $sql);
                $this->assertStringContainsString('FROM people', $sql);
                $this->assertSame([\App\Services\CurrentTenant::id(), 42], $params);
                return $patient;
            }
            $this->assertStringContainsString('FROM queue_entries', $sql);
            $this->assertStringNotContainsString('FOR UPDATE', $sql);
            $this->assertSame([\App\Services\CurrentTenant::id(), 7, 'clinic'], $params);
            return $ticket;
        });
        $service = $this->service();
        (new \ReflectionProperty($service, 'db'))->setValue($service, $db);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(404);
        $service->startEncounter(42, 1, 7);
    }

    public function testLegacyDispenseAdapterStillRequiresActorAndReplayKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->service()->dispenseMedicine(1, 1, 1);
    }
}
