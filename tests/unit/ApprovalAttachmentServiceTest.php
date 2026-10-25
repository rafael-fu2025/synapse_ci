<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyService;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

/** Sequential optimistic approvals and real MySQL transactions, not worker races. */
final class ApprovalAttachmentServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $tenant;
    private array $admin;
    private array $clinic;
    private array $counsellor;
    private int $readPermission;
    private SurveyService $surveys;
    private ReferralService $referrals;

    protected function setUp(): void
    {
        // FreshDatabaseTestCase must guard before DatabaseTestTrait can migrate.
        parent::setUp();
        self::assertSame('MySQLi', $this->db->DBDriver);
        self::assertSame($this->db, Database::connect());
        self::assertSame(getenv('FRESH_DATABASE_SCHEMA'), $this->db->query('SELECT DATABASE() AS name')->getRowArray()['name']);
        self::assertStringContainsString('STRICT_', $this->db->query('SELECT @@SESSION.sql_mode AS mode')->getRowArray()['mode']);
        self::assertSame(str_repeat('cd', 32), getenv('SURVEY_KEY'));
        self::assertSame(str_repeat('ab', 32), getenv('COUNSELLING_KEY'));
        AuthenticatedUser::clear();
        $this->tenant = $this->newTenant();
        CurrentTenant::set($this->tenant);
        $this->admin = $this->account('guidance_admin');
        $this->clinic = $this->account('clinic_staff');
        $this->counsellor = $this->account('counsellor');
        $permission = $this->db->table('permissions')->where('code', 'clinic.encounters.read')->get()->getRowArray();
        self::assertNotNull($permission);
        $this->readPermission = (int) $permission['id'];
        $this->surveys = new SurveyService();
        $this->referrals = new ReferralService();
        $this->login($this->admin);
        self::assertContains('guidance.surveys.manage', session('user')['permissions']);
    }

    protected function tearDown(): void
    {
        if (isset($this->readPermission)) {
            // Restore the one real role grant revoked by these tests; keep append-only
            // audit rows and synthetic identities for the runner's whole-schema cleanup.
            if ($this->db->table('role_permissions')->where('role', 'clinic_staff')
                ->where('permission_id', $this->readPermission)->countAllResults() === 0) {
                $this->db->table('role_permissions')->insert(['role' => 'clinic_staff', 'permission_id' => $this->readPermission]);
            }
            AuthenticatedUser::clear();
        }
        parent::tearDown();
    }

    private function newTenant(): int
    {
        $now = date('Y-m-d H:i:s');
        self::assertTrue($this->db->table('tenants')->insert(['slug' => uniqid('approval_'),
            'name' => 'Synthetic approval fixture', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]));
        return (int) $this->db->insertID();
    }

    private function account(string $role, ?int $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $person = (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => 'employee',
            'mis_id' => uniqid('AUTH0304_'), 'first_name' => 'Synthetic', 'last_name' => 'Employee']);
        self::assertNotFalse($person);
        $now = date('Y-m-d H:i:s');
        self::assertTrue($this->db->table('employee_profiles')->insert(['tenant_id' => $tenant,
            'person_id' => $person, 'is_teaching' => 0, 'created_at' => $now, 'updated_at' => $now]));
        $id = (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person,
            'username' => uniqid('auth0304_'), 'role' => $role, 'status' => 'active']);
        self::assertNotFalse($id);
        $identity = (new AuthenticatedUser())->forIdentity((int) $id, $tenant);
        self::assertNotNull($identity);
        self::assertSame($role, $identity['role']);
        return $identity;
    }

    private function login(array $identity): void
    {
        AuthenticatedUser::clear();
        $now = time();
        session()->set(['tenant_id' => $identity['tenant_id'], 'user' => $identity, '_auth' => [
            'started_at' => $now, 'last_seen_at' => $now, 'version' => $identity['auth_version'],
            'session_id' => session_id(),
        ]]);
        self::assertSame($identity, (new AuthenticatedUser())->current());
    }

    private function payload(array $changes = []): array
    {
        return $changes + ['title' => 'Synthetic employee feedback', 'description' => 'Synthetic test only.',
            'audience' => 'employees', 'starts_at' => '', 'ends_at' => '',
            'questions' => [['type' => 'text', 'label' => 'Synthetic feedback?', 'required' => false, 'options' => []]]];
    }

    private function rows(string $table): array
    {
        return $this->db->table($table)->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['surveys', 'survey_responses', 'referrals', 'notifications', 'audit_events', 'clinic_encounters', 'counselling_cases'] as $table) {
            $state[$table] = $this->rows($table);
        }
        return $state;
    }

    private function surveyRow(int $id): array
    {
        return $this->db->table('surveys')->where('tenant_id', $this->tenant)->where('id', $id)->get()->getRowArray();
    }

    private function rejectedUnchanged(callable $operation, int $code): void
    {
        $before = $this->snapshot();
        try {
            $operation();
            self::fail('Expected service rejection.');
        } catch (RuntimeException $error) {
            self::assertSame($code, $error->getCode(), $error->getMessage());
        }
        self::assertSame(0, $this->db->transDepth);
        self::assertTrue($this->db->transStatus());
        self::assertSame($before, $this->snapshot(), 'Denial must not mutate records, audit, or notification fanout.');
    }

    /** Observe actual SQL/depth, without replacing connections, services or authority. */
    private function transactional(callable $operation, array $writtenTables): mixed
    {
        $queries = [];
        $listener = function ($query) use (&$queries): void {
            $queries[] = [(string) $query, $this->db->transDepth];
        };
        Events::on('DBQuery', $listener);
        try {
            $result = $operation();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        self::assertSame(0, $this->db->transDepth);
        self::assertTrue($this->db->transStatus());
        $sql = implode("\n", array_column($queries, 0));
        self::assertStringContainsString('SET TRANSACTION ISOLATION LEVEL READ COMMITTED', $sql);
        self::assertStringContainsString('FOR UPDATE', $sql);
        foreach ($writtenTables as $table) {
            $writes = array_filter($queries, static fn (array $query): bool =>
                preg_match('/(?:INSERT INTO|UPDATE)\s+`?' . $table . '`?\b/i', $query[0]) === 1);
            self::assertNotEmpty($writes, 'Expected a real transactional write to ' . $table);
            foreach ($writes as [, $depth]) self::assertSame(1, $depth);
        }
        return $result;
    }

    private function assertSurveyAudit(int $id, array $actions): void
    {
        $rows = $this->db->table('audit_events')->where('tenant_id', $this->tenant)
            ->where('entity_type', 'surveys')->where('entity_id', (string) $id)->orderBy('id')->get()->getResultArray();
        self::assertSame(array_map(static fn (string $action): string => 'guidance.survey.' . $action, $actions), array_column($rows, 'event_type'));
        foreach ($rows as $row) {
            self::assertSame($this->admin['id'], (int) $row['actor_user_id']);
            self::assertSame('guidance_admin', $row['actor_role']);
            self::assertNotEmpty($row['curr_hash']);
        }
    }

    public function testPublishAndCloseCommitCurrentVersionExactlyOnceWithFanoutAndAudit(): void
    {
        $draft = $this->surveys->create($this->payload());
        $id = (int) $draft['id'];
        self::assertSame(1, (int) $draft['version']);
        self::assertSame([], $this->rows('notifications'));
        $this->transactional(fn () => $this->surveys->publish($id, '1'), ['surveys', 'notifications', 'audit_events']);
        $published = $this->surveyRow($id);
        self::assertSame('published', $published['status']);
        self::assertSame(2, (int) $published['version']);
        self::assertNotEmpty($published['published_at']);
        $notices = $this->rows('notifications');
        self::assertCount(3, $notices);
        self::assertEqualsCanonicalizing(array_column([$this->admin, $this->clinic, $this->counsellor], 'person_id'),
            array_map('intval', array_column($notices, 'recipient_person_id')));
        foreach ($notices as $notice) {
            self::assertSame($id, (int) $notice['survey_id']);
            self::assertSame('survey_published', $notice['type']);
            self::assertNull($notice['recipient_user_id']);
        }
        $this->assertSurveyAudit($id, ['created', 'published']);
        $this->rejectedUnchanged(fn () => $this->surveys->publish($id, 1), 409);
        $before = $this->snapshot();
        $this->surveys->publish($id, 2);
        self::assertSame($before, $this->snapshot());
        $this->rejectedUnchanged(fn () => $this->surveys->close($id, 1), 409);
        $this->transactional(fn () => $this->surveys->close($id, '2'), ['surveys', 'audit_events']);
        $closed = $this->surveyRow($id);
        self::assertSame('closed', $closed['status']);
        self::assertSame(3, (int) $closed['version']);
        self::assertNotEmpty($closed['closed_at']);
        self::assertSame($published['published_at'], $closed['published_at']);
        self::assertSame($notices, $this->rows('notifications'));
        $this->assertSurveyAudit($id, ['created', 'published', 'closed']);
        $this->rejectedUnchanged(fn () => $this->surveys->close($id, 2), 409);
        $before = $this->snapshot();
        $this->surveys->close($id, 3);
        self::assertSame($before, $this->snapshot());
    }

    public function testMissingMalformedAndStaleApprovalsLeaveBothTransitionsUnchanged(): void
    {
        $id = (int) $this->surveys->create($this->payload())['id'];
        $invalid = [null, '', [], ['1'], new \stdClass(), true, false, 1.0, 0, -1,
            '01', '+1', '1.0', '1e0', ' 1', "1\n", str_repeat('9', 50)];
        $this->rejectedUnchanged(fn () => $this->surveys->publish($id), 409);
        foreach (array_merge($invalid, [2]) as $version) {
            $this->rejectedUnchanged(fn () => $this->surveys->publish($id, $version), 409);
        }
        $this->surveys->publish($id, 1);
        $this->rejectedUnchanged(fn () => $this->surveys->close($id), 409);
        foreach (array_merge($invalid, [1, 3, '02']) as $version) {
            $this->rejectedUnchanged(fn () => $this->surveys->close($id, $version), 409);
        }
        $this->assertSurveyAudit($id, ['created', 'published']);
    }

    public function testEditedDraftCannotBePublishedWithAnEarlierApproval(): void
    {
        $draft = $this->surveys->create($this->payload());
        $id = (int) $draft['id'];
        $edited = $this->surveys->update($id, $this->payload(['version' => 1, 'title' => 'Edited synthetic feedback']));
        self::assertSame(2, (int) $edited['version']);
        $this->rejectedUnchanged(fn () => $this->surveys->publish($id, $draft['version']), 409);
        self::assertSame('draft', $this->surveyRow($id)['status']);
        self::assertSame([], $this->rows('notifications'));
        $this->assertSurveyAudit($id, ['created', 'updated']);
        $this->transactional(fn () => $this->surveys->publish($id, $edited['version']), ['surveys', 'notifications', 'audit_events']);
        self::assertSame(3, (int) $this->surveyRow($id)['version']);
        $this->assertSurveyAudit($id, ['created', 'updated', 'published']);
    }

    private function encounter(array $owner, array $changes = []): int
    {
        $now = date('Y-m-d H:i:s');
        self::assertTrue($this->db->table('clinic_encounters')->insert($changes + [
            'tenant_id' => $owner['tenant_id'], 'person_id' => $owner['person_id'],
            'attending_user_id' => $owner['id'], 'status' => 'in_progress',
            'chief_complaint' => 'Synthetic attachment fixture', 'started_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]));
        return (int) $this->db->insertID();
    }

    private function referralData(int $encounter, string $key = 'a'): array
    {
        return ['issuer_user_id' => $this->clinic['id'], 'patient_person_id' => $this->clinic['person_id'],
            'source_module' => 'clinic', 'target_module' => 'guidance', 'source_encounter_id' => $encounter,
            'reason_code' => 'academic_distress', 'reason_description' => 'Synthetic support request.',
            'priority' => 'routine', 'request_key' => str_repeat($key, 64)];
    }

    private function revokeRead(): void
    {
        self::assertTrue($this->db->table('role_permissions')->where('role', 'clinic_staff')
            ->where('permission_id', $this->readPermission)->delete());
        self::assertSame(1, $this->db->affectedRows());
    }

    private function attachedReferral(): array
    {
        $this->login($this->clinic);
        self::assertContains('clinic.encounters.read', session('user')['permissions']);
        self::assertContains('referrals.create', session('user')['permissions']);
        $data = $this->referralData($this->encounter($this->clinic));
        $referral = $this->transactional(fn () => $this->referrals->createReferral($data, $this->clinic), ['referrals', 'notifications']);
        self::assertSame('submitted', $referral['status']);
        self::assertSame($data['source_encounter_id'], (int) $referral['source_encounter_id']);
        self::assertCount(1, $this->rows('referrals'));
        $stored = $this->rows('referrals')[0];
        self::assertSame($data['request_key'], $stored['request_key']);
        self::assertSame('academic_distress', $stored['reason_code']);
        $notices = $this->rows('notifications');
        self::assertCount(2, $notices);
        self::assertEqualsCanonicalizing([$this->admin['id'], $this->counsellor['id']], array_map('intval', array_column($notices, 'recipient_user_id')));
        foreach ($notices as $notice) {
            self::assertSame((int) $referral['id'], (int) $notice['referral_id']);
            self::assertSame('referral_received', $notice['type']);
        }
        return [$data, $referral];
    }

    public function testAuthorizedReplayIsIdempotentButRevokedReadDeniesReplayAndNewAttachment(): void
    {
        [$data, $created] = $this->attachedReferral();
        $before = $this->snapshot();
        $replayed = $this->transactional(fn () => $this->referrals->createReferral($data, $this->clinic), []);
        self::assertSame((int) $created['id'], (int) $replayed['id']);
        self::assertSame($before, $this->snapshot());
        $this->revokeRead();
        $fresh = $this->referrals->authorize($this->clinic, 'referrals.create');
        self::assertNotContains('clinic.encounters.read', $fresh['permissions']);
        self::assertContains('clinic.encounters.write', $fresh['permissions']);
        self::assertContains('clinic.encounters.read', $this->clinic['permissions'], 'Previously loaded, genuine authority is now stale.');
        foreach ([$data, array_replace($data, ['request_key' => str_repeat('b', 64)])] as $attempt) {
            $this->rejectedUnchanged(fn () => $this->referrals->createReferral($attempt, $this->clinic), 403);
        }
        $plain = array_replace($data, ['request_key' => str_repeat('c', 64)]);
        unset($plain['source_encounter_id']);
        $createdPlain = $this->transactional(fn () => $this->referrals->createReferral($plain, $this->clinic), ['referrals', 'notifications']);
        self::assertNotSame((int) $created['id'], (int) $createdPlain['id']);
        self::assertNull($createdPlain['source_encounter_id']);
        self::assertCount(2, $this->rows('referrals'));
        self::assertCount(4, $this->rows('notifications'));
    }

    public function testForeignTenantWrongPersonAndDeletedSourcesRemainRejectedWithRealReadGrant(): void
    {
        $this->login($this->clinic);
        $foreign = $this->account('clinic_staff', $this->newTenant());
        $sources = [$this->encounter($foreign), $this->encounter($this->counsellor),
            $this->encounter($this->clinic, ['deleted_at' => date('Y-m-d H:i:s')])];
        foreach ($sources as $source) {
            $this->rejectedUnchanged(fn () => $this->referrals->createReferral($this->referralData($source), $this->clinic), 422);
        }
        self::assertSame([], $this->rows('referrals'));
        self::assertSame([], $this->rows('notifications'));
    }

    public function testReadIsRefreshedAfterActualTenantMutexBeforeReplayOrInsert(): void
    {
        [$data] = $this->attachedReferral();
        foreach ([$data, array_replace($data, ['request_key' => str_repeat('d', 64)])] as $attempt) {
            $revoked = false;
            $lookupsAfterMutex = [];
            // Deterministic in-transaction revocation at the real MySQL lock boundary.
            // Rollback restores this grant; this does not simulate a concurrent worker.
            $listener = function ($query) use (&$revoked, &$lookupsAfterMutex): void {
                $sql = (string) $query;
                if (!$revoked && preg_match('/SELECT id FROM `?tenants`? .*FOR UPDATE/i', $sql)) {
                    $revoked = true;
                    self::assertSame(1, $this->db->transDepth);
                    $this->revokeRead();
                } elseif ($revoked && $this->db->transDepth > 0
                                    && preg_match('/(?:FROM|INTO|UPDATE)\s+`?(?:referrals|clinic_encounters|notifications)`?\b/i', $sql)) {
                    $lookupsAfterMutex[] = $sql;
                }
            };
            Events::on('DBQuery', $listener);
            try {
                $this->rejectedUnchanged(fn () => $this->referrals->createReferral($attempt, $this->clinic), 403);
            } finally {
                Events::removeListener('DBQuery', $listener);
            }
            self::assertTrue($revoked, 'The real tenant FOR UPDATE must be reached.');
            self::assertSame([], $lookupsAfterMutex, 'Fresh denial must precede replay, source lookup, and backend writes.');
            self::assertContains('clinic.encounters.read', $this->referrals->authorize($this->clinic, 'referrals.create')['permissions']);
        }
    }
}
