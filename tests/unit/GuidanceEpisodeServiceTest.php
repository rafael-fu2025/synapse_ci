<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\Seeds\PermissionsAndRolesSeeder;
use App\Models\CounsellingCaseModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\GuidanceFormDefinitionModel;
use App\Models\GuidanceFormDefinitionRevisionModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceEpisodeService;
use App\Services\Guidance\GuidancePractitionerService;
use App\Services\Guidance\GuidanceTransferService;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\DatabaseTestTrait;
use DomainException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FreshDatabaseTestCase;

final class GuidanceEpisodeServiceTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = false;
    protected $namespace = 'App';

    private GuidanceEpisodeService $episodeService;
    private GuidanceEpisodeModel $episodeModel;
    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceCallSlipModel $callSlipModel;
    private GuidanceIntakeReferralModel $intakeReferralModel;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private GuidanceFormInstanceModel $formInstanceModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private PersonModel $personModel;
    private UserModel $userModel;
    private GuidancePractitionerService $practitionerService;
    private GuidanceTransferService $transferService;
    private GuidanceAccessPolicy $accessPolicy;
    private EncryptionService $encryptionService;
    private array $detailReadQueries = [];

    private int $counselorAUserId;
    private int $counselorBUserId;
    private int $unqualifiedUserId;
    private int $expiredPractitionerUserId;
    private int $supervisorUserId;
    private int $studentPersonId;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        CurrentTenant::set(1);

        (new PermissionsAndRolesSeeder(config('Database'), $this->db))->run();

        $this->episodeModel = new GuidanceEpisodeModel($this->db);
        $this->appointmentModel = new GuidanceAppointmentModel($this->db);
        $this->callSlipModel = new GuidanceCallSlipModel($this->db);
        $this->intakeReferralModel = new GuidanceIntakeReferralModel($this->db);
        $this->caseModel = new CounsellingCaseModel($this->db);
        $this->sessionModel = new CounsellingSessionModel($this->db);
        $this->formInstanceModel = new GuidanceFormInstanceModel($this->db);
        $this->profileModel = new GuidancePractitionerProfileModel($this->db);
        $this->supervisionModel = new GuidanceSupervisionAssignmentModel($this->db);
        $this->personModel = new PersonModel($this->db);
        $this->userModel = new UserModel($this->db);
        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);
        $this->encryptionService = new EncryptionService();

        $this->practitionerService = new GuidancePractitionerService(
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $this->transferService = new GuidanceTransferService(
            caseModel: $this->caseModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );

        $this->episodeService = new GuidanceEpisodeService(
            episodeModel: $this->episodeModel,
            appointmentModel: $this->appointmentModel,
            callSlipModel: $this->callSlipModel,
            intakeReferralModel: $this->intakeReferralModel,
            caseModel: $this->caseModel,
            sessionModel: $this->sessionModel,
            formInstanceModel: $this->formInstanceModel,
            personModel: $this->personModel,
            userModel: $this->userModel,
            practitionerProfileModel: $this->profileModel,
            practitionerService: $this->practitionerService,
            transferService: $this->transferService,
            accessPolicy: $this->accessPolicy,
            encryptionService: $this->encryptionService,
            db: $this->db
        );

        $unique = bin2hex(random_bytes(4));

        // 1. Student Person
        $this->studentPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'student',
            'mis_id'     => 'STU_' . $unique,
            'first_name' => 'Maria',
            'last_name'  => 'Santos',
        ]);

        // 2. Counselor A (Licensed Counselor, active)
        $counsAPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_A_' . $unique,
            'first_name' => 'Alice',
            'last_name'  => 'Counselor',
        ]);
        $this->counselorAUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsAPersonId,
            'username'  => 'counselor_a_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $counsAPersonId,
            'user_id'               => $this->counselorAUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-LIC-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'assessment']),
            'is_active'             => 1,
        ]);

        // 3. Counselor B (Licensed Counselor, active)
        $counsBPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_B_' . $unique,
            'first_name' => 'Bob',
            'last_name'  => 'Counselor',
        ]);
        $this->counselorBUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $counsBPersonId,
            'username'  => 'counselor_b_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $counsBPersonId,
            'user_id'               => $this->counselorBUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-LIC-B-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+1 year')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake']),
            'is_active'             => 1,
        ]);

        // 4. Supervisor User
        $supPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_SUP_' . $unique,
            'first_name' => 'Sarah',
            'last_name'  => 'Supervisor',
        ]);
        $this->supervisorUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $supPersonId,
            'username'  => 'supervisor_' . $unique,
            'role'      => 'guidance_supervisor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $supPersonId,
            'user_id'               => $this->supervisorUserId,
            'credential_tier'       => 'guidance_supervisor',
            'license_number'        => 'PRC-SUP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('+2 years')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling', 'intake', 'supervision']),
            'is_active'             => 1,
        ]);

        // 5. Unqualified User (no practitioner profile, staff role)
        $unqPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_UNQ_' . $unique,
            'first_name' => 'Una',
            'last_name'  => 'Staff',
        ]);
        $this->unqualifiedUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $unqPersonId,
            'username'  => 'unq_staff_' . $unique,
            'role'      => 'staff',
            'status'    => 'active',
        ]);

        // 6. Expired Practitioner User
        $expPersonId = (int) $this->personModel->insert([
            'tenant_id'  => 1,
            'kind'       => 'employee',
            'mis_id'     => 'EMP_EXP_' . $unique,
            'first_name' => 'Evan',
            'last_name'  => 'Expired',
        ]);
        $this->expiredPractitionerUserId = (int) $this->userModel->insert([
            'tenant_id' => 1,
            'person_id' => $expPersonId,
            'username'  => 'exp_couns_' . $unique,
            'role'      => 'counsellor',
            'status'    => 'active',
        ]);
        $this->profileModel->insert([
            'tenant_id'             => 1,
            'person_id'             => $expPersonId,
            'user_id'               => $this->expiredPractitionerUserId,
            'credential_tier'       => 'licensed_counselor',
            'license_number'        => 'PRC-EXP-' . $unique,
            'credential_expiry'     => date('Y-m-d', strtotime('-30 days')),
            'professional_standing' => 'active',
            'service_scope_json'    => json_encode(['counseling']),
            'is_active'             => 1,
        ]);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function signInReadActor(int $userId): array
    {
        CurrentTenant::set(1);
        $identity = (new AuthenticatedUser())->forIdentity($userId, 1);
        self::assertNotNull($identity, 'Read fixtures require a real same-tenant transport identity.');
        $now = time();
        session()->set([
            'user' => $identity,
            'tenant_id' => 1,
            '_auth' => [
                'started_at' => $now,
                'last_seen_at' => $now,
                'version' => $identity['auth_version'],
                'session_id' => session_id(),
            ],
        ]);
        return $identity;
    }

    private function insertReadEpisode(array $overrides = []): int
    {
        $id = $this->episodeModel->insert(array_replace([
            'tenant_id' => 1,
            'person_id' => $this->studentPersonId,
            'primary_counselor_user_id' => $this->counselorAUserId,
            'case_id' => null,
            'intake_referral_id' => null,
            'status' => 'open',
            'opened_at' => '2026-01-01 09:00:00',
            'closure_notes_cipher' => null,
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadCase(array $overrides = []): int
    {
        $id = $this->caseModel->insert(array_replace([
            'tenant_id' => 1,
            'person_id' => $this->studentPersonId,
            'counsellor_user_id' => $this->counselorAUserId,
            'case_number' => 'SYNTH-EP-CASE-' . bin2hex(random_bytes(4)),
            'title' => 'Synthetic linked counseling case',
            'category' => 'personal',
            'status' => 'open',
            'opened_at' => '2026-01-01 09:00:00',
            'summary_notes' => 'Synthetic linked case summary',
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadSession(int $caseId, array $overrides = []): int
    {
        $id = $this->sessionModel->insert(array_replace([
            'tenant_id' => 1,
            'case_id' => $caseId,
            'counsellor_user_id' => $this->counselorAUserId,
            'session_number' => 1,
            'session_type' => 'regular',
            'status' => 'in_progress',
            'started_at' => '2026-01-01 09:00:00',
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadIntake(array $overrides = []): int
    {
        $id = $this->intakeReferralModel->insert(array_replace([
            'tenant_id' => 1,
            'referral_number' => 'SYNTH-EP-INTAKE-' . bin2hex(random_bytes(4)),
            'source_type' => 'self',
            'subject_person_id' => $this->studentPersonId,
            'referrer_user_id' => $this->counselorAUserId,
            'referrer_name_snapshot' => 'Synthetic student',
            'referrer_designation_snapshot' => 'Student',
            'referrer_department_snapshot' => 'Synthetic Guidance',
            'assigned_counsellor_user_id' => $this->counselorAUserId,
            'linked_case_id' => null,
            'status' => 'triaged',
            'priority_hint' => 'routine',
            'concern_catalog_version' => '1.0',
            'selections_cipher' => 'SYNTHETIC-PRIVATE-INTAKE-SELECTIONS',
            'selections_nonce' => str_repeat('n', 12),
            'selections_tag' => str_repeat('t', 16),
            'selections_key_version' => 1,
            'narrative_cipher' => 'SYNTHETIC-PRIVATE-INTAKE-NARRATIVE',
            'narrative_nonce' => str_repeat('n', 12),
            'narrative_tag' => str_repeat('t', 16),
            'narrative_key_version' => 1,
            'triage_notes' => 'SYNTHETIC-PRIVATE-INTAKE-TRIAGE',
            'submitted_at' => '2026-01-01 09:00:00',
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadPerson(): int
    {
        $id = $this->personModel->insert([
            'tenant_id' => 1,
            'kind' => 'student',
            'mis_id' => 'SYNTH_EP_OTHER_' . bin2hex(random_bytes(4)),
            'first_name' => 'Synthetic',
            'last_name' => 'Other student',
        ]);
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadTenant(): int
    {
        self::assertTrue($this->db->table('tenants')->insert([
            'slug' => 'synthetic-episode-' . bin2hex(random_bytes(4)),
            'name' => 'Synthetic foreign episode tenant',
            'is_active' => 1,
            'created_at' => '2026-01-01 09:00:00',
            'updated_at' => '2026-01-01 09:00:00',
        ]));
        return (int) $this->db->insertID();
    }

    private function insertReadAppointment(int $episodeId, array $overrides = []): int
    {
        $id = $this->appointmentModel->insert(array_replace([
            'tenant_id' => 1,
            'episode_id' => $episodeId,
            'person_id' => $this->studentPersonId,
            'counselor_user_id' => $this->counselorAUserId,
            'appointment_number' => 'SYNTH-EP-APT-' . bin2hex(random_bytes(4)),
            'scheduled_date' => '2026-02-01',
            'scheduled_time' => '10:00:00',
            'purpose' => 'routine_interview',
            'purpose_other' => 'SYNTHETIC-PRIVATE-PREVIEW-PURPOSE',
            'notes_cipher' => 'SYNTHETIC-PRIVATE-PREVIEW-CIPHER',
            'status' => 'scheduled',
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadCallSlip(int $episodeId, array $overrides = []): int
    {
        $id = $this->callSlipModel->insert(array_replace([
            'tenant_id' => 1,
            'episode_id' => $episodeId,
            'person_id' => $this->studentPersonId,
            'issuing_counselor_user_id' => $this->counselorAUserId,
            'call_slip_number' => 'SYNTH-EP-SLIP-' . bin2hex(random_bytes(4)),
            'call_date' => '2026-02-01',
            'call_time' => '11:00:00',
            'purpose' => 'routine_interview',
            'purpose_other' => 'SYNTHETIC-PRIVATE-PREVIEW-PURPOSE',
            'delivery_notes' => 'SYNTHETIC-PRIVATE-PREVIEW-DELIVERY',
            'attendance_status' => 'pending',
            'parent_guardian_delivery' => 0,
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function insertReadFormDefinition(array $overrides = []): array
    {
        $existing = $this->db->table('guidance_form_definitions')
            ->where('tenant_id', $overrides['tenant_id'] ?? 1)
            ->where('internal_key', $overrides['internal_key'] ?? 'guidance_general_referral')
            ->get()->getRowArray();
        if ($existing !== null) {
            $revision = $this->db->table('guidance_form_definition_revisions')
                ->where('tenant_id', $existing['tenant_id'])->where('form_definition_id', $existing['id'])
                ->get()->getRowArray();
            self::assertNotNull($revision);
            return [(int) $existing['id'], (int) $revision['id']];
        }
        $definitionId = (new GuidanceFormDefinitionModel($this->db))->insert(array_replace([
            'tenant_id' => 1,
            'internal_key' => 'guidance_general_referral',
            'form_code' => 'F-OGC-GRE-GGR-005-00',
            'canonical_title' => 'Synthetic guidance general referral',
            'owner' => 'Synthetic Guidance',
            'is_active' => 1,
        ], $overrides));
        self::assertNotFalse($definitionId);
        $revisionId = (new GuidanceFormDefinitionRevisionModel($this->db))->insert([
            'tenant_id' => $overrides['tenant_id'] ?? 1,
            'form_definition_id' => (int) $definitionId,
            'revision_number' => '00',
            'schema_version' => '1.0.0',
            'renderer_version' => '1.0.0',
            'schema_json' => '{}',
            'schema_hash' => hash('sha256', '{}'),
            'effectivity_date' => '2026-01-01',
            'approval_state' => 'approved',
        ]);
        self::assertNotFalse($revisionId);
        return [(int) $definitionId, (int) $revisionId];
    }

    private function insertReadForm(int $definitionId, int $revisionId, array $overrides = []): int
    {
        $id = $this->formInstanceModel->insert(array_replace([
            'tenant_id' => 1,
            'form_definition_id' => $definitionId,
            'definition_revision_id' => $revisionId,
            'person_id' => $this->studentPersonId,
            'case_id' => null,
            'episode_id' => null,
            'session_id' => null,
            'intake_id' => null,
            'instance_number' => 'SYNTH-EP-FORM-' . bin2hex(random_bytes(4)),
            'status' => 'finalized',
            'created_by_user_id' => $this->counselorAUserId,
            'finalized_by_user_id' => $this->counselorAUserId,
            'finalized_at' => '2026-02-01 09:00:00',
            'submission_notes' => 'SYNTHETIC-PRIVATE-FORM-SUBMISSION',
            'supervisor_notes' => 'SYNTHETIC-PRIVATE-FORM-SUPERVISOR',
        ], $overrides));
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function revokeReadPermission(string $role, string $code): void
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        self::assertNotNull($permission);
        self::assertSame(1, $this->db->table('role_permissions')->where('role', $role)
            ->where('permission_id', $permission['id'])->countAllResults());
        self::assertTrue($this->db->table('role_permissions')->where('role', $role)
            ->where('permission_id', $permission['id'])->delete());
    }

    private function readDomainSnapshot(): array
    {
        $snapshot = [];
        foreach ($this->db->listTables() as $table) {
            if ($table === 'audit_events') {
                continue;
            }
            $rows = array_map('serialize', $this->db->table($table)->get()->getResultArray());
            sort($rows, SORT_STRING);
            $snapshot[$table] = hash('sha256', serialize($rows));
        }
        ksort($snapshot);
        return $snapshot;
    }

    private function readAuditRows(): array
    {
        return $this->db->table('audit_events')->where('tenant_id', 1)->orderBy('id', 'ASC')->get()->getResultArray();
    }

    private function readEpisodeWithoutDomainChanges(int $episodeId, array $actor): array
    {
        $before = $this->readDomainSnapshot();
        $this->detailReadQueries = [];
        $listener = function ($query): void { $this->detailReadQueries[] = (string) $query; };
        Events::on('DBQuery', $listener);
        try {
            return $this->episodeService->getEpisodeDetails($episodeId, 1, $actor);
        } finally {
            Events::removeListener('DBQuery', $listener);
            self::assertSame($before, $this->readDomainSnapshot(), 'Episode reads must not mutate any domain rows.');
            self::assertSame(0, $this->db->transDepth, 'Episode reads must leave no open transaction.');
        }
    }

    private function assertEpisodeReadRejected(int $episodeId, int $code, array $actor, int $tenantId = 1): void
    {
        $before = $this->readDomainSnapshot();
        $auditsBefore = $this->readAuditRows();
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        $result = null;
        $caught = null;
        Events::on('DBQuery', $listener);
        try {
            try {
                $result = $this->episodeService->getEpisodeDetails($episodeId, $tenantId, $actor);
            } catch (DomainException | InvalidArgumentException $exception) {
                $caught = $exception;
            }
        } finally {
            Events::removeListener('DBQuery', $listener);
            self::assertSame($before, $this->readDomainSnapshot(), 'Rejected reads must not mutate domain rows.');
            self::assertSame($auditsBefore, $this->readAuditRows(), 'Rejected reads must not append success audits.');
            self::assertSame(0, $this->db->transDepth);
        }
        self::assertNull($result, 'A rejected read must not release an episode package.');
        self::assertNotNull($caught, 'Episode read unexpectedly permitted an invalid or restricted link.');
        self::assertSame($code, $caught->getCode(), $caught->getMessage());
        self::assertNotEmpty($queries, 'Read-query observation must be active.');
        foreach ($queries as $query) {
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:from|join)\s+[`"]?(?:counselling_sessions|counselling_notes|guidance_followups|guidance_form_instances|guidance_appointments|guidance_call_slips|student_profiles)\b/i',
                $query,
                'Case/intake authorization must precede detail and section queries.'
            );
            if (preg_match('/\bfrom\s+[`"]?counselling_cases\b/i', $query)) {
                self::assertDoesNotMatchRegularExpression('/\*|\btitle\b|\bsummary_notes\b|closure_disposition_/i', $query,
                    'Restricted case content must not be selected before its read policy.');
            }
        }
    }

    private function assertEpisodeReadEvents(int $episodeId, int $actorId, array $before, ?int $oversightCaseId = null): void
    {
        $all = $this->readAuditRows();
        self::assertSame($before, array_slice($all, 0, count($before)), 'Read audits must preserve the existing ledger.');
        $events = array_slice($all, count($before));
        $expectedTypes = $oversightCaseId === null
            ? ['guidance.episode.read'] : ['guidance.case.oversight_read', 'guidance.episode.read'];
        self::assertSame($expectedTypes, array_column($events, 'event_type'));
        $episodeEvent = $events[array_key_last($events)];
        self::assertSame('guidance_episodes', $episodeEvent['entity_type']);
        self::assertSame((string) $episodeId, $episodeEvent['entity_id']);
        self::assertSame('read', $episodeEvent['action']);
        if ($oversightCaseId !== null) {
            self::assertSame('counselling_cases', $events[0]['entity_type']);
            self::assertSame((string) $oversightCaseId, $events[0]['entity_id']);
            self::assertSame('oversight_read', $events[0]['action']);
        }
        $actor = $this->userModel->find($actorId);
        foreach ($events as $event) {
            self::assertSame($actorId, (int) $event['actor_user_id']);
            self::assertSame($actor['role'], $event['actor_role']);
            self::assertNull($event['payload_json'], 'Read audits must not contain clinical or closure content.');
            self::assertSame(2, (int) $event['hash_version']);
        }
        self::assertTrue((new AuditService(null, $this->db))->verifyChain(1)['valid']);
    }

    private function assertReadMetadataKeys(array $row, array $expected): void
    {
        $keys = array_keys($row);
        sort($keys);
        sort($expected);
        self::assertSame($expected, $keys, 'Episode previews must contain exactly the metadata projection.');
    }

    public function testOpenEpisodeSuccess(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
                'status' => 'open',
            ]
        );

        self::assertNotNull($episode);
        self::assertGreaterThan(0, (int) $episode['id']);
        self::assertSame('open', $episode['status']);
        self::assertSame($this->studentPersonId, (int) $episode['person_id']);
        self::assertSame($this->counselorAUserId, (int) $episode['primary_counselor_user_id']);
        self::assertNotEmpty($episode['opened_at']);
        self::assertNull($episode['closed_at']);
    }

    public function testOpenEpisodeWithOptionalLinks(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        // Create intake referral
        $referralId = (int) $this->intakeReferralModel->insert([
            'tenant_id'                      => 1,
            'referral_number'                => 'GIR-' . bin2hex(random_bytes(4)),
            'source_type'                    => 'self',
            'subject_person_id'              => $this->studentPersonId,
            'referrer_user_id'               => $this->counselorAUserId,
            'referrer_name_snapshot'         => 'Self Referred',
            'referrer_designation_snapshot'  => 'Student',
            'referrer_department_snapshot'   => 'Guidance',
            'status'                         => 'triaged',
            'concern_catalog_version'        => '1.0',
            'selections_cipher'              => 'test',
            'selections_nonce'               => random_bytes(12),
            'selections_tag'                 => random_bytes(16),
            'selections_key_version'         => 1,
            'narrative_cipher'               => 'test',
            'narrative_nonce'                => random_bytes(12),
            'narrative_tag'                  => random_bytes(16),
            'narrative_key_version'          => 1,
            'created_at'                     => date('Y-m-d H:i:s'),
            'updated_at'                     => date('Y-m-d H:i:s'),
        ]);

        // Create counseling case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->counselorAUserId,
            'case_number'        => 'CAS-' . bin2hex(random_bytes(4)),
            'title'              => 'Academic Counseling Case',
            'category'           => 'academic',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor'                     => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
                'intake_referral_id'        => $referralId,
                'case_id'                   => $caseId,
            ]
        );

        self::assertSame($referralId, (int) $episode['intake_referral_id']);
        self::assertSame($caseId, (int) $episode['case_id']);
    }

    public function testOpenEpisodeFailsWithoutPermission(): void
    {
        $actor = [
            'id' => $this->unqualifiedUserId,
            'tenant_id' => 1,
            'role' => 'staff',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
    }

    public function testOpenEpisodeFailsWithUnqualifiedCounselor(): void
    {
        $actor = [
            'id' => $this->supervisorUserId,
            'tenant_id' => 1,
            'role' => 'guidance_supervisor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->unqualifiedUserId,
            ]
        );
    }

    public function testOpenEpisodeFailsWithExpiredPractitioner(): void
    {
        $actor = [
            'id' => $this->supervisorUserId,
            'tenant_id' => 1,
            'role' => 'guidance_supervisor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->expiredPractitionerUserId,
            ]
        );
    }

    public function testOpenEpisodeFailsWithNonexistentPerson(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(404);

        $this->episodeService->openEpisode(
            999999,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
    }

    public function testGetEpisodeDetailsSuccessWithLinkedRecords(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // 1. Add appointment linked to episode
        $this->appointmentModel->insert([
            'tenant_id'          => 1,
            'episode_id'         => $episodeId,
            'person_id'          => $this->studentPersonId,
            'counselor_user_id'  => $this->counselorAUserId,
            'appointment_number' => 'APT-' . bin2hex(random_bytes(4)),
            'scheduled_date'     => date('Y-m-d', strtotime('+2 days')),
            'scheduled_time'     => '10:00:00',
            'purpose'            => 'routine_interview',
            'status'             => 'scheduled',
        ]);

        // 2. Add call slip linked to episode
        $this->callSlipModel->insert([
            'tenant_id'                  => 1,
            'episode_id'                 => $episodeId,
            'person_id'                  => $this->studentPersonId,
            'issuing_counselor_user_id'  => $this->counselorAUserId,
            'call_slip_number'           => 'CS-' . bin2hex(random_bytes(4)),
            'call_date'                  => date('Y-m-d', strtotime('+3 days')),
            'call_time'                  => '14:00:00',
            'purpose'                    => 'routine_interview',
            'attendance_status'          => 'pending',
            'parent_guardian_delivery'   => 0,
        ]);

        $details = $this->episodeService->getEpisodeDetails($episodeId, 1, $actor);

        self::assertNotNull($details);
        self::assertSame($episodeId, (int) $details['id']);
        self::assertNotEmpty($details['person']);
        self::assertSame('Maria', $details['person']['first_name']);
        self::assertNotEmpty($details['primary_counselor']);
        self::assertSame($this->counselorAUserId, (int) $details['primary_counselor']['id']);
        self::assertCount(1, $details['appointments']);
        self::assertCount(1, $details['call_slips']);
        self::assertIsArray($details['evaluations']);
    }

    public function testGetEpisodeDetailsAccessControl(): void
    {
        $actorA = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actorA,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // Counselor B (not assigned, no read_any) should be denied
        $actorB = [
            'id' => $this->counselorBUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->getEpisodeDetails($episodeId, 1, $actorB);
    }

    public function testOwnedUnlinkedEpisodeDoesNotAcquireAnotherOwnersNewerPersonRecords(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $ownedCaseId = $this->insertReadCase();
        $ownedIntakeId = $this->insertReadIntake(['linked_case_id' => $ownedCaseId]);
        $episodeId = $this->insertReadEpisode(['opened_at' => '2026-01-01 09:00:00']);
        $otherCaseId = $this->insertReadCase([
            'counsellor_user_id' => $this->counselorBUserId,
            'title' => 'SYNTHETIC-OTHER-OWNER-CASE',
            'opened_at' => '2026-02-01 09:00:00',
        ]);
        $this->insertReadSession($otherCaseId, ['counsellor_user_id' => $this->counselorBUserId]);
        $this->insertReadIntake([
            'assigned_counsellor_user_id' => $this->counselorBUserId,
            'linked_case_id' => $otherCaseId,
            'referral_number' => 'SYNTHETIC-OTHER-OWNER-INTAKE-' . bin2hex(random_bytes(4)),
        ]);
        self::assertGreaterThan(0, $ownedIntakeId);

        // Person-only forms must not even become candidates for this episode.
        [$definitionId, $revisionId] = $this->insertReadFormDefinition();
        $this->insertReadForm($definitionId, $revisionId, [
            'case_id' => $otherCaseId,
            'created_by_user_id' => $this->counselorBUserId,
            'instance_number' => 'SYNTHETIC-OTHER-OWNER-FORM-' . bin2hex(random_bytes(4)),
        ]);
        $auditsBefore = $this->readAuditRows();
        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertNull($details['case_id']);
        self::assertNull($details['intake_referral_id']);
        self::assertNull($details['case']);
        self::assertNull($details['counseling_case']);
        self::assertNull($details['intake_referral']);
        self::assertSame([], $details['sessions']);
        self::assertSame([], $details['counseling_sessions']);
        self::assertSame([], $details['evaluations']);
        self::assertStringNotContainsString('SYNTHETIC-OTHER-OWNER', json_encode($details, JSON_THROW_ON_ERROR));
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public function testCorrectCaseAndIntakeLinksReturnOnlyThatAuthorizedCaseAndItsSessions(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $caseId = $this->insertReadCase();
        $sessionId = $this->insertReadSession($caseId);
        $intakeId = $this->insertReadIntake(['linked_case_id' => $caseId]);
        $episodeId = $this->insertReadEpisode(['case_id' => $caseId, 'intake_referral_id' => $intakeId]);
        $otherCaseId = $this->insertReadCase([
            'counsellor_user_id' => $this->counselorBUserId,
            'title' => 'SYNTHETIC-OTHER-OWNER-CASE',
        ]);
        $this->insertReadSession($otherCaseId, ['counsellor_user_id' => $this->counselorBUserId]);
        $this->insertReadIntake(['linked_case_id' => $otherCaseId]);
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame($caseId, (int) $details['case']['id']);
        self::assertSame($details['case'], $details['counseling_case']);
        self::assertSame([$sessionId], array_map('intval', array_column($details['sessions'], 'id')));
        self::assertSame($details['sessions'], $details['counseling_sessions']);
        self::assertSame($intakeId, (int) $details['intake_referral']['id']);
        self::assertSame($caseId, (int) $details['intake_referral']['linked_case_id']);
        $this->assertReadMetadataKeys($details['intake_referral'], [
            'id', 'subject_person_id', 'linked_case_id', 'referral_number', 'source_type',
            'priority_hint', 'status', 'submitted_at', 'created_at',
        ]);
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE-INTAKE', json_encode($details, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('SYNTHETIC-OTHER-OWNER', json_encode($details, JSON_THROW_ON_ERROR));
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public static function invalidEpisodeCaseLinks(): iterable
    {
        yield 'restricted case' => ['restricted', 403];
        yield 'missing case' => ['missing', 404];
        yield 'soft-deleted case' => ['deleted', 404];
        yield 'different person case' => ['wrong_person', 403];
        yield 'foreign tenant case' => ['foreign', 404];
    }

    #[DataProvider('invalidEpisodeCaseLinks')]
    public function testExplicitInvalidCaseLinksFailBeforeCaseContentOrSessionReads(string $kind, int $code): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $caseId = 2_000_000_000;
        if ($kind !== 'missing') {
            $overrides = match ($kind) {
                'restricted' => ['counsellor_user_id' => $this->counselorBUserId],
                'wrong_person' => ['person_id' => $this->insertReadPerson()],
                'foreign' => ['tenant_id' => $this->insertReadTenant()],
                default => [],
            };
            $caseId = $this->insertReadCase($overrides);
            $this->insertReadSession($caseId, ['tenant_id' => $overrides['tenant_id'] ?? 1]);
            if ($kind === 'deleted') {
                self::assertTrue($this->caseModel->delete($caseId));
            }
        }
        $episodeId = $this->insertReadEpisode(['case_id' => $caseId]);

        $this->assertEpisodeReadRejected($episodeId, $code, $actor);
    }

    public static function invalidEpisodeIntakeLinks(): iterable
    {
        yield 'restricted intake case' => ['restricted', 403];
        yield 'missing intake' => ['missing', 404];
        yield 'deleted intake' => ['deleted', 404];
        yield 'different subject intake' => ['wrong_person', 403];
        yield 'foreign tenant intake' => ['foreign', 404];
    }

    #[DataProvider('invalidEpisodeIntakeLinks')]
    public function testExplicitInvalidIntakeLinksFailWithoutPersonBasedFallback(string $kind, int $code): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $this->insertReadIntake(); // A readable person referral is not a replacement for an invalid exact link.
        $intakeId = 2_000_000_000;
        if ($kind !== 'missing') {
            $overrides = match ($kind) {
                'restricted' => ['linked_case_id' => $this->insertReadCase([
                    'counsellor_user_id' => $this->counselorBUserId,
                ]), 'assigned_counsellor_user_id' => $this->counselorBUserId],
                'wrong_person' => ['subject_person_id' => $this->insertReadPerson()],
                'foreign' => ['tenant_id' => $this->insertReadTenant()],
                default => [],
            };
            $intakeId = $this->insertReadIntake($overrides);
        }
        $episodeId = $this->insertReadEpisode(['intake_referral_id' => $intakeId]);
        if ($kind === 'deleted') {
            // Intakes have no soft-delete column in the production schema.
            self::assertTrue($this->db->table('guidance_intake_referrals')->where('id', $intakeId)->delete());
        }

        $this->assertEpisodeReadRejected($episodeId, $code, $actor);
    }

    public function testIndependentlyReadableIntakeAndCaseCannotRepairDisagreeingEpisodeLinks(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $caseId = $this->insertReadCase();
        $otherOwnedCaseId = $this->insertReadCase();
        $intakeId = $this->insertReadIntake(['linked_case_id' => $otherOwnedCaseId]);
        $episodeId = $this->insertReadEpisode(['case_id' => $caseId, 'intake_referral_id' => $intakeId]);

        $this->assertEpisodeReadRejected($episodeId, 403, $actor);
    }

    public static function invalidIntakeCaseLinks(): iterable
    {
        yield 'missing intake case' => ['missing', 404];
        yield 'deleted intake case' => ['deleted', 404];
        yield 'different person intake case' => ['wrong_person', 404];
        yield 'foreign tenant intake case' => ['foreign', 404];
    }

    #[DataProvider('invalidIntakeCaseLinks')]
    public function testIntakeOnlyEpisodeStillValidatesItsIntakeCaseLink(string $kind, int $code): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $caseId = 2_000_000_000;
        if ($kind !== 'missing') {
            $caseId = $this->insertReadCase(match ($kind) {
                'wrong_person' => ['person_id' => $this->insertReadPerson()],
                'foreign' => ['tenant_id' => $this->insertReadTenant()],
                default => [],
            });
            if ($kind === 'deleted') {
                self::assertTrue($this->caseModel->delete($caseId));
            }
        }
        $intakeId = $this->insertReadIntake(['linked_case_id' => $caseId]);
        $episodeId = $this->insertReadEpisode(['intake_referral_id' => $intakeId]);

        $this->assertEpisodeReadRejected($episodeId, $code, $actor);
    }

    public function testReadableIntakeCaseDoesNotBecomeAnImplicitEpisodeCase(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $caseId = $this->insertReadCase();
        $this->insertReadSession($caseId);
        $intakeId = $this->insertReadIntake(['linked_case_id' => $caseId]);
        $episodeId = $this->insertReadEpisode(['intake_referral_id' => $intakeId]);
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame($intakeId, (int) $details['intake_referral']['id']);
        self::assertNull($details['case_id']);
        self::assertNull($details['case']);
        self::assertNull($details['counseling_case']);
        self::assertSame([], $details['sessions']);
        self::assertSame([], $details['counseling_sessions']);
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public static function requiredEpisodeReadRoles(): iterable
    {
        yield 'owned counsellor' => ['counsellor'];
        yield 'supervisor with read-any' => ['guidance_supervisor'];
        yield 'superadmin with read-any' => ['superadmin'];
    }

    #[DataProvider('requiredEpisodeReadRoles')]
    public function testEpisodeReadGrantIsFreshAndCannotBeReplacedByOwnershipOrReadAny(string $role): void
    {
        self::assertTrue($this->userModel->update($this->counselorAUserId, ['role' => $role]));
        $actor = $this->signInReadActor($this->counselorAUserId);
        $episodeId = $this->insertReadEpisode();
        $auditsBefore = $this->readAuditRows();
        $this->readEpisodeWithoutDomainChanges($episodeId, $actor);
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
        $this->revokeReadPermission($role, 'guidance.cases.read');
        $actor['role'] = 'superadmin';
        $actor['permissions'] = ['guidance.cases.read', 'guidance.cases.read_any'];
        session()->set('user', $actor);

        $this->assertEpisodeReadRejected($episodeId, 403, $actor);
    }

    public function testExplicitTenantCannotOverrideFreshEpisodeReadAuthority(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $episodeId = $this->insertReadEpisode();

        $this->assertEpisodeReadRejected($episodeId, 403, $actor, $this->insertReadTenant());
    }

    public function testSupervisorEpisodeAccessDoesNotBypassRestrictedCaseReadPolicy(): void
    {
        $caseId = $this->insertReadCase(['counsellor_user_id' => $this->counselorBUserId]);
        $episodeId = $this->insertReadEpisode(['case_id' => $caseId]);
        $actor = $this->signInReadActor($this->supervisorUserId);
        $this->revokeReadPermission('guidance_supervisor', 'guidance.cases.read_any');

        $this->assertEpisodeReadRejected($episodeId, 403, $actor);
    }

    public function testSupervisorLinkedCaseReadPersistsBothExactMetadataOnlyReadAudits(): void
    {
        $caseId = $this->insertReadCase(['counsellor_user_id' => $this->counselorBUserId]);
        $sessionId = $this->insertReadSession($caseId, ['counsellor_user_id' => $this->counselorBUserId]);
        $episodeId = $this->insertReadEpisode(['case_id' => $caseId]);
        $actor = $this->signInReadActor($this->supervisorUserId);
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame($caseId, (int) $details['case']['id']);
        self::assertSame([$sessionId], array_map('intval', array_column($details['sessions'], 'id')));
        $this->assertEpisodeReadEvents($episodeId, $this->supervisorUserId, $auditsBefore, $caseId);
    }

    public function testEpisodePreviewSectionsRequireTheirOwnFreshPermissions(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $intakeId = $this->insertReadIntake();
        $episodeId = $this->insertReadEpisode(['intake_referral_id' => $intakeId]);
        $appointmentId = $this->insertReadAppointment($episodeId);
        $callSlipId = $this->insertReadCallSlip($episodeId);
        $auditsBefore = $this->readAuditRows();
        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);
        self::assertSame([$appointmentId], array_map('intval', array_column($details['appointments'], 'id')));
        self::assertSame([$callSlipId], array_map('intval', array_column($details['call_slips'], 'id')));
        self::assertSame($intakeId, (int) $details['intake_referral']['id']);
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);

        foreach (['guidance.schedule.read', 'guidance.call_slips.issue', 'guidance.intake.process'] as $permission) {
            $this->revokeReadPermission('counsellor', $permission);
            // Keeping old cached claims must not restore the section, or hide separately authorized sections.
            session()->set('user', $actor);
            $auditsBefore = $this->readAuditRows();
            $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);
            self::assertSame([], $details['appointments']);
            self::assertSame($permission === 'guidance.schedule.read' ? [$callSlipId] : [],
                array_map('intval', array_column($details['call_slips'], 'id')));
            if ($permission === 'guidance.intake.process') {
                self::assertNull($details['intake_referral']);
            } else {
                self::assertSame($intakeId, (int) $details['intake_referral']['id']);
            }
            self::assertSame($episodeId, (int) $details['id']);
            $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
        }
    }

    public function testEpisodePreviewsAreExactPersonTenantLinksAndContainOnlyMetadata(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $episodeId = $this->insertReadEpisode();
        $otherEpisodeId = $this->insertReadEpisode();
        $otherPersonId = $this->insertReadPerson();
        $foreignTenantId = $this->insertReadTenant();
        $appointmentId = $this->insertReadAppointment($episodeId);
        $callSlipId = $this->insertReadCallSlip($episodeId);
        foreach ([
            ['episode_id' => $otherEpisodeId],
            ['person_id' => $otherPersonId],
            ['tenant_id' => $foreignTenantId],
        ] as $overrides) {
            $this->insertReadAppointment($episodeId, $overrides);
            $this->insertReadCallSlip($episodeId, $overrides);
        }
        $deletedAppointmentId = $this->insertReadAppointment($episodeId);
        $deletedCallSlipId = $this->insertReadCallSlip($episodeId);
        self::assertTrue($this->appointmentModel->delete($deletedAppointmentId));
        self::assertTrue($this->callSlipModel->delete($deletedCallSlipId));
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame([$appointmentId], array_map('intval', array_column($details['appointments'], 'id')));
        self::assertSame([$callSlipId], array_map('intval', array_column($details['call_slips'], 'id')));
        $this->assertReadMetadataKeys($details['appointments'][0], [
            'id', 'episode_id', 'person_id', 'counselor_user_id', 'appointment_number',
            'scheduled_date', 'scheduled_time', 'purpose', 'status', 'form_instance_id',
        ]);
        $this->assertReadMetadataKeys($details['call_slips'][0], [
            'id', 'episode_id', 'person_id', 'issuing_counselor_user_id', 'call_slip_number',
            'call_date', 'call_time', 'purpose', 'attendance_status', 'parent_guardian_delivery', 'form_instance_id',
        ]);
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE-PREVIEW', json_encode($details, JSON_THROW_ON_ERROR));
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public function testEpisodeFormsRequireExactEpisodePersonAndConsistentAuthorizedContextMetadata(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $caseId = $this->insertReadCase();
        $sessionId = $this->insertReadSession($caseId);
        $intakeId = $this->insertReadIntake(['linked_case_id' => $caseId]);
        $episodeId = $this->insertReadEpisode(['case_id' => $caseId, 'intake_referral_id' => $intakeId]);
        $otherEpisodeId = $this->insertReadEpisode(['case_id' => $caseId]);
        $otherPersonId = $this->insertReadPerson();
        $foreignTenantId = $this->insertReadTenant();
        $restrictedCaseId = $this->insertReadCase(['counsellor_user_id' => $this->counselorBUserId]);
        $otherOwnedCaseId = $this->insertReadCase();
        $otherSessionId = $this->insertReadSession($otherOwnedCaseId);
        $otherIntakeId = $this->insertReadIntake(['linked_case_id' => $otherOwnedCaseId]);
        $wrongPersonCaseId = $this->insertReadCase(['person_id' => $otherPersonId]);
        $foreignCaseId = $this->insertReadCase(['tenant_id' => $foreignTenantId]);
        $deletedCaseId = $this->insertReadCase();
        self::assertTrue($this->caseModel->delete($deletedCaseId));
        [$definitionId, $revisionId] = $this->insertReadFormDefinition();
        [$foreignDefinitionId, $foreignRevisionId] = $this->insertReadFormDefinition(['tenant_id' => $foreignTenantId]);
        [, $otherRevisionId] = $this->insertReadFormDefinition([
            'internal_key' => 'synthetic_episode_other_definition', 'form_code' => 'SYNTHETIC-OTHER-DEFINITION',
        ]);
        $context = ['episode_id' => $episodeId, 'case_id' => $caseId, 'session_id' => $sessionId, 'intake_id' => $intakeId];
        $firstId = $this->insertReadForm($definitionId, $revisionId, $context);
        // A consistent episode edge may supply the case; it must never overwrite a conflicting case edge.
        $secondId = $this->insertReadForm($definitionId, $revisionId,
            array_replace($context, ['case_id' => null, 'status' => 'draft', 'finalized_at' => null]));
        foreach ([
            ['episode_id' => null],
            ['episode_id' => $otherEpisodeId],
            ['person_id' => $otherPersonId],
            ['tenant_id' => $foreignTenantId],
            ['case_id' => $restrictedCaseId],
            ['case_id' => $deletedCaseId],
            ['case_id' => $wrongPersonCaseId],
            ['case_id' => $foreignCaseId],
            ['case_id' => 2_000_000_000],
            ['session_id' => $otherSessionId],
            ['session_id' => 2_000_000_000],
            ['intake_id' => $otherIntakeId],
            ['form_definition_id' => $foreignDefinitionId, 'definition_revision_id' => $foreignRevisionId],
            ['form_definition_id' => 2_000_000_000],
            ['definition_revision_id' => $otherRevisionId],
            ['definition_revision_id' => 2_000_000_000],
            ['created_by_user_id' => 2_000_000_000],
        ] as $overrides) {
            $this->insertReadForm($definitionId, $revisionId, array_replace($context, $overrides));
        }
        $auditsBefore = $this->readAuditRows();
        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame([$secondId, $firstId], array_map('intval', array_column($details['evaluations'], 'id')));
        foreach ($details['evaluations'] as $evaluation) {
            $this->assertReadMetadataKeys($evaluation, [
                'id', 'instance_number', 'status', 'form_definition_id', 'created_at', 'finalized_at',
            ]);
        }
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE-FORM', json_encode($details, JSON_THROW_ON_ERROR));
        self::assertNotEmpty($this->detailReadQueries, 'Read-query observation must be active.');
        foreach ($this->detailReadQueries as $query) {
            self::assertDoesNotMatchRegularExpression(
                '/\b(?:from|join)\s+[`"]?guidance_form_(?:drafts|revisions|attestations)\b/i', $query,
                'Episode form previews must not load payload ciphertext or attestations.'
            );
        }
        self::assertArrayNotHasKey('closure_notes_cipher', $details);
        self::assertArrayNotHasKey('closure_notes_cipher', $details['episode']);
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public function testUnlinkedEpisodeFormCandidatesStillRequireTheirOwnCaseOrCreatorReadAccess(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $episodeId = $this->insertReadEpisode();
        $ownedCaseId = $this->insertReadCase();
        $restrictedCaseId = $this->insertReadCase(['counsellor_user_id' => $this->counselorBUserId]);
        $restrictedIntakeId = $this->insertReadIntake(['linked_case_id' => $restrictedCaseId]);
        [$definitionId, $revisionId] = $this->insertReadFormDefinition();
        $ownCreatorId = $this->insertReadForm($definitionId, $revisionId, ['episode_id' => $episodeId]);
        $ownCaseId = $this->insertReadForm($definitionId, $revisionId, [
            'episode_id' => $episodeId, 'case_id' => $ownedCaseId, 'created_by_user_id' => $this->counselorBUserId,
        ]);
        foreach ([
            ['case_id' => $restrictedCaseId],
            ['intake_id' => $restrictedIntakeId],
            ['created_by_user_id' => $this->counselorBUserId],
            ['case_id' => 2_000_000_000],
        ] as $overrides) {
            $this->insertReadForm($definitionId, $revisionId, ['episode_id' => $episodeId] + $overrides);
        }
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame([$ownCaseId, $ownCreatorId], array_map('intval', array_column($details['evaluations'], 'id')));
        self::assertNull($details['case']);
        self::assertNull($details['intake_referral']);
        self::assertSame([], $details['sessions']);
        foreach ($details['evaluations'] as $evaluation) {
            $this->assertReadMetadataKeys($evaluation, [
                'id', 'instance_number', 'status', 'form_definition_id', 'created_at', 'finalized_at',
            ]);
        }
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public function testAppointmentAndCallSlipPreviewsValidateTheirSourceFormContextBeforeListing(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $episodeId = $this->insertReadEpisode();
        $otherEpisodeId = $this->insertReadEpisode();
        $otherPersonId = $this->insertReadPerson();
        $foreignTenantId = $this->insertReadTenant();
        $restrictedCaseId = $this->insertReadCase(['counsellor_user_id' => $this->counselorBUserId]);
        [$definitionId, $revisionId] = $this->insertReadFormDefinition();
        $authorizedFormId = $this->insertReadForm($definitionId, $revisionId, ['episode_id' => $episodeId]);
        $noFormAppointmentId = $this->insertReadAppointment($episodeId);
        $noFormCallSlipId = $this->insertReadCallSlip($episodeId);
        $authorizedAppointmentId = $this->insertReadAppointment($episodeId, ['form_instance_id' => $authorizedFormId]);
        $authorizedCallSlipId = $this->insertReadCallSlip($episodeId, ['form_instance_id' => $authorizedFormId]);
        foreach ([
            ['case_id' => $restrictedCaseId],
            ['person_id' => $otherPersonId],
            ['episode_id' => $otherEpisodeId],
            ['tenant_id' => $foreignTenantId],
            ['definition_revision_id' => 2_000_000_000],
        ] as $overrides) {
            $formId = $this->insertReadForm($definitionId, $revisionId, array_replace(['episode_id' => $episodeId], $overrides));
            $this->insertReadAppointment($episodeId, ['form_instance_id' => $formId]);
            $this->insertReadCallSlip($episodeId, ['form_instance_id' => $formId]);
        }
        $this->insertReadAppointment($episodeId, ['form_instance_id' => 2_000_000_000]);
        $this->insertReadCallSlip($episodeId, ['form_instance_id' => 2_000_000_000]);
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame([$noFormAppointmentId, $authorizedAppointmentId],
            array_map('intval', array_column($details['appointments'], 'id')));
        self::assertSame([$noFormCallSlipId, $authorizedCallSlipId],
            array_map('intval', array_column($details['call_slips'], 'id')));
        self::assertSame([$authorizedFormId], array_map('intval', array_column($details['evaluations'], 'id')));
        self::assertStringNotContainsString('SYNTHETIC-PRIVATE', json_encode($details, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('closure_notes_cipher', $details);
        self::assertArrayNotHasKey('closure_notes_cipher', $details['episode']);
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public function testClosurePlaintextIsAuditedAndCipherPackageIsAbsentFromBothEpisodeViews(): void
    {
        $actor = $this->signInReadActor($this->counselorAUserId);
        $encrypted = $this->encryptionService->encrypt('Synthetic confidential episode closure');
        $cipherPayload = json_encode([
            'cipher' => base64_encode($encrypted['cipher']),
            'nonce' => base64_encode($encrypted['nonce']),
            'tag' => base64_encode($encrypted['tag']),
            'key_version' => $encrypted['key_version'],
        ], JSON_THROW_ON_ERROR);
        $episodeId = $this->insertReadEpisode([
            'status' => 'closed',
            'closed_at' => '2026-02-01 09:00:00',
            'closure_reason' => 'Synthetic completion',
            'closure_notes_cipher' => $cipherPayload,
        ]);
        $auditsBefore = $this->readAuditRows();

        $details = $this->readEpisodeWithoutDomainChanges($episodeId, $actor);

        self::assertSame('Synthetic confidential episode closure', $details['closure_notes']);
        self::assertSame('Synthetic completion', $details['closure_reason']);
        self::assertSame('Synthetic completion', $details['episode']['closure_reason']);
        self::assertArrayNotHasKey('closure_notes_cipher', $details);
        self::assertArrayNotHasKey('closure_notes_cipher', $details['episode']);
        self::assertStringNotContainsString($cipherPayload, json_encode($details, JSON_THROW_ON_ERROR));
        self::assertSame($cipherPayload, $this->episodeModel->find($episodeId)['closure_notes_cipher']);
        $this->assertEpisodeReadEvents($episodeId, $this->counselorAUserId, $auditsBefore);
    }

    public function testSupervisorCanReadAnyEpisode(): void
    {
        $actorA = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actorA,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // Supervisor can read
        $supActor = [
            'id' => $this->supervisorUserId,
            'tenant_id' => 1,
            'role' => 'guidance_supervisor',
        ];

        $details = $this->episodeService->getEpisodeDetails($episodeId, 1, $supActor);
        self::assertNotNull($details);
        self::assertSame($episodeId, (int) $details['id']);
    }

    public function testCloseEpisodeSuccessWithEncryptedNotes(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $confidentialNotes = 'Student has successfully improved academic focus and resolved anxiety symptoms.';
        $closed = $this->episodeService->closeEpisode(
            $episodeId,
            'Counseling completed satisfactorily',
            $confidentialNotes,
            1,
            $actor
        );

        self::assertSame('closed', $closed['status']);
        self::assertNotEmpty($closed['closed_at']);
        self::assertSame('Counseling completed satisfactorily', $closed['closure_reason']);
        self::assertNotEmpty($closed['closure_notes_cipher']);

        // Verify decrypted notes
        $decrypted = $this->episodeService->getDecryptedClosureNotes($closed);
        self::assertSame($confidentialNotes, $decrypted);

        // Verify decrypted notes via episode ID
        $decryptedById = $this->episodeService->getDecryptedClosureNotes($episodeId, $actor, 1);
        self::assertSame($confidentialNotes, $decryptedById);
    }

    public function testCloseEpisodeFailsForUnauthorizedActor(): void
    {
        $actorA = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actorA,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $actorB = [
            'id' => $this->counselorBUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->closeEpisode(
            $episodeId,
            'Unauthorized attempt',
            null,
            1,
            $actorB
        );
    }

    public function testCloseEpisodeFailsWithoutReason(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        $this->episodeService->closeEpisode(
            $episodeId,
            '   ',
            null,
            1,
            $actor
        );
    }

    public function testCloseAlreadyClosedEpisodeFails(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->episodeService->closeEpisode($episodeId, 'Completed', null, 1, $actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(409);

        $this->episodeService->closeEpisode($episodeId, 'Trying to close again', null, 1, $actor);
    }

    public function testTransferEpisodeSuccess(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        // Create episode with linked case
        $caseId = (int) $this->caseModel->insert([
            'tenant_id'          => 1,
            'person_id'          => $this->studentPersonId,
            'counsellor_user_id' => $this->counselorAUserId,
            'case_number'        => 'CAS-' . bin2hex(random_bytes(4)),
            'title'              => 'Transferrable Case',
            'category'           => 'personal',
            'status'             => 'open',
            'opened_at'          => date('Y-m-d H:i:s'),
        ]);

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor'                     => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
                'case_id'                   => $caseId,
            ]
        );
        $episodeId = (int) $episode['id'];

        // Transfer episode to Counselor B
        $transferred = $this->episodeService->transferEpisode(
            $episodeId,
            $this->counselorBUserId,
            'Caseload rebalancing and specialization referral',
            1,
            $actor
        );

        self::assertSame('active', $transferred['status']);
        self::assertSame($this->counselorBUserId, (int) $transferred['primary_counselor_user_id']);

        // Verify linked case was updated by GuidanceTransferService
        $updatedCase = $this->caseModel->find($caseId);
        self::assertSame($this->counselorBUserId, (int) $updatedCase['counsellor_user_id']);
    }

    public function testTransferEpisodeFailsToUnqualifiedCounselor(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(403);

        $this->episodeService->transferEpisode(
            $episodeId,
            $this->unqualifiedUserId,
            'Invalid transfer target',
            1,
            $actor
        );
    }

    public function testTransferEpisodeFailsToSameCounselor(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionCode(422);

        $this->episodeService->transferEpisode(
            $episodeId,
            $this->counselorAUserId,
            'Transferring to self',
            1,
            $actor
        );
    }

    public function testTransferClosedEpisodeFails(): void
    {
        $actor = [
            'id' => $this->counselorAUserId,
            'tenant_id' => 1,
            'role' => 'counsellor',
        ];

        $episode = $this->episodeService->openEpisode(
            $this->studentPersonId,
            [
                'actor' => $actor,
                'primary_counselor_user_id' => $this->counselorAUserId,
            ]
        );
        $episodeId = (int) $episode['id'];

        $this->episodeService->closeEpisode($episodeId, 'Completed', null, 1, $actor);

        $this->expectException(DomainException::class);
        $this->expectExceptionCode(409);

        $this->episodeService->transferEpisode(
            $episodeId,
            $this->counselorBUserId,
            'Attempt transfer of closed episode',
            1,
            $actor
        );
    }
}
