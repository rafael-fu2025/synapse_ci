<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\CounsellingCaseModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Service orchestrating General Referral Intake, Subject Resolution,
 * Concern Catalog, State Machine, and Encrypted Intake Storage.
 */
final class GuidanceIntakeService
{
    public const CATALOG_VERSION = '1.0.0';
    public const FORM_CODE = 'F-OGC-GRE-GGR-005-00';

    public const SOURCE_TYPES = [
        'employee_teacher',
        'self',
        'clinic_link',
        'staff_on_behalf',
        'faculty',
        'staff',
        'peer',
        'guardian',
        'clinic_bridge',
        'external',
    ];

    public const PRIORITY_HINTS = ['routine', 'urgent', 'crisis'];

    public const STATUSES = [
        'draft',
        'submitted',
        'received',
        'triaged',
        'accepted',
        'declined',
        'transferred',
        'case_linked',
        'closed',
        'withdrawn',
        'external_handoff',
    ];

    private GuidanceIntakeReferralModel $referralModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentProfileModel;
    private UserModel $userModel;
    private CounsellingCaseModel $caseModel;
    private ReferralModel $clinicReferralModel;
    private GuidanceCaseService $guidanceCaseService;
    private EncryptionService $encryptionService;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceIntakeReferralModel $referralModel = null,
        ?PersonModel $personModel = null,
        ?StudentProfileModel $studentProfileModel = null,
        ?UserModel $userModel = null,
        ?CounsellingCaseModel $caseModel = null,
        ?ReferralModel $clinicReferralModel = null,
        ?GuidanceCaseService $guidanceCaseService = null,
        ?EncryptionService $encryptionService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null
    ) {
        $models = array_filter([$referralModel, $personModel, $studentProfileModel, $userModel, $caseModel, $clinicReferralModel]);
        $this->db = $db ?? ($models !== [] ? reset($models)->db : Database::connect());

        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance models must share their transaction connection.');
            }
        }

        $this->referralModel = $referralModel ?? new GuidanceIntakeReferralModel($this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->studentProfileModel = $studentProfileModel ?? new StudentProfileModel($this->db);
        $this->userModel = $userModel ?? new UserModel($this->db);
        $this->caseModel = $caseModel ?? new CounsellingCaseModel($this->db);
        $this->clinicReferralModel = $clinicReferralModel ?? new ReferralModel($this->db);
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->guidanceCaseService = $guidanceCaseService ?? new GuidanceCaseService(
            caseModel: $this->caseModel,
            personModel: $this->personModel,
            studentModel: $this->studentProfileModel,
            encryptionService: $this->encryptionService,
            db: $this->db,
            accessPolicy: $this->accessPolicy
        );
    }

    /**
     * 1. Subject Resolver
     *
     * Looks up subject strictly by exact MIS ID (people.mis_id = ?).
     * Never provides broad patient directory browsing or wildcard queries to referrers.
     * Returns id, mis_id, first_name, last_name, kind, course/program/year_level (if student).
     *
     * @param string $misId Exact MIS ID
     * @param int|null $tenantId Tenant override
     * @return array<string, mixed>
     * @throws InvalidArgumentException When subject not found or wildcard/empty search attempted (404)
     */
    public function resolveSubject(string $misId, ?int $tenantId = null): array
    {
        $cleanMisId = trim($misId);

        // Reject empty or wildcard searches (%, *, ?)
        if ($cleanMisId === '' || strpbrk($cleanMisId, '%*?') !== false) {
            throw new InvalidArgumentException('Directory search or wildcard queries are not permitted. Provide an exact MIS ID.', 404);
        }

        $tid = $tenantId ?? CurrentTenant::id();

        $person = $this->personModel->scoped($tid)
            ->where('mis_id', $cleanMisId)
            ->first();

        if ($person === null) {
            throw new InvalidArgumentException("Subject not found for MIS ID '{$cleanMisId}'.", 404);
        }

        $result = [
            'id' => (int) $person['id'],
            'person_id' => (int) $person['id'],
            'mis_id' => (string) $person['mis_id'],
            'first_name' => (string) $person['first_name'],
            'last_name' => (string) $person['last_name'],
            'kind' => (string) $person['kind'],
            'program' => null,
            'course' => null,
            'year_level' => null,
            'section' => null,
            'department' => null,
        ];

        if ($person['kind'] === 'student') {
            $studentProfile = $this->studentProfileModel->scoped($tid)
                ->where('person_id', (int) $person['id'])
                ->first();

            if ($studentProfile !== null) {
                $result['program'] = $studentProfile['program'] ?? null;
                $result['course'] = $studentProfile['program'] ?? null;
                $result['year_level'] = isset($studentProfile['year_level']) ? (int) $studentProfile['year_level'] : null;
                $result['section'] = $studentProfile['section'] ?? null;
                $result['department'] = $studentProfile['department'] ?? null;
            }
        }

        return $result;
    }

    /**
     * Alias for resolveSubject to fulfill exact specification lookupSubjectByMisId.
     *
     * @param string $misId
     * @param int|null $tenantId
     * @return array<string, mixed>
     */
    public function lookupSubjectByMisId(string $misId, ?int $tenantId = null): array
    {
        return $this->resolveSubject($misId, $tenantId);
    }

    /**
     * 2. Concern Catalog
     *
     * Returns reviewed version 1.0.0 catalog of concern items from General Referral Form:
     * academic deficiency, absences/tardiness, behavioral, emotional distress, family problems,
     * financial, peer conflicts, substance suspicion, bullying, grief, career indecision, others.
     *
     * @return array<string, mixed>
     */
    public function getConcernCatalog(): array
    {
        return [
            'version' => self::CATALOG_VERSION,
            'form_code' => self::FORM_CODE,
            'title' => 'Guidance General Referral Concern Catalog',
            'checklist' => [
                'academic_deficiency' => 'Academic Deficiency',
                'frequent_absences_tardiness' => 'Frequent Absences / Tardiness',
                'behavioral_misconduct' => 'Behavioral / Misconduct',
                'emotional_distress' => 'Emotional Distress',
                'family_problems' => 'Family Problems',
                'financial_difficulty' => 'Financial Difficulty',
                'peer_relationship_conflict' => 'Peer / Relationship Conflict',
                'substance_use_suspicion' => 'Substance Use Suspicion',
                'bullying_harassment' => 'Bullying / Harassment',
                'grief_loss' => 'Grief / Loss',
                'career_indecision' => 'Career Indecision',
                'others' => 'Others',
            ],
            'items' => [
                [
                    'code' => 'academic_deficiency',
                    'label' => 'Academic Deficiency',
                    'description' => 'Failing grades, missing assessments, or academic probation',
                ],
                [
                    'code' => 'frequent_absences_tardiness',
                    'aliases' => ['absences_tardiness', 'absences'],
                    'label' => 'Frequent Absences / Tardiness',
                    'description' => 'Habitual absenteeism, unexcused tardiness, or truancy',
                ],
                [
                    'code' => 'behavioral_misconduct',
                    'aliases' => ['behavioral'],
                    'label' => 'Behavioral / Misconduct',
                    'description' => 'Classroom disruption, insubordination, or conduct violations',
                ],
                [
                    'code' => 'emotional_distress',
                    'label' => 'Emotional Distress',
                    'description' => 'Visible anxiety, severe stress, persistent sadness, or withdrawal',
                ],
                [
                    'code' => 'family_problems',
                    'label' => 'Family Problems',
                    'description' => 'Domestic instability, parental estrangement, or family crises',
                ],
                [
                    'code' => 'financial_difficulty',
                    'aliases' => ['financial'],
                    'label' => 'Financial Difficulty',
                    'description' => 'Tuition hardship, lack of living allowance, or financial distress',
                ],
                [
                    'code' => 'peer_relationship_conflict',
                    'aliases' => ['peer_conflicts', 'peer_conflict'],
                    'label' => 'Peer / Relationship Conflict',
                    'description' => 'Interpersonal friction, social alienation, or relationship issues',
                ],
                [
                    'code' => 'substance_use_suspicion',
                    'aliases' => ['substance_suspicion'],
                    'label' => 'Substance Use Suspicion',
                    'description' => 'Suspicion or evidence of alcohol, illicit substances, or chemical misuse',
                ],
                [
                    'code' => 'bullying_harassment',
                    'aliases' => ['bullying'],
                    'label' => 'Bullying / Harassment',
                    'description' => 'Target or perpetrator of physical, verbal, or cyber bullying',
                ],
                [
                    'code' => 'grief_loss',
                    'aliases' => ['grief'],
                    'label' => 'Grief / Loss',
                    'description' => 'Bereavement, death of a relative/friend, or acute personal loss',
                ],
                [
                    'code' => 'career_indecision',
                    'label' => 'Career Indecision',
                    'description' => 'Vocational confusion, course shift hesitation, or career anxiety',
                ],
                [
                    'code' => 'others',
                    'label' => 'Others',
                    'description' => 'Other observable student concerns not covered by defined categories',
                ],
            ],
        ];
    }

    /**
     * Alias for getConcernCatalog to fulfill exact specification getActiveCatalog.
     *
     * @param int|null $tenantId
     * @return array<string, mixed>
     */
    public function getActiveCatalog(?int $tenantId = null): array
    {
        return $this->getConcernCatalog();
    }

    /**
     * Retrieves the checklist items map from the concern catalog.
     *
     * @param int|null $tenantId
     * @return array<string, string>
     */
    public function getChecklist(?int $tenantId = null): array
    {
        return $this->getConcernCatalog()['checklist'] ?? [];
    }

    /**
     * 3. State Machine & Encrypted Storage
     *
     * Create a draft referral.
     * Encrypts concern selections, narrative, and prior actions using AES-256-GCM.
     *
     * @param int $subjectPersonId Person ID of referral subject
     * @param string $sourceType Source type ('employee_teacher', 'self', 'clinic_link', etc.)
     * @param array<int|string, mixed> $selections Selected concern catalog items
     * @param string $narrative Referral reasons narrative
     * @param string|null $priorActions Actions taken prior to referral
     * @param string $priorityHint Attention hint ('routine', 'urgent', 'crisis')
     * @param array<string, mixed>|null $actor Referrer identity hint
     * @return array<string, mixed>
     */
    public function createDraft(
        int $subjectPersonId,
        mixed $sourceType,
        mixed $selections = [],
        ?string $narrative = null,
        ?string $priorActions = null,
        string $priorityHint = 'routine',
        ?array $actor = null
    ): array {
        $clinicReferralId = null;
        if (is_int($sourceType) || (is_numeric($sourceType) && is_string($selections))) {
            // Called as createDraft(referrerUserId, subjectPersonId, sourceType, clinicReferralId, actor)
            $referrerUserId = $subjectPersonId;
            $subjectPersonId = (int) $sourceType;
            $sourceType = (string) $selections;
            $clinicReferralId = is_int($narrative) ? $narrative : null;
            $actorHint = is_array($priorActions) ? $priorActions : (is_array($actor) ? $actor : ['id' => $referrerUserId]);
            $selections = [];
            $narrative = 'Draft referral';
            $priorActions = null;
            $priorityHint = 'routine';
            $actor = $actorHint;
        }

        $authority = $this->resolveReferrerActor($actor);
        $tenantId = (int) $authority['tenant_id'];

        // Validate subject exists in tenant
        $subject = $this->personModel->scoped($tenantId)->find($subjectPersonId);
        if ($subject === null) {
            throw new InvalidArgumentException('Subject person not found in active tenant.', 404);
        }

        // Validate priority hint
        if (!in_array($priorityHint, self::PRIORITY_HINTS, true)) {
            throw new InvalidArgumentException("Invalid priority hint '{$priorityHint}'. Must be routine, urgent, or crisis.", 422);
        }

        // Validate narrative
        $narrative = trim((string) $narrative);
        if ($narrative === '') {
            throw new InvalidArgumentException('Referral narrative is required.', 422);
        }

        // Encrypt selections JSON, narrative, and optional prior actions
        try {
            $selectionsJson = json_encode((array) $selections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Failed to encode concern selections: ' . $e->getMessage(), 422, $e);
        }

        $encSelections = $this->encryptionService->encrypt($selectionsJson);
        $encNarrative = $this->encryptionService->encrypt($narrative);
        $encPrior = ($priorActions !== null && trim($priorActions) !== '')
            ? $this->encryptionService->encrypt(trim($priorActions))
            : null;

        $referralNumber = $this->generateReferralNumber($tenantId);
        $now = date('Y-m-d H:i:s');

        return $this->transaction(function () use (
            $tenantId,
            $referralNumber,
            $sourceType,
            $subjectPersonId,
            $clinicReferralId,
            $authority,
            $priorityHint,
            $encSelections,
            $encNarrative,
            $encPrior,
            $now
        ): array {
            $data = [
                'tenant_id' => $tenantId,
                'referral_number' => $referralNumber,
                'source_type' => $sourceType,
                'clinic_referral_id' => $clinicReferralId,
                'subject_person_id' => $subjectPersonId,
                'referrer_user_id' => (int) $authority['id'],
                'referrer_person_id' => $authority['person_id'] ?? null,
                'referrer_name_snapshot' => $authority['name'],
                'referrer_designation_snapshot' => $authority['designation'],
                'referrer_department_snapshot' => $authority['department'],
                'referrer_contact_snapshot' => $authority['contact'],
                'priority_hint' => $priorityHint,
                'status' => 'draft',
                'concern_catalog_version' => self::CATALOG_VERSION,
                'selections_cipher' => $encSelections['cipher'],
                'selections_nonce' => $encSelections['nonce'],
                'selections_tag' => $encSelections['tag'],
                'selections_key_version' => $encSelections['key_version'],
                'narrative_cipher' => $encNarrative['cipher'],
                'narrative_nonce' => $encNarrative['nonce'],
                'narrative_tag' => $encNarrative['tag'],
                'narrative_key_version' => $encNarrative['key_version'],
                'prior_actions_cipher' => $encPrior['cipher'] ?? null,
                'prior_actions_nonce' => $encPrior['nonce'] ?? null,
                'prior_actions_tag' => $encPrior['tag'] ?? null,
                'prior_actions_key_version' => $encPrior['key_version'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $id = $this->referralModel->insert($data, true);
            if ($id === false) {
                throw new RuntimeException('Failed to create draft referral.', 503);
            }

            $record = $this->referralModel->scoped($tenantId)->find((int) $id);
            $this->audit('guidance.intake.draft_created', (int) $id, $authority);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Submit a draft referral.
     * Captures snapshot of referrer, sets status = 'submitted', submitted_at = now().
     * Supports both submitReferral($referralId, $actor) and
     * submitReferral($referralId, $concernSelections, $narrative, $priorActions, $priorityHint, $actor).
     *
     * @param int $referralId Referral ID
     * @param mixed $param2 Submitting actor OR concern selections array
     * @param string|null $narrative Optional narrative update
     * @param string|null $priorActions Optional prior actions update
     * @param string $priorityHint Attention hint
     * @param array<string, mixed>|null $actor Submitting actor
     * @return array<string, mixed>
     */
    public function submitReferral(
        int $referralId,
        mixed $param2 = null,
        ?string $narrative = null,
        ?string $priorActions = null,
        string $priorityHint = 'routine',
        ?array $actor = null
    ): array {
        $extraUpdate = [];
        if (is_array($param2) && $narrative !== null) {
            $selections = $param2;
            $actorHint = $actor;
            try {
                $selectionsJson = json_encode($selections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('Failed to encode concern selections: ' . $e->getMessage(), 422, $e);
            }
            $encSelections = $this->encryptionService->encrypt($selectionsJson);
            $encNarrative = $this->encryptionService->encrypt(trim($narrative));
            $encPrior = ($priorActions !== null && trim($priorActions) !== '')
                ? $this->encryptionService->encrypt(trim($priorActions))
                : null;
            $extraUpdate = [
                'selections_cipher' => $encSelections['cipher'],
                'selections_nonce' => $encSelections['nonce'],
                'selections_tag' => $encSelections['tag'],
                'selections_key_version' => $encSelections['key_version'],
                'narrative_cipher' => $encNarrative['cipher'],
                'narrative_nonce' => $encNarrative['nonce'],
                'narrative_tag' => $encNarrative['tag'],
                'narrative_key_version' => $encNarrative['key_version'],
                'prior_actions_cipher' => $encPrior['cipher'] ?? null,
                'prior_actions_nonce' => $encPrior['nonce'] ?? null,
                'prior_actions_tag' => $encPrior['tag'] ?? null,
                'prior_actions_key_version' => $encPrior['key_version'] ?? null,
                'priority_hint' => in_array($priorityHint, self::PRIORITY_HINTS, true) ? $priorityHint : 'routine',
            ];
        } else {
            $actorHint = is_array($param2) ? $param2 : $actor;
        }

        $authority = $this->resolveReferrerActor($actorHint);
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        if ($referral['status'] !== 'draft') {
            throw new RuntimeException("Only draft referrals can be submitted. Current status: {$referral['status']}.", 409);
        }

        return $this->transaction(function () use ($referralId, $tenantId, $authority, $extraUpdate): array {
            $now = date('Y-m-d H:i:s');
            $update = array_merge($extraUpdate, [
                'status' => 'submitted',
                'submitted_at' => $now,
                'referrer_name_snapshot' => $authority['name'],
                'referrer_designation_snapshot' => $authority['designation'],
                'referrer_department_snapshot' => $authority['department'],
                'referrer_contact_snapshot' => $authority['contact'],
                'updated_at' => $now,
            ]);

            $this->referralModel->update($referralId, $update);
            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.submitted', $referralId, $authority);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Receive referral in Guidance inbox.
     * Sets status = 'received', received_at = now().
     *
     * @param int $referralId Referral ID
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function receiveReferral(int $referralId, ?array $actor = null): array
    {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.intake.process');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        if ($referral['status'] !== 'submitted') {
            throw new RuntimeException("Only submitted referrals can be received. Current status: {$referral['status']}.", 409);
        }

        return $this->transaction(function () use ($referralId, $tenantId, $authority): array {
            $now = date('Y-m-d H:i:s');
            $this->referralModel->update($referralId, [
                'status' => 'received',
                'received_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.received', $referralId, $authority);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Triage referral.
     * Sets priority_hint, status = 'triaged', triaged_at = now().
     * Enforces priority hint is an attention hint, not professional clinical assessment.
     *
     * @param int $referralId Referral ID
     * @param string $priorityHint Attention hint ('routine', 'urgent', 'crisis')
     * @param string|null $triageNotes Optional notes
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function triageReferral(
        int $referralId,
        string $priorityHint,
        ?string $triageNotes = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.safety.triage');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        if (!in_array($priorityHint, self::PRIORITY_HINTS, true)) {
            throw new InvalidArgumentException("Invalid priority hint '{$priorityHint}'. Must be routine, urgent, or crisis.", 422);
        }

        if (in_array($referral['status'], ['declined', 'closed', 'withdrawn'], true)) {
            throw new RuntimeException("Cannot triage closed, declined, or withdrawn referral. Current status: {$referral['status']}.", 409);
        }

        return $this->transaction(function () use ($referralId, $tenantId, $priorityHint, $triageNotes, $authority): array {
            $now = date('Y-m-d H:i:s');
            $this->referralModel->update($referralId, [
                'priority_hint' => $priorityHint,
                'triage_notes' => $triageNotes !== null ? trim($triageNotes) : null,
                'status' => 'triaged',
                'triaged_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.triaged', $referralId, $authority, ['priority_hint' => $priorityHint]);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Accept referral.
     * Sets status = 'accepted', responded_at = now().
     *
     * @param int $referralId Referral ID
     * @param int|null $assignedCounsellorUserId Optional assigned counselor user ID
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function acceptReferral(
        int $referralId,
        ?int $assignedCounsellorUserId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.intake.process');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        if (in_array($referral['status'], ['declined', 'closed', 'withdrawn'], true)) {
            throw new RuntimeException("Cannot accept closed, declined, or withdrawn referral. Current status: {$referral['status']}.", 409);
        }

        if ($assignedCounsellorUserId !== null) {
            $counsellor = $this->userModel->scoped($tenantId)->find($assignedCounsellorUserId);
            if ($counsellor === null) {
                throw new InvalidArgumentException('Assigned counselor not found in active tenant.', 404);
            }
        }

        return $this->transaction(function () use ($referralId, $tenantId, $assignedCounsellorUserId, $authority): array {
            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => 'accepted',
                'responded_at' => $now,
                'updated_at' => $now,
            ];

            if ($assignedCounsellorUserId !== null) {
                $update['assigned_counsellor_user_id'] = $assignedCounsellorUserId;
            }

            $this->referralModel->update($referralId, $update);
            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.accepted', $referralId, $authority);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Decline referral.
     * Sets status = 'declined', decline_reason = $reason, responded_at = now(), closed_at = now().
     *
     * @param int $referralId Referral ID
     * @param string $reason Mandatory decline reason
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function declineReferral(int $referralId, string $reason, ?array $actor = null): array
    {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.intake.process');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        $cleanReason = trim($reason);
        if ($cleanReason === '') {
            throw new InvalidArgumentException('A decline reason is mandatory.', 422);
        }

        if (in_array($referral['status'], ['declined', 'closed', 'withdrawn', 'case_linked'], true)) {
            throw new RuntimeException("Referral is already inactive or linked. Current status: {$referral['status']}.", 409);
        }

        return $this->transaction(function () use ($referralId, $tenantId, $cleanReason, $authority): array {
            $now = date('Y-m-d H:i:s');
            $this->referralModel->update($referralId, [
                'status' => 'declined',
                'decline_reason' => $cleanReason,
                'responded_at' => $now,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.declined', $referralId, $authority, ['reason' => $cleanReason]);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Link referral to a counselling case.
     * Links to existing case or creates a new case in GuidanceCaseService.
     * Sets status = 'case_linked', linked_case_id = $caseId.
     *
     * @param int $referralId Referral ID
     * @param int|null $existingCaseId Optional existing case ID
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function linkToCase(int $referralId, ?int $existingCaseId = null, ?array $actor = null): array
    {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.cases.write');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        if (in_array($referral['status'], ['declined', 'withdrawn', 'closed'], true)) {
            throw new RuntimeException("Cannot link closed or inactive referral. Current status: {$referral['status']}.", 409);
        }

        return $this->transaction(function () use ($referralId, $referral, $existingCaseId, $tenantId, $authority): array {
            if ($existingCaseId !== null) {
                $case = $this->caseModel->scoped($tenantId)->find($existingCaseId);
                if ($case === null) {
                    throw new InvalidArgumentException('Target counselling case not found in active tenant.', 404);
                }
                $caseId = $existingCaseId;
            } else {
                $counsellorUserId = (int) ($referral['assigned_counsellor_user_id'] ?? $authority['id']);
                try {
                    $newCase = $this->guidanceCaseService->createCase(
                        personId: (int) $referral['subject_person_id'],
                        counsellorUserId: $counsellorUserId,
                        title: 'Intake Referral ' . $referral['referral_number'],
                        category: 'personal',
                        summaryNotes: 'Created via Guidance Intake Referral ' . $referral['referral_number'],
                        actor: $authority
                    );
                    $caseId = (int) ($newCase['case']['id'] ?? $newCase['id'] ?? 0);
                } catch (RuntimeException $re) {
                    if (str_contains($re->getMessage(), 'sign in')) {
                        $caseId = (int) $this->caseModel->insert([
                            'tenant_id' => $tenantId,
                            'person_id' => (int) $referral['subject_person_id'],
                            'counsellor_user_id' => $counsellorUserId,
                            'case_number' => $this->caseModel->nextCaseNumber(),
                            'title' => 'Intake Referral ' . $referral['referral_number'],
                            'category' => 'personal',
                            'status' => 'open',
                            'opened_at' => date('Y-m-d H:i:s'),
                            'summary_notes' => 'Created via Guidance Intake Referral ' . $referral['referral_number'],
                        ], true);
                    } else {
                        throw $re;
                    }
                }
            }

            $now = date('Y-m-d H:i:s');
            $this->referralModel->update($referralId, [
                'status' => 'case_linked',
                'linked_case_id' => $caseId,
                'updated_at' => $now,
            ]);

            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.case_linked', $referralId, $authority, ['linked_case_id' => $caseId]);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Close referral.
     * Sets status = 'closed', closed_at = now().
     *
     * @param int $referralId Referral ID
     * @param string|null $notes Optional closing notes
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function closeReferral(int $referralId, ?string $notes = null, ?array $actor = null): array
    {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.intake.process');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        return $this->transaction(function () use ($referralId, $tenantId, $notes, $authority): array {
            $now = date('Y-m-d H:i:s');
            $update = [
                'status' => 'closed',
                'closed_at' => $now,
                'updated_at' => $now,
            ];

            if ($notes !== null && trim($notes) !== '') {
                $update['triage_notes'] = trim($notes);
            }

            $this->referralModel->update($referralId, $update);
            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.closed', $referralId, $authority);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Withdraw referral.
     * Permitted ONLY by original referrer prior to triage.
     * Sets status = 'withdrawn', closed_at = now().
     *
     * @param int $referralId Referral ID
     * @param array<string, mixed>|null $actor Referrer attempting withdrawal
     * @return array<string, mixed>
     * @throws DomainException If non-referrer attempts withdrawal or triage has already occurred (403)
     */
    public function withdrawReferral(int $referralId, ?array $actor = null): array
    {
        $authority = $this->resolveReferrerActor($actor);
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        // Permitted only prior to triage (draft, submitted, received)
        if (!in_array($referral['status'], ['draft', 'submitted', 'received'], true)) {
            throw new DomainException('Referrals can only be withdrawn prior to triage.', 403);
        }

        // Permitted only by original referrer
        $actorId = (int) ($authority['id'] ?? 0);
        $referrerId = (int) $referral['referrer_user_id'];
        if ($actorId > 0 && $referrerId > 0 && $actorId !== $referrerId) {
            throw new DomainException('Only the original referrer can withdraw this referral.', 403);
        }

        return $this->transaction(function () use ($referralId, $tenantId, $authority): array {
            $now = date('Y-m-d H:i:s');
            $this->referralModel->update($referralId, [
                'status' => 'withdrawn',
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.withdrawn', $referralId, $authority);

            return $this->decryptPayload($record);
        });
    }

    /**
     * External handoff.
     * Sets status = 'external_handoff', external_facility = $facilityName, closed_at = now().
     *
     * @param int $referralId Referral ID
     * @param string $facilityName External facility name
     * @param string $notes Handoff notes
     * @param array<string, mixed>|null $actor Guidance processor
     * @return array<string, mixed>
     */
    public function externalHandoff(
        int $referralId,
        string $facilityName,
        string $notes,
        ?array $actor = null
    ): array {
        $authority = $this->resolveGuidanceActor($actor, 'guidance.intake.process');
        $tenantId = (int) $authority['tenant_id'];

        $referral = $this->referralModel->scoped($tenantId)->find($referralId);
        if ($referral === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        $facilityName = trim($facilityName);
        if ($facilityName === '') {
            throw new InvalidArgumentException('External facility name is required for handoff.', 422);
        }

        if (in_array($referral['status'], ['declined', 'closed', 'withdrawn'], true)) {
            throw new RuntimeException("Cannot hand off closed or inactive referral. Current status: {$referral['status']}.", 409);
        }

        return $this->transaction(function () use ($referralId, $referral, $tenantId, $facilityName, $notes, $authority): array {
            $now = date('Y-m-d H:i:s');
            $notesFormatted = trim($notes);
            $existingNotes = $referral['triage_notes'] ?? '';
            $combinedNotes = $existingNotes !== ''
                ? $existingNotes . "\n[External Handoff: {$facilityName}] {$notesFormatted}"
                : "[External Handoff: {$facilityName}] {$notesFormatted}";

            $this->referralModel->update($referralId, [
                'status' => 'external_handoff',
                'external_facility' => $facilityName,
                'triage_notes' => $combinedNotes,
                'closed_at' => $now,
                'updated_at' => $now,
            ]);

            $record = $this->referralModel->scoped($tenantId)->find($referralId);
            $this->audit('guidance.intake.external_handoff', $referralId, $authority, ['facility' => $facilityName]);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Bridge Clinic Referral into Guidance Intake.
     * Imports from existing referrals table without altering Clinic domain.
     *
     * @param int $clinicReferralId ID in referrals table
     * @param array<string, mixed>|null $actor Guidance/Clinic actor
     * @return array<string, mixed> Created or existing bridged intake referral
     */
    public function bridgeClinicReferral(int $clinicReferralId, ?array $actor = null): array
    {
        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());

        // Check if already bridged (idempotent)
        $existing = $this->referralModel->scoped($tenantId)
            ->where('clinic_referral_id', $clinicReferralId)
            ->first();

        if ($existing !== null) {
            return $this->decryptPayload($existing);
        }

        // Query clinic referral strictly without modifying clinic table
        $clinicRef = $this->db->table('referrals')
            ->where('id', $clinicReferralId)
            ->where('tenant_id', $tenantId)
            ->get()
            ->getRowArray();

        if ($clinicRef === null) {
            throw new InvalidArgumentException('Clinic referral not found.', 404);
        }

        // Issuer snapshot
        $issuer = $this->userModel->scoped($tenantId)->find((int) $clinicRef['issuer_user_id']);
        $issuerPerson = !empty($issuer['person_id'])
            ? $this->personModel->scoped($tenantId)->find((int) $issuer['person_id'])
            : null;

        $referrerName = $issuerPerson
            ? trim($issuerPerson['first_name'] . ' ' . $issuerPerson['last_name'])
            : ($issuer['username'] ?? 'Clinic Healthcare Provider');

        $priority = in_array($clinicRef['priority'], self::PRIORITY_HINTS, true)
            ? $clinicRef['priority']
            : 'routine';

        $selections = [
            'reason_code' => $clinicRef['reason_code'],
            'clinic_referral_code' => $clinicRef['referral_code'],
        ];

        $narrative = !empty($clinicRef['reason_description'])
            ? (string) $clinicRef['reason_description']
            : 'Referred from University Clinic encounter.';

        $encSelections = $this->encryptionService->encrypt(json_encode($selections, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $encNarrative = $this->encryptionService->encrypt(trim($narrative));
        $refNumber = 'GIR-CR' . str_pad((string) $clinicReferralId, 4, '0', STR_PAD_LEFT) . '-' . strtoupper(bin2hex(random_bytes(2)));
        $now = date('Y-m-d H:i:s');

        return $this->transaction(function () use (
            $tenantId,
            $refNumber,
            $clinicReferralId,
            $clinicRef,
            $issuer,
            $issuerPerson,
            $referrerName,
            $priority,
            $encSelections,
            $encNarrative,
            $now
        ): array {
            $data = [
                'tenant_id' => $tenantId,
                'referral_number' => $refNumber,
                'source_type' => 'clinic_link',
                'clinic_referral_id' => $clinicReferralId,
                'subject_person_id' => (int) $clinicRef['patient_person_id'],
                'referrer_user_id' => (int) $clinicRef['issuer_user_id'],
                'referrer_person_id' => $issuerPerson ? (int) $issuerPerson['id'] : null,
                'referrer_name_snapshot' => $referrerName,
                'referrer_designation_snapshot' => 'Clinic Physician / Nurse',
                'referrer_department_snapshot' => 'University Clinic',
                'referrer_contact_snapshot' => $issuer['email'] ?? null,
                'priority_hint' => $priority,
                'status' => 'submitted',
                'concern_catalog_version' => self::CATALOG_VERSION,
                'selections_cipher' => $encSelections['cipher'],
                'selections_nonce' => $encSelections['nonce'],
                'selections_tag' => $encSelections['tag'],
                'selections_key_version' => $encSelections['key_version'],
                'narrative_cipher' => $encNarrative['cipher'],
                'narrative_nonce' => $encNarrative['nonce'],
                'narrative_tag' => $encNarrative['tag'],
                'narrative_key_version' => $encNarrative['key_version'],
                'submitted_at' => $clinicRef['created_at'] ?? $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $id = $this->referralModel->insert($data, true);
            if ($id === false) {
                throw new RuntimeException('Failed to bridge clinic referral.', 503);
            }

            $record = $this->referralModel->scoped($tenantId)->find((int) $id);
            $this->audit('guidance.intake.clinic_bridged', (int) $id, [
                'id' => (int) $clinicRef['issuer_user_id'],
                'role' => 'clinic_provider',
            ]);

            return $this->decryptPayload($record);
        });
    }

    /**
     * Retrieve intake referral by ID.
     *
     * @param int $referralId Referral ID
     * @param int|null $tenantId Optional tenant ID
     * @param bool $decrypt Whether to decrypt payload fields
     * @return array<string, mixed>
     */
    public function getReferral(int $referralId, ?int $tenantId = null, bool $decrypt = true): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $record = $this->referralModel->scoped($tid)->find($referralId);

        if ($record === null) {
            throw new InvalidArgumentException('Intake referral not found.', 404);
        }

        return $decrypt ? $this->decryptPayload($record) : $record;
    }

    /**
     * Retrieve intake referral by referral number.
     *
     * @param string $referralNumber Referral number
     * @param int|null $tenantId Optional tenant ID
     * @param bool $decrypt Whether to decrypt payload fields
     * @return array<string, mixed>|null
     */
    public function findByReferralNumber(string $referralNumber, ?int $tenantId = null, bool $decrypt = true): ?array
    {
        $record = $this->referralModel->findByReferralNumber($referralNumber, $tenantId);
        if ($record === null) {
            return null;
        }

        return $decrypt ? $this->decryptPayload($record) : $record;
    }

    /**
     * Decrypt encrypted selections, narrative, and prior actions payload.
     *
     * @param array<string, mixed> $referral Raw referral database row
     * @return array<string, mixed> Referral with decrypted selections, narrative, and prior_actions
     */
    public function decryptPayload(array $referral): array
    {
        $decrypted = $referral;

        // Decrypt selections
        if (!empty($referral['selections_cipher'])
            && !empty($referral['selections_nonce'])
            && !empty($referral['selections_tag'])) {
            try {
                $rawSelections = $this->encryptionService->decrypt(
                    $referral['selections_cipher'],
                    $referral['selections_nonce'],
                    $referral['selections_tag'],
                    (int) ($referral['selections_key_version'] ?? 1)
                );
                $decrypted['selections'] = json_decode($rawSelections, true) ?? [];
            } catch (Throwable) {
                $decrypted['selections'] = [];
            }
        } else {
            $decrypted['selections'] = [];
        }

        // Decrypt narrative
        if (!empty($referral['narrative_cipher'])
            && !empty($referral['narrative_nonce'])
            && !empty($referral['narrative_tag'])) {
            try {
                $decrypted['narrative'] = $this->encryptionService->decrypt(
                    $referral['narrative_cipher'],
                    $referral['narrative_nonce'],
                    $referral['narrative_tag'],
                    (int) ($referral['narrative_key_version'] ?? 1)
                );
            } catch (Throwable) {
                $decrypted['narrative'] = '';
            }
        } else {
            $decrypted['narrative'] = '';
        }

        // Decrypt prior actions
        if (!empty($referral['prior_actions_cipher'])
            && !empty($referral['prior_actions_nonce'])
            && !empty($referral['prior_actions_tag'])) {
            try {
                $decrypted['prior_actions'] = $this->encryptionService->decrypt(
                    $referral['prior_actions_cipher'],
                    $referral['prior_actions_nonce'],
                    $referral['prior_actions_tag'],
                    (int) ($referral['prior_actions_key_version'] ?? 1)
                );
            } catch (Throwable) {
                $decrypted['prior_actions'] = null;
            }
        } else {
            $decrypted['prior_actions'] = null;
        }

        unset(
            $decrypted['selections_cipher'],
            $decrypted['selections_nonce'],
            $decrypted['selections_tag'],
            $decrypted['selections_key_version'],
            $decrypted['narrative_cipher'],
            $decrypted['narrative_nonce'],
            $decrypted['narrative_tag'],
            $decrypted['narrative_key_version'],
            $decrypted['prior_actions_cipher'],
            $decrypted['prior_actions_nonce'],
            $decrypted['prior_actions_tag'],
            $decrypted['prior_actions_key_version']
        );

        return $decrypted;
    }

    /**
     * Resolve referrer identity (teachers, staff, students, or callers).
     */
    private function resolveReferrerActor(?array $actor): array
    {
        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
        $actorId = (int) ($actor['id'] ?? 0);

        if ($actorId > 0) {
            $user = $this->userModel->scoped($tenantId)->find($actorId);
            if ($user !== null) {
                $person = !empty($user['person_id'])
                    ? $this->personModel->scoped($tenantId)->find((int) $user['person_id'])
                    : null;

                $name = $actor['referrer_name_snapshot']
                    ?? ($person !== null ? trim($person['first_name'] . ' ' . $person['last_name']) : (string) $user['username']);

                return [
                    'id' => (int) $user['id'],
                    'tenant_id' => $tenantId,
                    'user_id' => (int) $user['id'],
                    'person_id' => $person !== null ? (int) $person['id'] : null,
                    'username' => (string) $user['username'],
                    'name' => $name,
                    'designation' => $actor['referrer_designation_snapshot'] ?? ($actor['designation'] ?? (string) $user['role']),
                    'department' => $actor['referrer_department_snapshot'] ?? ($actor['department'] ?? 'General'),
                    'contact' => $actor['referrer_contact_snapshot'] ?? ($user['email'] ?? null),
                    'role' => (string) $user['role'],
                ];
            }
        }

        return [
            'id' => $actorId > 0 ? $actorId : 1,
            'tenant_id' => $tenantId,
            'user_id' => $actorId > 0 ? $actorId : 1,
            'person_id' => isset($actor['person_id']) ? (int) $actor['person_id'] : null,
            'username' => (string) ($actor['username'] ?? 'referrer'),
            'name' => (string) ($actor['referrer_name_snapshot'] ?? $actor['name'] ?? 'Referrer'),
            'designation' => (string) ($actor['referrer_designation_snapshot'] ?? $actor['designation'] ?? 'Faculty/Staff'),
            'department' => (string) ($actor['referrer_department_snapshot'] ?? $actor['department'] ?? 'Academic Department'),
            'contact' => $actor['referrer_contact_snapshot'] ?? $actor['email'] ?? null,
            'role' => (string) ($actor['role'] ?? 'faculty'),
        ];
    }

    /**
     * Resolve Guidance staff identity and enforce permissions.
     */
    private function resolveGuidanceActor(?array $actor, ?string $permission = null): array
    {
        if ($actor !== null && isset($actor['permissions'])) {
            if ($permission !== null && !in_array($permission, $actor['permissions'], true)
                && !in_array('guidance.cases.write', $actor['permissions'], true)
                && !in_array('guidance.cases.manage', $actor['permissions'], true)) {
                throw new DomainException("Guidance permission forbidden: {$permission}.", 403);
            }

            return [
                'id' => (int) ($actor['id'] ?? 1),
                'tenant_id' => (int) ($actor['tenant_id'] ?? CurrentTenant::id()),
                'role' => (string) ($actor['role'] ?? 'counsellor'),
                'permissions' => $actor['permissions'],
            ];
        }

        try {
            return $this->accessPolicy->resolve($actor, $permission);
        } catch (DomainException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                try {
                    return $this->accessPolicy->authorize([
                        'id' => (int) $actor['id'],
                        'tenant_id' => $tenantId,
                    ], $permission);
                } catch (DomainException $de) {
                    throw $de;
                }
            }

            $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
            return [
                'id' => (int) ($actor['id'] ?? 1),
                'tenant_id' => $tenantId,
                'role' => (string) ($actor['role'] ?? 'counsellor'),
                'permissions' => ['guidance.intake.process', 'guidance.safety.triage', 'guidance.cases.write'],
            ];
        }
    }

    /**
     * Generate unique referral number: GIR-YYYYMMDD-XXXX.
     */
    private function generateReferralNumber(int $tenantId): string
    {
        for ($i = 0; $i < 10; $i++) {
            $code = 'GIR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $exists = $this->referralModel->scoped($tenantId)->where('referral_number', $code)->first();
            if ($exists === null) {
                return $code;
            }
        }

        return 'GIR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Execute database transaction with read committed isolation.
     */
    private function transaction(callable $operation): mixed
    {
        $ownsTransaction = $this->db->transDepth === 0;
        if ($ownsTransaction) {
            $this->db->resetTransStatus();
            if ($this->db->DBDriver === 'MySQLi') {
                $this->db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            if (!$this->db->transBegin()) {
                throw new RuntimeException('Unable to begin guidance intake transaction.', 503);
            }
        } elseif (!$this->db->transStatus()) {
            throw new RuntimeException('Guidance intake changes require a healthy caller transaction.', 503);
        }

        try {
            $result = $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit guidance intake changes.', 503);
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction) {
                try {
                    $this->db->transRollback();
                } finally {
                    $this->db->resetTransStatus();
                }
            }
            throw $e;
        }
    }

    /**
     * Log audit action safely without failing transaction on non-critical ledger issues.
     */
    private function audit(string $action, int $recordId, array $actor, array $changes = []): void
    {
        try {
            (new AuditService(null, $this->db))->log(
                $action,
                'guidance_intake_referrals',
                (string) $recordId,
                $action,
                $changes,
                (int) ($actor['id'] ?? 0),
                (string) ($actor['role'] ?? 'system')
            );
        } catch (Throwable) {
            // Non-critical audit logging failure
        }
    }
}
