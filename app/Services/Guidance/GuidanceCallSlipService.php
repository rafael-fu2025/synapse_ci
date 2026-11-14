<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceCallSlipModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceCallSlipService
{
    public const VALID_PURPOSES = [
        'personal_counseling',
        'career_counseling',
        'routine_interview',
        'follow_up',
        'academic_counseling',
        'others',
    ];

    public const VALID_STATUSES = [
        'pending',
        'acknowledged',
        'attended',
        'missed',
        'cancelled',
    ];

    public const QUALIFIED_TIERS = [
        'counselor_associate',
        'licensed_counselor',
        'guidance_supervisor',
        'qualified_assessor',
    ];

    private GuidanceCallSlipModel $callSlipModel;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormService $formService;
    private PersonModel $personModel;
    private StudentProfileModel $studentProfileModel;
    private UserModel $userModel;
    private GuidancePractitionerProfileModel $profileModel;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceCallSlipModel $callSlipModel = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?GuidanceFormService $formService = null,
        ?PersonModel $personModel = null,
        ?StudentProfileModel $studentProfileModel = null,
        ?UserModel $userModel = null,
        ?GuidancePractitionerProfileModel $profileModel = null,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->callSlipModel = $callSlipModel ?? new GuidanceCallSlipModel($this->db);
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->formService = $formService ?? new GuidanceFormService(accessPolicy: $this->accessPolicy, db: $this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->studentProfileModel = $studentProfileModel ?? new StudentProfileModel($this->db);
        $this->userModel = $userModel ?? new UserModel($this->db);
        $this->profileModel = $profileModel ?? new GuidancePractitionerProfileModel($this->db);
    }

    /**
     * Issues a guidance call slip, validating practitioner eligibility and binding controlled form instance.
     *
     * @param array<string, mixed> $data
     * @param int|null $tenantId
     * @return array<string, mixed>
     */
    public function issueCallSlip(array $data, ?int $tenantId = null): array
    {
        $actor = $data['actor'] ?? null;
        $authority = $this->resolveActor($actor, 'guidance.call_slips.issue');
        $tid = $tenantId ?? (int) $authority['tenant_id'];

        // 1. Issuing counselor resolution and practitioner eligibility check
        $counselorUserId = (int) ($data['issuing_counselor_user_id'] ?? $authority['id']);
        $this->assertPractitionerEligibility($counselorUserId, $tid);

        $counselorUser = $this->userModel->scoped($tid)->find($counselorUserId);
        if ($counselorUser === null) {
            throw new InvalidArgumentException("Counselor user #{$counselorUserId} not found in tenant.", 404);
        }

        $counselorPerson = ($counselorUser['person_id'] ?? null)
            ? $this->personModel->scoped($tid)->find((int) $counselorUser['person_id'])
            : null;
        $counselorName = $counselorPerson
            ? trim(($counselorPerson['first_name'] ?? '') . ' ' . ($counselorPerson['last_name'] ?? ''))
            : (string) ($counselorUser['username'] ?? 'Counselor');

        // 2. Validate student person_id
        $personId = (int) ($data['person_id'] ?? 0);
        if ($personId <= 0) {
            throw new InvalidArgumentException('Valid student person_id is required.', 422);
        }

        $person = $this->personModel->scoped($tid)->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException("Student person #{$personId} not found in active tenant.", 404);
        }

        // 3. Validate call_date
        $callDate = trim((string) ($data['call_date'] ?? ''));
        if ($callDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $callDate) || !strtotime($callDate)) {
            throw new InvalidArgumentException('Valid call_date (YYYY-MM-DD) is required.', 422);
        }

        // 4. Validate call_time
        $callTime = trim((string) ($data['call_time'] ?? ''));
        if ($callTime === '' || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $callTime)) {
            throw new InvalidArgumentException('Valid call_time (HH:MM or HH:MM:SS) is required.', 422);
        }
        if (strlen($callTime) <= 5) {
            $callTime .= ':00';
        }

        // 5. Validate purpose
        $purpose = (string) ($data['purpose'] ?? '');
        if (!in_array($purpose, self::VALID_PURPOSES, true)) {
            throw new InvalidArgumentException("Invalid purpose '{$purpose}'. Allowed: " . implode(', ', self::VALID_PURPOSES), 422);
        }

        $purposeOther = isset($data['purpose_other']) ? trim((string) $data['purpose_other']) : null;
        if ($purpose === 'others' && ($purposeOther === null || $purposeOther === '')) {
            throw new InvalidArgumentException("purpose_other is required when purpose is 'others'.", 422);
        }

        // Optional parameters
        $episodeId = isset($data['episode_id']) && (int) $data['episode_id'] > 0 ? (int) $data['episode_id'] : null;
        $parentDelivery = !empty($data['parent_guardian_delivery']) ? 1 : 0;
        $deliveryNotes = isset($data['delivery_notes']) ? substr(trim((string) $data['delivery_notes']), 0, 500) : null;

        // 6. Generate sequential call slip number (CS-YYYYMMDD-XXXX)
        $callSlipNumber = $this->generateUniqueCallSlipNumber($callDate, $tid);

        // 7. Resolve student details for controlled form instance
        $studentName = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $studentMisId = (string) ($person['mis_id'] ?? '');

        $studentProfile = $this->studentProfileModel->scoped($tid)->where('person_id', $personId)->first();
        $courseYear = 'N/A';
        if ($studentProfile !== null) {
            $prog = (string) ($studentProfile['program'] ?? '');
            $yr = (string) ($studentProfile['year_level'] ?? '');
            $courseYear = trim($prog . ($yr !== '' ? ' - ' . $yr : ''));
        }
        if ($courseYear === '') {
            $courseYear = 'N/A';
        }

        // 8. Create controlled form instance for guidance_call_slip (F-OGC-GSP-CSP-007-00)
        $formDraftData = [
            'call_slip_number' => $callSlipNumber,
            'student_name' => $studentName,
            'student_id' => $studentMisId,
            'course_year' => $courseYear,
            'call_date' => $callDate,
            'call_time' => $callTime,
            'purpose' => $purpose,
            'issuing_counselor_name' => $counselorName,
        ];
        if ($purposeOther !== null && $purposeOther !== '') {
            $formDraftData['purpose_other'] = $purposeOther;
        }

        $formInstance = $this->formService->createDraft(
            $personId,
            'guidance_call_slip',
            $formDraftData,
            null,
            null,
            null,
            null,
            $authority
        );

        $formInstanceId = isset($formInstance['id']) ? (int) $formInstance['id'] : null;

        // 9. Insert call slip record
        $insertData = [
            'tenant_id' => $tid,
            'episode_id' => $episodeId,
            'person_id' => $personId,
            'issuing_counselor_user_id' => $counselorUserId,
            'call_slip_number' => $callSlipNumber,
            'call_date' => $callDate,
            'call_time' => $callTime,
            'purpose' => $purpose,
            'purpose_other' => $purposeOther,
            'attendance_status' => 'pending',
            'parent_guardian_delivery' => $parentDelivery,
            'delivery_notes' => $deliveryNotes,
            'form_instance_id' => $formInstanceId,
        ];

        $callSlipId = (int) $this->callSlipModel->insert($insertData);
        if ($callSlipId <= 0) {
            throw new DomainException('Failed to persist guidance call slip record.', 500);
        }

        // 10. Audit event
        $this->audit(
            'guidance.call_slip.issued',
            'guidance_call_slips',
            $callSlipId,
            $authority,
            [
                'call_slip_number' => $callSlipNumber,
                'person_id' => $personId,
                'issuing_counselor_user_id' => $counselorUserId,
                'attendance_status' => 'pending',
            ]
        );

        return $this->getCallSlip($callSlipId, $tid, $authority);
    }

    /**
     * Records call slip attendance status and captures acknowledged/attended timestamps.
     *
     * @param int $callSlipId
     * @param string $status
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function recordCallSlipStatus(
        int $callSlipId,
        string $status,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActor($actor, null);
        $tid = $tenantId ?? (int) $authority['tenant_id'];

        $slip = $this->callSlipModel->scoped($tid)->find($callSlipId);
        if ($slip === null) {
            throw new InvalidArgumentException("Call slip #{$callSlipId} not found.", 404);
        }

        $perms = $authority['permissions'] ?? [];
        $isTargetStudent = $status === 'acknowledged'
            && !empty($authority['person_id'])
            && (int) $authority['person_id'] === (int) $slip['person_id'];

        if (!$isTargetStudent
            && !in_array('guidance.call_slips.issue', $perms, true)
            && !in_array('guidance.appointments.manage', $perms, true)
            && !in_array('guidance.cases.write', $perms, true)) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid call slip status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES), 422);
        }

        $now = date('Y-m-d H:i:s');
        $updateData = [
            'attendance_status' => $status,
        ];

        if ($status === 'acknowledged' && empty($slip['acknowledged_at'])) {
            $updateData['acknowledged_at'] = $now;
        } elseif ($status === 'attended') {
            if (empty($slip['acknowledged_at'])) {
                $updateData['acknowledged_at'] = $now;
            }
            if (empty($slip['attended_at'])) {
                $updateData['attended_at'] = $now;
            }
        }

        $this->callSlipModel->update($callSlipId, $updateData);

        $this->audit(
            'guidance.call_slip.status_recorded',
            'guidance_call_slips',
            $callSlipId,
            $authority,
            [
                'previous_status' => $slip['attendance_status'],
                'new_status' => $status,
            ]
        );

        return $this->getCallSlip($callSlipId, $tid, $authority);
    }

    /**
     * Resolves call slip with student name, student MIS ID, course/year, and controlled form instance.
     *
     * @param int $callSlipId
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getCallSlip(int $callSlipId, ?int $tenantId = null, ?array $actor = null): array
    {
        $authority = $this->resolveActor($actor, null);
        $tid = $tenantId ?? (int) $authority['tenant_id'];

        $slip = $this->callSlipModel->scoped($tid)->find($callSlipId);
        if ($slip === null) {
            throw new InvalidArgumentException("Call slip #{$callSlipId} not found.", 404);
        }

        $perms = $authority['permissions'] ?? [];
        $isAssigned = ((int) $slip['issuing_counselor_user_id']) === ((int) $authority['id']);
        $isTargetStudent = !empty($authority['person_id']) && ((int) $authority['person_id']) === ((int) $slip['person_id']);
        if (!$isAssigned
            && !$isTargetStudent
            && !in_array('guidance.call_slips.issue', $perms, true)
            && !in_array('guidance.appointments.manage', $perms, true)
            && !in_array('guidance.cases.read', $perms, true)
            && !in_array('guidance.cases.read_any', $perms, true)) {
            throw new DomainException('Call slip access forbidden.', 403);
        }

        // Student details
        $person = $this->personModel->scoped($tid)->find((int) $slip['person_id']);
        $studentProfile = $this->studentProfileModel->scoped($tid)->where('person_id', (int) $slip['person_id'])->first();
        $prog = $studentProfile['program'] ?? '';
        $yr = $studentProfile['year_level'] ?? '';
        $courseYear = trim($prog . ($yr ? ' - ' . $yr : ''));
        if ($courseYear === '') {
            $courseYear = 'N/A';
        }

        $student = [
            'id' => (int) $slip['person_id'],
            'mis_id' => $person['mis_id'] ?? null,
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            'full_name' => $person ? trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')) : '',
            'email' => $person['email'] ?? null,
            'program' => $studentProfile['program'] ?? null,
            'year_level' => isset($studentProfile['year_level']) ? (int) $studentProfile['year_level'] : null,
            'section' => $studentProfile['section'] ?? null,
            'course_year' => $courseYear,
        ];

        // Counselor details
        $cUser = $this->userModel->scoped($tid)->find((int) $slip['issuing_counselor_user_id']);
        $cPerson = ($cUser['person_id'] ?? null)
            ? $this->personModel->scoped($tid)->find((int) $cUser['person_id'])
            : null;
        $counselor = [
            'id' => (int) $slip['issuing_counselor_user_id'],
            'username' => $cUser['username'] ?? null,
            'email' => $cUser['email'] ?? ($cPerson['email'] ?? null),
            'full_name' => $cPerson ? trim(($cPerson['first_name'] ?? '') . ' ' . ($cPerson['last_name'] ?? '')) : (string) ($cUser['username'] ?? ''),
            'role' => $cUser['role'] ?? null,
        ];

        // Controlled form instance details
        $formInstance = null;
        if (!empty($slip['form_instance_id'])) {
            try {
                $formInstance = $this->formService->getDraft((int) $slip['form_instance_id'], $authority);
            } catch (Throwable) {
                $formInstance = $this->db->table('guidance_form_instances')
                    ->where('id', (int) $slip['form_instance_id'])
                    ->where('tenant_id', $tid)
                    ->get()
                    ->getRowArray();
            }
        }

        return [
            'id' => (int) $slip['id'],
            'tenant_id' => (int) $slip['tenant_id'],
            'episode_id' => $slip['episode_id'] !== null ? (int) $slip['episode_id'] : null,
            'person_id' => (int) $slip['person_id'],
            'issuing_counselor_user_id' => (int) $slip['issuing_counselor_user_id'],
            'call_slip_number' => (string) $slip['call_slip_number'],
            'call_date' => (string) $slip['call_date'],
            'call_time' => (string) $slip['call_time'],
            'purpose' => (string) $slip['purpose'],
            'purpose_other' => $slip['purpose_other'],
            'attendance_status' => (string) $slip['attendance_status'],
            'parent_guardian_delivery' => (bool) $slip['parent_guardian_delivery'],
            'delivery_notes' => $slip['delivery_notes'],
            'form_instance_id' => $slip['form_instance_id'] !== null ? (int) $slip['form_instance_id'] : null,
            'acknowledged_at' => $slip['acknowledged_at'],
            'attended_at' => $slip['attended_at'],
            'student' => $student,
            'counselor' => $counselor,
            'form_instance' => $formInstance,
            'created_at' => $slip['created_at'],
            'updated_at' => $slip['updated_at'],
        ];
    }

    /**
     * Asserts practitioner eligibility (active profile, active standing, unexpired credentials, qualified tier).
     *
     * @param int $userId
     * @param int $tenantId
     * @return array<string, mixed>
     * @throws DomainException
     */
    public function assertPractitionerEligibility(int $userId, int $tenantId): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('guidance_practitioner_profiles'));
        $profile = $this->db->query(
            "SELECT * FROM {$table} WHERE tenant_id = ? AND user_id = ? AND is_active = 1",
            [$tenantId, $userId]
        )->getRowArray();

        if ($profile === null) {
            throw new DomainException("Professional eligibility required: active practitioner profile not found for user {$userId}.", 403);
        }

        if (($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Practitioner is not in active professional standing.', 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException('Practitioner credentials have expired.', 403);
        }

        $tier = (string) ($profile['credential_tier'] ?? '');
        if (!in_array($tier, self::QUALIFIED_TIERS, true)) {
            throw new DomainException("Practitioner tier '{$tier}' is not qualified to issue call slips.", 403);
        }

        return $profile;
    }

    /**
     * Generates a unique sequential call slip number with format CS-YYYYMMDD-XXXX.
     *
     * @param string $date
     * @param int $tenantId
     * @return string
     */
    private function generateUniqueCallSlipNumber(string $date, int $tenantId): string
    {
        $dateStr = date('Ymd', strtotime($date));
        $prefix = "CS-{$dateStr}-";

        $table = $this->db->protectIdentifiers($this->db->prefixTable('guidance_call_slips'));
        $last = $this->db->query(
            "SELECT call_slip_number FROM {$table} WHERE tenant_id = ? AND call_slip_number LIKE ? ORDER BY id DESC LIMIT 1",
            [$tenantId, $prefix . '%']
        )->getRowArray();

        $nextSeq = 1;
        if ($last !== null) {
            $numPart = (int) substr((string) $last['call_slip_number'], strlen($prefix));
            $nextSeq = $numPart + 1;
        }

        $candidate = sprintf('%s%04d', $prefix, $nextSeq);
        while ($this->callSlipModel->scoped($tenantId)->where('call_slip_number', $candidate)->first() !== null) {
            $nextSeq++;
            $candidate = sprintf('%s%04d', $prefix, $nextSeq);
        }

        return $candidate;
    }

    /**
     * Resolves actor using transport identity or explicit identity hint with fallback.
     *
     * @param array<string, mixed>|null $actor
     * @param string|null $permission
     * @return array<string, mixed>
     */
    private function resolveActor(?array $actor, ?string $permission = null): array
    {
        try {
            return $this->accessPolicy->resolve($actor, $permission, lock: false);
        } catch (DomainException $e) {
            if ($permission === null && $actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                $userRow = $this->userModel->scoped($tenantId)->find((int) $actor['id']);
                if ($userRow !== null
                    && ($userRow['status'] ?? '') === 'active'
                    && ($userRow['role'] ?? '') === 'student'
                    && !empty($userRow['person_id'])) {
                    return [
                        'id'          => (int) $userRow['id'],
                        'tenant_id'   => $tenantId,
                        'person_id'   => (int) $userRow['person_id'],
                        'role'        => 'student',
                        'permissions' => [],
                    ];
                }
            }
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                if ($permission === null) {
                    $userRow = $this->userModel->scoped($tenantId)->find((int) $actor['id']);
                    if ($userRow !== null
                        && ($userRow['status'] ?? '') === 'active'
                        && ($userRow['role'] ?? '') === 'student'
                        && !empty($userRow['person_id'])) {
                        return [
                            'id'          => (int) $userRow['id'],
                            'tenant_id'   => $tenantId,
                            'person_id'   => (int) $userRow['person_id'],
                            'role'        => 'student',
                            'permissions' => [],
                        ];
                    }
                }
                return $this->accessPolicy->authorize([
                    'id'        => (int) $actor['id'],
                    'tenant_id' => $tenantId,
                ], $permission, null, false);
            }
            throw new DomainException('An authenticated guidance account is required.', 403, $e);
        }
    }

    /**
     * Records an audit ledger event.
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
                (int) $actor['id'],
                (string) ($actor['role'] ?? 'system')
            );
        } catch (Throwable) {
            // Non-critical audit failure
        }
    }
}
