<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Services\AnnouncementService;
use App\Services\AuthenticatedUser;
use App\Services\EngagementAccess;
use App\Services\Guidance\QuestionBankService;
use App\Services\Guidance\SurveyService;
use App\Services\NotificationService;
use App\Services\Reporting\ExportAccess;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use ReflectionProperty;
use RuntimeException;

/** Audit repros, not fixes. No migrations, dotenv, operational DB, or authority mocks. */
final class ContentOwnershipAuditTest extends CIUnitTestCase
{
    protected $db;
    private array $connections = [];
    private array $oldKey = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', ENVIRONMENT);
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->connections = $instances->getValue();
        $this->db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000], false);
        $instances->setValue(null, ['tests' => $this->db, 'default' => $this->db]);
        self::assertSame($this->db, Database::connect());
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, is_teaching INTEGER',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, year_level INTEGER, enrollment_type TEXT, is_graduating INTEGER',
            'permissions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT, name TEXT, module TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER',
            'announcements' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, unit TEXT, title TEXT, body TEXT, audience TEXT, target_year_levels TEXT, target_student_categories TEXT, status TEXT, starts_at TEXT, ends_at TEXT, published_at TEXT, version INTEGER',
            'announcements_reads' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, announcement_id INTEGER, person_id INTEGER, read_at TEXT',
            'surveys' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, title TEXT, description TEXT, audience TEXT, target_year_levels TEXT, target_student_categories TEXT, status TEXT, starts_at TEXT, ends_at TEXT, published_at TEXT, closed_at TEXT, updated_at TEXT, version INTEGER, questions_json TEXT',
            'survey_responses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, survey_id INTEGER, person_id INTEGER, submitted_by INTEGER, submitted_at TEXT, payload_cipher BLOB, payload_nonce BLOB, payload_tag BLOB, payload_key_version INTEGER',
            'survey_question_bank' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, status TEXT, current_revision INTEGER, version INTEGER',
            'survey_question_bank_revisions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, question_id INTEGER, revision INTEGER, question_json TEXT, created_at TEXT',
            'notifications' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, type TEXT, recipient_person_id INTEGER, recipient_user_id INTEGER, appointment_id INTEGER, appointment_version INTEGER, referral_id INTEGER, announcement_id INTEGER, survey_id INTEGER, created_at TEXT, read_at TEXT',
            'referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, patient_person_id INTEGER, target_module TEXT, status TEXT',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
        ] as $table => $columns) {
            $this->db->query('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();
        foreach ([1, 2] as $tenant) $this->db->table('tenants')->insert(['id' => $tenant, 'is_active' => 1]);
        foreach ([[1, 1], [2, 1], [3, 2]] as [$id, $tenant]) {
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'kind' => 'employee',
                'mis_id' => 'SYNTHETIC-' . $id, 'first_name' => 'Synthetic', 'last_name' => 'Person' . $id]);
            $this->db->table('employee_profiles')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id, 'is_teaching' => 0]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'synthetic-' . $id, 'role' => 'guidance_admin', 'status' => 'active']);
        }
        foreach (['SURVEY_KEY' => str_repeat('ab', 32), 'SURVEY_KEY_VERSION' => '1'] as $name => $value) {
            $this->oldKey[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name . '=' . $value);
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        $this->signIn('guidance_admin');
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
        $this->db->close();
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->connections);
        parent::tearDown();
    }

    private function signIn(string $role): void
    {
        $this->db->table('users')->where('id', 1)->update(['role' => $role]);
        session()->set(['tenant_id' => 1, 'user' => ['id' => 1, 'tenant_id' => 1, 'logged_in' => true],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function denied(callable $call, int $status): void
    {
        try { $call(); self::fail('Expected denial ' . $status); }
        catch (RuntimeException $error) { self::assertSame($status, $error->getCode(), $error->getMessage()); }
    }

    private function revoke(string $role, string $permission): void
    {
        $id = $this->db->table('permissions')->where('code', $permission)->get()->getRowArray()['id'];
        $this->db->table('role_permissions')->where(['role' => $role, 'permission_id' => $id])->delete();
    }

    private function survey(int $id, int $tenant = 1, array $changes = []): void
    {
        $this->db->table('surveys')->insert($changes + ['id' => $id, 'tenant_id' => $tenant, 'title' => 'Synthetic instrument',
            'description' => '', 'audience' => 'employees', 'status' => 'published', 'published_at' => '2020-01-01 00:00:00',
            'version' => 1, 'questions_json' => '[{"type":"text","label":"Synthetic question","required":false}]']);
    }

    private function response(int $id, int $tenant, int $survey, int $person): void
    {
        $nonce = str_repeat('x', 12);
        $tag = '';
        $cipher = openssl_encrypt('[{"value":"SYNTHETIC CONFIDENTIAL"}]', 'aes-256-gcm', hex2bin(str_repeat('ab', 32)),
            OPENSSL_RAW_DATA, $nonce, $tag, "humus:survey:v1:$tenant:$survey:$person", 16);
        $this->db->table('survey_responses')->insert(['id' => $id, 'tenant_id' => $tenant, 'survey_id' => $survey,
            'person_id' => $person, 'submitted_by' => $person, 'submitted_at' => '2026-10-01 00:00:00',
            'payload_cipher' => $cipher, 'payload_nonce' => $nonce, 'payload_tag' => $tag, 'payload_key_version' => 1]);
    }

    public function testAllTwelveSeededRoleCapabilitiesUseRealIdentityAndGrants(): void
    {
        // Clinic notices, Guidance notices, bank/surveys, response read, operational export, identified export.
        $matrix = [
            'superadmin' => [true, true, true, true, true, true],
            'clinic_admin' => [true, false, false, false, true, false],
            'clinic_staff' => [false, false, false, false, false, false],
            'guidance_admin' => [false, true, true, true, true, true],
            'guidance_supervisor' => [false, true, true, true, false, false],
            'counsellor' => [false, false, false, false, false, false],
            'bmg_admin' => [false, false, false, false, true, false],
            'facilities_op' => [false, false, false, false, false, false],
            'audit_reader' => [false, false, false, false, true, false],
            'report_viewer' => [false, false, false, false, true, false],
            'student' => [false, false, false, false, false, false],
            'employee' => [false, false, false, false, false, false],
        ];
        self::assertCount(12, $this->db->table('role_permissions')->select('role')->distinct()->get()->getResultArray());
        foreach ($matrix as $role => $expected) {
            $this->signIn($role);
            $checks = [fn () => (new AnnouncementService())->manager('clinic'),
                fn () => (new AnnouncementService())->manager('guidance'), fn () => (new QuestionBankService())->actor(),
                fn () => EngagementAccess::actor('guidance.responses.read', 'guidance'),
                fn () => ExportAccess::actor(), fn () => ExportAccess::actor(true)];
            foreach ($checks as $index => $check) {
                if ($expected[$index]) self::assertSame($role, $check()['role']);
                else $this->denied($check, 403);
            }
            $actor = (new AuthenticatedUser())->current();
            foreach (['portal.records.read', 'portal.appointments.read', 'portal.appointments.create'] as $permission) {
                self::assertContains($permission, $actor['permissions'], $role);
            }
        }
    }

    public function testRevocationIsFreshButEmployeePortalBaselineIsInherited(): void
    {
        $this->revoke('guidance_admin', 'guidance.surveys.manage');
        session()->set('user', session()->get('user') + ['role' => 'superadmin', 'permissions' => ['*']]);
        $this->denied(fn () => (new QuestionBankService())->actor(), 403);
        $this->revoke('guidance_admin', 'guidance.responses.read');
        $this->denied(fn () => EngagementAccess::actor('guidance.responses.read', 'guidance'), 403);
        $this->revoke('guidance_admin', 'portal.records.read');
        self::assertContains('portal.records.read', (new AuthenticatedUser())->current()['permissions']);
        $this->revoke('employee', 'portal.records.read');
        self::assertNotContains('portal.records.read', (new AuthenticatedUser())->current()['permissions']);
        $this->signIn('superadmin');
        $this->revoke('superadmin', 'guidance.surveys.manage');
        self::assertSame('superadmin', (new QuestionBankService())->actor()['role']);
    }

    public function testInvalidIdentityTenantPersonAndVersionFailClosed(): void
    {
        foreach ([['status' => 'disabled'], ['deleted_at' => '2026-01-01'], ['person_id' => 3], ['auth_version' => 2]] as $changes) {
            $this->db->table('users')->where('id', 1)->update(['status' => 'active', 'deleted_at' => null, 'person_id' => 1, 'auth_version' => 1]);
            $this->signIn('guidance_admin');
            $this->db->table('users')->where('id', 1)->update($changes);
            self::assertNull((new AuthenticatedUser())->current());
        }
        $this->db->table('users')->where('id', 1)->update(['auth_version' => 1]);
        $this->signIn('guidance_admin');
        session()->set('tenant_id', 2);
        self::assertNull((new AuthenticatedUser())->current());
        self::assertNull((new AuthenticatedUser())->forIdentity(1, 2));
    }

    public function testBankSurveyAndAnnouncementManagerForeignIdsAreNotFound(): void
    {
        $this->survey(11);
        $this->survey(12, 2);
        self::assertSame(11, (int) (new SurveyService())->get(11)['id']);
        $this->denied(fn () => (new SurveyService())->get(12), 404);
        foreach ([[21, 1], [22, 2]] as [$id, $tenant]) {
            $this->db->table('survey_question_bank')->insert(['id' => $id, 'tenant_id' => $tenant, 'status' => 'active', 'current_revision' => 1, 'version' => 1]);
            $this->db->table('survey_question_bank_revisions')->insert(['id' => $id, 'tenant_id' => $tenant,
                'question_id' => $id, 'revision' => 1, 'question_json' => '{"label":"Synthetic"}']);
            $this->db->table('announcements')->insert(['id' => $id, 'tenant_id' => $tenant, 'unit' => 'guidance', 'status' => 'draft']);
        }
        self::assertSame(21, (int) (new QuestionBankService())->get(21)['id']);
        $this->denied(fn () => (new QuestionBankService())->get(22), 404);
        $this->denied(fn () => (new QuestionBankService())->get(21, 2), 404);
        self::assertSame(21, (int) (new AnnouncementService())->draft(21, 'guidance')['id']);
        $this->denied(fn () => (new AnnouncementService())->draft(22, 'guidance'), 404);
        $this->signIn('superadmin');
        $this->denied(fn () => (new AnnouncementService())->draft(21, 'clinic'), 404);
    }

    public function testSurveyFormUsesCurrentAudienceAndOwnSubmissionNotForeignPerson(): void
    {
        $this->survey(11);
        $this->survey(12, 2);
        $this->survey(13, changes: ['audience' => 'students']);
        $this->survey(14, changes: ['starts_at' => '2099-01-01 00:00:00']);
        $this->survey(15, changes: ['status' => 'draft', 'published_at' => null]);
        $this->response(31, 1, 11, 2);
        self::assertFalse((new SurveyService())->form(11)['submitted']);
        foreach ([12, 13, 14, 15] as $id) $this->denied(fn () => (new SurveyService())->form($id), 404);
        $this->response(32, 1, 11, 1);
        self::assertTrue((new SurveyService())->form(11)['submitted']);
    }

    public function testIdentifiedReadChecksTenantSurveyAndPersonJoinBeforeDecrypt(): void
    {
        $this->survey(11);
        $this->survey(12);
        $this->survey(13, 2);
        $this->response(31, 1, 11, 2);
        $this->response(32, 1, 12, 2);
        $this->response(33, 2, 13, 3);
        $this->response(34, 1, 11, 3); // Deliberately inconsistent cross-tenant person link.
        $service = new SurveyService();
        self::assertSame('SYNTHETIC CONFIDENTIAL', $service->responseDetail(11, 31)['answers'][0]['value']);
        foreach ([32, 33, 34] as $id) $this->denied(fn () => $service->responseDetail(11, $id), 404);
        // Same-tenant administrators reading another person's response is intended, not IDOR.
        $this->signIn('employee');
        $this->denied(fn () => $service->responseDetail(11, 31), 403);
        $this->signIn('guidance_admin');
        $this->revoke('guidance_admin', 'guidance.responses.read');
        $this->denied(fn () => $service->responseDetail(11, 31), 403);
    }

    public function testOperationalAndIdentifiedExportAreDifferentAuthorities(): void
    {
        foreach (['clinic_admin' => ['clinic'], 'guidance_admin' => ['guidance'], 'bmg_admin' => ['bmg'],
            'audit_reader' => ['clinic', 'guidance', 'bmg'], 'report_viewer' => ['clinic', 'guidance', 'bmg']] as $role => $units) {
            $this->signIn($role);
            self::assertSame($units, ExportAccess::allowedUnits(ExportAccess::actor()));
            if (count($units) === 1) $this->denied(fn () => ExportAccess::selectUnits(ExportAccess::actor(), 'all'), 403);
        }
        $this->signIn('guidance_supervisor');
        self::assertNotContains('reports.export', (new AuthenticatedUser())->current()['permissions']);
        $this->denied(fn () => ExportAccess::actor(true), 403);
        $this->signIn('guidance_admin');
        $this->survey(11);
        $this->survey(12, 2);
        $this->response(31, 1, 11, 2);
        $input = ['confirm_identified' => '1', 'from' => '2026-10-01', 'to' => '2026-10-02', 'tenant_id' => 2];
        $export = (new SurveyService())->exportResponses(11, $input);
        self::assertStringContainsString('SYNTHETIC CONFIDENTIAL', $export['csv']);
        $this->denied(fn () => (new SurveyService())->exportResponses(12, $input), 404);
        $this->revoke('guidance_admin', 'reports.export');
        $this->denied(fn () => (new SurveyService())->exportResponses(11, $input), 403);
    }

    public function testIdentifiedExportFailsClosedOnCiphertextOrAuditIntegrityFailure(): void
    {
        $this->survey(11);
        $this->response(31, 1, 11, 2);
        $input = ['confirm_identified' => '1', 'from' => '2026-10-01', 'to' => '2026-10-02'];
        $this->db->table('survey_responses')->where('id', 31)->update(['payload_tag' => str_repeat('z', 16)]);
        $this->denied(fn () => (new SurveyService())->exportResponses(11, $input), 503);
        self::assertSame(0, $this->db->table('audit_events')->countAllResults());
        $this->db->table('survey_responses')->where('id', 31)->delete();
        $this->response(31, 1, 11, 2);
        self::assertStringContainsString('SYNTHETIC CONFIDENTIAL', (new SurveyService())->exportResponses(11, $input)['csv']);
        $audit = $this->db->table('audit_events')->get()->getRowArray();
        self::assertStringNotContainsString('SYNTHETIC CONFIDENTIAL', $audit['payload_json']);
        $this->db->table('audit_events')->where('id', $audit['id'])->update(['curr_hash' => str_repeat('0', 64)]);
        $this->denied(fn () => (new SurveyService())->exportResponses(11, $input), 503);
    }

    public function testSurveyCloseRejectsStaleApprovalAndIgnoresForgedOwnership(): void
    {
        $this->survey(11, changes: ['version' => 7]);
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setGlobal('post', ['version' => '1', 'tenant_id' => '2', 'person_id' => '3']);
        Services::injectMock('request', $request);
        Services::injectMock('response', new \CodeIgniter\HTTP\Response(config('App')));
        $controller = new \App\Controllers\SurveyController();
        $controller->initController($request, service('response'), new \Psr\Log\NullLogger());
        self::assertSame(409, $controller->close(11)->getStatusCode());
        $row = $this->db->table('surveys')->where('id', 11)->get()->getRowArray();
        self::assertSame('published', $row['status']);
        self::assertSame(7, (int) $row['version']);
        self::assertSame(1, (int) $row['tenant_id']);
        self::assertSame(0, $this->db->table('audit_events')->countAllResults());

        $request->setGlobal('post', ['version' => '7', 'tenant_id' => '2', 'person_id' => '3']);
        $controller = new \App\Controllers\SurveyController();
        $controller->initController($request, new \CodeIgniter\HTTP\Response(config('App')), new \Psr\Log\NullLogger());
        self::assertSame(200, $controller->close(11)->getStatusCode());
        $row = $this->db->table('surveys')->where('id', 11)->get()->getRowArray();
        self::assertSame('closed', $row['status']);
        self::assertSame(8, (int) $row['version']);
        self::assertSame(1, (int) $row['tenant_id']);
    }

    public function testNotificationReadCannotTargetForeignRecipientOrTenant(): void
    {
        foreach ([[11, 1, 1], [12, 1, 2], [13, 2, 3]] as [$id, $tenant, $person]) {
            $this->db->table('notifications')->insert(['id' => $id, 'tenant_id' => $tenant, 'recipient_person_id' => $person,
                'type' => 'appointment_confirmed', 'appointment_id' => 99, 'appointment_version' => 1, 'created_at' => '2026-10-01 00:00:00']);
        }
        $service = new NotificationService();
        self::assertSame([11], array_column($service->ownInbox()['items'], 'id'));
        $this->denied(fn () => $service->markRead(12), 404);
        $this->denied(fn () => $service->markRead(13), 404);
        $service->markAllRead();
        self::assertNotNull($this->db->table('notifications')->where('id', 11)->get()->getRowArray()['read_at']);
        self::assertNull($this->db->table('notifications')->where('id', 12)->get()->getRowArray()['read_at']);
        self::assertNull($this->db->table('notifications')->where('id', 13)->get()->getRowArray()['read_at']);
    }

    public function testAnnouncementPortalDirectIdsRespectTenantStateAndAudience(): void
    {
        foreach ([11 => [], 12 => ['tenant_id' => 2], 13 => ['audience' => 'students'],
            14 => ['status' => 'draft'], 15 => ['starts_at' => '2099-01-01 00:00:00'],
            16 => ['ends_at' => '2020-01-02 00:00:00'], 17 => ['unit' => 'unrecognized']] as $id => $changes) {
            $this->db->table('announcements')->insert($changes + ['id' => $id, 'tenant_id' => 1, 'unit' => 'guidance',
                'title' => 'Synthetic notice', 'body' => 'Synthetic body', 'audience' => 'employees',
                'status' => 'published', 'published_at' => '2020-01-01 00:00:00', 'version' => 1]);
        }
        $this->db->table('announcements_reads')->insert(['id' => 1, 'tenant_id' => 1, 'announcement_id' => 11,
            'person_id' => 2, 'read_at' => '2026-10-01 00:00:00']);
        self::assertNull((new AnnouncementService())->show(11)['read_at']);
        foreach (range(12, 17) as $id) $this->denied(fn () => (new AnnouncementService())->show($id), 404);
    }

    public function testRealtimeTicketUsesRealActorButRemainsValidUntilExpiryAfterRevocation(): void
    {
        $config = new \Config\Realtime();
        $config->enabled = true;
        $config->signingKey = str_repeat('s', 32);
        $config->publishKey = str_repeat('p', 32);
        $config->allowedOrigins = 'http://example.com';
        $config->publicUrl = 'ws://127.0.0.1:8081';
        $config->ticketTtl = 60;
        $actor = (new AuthenticatedUser())->current();
        $grant = (new \App\Services\Realtime\TicketIssuer($config))->issue($actor, 'http://example.com');
        $claims = (new \App\Services\Realtime\TicketSigner($config->signingKey, 60))->verify($grant['ticket'], 'http://example.com');
        self::assertSame(['private.tenant.1.user.1'], $claims['channels']);
        $this->db->table('users')->where('id', 1)->update(['status' => 'disabled', 'auth_version' => 2]);
        self::assertNull((new AuthenticatedUser())->current());
        // This is a real signed grant issued before DB revocation, not a fabricated identity.
        $connection = new class {
            public array $sent = [];
            public bool $destroyed = false;
            public function send(string $message): void { $this->sent[] = $message; }
            public function destroy(): void { $this->destroyed = true; }
        };
        $hub = new \App\Services\Realtime\Hub($config);
        $hub->open($connection);
        self::assertTrue($hub->handshake($connection, 'http://example.com'));
        $hub->message($connection, json_encode(['type' => 'authenticate', 'ticket' => $grant['ticket']], JSON_THROW_ON_ERROR));
        self::assertSame(['{"type":"ready"}'], $connection->sent);
        $hub->publish('private.tenant.2.user.1', 'notifications.changed', []);
        $hub->publish('private.tenant.1.user.2', 'notifications.changed', []);
        self::assertCount(1, $connection->sent);
        $hub->publish('private.tenant.1.user.1', 'notifications.changed', []);
        self::assertCount(2, $connection->sent);
        $hub->tick($claims['exp']);
        self::assertTrue($connection->destroyed);
    }

    public function testProfileOwnerIgnoresRequestPersonAndTenantWithoutReadingStoredPhotos(): void
    {
        $request = Services::incomingrequest(null, false);
        $request->setGlobal('get', ['person_id' => '3', 'tenant_id' => '2']);
        $request->setGlobal('post', ['person_id' => '2', 'tenant_id' => '2']);
        $controller = new \App\Controllers\ProfileSettingsController(new \App\Services\ProfilePhotoService(sys_get_temp_dir() . '/content-audit-absent-' . bin2hex(random_bytes(8))));
        $controller->initController($request, new \CodeIgniter\HTTP\Response(config('App')), new \Psr\Log\NullLogger());
        $owner = new \ReflectionMethod($controller, 'owner');
        [$actor, $person] = $owner->invoke($controller);
        self::assertSame(1, $actor['person_id']);
        self::assertSame(1, (int) $person['tenant_id']);
        self::assertSame(200, $controller->photo()->getStatusCode());
        $this->db->table('users')->where('id', 1)->update(['person_id' => 3]);
        self::assertSame(401, $controller->photo()->getStatusCode());
    }
}
