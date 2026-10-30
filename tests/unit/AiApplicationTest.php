<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Migrations\CreateAiInsights;
use App\Services\Ai\AiFailure;
use App\Services\Ai\ChatClient;
use App\Services\Ai\ClinicSnapshotBuilder;
use App\Services\Ai\DeploymentControl;
use App\Services\Ai\InsightApplication;
use App\Services\Ai\InsightGenerator;
use App\Services\Ai\InsightStore;
use App\Services\Ai\InsightWorker;
use App\Services\Ai\PayloadCipher;
use App\Services\Ai\Profile;
use App\Services\Ai\ProgramLibrary;
use CodeIgniter\Database\BaseConnection;
use Config\Ai;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Shared only by the three focused tests: no default DB, dotenv, DNS or HTTP. */
trait AiApplicationFixture
{
    protected ?BaseConnection $fixtureDb = null;
    protected InsightStore $store;
    protected Profile $profile;
    protected DeploymentControl $control;
    protected string $controlPath;
    protected string $sourceStamp = 'synthetic-source-a';
    protected DateTimeImmutable $month;
    protected int $now;
    protected int $requests = 0;
    protected array $requestBodies = [];

    protected static function fixtureProfile(array $changes = []): Profile
    {
        $config = (new ReflectionClass(Ai::class))->newInstanceWithoutConstructor();
        foreach (array_replace([
            'enabled' => true, 'governanceApproved' => true, 'programLibraryApproved' => true,
            'baseUrl' => 'https://gateway.example.test/v1', 'allowedOrigins' => 'https://gateway.example.test',
            'model' => 'org/synthetic-model', 'apiKey' => 'SYNTHETIC-SECRET', 'approvedTenants' => '1',
            'capabilityRevision' => ProgramLibrary::version() . ':' . InsightGenerator::PROMPT_VERSION,
            'encryptionKey' => base64_encode(str_repeat('k', 32)),
        ], $changes) as $key => $value) {
            $config->$key = $value;
        }
        $revision = Profile::fromConfig($config)->revision();
        $config->approvedRevision = $changes['approvedRevision'] ?? $revision;
        $config->compatibilityApprovedRevision = $changes['compatibilityApprovedRevision'] ?? $revision;
        return Profile::fromConfig($config);
    }

    protected static function fixtureActor(array $changes = []): array
    {
        return array_replace([
            'id' => 10, 'tenant_id' => 1, 'logged_in' => true, 'role' => 'clinic_staff',
            'permissions' => ['ai.insights.read', 'ai.insights.generate', 'ai.insights.review',
                'ai.insights.approve', 'clinic.encounters.read'],
        ], $changes);
    }

    protected function setUpControl(): void
    {
        $this->profile = self::fixtureProfile();
        $path = tempnam(sys_get_temp_dir(), 'ai-focused-');
        self::assertNotFalse($path);
        $this->controlPath = $path;
        unlink($path);
        $this->control = new DeploymentControl($path, fn (Profile $profile): string => $this->sourceStamp);
    }

