<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceEvaluationTokenModel;
use App\Models\GuidanceMultipleIntelligenceAssignmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;

/**
 * Service for Phase 8 Mobile Parity Contracts & Boundary Enforcement.
 *
 * Provides strictly filtered, mobile-safe contract payloads for authenticated students
 * and enforces workstation-only boundaries for clinical authoring, co-signing,
 * safety triage, and case transfers.
 */
final class GuidanceMobileContractService
{
    public const ALLOWED_MOBILE_ACTIONS = [
        'view_appointments',
        'acknowledge_call_slip',
        'submit_self_referral',
        'take_mi_survey',
        'view_mi_results',
        'submit_service_evaluation',
    ];

    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceCallSlipModel $callSlipModel;
    private GuidanceMultipleIntelligenceAssignmentModel $miAssignmentModel;
    private GuidanceEvaluationTokenModel $evaluationTokenModel;
    private GuidanceCallSlipService $callSlipService;
    private GuidanceServiceEvaluationService $evaluationService;
    private PersonModel $personModel;
    private UserModel $userModel;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceAppointmentModel $appointmentModel = null,
        ?GuidanceCallSlipModel $callSlipModel = null,
        ?GuidanceMultipleIntelligenceAssignmentModel $miAssignmentModel = null,
        ?GuidanceEvaluationTokenModel $evaluationTokenModel = null,
        ?GuidanceCallSlipService $callSlipService = null,
        ?GuidanceServiceEvaluationService $evaluationService = null,
        ?PersonModel $personModel = null,
        ?UserModel $userModel = null,
        ?BaseConnection $db = null
    ) {
        $this->db                   = $db ?? Database::connect();
        $this->appointmentModel     = $appointmentModel ?? new GuidanceAppointmentModel($this->db);
        $this->callSlipModel        = $callSlipModel ?? new GuidanceCallSlipModel($this->db);
        $this->miAssignmentModel    = $miAssignmentModel ?? new GuidanceMultipleIntelligenceAssignmentModel($this->db);
        $this->evaluationTokenModel = $evaluationTokenModel ?? new GuidanceEvaluationTokenModel($this->db);
        $this->callSlipService      = $callSlipService ?? new GuidanceCallSlipService(
            callSlipModel: $this->callSlipModel,
            db: $this->db
        );
        $this->evaluationService    = $evaluationService ?? new GuidanceServiceEvaluationService(
            tokenModel: $this->evaluationTokenModel,
            db: $this->db
        );
        $this->personModel          = $personModel ?? new PersonModel($this->db);
        $this->userModel            = $userModel ?? new UserModel($this->db);
    }

    /**
     * Assert that an action is permitted on the student mobile surface.
     *
     * Allows ['view_appointments', 'acknowledge_call_slip', 'submit_self_referral',
     * 'take_mi_survey', 'view_mi_results', 'submit_service_evaluation'].
     * Blocks clinical authoring, co-signing, safety triage, and case transfers on mobile
     * surface with 403 DomainException.
     *
     * @param string $action
     * @throws DomainException
     */
    public function assertMobileActionPermitted(string $action): void
    {
        $normalized = strtolower(trim($action));

        if (!in_array($normalized, self::ALLOWED_MOBILE_ACTIONS, true)) {
            throw new DomainException(
                'Clinical authoring and safety triage workflows are restricted to the web workstation.',
                403
            );
        }
    }

    /**
     * Returns clean, mobile-safe contract payload for an authenticated student:
     * - upcoming appointments
     * - pending/active call_slips
     * - active mi_assignments
     * - available evaluation_tokens
     * Strictly excludes confidential clinical counselor notes, safety assessments,
     * and internal triage notes.
     *
     * @param int $personId
     * @param int|array<string, mixed>|null $tenantId
     * @param int|array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getStudentDashboardPayload(
        int $personId,
        int|array|null $tenantId = null,
        int|array|null $actor = null
    ): array {
        if (is_array($tenantId)) {
            $actor    = $tenantId;
            $tenantId = isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null;
        }
        if (is_int($actor)) {
            $actor = ['id' => $actor];
        }

        if ($personId <= 0) {
            throw new InvalidArgumentException('Valid student person_id is required.', 422);
        }

        $tid = $tenantId ?? (is_array($actor) && isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null) ?? CurrentTenant::id();

        $this->assertStudentActorMatchesPerson($personId, $tid, $actor);

        // 1. Upcoming / active appointments (strictly stripped of notes_cipher / clinical notes)
        $rawAppointments = $this->appointmentModel->scoped($tid)
            ->where('person_id', $personId)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('scheduled_date', 'ASC')
            ->orderBy('scheduled_time', 'ASC')
            ->findAll();

        $appointments = [];
        foreach ($rawAppointments as $apt) {
            $counselorId = (int) ($apt['counselor_user_id'] ?? 0);
            $appointments[] = [
                'id'                 => (int) $apt['id'],
                'person_id'          => (int) $apt['person_id'],
                'appointment_number' => (string) $apt['appointment_number'],
                'scheduled_date'     => (string) $apt['scheduled_date'],
                'scheduled_time'     => (string) $apt['scheduled_time'],
                'purpose'            => (string) $apt['purpose'],
                'purpose_other'      => $apt['purpose_other'] ?? null,
                'status'             => (string) $apt['status'],
                'counselor_user_id'  => $counselorId,
                'counselor_name'     => $this->resolveCounselorDisplayName($counselorId, $tid),
            ];
        }

        // 2. Pending / active call slips (strictly stripped of internal delivery_notes / form_instance)
        $rawCallSlips = $this->callSlipModel->scoped($tid)
            ->where('person_id', $personId)
            ->whereNotIn('attendance_status', ['cancelled'])
            ->orderBy('call_date', 'ASC')
            ->orderBy('call_time', 'ASC')
            ->findAll();

        $callSlips = [];
        foreach ($rawCallSlips as $slip) {
            $counselorId = (int) ($slip['issuing_counselor_user_id'] ?? 0);
            $callSlips[] = [
                'id'                        => (int) $slip['id'],
                'person_id'                 => (int) $slip['person_id'],
                'call_slip_number'          => (string) $slip['call_slip_number'],
                'call_date'                 => (string) $slip['call_date'],
                'call_time'                 => (string) $slip['call_time'],
                'purpose'                   => (string) $slip['purpose'],
                'purpose_other'             => $slip['purpose_other'] ?? null,
                'attendance_status'         => (string) $slip['attendance_status'],
                'acknowledged_at'           => $slip['acknowledged_at'] ?? null,
                'attended_at'               => $slip['attended_at'] ?? null,
                'issuing_counselor_user_id' => $counselorId,
                'counselor_name'            => $this->resolveCounselorDisplayName($counselorId, $tid),
            ];
        }

        // 3. Active / assigned MI assessments
        $rawMiAssignments = $this->miAssignmentModel->scoped($tid)
            ->where('person_id', $personId)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->orderBy('id', 'DESC')
            ->findAll();

        $miAssignments = [];
        foreach ($rawMiAssignments as $assign) {
            $miAssignments[] = [
                'id'            => (int) $assign['id'],
                'person_id'     => (int) $assign['person_id'],
                'status'        => (string) $assign['status'],
                'academic_term' => $assign['academic_term'] ?? null,
                'academic_year' => $assign['academic_year'] ?? null,
                'assigned_at'   => (string) $assign['assigned_at'],
                'due_at'        => $assign['due_at'] ?? null,
                'completed_at'  => $assign['completed_at'] ?? null,
            ];
        }

        // 4. Available (issued, unexpired) evaluation tokens matching student's eligibility HMAC
        $issuedTokenRows = $this->evaluationTokenModel->scoped($tid)
            ->where('status', 'issued')
            ->orderBy('id', 'DESC')
            ->findAll();

        $evaluationTokens = [];
        foreach ($issuedTokenRows as $tokenRow) {
            if (!empty($tokenRow['expires_at']) && strtotime((string) $tokenRow['expires_at']) < time()) {
                continue;
            }

            $evalType  = (string) $tokenRow['evaluation_type'];
            $episodeId = !empty($tokenRow['episode_id']) ? (int) $tokenRow['episode_id'] : null;
            $sessionId = !empty($tokenRow['session_id']) ? (int) $tokenRow['session_id'] : null;

            $expectedHmac = $this->evaluationService->computeEligibilityHmac(
                $tid,
                $personId,
                $evalType,
                $episodeId,
                $sessionId
            );

            if (hash_equals($expectedHmac, (string) $tokenRow['eligibility_hmac'])) {
                $evaluationTokens[] = [
                    'id'              => (int) $tokenRow['id'],
                    'evaluation_type' => $evalType,
                    'form_code'       => GuidanceServiceEvaluationService::FORM_CODES[$evalType] ?? '',
                    'status'          => (string) $tokenRow['status'],
                    'issued_at'       => (string) $tokenRow['issued_at'],
                    'expires_at'      => $tokenRow['expires_at'] ?? null,
                ];
            }
        }

        return [
            'person_id'         => $personId,
            'tenant_id'         => $tid,
            'surface'           => 'mobile_student',
            'appointments'      => $appointments,
            'call_slips'        => $callSlips,
            'mi_assignments'    => $miAssignments,
            'evaluation_tokens' => $evaluationTokens,
        ];
    }

    /**
     * Acknowledge a call slip from the student mobile surface.
     * Verifies the call slip belongs to $personId and transitions status to 'acknowledged'
     * via GuidanceCallSlipService.
     *
     * @param int $callSlipId
     * @param int $personId
     * @param int|array<string, mixed>|null $tenantId
     * @param int|array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function acknowledgeCallSlipFromMobile(
        int $callSlipId,
        int $personId,
        int|array|null $tenantId = null,
        int|array|null $actor = null
    ): array {
        if (is_array($tenantId)) {
            $actor    = $tenantId;
            $tenantId = isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null;
        }
        if (is_int($actor)) {
            $actor = ['id' => $actor];
        }

        $this->assertMobileActionPermitted('acknowledge_call_slip');

        if ($personId <= 0) {
            throw new InvalidArgumentException('Valid student person_id is required.', 422);
        }

        $tid = $tenantId ?? (is_array($actor) && isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null) ?? CurrentTenant::id();

        $this->assertStudentActorMatchesPerson($personId, $tid, $actor);

        $slip = $this->callSlipModel->scoped($tid)->find($callSlipId);
        if ($slip === null) {
            throw new InvalidArgumentException("Call slip #{$callSlipId} not found.", 404);
        }

        if ((int) $slip['person_id'] !== $personId) {
            throw new DomainException(
                'Call slip acknowledgement forbidden: call slip does not belong to the target student.',
                403
            );
        }

        $serviceActor = $this->resolveCallSlipAcknowledgementActor($slip, $personId, $tid, $actor);

        $updated = $this->callSlipService->recordCallSlipStatus(
            $callSlipId,
            'acknowledged',
            $tid,
            $serviceActor
        );

        return [
            'id'                        => (int) $updated['id'],
            'tenant_id'                 => (int) $updated['tenant_id'],
            'person_id'                 => (int) $updated['person_id'],
            'call_slip_number'          => (string) $updated['call_slip_number'],
            'call_date'                 => (string) $updated['call_date'],
            'call_time'                 => (string) $updated['call_time'],
            'purpose'                   => (string) $updated['purpose'],
            'purpose_other'             => $updated['purpose_other'] ?? null,
            'attendance_status'         => (string) $updated['attendance_status'],
            'acknowledged_at'           => $updated['acknowledged_at'] ?? null,
            'attended_at'               => $updated['attended_at'] ?? null,
            'issuing_counselor_user_id' => (int) $updated['issuing_counselor_user_id'],
        ];
    }

    /**
     * Verify that if an actor is provided, a student actor cannot access another student's data.
     *
     * @param int $personId
     * @param int $tenantId
     * @param array<string, mixed>|null $actor
     */
    private function assertStudentActorMatchesPerson(int $personId, int $tenantId, ?array $actor): void
    {
        if ($actor === null) {
            return;
        }

        if (isset($actor['tenant_id']) && (int) $actor['tenant_id'] !== $tenantId) {
            throw new DomainException('Cross-tenant mobile guidance access forbidden.', 403);
        }

        if (!empty($actor['person_id']) && (int) $actor['person_id'] !== $personId) {
            $role = strtolower((string) ($actor['role'] ?? 'student'));
            if ($role === 'student') {
                throw new DomainException('Students may only access their own guidance mobile contract.', 403);
            }
        }

        $userId = isset($actor['id']) && is_numeric($actor['id']) ? (int) $actor['id'] : 0;
        if ($userId > 0) {
            $userRow = $this->userModel->scoped($tenantId)->find($userId);
            if ($userRow !== null) {
                $role = strtolower((string) ($userRow['role'] ?? ''));
                if ($role === 'student' && !empty($userRow['person_id']) && (int) $userRow['person_id'] !== $personId) {
                    throw new DomainException('Students may only access their own guidance mobile contract.', 403);
                }
            }
        }
    }

    /**
     * Resolve an actor array suitable for GuidanceCallSlipService::recordCallSlipStatus.
     *
     * @param array<string, mixed> $slip
     * @param int $personId
     * @param int $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    private function resolveCallSlipAcknowledgementActor(
        array $slip,
        int $personId,
        int $tenantId,
        ?array $actor
    ): array {
        if ($actor !== null && !empty($actor['id'])) {
            $userRow = $this->userModel->scoped($tenantId)->find((int) $actor['id']);
            if ($userRow !== null && ($userRow['status'] ?? '') === 'active') {
                return [
                    'id'        => (int) $userRow['id'],
                    'tenant_id' => $tenantId,
                    'person_id' => !empty($userRow['person_id']) ? (int) $userRow['person_id'] : $personId,
                    'role'      => (string) ($userRow['role'] ?? 'student'),
                ];
            }
        }

        $studentUser = $this->userModel->scoped($tenantId)
            ->where('person_id', $personId)
            ->where('status', 'active')
            ->first();

        if ($studentUser !== null) {
            return [
                'id'        => (int) $studentUser['id'],
                'tenant_id' => $tenantId,
                'person_id' => $personId,
                'role'      => (string) ($studentUser['role'] ?? 'student'),
            ];
        }

        return [
            'id'        => (int) $slip['issuing_counselor_user_id'],
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * Resolve display name for a counselor user ID without exposing internal profile details.
     */
    private function resolveCounselorDisplayName(int $counselorUserId, int $tenantId): string
    {
        if ($counselorUserId <= 0) {
            return 'Guidance Counselor';
        }

        $user = $this->userModel->scoped($tenantId)->find($counselorUserId);
        if ($user === null) {
            return 'Guidance Counselor';
        }

        if (!empty($user['person_id'])) {
            $person = $this->personModel->scoped($tenantId)->find((int) $user['person_id']);
            if ($person !== null) {
                $fullName = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
                if ($fullName !== '') {
                    return $fullName;
                }
            }
        }

        return (string) ($user['username'] ?? 'Guidance Counselor');
    }
}
