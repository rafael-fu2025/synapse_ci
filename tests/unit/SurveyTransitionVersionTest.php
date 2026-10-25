<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\SurveyController;
use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AuthenticatedUser;
use App\Services\Guidance\SurveyService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use ReflectionProperty;
use RuntimeException;

/** Private SQLite transactions, real current-role grants, no migrations or operational DB. */
final class SurveyTransitionVersionTest extends CIUnitTestCase
{
    private ?BaseConnection $fixture = null;
    private array $connections = [];
    private array $oldKey = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', ENVIRONMENT);
        if (!extension_loaded('sqlite3')) {
            $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        }
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $instances->getValue();
        $this->fixture = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000], false);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        AuthenticatedUser::clear();
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'surveys' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, title TEXT, description TEXT, audience TEXT, target_year_levels TEXT, target_student_categories TEXT, status TEXT, starts_at TEXT, ends_at TEXT, published_at TEXT, closed_at TEXT, updated_at TEXT, version INTEGER, questions_json TEXT',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'notifications' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, survey_id INTEGER',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        (new PermissionsAndRolesSeeder(config('Database'), $this->fixture))->run();
        $this->fixture->table('tenants')->insert(['id' => 1, 'is_active' => 1]);
        $this->fixture->table('people')->insert(['id' => 1, 'tenant_id' => 1, 'kind' => 'employee',
            'mis_id' => 'SYNTHETIC-1', 'first_name' => 'Synthetic', 'last_name' => 'Administrator']);
        $this->fixture->table('employee_profiles')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => 1, 'is_teaching' => 0]);
        $this->fixture->table('users')->insert(['id' => 1, 'tenant_id' => 1, 'person_id' => 1,
            'username' => 'synthetic-admin', 'role' => 'guidance_admin', 'status' => 'active']);
        foreach (['SURVEY_KEY' => str_repeat('ab', 32), 'SURVEY_KEY_VERSION' => '1'] as $name => $value) {
            $this->oldKey[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        foreach ($this->oldKey as $name => [$env, $local, $server]) {
            putenv($env === false ? $name : $name . '=' . $env);
            unset($_ENV[$name], $_SERVER[$name]);
            if ($local !== null) $_ENV[$name] = $local;
            if ($server !== null) $_SERVER[$name] = $server;
        }
        foreach (['request', 'response'] as $service) Services::resetSingle($service);
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        }
        parent::tearDown();
    }

    private function survey(string $status, int $version = 7, int $id = 11): void
    {
        $this->fixture->table('surveys')->insert(['id' => $id, 'tenant_id' => 1, 'title' => 'Synthetic survey',
            'description' => '', 'audience' => 'employees', 'status' => $status, 'version' => $version,
            'questions_json' => '[{"type":"text","label":"Synthetic question","required":false}]']);
    }

    private function row(): array
    {
        return $this->fixture->table('surveys')->where('id', 11)->get()->getRowArray();
    }

    private function conflict(callable $call): void
    {
        try { $call(); self::fail('Expected a version conflict.'); }
        catch (RuntimeException $error) {
            self::assertSame(409, $error->getCode(), $error->getMessage());
            self::assertSame('Survey changed. Reload before changing its state.', $error->getMessage());
        }
        self::assertSame(0, $this->fixture->transDepth);
        self::assertTrue($this->fixture->transStatus());
    }

    public function testInvalidVersionsRejectBeforeMutationAuditOrPublishFanout(): void
    {
        $invalid = [null, '', [], ['7'], new \stdClass(), true, false, 7.0, 0, -1, '0', '-7', '07', '+7', '7.0', '7e0', ' 7', "7\n", '6', '8', str_repeat('9', 50)];
        foreach (['publish' => ['draft', 'published'], 'close' => ['published', 'closed']] as $method => $states) {
            foreach ($states as $state) {
                $this->fixture->table('surveys')->truncate();
                $this->survey($state);
                // If publish validation/fanout is reached, this fixture fails instead of returning 409.
                $this->fixture->table('surveys')->where('id', 11)->update(['questions_json' => 'invalid-json']);
                $before = $this->row();
                foreach ($invalid as $version) {
                    $queries = [];
                    $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
                    Events::on('DBQuery', $listener);
                    try {
                        $this->conflict(fn () => (new SurveyService())->{$method}(11, $version));
                    } finally {
                        Events::removeListener('DBQuery', $listener);
                    }
                    self::assertNotEmpty($queries);
                    foreach ($queries as $query) {
                        self::assertDoesNotMatchRegularExpression('/(?:UPDATE|INSERT INTO) ["`]?surveys|audit_events|notifications/i', $query);
                    }
                    self::assertSame($before, $this->row());
                    self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
                    self::assertSame(0, $this->fixture->table('notifications')->countAllResults());
                }
                $this->conflict(fn () => (new SurveyService())->{$method}(11));
            }
        }
    }

    public function testCloseCommitsOneVersionIncrementAndAuditThenRejectsStaleRepeat(): void
    {
        $this->survey('published');
        (new SurveyService())->close(11, '7');
        $closed = $this->row();
        self::assertSame('closed', $closed['status']);
        self::assertSame(8, (int) $closed['version']);
        self::assertNotEmpty($closed['closed_at']);
        self::assertSame($closed['closed_at'], $closed['updated_at']);
        $audit = $this->fixture->table('audit_events')->get()->getResultArray();
        self::assertCount(1, $audit);
        self::assertSame('guidance.survey.closed', $audit[0]['event_type']);
        $this->conflict(fn () => (new SurveyService())->close(11, '7'));
        self::assertSame($closed, $this->row());
        self::assertSame($audit, $this->fixture->table('audit_events')->get()->getResultArray());
        (new SurveyService())->close(11, 8);
        self::assertSame($closed, $this->row());
        self::assertSame($audit, $this->fixture->table('audit_events')->get()->getResultArray());
        self::assertSame(0, $this->fixture->table('notifications')->countAllResults());
    }

    public function testPublishCurrentFinalVersionIsNoOpButOldApprovalConflicts(): void
    {
        $this->survey('published');
        $before = $this->row();
        (new SurveyService())->publish(11, '7');
        (new SurveyService())->publish(11, 7);
        $this->conflict(fn () => (new SurveyService())->publish(11, '6'));
        self::assertSame($before, $this->row());
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
        self::assertSame(0, $this->fixture->table('notifications')->countAllResults());
    }

    public function testZeroAffectedRowsRejectsBeforeAuditAndRollsBack(): void
    {
        $this->survey('published');
        $before = $this->row();
        $this->fixture->query('CREATE TRIGGER ignore_close BEFORE UPDATE ON surveys BEGIN SELECT RAISE(IGNORE); END');
        $this->conflict(fn () => (new SurveyService())->close(11, 7));
        self::assertSame($before, $this->row());
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testAuditFailureRollsBackCloseAndVersionIncrement(): void
    {
        $this->survey('published');
        $before = $this->row();
        $this->fixture->query('CREATE TRIGGER ignore_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(IGNORE); END');
        try {
            (new SurveyService())->close(11, 7);
            self::fail('An unpersisted audit must fail the transition.');
        } catch (RuntimeException $error) {
            self::assertSame(503, $error->getCode(), $error->getMessage());
        }
        self::assertSame($before, $this->row());
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
        self::assertSame(0, $this->fixture->transDepth);
        self::assertTrue($this->fixture->transStatus());
    }

    public function testRenderedTransitionFormsCarryEachReviewedRowVersionAndSubmitContracts(): void
    {
        $this->survey('draft', 7, 11);
        $this->survey('published', 9, 12);
        $data = (new SurveyService())->listing();
        self::assertSame('guidance_admin', session()->get('user')['role']);
        self::assertContains('guidance.surveys.manage', session()->get('user')['permissions']);

        Services::resetSingle('renderer');
        try {
            $html = view('surveys/index', $data + ['title' => 'Guidance Surveys',
                'baseUrl' => site_url('guidance/surveys')], ['saveData' => false]);
        } finally {
            Services::resetSingle('renderer');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { self::assertTrue($document->loadHTML($html)); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//body[contains(@class, "app-shell")]')->length);
        self::assertSame(2, $xpath->query('//*[@id="main"]//form[@data-background-submit]')->length);

        $contracts = [
            [11, 'publish', '7', 'Publish survey', 'Publish',
                'Publish this identified survey? Questions, audience and dates will become immutable.'],
            [12, 'close', '9', 'Close survey', 'Close survey',
                'Close this survey? No further submissions will be accepted. Existing responses will remain available.'],
        ];
        foreach ($contracts as [$id, $action, $version, $title, $label, $confirmation]) {
            $url = site_url('guidance/surveys/' . $id . '/' . $action);
            $forms = $xpath->query('//*[@id="main"]//form[@action="' . $url . '"]');
            self::assertSame(1, $forms->length, 'Expected the actual ' . $action . ' form.');
            $form = $forms->item(0);
            self::assertSame('post', strtolower($form->getAttribute('method')));
            self::assertTrue($form->hasAttribute('data-background-submit'));
            self::assertSame($confirmation, $form->getAttribute('data-confirm'));
            self::assertSame($title, $form->getAttribute('data-confirm-title'));
            self::assertSame($label, $form->getAttribute('data-confirm-label'));
            self::assertSame('warning', $form->getAttribute('data-confirm-tone'));
            foreach (['version' => $version, csrf_token() => csrf_hash()] as $name => $value) {
                $fields = $xpath->query('.//input[@name="' . $name . '"]', $form);
                self::assertSame(1, $fields->length, 'Expected one successful field named ' . $name);
                $field = $fields->item(0);
                self::assertSame('hidden', $field->getAttribute('type'));
                self::assertFalse($field->hasAttribute('disabled'));
                self::assertSame($value, $field->getAttribute('value'));
            }
            self::assertSame(1, $xpath->query('.//button[@type="submit" and not(@disabled)]', $form)->length);
        }
    }

    public function testControllerPreservesAjaxAndNativeFormSuccessResponses(): void
    {
        foreach ([true, false] as $ajax) {
            $this->fixture->table('surveys')->truncate();
            $this->survey('published');
            $request = Services::incomingrequest(null, false);
            $request->setMethod('POST');
            if ($ajax) $request->setHeader('X-Requested-With', 'XMLHttpRequest');
            else $request->removeHeader('X-Requested-With');
            $request->setGlobal('post', ['version' => '7']);
            Services::injectMock('request', $request);
            Services::injectMock('response', new \CodeIgniter\HTTP\Response(config('App')));
            $controller = new SurveyController();
            $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
            $response = $controller->close(11);
            self::assertSame($ajax ? 200 : 302, $response->getStatusCode());
            if ($ajax) {
                $json = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                self::assertTrue($json['success']);
                self::assertSame(site_url('guidance/surveys'), $json['redirect']);
                self::assertArrayHasKey('csrf', $json);
            } else {
                self::assertSame(site_url('guidance/surveys'), $response->getHeaderLine('Location'));
                self::assertSame('Survey closed. Existing responses remain available.', session()->getFlashdata('success'));
            }
            self::assertSame('closed', $this->row()['status']);
            self::assertSame(8, (int) $this->row()['version']);
        }
    }

    public function testControllerPassesRawVersionsAndReturnsConflictJson(): void
    {
        $this->survey('published');
        $before = $this->row();
        foreach (['publish', 'close'] as $method) {
            foreach ([null, ['7'], '6'] as $version) {
                $request = Services::incomingrequest(null, false);
                $request->setMethod('POST');
                $request->setHeader('X-Requested-With', 'XMLHttpRequest');
                $request->setGlobal('post', $version === null ? [] : ['version' => $version]);
                Services::injectMock('request', $request);
                Services::injectMock('response', new \CodeIgniter\HTTP\Response(config('App')));
                $controller = new SurveyController();
                $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
                $response = $controller->{$method}(11);
                self::assertSame(409, $response->getStatusCode());
                $json = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                self::assertFalse($json['success']);
                self::assertArrayHasKey('csrf', $json);
                self::assertSame($before, $this->row());
            }
        }
    }
}
