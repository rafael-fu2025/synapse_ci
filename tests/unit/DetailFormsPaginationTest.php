<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\GuidanceController;
use App\Controllers\QuestionBankController;
use App\Libraries\TablePage;
use App\Services\Api\ApiContext;
use App\Services\Crypto\EncryptionService;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\QuestionBankService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/** Guarded private SQLite only: no dotenv, application records, migrations or deployment keys. */
final class DetailFormsPaginationTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private ?array $savedApiContext = null;
    private GuidanceFormCipher $cipher;
    private EncryptionService $noteCipher;
    private array $identity = [
        'id' => 1, 'tenant_id' => 1, 'person_id' => 1, 'role' => 'guidance_supervisor',
        'username' => 'synthetic-supervisor', 'logged_in' => true,
        'permissions' => ['guidance.cases.read', 'guidance.surveys.manage'],
    ];

    protected function setUp(): void
    {
        if (!defined('PAGINATION_ISOLATED_TESTS') || PAGINATION_ISOLATED_TESTS !== true) {
            $this->markTestSkipped('Use the explicit no-dotenv pagination-isolated bootstrap.');
        }
        if (!extension_loaded('sqlite3')) $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        parent::setUp();
        $this->savedConnections = (new ReflectionProperty(Database::class, 'instances'))->getValue();
        $this->savedTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        $this->savedApiContext = (new ReflectionProperty(ApiContext::class, 'state'))->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'detail_page_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $this->fixture->initialize();
        self::assertSame(':memory:', $this->fixture->database);
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        CurrentTenant::set(1);
        ApiContext::set($this->identity, 'synthetic-pagination-token');
        helper(['url', 'form']);
        $request = Services::incomingrequest(null, false);
        $request->setMethod('GET')->setGlobal('get', []);
        $request->setHeader('Accept', 'application/json');
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response(config('App')));
        $this->cipher = new GuidanceFormCipher(str_repeat('ab', 32), 1);
        $this->noteCipher = new EncryptionService(str_repeat('ab', 32), 1);
        $this->schema();
        $this->seedAuthority();
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->savedConnections);
            (new ReflectionProperty(ApiContext::class, 'state'))->setValue(null, $this->savedApiContext);
            CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function schema(): void
    {
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, appointment_id INTEGER, counsellor_user_id INTEGER, session_number INTEGER, status TEXT, started_at TEXT, deleted_at TEXT',
            'counselling_notes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, session_id INTEGER, version INTEGER, notes_cipher BLOB, notes_nonce BLOB, notes_tag BLOB, notes_key_version INTEGER, created_at TEXT',
            'guidance_form_definitions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, internal_key TEXT, form_code TEXT, canonical_title TEXT, is_active INTEGER',
            'guidance_form_definition_revisions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, form_definition_id INTEGER, schema_version TEXT, schema_json TEXT',
            'guidance_form_instances' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, form_definition_id INTEGER, definition_revision_id INTEGER, person_id INTEGER, case_id INTEGER, session_id INTEGER, episode_id INTEGER, appointment_id INTEGER, referral_id INTEGER, intake_id INTEGER, created_by_user_id INTEGER, assigned_supervisor_user_id INTEGER, instance_number TEXT, status TEXT, finalized_by_user_id INTEGER, submission_notes TEXT, supervisor_notes TEXT, created_at TEXT, updated_at TEXT',
            'guidance_form_drafts' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_instance_id INTEGER, version INTEGER, draft_cipher BLOB, draft_nonce BLOB, draft_tag BLOB, draft_key_version INTEGER, draft_schema_version TEXT',
            'guidance_form_revisions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_instance_id INTEGER, revision_number INTEGER, snapshot_cipher BLOB, snapshot_nonce BLOB, snapshot_tag BLOB, snapshot_key_version INTEGER, schema_version TEXT, definition_revision_id INTEGER',
            'guidance_form_attestations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_instance_id INTEGER, form_revision_id INTEGER, signer_user_id INTEGER, signer_role TEXT, attested_at TEXT',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'survey_question_bank' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, current_revision INTEGER, version INTEGER',
            'survey_question_bank_revisions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, question_id INTEGER, revision INTEGER, category TEXT, audience TEXT, review_note TEXT, question_json TEXT, created_at TEXT',
        ] as $table => $columns) {
            self::assertNotFalse($this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')'));
        }
    }

    private function insert(string $table, array $row): void
    {
        self::assertTrue($this->fixture->table($table)->insert($row));
    }

    private function seedAuthority(): void
    {
        foreach ([1, 2] as $id) $this->insert('tenants', ['id' => $id, 'is_active' => 1]);
        foreach ([1, 2, 10, 11, 90] as $id) {
            $this->insert('people', ['id' => $id, 'tenant_id' => $id === 90 ? 2 : 1,
                'kind' => $id < 10 ? 'employee' : 'student', 'mis_id' => 'SYNTH-' . $id,
                'first_name' => 'Synthetic', 'last_name' => 'Person']);
        }
        foreach ([1, 2, 90] as $id) {
            $this->insert('users', ['id' => $id, 'tenant_id' => $id === 90 ? 2 : 1, 'person_id' => $id,
                'username' => 'synthetic-' . $id, 'role' => 'guidance_supervisor', 'status' => 'active']);
        }
        foreach ($this->identity['permissions'] as $index => $permission) {
            $this->insert('permissions', ['id' => $index + 1, 'code' => $permission]);
            $this->insert('role_permissions', ['role' => 'guidance_supervisor', 'permission_id' => $index + 1]);
        }
        foreach ([101 => 1, 102 => 2] as $caseId => $owner) {
            $this->insert('counselling_cases', ['id' => $caseId, 'tenant_id' => 1, 'person_id' => 10,
                'counsellor_user_id' => $owner, 'status' => 'open']);
        }
        foreach (GuidanceClinicalFormService::CANONICAL_FORM_KEYS as $key => $code) {
            $id = count($this->fixture->table('guidance_form_definitions')->get()->getResultArray()) + 1;
            $this->insert('guidance_form_definitions', ['id' => $id, 'tenant_id' => 1,
                'internal_key' => $key, 'form_code' => $code, 'canonical_title' => 'Synthetic clinical form', 'is_active' => 1]);
            $this->insert('guidance_form_definition_revisions', ['id' => $id, 'tenant_id' => 1,
                'form_definition_id' => $id, 'schema_version' => '1.0.0', 'schema_json' => '{}']);
        }
    }

    private function instance(int $id, array $changes = []): array
    {
        return array_replace(['id' => $id, 'tenant_id' => 1, 'form_definition_id' => 1,
            'definition_revision_id' => 1, 'person_id' => 10, 'case_id' => 101,
            'created_by_user_id' => 1, 'status' => 'draft', 'instance_number' => 'SYNTH-FORM-' . $id,
            'created_at' => '2026-11-01 09:00:00', 'updated_at' => '2026-11-02 09:00:00'], $changes);
    }

    private function blobs(string $table, int $id, string $prefix, array $encrypted): void
    {
        self::assertNotFalse($this->fixture->query('UPDATE ' . $this->fixture->prefixTable($table)
            . " SET {$prefix}cipher = X'" . bin2hex($encrypted['cipher'])
            . "', {$prefix}nonce = X'" . bin2hex($encrypted['nonce'])
            . "', {$prefix}tag = X'" . bin2hex($encrypted['tag']) . "' WHERE id = ?", [$id]));
    }

    private function seedCaseHistory(int $valid = 51): void
    {
        // More than a raw page of conflicting stored contexts precede every valid row.
        for ($id = 1; $id <= 27; ++$id) $this->insert('guidance_form_instances', $this->instance($id, ['person_id' => 11]));
        for ($index = 0; $index < $valid; ++$index) {
            $id = 28 + $index;
            $definition = $index % 3 + 1;
            $finalized = $definition === 3;
            $this->insert('guidance_form_instances', $this->instance($id, [
                'form_definition_id' => $definition, 'definition_revision_id' => $definition,
                'status' => $finalized ? 'finalized' : ($definition === 2 ? 'pending_cosign' : 'draft'),
            ]));
            $encrypted = $this->cipher->encrypt(json_encode(['synthetic_field' => 'form-' . $id], JSON_THROW_ON_ERROR),
                1, $id, $definition, $finalized ? 1 : 0, '1.0.0');
            $table = $finalized ? 'guidance_form_revisions' : 'guidance_form_drafts';
            $row = ['tenant_id' => 1, 'form_instance_id' => $id];
            $row += $finalized
                ? ['revision_number' => 1, 'snapshot_key_version' => 1, 'schema_version' => '1.0.0', 'definition_revision_id' => $definition]
                : ['version' => 1, 'draft_key_version' => 1, 'draft_schema_version' => '1.0.0'];
            $this->insert($table, $row);
            $revisionId = (int) $this->fixture->insertID();
            $this->blobs($table, $revisionId, $finalized ? 'snapshot_' : 'draft_', $encrypted);
            if ($finalized) {
                $this->insert('guidance_form_attestations', ['tenant_id' => 1, 'form_instance_id' => $id,
                    'form_revision_id' => $revisionId, 'signer_user_id' => 2, 'signer_role' => 'licensed_counselor',
                    'attested_at' => '2026-11-02 10:00:00']);
            }
        }
        $this->insert('guidance_form_instances', $this->instance(200, ['form_definition_id' => 999]));
        $this->insert('guidance_form_instances', $this->instance(201, ['created_by_user_id' => 90]));
        $this->insert('guidance_form_instances', $this->instance(202, ['tenant_id' => 2]));
        $this->insert('guidance_form_instances', $this->instance(203, ['case_id' => 102]));
        $this->insert('counselling_sessions', ['id' => 301, 'tenant_id' => 1, 'case_id' => 101,
            'counsellor_user_id' => 1, 'session_number' => 1, 'status' => 'completed']);
        $this->insert('counselling_notes', ['id' => 401, 'tenant_id' => 1, 'session_id' => 301, 'version' => 1,
            'notes_key_version' => 1, 'created_at' => '2026-10-31 09:00:00']);
        $this->blobs('counselling_notes', 401, 'notes_', $this->noteCipher->encrypt('SYNTHETIC-LEGACY-KEEP'));
        $this->insert('counselling_notes', ['id' => 402, 'tenant_id' => 1, 'session_id' => 301, 'version' => 2,
            'created_at' => '2026-11-03 09:00:00']);
    }

    private function clinical(): GuidanceClinicalFormService
    {
        return new GuidanceClinicalFormService(cipher: $this->cipher, encryptionService: $this->noteCipher, db: $this->fixture);
    }

    public function testCaseDisplaySlicesAfterContextExclusionsAndKeepsDecryptionAttestationsAndAudit(): void
    {
        $this->seedCaseHistory();
        $history = $this->clinical()->getClinicalHistoryForCase(101, 1, $this->identity,
            TablePage::forList(['forms_page' => '1'], 'forms_page'));
        self::assertSame(range(28, 52), array_map('intval', array_column($history['controlled_forms'], 'id')));
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 51, 'filtered' => 51, 'pages' => 3, 'from' => 1, 'to' => 25], $history['formsPagination']);
        self::assertSame(['synthetic_field' => 'form-28'], $history['controlled_forms'][0]['data']);
        self::assertSame('pending_cosign', $history['controlled_forms'][1]['status']);
        self::assertSame(2, (int) $history['controlled_forms'][2]['attestations'][0]['signer_user_id']);
        foreach (['interviews', 'contact_forms', 'contact_sheets'] as $key) self::assertCount(17, $history[$key]);
        self::assertCount(2, $history['legacy_session_notes']);
        self::assertSame($history['legacy_session_notes'], $history['session_notes']);
        self::assertSame('SYNTHETIC-LEGACY-KEEP', $history['legacy_session_notes'][0]['decrypted_text']);
        self::assertCount(53, $history['timeline']);
        $audits = $this->fixture->table('audit_events')->get()->getResultArray();
        self::assertCount(52, $audits);
        self::assertSame(range(28, 78), array_map('intval', array_column(array_values(array_filter($audits,
            static fn (array $row): bool => $row['entity_type'] === 'guidance_form_instances')), 'entity_id')));
        foreach ($audits as $audit) self::assertNull($audit['payload_json'], 'Read audits remain metadata-only.');
    }

    public function testCasePagesClampOnAuthorizedTotalsAndLegacyCallerRemainsComplete(): void
    {
        $this->seedCaseHistory();
        $service = $this->clinical();
        foreach ([['2', 25, range(53, 77)], ['1000000', 25, [78]], ['2', 50, [78]], ['1000000', 100, range(28, 78)]] as [$page, $size, $ids]) {
            $history = $service->getClinicalHistoryForCase(101, 1, $this->identity,
                TablePage::forList(['forms_page' => $page, 'per_page' => (string) $size], 'forms_page'));
            self::assertSame($ids, array_map('intval', array_column($history['controlled_forms'], 'id')));
            self::assertSame(51, $history['formsPagination']['total']);
            self::assertCount(53, $history['timeline']);
        }
        $legacy = $service->getClinicalHistoryForCase(101, 1, $this->identity);
        self::assertCount(51, $legacy['controlled_forms']);
        self::assertArrayNotHasKey('formsPagination', $legacy);
        self::assertSame($legacy['timeline'], $history['timeline']);
        self::assertSame($legacy['legacy_session_notes'], $history['legacy_session_notes']);
    }

    public function testCaseWithOnlyExcludedInstancesHasEmptyMetadataButRetainsLegacyHistory(): void
    {
        $this->seedCaseHistory(0);
        $history = $this->clinical()->getClinicalHistoryForCase(101, 1, $this->identity,
            TablePage::forList(['forms_page' => '999'], 'forms_page'));
        self::assertSame([], $history['controlled_forms']);
        self::assertSame(['page' => 1, 'per_page' => 25, 'total' => 0, 'filtered' => 0, 'pages' => 1, 'from' => 0, 'to' => 0], $history['formsPagination']);
        self::assertCount(2, $history['timeline']);
        self::assertSame(1, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testCaseReadDenialHappensBeforeFormOrLegacyNoteSelection(): void
    {
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = strtolower((string) $query); };
        Events::on('DBQuery', $listener);
        try {
            try {
                $this->clinical()->getClinicalHistoryForCase(102, 1, $this->identity, TablePage::forList([]));
                self::fail('A foreign-owned case must not supply any history.');
            } catch (DomainException $exception) {
                self::assertSame(403, $exception->getCode());
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertStringNotContainsString('guidance_form_instances', $query);
            self::assertStringNotContainsString('counselling_notes', $query);
        }
    }

    public function testCasePaginationDoesNotBypassAnAuditedReadFailure(): void
    {
        $this->seedCaseHistory(1);
        $this->fixture->query('DROP TABLE ' . $this->fixture->prefixTable('audit_events'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(503);
        $this->clinical()->getClinicalHistoryForCase(101, 1, $this->identity,
            TablePage::forList(['forms_page' => '1000000'], 'forms_page'));
    }

    private function seedQuestionHistory(int $total = 53, string $status = 'active'): void
    {
        $this->insert('survey_question_bank', ['id' => 5, 'tenant_id' => 1, 'status' => $status, 'current_revision' => $total, 'version' => $total]);
        for ($revision = 1; $revision <= $total; ++$revision) {
            $this->insert('survey_question_bank_revisions', ['tenant_id' => 1, 'question_id' => 5, 'revision' => $revision,
                'category' => 'Synthetic', 'audience' => 'all', 'review_note' => '',
                'question_json' => json_encode(['type' => 'text', 'label' => 'Synthetic revision ' . $revision, 'required' => true, 'options' => []], JSON_THROW_ON_ERROR),
                'created_at' => '2026-11-01 09:00:00']);
        }
        foreach ([[1, 6], [2, 5]] as [$tenant, $question]) {
            for ($revision = 100; $revision < 130; ++$revision) {
                $this->insert('survey_question_bank_revisions', ['tenant_id' => $tenant, 'question_id' => $question,
                    'revision' => $revision, 'question_json' => 'not-json', 'created_at' => '2026-11-01 09:00:00']);
            }
        }
    }

    public function testQuestionHistoryCountsAndLimitsOnlyTenantQuestionMetadataWithSelectedVersionIndependent(): void
    {
        $this->seedQuestionHistory();
        // An old body need not decode merely to list its revision metadata.
        $this->fixture->table('survey_question_bank_revisions')->where(['tenant_id' => 1, 'question_id' => 5, 'revision' => 4])->update(['question_json' => 'not-json']);
        $queries = [];
        $listener = static function ($query) use (&$queries): void {
            $queries[] = preg_replace('/\s+/', ' ', str_replace(['"', '`'], '', (string) $query));
        };
        Events::on('DBQuery', $listener);
        try {
            $service = new QuestionBankService();
            $history = $service->history(5, TablePage::forList(['history_page' => '2', 'per_page' => '25', 'page' => ['ignored'], 'forms_page' => ['ignored']], 'history_page'));
            $selected = $service->get(5, '1');
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertSame(range(28, 4), array_map('intval', array_column($history['revisions'], 'revision')));
        self::assertSame(['page' => 2, 'per_page' => 25, 'total' => 53, 'filtered' => 53, 'pages' => 3, 'from' => 26, 'to' => 50], $history['historyPagination']);
        self::assertSame('Synthetic revision 1', $selected['question']['label']);
        foreach ($history['revisions'] as $revision) self::assertSame(['revision', 'created_at'], array_keys($revision));
        $counts = array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'COUNT(*)') && str_contains($query, 'survey_question_bank_revisions')));
        $lists = array_values(array_filter($queries, static fn (string $query): bool => str_starts_with($query, 'SELECT revision, created_at')));
        self::assertCount(1, $counts);
        self::assertCount(1, $lists);
        self::assertStringNotContainsString('LIMIT', $counts[0]);
        self::assertStringNotContainsString('question_json', $counts[0]);
        self::assertStringContainsString('tenant_id = 1', $lists[0]);
        self::assertStringContainsString('question_id = 5', $lists[0]);
        self::assertMatchesRegularExpression('/ORDER BY revision DESC LIMIT (?:25 OFFSET 25|25, 25)$/', $lists[0]);
        self::assertLessThan(array_search($lists[0], $queries, true), array_search($counts[0], $queries, true));
    }

    public function testQuestionHistoryClampsPreservesArchivedReadsAndKeepsLegacyCallersUnpaged(): void
    {
        $this->seedQuestionHistory(status: 'archived');
        $service = new QuestionBankService();
        $history = $service->history(5, TablePage::forList(['history_page' => '1000000'], 'history_page'));
        self::assertSame([3, 2, 1], array_map('intval', array_column($history['revisions'], 'revision')));
        self::assertSame(['page' => 3, 'per_page' => 25, 'total' => 53, 'filtered' => 53, 'pages' => 3, 'from' => 51, 'to' => 53], $history['historyPagination']);
        $legacy = $service->history(5);
        self::assertSame('archived', $legacy['entry']['status']);
        self::assertCount(53, $legacy['revisions']);
        self::assertSame(range(53, 1), array_map('intval', array_column($legacy['revisions'], 'revision')));
        self::assertArrayNotHasKey('historyPagination', $legacy);
        self::assertSame($legacy['entry'], $history['entry']);
    }

    public static function malformedPages(): iterable
    {
        foreach ([['history_page' => ['2']], ['history_page' => '0'], ['history_page' => '-1'],
            ['history_page' => '1.5'], ['history_page' => '1000001'], ['history_page' => null],
            ['per_page' => ['25']], ['per_page' => '13']] as $index => $query) {
            yield 'malformed pagination ' . $index => [$query];
        }
    }

    #[DataProvider('malformedPages')]
    public function testQuestionShowReturns422Not503BeforeAnyQuestionRead(array $query): void
    {
        service('request')->setGlobal('get', $query);
        $controller = new QuestionBankController();
        $controller->initController(service('request'), service('response'), new NullLogger());
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            $response = $controller->show(5);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertSame(422, $response->getStatusCode());
        self::assertFalse(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)['success']);
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame([], $queries);
    }

    public function testCaseShowValidatesFormsPageOutsideItsOptionalHistoryFallback(): void
    {
        service('request')->setGlobal('get', ['forms_page' => ['2']]);
        $controller = (new ReflectionClass(GuidanceController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(GuidanceController::class, 'actorResolver'))->setValue($controller, fn (): array => $this->identity);
        $controller->initController(service('request'), service('response'), new NullLogger());
        $response = $controller->caseView(101);
        self::assertSame(422, $response->getStatusCode());
        self::assertNull((new ReflectionProperty(GuidanceController::class, 'caseService'))->getValue($controller));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }
}
