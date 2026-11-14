<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceAppointmentModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceAppointmentService
{
    public const VALID_PURPOSES = [
        'routine_interview',
        'counseling',
        'follow_up',
        'intake',
        'inquiry',
        'others',
    ];

    public const VALID_STATUSES = [
        'scheduled',
        'confirmed',
        'attended',
        'cancelled',
        'rescheduled',
        'no_show',
    ];

    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormService $formService;
    private PersonModel $personModel;
    private StudentProfileModel $studentProfileModel;
    private UserModel $userModel;
    private EncryptionService $encryptionService;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceAppointmentModel $appointmentModel = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?GuidanceFormService $formService = null,
        ?PersonModel $personModel = null,
        ?StudentProfileModel $studentProfileModel = null,
        ?UserModel $userModel = null,
        ?EncryptionService $encryptionService = null,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->appointmentModel = $appointmentModel ?? new GuidanceAppointmentModel($this->db);
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->formService = $formService ?? new GuidanceFormService(accessPolicy: $this->accessPolicy, db: $this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->studentProfileModel = $studentProfileModel ?? new StudentProfileModel($this->db);
        $this->userModel = $userModel ?? new UserModel($this->db);
        $this->encryptionService = $encryptionService ?? new EncryptionService();
    }

    /**
     * Schedules a guidance appointment, instantiates the controlled form instance, and binds them.
     *
     * @param array<string, mixed> $data
     * @param int|null $tenantId
     * @return array<string, mixed>
     */
    public function scheduleAppointment(array $data, ?int $tenantId = null): array
    {
        $actor = $data['actor'] ?? null;
        $authority = $this->resolveActor($actor, 'guidance.appointments.manage');
        $tid = $tenantId ?? (int) $authority['tenant_id'];

        // 1. Validate student person_id
        $personId = (int) ($data['person_id'] ?? 0);
        if ($personId <= 0) {
            throw new InvalidArgumentException('Valid student person_id is required.', 422);
        }

        $person = $this->personModel->scoped($tid)->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException("Student person #{$personId} not found in active tenant.", 404);
        }

        // 2. Validate scheduled_date
        $scheduledDate = trim((string) ($data['scheduled_date'] ?? ''));
        if ($scheduledDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduledDate) || !strtotime($scheduledDate)) {
            throw new InvalidArgumentException('Valid scheduled_date (YYYY-MM-DD) is required.', 422);
        }

        // 3. Validate scheduled_time
        $scheduledTime = trim((string) ($data['scheduled_time'] ?? ''));
        if ($scheduledTime === '' || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $scheduledTime)) {
            throw new InvalidArgumentException('Valid scheduled_time (HH:MM or HH:MM:SS) is required.', 422);
        }
        if (strlen($scheduledTime) <= 5) {
            $scheduledTime .= ':00';
        }

        // 4. Validate purpose
        $purpose = (string) ($data['purpose'] ?? '');
        if (!in_array($purpose, self::VALID_PURPOSES, true)) {
            throw new InvalidArgumentException("Invalid purpose '{$purpose}'. Allowed: " . implode(', ', self::VALID_PURPOSES), 422);
        }

        $purposeOther = isset($data['purpose_other']) ? trim((string) $data['purpose_other']) : null;
        if ($purpose === 'others' && ($purposeOther === null || $purposeOther === '')) {
            throw new InvalidArgumentException("purpose_other is required when purpose is 'others'.", 422);
        }

        // 5. Counselor resolution
        $counselorUserId = (int) ($data['counselor_user_id'] ?? $authority['id']);
        $counselor = $this->userModel->scoped($tid)->find($counselorUserId);
        if ($counselor === null) {
            throw new InvalidArgumentException("Counselor user #{$counselorUserId} not found in tenant.", 404);
        }

        $counselorPerson = ($counselor['person_id'] ?? null)
            ? $this->personModel->scoped($tid)->find((int) $counselor['person_id'])
            : null;
        $counselorName = $counselorPerson
            ? trim(($counselorPerson['first_name'] ?? '') . ' ' . ($counselorPerson['last_name'] ?? ''))
            : (string) ($counselor['username'] ?? 'Counselor');

        // Optional links
        $episodeId = isset($data['episode_id']) && (int) $data['episode_id'] > 0 ? (int) $data['episode_id'] : null;
        $queueTicketId = isset($data['queue_ticket_id']) && (int) $data['queue_ticket_id'] > 0 ? (int) $data['queue_ticket_id'] : null;

        // Notes encryption
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;
        $notesCipher = null;
        if ($notes !== null && $notes !== '') {
            $enc = $this->encryptionService->encrypt($notes);
            $notesCipher = json_encode([
                'cipher'      => base64_encode($enc['cipher']),
                'nonce'       => base64_encode($enc['nonce']),
                'tag'         => base64_encode($enc['tag']),
                'key_version' => $enc['key_version'],
            ], JSON_THROW_ON_ERROR);
        }

        // 6. Generate sequential appointment number (APT-YYYYMMDD-XXXX)
        $appointmentNumber = $this->generateUniqueAppointmentNumber($scheduledDate, $tid);

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

        // 8. Create controlled form instance for guidance_appointment_form (F-OGC-GAP-006-00)
        $formDraftData = [
            'appointment_number' => $appointmentNumber,
            'student_name' => $studentName,
            'student_id' => $studentMisId,
            'course_year' => $courseYear,
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'counselor_name' => $counselorName,
            'purpose' => $purpose,
        ];
        if ($purposeOther !== null && $purposeOther !== '') {
            $formDraftData['purpose_other'] = $purposeOther;
        }

        $formInstance = $this->formService->createDraft(
            $personId,
            'guidance_appointment_form',
            $formDraftData,
            null,
            null,
            null,
            null,
            $authority
        );

        $formInstanceId = isset($formInstance['id']) ? (int) $formInstance['id'] : null;

        // 9. Insert appointment record
        $insertData = [
            'tenant_id' => $tid,
            'episode_id' => $episodeId,
            'person_id' => $personId,
            'counselor_user_id' => $counselorUserId,
            'appointment_number' => $appointmentNumber,
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'purpose' => $purpose,
            'purpose_other' => $purposeOther,
            'status' => 'scheduled',
            'form_instance_id' => $formInstanceId,
            'queue_ticket_id' => $queueTicketId,
            'notes_cipher' => $notesCipher,
        ];

        $appointmentId = (int) $this->appointmentModel->insert($insertData);
        if ($appointmentId <= 0) {
            throw new DomainException('Failed to persist guidance appointment record.', 500);
        }

        // 10. Audit event
        $this->audit(
            'guidance.appointment.scheduled',
            'guidance_appointments',
            $appointmentId,
            $authority,
            [
                'appointment_number' => $appointmentNumber,
                'status' => 'scheduled',
                'person_id' => $personId,
                'counselor_user_id' => $counselorUserId,
            ]
        );

        return $this->getAppointment($appointmentId, $tid, $authority);
    }

    /**
     * Updates appointment status and records status history audit.
     *
     * @param int $appointmentId
     * @param string $status
     * @param string|null $notes
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function updateAppointmentStatus(
        int $appointmentId,
        string $status,
        ?string $notes = null,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActor($actor, 'guidance.appointments.manage');
        $tid = $tenantId ?? (int) $authority['tenant_id'];

        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid appointment status '{$status}'. Allowed: " . implode(', ', self::VALID_STATUSES), 422);
        }

        $apt = $this->appointmentModel->scoped($tid)->find($appointmentId);
        if ($apt === null) {
            throw new InvalidArgumentException("Appointment #{$appointmentId} not found.", 404);
        }

        $updateData = [
            'status' => $status,
        ];

        if ($notes !== null && $notes !== '') {
            $enc = $this->encryptionService->encrypt($notes);
            $updateData['notes_cipher'] = json_encode([
                'cipher'      => base64_encode($enc['cipher']),
                'nonce'       => base64_encode($enc['nonce']),
                'tag'         => base64_encode($enc['tag']),
                'key_version' => $enc['key_version'],
            ], JSON_THROW_ON_ERROR);
        }

        $this->appointmentModel->update($appointmentId, $updateData);

        $this->audit(
            'guidance.appointment.status_updated',
            'guidance_appointments',
            $appointmentId,
            $authority,
            [
                'previous_status' => $apt['status'],
                'new_status' => $status,
            ]
        );

        return $this->getAppointment($appointmentId, $tid, $authority);
    }

    /**
     * Retrieves appointment by ID with student details, counselor details, and controlled form instance.
     *
     * @param int $appointmentId
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getAppointment(int $appointmentId, ?int $tenantId = null, ?array $actor = null): array
    {
        $authority = $this->resolveActor($actor, null);
        $tid = $tenantId ?? (int) $authority['tenant_id'];

        $apt = $this->appointmentModel->scoped($tid)->find($appointmentId);
        if ($apt === null) {
            throw new InvalidArgumentException("Appointment #{$appointmentId} not found.", 404);
        }

        $perms = $authority['permissions'] ?? [];
        $isAssigned = ((int) $apt['counselor_user_id']) === ((int) $authority['id']);
        if (!$isAssigned
            && !in_array('guidance.appointments.manage', $perms, true)
            && !in_array('guidance.cases.read', $perms, true)
            && !in_array('guidance.cases.read_any', $perms, true)) {
            throw new DomainException('Appointment access forbidden.', 403);
        }

        // Student details
        $person = $this->personModel->scoped($tid)->find((int) $apt['person_id']);
        $studentProfile = $this->studentProfileModel->scoped($tid)->where('person_id', (int) $apt['person_id'])->first();
        $student = [
            'id' => (int) $apt['person_id'],
            'mis_id' => $person['mis_id'] ?? null,
            'first_name' => $person['first_name'] ?? null,
            'last_name' => $person['last_name'] ?? null,
            'full_name' => $person ? trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? '')) : '',
            'email' => $person['email'] ?? null,
            'program' => $studentProfile['program'] ?? null,
            'year_level' => isset($studentProfile['year_level']) ? (int) $studentProfile['year_level'] : null,
            'section' => $studentProfile['section'] ?? null,
            'department' => $studentProfile['department'] ?? null,
        ];

        // Counselor details
        $cUser = $this->userModel->scoped($tid)->find((int) $apt['counselor_user_id']);
        $cPerson = ($cUser['person_id'] ?? null)
            ? $this->personModel->scoped($tid)->find((int) $cUser['person_id'])
            : null;
        $counselor = [
            'id' => (int) $apt['counselor_user_id'],
            'username' => $cUser['username'] ?? null,
            'email' => $cUser['email'] ?? ($cPerson['email'] ?? null),
            'full_name' => $cPerson ? trim(($cPerson['first_name'] ?? '') . ' ' . ($cPerson['last_name'] ?? '')) : (string) ($cUser['username'] ?? ''),
            'role' => $cUser['role'] ?? null,
        ];

        // Controlled form instance details
        $formInstance = null;
        if (!empty($apt['form_instance_id'])) {
            try {
                $formInstance = $this->formService->getDraft((int) $apt['form_instance_id'], $authority);
            } catch (Throwable) {
                $formInstance = $this->db->table('guidance_form_instances')
                    ->where('id', (int) $apt['form_instance_id'])
                    ->where('tenant_id', $tid)
                    ->get()
                    ->getRowArray();
            }
        }

        // Decrypt notes if present
        $notes = null;
        if (!empty($apt['notes_cipher'])) {
            try {
                $payload = json_decode((string) $apt['notes_cipher'], true);
                if (is_array($payload) && isset($payload['cipher'], $payload['nonce'], $payload['tag'])) {
                    $notes = $this->encryptionService->decrypt(
                        base64_decode((string) $payload['cipher']),
                        base64_decode((string) $payload['nonce']),
                        base64_decode((string) $payload['tag']),
                        (int) ($payload['key_version'] ?? 1)
                    );
                } else {
                    $notes = (string) $apt['notes_cipher'];
                }
            } catch (Throwable) {
                $notes = null;
            }
        }

        return [
            'id' => (int) $apt['id'],
            'tenant_id' => (int) $apt['tenant_id'],
            'episode_id' => $apt['episode_id'] !== null ? (int) $apt['episode_id'] : null,
            'person_id' => (int) $apt['person_id'],
            'counselor_user_id' => (int) $apt['counselor_user_id'],
            'appointment_number' => (string) $apt['appointment_number'],
            'scheduled_date' => (string) $apt['scheduled_date'],
            'scheduled_time' => (string) $apt['scheduled_time'],
            'purpose' => (string) $apt['purpose'],
            'purpose_other' => $apt['purpose_other'],
            'status' => (string) $apt['status'],
            'form_instance_id' => $apt['form_instance_id'] !== null ? (int) $apt['form_instance_id'] : null,
            'queue_ticket_id' => $apt['queue_ticket_id'] !== null ? (int) $apt['queue_ticket_id'] : null,
            'notes' => $notes,
            'student' => $student,
            'counselor' => $counselor,
            'form_instance' => $formInstance,
            'created_at' => $apt['created_at'],
            'updated_at' => $apt['updated_at'],
        ];
    }

    /**
     * Generates a unique sequential appointment number with format APT-YYYYMMDD-XXXX.
     *
     * @param string $date
     * @param int $tenantId
     * @return string
     */
    private function generateUniqueAppointmentNumber(string $date, int $tenantId): string
    {
        $dateStr = date('Ymd', strtotime($date));
        $prefix = "APT-{$dateStr}-";

        $table = $this->db->protectIdentifiers($this->db->prefixTable('guidance_appointments'));
        $last = $this->db->query(
            "SELECT appointment_number FROM {$table} WHERE tenant_id = ? AND appointment_number LIKE ? ORDER BY id DESC LIMIT 1",
            [$tenantId, $prefix . '%']
        )->getRowArray();

        $nextSeq = 1;
        if ($last !== null) {
            $numPart = (int) substr((string) $last['appointment_number'], strlen($prefix));
            $nextSeq = $numPart + 1;
        }

        $candidate = sprintf('%s%04d', $prefix, $nextSeq);
        while ($this->appointmentModel->scoped($tenantId)->where('appointment_number', $candidate)->first() !== null) {
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
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
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
