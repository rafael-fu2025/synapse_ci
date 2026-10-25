<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Clinic\EncounterService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real service/models with in-memory builder doubles; never opens a database connection. */
final class EncounterDraftPersistenceTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function fixture(): object
    {
        CurrentTenant::set(7);
        $state = (object) [
            'depth' => 0, 'commits' => 0, 'rollbacks' => 0, 'writes' => [], 'failTable' => null,
            'healthy' => true, 'commit' => true,
            'rows' => [
                'clinic_encounters' => ['id' => 42, 'tenant_id' => 7, 'person_id' => 88,
                    'status' => 'in_progress', 'chief_complaint' => 'Old complaint',
                    'disposition' => 'sent_home', 'disposition_notes' => 'Old outcome',
                    'follow_up_date' => '2026-11-01', 'completed_at' => null],
                'clinic_vitals' => ['id' => 12, 'tenant_id' => 7, 'encounter_id' => 42,
                    'weight_kg' => 68, 'height_cm' => 170, 'pulse_bpm' => 75, 'spo2_pct' => 99],
                'clinic_treatments' => ['id' => 13, 'tenant_id' => 7, 'encounter_id' => 42,
                    'diagnosis' => 'Old diagnosis', 'treatment_plan' => 'Keep plan', 'services_rendered' => 'Keep care'],
                'people' => ['id' => 88, 'tenant_id' => 7],
                'clinic_dispensations' => null,
            ],
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
        $db->method('transBegin')->willReturnCallback(static function () use ($state) {
            ++$state->depth;
            $state->snapshot = $state->rows;
            return true;
        });
        $db->method('transStatus')->willReturnCallback(static fn () => $state->healthy);
        $db->method('transCommit')->willReturnCallback(static function () use ($state) {
            ++$state->commits;
            if ($state->commit) { --$state->depth; }
            return $state->commit;
        });
        $db->method('transRollback')->willReturnCallback(static function () use ($state) {
            ++$state->rollbacks;
            $state->rows = $state->snapshot;
            $state->depth = 0;
            return true;
        });
        $db->method('resetTransStatus')->willReturnSelf();
        $db->method('error')->willReturn(['code' => 0]);
        $db->method('insertID')->willReturn(100);
        $lock = $this->createMock(BaseResult::class);
        $lock->method('getRowArray')->willReturnCallback(static fn () => $state->rows['clinic_encounters']);
        $db->method('query')->willReturnCallback(function ($sql, $params = []) use ($state, $lock) {
            if (str_starts_with($sql, 'SET TRANSACTION')) { return true; }
            $this->assertSame(1, $state->depth);
            $this->assertStringContainsString('tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE', $sql);
            $this->assertSame([7, 42], $params);
            return $lock;
        });
        $builders = [];
        foreach (array_keys($state->rows) as $table) {
            $builder = $this->createMock(BaseBuilder::class);
            foreach (['where', 'whereIn', 'limit', 'select', 'join', 'orderBy'] as $method) {
                $builder->method($method)->willReturnSelf();
            }
            $builder->method('getCompiledQBWhere')->willReturn(['fixture constraint']);
            $pending = (object) ['data' => []];
            $builder->method('set')->willReturnCallback(static function ($data, $value = null) use ($pending, $builder) {
                $pending->data = array_replace($pending->data, is_array($data) ? $data : [$data => $value]);
                return $builder;
            });
            $write = function ($data = null) use ($state, $table, $pending) {
                $this->assertSame(1, $state->depth, 'All clinical writes must share the outer transaction');
                $data = array_replace($pending->data, $data ?? []);
                $pending->data = [];
                $state->writes[] = $table;
                if ($state->failTable === $table) { return false; }
                $state->rows[$table] = array_replace($state->rows[$table] ?? ['id' => 100], $data);
                return true;
            };
            $builder->method('update')->willReturnCallback($write);
            $builder->method('insert')->willReturnCallback($write);
            $result = $this->createMock(BaseResult::class);
            $result->method('getFirstRow')->willReturnCallback(static fn () => $state->rows[$table]);
            $result->method('getRowArray')->willReturnCallback(static fn () => $state->rows[$table]);
            $result->method('getResult')->willReturn([]);
            $builder->method('get')->willReturn($result);
            $builders[$table] = $builder;
        }
        $db->method('table')->willReturnCallback(static fn ($table) => $builders[$table]);
        $state->service = new EncounterService(db: $db);
        return $state;
    }

    public function testDraftPatchesPreserveMissingValuesAndClearExplicitEmptyValues(): void
    {
        $s = $this->fixture();
        $s->service->saveDraft(42, ['weight_kg' => '70', 'spo2_pct' => '0', 'diagnosis' => '',
            'disposition_notes' => '', 'status' => 'completed', 'tenant_id' => 999], 19);
        $this->assertSame(1, $s->commits);
        $this->assertSame(0, $s->rollbacks);
        $this->assertSame('in_progress', $s->rows['clinic_encounters']['status']);
        $this->assertNull($s->rows['clinic_encounters']['completed_at']);
        $this->assertSame('Old complaint', $s->rows['clinic_encounters']['chief_complaint']);
        $this->assertSame('2026-11-01', $s->rows['clinic_encounters']['follow_up_date']);
        $this->assertNull($s->rows['clinic_encounters']['disposition_notes']);
        $this->assertNull($s->rows['clinic_treatments']['diagnosis']);
        $this->assertSame('Keep plan', $s->rows['clinic_treatments']['treatment_plan']);
        $this->assertSame(170.0, $s->rows['clinic_vitals']['height_cm']);
        $this->assertSame(24.22, $s->rows['clinic_vitals']['bmi']);
        $this->assertSame(0, $s->rows['clinic_vitals']['spo2_pct']);
        $this->assertSame(19, $s->rows['clinic_vitals']['recorded_by_user_id']);
        $this->assertSame(7, $s->rows['clinic_vitals']['tenant_id']);
    }

    public function testDraftCanInsertChildRecordsAndClearVitalsAndOutcome(): void
    {
        $s = $this->fixture();
        $s->rows['clinic_vitals'] = null;
        $s->rows['clinic_treatments'] = null;
        $s->service->saveDraft(42, ['pulse_bpm' => '72', 'diagnosis' => 'New'], 19);
        $this->assertSame(72, $s->rows['clinic_vitals']['pulse_bpm']);
        $this->assertSame(42, $s->rows['clinic_treatments']['encounter_id']);
        $s->service->saveDraft(42, ['pulse_bpm' => '', 'disposition' => '', 'follow_up_date' => ''], 19);
        $this->assertNull($s->rows['clinic_vitals']['pulse_bpm']);
        $this->assertNull($s->rows['clinic_encounters']['disposition']);
        $this->assertNull($s->rows['clinic_encounters']['follow_up_date']);
    }

    public function testCompletionSavesPendingFieldsAndSealsWithOneCommit(): void
    {
        $s = $this->fixture();
        $result = $s->service->completeEncounter(42, 'discharged', pendingDraft: [
            'chief_complaint' => 'Headache', 'pulse_bpm' => '80', 'diagnosis' => 'Migraine',
            'treatment_plan' => 'Rest', 'disposition_notes' => 'Home', 'follow_up_date' => '',
        ], recordedByUserId: 19);
        $this->assertSame(1, $s->commits);
        $this->assertSame('completed', $result['encounter']['status']);
        $this->assertSame('Headache', $result['encounter']['chief_complaint']);
        $this->assertSame('Home', $result['encounter']['disposition_notes']);
        $this->assertNull($result['encounter']['follow_up_date']);
        $this->assertNotNull($result['encounter']['completed_at']);
        $this->assertSame('Migraine', $result['treatment']['diagnosis']);
        $this->assertSame(80, $result['vitals']['pulse_bpm']);
    }

    public function testOutcomeOnlyLegacyCompletionDoesNotRequireComplaintOrOverwriteChildren(): void
    {
        $s = $this->fixture();
        $s->rows['clinic_encounters']['chief_complaint'] = null;
        $result = $s->service->completeEncounter(42, 'referred_to_guidance', 'Referral', '2026-12-01');
        $this->assertSame('referred', $result['encounter']['status']);
        $this->assertSame('Referral', $result['encounter']['disposition_notes']);
        $this->assertSame(['clinic_encounters'], $s->writes);
    }

    public function testDraftAllowsEmptyComplaintAndKeepsZeroText(): void
    {
        $s = $this->fixture();
        $s->service->saveDraft(42, ['chief_complaint' => '', 'diagnosis' => '0', 'disposition_notes' => '0'], 19);
        $this->assertNull($s->rows['clinic_encounters']['chief_complaint']);
        $this->assertSame('0', $s->rows['clinic_treatments']['diagnosis']);
        $result = $s->service->completeEncounter(42, 'discharged', pendingDraft: ['chief_complaint' => 'Headache']);
        $this->assertSame('0', $result['encounter']['disposition_notes']);
    }

    public function testExtendedServiceApiStillHonorsPositionalOutcomeArguments(): void
    {
        $s = $this->fixture();
        $result = $s->service->completeEncounter(42, 'discharged', 'New outcome', '2026-12-01',
            pendingDraft: ['diagnosis' => 'Changed']);
        $this->assertSame('New outcome', $result['encounter']['disposition_notes']);
        $this->assertSame('2026-12-01', $result['encounter']['follow_up_date']);
    }

    public function testAggregateCompletionPreservesOmittedOutcomeNotes(): void
    {
        $s = $this->fixture();
        $result = $s->service->completeEncounter(42, 'discharged', pendingDraft: ['chief_complaint' => 'Headache']);
        $this->assertSame('Old outcome', $result['encounter']['disposition_notes']);
        $this->assertSame('2026-11-01', $result['encounter']['follow_up_date']);
    }

    public static function failures(): iterable
    {
        foreach (['draft', 'complete'] as $operation) {
            foreach (['clinic_vitals', 'clinic_treatments', 'clinic_encounters', 'status', 'commit', 'merged_bmi'] as $failure) {
                yield [$operation, $failure];
            }
        }
    }

    #[DataProvider('failures')]
    public function testAggregateFailureRollsBackAllChanges(string $operation, string $failure): void
    {
        $s = $this->fixture();
        $before = $s->rows;
        $s->failTable = $failure;
        $s->healthy = $failure !== 'status';
        $s->commit = $failure !== 'commit';
        $draft = ['chief_complaint' => 'Headache', 'pulse_bpm' => '80', 'diagnosis' => 'Changed'];
        if ($failure === 'merged_bmi') {
            $s->rows['clinic_vitals']['height_cm'] = 10;
            $before = $s->rows;
            $draft['weight_kg'] = '500';
        }
        try {
            if ($operation === 'draft') {
                $s->service->saveDraft(42, $draft, 19);
            } else {
                $s->service->completeEncounter(42, 'discharged', pendingDraft: $draft, recordedByUserId: 19);
            }
            $this->fail('Expected aggregate save failure');
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame(1, $s->rollbacks);
        $this->assertSame($before, $s->rows);
        $this->assertSame(0, $s->depth);
    }
}