    protected function setUpStore(bool $install = true): void
    {
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Disposable SQLite requires sqlite3.');
        }
        $this->now = time();
        $this->month = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Asia/Manila'));
        $this->fixtureDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_focused_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        if ($install) {
            require_once APPPATH . 'Database/Migrations/2026-10-28-000001_CreateAiInsights.php';
            (new CreateAiInsights(Database::forge($this->fixtureDb)))->up();
        }
        $this->store = new InsightStore($this->fixtureDb, new PayloadCipher($this->profile->config->encryptionKey), fn (): int => $this->now);
    }

    protected function tearDownFixture(): void
    {
        $this->fixtureDb?->close();
        if (isset($this->controlPath) && is_file($this->controlPath)) {
            unlink($this->controlPath);
        }
    }

    protected function seedSource(int $contributors = 10): void
    {
        $this->fixtureDb->query('CREATE TABLE ai_focused_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT)');
        $this->fixtureDb->query('CREATE TABLE ai_focused_clinic_encounters (id INTEGER PRIMARY KEY, tenant_id INTEGER,
            person_id INTEGER, status TEXT, started_at TEXT, chief_complaint TEXT)');
        for ($id = 1; $id <= $contributors; ++$id) {
            $this->fixtureDb->table('people')->insert(['id' => $id, 'tenant_id' => 1, 'name' => 'PRIVATE PERSON']);
        }
        foreach ([-2 => 200, -1 => 240] as $offset => $count) {
            $date = $this->month->modify($offset . ' months')->modify('+10 days')->format('Y-m-d H:i:s');
            for ($i = 0; $i < $count; ++$i) {
                $this->fixtureDb->table('clinic_encounters')->insert(['tenant_id' => 1, 'person_id' => $i % $contributors + 1,
                    'status' => 'completed', 'started_at' => $date, 'chief_complaint' => 'PRIVATE COMPLAINT']);
            }
        }
    }

    protected function application(?Profile $profile = null, ?InsightStore $store = null, bool $forbidCollection = false): InsightApplication
    {
        $source = $this->fixtureDb;
        if ($forbidCollection) {
            $source = $this->getMockBuilder(BaseConnection::class)->disableOriginalConstructor()->getMock();
            $source->expects(self::never())->method('escape');
            $source->expects(self::never())->method('table');
        }
        return new InsightApplication($profile ?? $this->profile, $store ?? $this->store,
            new ClinicSnapshotBuilder($source, $this->month, 10, 'Asia/Manila'), $this->control);
    }

    protected function worker(?callable $identity = null, ?callable $resolver = null, int $providerStatus = 200): InsightWorker
    {
        $client = new ChatClient($this->profile, function ($profile, $endpoint, $headers, $body, $receive) use ($providerStatus): int {
            ++$this->requests;
            $this->requestBodies[] = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $receive(json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant',
                'content' => json_encode([
                    'schema_version' => '1', 'evidence_ids' => ['m1'], 'summary' => 'Completed encounter activity increased.',
                    'interpretation' => 'This descriptive comparison does not establish clinical need or causation. Human review is required.',
                    'alternative_explanations' => ['Operating days or recording coverage may differ.'],
                    'program_ids' => ['capacity_review'], 'abstained' => false,
                ], JSON_THROW_ON_ERROR)]]]], JSON_THROW_ON_ERROR));
            return $providerStatus;
        }, $resolver ?? static fn (): array => ['8.8.8.8']);
        return new InsightWorker($this->profile, $this->store, new InsightGenerator($this->profile, $client),
            $identity ?? static fn (int $id): array => self::fixtureActor(['id' => $id]), $this->control->assertAllowed(...));
    }

    protected function assertAiFailure(string $reason, callable $action, int $status = 503): void
    {
        try {
            $action();
            self::fail('Expected controlled AI failure: ' . $reason);
        } catch (AiFailure $error) {
            self::assertSame($reason, $error->reason);
            self::assertSame($status, $error->getCode());
            self::assertNull($error->getPrevious());
        }
    }

    protected function assertNoAiRows(): void
    {
        foreach (['ai_releases', 'ai_snapshots', 'ai_jobs', 'ai_dispatch_attempts', 'ai_insights', 'ai_reviews'] as $table) {
            self::assertSame(0, $this->fixtureDb->table($table)->countAllResults(), $table);
        }
        self::assertSame(0, $this->requests);
    }
}

