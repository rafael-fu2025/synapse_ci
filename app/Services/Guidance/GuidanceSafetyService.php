<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\CounsellingCaseModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSafetyAssessmentModel;
use App\Models\GuidanceSafetyEscalationModel;
use App\Models\PersonModel;
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
 * Service managing authoritative clinical safety assessments and crisis escalations.
 *
 * Implements Phase 3 Safety Triage requirements:
 * 1. Professional Safety Assessments:
 *    - Enforces guidance.safety.triage permission and professional practitioner eligibility.
 *    - Strictly separates authoritative clinical assessment (low, moderate, high, imminent_danger)
 *      from referrer attention hints (routine, urgent, crisis).
 *    - Encrypts clinical indicators and safety plans with AES-256-GCM.
 *    - Persists immutable append-only safety assessment records.
 * 2. Safety Escalations:
 *    - Routes crisis events to internal/external channels with acknowledgement receipt tracking.
 *    - Provides decrypted safety history and escalation audit trail.
 */
class GuidanceSafetyService
{
    /** Authoritative clinical risk levels. Distinct from intake priority hints. */
    public const RISK_LEVELS = [
        'low',
        'moderate',
        'high',
        'imminent_danger',
    ];

    /** Permitted crisis escalation dispatch channels. */
    public const ESCALATION_CHANNELS = [
        'internal_crisis_team',
        'dean_student_affairs',
        'university_physician',
        'external_emergency',
        'guardian_contact',
    ];

    /** Permitted professional practitioner credential tiers. General staff are excluded. */
    public const QUALIFIED_TIERS = [
        'counselor_associate',
        'licensed_counselor',
        'guidance_supervisor',
        'qualified_assessor',
    ];

