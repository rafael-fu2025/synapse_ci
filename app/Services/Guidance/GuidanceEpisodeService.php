<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceCallSlipModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceIntakeReferralModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\Audit\AuditService;
use App\Services\AuthenticatedUser;
use App\Services\Crypto\EncryptionService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service managing Guidance Episodes.
 *
 * An episode is the overarching operational container for a student's guidance journey
 * spanning referral -> intake -> appointments -> call slips -> counseling sessions -> evaluations.
 */
class GuidanceEpisodeService
{
    private GuidanceEpisodeModel $episodeModel;
    private GuidanceAppointmentModel $appointmentModel;
    private GuidanceCallSlipModel $callSlipModel;
    private GuidanceIntakeReferralModel $intakeReferralModel;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private GuidanceFormInstanceModel $formInstanceModel;
    private PersonModel $personModel;
    private UserModel $userModel;
    private GuidancePractitionerProfileModel $practitionerProfileModel;

    private GuidancePractitionerService $practitionerService;
    private GuidanceTransferService $transferService;
    private GuidanceAccessPolicy $accessPolicy;
    private EncryptionService $encryptionService;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceEpisodeModel $episodeModel = null,
        ?GuidanceAppointmentModel $appointmentModel = null,
        ?GuidanceCallSlipModel $callSlipModel = null,
        ?GuidanceIntakeReferralModel $intakeReferralModel = null,
        ?CounsellingCaseModel $caseModel = null,
        ?CounsellingSessionModel $sessionModel = null,
        ?GuidanceFormInstanceModel $formInstanceModel = null,
        ?PersonModel $personModel = null,
        ?UserModel $userModel = null,
        ?GuidancePractitionerProfileModel $practitionerProfileModel = null,
        ?GuidancePractitionerService $practitionerService = null,
        ?GuidanceTransferService $transferService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?EncryptionService $encryptionService = null,
        ?BaseConnection $db = null
    ) {
        $models = array_filter([
            $episodeModel,
            $appointmentModel,
            $callSlipModel,
            $intakeReferralModel,
            $caseModel,
            $sessionModel,
            $formInstanceModel,
            $personModel,
            $userModel,
            $practitionerProfileModel,
        ]);
        $this->db = $db ?? ($models !== [] ? reset($models)->db : Database::connect());

        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance models must share their transaction connection.');
            }
        }

        $this->episodeModel = $episodeModel ?? new GuidanceEpisodeModel($this->db);
        $this->appointmentModel = $appointmentModel ?? new GuidanceAppointmentModel($this->db);
        $this->callSlipModel = $callSlipModel ?? new GuidanceCallSlipModel($this->db);
        $this->intakeReferralModel = $intakeReferralModel ?? new GuidanceIntakeReferralModel($this->db);
        $this->caseModel = $caseModel ?? new CounsellingCaseModel($this->db);
        $this->sessionModel = $sessionModel ?? new CounsellingSessionModel($this->db);
        $this->formInstanceModel = $formInstanceModel ?? new GuidanceFormInstanceModel($this->db);
        $this->personModel = $personModel ?? new PersonModel($this->db);
        $this->userModel = $userModel ?? new UserModel($this->db);
        $this->practitionerProfileModel = $practitionerProfileModel ?? new GuidancePractitionerProfileModel($this->db);

        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = $practitionerService ?? new GuidancePractitionerService(
            profileModel: $this->practitionerProfileModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );
        $this->transferService = $transferService ?? new GuidanceTransferService(
            caseModel: $this->caseModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );
        $this->encryptionService = $encryptionService ?? new EncryptionService();
    }

    /**
     * Open a new guidance episode container for a person.
     *
     * @param int $personId Subject student/person ID
     * @param array<string, mixed> $data Configuration data, optional links, and optional actor hint
     * @param int|null $tenantId Tenant ID (defaults to resolved actor tenant)
     * @return array<string, mixed> Created guidance episode record
     */
    public function openEpisode(int $personId, array $data, ?int $tenantId = null): array
    {
        $actor = $data['actor'] ?? null;
        $authority = $this->resolveActor($actor);
        $tenantId = $tenantId !== null ? $tenantId : (int) ($authority['tenant_id'] ?? CurrentTenant::id());

        // Enforce permissions: guidance.cases.create or guidance.intake.manage (or guidance.intake.process)
        $permissions = $authority['permissions'] ?? [];
        $canCreate = in_array('guidance.cases.create', $permissions, true)
            || in_array('guidance.intake.manage', $permissions, true)
            || in_array('guidance.intake.process', $permissions, true);
        if (!$canCreate) {
            throw new DomainException('Guidance permission forbidden: requires guidance.cases.create or guidance.intake.manage.', 403);
        }

        // Validate person exists in same tenant
        $person = $this->personModel->scoped($tenantId)->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException("Person {$personId} not found in tenant {$tenantId}.", 404);
        }

        // Assign primary counselor and validate against GuidancePractitionerService::QUALIFIED_TIERS
        $counselorUserId = (int) ($data['primary_counselor_user_id'] ?? $data['counselor_user_id'] ?? $authority['id']);
        if ($counselorUserId <= 0) {
            throw new InvalidArgumentException('Primary counselor user ID must be a positive integer.', 422);
        }
        $this->assertQualifiedPractitioner($counselorUserId, $tenantId);

        // Optionally link intake_referral_id
        $intakeReferralId = !empty($data['intake_referral_id']) ? (int) $data['intake_referral_id'] : null;
        if ($intakeReferralId !== null) {
            $referral = $this->intakeReferralModel->scoped($tenantId)->find($intakeReferralId);
            if ($referral === null) {
                throw new InvalidArgumentException("Intake referral {$intakeReferralId} not found in tenant {$tenantId}.", 404);
            }
            if (!empty($referral['subject_person_id']) && (int) $referral['subject_person_id'] !== $personId) {
                throw new InvalidArgumentException('Intake referral subject does not match episode person.', 422);
            }
        }

        // Optionally link case_id
        $caseId = !empty($data['case_id']) ? (int) $data['case_id'] : null;
        if ($caseId !== null) {
            $case = $this->caseModel->scoped($tenantId)->find($caseId);
            if ($case === null) {
                throw new InvalidArgumentException("Counseling case {$caseId} not found in tenant {$tenantId}.", 404);
            }
            if (!empty($case['person_id']) && (int) $case['person_id'] !== $personId) {
                throw new InvalidArgumentException('Counseling case subject does not match episode person.', 422);
            }
        }

        $status = (string) ($data['status'] ?? 'open');
        if (!in_array($status, ['open', 'active'], true)) {
            $status = 'open';
        }
        $now = date('Y-m-d H:i:s');
        $openedAt = !empty($data['opened_at']) ? (string) $data['opened_at'] : $now;

        return $this->transaction(function () use ($tenantId, $personId, $intakeReferralId, $caseId, $status, $openedAt, $counselorUserId, $authority): array {
            $record = [
                'tenant_id' => $tenantId,
                'person_id' => $personId,
                'intake_referral_id' => $intakeReferralId,
                'case_id' => $caseId,
                'status' => $status,
                'opened_at' => $openedAt,
                'closed_at' => null,
                'primary_counselor_user_id' => $counselorUserId,
                'closure_reason' => null,
                'closure_notes_cipher' => null,
            ];

            $episodeId = $this->episodeModel->insert($record, true);
            if ($episodeId === false) {
                throw new RuntimeException('Failed to create guidance episode.', 500);
            }

            $created = $this->episodeModel->scoped($tenantId)->find((int) $episodeId);
            $this->audit('guidance.episode.opened', 'guidance_episodes', (int) $episodeId, $authority, [
                'person_id' => $personId,
                'primary_counselor_user_id' => $counselorUserId,
                'status' => $status,
            ]);

            return $created ?? [];
        });
    }

    /**
     * Retrieve full guidance episode details including linked referrals, appointments,
     * call slips, counseling case/sessions, and evaluations.
     *
     * @param int $episodeId Guidance episode ID
     * @param int|null $tenantId Optional tenant scope
     * @param array<string, mixed>|null $actor Optional caller identity hint
     * @return array<string, mixed> Comprehensive episode package
     */
    public function getEpisodeDetails(int $episodeId, ?int $tenantId = null, ?array $actor = null): array
    {
        $authority = $this->resolveActor($actor);
        $tenantId = $tenantId !== null ? $tenantId : (int) ($authority['tenant_id'] ?? CurrentTenant::id());

        $episode = $this->episodeModel->scoped($tenantId)->find($episodeId);
        if ($episode === null) {
            throw new InvalidArgumentException("Guidance episode {$episodeId} not found in tenant {$tenantId}.", 404);
        }

        // Enforce read access: guidance.cases.read_any OR assigned counselor OR supervisor
        $this->assertCanReadEpisode($authority, $episode, $tenantId);

        // Gather linked person
        $person = $this->personModel->scoped($tenantId)->find((int) $episode['person_id']);

        // Gather primary counselor
        $primaryCounselor = !empty($episode['primary_counselor_user_id'])
            ? $this->userModel->scoped($tenantId)->find((int) $episode['primary_counselor_user_id'])
            : null;

        // Gather linked intake referral
        $intakeReferral = null;
        if (!empty($episode['intake_referral_id'])) {
            $intakeReferral = $this->intakeReferralModel->scoped($tenantId)->find((int) $episode['intake_referral_id']);
        }
        if ($intakeReferral === null && !empty($episode['person_id'])) {
            $intakeReferral = $this->intakeReferralModel->scoped($tenantId)
                ->where('subject_person_id', (int) $episode['person_id'])
                ->orderBy('id', 'DESC')
                ->first();
        }

        // Gather appointments linked to episode
        $appointments = $this->appointmentModel->scoped($tenantId)
            ->where('episode_id', $episodeId)
            ->orderBy('scheduled_date', 'ASC')
            ->orderBy('scheduled_time', 'ASC')
            ->findAll();

        // Gather call slips linked to episode
        $callSlips = $this->callSlipModel->scoped($tenantId)
            ->where('episode_id', $episodeId)
            ->orderBy('call_date', 'ASC')
            ->orderBy('call_time', 'ASC')
            ->findAll();

        // Gather counseling case & sessions
        $case = null;
        if (!empty($episode['case_id'])) {
            $case = $this->caseModel->scoped($tenantId)->find((int) $episode['case_id']);
        }
        if ($case === null && !empty($episode['person_id'])) {
            $case = $this->caseModel->scoped($tenantId)
                ->where('person_id', (int) $episode['person_id'])
                ->orderBy('id', 'DESC')
                ->first();
        }

        $sessions = [];
        if ($case !== null && !empty($case['id'])) {
            $sessions = $this->sessionModel->scoped($tenantId)
                ->where('case_id', (int) $case['id'])
                ->orderBy('id', 'ASC')
                ->findAll();
        }

        // Gather evaluations / controlled form instances
        $evaluations = $this->formInstanceModel->scoped($tenantId)
            ->where('person_id', (int) $episode['person_id'])
            ->orderBy('id', 'DESC')
            ->findAll();

        $decryptedClosureNotes = null;
        if (!empty($episode['closure_notes_cipher'])) {
            $decryptedClosureNotes = $this->decryptClosureNotesPayload((string) $episode['closure_notes_cipher']);
        }

        return array_merge($episode, [
            'episode'             => $episode,
            'person'              => $person,
            'primary_counselor'   => $primaryCounselor,
            'intake_referral'     => $intakeReferral,
            'appointments'        => $appointments,
            'call_slips'          => $callSlips,
            'case'                => $case,
            'counseling_case'     => $case,
            'sessions'            => $sessions,
            'counseling_sessions' => $sessions,
            'evaluations'         => $evaluations,
            'closure_notes'       => $decryptedClosureNotes,
        ]);
    }

    /**
     * Close a guidance episode with validated closure reason and encrypted notes.
     *
     * @param int $episodeId Guidance episode ID
     * @param string $closureReason Reason for closing episode
     * @param string|null $closureNotes Optional confidential closure notes
     * @param int|null $tenantId Optional tenant scope
     * @param array<string, mixed>|null $actor Optional caller identity hint
     * @return array<string, mixed> Updated guidance episode record
     */
    public function closeEpisode(
        int $episodeId,
        string $closureReason,
        ?string $closureNotes = null,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActor($actor);
        $tenantId = $tenantId !== null ? $tenantId : (int) ($authority['tenant_id'] ?? CurrentTenant::id());

        $episode = $this->episodeModel->scoped($tenantId)->find($episodeId);
        if ($episode === null) {
            throw new InvalidArgumentException("Guidance episode {$episodeId} not found in tenant {$tenantId}.", 404);
        }

        if ($episode['status'] === 'closed') {
            throw new DomainException('Episode is already closed.', 409);
        }

        // Enforce assigned counselor or supervisor permission
        $this->assertCanCloseEpisode($authority, $episode, $tenantId);

        // Validate closure reason
        $closureReason = trim($closureReason);
        if ($closureReason === '') {
            throw new InvalidArgumentException('Closure reason is required.', 422);
        }
        if (mb_strlen($closureReason) > 255) {
            throw new InvalidArgumentException('Closure reason must not exceed 255 characters.', 422);
        }

        // Encrypt closure notes if provided
        $closureNotesCipher = null;
        if ($closureNotes !== null && trim($closureNotes) !== '') {
            $encrypted = $this->encryptionService->encrypt(trim($closureNotes));
            $closureNotesCipher = json_encode([
                'cipher'      => base64_encode($encrypted['cipher']),
                'nonce'       => base64_encode($encrypted['nonce']),
                'tag'         => base64_encode($encrypted['tag']),
                'key_version' => $encrypted['key_version'],
            ], JSON_THROW_ON_ERROR);
        }

        return $this->transaction(function () use ($episodeId, $closureReason, $closureNotesCipher, $authority, $tenantId): array {
            $now = date('Y-m-d H:i:s');
            $this->episodeModel->update($episodeId, [
                'status'               => 'closed',
                'closed_at'            => $now,
                'closure_reason'       => $closureReason,
                'closure_notes_cipher' => $closureNotesCipher,
                'updated_at'           => $now,
            ]);

            $this->audit('guidance.episode.closed', 'guidance_episodes', $episodeId, $authority, [
                'closure_reason' => $closureReason,
                'closed_at'      => $now,
            ]);

            return $this->episodeModel->scoped($tenantId)->find($episodeId) ?? [];
        });
    }

    /**
     * Transfer guidance episode to a new primary counselor, validating qualified tiers
     * and coordinating with GuidanceTransferService.
     *
     * @param int $episodeId Guidance episode ID
     * @param int $newCounselorUserId New assigned primary counselor user ID
     * @param string $reason Reason for transferring episode
     * @param int|null $tenantId Optional tenant scope
     * @param array<string, mixed>|null $actor Optional caller identity hint
     * @return array<string, mixed> Updated guidance episode record
     */
    public function transferEpisode(
        int $episodeId,
        int $newCounselorUserId,
        string $reason,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActor($actor);
        $tenantId = $tenantId !== null ? $tenantId : (int) ($authority['tenant_id'] ?? CurrentTenant::id());

        $episode = $this->episodeModel->scoped($tenantId)->find($episodeId);
        if ($episode === null) {
            throw new InvalidArgumentException("Guidance episode {$episodeId} not found in tenant {$tenantId}.", 404);
        }

        if ($episode['status'] === 'closed') {
            throw new DomainException('Cannot transfer a closed episode.', 409);
        }

        // Enforce transfer authority (assigned counselor or supervisor/admin)
        $this->assertCanTransferEpisode($authority, $episode, $tenantId);

        // Cannot transfer to self / same counselor
        if ((int) ($episode['primary_counselor_user_id'] ?? 0) === $newCounselorUserId) {
            throw new InvalidArgumentException('Cannot transfer episode to the currently assigned counselor.', 422);
        }

        // Validate target counselor satisfies qualified tiers
        $this->assertQualifiedPractitioner($newCounselorUserId, $tenantId);

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Transfer reason is required.', 422);
        }

        return $this->transaction(function () use ($episode, $episodeId, $newCounselorUserId, $reason, $authority, $tenantId): array {
            $fromCounselorId = (int) ($episode['primary_counselor_user_id'] ?? 0);
            $caseId = !empty($episode['case_id']) ? (int) $episode['case_id'] : null;

            // Coordinate with GuidanceTransferService to log transfer and update linked case
            $this->transferService->logEpisodeTransfer($episodeId, $fromCounselorId, $newCounselorUserId, $reason, $authority, $caseId);

            $now = date('Y-m-d H:i:s');
            $this->episodeModel->update($episodeId, [
                'primary_counselor_user_id' => $newCounselorUserId,
                'status'                    => 'active',
                'updated_at'                => $now,
            ]);

            return $this->episodeModel->scoped($tenantId)->find($episodeId) ?? [];
        });
    }

    /**
     * Decrypt confidential closure notes for an episode.
     *
     * @param array<string, mixed>|int $episode Episode row or ID
     * @param array<string, mixed>|null $actor Optional caller identity hint
     * @param int|null $tenantId Optional tenant scope
     * @return string|null Plaintext closure notes or null
     */
    public function getDecryptedClosureNotes(array|int $episode, ?array $actor = null, ?int $tenantId = null): ?string
    {
        if (is_int($episode)) {
            $details = $this->getEpisodeDetails($episode, $tenantId, $actor);
            return $details['closure_notes'] ?? null;
        }

        if (empty($episode['closure_notes_cipher'])) {
            return null;
        }

        return $this->decryptClosureNotesPayload((string) $episode['closure_notes_cipher']);
    }

    /**
     * Asserts that a user satisfies GuidancePractitionerService::QUALIFIED_TIERS,
     * has an active profile, active professional standing, and unexpired credentials.
     *
     * @param int $userId Practitioner user ID
     * @param int $tenantId Tenant ID
     * @return array<string, mixed> Practitioner profile record
     */
    public function assertQualifiedPractitioner(int $userId, int $tenantId): array
    {
        $user = $this->userModel->scoped($tenantId)
            ->where('id', $userId)
            ->where('status', 'active')
            ->first();
        if ($user === null) {
            throw new InvalidArgumentException("User {$userId} not found in tenant {$tenantId}.", 404);
        }

        $profile = $this->practitionerProfileModel->scoped($tenantId)
            ->where('user_id', $userId)
            ->first();
        if ($profile === null) {
            throw new DomainException("Practitioner profile not found for user {$userId}.", 403);
        }

        if ((int) ($profile['is_active'] ?? 0) !== 1 || ($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException("Practitioner {$userId} is not in active professional standing.", 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException("Practitioner {$userId} credentials have expired.", 403);
        }

        $tier = (string) ($profile['credential_tier'] ?? '');
        if (!in_array($tier, GuidancePractitionerService::QUALIFIED_TIERS, true)) {
            throw new DomainException("Practitioner tier '{$tier}' does not satisfy qualified tiers.", 403);
        }

        return $profile;
    }

    /**
     * Decrypt an encrypted closure notes JSON payload.
     */
    private function decryptClosureNotesPayload(string $cipherPayload): ?string
    {
        try {
            $decoded = json_decode($cipherPayload, true);
            if (is_array($decoded) && isset($decoded['cipher'], $decoded['nonce'], $decoded['tag'], $decoded['key_version'])) {
                return $this->encryptionService->decrypt(
                    base64_decode((string) $decoded['cipher'], true),
                    base64_decode((string) $decoded['nonce'], true),
                    base64_decode((string) $decoded['tag'], true),
                    (int) $decoded['key_version']
                );
            }
        } catch (Throwable) {
            // Decryption failure falls through to null
        }

        return null;
    }

    /**
     * Enforce read access on an episode.
     */
    private function assertCanReadEpisode(array $authority, array $episode, int $tenantId): void
    {
        $actorId = (int) ($authority['id'] ?? 0);
        $primaryCounselorId = (int) ($episode['primary_counselor_user_id'] ?? 0);

        if ($actorId > 0 && $actorId === $primaryCounselorId) {
            return;
        }

        if (in_array('guidance.cases.read_any', $authority['permissions'] ?? [], true)) {
            return;
        }

        $role = (string) ($authority['role'] ?? '');
        if (in_array($role, ['guidance_supervisor', 'guidance_admin', 'superadmin'], true)) {
            return;
        }

        if ($primaryCounselorId > 0) {
            $supAssignment = $this->db->table('guidance_supervision_assignments')
                ->where('tenant_id', $tenantId)
                ->where('associate_user_id', $primaryCounselorId)
                ->where('supervisor_user_id', $actorId)
                ->where('status', 'active')
                ->get()->getRowArray();
            if ($supAssignment !== null) {
                return;
            }
        }

        throw new DomainException('Episode access forbidden: actor is neither the assigned counselor nor authorized supervisor.', 403);
    }

    /**
     * Enforce closure access on an episode.
     */
    private function assertCanCloseEpisode(array $authority, array $episode, int $tenantId): void
    {
        $actorId = (int) ($authority['id'] ?? 0);
        $primaryCounselorId = (int) ($episode['primary_counselor_user_id'] ?? 0);

        if ($actorId > 0 && $actorId === $primaryCounselorId) {
            return;
        }

        $role = (string) ($authority['role'] ?? '');
        if (in_array($role, ['guidance_supervisor', 'guidance_admin', 'superadmin'], true)) {
            return;
        }

        $permissions = $authority['permissions'] ?? [];
        if (in_array('guidance.cases.reassign', $permissions, true)) {
            return;
        }

        if ($primaryCounselorId > 0) {
            $supAssignment = $this->db->table('guidance_supervision_assignments')
                ->where('tenant_id', $tenantId)
                ->where('associate_user_id', $primaryCounselorId)
                ->where('supervisor_user_id', $actorId)
                ->where('status', 'active')
                ->get()->getRowArray();
            if ($supAssignment !== null) {
                return;
            }
        }

        throw new DomainException('Episode closure forbidden: only assigned counselor or supervisor may close an episode.', 403);
    }

    /**
     * Enforce transfer access on an episode.
     */
    private function assertCanTransferEpisode(array $authority, array $episode, int $tenantId): void
    {
        $actorId = (int) ($authority['id'] ?? 0);
        $primaryCounselorId = (int) ($episode['primary_counselor_user_id'] ?? 0);

        if ($actorId > 0 && $actorId === $primaryCounselorId) {
            return;
        }

        $permissions = $authority['permissions'] ?? [];
        if (in_array('guidance.cases.reassign', $permissions, true)) {
            return;
        }

        $role = (string) ($authority['role'] ?? '');
        if (in_array($role, ['guidance_supervisor', 'guidance_admin', 'superadmin'], true)) {
            return;
        }

        if ($primaryCounselorId > 0) {
            $supAssignment = $this->db->table('guidance_supervision_assignments')
                ->where('tenant_id', $tenantId)
                ->where('associate_user_id', $primaryCounselorId)
                ->where('supervisor_user_id', $actorId)
                ->where('status', 'active')
                ->get()->getRowArray();
            if ($supAssignment !== null) {
                return;
            }
        }

        throw new DomainException('Episode transfer forbidden: actor is not authorized to transfer this episode.', 403);
    }

    /**
     * Resolve actor identity authority.
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
                    'id' => (int) $actor['id'],
                    'tenant_id' => $tenantId,
                ], $permission, $expectedActorId, $lock);
            }

            $current = (new AuthenticatedUser())->current();
            if ($current !== null && isset($current['id'])) {
                $tenantId = (int) ($current['tenant_id'] ?? CurrentTenant::id());
                return $this->accessPolicy->authorize([
                    'id' => (int) $current['id'],
                    'tenant_id' => $tenantId,
                ], $permission, $expectedActorId, $lock);
            }

            throw $e;
        }
    }

    /**
     * Audit log helper.
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
                (int) ($actor['id'] ?? 1),
                (string) ($actor['role'] ?? 'system')
            );
        } catch (Throwable) {
            // Non-critical audit logging failure
        }
    }

    /**
     * Execute callback in database transaction.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation)
    {
        $ownsTransaction = false;
        if ($this->db->transDepth === 0) {
            $this->db->transBegin();
            $ownsTransaction = true;
        }

        try {
            $result = $operation();
            if (!$this->db->transStatus() || ($ownsTransaction && !$this->db->transCommit())) {
                throw new RuntimeException('Unable to commit guidance episode transaction.', 503);
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
}