final class AiApplicationTest extends TestCase
{
    use AiApplicationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpControl();
        $this->setUpStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
        parent::tearDown();
    }

    public function testLocalPrepareFreezesEncryptedEvidenceAndDifferentUserReusesJobBeforeWorkerReview(): void
    {
        $this->seedSource();
        $app = $this->application();
        $actor = self::fixtureActor();
        // Local preparation is independent of the default-deny dispatch manifest.
        $evidence = $app->prepare($actor);
        self::assertSame(240, $evidence['metrics'][0]['current_value']);
        self::assertSame(0, $this->requests);
        self::assertSame([], $this->store->listJobs(1, 'clinic'));
        self::assertSame(2, $this->fixtureDb->table('ai_releases')->countAllResults());
        $snapshot = $this->fixtureDb->table('ai_snapshots')->get()->getRowArray();
        self::assertStringNotContainsString('metrics', $snapshot['payload']);
        self::assertStringNotContainsString('PRIVATE', $snapshot['payload']);
        self::assertSame($evidence, $app->prepare($actor));
        self::assertSame($snapshot, $this->fixtureDb->table('ai_snapshots')->get()->getRowArray());

        $this->control->resume($this->profile, 1);
        $job = $app->generate($actor);
        $other = self::fixtureActor(['id' => 11]);
        $reused = $app->generate($other);
        self::assertFalse($job['reused']);
        self::assertTrue($reused['reused']);
        self::assertSame($job['id'], $reused['id']);
        self::assertSame(10, $this->store->job(1, 'clinic', $job['id'])['actor_id']);
        self::assertSame(1, $this->fixtureDb->table('ai_snapshots')->countAllResults());
        self::assertSame(1, $this->fixtureDb->table('ai_jobs')->countAllResults());
        self::assertSame(0, $this->requests);

        // Dispatch consumes the frozen snapshot even when the source is no longer available.
        $this->fixtureDb->query('DROP TABLE ai_focused_clinic_encounters');
        self::assertSame(['processed' => 1, 'failed' => 0, 'outcome_unknown' => 0], $this->worker()->run(1));
        self::assertSame(1, $this->requests);
        $sent = json_decode($this->requestBodies[0]['messages'][1]['content'], true, 32, JSON_THROW_ON_ERROR);
        self::assertEquals($evidence, $sent['evidence']);
        self::assertStringNotContainsString('PRIVATE', json_encode($this->requestBodies));
        $insight = $this->store->listInsights(1, 'clinic')[0];
        self::assertSame('draft', $insight['status']);
        self::assertSame(1, $insight['version']);
        $this->assertAiFailure('invalid_review_transition', fn () => $app->review($actor, $insight['id'], 1, 'approve'), 409);
        $app->review($other, $insight['id'], 1, 'accept');
        $this->assertAiFailure('review_conflict', fn () => $app->review($actor, $insight['id'], 1, 'reject'), 409);
        $app->review($actor, $insight['id'], 2, 'approve');
        $approved = $this->store->getInsight(1, 'clinic', $insight['id']);
        self::assertSame('approved', $approved['status']);
        self::assertSame(3, $approved['version']);
        $this->assertAiFailure('invalid_review_transition', fn () => $app->review($actor, $insight['id'], 3, 'reject'), 409);
        self::assertSame(['accept', 'approve'], array_column($this->fixtureDb->table('ai_reviews')->orderBy('id')->get()->getResultArray(), 'decision'));
        self::assertSame(1, $this->requests);
    }

    public function testRejectedDraftCannotBeAcceptedOrApprovedAndStaleVersionDoesNotWriteAudit(): void
    {
        $this->seedSource();
        $this->control->resume($this->profile, 1);
        $app = $this->application();
        $app->generate(self::fixtureActor());
        $this->worker()->run(1);
        $row = $this->store->listInsights(1, 'clinic')[0];
        $app->review(self::fixtureActor(), $row['id'], 1, 'reject');
        $this->assertAiFailure('review_conflict', fn () => $app->review(self::fixtureActor(), $row['id'], 1, 'accept'), 409);
        foreach (['accept', 'approve', 'reject'] as $decision) {
            $this->assertAiFailure('invalid_review_transition', fn () => $app->review(self::fixtureActor(), $row['id'], 2, $decision), 409);
        }
        self::assertSame('rejected', $this->store->getInsight(1, 'clinic', $row['id'])['status']);
        self::assertSame(1, $this->fixtureDb->table('ai_reviews')->countAllResults());
    }

    public function testChangedSourceCannotReplaceFrozenRelease(): void
    {
        $this->seedSource();
        $app = $this->application();
        $before = $app->prepare(self::fixtureActor());
        $this->fixtureDb->table('clinic_encounters')->where('id', 440)->delete();
        $this->assertAiFailure('snapshot_changed_requires_review', fn () => $app->prepare(self::fixtureActor()), 409);
        self::assertSame(1, $this->fixtureDb->table('ai_snapshots')->countAllResults());
        self::assertSame($before, $this->store->saveSnapshot(1, 'clinic', $before)['payload']);
    }

    public static function unavailableProfiles(): iterable
    {
        yield 'disabled' => [['enabled' => false], 'disabled'];
        yield 'privacy approval' => [['governanceApproved' => false], 'governance_required'];
        yield 'tenant approval' => [['approvedTenants' => '2'], 'tenant_not_approved'];
        yield 'revision approval' => [['approvedRevision' => ''], 'revision_not_approved'];
        yield 'compatibility' => [['compatibilityApprovedRevision' => ''], 'compatibility_required'];
        yield 'library' => [['programLibraryApproved' => false], 'library_not_approved'];
    }

    #[DataProvider('unavailableProfiles')]
    public function testUnavailableDispatchDoesNotCollectOrPersist(array $changes, string $reason): void
    {
        $profile = self::fixtureProfile($changes);
        $app = $this->application($profile, forbidCollection: true);
        $panel = $app->panel(self::fixtureActor());
        self::assertSame($reason, $panel['reason']);
        self::assertSame([], $panel['jobs']);
        self::assertSame([], $panel['insights']);
        $this->assertAiFailure($reason, fn () => $app->generate(self::fixtureActor()));
        if (in_array($reason, ['governance_required', 'tenant_not_approved', 'revision_not_approved'], true)) {
            $this->assertAiFailure($reason, fn () => $app->prepare(self::fixtureActor()));
        }
        $this->assertNoAiRows();
    }

    public function testExplicitGrantsAreRequiredEvenForSuperadminBeforeAnyCollection(): void
    {
        $app = $this->application(forbidCollection: true);
        foreach (['clinic_staff', 'clinic_admin', 'superadmin'] as $role) {
            $actor = self::fixtureActor(['role' => $role, 'permissions' => []]);
            $this->assertAiFailure('access_denied', fn () => $app->panel($actor), 403);
            $this->assertAiFailure('access_denied', fn () => $app->prepare($actor), 403);
            $this->assertAiFailure('access_denied', fn () => $app->generate($actor), 403);
            foreach (['accept', 'reject', 'approve'] as $decision) {
                $this->assertAiFailure('access_denied', fn () => $app->review($actor, 1, 1, $decision), 403);
            }
        }
        foreach ([['ai.insights.generate', 'clinic.encounters.read'], ['ai.insights.read', 'ai.insights.generate']] as $permissions) {
            $this->assertAiFailure('access_denied', fn () => $app->prepare(self::fixtureActor(['permissions' => $permissions])), 403);
        }
        $this->assertNoAiRows();
    }

    public function testPanelDoesNotCollectSourceOrInferAndReadOnlyActorHasNoActions(): void
    {
        $this->control->resume($this->profile, 1);
        $panel = $this->application(forbidCollection: true)->panel(self::fixtureActor(['permissions' => ['ai.insights.read']]));
        self::assertTrue($panel['ready']);
        self::assertNull($panel['reason']);
        foreach (['canGenerate', 'canReview', 'canApprove'] as $flag) {
            self::assertFalse($panel[$flag]);
        }
        $this->assertNoAiRows();
    }

    public function testMissingPausedOrChangedDeploymentControlBlocksEnqueueBeforeCollection(): void
    {
        $app = $this->application(forbidCollection: true);
        $actor = self::fixtureActor();
        $assertBlocked = function () use ($app, $actor): void {
            $panel = $app->panel($actor);
            self::assertTrue($panel['ready']);
            self::assertSame('dispatch_paused', $panel['reason']);
            $this->assertAiFailure('dispatch_paused', fn () => $app->generate($actor));
            $this->assertNoAiRows();
        };
        $assertBlocked();
        $this->control->pause();
        $assertBlocked();
        $this->control->resume($this->profile, 1);
        $this->sourceStamp = 'changed-after-approval';
        $assertBlocked();
        $this->control->resume(self::fixtureProfile(['credentialVersion' => '2']), 1);
        $assertBlocked();
    }

    public function testUndercontributingSourceAbstainsWithoutPersistingRows(): void
    {
        $this->seedSource(9);
        $this->control->resume($this->profile, 1);
        $app = $this->application();
        $this->assertAiFailure('insufficient_data', fn () => $app->prepare(self::fixtureActor()), 422);
        $this->assertAiFailure('insufficient_data', fn () => $app->generate(self::fixtureActor()), 422);
        $this->assertNoAiRows();
    }

    public function testUninstalledEncryptedStoreReturnsCleanPanelWithoutSourceReads(): void
    {
        $this->fixtureDb->close();
        $this->setUpStore(false);
        $app = $this->application(forbidCollection: true);
        $panel = $app->panel(self::fixtureActor());
        self::assertFalse($panel['ready']);
        self::assertSame('store_unavailable', $panel['reason']);
        self::assertSame([], $panel['jobs']);
        self::assertSame([], $panel['insights']);
        self::assertSame([], $this->fixtureDb->listTables());
        $this->assertAiFailure('store_unavailable', fn () => $app->prepare(self::fixtureActor()));
        self::assertSame([], $this->fixtureDb->listTables());
    }
}
