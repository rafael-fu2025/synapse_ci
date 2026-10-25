<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyService;
use App\Services\NotificationService;
use Tests\Support\FreshDatabaseTestCase;
use Tests\Support\PublicationRecipientFixtures;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use RuntimeException;


/** Shared fixture is intentionally in the owned survey test file. */
abstract class SurveyTestCase extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    use PublicationRecipientFixtures;

    protected $DBGroup = 'tests';

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';
    protected int $tenant;
    protected array $admin;
    protected array $student;
    protected SurveyService $surveys;
    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        foreach (['SURVEY_KEY', 'SURVEY_KEY_VERSION', 'SURVEY_KEY_V2'] as $key) {
            $this->environment[$key] = [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null];
        }
        $this->environment('SURVEY_KEY', str_repeat('a1', 32));
        $this->environment('SURVEY_KEY_VERSION', '1');
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('survey_test_'), 'name' => 'Survey fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->admin = $this->account('superadmin', 'employee');
        $this->student = $this->account('student', 'student');
        $this->surveys = new SurveyService();
        $this->login($this->admin);
    }

    protected function tearDown(): void
    {
        // Schema-shape regressions below require surveys from prior tests to be absent.
        // Retain append-only audit history and identities until the runner drops the schema.
        if (isset($this->tenant)) {
            foreach (['notifications', 'survey_responses', 'surveys'] as $table) {
                $this->db->table($table)->where('tenant_id', $this->tenant)->delete();
            }
        }
        foreach ($this->environment as $key => [$process, $env, $server]) {
            $process === false ? putenv($key) : putenv($key . '=' . $process);
            if ($env === null) { unset($_ENV[$key]); } else { $_ENV[$key] = $env; }
            if ($server === null) { unset($_SERVER[$key]); } else { $_SERVER[$key] = $server; }
        }
        foreach (['renderer', 'security'] as $service) { Services::resetSingle($service); }
        service('superglobals')->setCookieArray([]);
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    protected function environment(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }

    protected function account(string $role, string $kind = 'student'): array
    {
        $person = (int) (new PersonModel())->insert(['tenant_id' => $this->tenant, 'kind' => $kind,
            'mis_id' => uniqid('survey_'), 'first_name' => 'Survey', 'last_name' => 'Person']);
        $now = date('Y-m-d H:i:s');
        $profile = $kind === 'student' ? 'student_profiles' : 'employee_profiles';
        $this->db->table($profile)->insert(['tenant_id' => $this->tenant, 'person_id' => $person, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('survey_user_'), 'role' => $role, 'status' => 'active']);
        // Forged cached privileges must never grant access.
        return ['id' => $id, 'tenant_id' => $this->tenant, 'person_id' => $person, 'role' => 'superadmin',
            'permissions' => ['*'], 'logged_in' => true];
    }

    protected function login(array $actor): void
    {
        $this->establishPublicationSession($actor);
    }

    protected function payload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Student experience', 'description' => 'Identified feedback.', 'audience' => 'all',
            'starts_at' => '', 'ends_at' => '', 'questions' => [
                ['type' => 'text', 'label' => 'Your feedback', 'required' => true, 'options' => []],
                ['type' => 'single', 'label' => 'Choose one', 'required' => true, 'options' => ['First', 'Second']],
                ['type' => 'multiple', 'label' => 'Choose several', 'required' => false, 'options' => ['A', 'B']],
                ['type' => 'rating', 'label' => 'Rating', 'required' => true, 'options' => []],
            ]];
    }

    protected function published(array $overrides = []): int
    {
        $this->login($this->admin);
        $survey = $this->surveys->create($this->payload($overrides));
        $id = (int) $survey['id'];
        $this->surveys->publish($id, $survey['version']);
        return $id;
    }

    protected function validAnswers(): array
    {
        return [0 => 'PRIVATE SURVEY ANSWER', 1 => '0', 2 => ['0', '1'], 3 => '5'];
    }

    protected function rejected(callable $callback, int $status): void
    {
        try { $callback(); $this->fail('Expected rejection.'); }
        catch (RuntimeException $e) { $this->assertSame($status, $e->getCode(), $e->getMessage()); }
    }
}

final class SurveyWorkflowTest extends SurveyTestCase
{
    public static function publicationAudiences(): array
    {
        return ['all' => ['all'], 'employees' => ['employees'], 'students' => ['students']];
    }

