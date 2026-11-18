<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceCumulativeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/** Synthetic private SQLite; no ambient DB, dotenv, migrations, or clinical-content tables. */
final class GuidanceClientRecordTest extends TestCase
{
    private ?BaseConnection $fixture = null;
    private array $savedConnections = [];
    private ?int $savedTenant = null;
    private ?array $identity = ['id' => 1, 'tenant_id' => 1];
    private GuidanceCumulativeService $service;
    private array $queries = [];
    private array $businessTables = [];
    private array $grants = [];

    private const PRIVATE_VALUE = 'SYNTHETIC-PRIVATE-CONTENT';
    private const MI_SCORES = [
        'logical_mathematical' => 31, 'linguistic' => 12, 'spatial' => 9, 'musical' => 5,
        'bodily_kinesthetic' => 22, 'interpersonal' => 17, 'intrapersonal' => 28, 'naturalist' => 34,
    ];
    private const HISTORY_KEYS = [
        'authorizedCases', 'episodes', 'sessions', 'followups', 'miAttempts', 'pendingAssessments', 'surveys',
        'intakes', 'bridgeReferrals', 'transfers', 'assignments', 'safetyReviews', 'forms',
        'sharedAppointments', 'guidanceAppointments', 'callSlips',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $bootstrap = realpath(dirname(__DIR__) . '/_support/guidance-read-bootstrap.php');
        if (!defined('ENVIRONMENT') || ENVIRONMENT !== 'testing'
            || !in_array($bootstrap, array_map('realpath', get_included_files()), true)) {
            $this->markTestSkipped('The no-dotenv, private SQLite guidance-read bootstrap is required.');
        }
        if (!extension_loaded('sqlite3')) $this->markTestSkipped('Private SQLite fixtures require sqlite3.');
        $instances = new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances');
        $this->savedConnections = $instances->getValue();
        $this->savedTenant = (new ReflectionProperty(CurrentTenant::class, 'id'))->getValue();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => '', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        self::assertSame('SQLite3', $this->fixture->DBDriver);
        self::assertSame(':memory:', $this->fixture->database);
        $instances->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        CurrentTenant::set(1);
        $this->initializeFixture();
    }

    private function initializeFixture(): void
    {
        $this->schema();
        $this->seed();
        $policy = new GuidanceAccessPolicy(identitySource: fn (): ?array => $this->identity, db: $this->fixture);
        $this->service = new GuidanceCumulativeService(db: $this->fixture, accessPolicy: $policy);
        $this->protectDomain();
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->close();
            (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
                ->setValue(null, $this->savedConnections);
            CurrentTenant::set($this->savedTenant);
        }
        parent::tearDown();
    }

    private function schema(): void
    {
        $tables = [
            'audit_events' => 'id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER, event_type TEXT, entity_type TEXT, entity_id TEXT, actor_user_id INTEGER, actor_role TEXT, actor_ip TEXT, action TEXT, payload_json TEXT, prev_hash TEXT, curr_hash TEXT, hash_version INTEGER, occurred_at TEXT',
            'tenants' => 'id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT',
            'people' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, kind TEXT, mis_id TEXT, first_name TEXT, middle_name TEXT, last_name TEXT, date_of_birth TEXT, gender TEXT, email TEXT, phone TEXT, address TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, password_hash TEXT, deleted_at TEXT',
            'student_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, program TEXT, year_level INTEGER, section TEXT, department TEXT',
            'employee_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, department TEXT, position TEXT',
            'permissions' => 'id INTEGER PRIMARY KEY, code TEXT',
            'role_permissions' => 'role TEXT, permission_id INTEGER, PRIMARY KEY(role, permission_id)',
            'counselling_cases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counsellor_user_id INTEGER, case_number TEXT, title TEXT, category TEXT, status TEXT, opened_at TEXT, closed_at TEXT, summary_notes TEXT, closure_disposition_cipher TEXT, deleted_at TEXT',
            'counselling_sessions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, appointment_id INTEGER, session_number INTEGER, session_type TEXT, status TEXT, started_at TEXT, ended_at TEXT, counsellor_user_id INTEGER, deleted_at TEXT',
            'guidance_followups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, due_date TEXT, status TEXT, completed_at TEXT, action_plan TEXT, completion_notes_cipher TEXT',
            'guidance_episodes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, case_id INTEGER, intake_referral_id INTEGER, status TEXT, opened_at TEXT, closed_at TEXT, primary_counselor_user_id INTEGER, closure_reason TEXT, closure_notes_cipher TEXT, deleted_at TEXT',
            'guidance_supervision_assignments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, associate_user_id INTEGER, supervisor_user_id INTEGER, status TEXT',
            'guidance_case_transfers' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, from_counsellor_user_id INTEGER, to_counsellor_user_id INTEGER, transfer_type TEXT, status TEXT, requested_at TEXT, responded_at TEXT, reason TEXT',
            'guidance_case_assignments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, case_id INTEGER, counsellor_user_id INTEGER, assignment_type TEXT, assigned_at TEXT, relinquished_at TEXT, notes TEXT',
            'guidance_practitioner_profiles' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, user_id INTEGER, credential_tier TEXT, is_active INTEGER, professional_standing TEXT, credential_expiry TEXT, license_number TEXT',
            'guidance_mi_assignments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, status TEXT, academic_term TEXT, academic_year TEXT, assigned_at TEXT, due_at TEXT, deleted_at TEXT',
            'guidance_mi_submissions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, assignment_id INTEGER, status TEXT, domain_scores_json TEXT, attested_at TEXT, created_at TEXT, answers_cipher TEXT, deleted_at TEXT',
            'surveys' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, title TEXT, audience TEXT, deleted_at TEXT',
            'survey_responses' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, survey_id INTEGER, submitted_at TEXT, interviewed_by_user_id INTEGER, interviewed_at TEXT, staff_remarks_cipher TEXT',
            'guidance_intake_referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, subject_person_id INTEGER, referral_number TEXT, source_type TEXT, status TEXT, linked_case_id INTEGER, clinic_referral_id INTEGER, submitted_at TEXT, created_at TEXT, narrative_cipher TEXT',
            'referrals' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, patient_person_id INTEGER, target_case_id INTEGER, referral_code TEXT, source_module TEXT, target_module TEXT, status TEXT, created_at TEXT, narrative_cipher TEXT',
            'guidance_safety_assessments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, case_id INTEGER, assessor_user_id INTEGER, assessed_at TEXT, safety_plan_cipher TEXT',
            'queue_entries' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, unit TEXT, status TEXT',
            'appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, provider_user_id INTEGER, starts_at TEXT, ends_at TEXT, status TEXT, type TEXT, unit TEXT, deleted_at TEXT',
            'guidance_appointments' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, counselor_user_id INTEGER, episode_id INTEGER, form_instance_id INTEGER, appointment_number TEXT, scheduled_date TEXT, scheduled_time TEXT, status TEXT, notes_cipher TEXT, deleted_at TEXT',
            'guidance_call_slips' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, issuing_counselor_user_id INTEGER, episode_id INTEGER, form_instance_id INTEGER, call_slip_number TEXT, call_date TEXT, call_time TEXT, attendance_status TEXT, delivery_notes TEXT, deleted_at TEXT',
            'guidance_form_definitions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, internal_key TEXT, form_code TEXT, canonical_title TEXT, is_active INTEGER DEFAULT 1',
            'guidance_form_definition_revisions' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, form_definition_id INTEGER',
            'guidance_form_instances' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, case_id INTEGER, episode_id INTEGER, session_id INTEGER, appointment_id INTEGER, referral_id INTEGER, intake_id INTEGER, form_definition_id INTEGER, definition_revision_id INTEGER, instance_number TEXT, status TEXT, privacy_notice_id INTEGER, created_by_user_id INTEGER, finalized_at TEXT, created_at TEXT, assigned_supervisor_user_id INTEGER, submitted_for_cosign_at TEXT, submission_notes TEXT, supervisor_notes TEXT',
            'guidance_form_attestations' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, form_instance_id INTEGER, signer_role TEXT, attested_at TEXT, signature_cipher TEXT',
        ];
        $this->businessTables = array_values(array_diff(array_keys($tables), ['audit_events']));
        foreach ($tables as $table => $columns) {
            $this->fixture->query('CREATE TABLE ' . $this->fixture->prefixTable($table) . ' (' . $columns . ')');
        }
    }

