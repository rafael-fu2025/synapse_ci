<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiInsightsController;
use App\Libraries\TablePage;
use App\Services\Ai\AiFailure;
use App\Services\Ai\InsightApplication;
use App\Services\Ai\InsightStore;
use App\Services\Ai\PayloadCipher;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use Config\Database;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Invalid requests must not instantiate profiles, collectors, sessions or provider clients. */
final class AiPanelPaginationControllerProbe extends AiInsightsController
{
    public int $applicationCalls = 0;
    public array $authority = [];

    protected function actor(string $permission): array
    {
        $this->authority[] = [$permission, $this->unit()];
        return ['id' => 10, 'tenant_id' => 1];
    }

    protected function application(): InsightApplication
    {
        ++$this->applicationCalls;
        throw new AiFailure('disabled');
    }
}

/** Run only with the main pagination-isolated-bootstrap.php: no dotenv or operational DB. */
final class AiPanelPaginationTest extends TestCase
{
    private BaseConnection $db;
    private PayloadCipher $cipher;
    private InsightStore $store;
    private const NOW = '2026-11-23 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            $this->markTestSkipped('The main no-dotenv/private SQLite pagination bootstrap is required.');
        }
        if (!extension_loaded('sqlite3') || !extension_loaded('openssl')) {
            $this->markTestSkipped('Disposable SQLite and the explicit fixture cipher are required.');
        }
        helper(['url', 'form']);
        $this->db = Database::connect([
            'DSN' => '', 'hostname' => '', 'username' => '', 'password' => '',
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'ai_page_fixture_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        // Hand-written private schema only: never execute application migrations or grant/runtime writes.
        foreach ([
            'ai_releases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, metric_code TEXT NOT NULL, month TEXT NOT NULL, fingerprint TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE(tenant_id,unit,metric_code,month)',
            'ai_snapshots' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, analysis_code TEXT NOT NULL, period TEXT NOT NULL, schema_version TEXT NOT NULL, disclosure_version TEXT NOT NULL, fingerprint TEXT NOT NULL, payload TEXT, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, UNIQUE(tenant_id,unit,analysis_code,period)',
            'ai_jobs' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, snapshot_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, profile_revision TEXT NOT NULL, model TEXT NOT NULL, dedupe_key TEXT NOT NULL UNIQUE, budget_key TEXT NOT NULL UNIQUE, active_key TEXT UNIQUE, status TEXT NOT NULL, reason TEXT, attempts INTEGER NOT NULL DEFAULT 0, lease_token TEXT, lease_expires_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, expires_at TEXT NOT NULL',
            'ai_dispatch_attempts' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, job_id INTEGER NOT NULL, day TEXT NOT NULL, slot INTEGER NOT NULL, reserved_at TEXT NOT NULL, UNIQUE(tenant_id,day,slot), UNIQUE(tenant_id,unit,job_id)',
            'ai_insights' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, job_id INTEGER NOT NULL, snapshot_id INTEGER NOT NULL, payload TEXT, status TEXT NOT NULL, version INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, expires_at TEXT NOT NULL, UNIQUE(tenant_id,unit,job_id)',
            'ai_reviews' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, insight_id INTEGER NOT NULL, actor_id INTEGER NOT NULL, version INTEGER NOT NULL, decision TEXT NOT NULL, reason TEXT, created_at TEXT NOT NULL, UNIQUE(tenant_id,unit,insight_id,version)',
        ] as $table => $columns) {
            $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable($table)) . ' (' . $columns . ')');
        }
        $this->db->resetDataCache();
        $this->cipher = new PayloadCipher(base64_encode(str_repeat('p', 32)));
        $this->store = new InsightStore($this->db, $this->cipher, static fn (): int => strtotime(self::NOW . ' UTC'), 30,
            static function (): void { throw new \LogicException('Paged reads must not emit mutations.'); });
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->close(); }
        parent::tearDown();
    }

    private function snapshot(int $id, int $tenant = 1, string $unit = 'clinic', string $analysis = 'clinic.activity', string $payload = 'invalid-off-page-snapshot'): void
    {
        self::assertTrue($this->db->table('ai_snapshots')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'unit' => $unit, 'analysis_code' => $analysis,
            'period' => '2026-10', 'schema_version' => '1', 'disclosure_version' => '1',
            'fingerprint' => hash('sha256', 'snapshot-' . $id), 'payload' => $payload,
            'created_at' => self::NOW, 'expires_at' => '2026-12-31 00:00:00',
        ]));
    }

    private function job(int $id, int $snapshot = 1, int $tenant = 1, string $unit = 'clinic', string $status = 'succeeded', string $revision = 'fixture-r1'): void
    {
        self::assertTrue($this->db->table('ai_jobs')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'unit' => $unit, 'snapshot_id' => $snapshot,
            'actor_id' => 10, 'profile_revision' => $revision, 'model' => 'synthetic-model',
            'dedupe_key' => hash('sha256', 'dedupe-' . $id), 'budget_key' => hash('sha256', 'budget-' . $id),
            'status' => $status, 'attempts' => 0, 'reason' => null,
            'created_at' => self::NOW, 'updated_at' => self::NOW, 'expires_at' => '2026-12-31 00:00:00',
        ]));
    }

    private function insight(int $id, int $snapshot = 1, int $tenant = 1, string $unit = 'clinic', string $status = 'draft', ?string $payload = null, string $expires = '2026-12-31 00:00:00'): void
    {
        self::assertTrue($this->db->table('ai_insights')->insert([
            'id' => $id, 'tenant_id' => $tenant, 'unit' => $unit, 'snapshot_id' => $snapshot, 'job_id' => $id,
            'status' => $status, 'version' => 1, 'created_at' => self::NOW, 'updated_at' => self::NOW,
            'expires_at' => $expires,
            'payload' => $payload ?? $this->cipher->encrypt($tenant, $unit, 'insight:' . $id, ['marker' => 'draft-' . $id]),
        ]));
    }

    private function seedInsights(int $count = 45): void
    {
        $this->snapshot(1);
        for ($id = 1; $id <= $count; ++$id) {
            $this->job($id);
            $this->insight($id, status: ['draft', 'accepted', 'approved', 'rejected'][($id - 1) % 4]);
        }
    }

    public function testCountsClampBeforeDecryptingOnlyCurrentPageAndLegacySignaturesStayBounded(): void
    {
        $this->seedInsights();
        $this->db->table('ai_insights')->where('id', 1)->update(['payload' => 'CORRUPT OFF PAGE']);
        $one = $this->store->listInsightsPage(1, 'clinic', TablePage::forList([], 'insights_page', 'insights_per_page', 20));
        self::assertSame(range(45, 26), array_column($one['rows'], 'id'));
        self::assertSame(['total' => 45, 'draft' => 12, 'accepted' => 11, 'approved' => 11, 'rejected' => 11, 'reviewed' => 22], $one['counts']);
        self::assertSame(['page' => 1, 'per_page' => 20, 'total' => 45, 'filtered' => 45, 'pages' => 3, 'from' => 1, 'to' => 20], $one['pagination']);
        $two = $this->store->listInsightsPage(1, 'clinic', TablePage::forList(['page' => '2'], defaultPerPage: 20));
        self::assertSame(range(25, 6), array_column($two['rows'], 'id'));
        self::assertSame('draft-25', $two['rows'][0]['draft']['marker']);
        self::assertCount(20, $this->store->listInsights(1, 'clinic'));
        self::assertCount(20, $this->store->listJobs(1, 'clinic'));
        self::assertSame('draft-25', $this->store->getInsight(1, 'clinic', 25)['draft']['marker']);
        self::assertSame(25, $this->store->job(1, 'clinic', 25)['id']);
        try {
            $this->store->listInsightsPage(1, 'clinic', TablePage::forList(['page' => '999'], defaultPerPage: 20));
            self::fail('Clamping to the last page must not bypass corrupt current-page ciphertext.');
        } catch (AiFailure $failure) {
            self::assertSame('payload_unavailable', $failure->reason);
        }
        $this->db->table('ai_insights')->where('id', 1)->update(['payload' => $this->cipher->encrypt(1, 'clinic', 'insight:1', ['marker' => 'draft-1'])]);
        $last = $this->store->listInsightsPage(1, 'clinic', TablePage::forList(['page' => '999'], defaultPerPage: 20));
        self::assertSame(3, $last['pagination']['page']);
        self::assertSame(range(5, 1), array_column($last['rows'], 'id'));
        self::assertSame(41, $last['pagination']['from']);
        foreach ([20, 25, 50, 100] as $size) {
            self::assertCount(min(45, $size), $this->store->listInsightsPage(1, 'clinic', TablePage::forList(['per_page' => $size]))['rows']);
        }
        $empty = $this->store->listInsightsPage(90, 'clinic', TablePage::forList(['page' => '999']));
        self::assertSame([], $empty['rows']);
        self::assertSame(1, $empty['pagination']['page']);
        self::assertSame(0, $empty['pagination']['from']);
        self::assertSame(0, $empty['pagination']['to']);
    }

    public function testFullScopeStatusMetricsRetainSnapshotTenantUnitAnalysisAndRetentionPredicates(): void
    {
        $this->seedInsights();
        $this->snapshot(2, analysis: 'clinic.complaints');
        $this->snapshot(3, tenant: 2);
        $this->snapshot(4, unit: 'guidance', analysis: 'guidance.activity');
        $this->insight(51, snapshot: 2, status: 'rejected');
        $this->insight(52, snapshot: 3); // Forged join must not borrow tenant 2's snapshot.
        $this->insight(53, snapshot: 4); // Forged join must not borrow another unit's snapshot.
        $this->insight(54, tenant: 2, snapshot: 3);
        $this->insight(55, unit: 'guidance', snapshot: 4);
        $this->insight(56, expires: self::NOW);
        $this->insight(57);
        $this->db->table('ai_insights')->where('id', 57)->update(['payload' => null]);
        $page = $this->store->listInsightsPage(1, 'clinic', TablePage::forList(['page' => '99'], defaultPerPage: 20), 'clinic.activity', 'reviewed');
        self::assertSame(45, $page['pagination']['total']);
        self::assertSame(22, $page['pagination']['filtered']);
        self::assertSame(2, $page['pagination']['page']);
        self::assertSame([3, 2], array_column($page['rows'], 'id'));
        self::assertSame(12, $page['counts']['draft']);
        self::assertSame(46, $this->store->listInsightsPage(1, 'clinic', TablePage::forList([]))['counts']['total']);
        self::assertSame([51], array_column($this->store->listInsightsPage(1, 'clinic', TablePage::forList([]), 'clinic.complaints')['rows'], 'id'));
        self::assertSame([54], array_column($this->store->listInsightsPage(2, 'clinic', TablePage::forList([]))['rows'], 'id'));
        self::assertSame([55], array_column($this->store->listInsightsPage(1, 'guidance', TablePage::forList([]))['rows'], 'id'));
    }

    public function testJobMetricsIncludeOffPageActiveRowsWithoutOffPageRetrySnapshotReads(): void
    {
        $snapshotTable = $this->db->escapeIdentifiers($this->db->prefixTable('ai_snapshots'));
        $jobTable = $this->db->escapeIdentifiers($this->db->prefixTable('ai_jobs'));
        $this->db->query('ALTER TABLE ' . $snapshotTable . ' ADD COLUMN materialization_generation INTEGER NOT NULL DEFAULT 1');
        foreach (['snapshot_generation INTEGER', 'pipeline_digest TEXT', 'retry_of_id INTEGER', 'root_job_id INTEGER'] as $column) {
            $this->db->query('ALTER TABLE ' . $jobTable . ' ADD COLUMN ' . $column);
        }
        $this->db->query('CREATE UNIQUE INDEX ai_page_fixture_retry_successor ON ' . $jobTable . ' (retry_of_id)');
        $this->db->query('CREATE TABLE ' . $this->db->escapeIdentifiers($this->db->prefixTable('ai_storage_audit'))
            . ' (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, unit TEXT NOT NULL, actor_id INTEGER NOT NULL, action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL, generation INTEGER NOT NULL, created_at TEXT NOT NULL)');
        $this->db->resetDataCache();
        $this->snapshot(1);
        $this->snapshot(2, analysis: 'clinic.complaints');
        for ($id = 1; $id <= 45; ++$id) {
            $this->job($id, snapshot: $id === 3 ? 2 : 1,
                status: $id === 2 ? 'running' : ($id === 3 ? 'queued' : 'failed'), revision: $id === 3 ? 'old-r1' : 'fixture-r1');
        }
        // Only this off-page failed job could reach snapshotPayload() if eagerly mapped.
        $this->db->table('ai_jobs')->where('id', 1)->update(['pipeline_digest' => str_repeat('a', 64), 'root_job_id' => 1, 'snapshot_generation' => 1]);
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            $page = $this->store->listJobsPage(1, 'clinic', TablePage::forList([], defaultPerPage: 20), profileRevision: 'fixture-r1');
        } finally { Events::removeListener('DBQuery', $listener); }
        self::assertSame(range(45, 26), array_column($page['rows'], 'id'));
        self::assertSame(['total' => 45, 'active' => 1, 'pending' => 2, 'older_active' => 1, 'history' => 44], $page['counts']);
        self::assertSame(array_fill(0, 20, false), array_column($page['rows'], 'can_retry'));
        self::assertFalse((bool) array_filter($queries, static fn (string $sql): bool => preg_match('/SELECT\s+\*\s+FROM\s+[`"]?ai_page_fixture_ai_snapshots\b/i', $sql) === 1), 'No off-page retry snapshot is materialized.');
        $last = $this->store->listJobsPage(1, 'clinic', TablePage::forList(['page' => '999'], defaultPerPage: 20), profileRevision: 'fixture-r1');
        self::assertSame(3, $last['pagination']['page']);
        self::assertSame(range(5, 1), array_column($last['rows'], 'id'));
        self::assertFalse($last['rows'][4]['can_retry'], 'Corrupt current-page retry snapshot is ineligible, never retried.');
    }

    private function controller(array $get = [], array $post = [], string $method = 'GET', string $path = '/guidance/insights/panel'): AiPanelPaginationControllerProbe
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com' . $path), null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $request->setGlobal('get', $get);
        $request->setGlobal('post', $post);
        $controller = new AiPanelPaginationControllerProbe();
        $controller->initController($request, new Response(config('App')), new NullLogger());
        return $controller;
    }

    public function testOnlyValidatedGetPageStateIsAllowedAndPostQueryRejectionIsUnchanged(): void
    {
        self::assertSame(['insights_page' => 1, 'insights_per_page' => 20, 'jobs_page' => 1, 'jobs_per_page' => 20, 'insights_status' => 'all'], InsightApplication::panelQuery([]));
        $query = ['insights_page' => '3', 'insights_per_page' => '50', 'jobs_page' => '2', 'jobs_per_page' => '25', 'insights_status' => 'reviewed'];
        self::assertSame(['insights_page' => 3, 'insights_per_page' => 50, 'jobs_page' => 2, 'jobs_per_page' => 25, 'insights_status' => 'reviewed'], InsightApplication::panelQuery($query));
        $readQuery = new \ReflectionMethod(AiInsightsController::class, 'readQuery');
        self::assertSame(InsightApplication::panelQuery($query), $readQuery->invoke($this->controller($query)));
        $invalid = [['unit' => 'clinic'], ['tenant_id' => '2'], ['analysis_code' => 'clinic.activity'], ['prompt' => 'hidden'], ['page' => '2'], ['per_page' => '20'], ['insights_status' => null], ['insights_status' => ['draft']], ['insights_status' => 'approved']];
        foreach (['insights_page', 'jobs_page'] as $key) {
            foreach (['0', '01', '-1', '1000001', '1.5', '1e2', '', [], null] as $value) { $invalid[] = [$key => $value]; }
        }
        foreach (['insights_per_page', 'jobs_per_page'] as $key) {
            foreach (['21', '020', '1000', [], null] as $value) { $invalid[] = [$key => $value]; }
        }
        foreach ($invalid as $get) {
            $controller = $this->controller($get);
            $response = $controller->panel();
            self::assertSame(422, $response->getStatusCode(), json_encode($get));
            self::assertSame('invalid_request', json_decode($response->getBody(), true)['reason']);
            self::assertSame(0, $controller->applicationCalls);
            self::assertSame([['ai.insights.read', 'guidance']], $controller->authority);
            self::assertStringContainsString('private, no-store', $response->getHeaderLine('Cache-Control'));
        }
        self::assertSame(422, $this->controller([], ['insights_page' => '2'])->panel()->getStatusCode());
        foreach (['panel', 'index'] as $action) {
            $controller = $this->controller(method: 'POST');
            $response = $controller->$action();
            self::assertSame(405, $response->getStatusCode());
            self::assertSame('GET', $response->getHeaderLine('Allow'));
            self::assertSame([], $controller->authority);
        }
        $controller = $this->controller($query, ['analysis_code' => 'guidance.activity'], 'POST', '/guidance/insights/prepare');
        self::assertSame(422, $controller->prepare()->getStatusCode());
        self::assertSame(0, $controller->applicationCalls);
    }

    public function testPanelUsesIndependentStandardPagersAndFullScopeMetricsWithLegacyFallback(): void
    {
        $data = [
            'unit' => 'guidance', 'profile' => ['revision' => 'fixture-r1'], 'ready' => true, 'reason' => null,
            'jobs' => [], 'insights' => [], 'canGenerate' => false, 'canReview' => false, 'canApprove' => false,
            'insightPagination' => TablePage::forList(['page' => 2], defaultPerPage: 20)->metadata(45, 22),
            'jobPagination' => TablePage::forList(['page' => 2, 'per_page' => 25])->metadata(51, 51),
            'insightCounts' => ['total' => 45, 'draft' => 12, 'reviewed' => 22, 'rejected' => 11],
            'jobCounts' => ['total' => 51, 'active' => 7, 'pending' => 8, 'older_active' => 1],
            'panelQuery' => ['insights_status' => 'reviewed'],
        ];
        $legacyData = $data;
        $html = view('ai/panel', $data, ['saveData' => false]);
        self::assertStringContainsString('data-ai-pending="true"', $html);
        self::assertStringContainsString('data-ai-jobs-page="2"', $html);
        self::assertStringContainsString('Showing 21–22 of 22', $html);
        self::assertStringContainsString('Showing 26–50 of 51', $html);
        self::assertStringContainsString('not only this page or status filter', $html);
        self::assertStringContainsString('All 7 active requests', $html);
        self::assertStringContainsString('No drafts match this status', $html);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//*[@data-ai-pagination]//a[@data-table-page]') as $anchor) {
            parse_str((string) parse_url($anchor->getAttribute('href'), PHP_URL_QUERY), $query);
            self::assertSame('20', $query['insights_per_page']);
            self::assertSame('25', $query['jobs_per_page']);
            self::assertSame('reviewed', $query['insights_status']);
            self::assertArrayNotHasKey('per_page', $query);
        }
        foreach (['insightPagination', 'jobPagination', 'insightCounts', 'jobCounts', 'panelQuery'] as $key) { $legacyData[$key] = null; }
        \Config\Services::resetSingle('renderer');
        $legacy = view('ai/panel', $legacyData, ['saveData' => false]);
        self::assertStringNotContainsString('data-ai-page-state', $legacy);
        self::assertStringNotContainsString('data-ai-pagination', $legacy);
        self::assertStringContainsString('No AI drafts yet', $legacy);
    }
}