    private GuidanceSafetyAssessmentModel $safetyAssessmentModel;
    private GuidanceSafetyEscalationModel $safetyEscalationModel;
    private PersonModel $personModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceIntakeReferralModel $intakeReferralModel;
    private CounsellingCaseModel $caseModel;
    private EncryptionService $encryptionService;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceSafetyAssessmentModel $safetyAssessmentModel = null,
        ?GuidanceSafetyEscalationModel $safetyEscalationModel = null,
        ?PersonModel $personModel = null,
        ?GuidancePractitionerProfileModel $profileModel = null,
        ?GuidanceIntakeReferralModel $intakeReferralModel = null,
        ?CounsellingCaseModel $caseModel = null,
        ?EncryptionService $encryptionService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null
    ) {
        $models = array_filter([
            $safetyAssessmentModel,
            $safetyEscalationModel,
            $personModel,
            $profileModel,
            $intakeReferralModel,
            $caseModel,
        ]);
        $this->db = $db ?? ($models !== [] ? reset($models)->db : Database::connect());

        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance models must share their transaction connection.');
            }
        }

        $this->safetyAssessmentModel = $safetyAssessmentModel ?? new GuidanceSafetyAssessmentModel($this->db);
        $this->safetyEscalationModel = $safetyEscalationModel ?? new GuidanceSafetyEscalationModel($this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->profileModel = $profileModel ?? new GuidancePractitionerProfileModel($this->db);
        $this->intakeReferralModel = $intakeReferralModel ?? new GuidanceIntakeReferralModel($this->db);
        $this->caseModel = $caseModel ?? new CounsellingCaseModel($this->db);
        $this->encryptionService = $encryptionService ?? new EncryptionService();
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
    }

    /**
     * Record an immutable, authoritative clinical safety assessment.
     *
     * Clinical safety assessment is distinct from referral priority attention hints:
     * referral priority is a subjective hint from a referrer, whereas safety assessment
     * is an authoritative clinical determination conducted by a qualified practitioner.
     *
     * @param int $personId Subject person ID
     * @param string $riskLevel Authoritative risk level ('low', 'moderate', 'high', 'imminent_danger')
     * @param bool $selfHarm Whether risk of self-harm / suicide is present
     * @param bool $harmToOthers Whether risk of harm to others / violence is present
     * @param array<int, mixed> $indicators Clinical indicators observed/reported
     * @param string $immediateActions Immediate clinical interventions or containment actions taken
     * @param string|null $safetyPlan Optional formalized safety plan narrative
     * @param int|null $intakeReferralId Optional linked intake referral ID
     * @param int|null $caseId Optional linked counseling case ID
     * @param array<string, mixed>|null $actor Caller identity hint
     * @return array<string, mixed> Inserted assessment record with decrypted convenience fields
     */
    public function recordSafetyAssessment(
        int $personId,
        string $riskLevel,
        bool $selfHarm,
        bool $harmToOthers,
        array $indicators,
        string $immediateActions,
        ?string $safetyPlan = null,
        ?int $intakeReferralId = null,
        ?int $caseId = null,
        ?array $actor = null
    ): array {
        // 1. Validate risk level
        if (!in_array($riskLevel, self::RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                "Invalid risk level '{$riskLevel}'. Must be one of: " . implode(', ', self::RISK_LEVELS) . '.',
                422
            );
        }

        // 2. Validate immediate actions
        $immediateActions = trim($immediateActions);
        if ($immediateActions === '') {
            throw new InvalidArgumentException('Immediate actions taken is required.', 422);
        }

        // 3. Resolve actor and enforce guidance.safety.triage permission
        $authority = $this->resolveActor($actor, 'guidance.safety.triage');
        $tenantId = (int) $authority['tenant_id'];
        $assessorUserId = (int) $authority['id'];

        // 4. Enforce professional eligibility (requires qualified practitioner, not general staff / admin)
        $this->assertProfessionalEligibility($assessorUserId, $tenantId);

        // 5. Validate subject person exists in active tenant
        $this->assertPersonExists($personId, $tenantId);

        // 6. Validate intake referral if supplied
        if ($intakeReferralId !== null) {
            $referral = $this->intakeReferralModel->scoped($tenantId)->find($intakeReferralId);
            if ($referral === null) {
                throw new InvalidArgumentException("Intake referral {$intakeReferralId} not found.", 404);
            }
        }

        // 7. Validate case if supplied
        if ($caseId !== null) {
            $case = $this->caseModel->scoped($tenantId)->find($caseId);
            if ($case === null) {
                throw new InvalidArgumentException("Counseling case {$caseId} not found.", 404);
            }
        }

        // 8. Encrypt clinical indicators with AES-256-GCM
        try {
            $indicatorsPlaintext = json_encode($indicators, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid clinical indicators payload: ' . $e->getMessage(), 422, $e);
        }

        $encryptedIndicators = $this->encryptionService->encrypt($indicatorsPlaintext);

        // 9. Encrypt safety plan with AES-256-GCM if provided
        $trimmedPlan = ($safetyPlan !== null && trim($safetyPlan) !== '') ? trim($safetyPlan) : null;
        $encryptedPlan = null;
        if ($trimmedPlan !== null) {
            $encryptedPlan = $this->encryptionService->encrypt($trimmedPlan);
        }

        // 10. Persist immutable record inside transaction
        return $this->transaction(function () use (
            $tenantId,
            $intakeReferralId,
            $caseId,
            $personId,
            $assessorUserId,
            $riskLevel,
            $selfHarm,
            $harmToOthers,
            $encryptedIndicators,
            $immediateActions,
            $encryptedPlan,
            $indicators,
            $trimmedPlan,
            $authority
        ): array {
            $now = date('Y-m-d H:i:s');
            $data = [
                'tenant_id'                => $tenantId,
                'intake_referral_id'       => $intakeReferralId,
                'case_id'                  => $caseId,
                'person_id'                => $personId,
                'assessor_user_id'         => $assessorUserId,
                'risk_level'               => $riskLevel,
                'self_harm_risk'           => $selfHarm ? 1 : 0,
                'harm_to_others_risk'      => $harmToOthers ? 1 : 0,
                'indicators_cipher'        => $encryptedIndicators['cipher'],
                'indicators_nonce'         => $encryptedIndicators['nonce'],
                'indicators_tag'           => $encryptedIndicators['tag'],
                'indicators_key_version'   => (int) $encryptedIndicators['key_version'],
                'immediate_actions_taken'  => $immediateActions,
                'safety_plan_cipher'       => $encryptedPlan['cipher'] ?? null,
                'safety_plan_nonce'        => $encryptedPlan['nonce'] ?? null,
                'safety_plan_tag'          => $encryptedPlan['tag'] ?? null,
                'safety_plan_key_version'  => isset($encryptedPlan['key_version']) ? (int) $encryptedPlan['key_version'] : null,
                'assessed_at'              => $now,
            ];

            $assessmentId = (int) $this->safetyAssessmentModel->insert($data);

            // Audit log (metadata only; no plaintext clinical narratives)
            $this->audit('safety_assessment_recorded', 'guidance_safety_assessments', $assessmentId, $authority, [
                'person_id'           => $personId,
                'risk_level'          => $riskLevel,
                'self_harm_risk'      => $selfHarm ? 1 : 0,
                'harm_to_others_risk' => $harmToOthers ? 1 : 0,
                'intake_referral_id'  => $intakeReferralId,
                'case_id'             => $caseId,
            ]);

            $record = $this->safetyAssessmentModel->scoped($tenantId)->find($assessmentId);
            if ($record === null) {
                throw new RuntimeException('Failed to retrieve newly inserted safety assessment.', 500);
            }

            // Expose decrypted convenience properties alongside raw cipher fields
            $record['indicators'] = $indicators;
            $record['safety_plan'] = $trimmedPlan;
            $record['escalations'] = [];

            return $record;
        });
    }

    /**
     * Record a safety escalation event dispatched to a crisis channel.
     *
     * @param int $safetyAssessmentId Linked safety assessment ID
     * @param string $channel Target channel in self::ESCALATION_CHANNELS
     * @param string $recipient Specific agency, contact, or official receiving escalation
     * @param string $summary Summary narrative / handover briefing
     * @param array<string, mixed>|null $actor Caller identity hint
     * @return array<string, mixed> Inserted escalation record
     */
    public function recordEscalation(
        int $safetyAssessmentId,
        string $channel,
        string $recipient,
        string $summary,
        ?array $actor = null
    ): array {
        // 1. Validate escalation channel
        if (!in_array($channel, self::ESCALATION_CHANNELS, true)) {
            throw new InvalidArgumentException(
                "Invalid escalation channel '{$channel}'. Must be one of: " . implode(', ', self::ESCALATION_CHANNELS) . '.',
                422
            );
        }

        // 2. Validate recipient and summary
        $recipient = trim($recipient);
        if ($recipient === '') {
            throw new InvalidArgumentException('Recipient is required.', 422);
        }

        $summary = trim($summary);
        if ($summary === '') {
            throw new InvalidArgumentException('Summary notes are required.', 422);
        }

        // 3. Resolve actor with guidance.safety.triage permission
        $authority = $this->resolveActor($actor, 'guidance.safety.triage');
        $tenantId = (int) $authority['tenant_id'];
        $escalatedByUserId = (int) $authority['id'];

        // 4. Verify linked assessment exists in this tenant
        $assessment = $this->safetyAssessmentModel->scoped($tenantId)->find($safetyAssessmentId);
        if ($assessment === null) {
            throw new InvalidArgumentException("Safety assessment {$safetyAssessmentId} not found.", 404);
        }

        // 5. Persist escalation inside transaction
        return $this->transaction(function () use (
            $tenantId,
            $safetyAssessmentId,
            $escalatedByUserId,
            $channel,
            $recipient,
            $summary,
            $authority
        ): array {
            $now = date('Y-m-d H:i:s');
            $data = [
                'tenant_id'                  => $tenantId,
                'safety_assessment_id'       => $safetyAssessmentId,
                'escalated_by_user_id'       => $escalatedByUserId,
                'escalation_channel'         => $channel,
                'recipient_agency_or_person' => $recipient,
                'summary_notes'              => $summary,
                'acknowledged_at'            => null,
                'acknowledged_by'            => null,
                'occurred_at'                => $now,
            ];

            $escalationId = (int) $this->safetyEscalationModel->insert($data);

            $this->audit('safety_escalation_recorded', 'guidance_safety_escalations', $escalationId, $authority, [
                'safety_assessment_id' => $safetyAssessmentId,
                'escalation_channel'   => $channel,
                'recipient'            => $recipient,
            ]);

            $record = $this->safetyEscalationModel->scoped($tenantId)->find($escalationId);
            if ($record === null) {
                throw new RuntimeException('Failed to retrieve newly inserted safety escalation.', 500);
            }

            return $record;
        });
    }

    /**
     * Acknowledge receipt of a safety escalation event.
     *
     * @param int $escalationId Target escalation ID
     * @param string $acknowledgedBy Name/title of acknowledging official or external respondent
     * @param array<string, mixed>|null $actor Caller identity hint
     * @return array<string, mixed> Updated escalation record
     */
    public function acknowledgeEscalation(
        int $escalationId,
        string $acknowledgedBy,
        ?array $actor = null
    ): array {
        $acknowledgedBy = trim($acknowledgedBy);
        if ($acknowledgedBy === '') {
            throw new InvalidArgumentException('Acknowledged by name or title is required.', 422);
        }

        $authority = $this->resolveActor($actor, 'guidance.safety.triage');
        $tenantId = (int) $authority['tenant_id'];

        $escalation = $this->safetyEscalationModel->scoped($tenantId)->find($escalationId);
        if ($escalation === null) {
            throw new InvalidArgumentException("Safety escalation {$escalationId} not found.", 404);
        }

        return $this->transaction(function () use ($tenantId, $escalationId, $acknowledgedBy, $authority): array {
            $now = date('Y-m-d H:i:s');
            $this->safetyEscalationModel->scoped($tenantId)->update($escalationId, [
                'acknowledged_at' => $now,
                'acknowledged_by' => $acknowledgedBy,
            ]);

            $this->audit('safety_escalation_acknowledged', 'guidance_safety_escalations', $escalationId, $authority, [
                'acknowledged_by' => $acknowledgedBy,
                'acknowledged_at' => $now,
            ]);

            $record = $this->safetyEscalationModel->scoped($tenantId)->find($escalationId);
            if ($record === null) {
                throw new RuntimeException('Failed to retrieve updated safety escalation.', 500);
            }

            return $record;
        });
    }

    /**
     * Retrieve decrypted safety history (assessments and escalation events) for a person.
     *
     * @param int $personId Subject person ID
     * @param array<string, mixed>|null $actor Caller identity hint
     * @return array{
     *     person_id: int,
     *     assessments: array<int, array<string, mixed>>,
     *     escalations: array<int, array<string, mixed>>,
     *     events: array<int, array<string, mixed>>
     * }
     */
    public function getSafetyHistory(int $personId, ?array $actor = null): array
    {
        $authority = $this->resolveActor($actor);
        $tenantId = (int) $authority['tenant_id'];

        // Enforce guidance permissions for accessing confidential safety history
        $hasPermission = in_array('guidance.safety.triage', $authority['permissions'] ?? [], true)
            || in_array('guidance.cases.read_any', $authority['permissions'] ?? [], true)
            || in_array('guidance.cases.read', $authority['permissions'] ?? [], true);
        if (!empty($authority['permissions']) && !$hasPermission) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        $this->assertPersonExists($personId, $tenantId);

        $rawAssessments = $this->safetyAssessmentModel->getByPersonId($personId, $tenantId);

        $assessments = [];
        $allEscalations = [];
        $timeline = [];

        foreach ($rawAssessments as $row) {
            $assessmentId = (int) $row['id'];
            $decrypted = $this->decryptAssessmentRow($row);

            $escalations = $this->safetyEscalationModel->getByAssessmentId($assessmentId, $tenantId);
            $decrypted['escalations'] = $escalations;
            $assessments[] = $decrypted;

            $timeline[] = [
                'event_type'              => 'safety_assessment',
                'id'                      => $assessmentId,
                'timestamp'               => $decrypted['assessed_at'],
                'risk_level'              => $decrypted['risk_level'],
                'self_harm_risk'          => (int) $decrypted['self_harm_risk'],
                'harm_to_others_risk'     => (int) $decrypted['harm_to_others_risk'],
                'immediate_actions_taken' => $decrypted['immediate_actions_taken'],
                'indicators'              => $decrypted['indicators'],
                'safety_plan'             => $decrypted['safety_plan'],
            ];

            foreach ($escalations as $esc) {
                $allEscalations[] = $esc;
                $timeline[] = [
                    'event_type'           => 'safety_escalation',
                    'id'                   => (int) $esc['id'],
                    'safety_assessment_id' => $assessmentId,
                    'timestamp'            => $esc['occurred_at'],
                    'channel'              => $esc['escalation_channel'],
                    'recipient'            => $esc['recipient_agency_or_person'],
                    'summary_notes'        => $esc['summary_notes'],
                    'acknowledged_at'      => $esc['acknowledged_at'],
                    'acknowledged_by'      => $esc['acknowledged_by'],
                ];
            }
        }

        // Sort events chronologically descending (newest first)
        usort($timeline, static function (array $a, array $b): int {
            return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
        });

        // Audit view access (metadata only)
        $this->audit('safety_history_read', 'guidance_safety_assessments', $personId, $authority);

        return [
            'person_id'   => $personId,
            'assessments' => $assessments,
            'escalations' => $allEscalations,
            'events'      => $timeline,
        ];
    }

    /**
     * Retrieve a single safety assessment with decrypted fields and linked escalations.
     *
     * @param int $safetyAssessmentId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getSafetyAssessment(int $safetyAssessmentId, ?array $actor = null): array
    {
        $authority = $this->resolveActor($actor);
        $tenantId = (int) $authority['tenant_id'];

        $hasPermission = in_array('guidance.safety.triage', $authority['permissions'] ?? [], true)
            || in_array('guidance.cases.read_any', $authority['permissions'] ?? [], true)
            || in_array('guidance.cases.read', $authority['permissions'] ?? [], true);
        if (!empty($authority['permissions']) && !$hasPermission) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        $row = $this->safetyAssessmentModel->scoped($tenantId)->find($safetyAssessmentId);
        if ($row === null) {
            throw new InvalidArgumentException("Safety assessment {$safetyAssessmentId} not found.", 404);
        }

        $decrypted = $this->decryptAssessmentRow($row);
        $decrypted['escalations'] = $this->safetyEscalationModel->getByAssessmentId($safetyAssessmentId, $tenantId);

        return $decrypted;
    }

    /**
     * Check whether a user possesses the professional standing and credential tier
     * required to perform authoritative clinical safety assessments.
     *
     * General staff and administrators without registered clinical practitioner credentials
     * return false. Superadmin role NEVER bypasses clinical eligibility.
     */
    public function canPerformSafetyAssessment(int $userId, ?int $tenantId = null): bool
    {
        try {
            $this->assertProfessionalEligibility($userId, $tenantId);
            return true;
        } catch (DomainException) {
            return false;
        }
    }

    /**
     * Asserts that a user has an active, valid clinical practitioner profile in active standing.
     * Throws DomainException (403) on failure.
     *
     * @param int $userId
     * @param int|null $tenantId
     * @return array<string, mixed> The verified practitioner profile
     * @throws DomainException If user lacks active practitioner standing or credentials expired
     */
    public function assertProfessionalEligibility(int $userId, ?int $tenantId = null): array
    {
        $tenantId ??= CurrentTenant::id();
        $table = $this->db->protectIdentifiers($this->db->prefixTable('guidance_practitioner_profiles'));

        $profile = $this->db->query(
            "SELECT * FROM {$table} WHERE user_id = ? AND tenant_id = ? AND is_active = 1",
            [$userId, $tenantId]
        )->getRowArray();

        if ($profile === null) {
            throw new DomainException(
                "Professional eligibility required: active qualified practitioner profile not found for user {$userId}.",
                403
            );
        }

        if (($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Practitioner is not in active professional standing.', 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException('Practitioner credentials have expired.', 403);
        }

        $tier = (string) ($profile['credential_tier'] ?? '');
        if (!in_array($tier, self::QUALIFIED_TIERS, true)) {
            throw new DomainException("Practitioner tier '{$tier}' is not qualified for clinical safety triage.", 403);
        }

        return $profile;
    }

    /**
     * Decrypt AES-256-GCM clinical indicators and safety plan for a database row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decryptAssessmentRow(array $row): array
    {
        $indicators = [];
        if (!empty($row['indicators_cipher']) && !empty($row['indicators_nonce']) && !empty($row['indicators_tag'])) {
            try {
                $plaintext = $this->encryptionService->decrypt(
                    (string) $row['indicators_cipher'],
                    (string) $row['indicators_nonce'],
                    (string) $row['indicators_tag'],
                    (int) ($row['indicators_key_version'] ?? 1)
                );
                $indicators = json_decode($plaintext, true) ?: [];
            } catch (Throwable) {
                $indicators = [];
            }
        }

        $safetyPlan = null;
        if (!empty($row['safety_plan_cipher']) && !empty($row['safety_plan_nonce']) && !empty($row['safety_plan_tag'])) {
            try {
                $safetyPlan = $this->encryptionService->decrypt(
                    (string) $row['safety_plan_cipher'],
                    (string) $row['safety_plan_nonce'],
                    (string) $row['safety_plan_tag'],
                    (int) ($row['safety_plan_key_version'] ?? 1)
                );
            } catch (Throwable) {
                $safetyPlan = null;
            }
        }

        $row['indicators'] = $indicators;
        $row['safety_plan'] = $safetyPlan;

        return $row;
    }

    /**
     * Verify subject person exists in current tenant.
     */
    private function assertPersonExists(int $personId, int $tenantId): void
    {
        if ($personId <= 0) {
            throw new InvalidArgumentException("Invalid person ID {$personId}.", 404);
        }

        $person = $this->personModel->scoped($tenantId)->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException("Person {$personId} not found in active tenant.", 404);
        }
    }

    /**
     * Resolve actor using transport identity or explicit identity hint.
     *
     * @param array<string, mixed>|null $actor
     * @param string|null $permission
     * @param int|null $expectedActorId
     * @param bool $lock
     * @return array<string, mixed>
     */
    private function resolveActor(
        ?array $actor,
        ?string $permission = null,
        ?int $expectedActorId = null,
        bool $lock = false
    ): array {
        try {
            return $this->accessPolicy->resolve($actor, $permission, $expectedActorId, $lock);
        } catch (DomainException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                return $this->accessPolicy->authorize([
                    'id'        => (int) $actor['id'],
                    'tenant_id' => $tenantId,
                ], $permission, $expectedActorId, $lock);
            }
            throw new DomainException('An authenticated guidance account is required.', 403, $e);
        }
    }

    /**
     * Execute an operation inside a database transaction.
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
                throw new RuntimeException('Unable to begin guidance safety transaction.', 503);
            }
        } elseif (!$this->db->transStatus()) {
            throw new RuntimeException('Guidance safety changes require a healthy caller transaction.', 503);
        }

        try {
            $result = $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit guidance safety changes.', 503);
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
    private function audit(string $action, string $tableName, int $recordId, array $actor, array $changes = []): void
    {
        try {
            (new AuditService(null, $this->db))->log(
                $action,
                $tableName,
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