    private function insert(string $table, array $data): void
    {
        self::assertTrue($this->fixture->table($table)->insert($data));
    }

    private function seed(): void
    {
        foreach ([1, 2] as $id) $this->insert('tenants', ['id' => $id, 'is_active' => 1]);
        foreach ([
            [10, 1, 'student', null], [20, 1, 'employee', null], [30, 1, 'guest', null],
            [31, 1, 'external', null], [40, 2, 'student', null], [50, 1, 'student', '2026-01-01'],
            [60, 1, 'student', null], [101, 1, 'employee', null], [102, 1, 'employee', null],
            [103, 1, 'employee', null], [109, 2, 'employee', null],
        ] as [$id, $tenantId, $kind, $deleted]) {
            $this->insert('people', ['id' => $id, 'tenant_id' => $tenantId, 'kind' => $kind,
                'mis_id' => 'SYNTH-' . $id, 'first_name' => 'Synthetic', 'last_name' => 'Person ' . $id,
                'email' => 'person-' . $id . '@synthetic.invalid', 'deleted_at' => $deleted]);
        }
        $this->insert('student_profiles', ['id' => 1, 'tenant_id' => 1, 'person_id' => 10,
            'program' => 'Synthetic BS', 'year_level' => 2, 'section' => 'Synthetic A', 'department' => 'Synthetic School']);
        $this->insert('student_profiles', ['id' => 2, 'tenant_id' => 2, 'person_id' => 10,
            'program' => self::PRIVATE_VALUE]);
        $this->insert('employee_profiles', ['id' => 1, 'tenant_id' => 1, 'person_id' => 20,
            'department' => 'Synthetic Department', 'position' => 'Synthetic Teacher']);
        foreach ([[1, 1, 101], [2, 1, 102], [3, 1, 103], [9, 2, 109]] as [$id, $tenantId, $personId]) {
            $this->insert('users', ['id' => $id, 'tenant_id' => $tenantId, 'person_id' => $personId,
                'username' => 'synthetic-' . $id, 'role' => 'counsellor', 'status' => 'active',
                'password_hash' => self::PRIVATE_VALUE]);
        }
        foreach (['guidance.cases.read', 'guidance.cases.read_any', 'guidance.queue.read',
            'guidance.assessments.view', 'guidance.responses.read', 'guidance.intake.process',
            'referrals.read', 'guidance.safety.triage', 'guidance.schedule.read', 'guidance.call_slips.issue',
            'guidance.clinical.author', 'guidance.forms.manage', 'guidance.documents.print'] as $index => $code) {
            $this->grants[$code] = $index + 1;
            $this->insert('permissions', ['id' => $index + 1, 'code' => $code]);
        }
        $this->insert('role_permissions', ['role' => 'counsellor', 'permission_id' => $this->grants['guidance.cases.read']]);
        foreach ([[1, 'counselor_associate'], [2, 'licensed_counselor'], [3, 'licensed_counselor']] as [$id, $tier]) {
            $this->insert('guidance_practitioner_profiles', ['id' => $id, 'tenant_id' => 1, 'user_id' => $id,
                'credential_tier' => $tier, 'is_active' => 1, 'professional_standing' => 'active', 'license_number' => self::PRIVATE_VALUE]);
        }
        foreach ([[201, 1, 10, 1, 'active', null], [202, 1, 10, 2, 'active', null],
            [203, 1, 10, 1, 'closed', null], [204, 1, 10, 1, 'open', '2026-01-01'],
            [205, 1, 20, 1, 'open', null], [209, 2, 40, 9, 'active', null]] as [$id, $tenant, $person, $owner, $status, $deleted]) {
            $this->case($id, ['tenant_id' => $tenant, 'person_id' => $person,
                'counsellor_user_id' => $owner, 'status' => $status, 'deleted_at' => $deleted]);
        }
        foreach ([[301, 1, 201, null], [302, 1, 203, null], [303, 1, 202, null],
            [304, 1, 201, '2026-01-01'], [305, 2, 201, null], [306, 1, 204, null],
            [307, 1, 205, null], [309, 2, 209, null]] as [$id, $tenant, $case, $deleted]) {
            $this->session($id, ['tenant_id' => $tenant, 'case_id' => $case, 'deleted_at' => $deleted]);
        }
        foreach ([[401, 1, 201, 'pending'], [402, 1, 203, 'completed'], [403, 1, 202, 'pending'],
            [404, 2, 201, 'pending'], [405, 1, 204, 'pending']] as [$id, $tenant, $case, $status]) {
            $this->followup($id, ['tenant_id' => $tenant, 'case_id' => $case, 'status' => $status]);
        }
        foreach ([[501, 1, 10, 1, 201, null], [502, 1, 10, 1, null, null],
            [503, 1, 10, 2, 202, null], [504, 1, 10, 2, null, null],
            [505, 1, 10, 1, null, '2026-01-01'], [509, 2, 10, 1, null, null]] as [$id, $tenant, $person, $owner, $case, $deleted]) {
            $this->episode($id, ['tenant_id' => $tenant, 'person_id' => $person,
                'primary_counselor_user_id' => $owner, 'case_id' => $case, 'deleted_at' => $deleted]);
        }
        foreach ([[601, 1, 201], [602, 1, 202], [603, 2, 201], [604, 1, 204]] as [$id, $tenant, $case]) {
            $this->insert('guidance_case_transfers', ['id' => $id, 'tenant_id' => $tenant, 'case_id' => $case,
                'from_counsellor_user_id' => 1, 'to_counsellor_user_id' => 3, 'transfer_type' => 'full_transfer',
                'status' => 'pending', 'requested_at' => '2026-01-01', 'reason' => self::PRIVATE_VALUE]);
            $this->insert('guidance_case_assignments', ['id' => $id, 'tenant_id' => $tenant, 'case_id' => $case,
                'counsellor_user_id' => 1, 'assignment_type' => 'primary', 'assigned_at' => '2026-01-01',
                'notes' => self::PRIVATE_VALUE]);
            $this->insert('guidance_safety_assessments', ['id' => $id, 'tenant_id' => $tenant, 'person_id' => 10,
                'case_id' => $case, 'assessor_user_id' => 1, 'assessed_at' => '2026-01-01', 'safety_plan_cipher' => self::PRIVATE_VALUE]);
        }
        foreach ([[701, 1, 10, 'completed', null], [702, 1, 10, 'assigned', null],
            [703, 1, 10, 'in_progress', null], [704, 2, 10, 'assigned', null],
            [705, 1, 20, 'assigned', null], [706, 1, 10, 'assigned', '2026-01-01']] as [$id, $tenant, $person, $status, $deleted]) {
            $this->insert('guidance_mi_assignments', ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'status' => $status, 'academic_term' => 'Synthetic Term', 'academic_year' => '2026-2027',
                'assigned_at' => '2026-01-01', 'due_at' => '2026-02-01', 'deleted_at' => $deleted]);
        }
        foreach ([[711, 1, 10, 701, null], [712, 2, 10, 701, null], [713, 1, 10, 706, null],
            [714, 1, 10, 701, '2026-01-01'], [715, 1, 10, 999, null], [716, 1, 10, 705, null]] as [$id, $tenant, $person, $assignment, $deleted]) {
            $this->insert('guidance_mi_submissions', ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'assignment_id' => $assignment, 'status' => 'finalized',
                'domain_scores_json' => json_encode(self::MI_SCORES, JSON_THROW_ON_ERROR),
                'attested_at' => '2026-01-01', 'created_at' => '2026-01-01', 'answers_cipher' => self::PRIVATE_VALUE, 'deleted_at' => $deleted]);
        }
        foreach ([[721, 1, null], [722, 2, null], [723, 1, '2026-01-01']] as [$id, $tenant, $deleted]) {
            $this->insert('surveys', ['id' => $id, 'tenant_id' => $tenant, 'title' => 'Synthetic Survey',
                'audience' => 'student', 'deleted_at' => $deleted]);
        }
        foreach ([[731, 1, 10, 721], [732, 2, 10, 721], [733, 1, 10, 722],
            [734, 1, 10, 723], [735, 1, 20, 721]] as [$id, $tenant, $person, $survey]) {
            $this->insert('survey_responses', ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'survey_id' => $survey, 'submitted_at' => '2026-01-01', 'interviewed_by_user_id' => 1,
                'interviewed_at' => '2026-01-02', 'staff_remarks_cipher' => self::PRIVATE_VALUE]);
        }
        foreach ([[801, 1, 10, null], [802, 1, 10, 201], [803, 1, 10, 202],
            [804, 2, 10, null], [805, 1, 10, 999], [806, 1, 20, null]] as [$id, $tenant, $person, $case]) {
            $this->insert('guidance_intake_referrals', ['id' => $id, 'tenant_id' => $tenant,
                'subject_person_id' => $person, 'referral_number' => 'SYNTH-INTAKE-' . $id,
                'source_type' => 'self', 'status' => 'triaged', 'linked_case_id' => $case,
                'submitted_at' => '2026-01-01', 'created_at' => '2026-01-01', 'narrative_cipher' => self::PRIVATE_VALUE]);
        }
        foreach ([[821, 1, 10, 'clinic', 'guidance'], [822, 1, 10, 'clinic', 'clinic'],
            [823, 2, 10, 'clinic', 'guidance'], [824, 1, 20, 'clinic', 'guidance']] as [$id, $tenant, $person, $source, $target]) {
            $this->insert('referrals', ['id' => $id, 'tenant_id' => $tenant, 'patient_person_id' => $person,
                'referral_code' => 'SYNTH-BRIDGE-' . $id, 'source_module' => $source, 'target_module' => $target,
                'status' => 'pending', 'created_at' => '2026-01-01', 'narrative_cipher' => self::PRIVATE_VALUE]);
        }
        foreach ([[901, 1, 10, 'guidance', 'done'], [902, 1, 10, 'guidance', 'waiting'],
            [903, 1, 10, 'guidance', 'cancelled'], [904, 1, 10, 'clinic', 'done'],
            [905, 2, 10, 'guidance', 'done'], [906, 1, 20, 'guidance', 'done']] as [$id, $tenant, $person, $unit, $status]) {
            $this->insert('queue_entries', ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person, 'unit' => $unit, 'status' => $status]);
        }
        foreach ([[921, 1, 10, 'guidance', null], [922, 1, 10, 'clinic', null],
            [923, 2, 10, 'guidance', null], [924, 1, 10, 'guidance', '2026-01-01'],
            [925, 1, 20, 'guidance', null]] as [$id, $tenant, $person, $unit, $deleted]) {
            $this->insert('appointments', ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'provider_user_id' => 1, 'starts_at' => '2026-01-01', 'ends_at' => '2026-01-01',
                'status' => 'scheduled', 'type' => 'routine', 'unit' => $unit, 'deleted_at' => $deleted]);
        }
        $this->insert('guidance_form_definitions', ['id' => 1001, 'tenant_id' => 1,
            'internal_key' => 'synthetic_intake', 'form_code' => 'SYNTH-01', 'canonical_title' => 'Synthetic Intake Form']);
        $this->insert('guidance_form_definitions', ['id' => 1002, 'tenant_id' => 2,
            'internal_key' => 'foreign', 'form_code' => 'SYNTH-02', 'canonical_title' => self::PRIVATE_VALUE]);
        $this->insert('guidance_form_definition_revisions', ['id' => 1101, 'tenant_id' => 1, 'form_definition_id' => 1001]);
        $this->insert('guidance_form_definition_revisions', ['id' => 1102, 'tenant_id' => 2, 'form_definition_id' => 1002]);
        foreach ([
            [1011, 1, 10, 201, 501, 301, 1, 1001], [1012, 1, 10, null, null, null, 1, 1001],
            [1013, 1, 10, 202, null, 303, 1, 1001], [1014, 1, 10, 201, 503, 301, 1, 1001],
            [1015, 2, 10, null, null, null, 1, 1001], [1016, 1, 20, null, null, null, 1, 1001],
            [1017, 1, 10, 201, null, 304, 1, 1001], [1018, 1, 10, 201, null, 305, 1, 1001],
            [1019, 1, 10, null, null, null, 2, 1001], [1020, 1, 10, null, null, null, 1, 1002],
            [1021, 1, 10, 201, null, 303, 1, 1001],
        ] as [$id, $tenant, $person, $case, $episode, $session, $creator, $definition]) {
            $this->form($id, ['tenant_id' => $tenant, 'person_id' => $person, 'case_id' => $case,
                'episode_id' => $episode, 'session_id' => $session, 'created_by_user_id' => $creator,
                'form_definition_id' => $definition]);
        }
        foreach ([[1051, 1, 1011], [1052, 2, 1011], [1053, 1, 1013]] as [$id, $tenant, $form]) {
            $this->insert('guidance_form_attestations', ['id' => $id, 'tenant_id' => $tenant,
                'form_instance_id' => $form, 'signer_role' => 'counselor', 'attested_at' => '2026-01-01',
                'signature_cipher' => self::PRIVATE_VALUE]);
        }
        foreach ([
            [941, 1, 10, null, null, null], [942, 1, 10, 501, 1011, null],
            [943, 1, 10, 503, null, null], [944, 1, 10, null, 1013, null],
            [945, 1, 10, null, 1014, null], [946, 1, 10, null, 1017, null],
            [947, 2, 10, null, null, null], [948, 1, 20, null, null, null],
            [949, 1, 10, null, null, '2026-01-01'], [950, 1, 10, null, 999, null],
            [951, 1, 10, null, 1021, null],
        ] as [$id, $tenant, $person, $episode, $form, $deleted]) {
            $common = ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person,
                'episode_id' => $episode, 'form_instance_id' => $form, 'deleted_at' => $deleted];
            $this->insert('guidance_appointments', $common + ['counselor_user_id' => 1,
                'appointment_number' => 'SYNTH-APPT-' . $id, 'scheduled_date' => '2026-01-01',
                'scheduled_time' => '09:00:00', 'status' => 'scheduled', 'notes_cipher' => self::PRIVATE_VALUE]);
            $this->insert('guidance_call_slips', $common + ['issuing_counselor_user_id' => 1,
                'call_slip_number' => 'SYNTH-CALL-' . $id, 'call_date' => '2026-01-01',
                'call_time' => '09:00:00', 'attendance_status' => 'pending', 'delivery_notes' => self::PRIVATE_VALUE]);
        }
    }

    private function case(int $id, array $overrides = []): void
    {
        $this->insert('counselling_cases', array_replace(['id' => $id, 'tenant_id' => 1, 'person_id' => 10,
            'counsellor_user_id' => 1, 'case_number' => 'SYNTH-CASE-' . $id, 'title' => 'Synthetic case ' . $id,
            'category' => 'personal', 'status' => 'active', 'opened_at' => '2026-01-01', 'closed_at' => null,
            'summary_notes' => self::PRIVATE_VALUE, 'closure_disposition_cipher' => self::PRIVATE_VALUE, 'deleted_at' => null], $overrides));
    }

    private function session(int $id, array $overrides = []): void
    {
        $this->insert('counselling_sessions', array_replace(['id' => $id, 'tenant_id' => 1, 'case_id' => 201,
            'session_number' => $id, 'session_type' => 'regular', 'status' => 'completed',
            'started_at' => '2026-01-01', 'ended_at' => '2026-01-01', 'counsellor_user_id' => 1, 'deleted_at' => null], $overrides));
    }

    private function followup(int $id, array $overrides = []): void
    {
        $this->insert('guidance_followups', array_replace(['id' => $id, 'tenant_id' => 1, 'case_id' => 201,
            'due_date' => '2026-01-01', 'status' => 'pending', 'completed_at' => null,
            'action_plan' => self::PRIVATE_VALUE, 'completion_notes_cipher' => self::PRIVATE_VALUE], $overrides));
    }

    private function episode(int $id, array $overrides = []): void
    {
        $this->insert('guidance_episodes', array_replace(['id' => $id, 'tenant_id' => 1, 'person_id' => 10,
            'case_id' => null, 'intake_referral_id' => 802, 'status' => 'open', 'opened_at' => '2026-01-01',
            'closed_at' => null, 'primary_counselor_user_id' => 1, 'closure_reason' => self::PRIVATE_VALUE,
            'closure_notes_cipher' => self::PRIVATE_VALUE, 'deleted_at' => null], $overrides));
    }

    private function form(int $id, array $overrides = []): void
    {
        $this->insert('guidance_form_instances', array_replace(['id' => $id, 'tenant_id' => 1,
            'person_id' => 10, 'case_id' => null, 'episode_id' => null, 'session_id' => null,
            'appointment_id' => null, 'referral_id' => null, 'intake_id' => null,
            'form_definition_id' => 1001, 'definition_revision_id' => 1101,
            'instance_number' => 'SYNTH-FORM-' . $id, 'status' => 'pending_cosign',
            'privacy_notice_id' => 1, 'created_by_user_id' => 1, 'finalized_at' => null,
            'created_at' => '2026-01-01', 'assigned_supervisor_user_id' => 2, 'submitted_for_cosign_at' => '2026-01-01',
            'submission_notes' => self::PRIVATE_VALUE, 'supervisor_notes' => self::PRIVATE_VALUE], $overrides));
    }

    private function enableWrites(): void
    {
        // Fixture preparation only; every production GET is checked against a complete snapshot.
        foreach ($this->businessTables as $table) {
            foreach (['insert', 'delete', 'update'] as $operation) {
                $this->fixture->query('DROP TRIGGER IF EXISTS ' . $this->fixture->prefixTable('deny_record_' . $operation . '_' . $table));
            }
        }
    }

    private function protectDomain(): void
    {
        foreach ($this->businessTables as $table) {
            foreach (['INSERT', 'DELETE', 'UPDATE'] as $operation) {
                if ($table === 'tenants' && $operation === 'UPDATE') continue;
                $trigger = $this->fixture->prefixTable('deny_record_' . strtolower($operation) . '_' . $table);
                $this->fixture->query('CREATE TRIGGER ' . $trigger . ' BEFORE ' . $operation . ' ON ' . $this->fixture->prefixTable($table)
                    . " BEGIN SELECT RAISE(ABORT, 'Synthetic record read must not mutate domain rows'); END");
            }
        }
    }

    private function grant(string ...$codes): void
    {
        $this->enableWrites();
        foreach ($codes as $code) {
            $this->fixture->table('role_permissions')->ignore(true)
                ->insert(['role' => 'counsellor', 'permission_id' => $this->grants[$code]]);
        }
        $this->protectDomain();
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->businessTables as $table) {
            $order = $table === 'role_permissions' ? 'permission_id' : 'id';
            $snapshot[$table] = $this->fixture->table($table)->orderBy($order)->get()->getResultArray();
        }
        return $snapshot;
    }

    private function record(array $query = [], int $personId = 10, ?array $actor = null): array
    {
        $before = $this->snapshot();
        $tenantTable = preg_quote($this->fixture->prefixTable('tenants'), '/');
        $usersTable = $this->fixture->prefixTable('users');
        $this->queries = [];
        $listener = function ($query): void { $this->queries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            $record = $this->service->getStudentCumulativeRecord($personId, $actor, $query);
            self::assertStringNotContainsString(self::PRIVATE_VALUE, json_encode($record, JSON_THROW_ON_ERROR));
            self::assertArrayNotHasKey('restrictedCasesCount', $record);
            return $record;
        } finally {
            Events::removeListener('DBQuery', $listener);
            self::assertSame($before, $this->snapshot(), 'Cumulative GETs may append audits, but never mutate domain rows.');
            self::assertSame(0, $this->fixture->transDepth);
            foreach ($this->queries as $sql) {
                $lower = strtolower($sql);
                if (preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $sql)) {
                    self::assertTrue(str_contains($lower, 'audit_events')
                        || preg_match('/^\s*update\s+["`]?' . $tenantTable . '["`]?\s+set\s+id\s*=\s*id\b/i', $sql) === 1,
                        'Only audit append and its tenant no-op mutex may write.');
                }
                foreach (['counselling_notes', 'guidance_form_drafts', 'guidance_form_revisions',
                    'guidance_clinical_', 'survey_answers', 'guidance_mi_answers'] as $table) {
                    self::assertStringNotContainsString($table, $lower);
                }
                if (!str_contains($lower, 'audit_events') && str_starts_with(ltrim($lower), 'select')
                    && !str_contains($lower, 'select * from "' . $usersTable . '"')) {
                    foreach (['cipher', 'nonce', 'closure_reason', 'summary_notes', 'action_plan',
                        'staff_remarks', 'delivery_notes', 'submission_notes', 'supervisor_notes', 'license_number'] as $content) {
                        self::assertStringNotContainsString($content, $lower);
                    }
                }
            }
        }
    }

    private function ids(array $rows): array
    {
        return array_map('intval', array_column($rows, 'id'));
    }

    private function denied(array $query, int $personId, int $code, ?array $actor = null): void
    {
        $audits = $this->fixture->table('audit_events')->countAllResults();
        try {
            $this->record($query, $personId, $actor);
        } catch (DomainException | InvalidArgumentException | RuntimeException $error) {
            self::assertSame($code, $error->getCode(), $error->getMessage());
            self::assertSame($audits, $this->fixture->table('audit_events')->countAllResults());
            return;
        }
        self::fail('Expected cumulative record read to be rejected.');
    }

    public function testOverviewCountsOnlyAuthorizedMetadataAndLoadsNoHistoryRows(): void
    {
        $record = $this->record();
        self::assertSame('overview', $record['activeTab']);
        self::assertSame(['tab' => 'overview', 'per_page' => 25], $record['recordQuery']);
        foreach (self::HISTORY_KEYS as $key) self::assertSame([], $record[$key], 'Overview eagerly loaded ' . $key);
        self::assertSame(2, $record['metrics']['cases_total']);
        self::assertSame(1, $record['metrics']['cases_active']);
        self::assertSame(1, $record['metrics']['cases_closed']);
        self::assertSame(2, $record['metrics']['episodes_total']);
        self::assertSame(2, $record['metrics']['sessions_total']);
        self::assertSame(1, $record['metrics']['followups_pending']);
        self::assertSame(2, $record['totals']['followups']);
        foreach (['miAttempts', 'surveys', 'intakes', 'forms', 'sharedAppointments', 'callSlips'] as $key) {
            self::assertSame(0, $record['totals'][$key]);
        }
        foreach ($this->queries as $sql) {
            if (str_contains(strtolower($sql), 'from counselling_sessions') || str_contains(strtolower($sql), 'from "counselling_sessions"')) {
                self::assertStringContainsString('COUNT(*)', $sql);
            }
        }
        $event = $this->fixture->table('audit_events')->get()->getRowArray();
        self::assertSame('guidance.student_cumulative.viewed', $event['event_type']);
        self::assertSame('people', $event['entity_type']);
        self::assertSame('10', $event['entity_id']);
        self::assertSame(['authorized_cases' => 2, 'section' => 'overview'], json_decode($event['payload_json'], true));
    }

    public function testPrefixedOverviewPreservesPendingFollowupAliasesBeforePractitionerRead(): void
    {
        $this->fixture->close();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'record_prefix_', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        self::assertSame('SQLite3', $this->fixture->DBDriver);
        self::assertSame(':memory:', $this->fixture->database);
        self::assertSame('record_prefix_', $this->fixture->DBPrefix);
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
            ->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        self::assertSame($this->fixture, Database::connect());
        $this->initializeFixture();
        $this->grant('guidance.queue.read');

        $record = $this->record();
        self::assertSame('overview', $record['activeTab']);
        self::assertSame(2, $record['totals']['followups']);
        self::assertSame(1, $record['metrics']['followups_pending']);
        self::assertSame(2, $record['metrics']['sessions_total']);
        self::assertSame(2, $record['metrics']['cases_total']);
        self::assertSame(1, $record['metrics']['queue_visits_completed']);
        self::assertSame(2, $record['metrics']['queue_visits_total']);
        foreach (self::HISTORY_KEYS as $key) self::assertSame([], $record[$key]);
        $pendingQueryIndex = null;
        $directoryQueryIndex = null;
        foreach ($this->queries as $index => $sql) {
            if (str_contains($sql, 'record_prefix_guidance_followups') && str_contains($sql, "'pending'")) {
                $pendingQueryIndex = $index;
                self::assertStringContainsString('COUNT(*)', $sql);
            }
            if (str_contains($sql, 'record_prefix_users') && str_contains($sql, 'credential_tier')) {
                $directoryQueryIndex = $index;
            }
        }
        self::assertNotNull($pendingQueryIndex);
        self::assertNotNull($directoryQueryIndex);
        self::assertLessThan($directoryQueryIndex, $pendingQueryIndex,
            'Pending followup count must execute before practitioner get resets connection aliases.');
        self::assertSame(1, $this->fixture->table('audit_events')->countAllResults());
    }

    public function testSelectedCasesAndSessionsTabsReturnOnlyAuthorizedProjection(): void
    {
        $cases = $this->record(['tab' => 'cases']);
        self::assertSame([203, 201], $this->ids($cases['authorizedCases']));
        self::assertSame([502, 501], $this->ids($cases['episodes']));
        self::assertSame('Psychosocial Processing (PSP)', $cases['authorizedCases'][0]['service_mode']);
        foreach ($cases['episodes'] as $episode) self::assertArrayNotHasKey('intake_referral_id', $episode);
        self::assertSame([], $cases['sessions']);
        $sessions = $this->record(['tab' => 'sessions']);
        self::assertSame([302, 301], $this->ids($sessions['sessions']));
        self::assertSame([402, 401], $this->ids($sessions['followups']));
        self::assertSame([], $sessions['authorizedCases']);
        foreach ($sessions['followups'] as $row) self::assertArrayNotHasKey('action_plan', $row);
    }

    public function testAcceptedConsultationAddsCaseHistoriesButNotUnauthorizedEpisodes(): void
    {
        $this->enableWrites();
        $this->insert('guidance_case_transfers', ['id' => 699, 'tenant_id' => 1, 'case_id' => 202,
            'to_counsellor_user_id' => 1, 'transfer_type' => 'consultation', 'status' => 'accepted']);
        $this->protectDomain();
        $record = $this->record(['tab' => 'cases']);
        self::assertSame([203, 202, 201], $this->ids($record['authorizedCases']));
        self::assertTrue($record['authorizedCases'][1]['is_consultation']);
        self::assertSame([502, 501], $this->ids($record['episodes']));
        self::assertSame(3, $record['metrics']['sessions_total']);
    }

    public function testIndependentHistoryPagesClampUsingAuthorizedCountsAndReturnNewestRows(): void
    {
        $this->enableWrites();
        for ($id = 2000; $id < 2050; ++$id) {
            $this->case($id, ['opened_at' => '2026-02-01']);
            $this->session($id, ['started_at' => '2026-02-01']);
            $this->followup($id, ['due_date' => '2026-02-01']);
            $this->episode($id, ['opened_at' => '2026-02-01', 'case_id' => 201]);
        }
        $this->protectDomain();
        $record = $this->record(['tab' => 'sessions', 'per_page' => 20, 'sessions_page' => 2,
            'followups_page' => 999, 'cases_page' => 999, 'episodes_page' => 2]);
        self::assertSame(range(2029, 2010), $this->ids($record['sessions']));
        self::assertSame(array_merge(range(2009, 2000), [402, 401]), $this->ids($record['followups']));
        self::assertSame(52, $record['totals']['sessions']);
        self::assertSame(52, $record['totals']['followups']);
        self::assertSame(2, $record['historyPagination']['sessions']['page']);
        self::assertSame(3, $record['historyPagination']['followups']['page']);
        self::assertSame(3, $record['recordQuery']['cases_page']);
        self::assertSame(2, $record['recordQuery']['episodes_page']);
        self::assertSame([], $record['authorizedCases']);
        $cases = $this->record(['tab' => 'cases', 'per_page' => 20, 'cases_page' => 3, 'episodes_page' => 2]);
        self::assertSame(array_merge(range(2009, 2000), [203, 201]), $this->ids($cases['authorizedCases']));
        self::assertSame(range(2029, 2010), $this->ids($cases['episodes']));
    }

    public function testPermissionGatingPreventsAssessmentsIntakeSchedulingAndFormsQueries(): void
    {
        foreach (['assessments', 'coordination', 'appointments', 'forms'] as $tab) {
            $record = $this->record(['tab' => $tab]);
            foreach (['miAttempts', 'pendingAssessments', 'surveys', 'intakes', 'bridgeReferrals', 'safetyReviews',
                'sharedAppointments', 'guidanceAppointments', 'callSlips', 'forms'] as $key) self::assertSame(0, $record['totals'][$key]);
            foreach ($this->queries as $sql) {
                foreach (['guidance_mi_assignments', 'guidance_mi_submissions', 'survey_responses',
                    'guidance_intake_referrals', 'guidance_safety_assessments', 'guidance_appointments',
                    'guidance_call_slips', 'guidance_form_instances'] as $table) self::assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function testAssessmentsTabCountsAndProjectsOnlyTenantSubjectAttemptsAndSurveyMetadata(): void
    {
        $this->grant('guidance.assessments.view', 'guidance.responses.read');
        $record = $this->record(['tab' => 'assessments']);
        self::assertSame([711], $this->ids($record['miAttempts']));
        self::assertSame(711, (int) $record['miAttempts'][0]['id'], 'MI result readers address submission IDs.');
        self::assertSame(701, (int) $record['miAttempts'][0]['assignment_id']);
        self::assertSame(self::MI_SCORES, $record['miAttempts'][0]['domain_scores']);
        self::assertArrayNotHasKey('domain_scores_json', $record['miAttempts'][0]);
        self::assertSame([703, 702], $this->ids($record['pendingAssessments']));
        self::assertSame([731], array_map('intval', array_column($record['surveys'], 'response_id')));
        self::assertSame('Synthetic Person 101', $record['surveys'][0]['interviewer_name']);
        self::assertSame(1, $record['totals']['miAttempts']);
        self::assertSame(2, $record['totals']['pendingAssessments']);
        self::assertSame(1, $record['totals']['surveys']);
        self::assertSame([], $record['intakes']);
    }

    public function testAssessmentDisplayUsesCanonicalDomainScoresAndSubmissionResultLinks(): void
    {
        $this->grant('guidance.assessments.view');
        $record = $this->record(['tab' => 'assessments']);
        $html = view('guidance/clients/_assessments', $record + [
            'displayStatus' => static fn (string $status): string => ucfirst($status),
            'formatDate' => static fn ($date): string => (string) $date,
            'historyPager' => static fn (string $key): string => '',
        ], ['saveData' => false]);
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            self::assertTrue($document->loadHTML('<?xml encoding="UTF-8">' . $html));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $cells = $xpath->query('//table/tbody/tr[1]/td[contains(@class, "text-center")]');
        self::assertSame(8, $cells->length);
        self::assertSame([12, 31, 9, 22, 5, 17, 28, 34],
            array_map(static fn (\DOMNode $cell): int => (int) trim($cell->textContent), iterator_to_array($cells)));
        $resultLinks = $xpath->query('//a[normalize-space()="Results"]');
        self::assertSame(2, $resultLinks->length, 'Desktop and mobile should both use the submission result link.');
        foreach ($resultLinks as $link) {
            self::assertStringEndsWith('/guidance/assessments/mi/results/711', $link->getAttribute('href'));
            self::assertStringNotContainsString('/results/701', $link->getAttribute('href'));
        }
    }

    public function testCoordinationTabOmitsRestrictedCaseLinkedIntakesAndPrivateSafetyPlans(): void
    {
        $this->grant('guidance.intake.process', 'referrals.read', 'guidance.safety.triage');
        $record = $this->record(['tab' => 'coordination']);
        self::assertSame([802, 801], $this->ids($record['intakes']));
        self::assertSame([821], $this->ids($record['bridgeReferrals']));
        self::assertSame([601], $this->ids($record['transfers']));
        self::assertSame([601], $this->ids($record['assignments']));
        self::assertSame([601], $this->ids($record['safetyReviews']));
        self::assertSame([], $record['miAttempts']);
    }

    public function testSchedulingAndCallSlipMetadataRequireAllowedEpisodeAndDocumentContext(): void
    {
        $this->grant('guidance.schedule.read', 'guidance.call_slips.issue', 'guidance.queue.read');
        $record = $this->record(['tab' => 'appointments']);
        self::assertSame([921], $this->ids($record['sharedAppointments']));
        self::assertSame([942, 941], $this->ids($record['guidanceAppointments']));
        self::assertSame([942, 941], $this->ids($record['callSlips']));
        self::assertSame(1, $record['metrics']['queue_visits_completed']);
        self::assertSame(2, $record['metrics']['queue_visits_total']);
        self::assertSame([], $record['forms']);
    }

    public function testFormsTabReturnsAuthorizationAndAttestationMetadataButNoClinicalDrafts(): void
    {
        $this->grant('guidance.clinical.author');
        $record = $this->record(['tab' => 'forms']);
        self::assertSame([1012, 1011], $this->ids($record['forms']));
        self::assertSame(2, $record['totals']['forms']);
        self::assertSame([['signer_role' => 'counselor', 'attested_at' => '2026-01-01']], $record['forms'][1]['attestations']);
        self::assertSame([], $record['forms'][0]['attestations']);
        self::assertSame('pending_cosign', $record['forms'][1]['status']);
        self::assertSame(2, (int) $record['forms'][1]['assigned_supervisor_user_id']);
        self::assertSame([], $record['surveys']);
    }

    public function testStandaloneFormManagementDoesNotRevealOtherCreatorsWithoutReadOversight(): void
    {
        $this->grant('guidance.forms.manage');
        $forms = $this->record(['tab' => 'forms']);
        self::assertSame([1012, 1011], $this->ids($forms['forms']));
        self::assertSame(2, $forms['totals']['forms']);
        self::assertNotContains(1019, $this->ids($forms['forms']));

        $this->grant('guidance.cases.read_any');
        $oversight = $this->record(['tab' => 'forms']);
        self::assertContains(1019, $this->ids($oversight['forms']));
        self::assertSame(count($oversight['forms']), $oversight['totals']['forms']);
    }

    public function testFormCountsAndPreviewMetadataRejectBrokenIndirectAndReverseGraphLinks(): void
    {
        $this->grant('guidance.clinical.author', 'guidance.schedule.read', 'guidance.call_slips.issue');
        $this->enableWrites();
        $this->form(1201, ['intake_id' => 803]);
        $this->form(1202, ['appointment_id' => 943]);
        $this->form(1203, ['referral_id' => 824]);
        $this->form(1204, ['definition_revision_id' => 1102]);
        $this->form(1205, ['session_id' => 303]);
        $this->form(1206, ['referral_id' => 99999]);
        $this->form(1207, ['created_by_user_id' => 9]);
        $this->form(1208);
        foreach (['guidance_appointments', 'guidance_call_slips'] as $table) {
            $number = $table === 'guidance_appointments' ? 'appointment_number' : 'call_slip_number';
            foreach ([1201, 1202, 1203, 1204, 1205, 1206, 1207] as $id) {
                $this->insert($table, ['id' => $id, 'tenant_id' => 1, 'person_id' => 10,
                    'form_instance_id' => $id, $number => 'SYNTH-DENIED-' . $id]);
            }
            // Even an otherwise standalone form cannot ignore a conflicting reverse subject.
            $this->insert($table, ['id' => 1208, 'tenant_id' => 1, 'person_id' => 20,
                'form_instance_id' => 1208, $number => 'SYNTH-DENIED-1208']);
        }
        $this->protectDomain();

        $forms = $this->record(['tab' => 'forms']);
        self::assertSame([1012, 1011], $this->ids($forms['forms']));
        self::assertSame(2, $forms['totals']['forms']);
        $appointments = $this->record(['tab' => 'appointments']);
        foreach (['guidanceAppointments', 'callSlips'] as $key) {
            self::assertSame([942, 941], $this->ids($appointments[$key]));
            self::assertSame(2, $appointments['totals'][$key]);
        }
    }

    public function testStandaloneFormMetadataRequiresCurrentQualifiedSupervisionNotItsSavedSnapshot(): void
    {
        $this->grant('guidance.clinical.author');
        $this->enableWrites();
        $this->insert('guidance_supervision_assignments', ['id' => 1, 'tenant_id' => 1,
            'associate_user_id' => 1, 'supervisor_user_id' => 2, 'status' => 'active']);
        $this->identity = ['id' => 2, 'tenant_id' => 1];
        $this->protectDomain();
        $current = $this->record(['tab' => 'forms']);
        self::assertContains(1012, $this->ids($current['forms']));

        $this->enableWrites();
        $this->fixture->table('guidance_supervision_assignments')->where('id', 1)->update(['status' => 'ended']);
        $this->protectDomain();
        $ended = $this->record(['tab' => 'forms']);
        self::assertNotContains(1012, $this->ids($ended['forms']));
        self::assertSame(count($ended['forms']), $ended['totals']['forms']);

        $this->enableWrites();
        $this->fixture->table('guidance_supervision_assignments')->where('id', 1)
            ->update(['status' => 'active', 'supervisor_user_id' => 3]);
        $this->protectDomain();
        self::assertNotContains(1012, $this->ids($this->record(['tab' => 'forms'])['forms']));
        $this->identity = ['id' => 3, 'tenant_id' => 1];
        self::assertContains(1012, $this->ids($this->record(['tab' => 'forms'])['forms']));

        $this->enableWrites();
        $this->fixture->table('guidance_practitioner_profiles')->where('user_id', 3)
            ->update(['professional_standing' => 'suspended']);
        $this->protectDomain();
        self::assertNotContains(1012, $this->ids($this->record(['tab' => 'forms'])['forms']));
    }

    public function testPrefixedFormsAndPreviewCountsUseOnlyAuthorizedContext(): void
    {
        $this->fixture->close();
        $this->fixture = Database::connect([
            'DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:',
            'DBPrefix' => 'record_prefix_', 'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ], false);
        (new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))
            ->setValue(null, ['tests' => $this->fixture, 'default' => $this->fixture]);
        $this->initializeFixture();
        $this->grant('guidance.clinical.author', 'guidance.schedule.read', 'guidance.call_slips.issue');
        $forms = $this->record(['tab' => 'forms']);
        self::assertSame([1012, 1011], $this->ids($forms['forms']));
        $appointments = $this->record(['tab' => 'appointments']);
        self::assertSame([942, 941], $this->ids($appointments['guidanceAppointments']));
        self::assertSame([942, 941], $this->ids($appointments['callSlips']));
    }

    public function testFormSessionMustMatchItsCaseEvenWhenBothCasesAreAuthorized(): void
    {
        // Both 201 and 203 belong to this actor. Independent read access cannot repair mismatched linkage.
        $this->grant('guidance.clinical.author', 'guidance.schedule.read', 'guidance.call_slips.issue');
        $this->enableWrites();
        $this->form(1022, ['case_id' => 201, 'session_id' => 302]);
        $this->form(1023, ['case_id' => 203, 'session_id' => 302]);
        $this->form(1024, ['case_id' => null, 'session_id' => 302]);
        foreach ([[952, 1022], [953, 1023], [954, 1024]] as [$id, $formId]) {
            $common = ['id' => $id, 'tenant_id' => 1, 'person_id' => 10, 'episode_id' => null,
                'form_instance_id' => $formId, 'deleted_at' => null];
            $this->insert('guidance_appointments', $common + ['counselor_user_id' => 1,
                'appointment_number' => 'SYNTH-APPT-' . $id, 'scheduled_date' => '2026-01-01',
                'scheduled_time' => '09:00:00', 'status' => 'scheduled', 'notes_cipher' => self::PRIVATE_VALUE]);
            $this->insert('guidance_call_slips', $common + ['issuing_counselor_user_id' => 1,
                'call_slip_number' => 'SYNTH-CALL-' . $id, 'call_date' => '2026-01-01',
                'call_time' => '09:00:00', 'attendance_status' => 'pending', 'delivery_notes' => self::PRIVATE_VALUE]);
        }
        $this->protectDomain();
        $cases = $this->record(['tab' => 'cases']);
        self::assertSame([203, 201], $this->ids($cases['authorizedCases']));
        $forms = $this->record(['tab' => 'forms']);
        self::assertSame([1024, 1023, 1012, 1011], $this->ids($forms['forms']));
        self::assertSame(4, $forms['totals']['forms']);
        self::assertNotContains(1022, $this->ids($forms['forms']));
        $appointments = $this->record(['tab' => 'appointments']);
        foreach (['guidanceAppointments', 'callSlips'] as $key) {
            self::assertSame([954, 953, 942, 941], $this->ids($appointments[$key]));
            self::assertSame(4, $appointments['totals'][$key]);
            self::assertNotContains(952, $this->ids($appointments[$key]));
        }
    }

    public function testRecordWithoutAnyOwnedCasesDoesNotLeakRestrictedCaseOrSessionCounts(): void
    {
        $this->identity = ['id' => 3, 'tenant_id' => 1];
        $record = $this->record(['tab' => 'sessions']);
        self::assertSame(0, $record['metrics']['cases_total']);
        self::assertSame(0, $record['metrics']['sessions_total']);
        self::assertSame(0, $record['metrics']['episodes_total']);
        self::assertSame([], $record['sessions']);
        self::assertSame([], $record['followups']);
    }

    public function testEmployeeAndNoHistoryStudentNeedNoPortalLoginOrNewEpisode(): void
    {
        $employee = $this->record([], 20);
        self::assertSame('employee', $employee['person']['kind']);
        self::assertNull($employee['studentProfile']);
        self::assertSame('Synthetic Teacher', $employee['employeeProfile']['position']);
        self::assertSame(1, $employee['metrics']['cases_total']);
        $empty = $this->record([], 60);
        self::assertSame(0, $empty['metrics']['cases_total']);
        self::assertSame(0, $empty['metrics']['episodes_total']);
    }

    public function testReadGrantIsReloadedAndNoActorHintCanGrantOrReplaceTransportIdentity(): void
    {
        $forged = ['id' => 1, 'tenant_id' => 1, 'role' => 'superadmin',
            'permissions' => ['guidance.cases.read', 'guidance.cases.read_any']];
        self::assertSame(2, $this->record([], 10, $forged)['metrics']['cases_total']);
        $this->enableWrites();
        $this->fixture->table('role_permissions')->where('role', 'counsellor')
            ->where('permission_id', $this->grants['guidance.cases.read'])->delete();
        $this->denied([], 10, 403, $forged);
        $this->denied([], 10, 403, ['id' => 2, 'tenant_id' => 1]);
    }

    public static function invalidPeople(): iterable
    {
        yield 'guest' => [30];
        yield 'external' => [31];
        yield 'other tenant' => [40];
        yield 'deleted' => [50];
        yield 'missing' => [999];
    }

    #[DataProvider('invalidPeople')]
    public function testInvalidClientIsNotFoundBeforeHistoryAndAudit(int $personId): void
    {
        $this->denied([], $personId, 404);
        foreach ($this->queries as $sql) self::assertStringNotContainsString('counselling_cases', $sql);
    }

    public function testUnauthenticatedAndMismatchedTenantRecordRequestsAreRejected(): void
    {
        CurrentTenant::set(2);
        $this->denied([], 10, 403);
        CurrentTenant::set(1);
        $this->denied([], 10, 403, ['id' => 1, 'tenant_id' => 2]);
        $this->identity = null;
        $this->denied([], 10, 401, ['id' => 1, 'tenant_id' => 1]);
    }

    public static function invalidPagination(): iterable
    {
        yield 'unknown tab' => [['tab' => 'clinical_notes']];
        yield 'array tab' => [['tab' => ['cases']]];
        yield 'per page range' => [['per_page' => 30]];
        yield 'negative page' => [['sessions_page' => -1]];
        yield 'zero page' => [['forms_page' => 0]];
        yield 'nested page' => [['intakes_page' => ['2']]];
        yield 'hidden invalid page' => [['tab' => 'overview', 'mi_page' => 'NaN']];
    }

    #[DataProvider('invalidPagination')]
    public function testInvalidIndependentTabOrPaginationParametersAreRejected(array $query): void
    {
        $this->denied($query, 10, 422);
    }

    public function testRepeatedReadsLeaveDomainRowsUnchangedAndAppendValidMetadataOnlyAudits(): void
    {
        $before = $this->snapshot();
        $this->record();
        $this->record(['tab' => 'sessions']);
        self::assertSame($before, $this->snapshot());
        $events = $this->fixture->table('audit_events')->orderBy('id')->get()->getResultArray();
        self::assertCount(2, $events);
        foreach ($events as $event) {
            self::assertSame('guidance.student_cumulative.viewed', $event['event_type']);
            self::assertSame(1, (int) $event['actor_user_id']);
            self::assertSame('counsellor', $event['actor_role']);
            self::assertSame(['authorized_cases', 'section'], array_keys(json_decode($event['payload_json'], true)));
        }
        self::assertTrue((new AuditService(null, $this->fixture))->verifyChain()['valid']);
    }

    public static function auditFailures(): iterable
    {
        yield 'missing table' => ['missing'];
        yield 'refused insert' => ['refused'];
        yield 'invalid chain head' => ['bad_head'];
    }

    #[DataProvider('auditFailures')]
    public function testAuditFailureReturnsNoRecordAndDoesNotChangeBusinessRows(string $failure): void
    {
        if ($failure === 'missing') {
            $this->fixture->query('DROP TABLE audit_events');
        } elseif ($failure === 'refused') {
            $this->fixture->query("CREATE TRIGGER deny_audit BEFORE INSERT ON audit_events BEGIN SELECT RAISE(ABORT, 'Synthetic audit failure'); END");
        } else {
            (new AuditService(null, $this->fixture))->log('fixture', 'fixture', '1', 'fixture');
            $this->fixture->table('audit_events')->update(['curr_hash' => str_repeat('f', 64)]);
        }
        $result = null;
        $caught = null;
        try {
            $result = $this->record(['tab' => 'cases']);
        } catch (Throwable $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'Audit failure must fail the entire cumulative read.');
        self::assertNull($result, 'No successful record may escape a failed audit append.');
    }

    public function testMetadataReadDoesNotRequireCounselingEncryptionConfiguration(): void
    {
        $saved = [];
        foreach (['COUNSELLING_KEY', 'COUNSELLING_KEY_VERSION'] as $name) {
            $saved[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv($name . '=invalid-synthetic-value');
            $_ENV[$name] = $_SERVER[$name] = 'invalid-synthetic-value';
        }
        try {
            self::assertSame(2, $this->record()['metrics']['episodes_total']);
        } finally {
            foreach ($saved as $name => [$env, $local, $server]) {
                putenv($env === false ? $name : $name . '=' . $env);
                unset($_ENV[$name], $_SERVER[$name]);
                if ($local !== null) $_ENV[$name] = $local;
                if ($server !== null) $_SERVER[$name] = $server;
            }
        }
    }
}