    /** @dataProvider publicationAudiences */
    public function testPublicationRecipientsAcrossOperationalRoles(string $audience): void
    {
        $cases = $this->publicationRecipientMatrix(fn (string $role, string $profile): array => $this->account($role, $profile));
        $draft = $this->surveys->create($this->payload(['audience' => $audience,
            'title' => 'SYNTHETIC PRIVATE PUBLICATION', 'description' => 'SYNTHETIC PRIVATE CONTENT']));
        $id = (int) $draft['id'];
        $reviewedDraft = $this->surveys->update($id, $this->payload(['audience' => $audience, 'version' => $draft['version'],
            'title' => 'SYNTHETIC PRIVATE PUBLICATION', 'description' => 'SYNTHETIC PRIVATE CONTENT']));
        $this->surveys->listing();
        $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults(),
            'Draft/edit/list must remain quiet.');
        $this->surveys->publish($id, $reviewedDraft['version']);
        $this->assertPublicationRecipientMatrix('survey', $id, $audience, $cases,
            fn (array $actor) => $this->login($actor), fn () => $this->surveys->form($id));
        $this->assertSame(0, $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->countAllResults(),
            'Viewing a form or reading its bell must not submit a response.');
        $this->login($this->admin);
        $count = $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults();
        $this->rejected(fn () => $this->surveys->publish($id, $reviewedDraft['version']), 409);
        // A fresh review permits an explicit published-state no-op and subsequent close.
        $published = $this->surveys->get($id);
        $this->surveys->publish($id, $published['version']);
        $this->surveys->close($id, $published['version']);
        $this->assertSame($count, $this->db->table('notifications')->where('tenant_id', $this->tenant)->countAllResults(),
            'Publish retry and closure must not fan out new notices.');
    }

    public function testSurveyInboxUsesGenericPayloadFixedPortalUrlAndPersonOwnedReadMarkers(): void
    {
        $other = $this->account('student');
        $id = $this->published(['title' => 'PRIVATE SURVEY TITLE', 'description' => 'PRIVATE SURVEY DESCRIPTION']);
        $notices = new NotificationService();
        $this->login($this->student);
        $inbox = $notices->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertCount(1, $inbox['items']);
        $item = $inbox['items'][0];
        $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($item));
        $this->assertSame('New survey', $item['title']);
        $this->assertSame('An identified Guidance survey is available in your portal.', $item['message']);
        $this->assertSame(site_url('/portal/surveys'), $item['url']);
        $this->assertNull($item['read_at']);
        $this->assertStringNotContainsString('PRIVATE SURVEY', json_encode($inbox));
        $stored = $this->db->table('notifications')->where('id', $item['id'])->get()->getRowArray();
        $this->assertSame($this->tenant, (int) $stored['tenant_id']);
        $this->assertSame((int) $this->student['person_id'], (int) $stored['recipient_person_id']);
        $this->assertSame($id, (int) $stored['survey_id']);
        $this->assertSame('survey_published', $stored['type']);
        $this->assertNull($stored['recipient_user_id']);
        $notices->markRead($item['id']);
        $read = $notices->ownInbox();
        $this->assertSame(0, $read['unread_count']);
        $this->assertNotNull($read['items'][0]['read_at']);
        $notices->markRead($item['id']);
        $this->assertSame($read, $notices->ownInbox(), 'Retrying markRead must preserve the original read marker.');
        $this->login($other);
        $otherInbox = $notices->ownInbox();
        $this->assertSame(1, $otherInbox['unread_count']);
        $this->assertCount(1, $otherInbox['items']);
        $this->assertNotSame($item['id'], $otherInbox['items'][0]['id']);
        $this->rejected(fn () => $notices->markRead($item['id']), 404);
        $this->assertSame($otherInbox, $notices->ownInbox(), 'Another person cannot read or change the owner notice.');
    }

    public function testSurveyInboxHidesFutureClosedAndExpiredNoticesAndRejectsReadMarkers(): void
    {
        $future = $this->published(['starts_at' => '2099-01-01T12:00']);
        $closed = $this->published();
        $reviewed = $this->surveys->get($closed);
        $this->surveys->close($closed, $reviewed['version']);
        $expired = $this->published();
        $this->db->table('surveys')->where('tenant_id', $this->tenant)->where('id', $expired)
            ->update(['ends_at' => '2000-01-01 00:00:00']);
        $rows = $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->where('recipient_person_id', $this->student['person_id'])->whereIn('survey_id', [$future, $closed, $expired])
            ->get()->getResultArray();
        $this->assertCount(3, $rows, 'Fanout exists; current availability must hide it rather than delete it.');
        $this->login($this->student);
        $notices = new NotificationService();
        $this->assertSame(['items' => [], 'unread_count' => 0], $notices->ownInbox());
        foreach ($rows as $row) {
            $this->rejected(fn () => $notices->markRead((int) $row['id']), 404);
            $this->assertNull($this->db->table('notifications')->where('tenant_id', $this->tenant)
                ->where('id', $row['id'])->get()->getRow('read_at'));
        }
    }

    public function testSurveyInboxRechecksProfileMembershipAndRejectsForeignTenantOwnership(): void
    {
        $id = $this->published(['audience' => 'students']);
        $notices = new NotificationService();
        $this->login($this->student);
        $inbox = $notices->ownInbox();
        $this->assertCount(1, $inbox['items']);
        $noticeId = $inbox['items'][0]['id'];
        $profile = $this->db->table('student_profiles')->where('tenant_id', $this->tenant)
            ->where('person_id', $this->student['person_id'])->get()->getRowArray();
        $this->assertNotNull($profile);
        $this->db->table('student_profiles')->where('tenant_id', $this->tenant)->where('id', $profile['id'])->delete();
        try {
            $this->assertSame(['items' => [], 'unread_count' => 0], $notices->ownInbox());
            $this->rejected(fn () => $notices->markRead($noticeId), 404);
        } finally { $this->db->table('student_profiles')->insert($profile); }
        $this->assertSame($inbox, $notices->ownInbox(), 'Restoring membership restores the same still-unread notice.');
        $original = $this->tenant;
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('survey_notice_foreign_'), 'name' => 'Foreign notice fixture',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreign = (int) $this->db->insertID();
        try {
            $this->tenant = $foreign;
            $foreignStudent = $this->account('student');
            $this->login($foreignStudent);
            $this->assertSame(['items' => [], 'unread_count' => 0], $notices->ownInbox());
            $this->rejected(fn () => $notices->markRead($noticeId), 404);
            $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $foreign)->where('survey_id', $id)->countAllResults());
            $this->assertNull($this->db->table('notifications')->where('tenant_id', $original)->where('id', $noticeId)->get()->getRow('read_at'));
        } finally {
            foreach (['student_profiles', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $foreign)->delete();
            }
            $this->db->table('tenants')->where('id', $foreign)->delete();
            $this->tenant = $original;
            $this->login($this->student);
        }
        $this->assertSame($inbox, $notices->ownInbox());
    }

    public function testDraftVersionRejectsLostUpdatesAndMissingTokens(): void
    {
        $draft = $this->surveys->create($this->payload());
        $id = (int) $draft['id'];
        $this->assertSame(1, (int) $draft['version']);
        $updated = $this->surveys->update($id, $this->payload(['version' => '1', 'title' => 'First editor wins']));
        $this->assertSame(2, (int) $updated['version']);
        foreach ([null, '1', '02', [], true] as $version) {
            $this->rejected(fn () => $this->surveys->update($id, $this->payload(['version' => $version, 'title' => 'Lost update'])), 409);
        }
        $this->assertSame('First editor wins', $this->surveys->get($id)['title']);
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->surveys->update($id, $this->payload(['version' => '2', 'title' => 'Fresh edit']));
        $this->assertSame('Fresh edit', $this->surveys->get($id)['title']);
    }

    public function testIndependentTransactionGuardLeavesCallerTransactionUntouched(): void
    {
        $this->db->transBegin();
        try {
            $this->rejected(fn () => $this->surveys->create($this->payload()), 409);
            $this->assertSame(1, $this->db->transDepth);
            $this->assertTrue($this->db->transStatus());
            $this->assertSame(0, $this->db->table('surveys')->where('tenant_id', $this->tenant)->countAllResults());
        } finally { $this->db->transRollback(); $this->db->resetTransStatus(); }
        $this->assertSame('draft', $this->surveys->create($this->payload())['status']);
    }

    public function testOwnedDatabaseFailureRollsBackAndResetsTransactionStatus(): void
    {
        $draft = $this->surveys->create($this->payload());
        unset($draft['questions']);
        $transaction = new \ReflectionMethod(SurveyService::class, 'transaction');
        $this->rejected(fn () => $transaction->invoke($this->surveys, function () use ($draft): void {
            try {
                $this->db->table('surveys')->insert($draft);
            } catch (\Throwable $e) {
                throw new RuntimeException('Deliberate duplicate-key failure.', 503, $e);
            }
            throw new RuntimeException('Database rejected duplicate key.', 503);
        }), 503);
        $this->assertSame(0, $this->db->transDepth);
        $this->assertTrue($this->db->transStatus());
        $this->assertSame('draft', $this->surveys->create($this->payload())['status']);
    }

    public function testMigrationRestartAndForwardOnlyRollbackPreserveResponses(): void
    {
        $id = $this->published(['audience' => 'students', 'target_year_levels' => [], 'target_student_categories' => []]);
        $targetColumns = $this->db->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
            AND COLUMN_NAME IN (?,?) ORDER BY COLUMN_NAME',
            [$this->db->prefixTable('surveys'), 'target_year_levels', 'target_student_categories'])->getResultArray();
        $this->assertCount(2, $targetColumns);
        $this->login($this->student);
        $response = $this->surveys->submit($id, $this->validAnswers());
        $before = $this->db->table('survey_responses')->where('id', $response)->get()->getRowArray();
        require_once APPPATH . 'Database/Migrations/2026-10-17-000002_CreateSurveys.php';
        $migration = new \App\Database\Migrations\CreateSurveys();
        $migration->up();
        $migration->up();
        try { $migration->down(); $this->fail('Rollback must preserve response history.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('forward-only', $e->getMessage()); }
        $this->assertSame($before, $this->db->table('survey_responses')->where('id', $response)->get()->getRowArray());
        $this->assertSame($targetColumns, $this->db->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?
            AND COLUMN_NAME IN (?,?) ORDER BY COLUMN_NAME',
            [$this->db->prefixTable('surveys'), 'target_year_levels', 'target_student_categories'])->getResultArray());
    }

    public function testMigrationRefusesIncompatibleStudentTargetColumnsWithoutRepair(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-17-000002_CreateSurveys.php';
        $migration = new \App\Database\Migrations\CreateSurveys();
        $table = $this->db->protectIdentifiers($this->db->prefixTable('surveys'));
        foreach (['target_year_levels', 'target_student_categories'] as $name) {
            $column = $this->db->protectIdentifiers($name);
            foreach (['VARCHAR(200) NULL DEFAULT NULL', 'TEXT NOT NULL', "TEXT NULL DEFAULT ('[]')"] as $definition) {
                // Other test classes may retain valid NULL audience targets in this disposable schema.
                // Make only the NOT NULL probe representable, then restore those exact values.
                $nullIds = $definition === 'TEXT NOT NULL'
                    ? array_column($this->db->table('surveys')->select('id')->where($name, null)->get()->getResultArray(), 'id') : [];
                try {
                    if ($nullIds !== []) {
                        $this->db->table('surveys')->whereIn('id', $nullIds)->update([$name => '[]']);
                    }
                    $this->db->query('ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $column . ' ' . $definition);
                    $before = $this->db->query('SHOW CREATE TABLE ' . $table)->getRowArray();
                    try { $migration->up(); $this->fail('Incompatible student targeting column must fail.'); }
                    catch (RuntimeException $e) {
                        $this->assertStringContainsString('Incompatible survey column: surveys.' . $name, $e->getMessage());
                    }
                    $this->assertSame($before, $this->db->query('SHOW CREATE TABLE ' . $table)->getRowArray());
                } finally {
                    $this->db->query('ALTER TABLE ' . $table . ' MODIFY COLUMN ' . $column . ' TEXT NULL DEFAULT NULL');
                    if ($nullIds !== []) {
                        $this->db->table('surveys')->whereIn('id', $nullIds)->update([$name => null]);
                        $this->assertSame(count($nullIds), $this->db->table('surveys')->whereIn('id', $nullIds)->where($name, null)->countAllResults());
                    }
                }
            }
        }
        $migration->up();
    }

    public function testMigrationRefusesUnknownLaterSurveyColumnsWithoutRepair(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-17-000002_CreateSurveys.php';
        $migration = new \App\Database\Migrations\CreateSurveys();
        $table = $this->db->protectIdentifiers($this->db->prefixTable('surveys'));
        $this->db->query('ALTER TABLE ' . $table . ' ADD COLUMN unexpected_target TEXT NULL DEFAULT NULL');
        try {
            $before = $this->db->query('SHOW CREATE TABLE ' . $table)->getRowArray();
            try { $migration->up(); $this->fail('Unknown later survey column must fail.'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('Unexpected survey columns: surveys', $e->getMessage()); }
            $this->assertSame($before, $this->db->query('SHOW CREATE TABLE ' . $table)->getRowArray());
        } finally { $this->db->query('ALTER TABLE ' . $table . ' DROP COLUMN unexpected_target'); }
        $migration->up();
    }

    public function testMigrationRefusesIncompatibleExistingSchemaWithoutRepair(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-10-17-000002_CreateSurveys.php';
        $migration = new \App\Database\Migrations\CreateSurveys();
        $table = $this->db->protectIdentifiers($this->db->prefixTable('surveys'));
        $this->db->query('ALTER TABLE ' . $table . ' ALTER COLUMN version SET DEFAULT 2');
        try {
            try { $migration->up(); $this->fail('Incompatible restart must fail.'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('Incompatible survey column: surveys.version', $e->getMessage()); }
            $default = $this->db->query('SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
                [$this->db->prefixTable('surveys'), 'version'])->getRow('COLUMN_DEFAULT');
            $this->assertSame('2', trim((string) $default, "'"));
        } finally { $this->db->query('ALTER TABLE ' . $table . ' ALTER COLUMN version SET DEFAULT 1'); }
        $migration->up();
    }

    public function testPublishIsImmutableAndNotifiesOnlyOnce(): void
    {
        $draft = $this->surveys->create($this->payload());
        $id = (int) $draft['id'];
        $this->surveys->publish($id, $draft['version']);
        $count = $this->db->table('notifications')->where('tenant_id', $this->tenant)->where('survey_id', $id)->countAllResults();
        $this->assertGreaterThan(0, $count);
        $this->rejected(fn () => $this->surveys->publish($id, $draft['version']), 409);
        // Only a fresh review of the final version may be treated as an idempotent publish.
        $published = $this->surveys->get($id);
        $this->surveys->publish($id, $published['version']);
        $this->assertSame($count, $this->db->table('notifications')->where('tenant_id', $this->tenant)->where('survey_id', $id)->countAllResults());
        $this->rejected(fn () => $this->surveys->update($id, $this->payload()), 409);
        $this->surveys->close($id, $published['version']);
        $closed = $this->surveys->get($id);
        $this->rejected(fn () => $this->surveys->publish($id, $closed['version']), 409);
        $this->login($this->student);
        $this->assertSame([], $this->surveys->available(['status' => 'available'])['items']);
        $this->assertContains($id, array_map('intval', array_column($this->surveys->available(['status' => 'closed'])['items'], 'id')));
        $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 404);
    }

    public function testResponseIsEncryptedIdentifiedAndAuditedWithoutPlaintextProjection(): void
    {
        $id = $this->published();
        $notifications = $this->db->table('notifications')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
        $this->login($this->student);
        $responseId = $this->surveys->submit($id, $this->validAnswers());
        $stored = $this->db->table('survey_responses')->where('id', $responseId)->get()->getRowArray();
        $this->assertSame((int) $this->student['person_id'], (int) $stored['person_id']);
        $this->assertStringNotContainsString('PRIVATE SURVEY ANSWER', json_encode($stored, JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertTrue($this->surveys->form($id)['submitted']);
        $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 409);
        $this->login($this->admin);
        $detail = $this->surveys->responseDetail($id, $responseId);
        $this->assertSame('PRIVATE SURVEY ANSWER', $detail['answers'][0]['value']);
        $this->assertSame('First', $detail['answers'][1]['value']);
        $this->assertSame(['A', 'B'], $detail['answers'][2]['value']);
        $audit = $this->db->table('audit_events')->where('tenant_id', $this->tenant)->get()->getResultArray();
        $this->assertStringNotContainsString('PRIVATE SURVEY ANSWER', json_encode($audit));
        $this->assertContains('guidance.survey.response_read', array_column($audit, 'event_type'));
        $this->assertSame($notifications, $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->orderBy('id')->get()->getResultArray(), 'Submission, form GET and results read must not mutate the bell.');
    }

    public function testStrictAnswerValidationDoesNotWriteAnything(): void
    {
        $id = $this->published();
        $this->login($this->student);
        foreach ([null, 'json', [0 => ['bad']], [0 => str_repeat('x', 2001)],
            $this->validAnswers() + [20 => 'unknown'],
            array_replace($this->validAnswers(), [1 => '9']),
            array_replace($this->validAnswers(), [2 => ['0', '0']]),
            array_replace($this->validAnswers(), [3 => '1.5']),
            array_replace($this->validAnswers(), [3 => true])] as $answers) {
            $this->rejected(fn () => $this->surveys->submit($id, $answers), 422);
        }
        $this->assertSame(0, $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testEveryRequiredAnswerTypeRejectsOmittedAndEmptyValues(): void
    {
        $questions = $this->payload()['questions'];
        $questions[2]['required'] = true;
        $id = $this->published(['questions' => $questions]);
        $this->login($this->student);
        foreach (array_keys($questions) as $index) {
            $missing = $this->validAnswers();
            unset($missing[$index]);
            $this->rejected(fn () => $this->surveys->submit($id, $missing), 422);
            foreach ([null, '', []] as $empty) {
                $answers = array_replace($this->validAnswers(), [$index => $empty]);
                $this->rejected(fn () => $this->surveys->submit($id, $answers), 422);
            }
        }
        $this->rejected(fn () => $this->surveys->submit($id, array_replace($this->validAnswers(), [0 => '   '])), 422);
        $this->assertSame(0, $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->countAllResults());
        $response = $this->surveys->submit($id, $this->validAnswers());
        $this->assertGreaterThan(0, $response, 'Single/multiple option index zero must count as answered.');
    }

    public function testOptionalUnansweredValuesAreStoredAsNull(): void
    {
        $questions = array_map(static fn (array $question): array => array_replace($question, ['required' => false]), $this->payload()['questions']);
        $id = $this->published(['questions' => $questions]);
        $this->login($this->student);
        $response = $this->surveys->submit($id, []);
        $this->login($this->admin);
        $this->assertSame([null, null, null, null], array_column($this->surveys->responseDetail($id, $response)['answers'], 'value'));
    }

    public function testConcurrentSubmissionsSerializeOnTheSurveyRoot(): void
    {
        $id = $this->published();
        $workers = [];
        // Inline worker keeps this test self-contained inside the owned write set.
        $code = <<<'PHP'
require getcwd() . '/tests/_support/fresh-framework-bootstrap.php';
$job = json_decode((string) fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $schema = getenv('FRESH_DATABASE_SCHEMA');
    if (!is_string($schema) || !preg_match('/^humus_fresh_[0-9a-f]{16}$/D', $schema)
        || $job['database']['database'] !== $schema || $job['database']['hostname'] !== '127.0.0.1'
        || $job['database']['DBDriver'] !== 'MySQLi' || $job['database']['DSN'] !== ''
        || $job['database']['DBPrefix'] !== '' || !empty($job['database']['failover'])
        || !empty($job['database']['pConnect']) || (int) $job['database']['port'] !== (int) getenv('FRESH_DATABASE_PORT')) {
        throw new RuntimeException('Refusing non-disposable worker database.');
    }
    $config = config('Database');
    $config->defaultGroup = 'tests';
    foreach (['default', 'tests'] as $group) {
        $config->$group = $job['database'];
        if (\Config\Database::connect($group)->query('SELECT DATABASE() AS name')->getRow('name') !== $schema) {
            throw new RuntimeException('Worker database identity mismatch.');
        }
    }
    foreach (['SURVEY_KEY' => $job['key'], 'SURVEY_KEY_VERSION' => '1', 'FUMIS_ENABLED' => 'false'] as $name => $value) {
        putenv($name . '=' . $value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    \Config\Database::connect()->query('SET SESSION innodb_lock_wait_timeout=10');
    $identity = (new \App\Services\AuthenticatedUser())->forIdentity((int) $job['actor']['id'], (int) $job['actor']['tenant_id']);
    if ($identity === null) { throw new RuntimeException('Worker identity not active.'); }
    session()->set(['user' => $job['actor'], 'tenant_id' => $identity['tenant_id'], '_auth' => [
        'started_at' => time(), 'last_seen_at' => time(), 'version' => $identity['auth_version'], 'session_id' => session_id(),
    ]]);
    \App\Services\EngagementAccess::actor();
    echo "READY\n"; flush();
    if (trim((string) fgets(STDIN)) !== 'GO') { throw new RuntimeException('Missing start barrier.'); }
    echo "ATTEMPT\n"; flush();
    $id = (new \App\Services\Guidance\SurveyService())->submit($job['survey'], $job['answers']);
    echo json_encode(['code' => 200, 'id' => $id], JSON_THROW_ON_ERROR) . "\n";
} catch (\Throwable $e) {
    echo json_encode(['code' => $e->getCode(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR) . "\n";
}
PHP;
        $this->assertTrue($this->db->transBegin());
        try {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('surveys'));
            $this->db->query('SELECT id FROM ' . $table . ' WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant, $id]);
            for ($worker = 0; $worker < 2; $worker++) {
                $process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, ROOTPATH);
                $this->assertIsResource($process);
                $workers[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 20);
                stream_set_timeout($pipes[2], 20);
                fwrite($pipes[0], json_encode(['database' => config('Database')->tests, 'actor' => $this->student,
                    'survey' => $id, 'answers' => $this->validAnswers(), 'key' => str_repeat('a1', 32)], JSON_THROW_ON_ERROR) . "\n");
                fflush($pipes[0]);
                $this->assertSame("READY\n", fgets($pipes[1]), 'Worker must verify the isolated database and refreshed actor.');
            }
            foreach ($workers as [$process, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); }
            foreach ($workers as [$process, $pipes]) { $this->assertSame("ATTEMPT\n", fgets($pipes[1])); }
            usleep(200000);
            $this->assertTrue($this->db->transCommit());
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $line = fgets($pipes[1]);
                $this->assertNotFalse($line, 'Concurrent worker timed out or exited without a result.');
                $results[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
            $codes = array_column($results, 'code');
            sort($codes);
            $this->assertSame([200, 409], $codes, json_encode($results));
            $this->assertSame(1, $this->db->table('survey_responses')->where('tenant_id', $this->tenant)->where('survey_id', $id)->countAllResults());
            $this->assertSame(1, $this->db->table('audit_events')->where('tenant_id', $this->tenant)->where('entity_id', (string) $id)
                ->where('event_type', 'guidance.survey.submitted')->countAllResults());
        } finally {
            if ($this->db->transDepth > 0) { $this->db->transRollback(); }
            $this->db->resetTransStatus();
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                if (proc_get_status($process)['running']) { proc_terminate($process); }
                proc_close($process);
            }
        }
    }

    public function testFailClosedKeyAndAuthenticatedCiphertext(): void
    {
        $survey = $this->surveys->create($this->payload());
        $id = (int) $survey['id'];
        $this->environment('SURVEY_KEY', 'invalid');
        $this->rejected(fn () => $this->surveys->publish($id, $survey['version']), 503);
        $this->assertSame('draft', $this->surveys->get($id)['status']);
        $this->environment('SURVEY_KEY', str_repeat('a1', 32));
        $this->surveys->publish($id, $survey['version']);
        $this->login($this->student);
        $this->environment('SURVEY_KEY', '');
        $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 503);
        $this->environment('SURVEY_KEY', str_repeat('a1', 32));
        $response = $this->surveys->submit($id, $this->validAnswers());
        $this->db->table('survey_responses')->where('id', $response)->update(['payload_tag' => str_repeat('x', 16)]);
        $this->login($this->admin);
        $this->rejected(fn () => $this->surveys->responseDetail($id, $response), 503);
    }

    public function testKeyRotationRetainsHistoricalDecryptionAndRejectsMissingVersion(): void
    {
        $id = $this->published();
        $this->login($this->student);
        $first = $this->surveys->submit($id, $this->validAnswers());
        $secondPerson = $this->account('student');
        $this->environment('SURVEY_KEY_VERSION', '2');
        $this->environment('SURVEY_KEY_V2', '');
        $this->login($secondPerson);
        $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 503);
        $this->environment('SURVEY_KEY_V2', str_repeat('b2', 32));
        $second = $this->surveys->submit($id, $this->validAnswers());
        $this->assertSame(2, (int) $this->db->table('survey_responses')->where('id', $second)->get()->getRow('payload_key_version'));
        $this->login($this->admin);
        $this->assertSame('PRIVATE SURVEY ANSWER', $this->surveys->responseDetail($id, $first)['answers'][0]['value']);
        $this->assertSame('PRIVATE SURVEY ANSWER', $this->surveys->responseDetail($id, $second)['answers'][0]['value']);
    }

    public function testInvalidBuilderShapesAndDatesAreRejected(): void
    {
        foreach ([['questions' => []], ['questions' => array_fill(0, 21, $this->payload()['questions'][0])],
            ['title' => ['bad']], ['audience' => 'anonymous'], ['starts_at' => '2026-02-30T12:00'],
            ['starts_at' => '2027-01-01T12:00', 'ends_at' => '2027-01-01T11:00'],
            ['questions' => [['type' => 'text', 'label' => 'Text', 'required' => false, 'options' => ['not allowed']]]],
            ['questions' => [['type' => 'single', 'label' => 'Choice', 'options' => ['same', 'same']]]],
            ['questions' => [['type' => 'single', 'label' => 'Choice', 'options' => array_fill(0, 11, 'x')]]],
            ['tenant_id' => 999]] as $override) {
            $this->rejected(fn () => $this->surveys->create($this->payload($override)), 422);
        }
    }

    public function testAudienceWindowsAndRevokedAccounts(): void
    {
        $employeeOnly = $this->published(['audience' => 'employees']);
        $future = $this->published(['starts_at' => '2099-01-01T12:00']);
        $this->login($this->student);
        $this->assertSame([], $this->surveys->available()['items']);
        foreach ([$employeeOnly, $future] as $id) {
            $this->rejected(fn () => $this->surveys->form($id), 404);
            $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 404);
        }
        $this->login($this->admin);
        $id = $this->published();
        $this->db->table('surveys')->where('id', $id)->update(['ends_at' => '2000-01-01 00:00:00']);
        $this->login($this->student);
        $this->rejected(fn () => $this->surveys->form($id), 404);
        $this->db->table('users')->where('id', $this->student['id'])->update(['status' => 'suspended']);
        $this->rejected(fn () => $this->surveys->available(), 401);
    }

    public function testResultsAreNotAvailableToCounsellorsOrForgedStudentSessions(): void
    {
        $id = $this->published();
        foreach ([$this->student, $this->account('counsellor')] as $actor) {
            $this->login($actor);
            $this->rejected(fn () => $this->surveys->responses($id), 403);
            $this->rejected(fn () => $this->surveys->get($id), 403);
            $this->rejected(fn () => $this->surveys->create($this->payload()), 403);
        }
    }

    public function testUniquenessIsPerPersonNotLogin(): void
    {
        $id = $this->published();
        $this->login($this->student);
        $this->surveys->submit($id, $this->validAnswers());
        // The current schema allows one login per person. Re-provision that login without changing the respondent identity.
        $this->db->table('users')->where('id', $this->student['id'])->update(['person_id' => null, 'status' => 'suspended']);
        $second = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $this->student['person_id'],
            'username' => uniqid('survey_second_'), 'role' => 'student', 'status' => 'active']);
        $this->login(array_replace($this->student, ['id' => $second]));
        $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 409);
    }

    public function testPaginationAndTenantIsolation(): void
    {
        for ($i = 0; $i < 26; $i++) { $this->surveys->create($this->payload()); }
        $this->assertCount(25, $this->surveys->listing()['items']);
        $this->assertCount(1, $this->surveys->listing(['page' => '2'])['items']);
        $id = (int) $this->surveys->listing()['items'][0]['id'];
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('survey_foreign_'), 'name' => 'Foreign survey fixture',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreign = (int) $this->db->insertID();
        $original = $this->tenant;
        try {
            $this->tenant = $foreign;
            $foreignAdmin = $this->account('superadmin', 'employee');
            $this->login($foreignAdmin);
            $this->rejected(fn () => $this->surveys->get($id), 404);
            $this->rejected(fn () => $this->surveys->responses($id), 404);
            $this->rejected(fn () => $this->surveys->form($id), 404);
            $this->rejected(fn () => $this->surveys->submit($id, $this->validAnswers()), 404);
        } finally {
            foreach (['employee_profiles', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $foreign)->delete();
            }
            $this->db->table('tenants')->where('id', $foreign)->delete();
            $this->tenant = $original;
            $this->login($this->admin);
        }
    }
}
