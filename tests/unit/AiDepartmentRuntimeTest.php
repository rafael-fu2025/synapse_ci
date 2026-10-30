<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\AddAiAnalysisScope;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\ChatClient;
use App\Services\Ai\ClinicSnapshotBuilder;
use App\Services\Ai\DepartmentSnapshotBuilder;
use App\Services\Ai\InsightApplication;
use App\Services\Ai\InsightGenerator;
use App\Services\Ai\InsightWorker;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\Profile;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

require_once __DIR__ . '/AiApplicationTest.php';

/** Real collectors/store/grants over disposable SQLite; synthetic stamps, DNS and transport only. */
final class AiDepartmentRuntimeTest extends TestCase
{
    use AiApplicationFixture;

    private array $connections;
    private array $actor;
    private array $unitsChecked = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->connections = Database::getConnections();
        $this->setUpControl();
        $this->profile = self::fixtureProfile(['approvedUnits' => 'clinic,guidance,bmg', 'approvedTenants' => '1,2']);
        $this->setUpStore();
        require_once APPPATH . 'Database/Migrations/2026-10-29-000001_AddAiAnalysisScope.php';
        (new AddAiAnalysisScope(Database::forge($this->fixtureDb)))->up();
        // AiAccess's production grant merger must resolve only this disposable connection.
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, ['tests' => $this->fixtureDb] + $this->connections);
        $this->actor = self::fixtureActor(['role' => 'superadmin', 'permissions' => [
            'clinic.encounters.read', 'guidance.cases.read_any', 'facilities.read',
        ]]);
        foreach (['clinic', 'guidance', 'bmg'] as $unit) { $this->grant($unit); }
        $this->control->resume($this->profile, 1);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
        $this->tearDownFixture();
        parent::tearDown();
    }

    private function grant(string $unit, int $tenant = 1): void
    {
        foreach (['read', 'generate', 'review', 'approve'] as $action) {
            $this->fixtureDb->table('ai_department_access')->insert(['tenant_id' => $tenant, 'unit' => $unit,
                'role' => 'superadmin', 'permission' => 'ai.insights.' . $action, 'created_by' => 10, 'created_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    private function app(string $unit = 'clinic', ?Profile $profile = null): InsightApplication
    {
        return new InsightApplication($profile ?? $this->profile, $this->store,
            new ClinicSnapshotBuilder($this->fixtureDb, $this->month, 10, 'Asia/Manila'), $this->control,
            $unit, new DepartmentSnapshotBuilder($this->fixtureDb, $this->month, 10, 'Asia/Manila'));
    }

    private function seedDepartments(): void
    {
        $this->seedSource();
        $this->fixtureDb->query("UPDATE ai_focused_clinic_encounters SET chief_complaint = CASE WHEN id % 20 < 10 THEN 'cough' ELSE 'fever' END");
        $this->fixtureDb->query('CREATE TABLE ai_focused_counselling_cases (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, category TEXT)');
        $this->fixtureDb->query('CREATE TABLE ai_focused_counselling_sessions (id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, status TEXT, started_at TEXT)');
        $this->fixtureDb->query('CREATE TABLE ai_focused_bmg_batches (id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, started_at TEXT, completed_at TEXT)');
        $this->fixtureDb->query('CREATE TABLE ai_focused_bmg_rotation_runs (id INTEGER PRIMARY KEY, tenant_id INTEGER, motor_state TEXT, occurred_at TEXT)');
        for ($id = 1; $id <= 20; ++$id) {
            $this->fixtureDb->table('counselling_cases')->insert(['id' => $id, 'tenant_id' => 1,
                'person_id' => ($id - 1) % 10 + 1, 'category' => $id <= 10 ? 'academic' : 'career']);
        }
        foreach ([-2 => 1, -1 => 2] as $offset => $repeats) {
            $date = $this->month->modify($offset . ' months')->modify('+10 days')->format('Y-m-d H:i:s');
            for ($i = 0; $i < 20 * $repeats; ++$i) {
                $this->fixtureDb->table('counselling_sessions')->insert(['tenant_id' => 1, 'case_id' => $i % 20 + 1,
                    'status' => 'completed', 'started_at' => $date]);
            }
            for ($i = 0; $i <= $repeats; ++$i) {
                $this->fixtureDb->table('bmg_batches')->insert(['tenant_id' => 1, 'status' => 'released', 'started_at' => $date, 'completed_at' => $date]);
                $this->fixtureDb->table('bmg_rotation_runs')->insert(['tenant_id' => 1, 'motor_state' => $i === 0 ? 'failed' : 'stopped', 'occurred_at' => $date]);
            }
        }
    }

    private function runtimeWorker(?callable $resolver = null, ?callable $gate = null, ?callable $transport = null, ?callable $identity = null): InsightWorker
    {
        $client = new ChatClient($this->profile, function ($profile, $endpoint, $headers, $body, $receive) use ($transport): int {
            ++$this->requests;
            $this->requestBodies[] = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if ($transport !== null) { return $transport($receive); }
            $receive(json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant',
                'content' => json_encode(['schema_version' => '1', 'evidence_ids' => ['m1'],
                    'summary' => 'Recorded activity changed.',
                    'interpretation' => 'This descriptive comparison does not establish cause or individual need. Human review is required.',
                    'alternative_explanations' => ['Recording coverage may differ.'], 'program_ids' => [], 'abstained' => false], JSON_THROW_ON_ERROR),
            ]]]], JSON_THROW_ON_ERROR));
            return 200;
        }, $resolver ?? static fn (): array => ['8.8.8.8']);
        return new InsightWorker($this->profile, $this->store, new InsightGenerator($this->profile, $client),
            $identity ?? function (int $id, int $tenant, string $unit): array {
                self::assertSame(10, $id);
                self::assertSame(1, $tenant);
                $this->unitsChecked[] = $unit;
                return $this->actor;
            }, $gate ?? $this->control->assertAllowed(...));
    }

    private function queue(string $analysis, int $tenant = 1, int $limit = 100): array
    {
        $unit = AnalysisCatalog::get($analysis)['unit'];
        $payload = $this->app($unit)->prepare($this->actor, $analysis);
        $snapshot = $this->store->saveSnapshot($tenant, $unit, $payload);
        return $this->store->enqueue($tenant, $unit, $snapshot['id'], 10, $this->profile->revision(), $this->profile->config->model, $limit);
    }

    public static function analyses(): iterable
    {
        foreach (AnalysisCatalog::definitions() as $analysis => $definition) {
            yield $analysis => [$definition['unit'], $analysis];
        }
    }

    #[DataProvider('analyses')]
    public function testPrepareGenerateAndWorkerUseActualUnitAndAnalysis(string $unit, string $analysis): void
    {
        $this->seedDepartments();
        $app = $this->app($unit);
        $payload = $app->prepare($this->actor, $analysis);
        self::assertSame($analysis === 'clinic.activity' ? '1' : '2', $payload['schema_version']);
        self::assertSame($unit, $payload['department']);
        self::assertSame($analysis, $payload['analysis_code'] ?? 'clinic.activity');
        $job = $app->generate($this->actor, $analysis);
        self::assertSame($analysis, $job['analysis_code']);
        self::assertSame(0, $this->requests);
        self::assertSame(['processed' => 1, 'failed' => 0, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1, $unit));
        self::assertSame([$unit], array_values(array_unique($this->unitsChecked)));
        $panel = $app->panel($this->actor);
        self::assertSame($unit, $panel['unit']);
        self::assertSame(AnalysisCatalog::forUnit($unit), $panel['analyses']);
        self::assertSame(match ($unit) {
            'guidance' => 'guidance/insights', 'bmg' => 'facilities/insights', default => 'clinic/insights',
        }, $panel['basePath']);
        $row = $panel['insights'][0];
        self::assertEquals($payload, $row['draft']['evidence']);
        $app->review($this->actor, $row['id'], 1, 'accept');
        self::assertSame('accepted', $this->store->getInsight(1, $unit, $row['id'])['status']);
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1, $unit));
        self::assertSame(1, $this->requests);
    }

    public function testDefaultsAreUnitSpecificAndClinicRemainsLegacy(): void
    {
        $this->seedDepartments();
        self::assertSame('1', $this->app()->prepare($this->actor)['schema_version']);
        self::assertSame('guidance.activity', $this->app('guidance')->prepare($this->actor)['analysis_code']);
        self::assertSame('bmg.batches', $this->app('bmg')->prepare($this->actor)['analysis_code']);
    }

    public function testInvalidCrossUnitAndMissingSourceRightsFailBeforeCollection(): void
    {
        // No source tables exist: errors must happen before either collector is used.
        foreach (['guidance.activity', 'bmg.batches', 'unknown', ''] as $analysis) {
            $this->assertAiFailure('invalid_request', fn () => $this->app()->prepare($this->actor, $analysis), 422);
            $this->assertAiFailure('invalid_request', fn () => $this->app()->generate($this->actor, $analysis), 422);
        }
        foreach (['clinic', 'guidance', 'bmg'] as $unit) {
            $actor = $this->actor;
            $actor['permissions'] = [];
            $this->assertAiFailure('access_denied', fn () => $this->app($unit)->prepare($actor), 403);
        }
        $this->assertNoAiRows();
    }

    public function testPreparationNeedsUnitApprovalButNotEnabledDispatch(): void
    {
        $this->seedDepartments();
        $this->control->pause();
        $profile = self::fixtureProfile(['enabled' => false, 'approvedUnits' => 'guidance']);
        $payload = $this->app('guidance', $profile)->prepare($this->actor);
        self::assertSame('guidance.activity', $payload['analysis_code']);
        $this->assertAiFailure('unit_not_approved', fn () => $this->app('bmg', $profile)->prepare($this->actor));
        $this->assertAiFailure('disabled', fn () => $this->app('guidance', $profile)->generate($this->actor));
        self::assertSame(0, $this->requests);
    }

    public function testPanelCancelAndReviewCannotCrossTenantOrUnit(): void
    {
        $this->seedDepartments();
        $clinic = $this->queue('clinic.activity');
        $guidance = $this->queue('guidance.activity');
        $otherTenant = $this->queue('guidance.activity', 2);
        self::assertSame([$guidance['id']], array_column($this->app('guidance')->panel($this->actor)['jobs'], 'id'));
        $this->assertAiFailure('job_not_found', fn () => $this->app()->cancel($this->actor, $guidance['id']), 404);
        $this->assertAiFailure('job_not_found', fn () => $this->app('guidance')->cancel($this->actor, $otherTenant['id']), 404);
        $this->app()->cancel($this->actor, $clinic['id']);
        self::assertSame(['processed' => 1, 'failed' => 0, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1));
        $row = $this->store->listInsights(1, 'guidance')[0];
        $this->assertAiFailure('insight_not_found', fn () => $this->app()->review($this->actor, $row['id'], 1, 'accept'), 404);
        self::assertSame('queued', $this->store->job(2, 'guidance', $otherTenant['id'])['status']);
    }

    public function testAllApprovedUnitsShareOneFiniteJobBudgetIncludingFailures(): void
    {
        $this->profile = self::fixtureProfile(['approvedUnits' => 'clinic,guidance,bmg', 'maxJobsPerRun' => 2]);
        $this->control->resume($this->profile, 1);
        $this->seedDepartments();
        foreach (array_keys(AnalysisCatalog::definitions()) as $analysis) { $this->queue($analysis); }
        $this->fixtureDb->table('ai_department_access')->where('unit', 'clinic')->delete();
        self::assertSame(['processed' => 1, 'failed' => 1, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1));
        self::assertSame(4, $this->fixtureDb->table('ai_jobs')->where('status', 'queued')->countAllResults());
        self::assertSame(['clinic', 'guidance'], array_values(array_unique($this->unitsChecked)));
        self::assertSame(1, $this->requests);
    }

    public function testSelectedUnitDoesNotClaimOthersAndUnapprovedUnitFailsClosed(): void
    {
        $this->seedDepartments();
        $clinic = $this->queue('clinic.activity');
        $this->queue('bmg.batches');
        self::assertSame(['processed' => 1, 'failed' => 0, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1, 'bmg'));
        self::assertSame('queued', $this->store->job(1, 'clinic', $clinic['id'])['status']);
        $this->assertAiFailure('unit_not_approved', fn () => $this->runtimeWorker()->run(1, 'main'));
        self::assertSame(1, $this->requests);
    }

    public function testSourceRevokedDuringDnsPreventsReservationAndTransport(): void
    {
        $this->seedDepartments();
        $job = $this->queue('guidance.activity');
        $worker = $this->runtimeWorker(resolver: function (): array {
            $this->actor['permissions'] = ['facilities.read'];
            return ['8.8.8.8'];
        });
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1, 'guidance'));
        self::assertSame(0, $this->requests);
        self::assertSame(0, $this->fixtureDb->table('ai_dispatch_attempts')->countAllResults());
        self::assertSame('authorization_revoked', $this->store->job(1, 'guidance', $job['id'])['reason']);
    }

    public function testGrantRevokedAfterBudgetReservationPreventsSendAndIsNeverReplayed(): void
    {
        $this->seedDepartments();
        $job = $this->queue('bmg.batches');
        $worker = $this->runtimeWorker(gate: function (Profile $profile, int $tenant, string $unit): void {
            $this->control->assertAllowed($profile, $tenant, $unit);
            if ($this->fixtureDb->table('ai_dispatch_attempts')->countAllResults() > 0) {
                $this->fixtureDb->table('ai_department_access')->where(['tenant_id' => 1, 'unit' => 'bmg'])->delete();
            }
        });
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1, 'bmg'));
        self::assertSame(0, $this->requests);
        self::assertSame(1, $this->fixtureDb->table('ai_dispatch_attempts')->countAllResults());
        self::assertSame('authorization_revoked', $this->store->job(1, 'bmg', $job['id'])['reason']);
        $this->grant('bmg');
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1, 'bmg'));
    }

    public function testGrantsFromOtherTenantOrUnitCannotAuthorizeWorker(): void
    {
        $this->seedDepartments();
        $job = $this->queue('guidance.activity');
        $this->fixtureDb->table('ai_department_access')->where(['tenant_id' => 1, 'unit' => 'guidance'])->delete();
        $this->grant('guidance', 2);
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $this->runtimeWorker()->run(1, 'guidance'));
        self::assertSame('authorization_revoked', $this->store->job(1, 'guidance', $job['id'])['reason']);
        self::assertSame(0, $this->requests);
    }

    public function testLeaseExpiryDuringDnsNeverSendsOrReplays(): void
    {
        $this->seedDepartments();
        $this->queue('bmg.rotations');
        $worker = $this->runtimeWorker(resolver: function (): array { $this->now += 200; return ['8.8.8.8']; });
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1, 'bmg'));
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1, 'bmg'));
        self::assertSame(0, $this->requests);
        self::assertSame(0, $this->fixtureDb->table('ai_dispatch_attempts')->countAllResults());
    }

    public function testAmbiguousTransportIsRecordedOnceWithoutReplay(): void
    {
        $this->seedDepartments();
        $job = $this->queue('bmg.batches');
        $worker = $this->runtimeWorker(transport: static function (): never { throw new RuntimeException('Synthetic lost response'); });
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 1], $worker->run(1, 'bmg'));
        self::assertSame('outcome_unknown', $this->store->job(1, 'bmg', $job['id'])['status']);
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1, 'bmg'));
        self::assertSame(1, $this->requests);
    }

    public function testAllApprovedUnitsAreDrainedWithoutReplayingOrCrossingTenants(): void
    {
        $this->profile = self::fixtureProfile(['approvedUnits' => 'clinic,guidance,bmg', 'approvedTenants' => '1,2', 'maxJobsPerRun' => 6]);
        $this->control->resume($this->profile, 1);
        $this->seedDepartments();
        foreach (array_keys(AnalysisCatalog::definitions()) as $analysis) { $this->queue($analysis); }
        $otherTenant = $this->queue('bmg.batches', 2);
        $worker = $this->runtimeWorker();
        self::assertSame(['processed' => 6, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame(['clinic', 'guidance', 'bmg'], array_values(array_unique($this->unitsChecked)));
        self::assertSame('queued', $this->store->job(2, 'bmg', $otherTenant['id'])['status']);
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame(6, $this->requests);
    }

    public function testDefaultJobBudgetLeavesRemainingWorkForTheNextRun(): void
    {
        $this->seedDepartments();
        foreach (array_keys(AnalysisCatalog::definitions()) as $analysis) { $this->queue($analysis); }
        self::assertSame(5, $this->profile->config->maxJobsPerRun);
        $worker = $this->runtimeWorker();
        self::assertSame(['processed' => 5, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame(1, $this->fixtureDb->table('ai_jobs')->where('status', 'queued')->countAllResults());
        self::assertSame(5, $this->requests);
        self::assertSame(['processed' => 1, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame(6, $this->requests);
        self::assertSame(6, $this->fixtureDb->table('ai_jobs')->where('attempts', 1)->countAllResults());
    }

    public function testDailyDispatchBudgetIsCheckedForActualUnitAfterDns(): void
    {
        $this->profile = self::fixtureProfile(['approvedUnits' => 'bmg', 'dailyRequestLimit' => 1]);
        $this->control->resume($this->profile, 1);
        $this->seedDepartments();
        $this->queue('bmg.batches');
        $blocked = $this->queue('bmg.rotations');
        $worker = $this->runtimeWorker();
        self::assertSame(['processed' => 1, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame('budget_exceeded', $this->store->job(1, 'bmg', $blocked['id'])['reason']);
        self::assertSame(1, $this->fixtureDb->table('ai_dispatch_attempts')->where('unit', 'bmg')->countAllResults());
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1));
        self::assertSame(1, $this->requests);
    }

    public function testPostTransportSourceRevocationDiscardsDraft(): void
    {
        $this->seedDepartments();
        $job = $this->queue('clinic.activity');
        $worker = $this->runtimeWorker(identity: function (): array {
            if ($this->requests > 0) { $this->actor['permissions'] = []; }
            return $this->actor;
        });
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1, 'clinic'));
        self::assertSame('authorization_revoked', $this->store->job(1, 'clinic', $job['id'])['reason']);
        self::assertSame([], $this->store->listInsights(1, 'clinic'));
        self::assertSame(1, $this->requests);
    }

    public function testLegacyPayloadCannotBeSentUnderAnotherDepartment(): void
    {
        $this->seedDepartments();
        $legacy = $this->app()->prepare($this->actor);
        $snapshots = $this->fixtureDb->table('ai_snapshots')->countAllResults();
        $releases = $this->fixtureDb->table('ai_releases')->countAllResults();
        $this->assertAiFailure('invalid_scope', fn () => $this->store->saveSnapshot(1, 'guidance', $legacy), 422);
        self::assertSame($snapshots, $this->fixtureDb->table('ai_snapshots')->countAllResults());
        self::assertSame($releases, $this->fixtureDb->table('ai_releases')->countAllResults());

        $job = $this->queue('guidance.activity');
        $snapshot = $this->fixtureDb->table('ai_snapshots')->where('id', $job['snapshot_id'])->get()->getRowArray();
        $cipher = new PayloadCipher($this->profile->config->encryptionKey);
        // Only disposable SQL bypasses admission to simulate a legacy out-of-scope encrypted row.
        self::assertTrue($this->fixtureDb->table('ai_snapshots')->where('id', $snapshot['id'])->update([
            'schema_version' => '1', 'analysis_code' => 'clinic.activity',
            'payload' => $cipher->encrypt(1, 'guidance', 'snapshot:' . $snapshot['fingerprint'], $legacy),
        ]));
        $worker = $this->runtimeWorker();
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $worker->run(1, 'guidance'));
        $failed = $this->store->job(1, 'guidance', $job['id']);
        self::assertSame('failed', $failed['status']);
        self::assertSame('policy_blocked', $failed['reason']);
        self::assertSame(1, $failed['attempts']);
        self::assertSame(0, $this->requests);
        self::assertSame(0, $this->fixtureDb->table('ai_dispatch_attempts')->countAllResults());
        self::assertSame([], $this->store->listInsights(1, 'guidance'));
        self::assertSame(['processed' => 0, 'failed' => 0, 'outcome_unknown' => 0], $worker->run(1, 'guidance'));
        self::assertSame(0, $this->requests);
    }

    public function testControlDefaultsToFirstApprovedUnitAndPauseCoversEveryUnit(): void
    {
        $profile = self::fixtureProfile(['approvedUnits' => 'guidance,bmg']);
        $this->control->resume($profile, 1);
        $this->control->assertAllowed($profile, 1);
        $this->control->assertAllowed($profile, 1, 'bmg');
        $this->assertAiFailure('unit_not_approved', fn () => $this->control->assertAllowed($profile, 1, 'clinic'));
        $this->control->pause();
        foreach (['guidance', 'bmg'] as $unit) {
            $this->assertAiFailure('dispatch_paused', fn () => $this->control->assertAllowed($profile, 1, $unit));
        }
    }
}
