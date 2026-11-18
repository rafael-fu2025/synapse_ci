<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\AuditService;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceClinicalFormService;
use App\Services\Guidance\GuidanceFormContextPolicy;
use App\Services\Guidance\GuidanceFormService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

/** Private synthetic SQLite only; no dotenv, migrations, persistent DB, or deployment keys. */
final class GuidanceFormContextPolicyTest extends TestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private array $identity = ['id' => 1, 'tenant_id' => 1];
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormContextPolicy $policy;
    private GuidanceFormService $generic;
    private GuidanceClinicalFormService $clinical;
    private GuidanceFormCipher $cipher;

    protected function setUp(): void
    {
        parent::setUp();
        $bootstrap = realpath(dirname(__DIR__) . '/_support/guidance-read-bootstrap.php');
        if (!defined('ENVIRONMENT') || ENVIRONMENT !== 'testing'
            || !in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
            $this->markTestSkipped('The no-dotenv private SQLite guidance-read bootstrap is required.');
        }
        if (!extension_loaded('sqlite3')) $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->savedTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'form_guard_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        CurrentTenant::set(1);
        $this->schema();
        $this->seed();
        $this->accessPolicy = new GuidanceAccessPolicy(identitySource: fn (): array => $this->identity, db: $this->fixture);
        $this->policy = new GuidanceFormContextPolicy(db: $this->fixture);
        $this->cipher = new GuidanceFormCipher(str_repeat('ab', 32), 1);
        $this->generic = new GuidanceFormService(accessPolicy: $this->accessPolicy, cipher: $this->cipher, db: $this->fixture);
        $this->clinical = new GuidanceClinicalFormService(accessPolicy: $this->accessPolicy, cipher: $this->cipher, db: $this->fixture);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, $this->savedConnections);
            CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function schema(): void
    {
        foreach ([
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, last_name TEXT, email TEXT, phone TEXT, date_of_birth TEXT, gender TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER, PRIMARY KEY(role, permission_id)',
            'guidance_practitioner_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, credential_tier TEXT, professional_standing TEXT, credential_expiry TEXT, is_active INTEGER, service_scope_json TEXT',
            'guidance_supervision_assignments' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, associate_user_id INTEGER, supervisor_user_id INTEGER, status TEXT, starts_at TEXT, ends_at TEXT',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, status TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, appointment_id INTEGER, counsellor_user_id INTEGER, session_number INTEGER, status TEXT, started_at TEXT, ended_at TEXT, deleted_at TEXT',
            'counselling_notes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, session_id INTEGER, version INTEGER, notes_cipher BLOB, notes_nonce BLOB, notes_tag BLOB, notes_key_version INTEGER, created_at TEXT',
            'guidance_episodes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, case_id INTEGER, intake_referral_id INTEGER, primary_counselor_user_id INTEGER, deleted_at TEXT',
            'guidance_appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, episode_id INTEGER, form_instance_id INTEGER, deleted_at TEXT',
            'guidance_call_slips' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, episode_id INTEGER, form_instance_id INTEGER, deleted_at TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, deleted_at TEXT',
            'referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, patient_person_id INTEGER, target_case_id INTEGER, source_module TEXT, target_module TEXT',
            'guidance_intake_referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, subject_person_id INTEGER, linked_case_id INTEGER, clinic_referral_id INTEGER',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level INTEGER, student_id TEXT',
            'guidance_form_definitions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, internal_key TEXT, form_code TEXT, canonical_title TEXT, is_active INTEGER',
            'guidance_form_definition_revisions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, form_definition_id INTEGER, schema_version TEXT, schema_json TEXT, revision_number TEXT',
            'guidance_form_instances' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_definition_id INTEGER, definition_revision_id INTEGER, person_id INTEGER, case_id INTEGER, session_id INTEGER, episode_id INTEGER, appointment_id INTEGER, referral_id INTEGER, intake_id INTEGER, created_by_user_id INTEGER, assigned_supervisor_user_id INTEGER, instance_number TEXT, status TEXT, finalized_by_user_id INTEGER, finalized_at TEXT, retention_due_at TEXT, submission_notes TEXT, supervisor_notes TEXT, submitted_for_cosign_at TEXT, created_at TEXT, updated_at TEXT',
            'guidance_form_drafts' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_instance_id INTEGER, version INTEGER, draft_cipher BLOB, draft_nonce BLOB, draft_tag BLOB, draft_key_version INTEGER, draft_schema_version TEXT, updated_by_user_id INTEGER, created_at TEXT, updated_at TEXT',
            'guidance_form_revisions' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_instance_id INTEGER, revision_number INTEGER, revision_type TEXT, snapshot_hash TEXT, snapshot_cipher BLOB, snapshot_nonce BLOB, snapshot_tag BLOB, snapshot_key_version INTEGER, schema_version TEXT, definition_revision_id INTEGER, created_by_user_id INTEGER, created_at TEXT',
            'guidance_form_attestations' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, form_instance_id INTEGER, form_revision_id INTEGER, signer_user_id INTEGER, signer_person_id INTEGER, signer_role TEXT, signer_auth_version INTEGER, credential_snapshot TEXT, attestation_statement_hash TEXT, snapshot_hash TEXT, attested_at TEXT, attestation_method TEXT',
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
        ] as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')');
        }
    }

    private function seed(): void
    {
        foreach ([1, 2] as $id) $this->insert('tenants', ['id' => $id, 'is_active' => 1]);
        foreach ([1, 2, 3, 4, 10, 20, 90] as $id) {
            $this->insert('people', ['id' => $id, 'tenant_id' => $id === 90 ? 2 : 1, 'kind' => $id < 10 ? 'employee' : 'student',
                'mis_id' => 'SYNTH-' . $id, 'first_name' => 'Synthetic', 'last_name' => 'Person']);
        }
        foreach ([[1, 'counsellor'], [2, 'counsellor'], [3, 'guidance_supervisor'], [4, 'guidance_admin']] as [$id, $role]) {
            $this->insert('users', ['id' => $id, 'tenant_id' => 1, 'person_id' => $id,
                'username' => 'form-' . $id, 'role' => $role, 'status' => 'active']);
        }
        foreach (['guidance.cases.read', 'guidance.cases.write', 'guidance.cases.read_any', 'guidance.clinical.author',
            'guidance.clinical.cosign', 'guidance.appointments.manage', 'guidance.call_slips.issue'] as $index => $code) {
            $this->insert('permissions', ['id' => $index + 1, 'code' => $code]);
        }
        foreach (['counsellor', 'guidance_supervisor', 'guidance_admin'] as $role) {
            foreach ([1, 2, 4, 6, 7] as $permission) $this->insert('role_permissions', ['role' => $role, 'permission_id' => $permission]);
        }
        $this->insert('role_permissions', ['role' => 'guidance_supervisor', 'permission_id' => 5]);
        $this->insert('role_permissions', ['role' => 'guidance_admin', 'permission_id' => 3]);
        foreach ([[1, 'counselor_associate'], [2, 'licensed_counselor'], [3, 'guidance_supervisor']] as [$id, $tier]) {
            $this->insert('guidance_practitioner_profiles', ['id' => $id, 'tenant_id' => 1, 'user_id' => $id,
                'credential_tier' => $tier, 'professional_standing' => 'active', 'credential_expiry' => '2099-01-01', 'is_active' => 1,
                'service_scope_json' => '["counseling","psychosocial"]']);
        }
        $this->insert('guidance_supervision_assignments', ['id' => 1, 'tenant_id' => 1, 'associate_user_id' => 1,
            'supervisor_user_id' => 3, 'status' => 'active', 'starts_at' => '2020-01-01']);
        foreach ([[101, 10, 1], [102, 10, 2], [103, 20, 1], [104, 90, 1]] as [$id, $person, $owner]) {
            $this->insert('counselling_cases', ['id' => $id, 'tenant_id' => 1, 'person_id' => $person,
                'counsellor_user_id' => $owner, 'status' => 'open']);
            $this->insert('counselling_sessions', ['id' => $id, 'tenant_id' => 1, 'case_id' => $id,
                'counsellor_user_id' => $owner, 'session_number' => 1, 'status' => 'in_progress']);
        }
        $this->insert('guidance_episodes', ['id' => 201, 'tenant_id' => 1, 'person_id' => 10, 'case_id' => 101]);
        $this->insert('guidance_episodes', ['id' => 202, 'tenant_id' => 1, 'person_id' => 10, 'case_id' => 102]);
        $this->insert('guidance_appointments', ['id' => 301, 'tenant_id' => 1, 'person_id' => 10, 'episode_id' => 201]);
        $this->insert('referrals', ['id' => 401, 'tenant_id' => 1, 'patient_person_id' => 10, 'target_case_id' => 101,
            'source_module' => 'clinic', 'target_module' => 'guidance']);
        $this->insert('guidance_intake_referrals', ['id' => 501, 'tenant_id' => 1, 'subject_person_id' => 10,
            'linked_case_id' => 101, 'clinic_referral_id' => 401]);
        foreach ([[1, 'guidance_general_referral', 'F-OGC-GRE-GGR-005-00'],
            [2, 'psychosocial_support_interview', 'F-OGC-GPS-PIF-008-00'],
            [3, 'guidance_appointment_form', 'F-OGC-GAP-006-00'],
            [4, 'guidance_call_slip', 'F-OGC-GSP-CSP-007-00']] as [$id, $key, $code]) {
            $this->insert('guidance_form_definitions', ['id' => $id, 'tenant_id' => 1,
                'internal_key' => $key, 'form_code' => $code, 'canonical_title' => 'Synthetic form', 'is_active' => 1]);
            $this->insert('guidance_form_definition_revisions', ['id' => $id, 'tenant_id' => 1,
                'form_definition_id' => $id, 'schema_version' => '1.0.0', 'schema_json' => '{}', 'revision_number' => '00']);
        }
    }

    private function insert(string $table, array $data): void
    {
        self::assertTrue($this->fixture->table($table)->insert($data));
    }

    private function instance(array $overrides = []): array
    {
        return array_replace(['id' => 601, 'tenant_id' => 1, 'person_id' => 10,
            'case_id' => null, 'session_id' => null, 'episode_id' => null, 'appointment_id' => null,
            'referral_id' => null, 'intake_id' => null, 'form_definition_id' => 1, 'definition_revision_id' => 1,
            'created_by_user_id' => 1, 'assigned_supervisor_user_id' => 3,
            'status' => 'draft', 'instance_number' => 'SYNTH-601'], $overrides);
    }

    private function authority(int $id): array
    {
        $this->identity = ['id' => $id, 'tenant_id' => 1];
        return $this->accessPolicy->resolve($this->identity);
    }

    private function denied(callable $operation, int $code = 403): void
    {
        try {
            $operation();
        } catch (DomainException | InvalidArgumentException | RuntimeException $exception) {
            self::assertSame($code, $exception->getCode(), $exception->getMessage());
            return;
        }
        self::fail('Expected a fail-closed form boundary.');
    }

    private function encryptedInstance(int $definition = 1, array $links = []): int
    {
        $instance = $this->instance(array_replace(['form_definition_id' => $definition,
            'definition_revision_id' => $definition], $links));
        $this->insert('guidance_form_instances', $instance);
        $data = '{"synthetic_secret":"SYNTHETIC-CONFIDENTIAL-FORM"}';
        foreach ([0, 1] as $number) {
            $enc = $this->cipher->encrypt($data, 1, 601, $definition, $number, '1.0.0');
            $table = $number === 0 ? 'guidance_form_drafts' : 'guidance_form_revisions';
            $prefix = $number === 0 ? 'draft_' : 'snapshot_';
            $row = ['tenant_id' => 1, 'form_instance_id' => 601, $prefix . 'key_version' => 1];
            if ($number === 0) $row += ['version' => 1, 'draft_schema_version' => '1.0.0'];
            else $row += ['revision_number' => 1, 'definition_revision_id' => $definition, 'schema_version' => '1.0.0'];
            $this->insert($table, $row);
            $id = $this->fixture->insertID();
            $this->fixture->query('UPDATE ' . $this->fixture->prefixTable($table) . " SET {$prefix}cipher = X'"
                . bin2hex($enc['cipher']) . "', {$prefix}nonce = X'" . bin2hex($enc['nonce'])
                . "', {$prefix}tag = X'" . bin2hex($enc['tag']) . "' WHERE id = ?", [$id]);
        }
        return 601;
    }

    public function testConfidentialStandaloneCreatorSupervisorAndReadOnlyOversight(): void
    {
        $instance = $this->instance();
        self::assertSame(10, $this->policy->assertInstanceAccess($instance, $this->authority(1), true)['person_id']);
        $this->denied(fn () => $this->policy->assertInstanceAccess($instance, $this->authority(2)));
        self::assertTrue($this->policy->assertInstanceAccess($instance, $this->authority(3), false, true)['is_current_supervisor']);
        self::assertTrue($this->policy->assertInstanceAccess($instance, $this->authority(3), true, true)['is_current_supervisor']);
        self::assertSame(10, $this->policy->assertInstanceAccess($instance, $this->authority(4))['person_id']);
        $this->denied(fn () => $this->policy->assertInstanceAccess($instance, $this->authority(4), true, true));
        $this->fixture->table('guidance_supervision_assignments')->where('id', 1)->update(['status' => 'completed']);
        $this->denied(fn () => $this->policy->assertInstanceAccess($instance, $this->authority(3), false, true));
        $this->insert('guidance_supervision_assignments', ['id' => 2, 'tenant_id' => 1, 'associate_user_id' => 1,
            'supervisor_user_id' => 2, 'status' => 'active']);
        $this->denied(fn () => $this->policy->assertInstanceAccess($instance, $this->authority(3), false, true));
        self::assertTrue($this->policy->assertInstanceAccess($instance, $this->authority(2), false, true)['is_current_supervisor']);
        $this->fixture->table('guidance_practitioner_profiles')->where('user_id', 2)->update(['professional_standing' => 'suspended']);
        $this->denied(fn () => $this->policy->assertInstanceAccess($instance, $this->authority(2), false, true));
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults(), 'Metadata authorization does not audit or decrypt.');
    }

    public function testIndirectLinksDeriveOnlyConsistentEffectiveCaseAndCreationPerson(): void
    {
        $authority = $this->authority(1);
        foreach (['session_id' => 101, 'episode_id' => 201, 'appointment_id' => 301, 'referral_id' => 401, 'intake_id' => 501] as $field => $id) {
            $instance = $this->instance([$field => $id]);
            $context = $this->policy->assertInstanceAccess($instance, $authority);
            self::assertSame(101, $context['effective_case_id']);
            self::assertNull($instance['case_id'], 'Stored context is not silently rewritten.');
            $creation = $this->policy->assertCreationAccess(array_replace($instance, ['person_id' => null]), $authority);
            self::assertSame(10, $creation['person_id']);
            self::assertSame(101, $creation['case_id']);
        }
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(['session_id' => 102]), $authority));
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(['case_id' => 101, 'episode_id' => 202]), $authority), 404);
        $this->denied(fn () => $this->policy->assertCreationAccess($this->instance(['person_id' => 20, 'case_id' => 101]), $authority), 404);
        $this->denied(fn () => $this->policy->assertCreationAccess($this->instance(['session_id' => 999]), $authority), 404);
    }

    public static function invalidStoredGraphs(): iterable
    {
        yield 'wrong person' => [['person_id' => 20, 'case_id' => 101], null, null];
        yield 'foreign person' => [['person_id' => 90], null, null];
        yield 'missing session' => [['session_id' => 999], null, null];
        yield 'missing episode' => [['episode_id' => 999], null, null];
        yield 'missing appointment' => [['appointment_id' => 999], null, null];
        yield 'missing referral' => [['referral_id' => 999], null, null];
        yield 'missing intake' => [['intake_id' => 999], null, null];
        yield 'conflicting session case' => [['case_id' => 101, 'session_id' => 102], null, null];
        yield 'conflicting episode case' => [['case_id' => 101, 'episode_id' => 202], null, null];
        yield 'invalid revision binding' => [['definition_revision_id' => 2], null, null];
        yield 'deleted person' => [[], 'people', 10];
        yield 'deleted case' => [['case_id' => 101], 'counselling_cases', 101];
        yield 'deleted session' => [['session_id' => 101], 'counselling_sessions', 101];
        yield 'deleted episode' => [['episode_id' => 201], 'guidance_episodes', 201];
        yield 'deleted appointment' => [['appointment_id' => 301], 'guidance_appointments', 301];
    }

    #[DataProvider('invalidStoredGraphs')]
    public function testInvalidGraphRejectsEvenCreatorBeforeCipherRead(array $links, ?string $table, ?int $id): void
    {
        $this->encryptedInstance(1, $links);
        if ($table !== null) $this->fixture->table($table)->where('id', $id)->update(['deleted_at' => '2026-01-01']);
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = strtolower((string) $query); };
        Events::on('DBQuery', $listener);
        try {
            $this->denied(fn () => $this->generic->getDraft(601, $this->authority(1)), 404);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        foreach ($queries as $query) {
            self::assertStringNotContainsString('form_drafts', $query);
            self::assertStringNotContainsString('form_revisions', $query);
        }
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testCaseLessEpisodeCannotBypassCreatorOrPrimaryOwnership(): void
    {
        $this->insert('guidance_episodes', ['id' => 203, 'tenant_id' => 1, 'person_id' => 10, 'primary_counselor_user_id' => 2]);
        $links = $this->instance(['episode_id' => 203]);
        $this->denied(fn () => $this->policy->assertCreationAccess($links, $this->authority(1)));
        $this->denied(fn () => $this->policy->assertInstanceAccess($links, $this->authority(1)));
        self::assertSame(10, $this->policy->assertCreationAccess($links, $this->authority(2))['person_id']);
        // Episode ownership alone is not authority over another creator's confidential form.
        $this->denied(fn () => $this->policy->assertInstanceAccess($links, $this->authority(2)));
        self::assertSame(10, $this->policy->assertInstanceAccess($links, $this->authority(4))['person_id']);
        $this->denied(fn () => $this->policy->assertCreationAccess($links, $this->authority(4)));
    }

    public function testReverseOperationalBindingsCannotInventAnotherPersonEpisodeOrAppointment(): void
    {
        $this->insert('guidance_appointments', ['id' => 302, 'tenant_id' => 1, 'person_id' => 20, 'form_instance_id' => 601]);
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(), $this->authority(1)), 404);
        $this->fixture->table('guidance_appointments')->where('id', 302)->delete();
        $this->insert('guidance_call_slips', ['id' => 302, 'tenant_id' => 1, 'person_id' => 10, 'episode_id' => 202, 'form_instance_id' => 601]);
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(['episode_id' => 201]), $this->authority(1)), 404);
    }

    public function testSessionSharedAppointmentDoesNotBecomeUnrelatedGuidanceAppointment(): void
    {
        $this->insert('appointments', ['id' => 301, 'tenant_id' => 1, 'person_id' => 10, 'unit' => 'guidance']);
        $this->fixture->table('counselling_sessions')->where('id', 101)->update(['appointment_id' => 301]);
        $context = $this->policy->assertCreationAccess($this->instance(['person_id' => null, 'session_id' => 101]), $this->authority(1));
        self::assertNull($context['appointment_id']);
        self::assertSame(301, (int) $context['shared_appointment']['id']);
        $this->fixture->table('appointments')->where('id', 301)->update(['person_id' => 20]);
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(['session_id' => 101]), $this->authority(1)), 404);
    }

    public function testOperationalPermissionsStayTypeSpecificAndFreshReadAnyNeverBecomesWrite(): void
    {
        $this->fixture->table('role_permissions')->where('role', 'counsellor')->whereIn('permission_id', [1, 2, 4])->delete();
        $authority = $this->authority(1);
        foreach ([3 => 'guidance_appointment_form', 4 => 'guidance_call_slip'] as $id => $key) {
            $instance = $this->generic->createInstance(10, $key, actor: $authority);
            $this->generic->saveDraft((int) $instance['id'], ['operational' => true], $authority);
            // The SQLite builder escapes binary strings as text; repair only the synthetic
            // blob transport, using the same bound AAD as saveDraft, before testing reads.
            $encrypted = $this->cipher->encrypt('{"operational":true}', 1, (int) $instance['id'], $id, 0, '1.0.0');
            $this->fixture->query('UPDATE ' . $this->fixture->prefixTable('guidance_form_drafts')
                . " SET draft_cipher = X'" . bin2hex($encrypted['cipher']) . "', draft_nonce = X'"
                . bin2hex($encrypted['nonce']) . "', draft_tag = X'" . bin2hex($encrypted['tag'])
                . "' WHERE form_instance_id = ?", [(int) $instance['id']]);
            self::assertTrue($this->generic->getDraft((int) $instance['id'], $authority)['data']['operational']);
        }
        $this->denied(fn () => $this->generic->createInstance(10, 'guidance_general_referral', actor: $authority));
        $forged = $this->authority(2) + ['permissions' => ['guidance.cases.read_any']];
        $forged['permissions'] = ['guidance.cases.read_any', 'guidance.cases.write'];
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(), $forged));
        $readAny = $this->authority(4);
        self::assertSame(10, $this->policy->assertInstanceAccess($this->instance(), $readAny)['person_id']);
        $this->fixture->table('role_permissions')->where('role', 'guidance_admin')->where('permission_id', 3)->delete();
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(), $readAny));
    }

    public static function readers(): iterable
    {
        yield 'generic draft' => ['generic', 'getDraft', 1, 'guidance.form.draft_read'];
        yield 'generic final' => ['generic', 'getFinalizedRevision', 1, 'guidance.form.finalized_read'];
        yield 'clinical draft' => ['clinical', 'getClinicalDraft', 2, 'guidance.clinical_form.draft_read'];
        yield 'clinical final' => ['clinical', 'getFinalizedClinicalForm', 2, 'guidance.clinical_form.finalized_read'];
    }

    private function read(string $service, string $method, int $actorId = 1): array
    {
        $authority = $this->authority($actorId);
        return match ($method) {
            'getDraft' => $this->generic->getDraft(601, $authority),
            'getFinalizedRevision' => $this->generic->getFinalizedRevision(601, 1, $authority),
            'getClinicalDraft' => $this->clinical->getClinicalDraft(601, 1, $authority),
            default => $this->clinical->getFinalizedClinicalForm(601, 1, 1, $authority),
        };
    }

    #[DataProvider('readers')]
    public function testOtherCounselorCannotReachAnyStandalonePlaintextReader(string $service, string $method, int $definition, string $event): void
    {
        $this->encryptedInstance($definition);
        $before = $this->fixture->table('guidance_form_instances')->get()->getResultArray();
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = strtolower((string) $query); };
        Events::on('DBQuery', $listener);
        try {
            $this->denied(fn () => $this->read($service, $method, 2));
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        foreach ($queries as $query) {
            self::assertStringNotContainsString('form_drafts', $query);
            self::assertStringNotContainsString('form_revisions', $query);
        }
        self::assertSame($before, $this->fixture->table('guidance_form_instances')->get()->getResultArray());
        self::assertSame(0, $this->fixture->table('audit_events')->countAllResults());
    }

    public static function unavailableSupervisions(): iterable
    {
        yield 'revoked' => [['status' => 'revoked']];
        yield 'ended active row' => [['ends_at' => '2020-01-01']];
        yield 'not started' => [['starts_at' => '2099-01-01']];
    }

    #[DataProvider('unavailableSupervisions')]
    public function testStoredAssignedSupervisorDoesNotReplaceCurrentSupervision(array $changes): void
    {
        $this->fixture->table('guidance_supervision_assignments')->where('id', 1)->update($changes);
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(), $this->authority(3), false, true));
        $this->denied(fn () => $this->policy->assertInstanceAccess($this->instance(), $this->authority(3), true, true));
    }

    #[DataProvider('readers')]
    public function testEveryPlaintextReaderPersistsMetadataOnlyAuditOnSameConnection(string $service, string $method, int $definition, string $event): void
    {
        $this->encryptedInstance($definition);
        self::assertSame('SYNTHETIC-CONFIDENTIAL-FORM', $this->read($service, $method)['data']['synthetic_secret']);
        $row = $this->fixture->table('audit_events')->get()->getRowArray();
        self::assertNotNull($row);
        self::assertSame($event, $row['event_type']);
        self::assertSame('guidance_form_instances', $row['entity_type']);
        self::assertSame('601', $row['entity_id']);
        self::assertSame(1, (int) $row['actor_user_id']);
        self::assertNull($row['payload_json']);
        self::assertTrue((new AuditService(null, $this->fixture))->verifyChain()['valid']);
    }

    public static function readerAuditFailures(): iterable
    {
        foreach (self::readers() as $name => [$service, $method, $definition, $event]) {
            foreach (['missing_table', 'bad_head', 'append_failure'] as $failure) {
                yield $name . '-' . $failure => [$service, $method, $definition, $failure];
            }
        }
    }

    private function breakAudit(string $failure): void
    {
        $table = $this->fixture->prefixTable('audit_events');
        if ($failure === 'missing_table') $this->fixture->query('DROP TABLE ' . $table);
        elseif ($failure === 'bad_head') {
            (new AuditService(null, $this->fixture))->log('synthetic', 'synthetic', '1', 'read');
            $this->fixture->table('audit_events')->update(['curr_hash' => str_repeat('f', 64)]);
        } else {
            $this->fixture->query("CREATE TRIGGER refuse_form_audit BEFORE INSERT ON {$table} BEGIN SELECT RAISE(ABORT, 'Synthetic append failure'); END");
        }
    }

    #[DataProvider('readerAuditFailures')]
    public function testEveryPlaintextReaderFailsClosedOnAuditFailure(string $service, string $method, int $definition, string $failure): void
    {
        $this->encryptedInstance($definition);
        $this->breakAudit($failure);
        $this->denied(fn () => $this->read($service, $method), 503);
    }

    public static function missingDraftReaders(): iterable
    {
        yield 'generic empty draft' => ['generic', 'getDraft', 1, 'guidance.form.draft_read'];
        yield 'clinical empty draft' => ['clinical', 'getClinicalDraft', 2, 'guidance.clinical_form.draft_read'];
    }

    private function instanceWithoutDraft(int $definition): void
    {
        $this->insert('guidance_form_instances', $this->instance([
            'form_definition_id' => $definition, 'definition_revision_id' => $definition,
            'submission_notes' => 'SYNTHETIC-CONFIDENTIAL-SUBMISSION-NOTE',
            'supervisor_notes' => 'SYNTHETIC-CONFIDENTIAL-SUPERVISOR-NOTE',
        ]));
        self::assertSame(0, $this->fixture->table('guidance_form_drafts')->countAllResults());
    }

    #[DataProvider('missingDraftReaders')]
    public function testMissingDraftInstanceNotesRequirePersistedReadAudit(string $service, string $method, int $definition, string $event): void
    {
        $this->instanceWithoutDraft($definition);
        $result = $this->read($service, $method);
        self::assertNull($result['draft']);
        self::assertSame([], $result['data']);
        self::assertSame('SYNTHETIC-CONFIDENTIAL-SUBMISSION-NOTE', $result['instance']['submission_notes']);
        self::assertSame('SYNTHETIC-CONFIDENTIAL-SUPERVISOR-NOTE', $result['instance']['supervisor_notes']);
        $rows = $this->fixture->table('audit_events')->get()->getResultArray();
        self::assertCount(1, $rows);
        self::assertSame($event, $rows[0]['event_type']);
        self::assertSame('guidance_form_instances', $rows[0]['entity_type']);
        self::assertSame('601', $rows[0]['entity_id']);
        self::assertSame(1, (int) $rows[0]['actor_user_id']);
        self::assertNull($rows[0]['payload_json']);
        self::assertStringNotContainsString('SYNTHETIC-CONFIDENTIAL', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertTrue((new AuditService(null, $this->fixture))->verifyChain()['valid']);
    }

    public static function missingDraftAuditFailures(): iterable
    {
        foreach (self::missingDraftReaders() as $name => [$service, $method, $definition, $event]) {
            foreach (['missing_table', 'bad_head', 'append_failure'] as $failure) {
                yield $name . '-' . $failure => [$service, $method, $definition, $failure];
            }
        }
    }

    #[DataProvider('missingDraftAuditFailures')]
    public function testMissingDraftInstanceNotesNeverEscapeAuditFailure(string $service, string $method, int $definition, string $failure): void
    {
        $this->instanceWithoutDraft($definition);
        $before = $this->fixture->table('guidance_form_instances')->get()->getResultArray();
        $this->breakAudit($failure);
        $result = null;
        try {
            $result = $this->read($service, $method);
            self::fail('An empty draft must not release instance notes without a persisted read audit.');
        } catch (RuntimeException $exception) {
            self::assertSame(503, $exception->getCode());
        }
        self::assertNull($result, 'No instance notes or empty-success package may escape audit failure.');
        self::assertSame($before, $this->fixture->table('guidance_form_instances')->get()->getResultArray());
        if ($failure !== 'missing_table') {
            self::assertSame($failure === 'bad_head' ? 1 : 0, $this->fixture->table('audit_events')->countAllResults());
        }
    }

    public function testClinicalHistoryDoesNotSwallowAuditFailure(): void
    {
        $this->encryptedInstance(2, ['case_id' => 101]);
        $this->breakAudit('append_failure');
        $this->denied(fn () => $this->clinical->getClinicalHistoryForCase(101, 1, $this->authority(1)), 503);
    }

    public function testClinicalCosignUsesCurrentRelationNotStoredSnapshot(): void
    {
        $this->encryptedInstance(2, ['status' => 'pending_cosign']);
        $this->fixture->table('guidance_supervision_assignments')->where('id', 1)->update(['status' => 'completed']);
        $this->denied(fn () => $this->clinical->coSignAndFinalize(601, 'Synthetic attestation', null, 1, $this->authority(3)));
        $this->insert('guidance_supervision_assignments', ['id' => 2, 'tenant_id' => 1,
            'associate_user_id' => 1, 'supervisor_user_id' => 2, 'status' => 'active']);
        $this->denied(fn () => $this->clinical->coSignAndFinalize(601, 'Synthetic attestation', null, 1, $this->authority(3)));
        $this->fixture->table('users')->where('id', 2)->update(['role' => 'guidance_supervisor']);
        $result = $this->clinical->coSignAndFinalize(601, 'Synthetic attestation', null, 1, $this->authority(2));
        self::assertSame('finalized', $result['status']);
        self::assertSame(2, (int) $result['attestation']['signer_user_id']);
        self::assertSame(3, (int) $result['instance']['assigned_supervisor_user_id'], 'Historical snapshot is not authority.');
    }
}
