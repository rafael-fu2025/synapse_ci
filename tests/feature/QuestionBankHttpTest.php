<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Migrations\CreateSurveyQuestionBank;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\Audit\AuditService;
use App\Services\Guidance\QuestionBankService;
use App\Services\Guidance\SurveyService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class QuestionBankHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = ['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'qb_', 'DBDebug' => true, 'foreignKeys' => true];
        $this->db = Database::connect();
        $this->assertSame(':memory:', $this->db->database);
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT',
            'employee_profiles' => 'person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'surveys' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, title TEXT, description TEXT, audience TEXT, status TEXT, version INTEGER DEFAULT 1, questions_json TEXT, target_year_levels TEXT, target_student_categories TEXT, starts_at TEXT, ends_at TEXT, published_at TEXT, closed_at TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT',
            'survey_responses' => 'id INTEGER PRIMARY KEY, survey_id INTEGER, payload_cipher TEXT',
        ] as $table => $columns) $this->db->query('CREATE TABLE qb_' . $table . ' (' . $columns . ')');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'superadmin', 2 => 'guidance_admin', 3 => 'guidance_supervisor', 4 => 'counsellor', 5 => 'clinic_admin',
            6 => 'student', 7 => 'employee', 8 => 'bmg_admin', 9 => 'guidance_admin', 10 => 'guidance_admin'] as $id => $role) {
            $tenant = $id === 9 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'first_name' => 'Person', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id, 'username' => 'user' . $id,
                'role' => $role, 'status' => $id === 10 ? 'suspended' : 'active']);
        }
        (new PermissionsAndRolesSeeder(config('Database')))->run();
        require_once APPPATH . 'Database/Migrations/2026-10-19-000006_CreateSurveyQuestionBank.php';
        (new CreateSurveyQuestionBank())->up();
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function identity(int $id): array
    {
        $tenant = $id === 9 ? 2 : 1;
        return ['tenant_id' => $tenant, 'user' => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function requestAs(int $id, string $method, string $path, array $input = [], bool $csrf = true)
    {
        foreach (['renderer', 'response', 'redirectresponse'] as $name) Services::resetSingle($name);
        if ($method === 'POST' && $csrf) {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $input[config('Security')->tokenName] = $hash;
        }
        return $this->withSession($this->identity($id))->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->call($method, $path, $input);
    }

    private function payload(array $changes = []): array
    {
        return $changes + ['category' => 'Service feedback', 'audience' => 'all', 'review_note' => 'General feedback, not a screening instrument.',
            'question' => ['type' => 'single', 'label' => 'Preferred service time?', 'required' => true, 'options' => ['Morning', 'Afternoon']]];
    }

    private function entry(array $changes = [], int $actor = 2): int
    {
        session()->set($this->identity($actor));
        return (new QuestionBankService())->create($this->payload($changes));
    }

    private function source(int $id, int $revision = 1): array
    {
        session()->set($this->identity(2));
        return (new QuestionBankService())->get($id, $revision)['question'] + ['bank_id' => $id, 'bank_revision' => $revision];
    }

    private function survey(array $question): array
    {
        return ['title' => 'Synthetic test survey', 'description' => '', 'audience' => 'all', 'starts_at' => '', 'ends_at' => '', 'questions' => [$question]];
    }

    public function testAuthorizedCatalogAndNiceAdminModalRender(): void
    {
        foreach ([1, 2, 3] as $actor) {
            $this->requestAs($actor, 'GET', 'guidance/question-bank')->assertStatus(200);
            $form = $this->requestAs($actor, 'GET', 'guidance/question-bank/create?fragment=1');
            $form->assertStatus(200);
            $this->assertStringContainsString('data-app-form-modal', $form->getBody());
            $this->assertStringContainsString('data-modal-submit', $form->getBody());
        }
        $this->requestAs(2, 'POST', 'guidance/question-bank/store', $this->payload())->assertStatus(200);
        $result = $this->requestAs(3, 'GET', 'guidance/question-bank/catalog');
        $result->assertStatus(200);
        $data = json_decode($result->getJSON(), true);
        $this->assertSame('Preferred service time?', $data['items'][0]['question']['label']);
        $this->assertSame(1, $data['items'][0]['revision']);
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
    }

    public function testCachedPrivilegesCannotAuthorizeAnyBankRoute(): void
    {
        $id = $this->entry();
        foreach ([4, 5, 6, 7, 8] as $actor) {
            foreach (['', '/catalog', '/create', '/' . $id, '/' . $id . '/edit'] as $suffix) {
                $this->requestAs($actor, 'GET', 'guidance/question-bank' . $suffix)->assertStatus(403);
            }
            $this->requestAs($actor, 'POST', 'guidance/question-bank/store', $this->payload())->assertStatus(403);
            $this->requestAs($actor, 'POST', 'guidance/question-bank/' . $id . '/update', $this->payload(['version' => 1]))->assertStatus(403);
            $this->requestAs($actor, 'POST', 'guidance/question-bank/' . $id . '/archive', ['version' => 1])->assertStatus(403);
        }
        $this->requestAs(10, 'GET', 'guidance/question-bank/catalog')->assertStatus(401);
    }

    public function testTenantIsolationAndPermissionRevocation(): void
    {
        $id = $this->entry([], 9);
        foreach (['/' . $id, '/' . $id . '/edit'] as $suffix) $this->requestAs(2, 'GET', 'guidance/question-bank' . $suffix)->assertStatus(404);
        $this->requestAs(2, 'POST', 'guidance/question-bank/' . $id . '/archive', ['version' => 1])->assertStatus(404);
        $data = json_decode($this->requestAs(2, 'GET', 'guidance/question-bank/catalog')->getJSON(), true);
        $this->assertSame([], $data['items']);
        $this->db->table('role_permissions')->where('role', 'guidance_admin')->delete();
        $this->requestAs(2, 'GET', 'guidance/question-bank/catalog')->assertStatus(403);
    }

    public function testRevisionHistoryAndArchiveNeverChangeStoredSurveysOrResponses(): void
    {
        $id = $this->entry();
        $source = $this->source($id);
        $survey = (new SurveyService())->create($this->survey($source));
        $published = (new SurveyService())->create($this->survey($source));
        $this->db->table('surveys')->where('id', $published['id'])->update(['status' => 'published']);
        $this->db->table('survey_responses')->insert(['id' => 1, 'survey_id' => $published['id'], 'payload_cipher' => 'unchanged encrypted fixture']);
        $before = $this->db->table('surveys')->get()->getResultArray();
        $answers = $this->db->table('survey_responses')->get()->getResultArray();
        $revised = $this->payload(['version' => 1, 'question' => ['type' => 'rating', 'label' => 'Revised rating?', 'required' => false, 'options' => []]]);
        $this->requestAs(3, 'POST', 'guidance/question-bank/' . $id . '/update', $revised)->assertStatus(200);
        $this->requestAs(2, 'POST', 'guidance/question-bank/' . $id . '/update', $revised)->assertStatus(409);
        $history = $this->requestAs(2, 'GET', 'guidance/question-bank/' . $id . '?revision=1');
        $history->assertStatus(200);
        $this->assertStringContainsString('Preferred service time?', $history->getBody());
        $this->requestAs(2, 'POST', 'guidance/question-bank/' . $id . '/archive', ['version' => 2])->assertStatus(200);
        $this->assertSame($before, $this->db->table('surveys')->get()->getResultArray());
        $this->assertSame($answers, $this->db->table('survey_responses')->get()->getResultArray());
        session()->set($this->identity(2));
        $saved = (new SurveyService())->update((int) $survey['id'], $this->survey($source) + ['version' => 1]);
        $this->assertSame($source, $saved['questions'][0], 'Saved archived copies remain editable as part of an existing draft');
        $duplicate = $this->survey($source) + ['version' => 2];
        $duplicate['questions'][] = $source;
        $this->requestAs(2, 'POST', 'guidance/surveys/' . $survey['id'] . '/update', $duplicate)->assertStatus(409);
        $this->db->table('surveys')->where('id', $survey['id'])->update(['status' => 'published']);
        $result = $this->requestAs(2, 'POST', 'guidance/surveys/' . $survey['id'] . '/update', $this->survey($source) + ['version' => 2]);
        $result->assertStatus(409);
        $this->assertTrue((new AuditService())->verifyChain(1)['valid']);
    }

    public function testArchiveExcludesNewSelectionsButRetainsHistory(): void
    {
        $id = $this->entry();
        $this->requestAs(2, 'POST', 'guidance/question-bank/' . $id . '/archive', ['version' => 1])->assertStatus(200);
        $catalog = json_decode($this->requestAs(2, 'GET', 'guidance/question-bank/catalog?status=archived')->getJSON(), true);
        $this->assertSame([], $catalog['items']);
        $this->requestAs(2, 'GET', 'guidance/question-bank?status=archived')->assertStatus(200);
        $this->requestAs(2, 'GET', 'guidance/question-bank/' . $id)->assertStatus(200);
        $this->requestAs(2, 'GET', 'guidance/question-bank/' . $id . '/edit')->assertStatus(409);
        $source = $this->source($id);
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $this->survey($source))->assertStatus(409);
        unset($source['bank_id'], $source['bank_revision']);
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $this->survey($source))->assertStatus(200);
    }

    public function testForgedSourcesAndModifiedCopiesAreRejected(): void
    {
        $id = $this->entry();
        $source = $this->source($id);
        $modified = $source;
        $modified['label'] = 'Changed but claiming original source';
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $this->survey($modified))->assertStatus(422);
        $modified = $source;
        $modified['bank_revision'] = 99;
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $this->survey($modified))->assertStatus(404);
        $foreign = $this->entry([], 9);
        $source['bank_id'] = $foreign;
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $this->survey($source))->assertStatus(404);
        $this->assertSame(0, $this->db->table('surveys')->countAllResults());
    }

    public function testValidationCsrfAndAuditFailureAreAtomic(): void
    {
        $invalid = ['category' => '', 'audience' => 'unknown', 'question' => ['type' => 'invented'], 'review_note' => str_repeat('x', 1001), 'tenant_id' => 2];
        foreach ($invalid as $key => $value) $this->requestAs(2, 'POST', 'guidance/question-bank/store', array_replace($this->payload(), [$key => $value]))->assertStatus(422);
        $this->requestAs(2, 'POST', 'guidance/question-bank/store', $this->payload(), false)->assertStatus(403);
        $this->db->query("CREATE TRIGGER qb_audit_failure BEFORE INSERT ON qb_audit_events BEGIN SELECT RAISE(ABORT, 'Simulated audit outage'); END");
        $this->requestAs(2, 'POST', 'guidance/question-bank/store', $this->payload())->assertStatus(503);
        $this->assertSame(0, $this->db->table('survey_question_bank')->countAllResults());
        $this->assertSame(0, $this->db->table('survey_question_bank_revisions')->countAllResults());
    }

    public function testSearchFiltersPaginationAndOutputEscaping(): void
    {
        $id = $this->entry(['category' => 'Wellbeing', 'audience' => 'students', 'question' => ['type' => 'text', 'label' => '<script>literal 100%_!</script>', 'required' => true, 'options' => []]]);
        $result = $this->requestAs(2, 'GET', 'guidance/question-bank?q=100%25_!&category=Wellbeing&audience=students');
        $result->assertStatus(200);
        $this->assertStringContainsString('&lt;script&gt;literal 100%_!&lt;/script&gt;', $result->getBody());
        $this->assertStringNotContainsString('<script>literal', $result->getBody());
        $data = json_decode($this->requestAs(2, 'GET', 'guidance/question-bank/catalog?audience=employees')->getJSON(), true);
        $this->assertSame([], $data['items']);
        for ($i = 0; $i < 21; $i++) $this->entry();
        $data = json_decode($this->requestAs(2, 'GET', 'guidance/question-bank/catalog?page=2')->getJSON(), true);
        $this->assertCount(2, $data['items']);
        $this->assertSame(2, $data['pages']);
        $this->requestAs(2, 'GET', 'guidance/question-bank/catalog?page=0')->assertStatus(422);
    }

    public function testBulkCopiesPreserveEachSourceAndServerEnforcesTwentyQuestionLimit(): void
    {
        $sources = [];
        for ($i = 0; $i < 3; $i++) $sources[] = $this->source($this->entry());
        $payload = $this->survey($sources[0]);
        $payload['questions'] = $sources;
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $payload)->assertStatus(200);
        $row = $this->db->table('surveys')->get()->getRowArray();
        $this->assertSame($sources, json_decode($row['questions_json'], true));
        $payload['questions'] = array_fill(0, 21, $sources[0]);
        $this->requestAs(2, 'POST', 'guidance/surveys/store', $payload)->assertStatus(422);
        $this->assertSame(1, $this->db->table('surveys')->countAllResults(), 'An over-limit batch must not create a survey');
    }

    public function testAllSupportedQuestionTypesUseTheSameContract(): void
    {
        foreach (['text', 'single', 'multiple', 'rating'] as $type) {
            $question = ['type' => $type, 'label' => 'Reusable ' . $type, 'required' => false, 'options' => in_array($type, ['single', 'multiple'], true) ? ['A', 'B'] : []];
            $id = $this->entry(['question' => $question]);
            $source = $this->source($id);
            $survey = (new SurveyService())->create($this->survey($source));
            $this->assertSame($source, $survey['questions'][0]);
        }
    }
}
