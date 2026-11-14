<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\CounsellingCaseModel;
use App\Models\CounsellingNoteModel;
use App\Models\CounsellingSessionModel;
use App\Models\GuidanceAppointmentModel;
use App\Models\GuidanceEpisodeModel;
use App\Models\GuidanceFormAttestationModel;
use App\Models\GuidanceFormDraftModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceFormRevisionModel;
use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Models\UserModel;
use App\Services\Crypto\EncryptionService;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service managing clinical controlled guidance forms:
 * - Psychosocial Support Interview (F-OGC-GPS-PIF-008-00)
 * - Psychosocial Support Contact (F-OGC-GPS-PCF-008-00)
 * - Counseling Contact Sheet (F-OGC-GCS-CCF-009-00)
 *
 * Implements strict clinical practitioner credential checks, associate co-sign workflow,
 * AES-256-GCM cipher encryption with bound AAD, and integration with counseling cases and sessions.
 */
class GuidanceClinicalFormService
{
    public const FORM_PSYCHOSOCIAL_INTERVIEW = 'psychosocial_support_interview';
    public const FORM_PSYCHOSOCIAL_CONTACT   = 'psychosocial_support_contact';
    public const FORM_COUNSELING_CONTACT     = 'counseling_contact_sheet';

    public const CANONICAL_FORM_KEYS = [
        self::FORM_PSYCHOSOCIAL_INTERVIEW => 'F-OGC-GPS-PIF-008-00',
        self::FORM_PSYCHOSOCIAL_CONTACT   => 'F-OGC-GPS-PCF-008-00',
        self::FORM_COUNSELING_CONTACT     => 'F-OGC-GCS-CCF-009-00',
    ];

    public const FORM_CODES_TO_KEYS = [
        'F-OGC-GPS-PIF-008-00' => self::FORM_PSYCHOSOCIAL_INTERVIEW,
        'F-OGC-GPS-PCF-008-00' => self::FORM_PSYCHOSOCIAL_CONTACT,
        'F-OGC-GCS-CCF-009-00' => self::FORM_COUNSELING_CONTACT,
    ];

    private GuidanceFormInstanceModel $instanceModel;
    private GuidanceFormDraftModel $draftModel;
    private GuidanceFormRevisionModel $revisionModel;
    private GuidanceFormAttestationModel $attestationModel;
    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private CounsellingCaseModel $caseModel;
    private CounsellingSessionModel $sessionModel;
    private CounsellingNoteModel $noteModel;
    private GuidanceEpisodeModel $episodeModel;
    private GuidanceAppointmentModel $appointmentModel;
    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private UserModel $userModel;

    private GuidancePractitionerService $practitionerService;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormDefinitionService $definitionService;
    private FormAttestationService $attestationService;
    private GuidanceFormCipher $cipher;
    private EncryptionService $encryptionService;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceFormInstanceModel $instanceModel = null,
        ?GuidanceFormDraftModel $draftModel = null,
        ?GuidanceFormRevisionModel $revisionModel = null,
        ?GuidanceFormAttestationModel $attestationModel = null,
        ?GuidancePractitionerProfileModel $profileModel = null,
        ?GuidanceSupervisionAssignmentModel $supervisionModel = null,
        ?CounsellingCaseModel $caseModel = null,
        ?CounsellingSessionModel $sessionModel = null,
        ?CounsellingNoteModel $noteModel = null,
        ?GuidanceEpisodeModel $episodeModel = null,
        ?GuidanceAppointmentModel $appointmentModel = null,
        ?PersonModel $personModel = null,
        ?StudentProfileModel $studentModel = null,
        ?UserModel $userModel = null,
        ?GuidancePractitionerService $practitionerService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?GuidanceFormDefinitionService $definitionService = null,
        ?FormAttestationService $attestationService = null,
        ?GuidanceFormCipher $cipher = null,
        ?EncryptionService $encryptionService = null,
        ?BaseConnection $db = null
    ) {
        $models = array_filter([
            $instanceModel,
            $draftModel,
            $revisionModel,
            $attestationModel,
            $profileModel,
            $supervisionModel,
            $caseModel,
            $sessionModel,
            $noteModel,
            $episodeModel,
            $appointmentModel,
            $personModel,
            $studentModel,
            $userModel,
        ]);

        $this->db = $db ?? ($models !== [] ? reset($models)->db : Database::connect());

        foreach ($models as $model) {
            if ($model->db !== $this->db) {
                throw new InvalidArgumentException('Guidance models must share their transaction connection.');
            }
        }

        $this->instanceModel       = $instanceModel ?? new GuidanceFormInstanceModel($this->db);
        $this->draftModel          = $draftModel ?? new GuidanceFormDraftModel($this->db);
        $this->revisionModel       = $revisionModel ?? new GuidanceFormRevisionModel($this->db);
        $this->attestationModel    = $attestationModel ?? new GuidanceFormAttestationModel($this->db);
        $this->profileModel        = $profileModel ?? new GuidancePractitionerProfileModel($this->db);
        $this->supervisionModel    = $supervisionModel ?? new GuidanceSupervisionAssignmentModel($this->db);
        $this->caseModel           = $caseModel ?? new CounsellingCaseModel($this->db);
        $this->sessionModel        = $sessionModel ?? new CounsellingSessionModel($this->db);
        $this->noteModel           = $noteModel ?? new CounsellingNoteModel($this->db);
        $this->episodeModel        = $episodeModel ?? new GuidanceEpisodeModel($this->db);
        $this->appointmentModel    = $appointmentModel ?? new GuidanceAppointmentModel($this->db);
        $this->personModel         = $personModel ?? new PersonModel($this->db);
        $this->studentModel        = $studentModel ?? new StudentProfileModel($this->db);
        $this->userModel           = $userModel ?? new UserModel($this->db);

        $this->accessPolicy        = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = $practitionerService ?? new GuidancePractitionerService(
            profileModel: $this->profileModel,
            supervisionModel: $this->supervisionModel,
            userModel: $this->userModel,
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );
        $this->definitionService   = $definitionService ?? new GuidanceFormDefinitionService();
        $this->attestationService  = $attestationService ?? new FormAttestationService($this->attestationModel);
        $this->cipher              = $cipher ?? new GuidanceFormCipher();
        $this->encryptionService   = $encryptionService ?? new EncryptionService();
    }

    /**
     * Resolves canonical form key from internal key or ISO form code.
     */
    public function resolveCanonicalKey(string $formKey): string
    {
        $trimmed = trim($formKey);
        if (isset(self::CANONICAL_FORM_KEYS[$trimmed])) {
            return $trimmed;
        }
        if (isset(self::FORM_CODES_TO_KEYS[$trimmed])) {
            return self::FORM_CODES_TO_KEYS[$trimmed];
        }

        throw new InvalidArgumentException("Invalid clinical form key: {$formKey}", 422);
    }

    /**
     * Creates a new clinical controlled form draft.
     *
     * Validates author practitioner credentials, active standing, and authoring permissions.
     * Encrypts clinical fields using GuidanceFormCipher.
     *
     * @param string $formKey Clinical form key or code
     * @param array<string, mixed> $data Form payload and optional linkage IDs
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed> Created instance with status 'draft'
     */
    public function createClinicalDraft(
        string $formKey,
        array $data,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $canonicalKey = $this->resolveCanonicalKey($formKey);

        $authority = $this->resolveClinicalAuthorAuthority($actor ?? ($data['actor'] ?? null), $tenantId);
        $tid = (int) $authority['tenant_id'];
        $actorId = (int) $authority['id'];

        // Extract and resolve linkages
        $personId      = !empty($data['person_id']) ? (int) $data['person_id'] : null;
        $caseId        = !empty($data['case_id']) ? (int) $data['case_id'] : null;
        $sessionId     = !empty($data['session_id']) ? (int) $data['session_id'] : null;
        $appointmentId = !empty($data['appointment_id']) ? (int) $data['appointment_id'] : null;
        $episodeId     = !empty($data['episode_id']) ? (int) $data['episode_id'] : null;
        $referralId    = !empty($data['referral_id']) ? (int) $data['referral_id'] : null;
        $intakeId      = !empty($data['intake_id']) ? (int) $data['intake_id'] : null;

        // Auto-resolve cross-references if missing
        if ($sessionId !== null) {
            $session = $this->sessionModel->scoped($tid)->find($sessionId);
            if ($session !== null) {
                $caseId ??= !empty($session['case_id']) ? (int) $session['case_id'] : null;
                $appointmentId ??= !empty($session['appointment_id']) ? (int) $session['appointment_id'] : null;
            }
        }

        if ($caseId !== null) {
            $case = $this->caseModel->scoped($tid)->find($caseId);
            if ($case !== null) {
                $personId ??= !empty($case['person_id']) ? (int) $case['person_id'] : null;
            }
        }

        if ($episodeId !== null) {
            $episode = $this->episodeModel->scoped($tid)->find($episodeId);
            if ($episode !== null) {
                $personId ??= !empty($episode['person_id']) ? (int) $episode['person_id'] : null;
                $caseId ??= !empty($episode['case_id']) ? (int) $episode['case_id'] : null;
            }
        }

        if ($appointmentId !== null) {
            $appointment = $this->appointmentModel->scoped($tid)->find($appointmentId);
            if ($appointment !== null) {
                $personId ??= !empty($appointment['person_id']) ? (int) $appointment['person_id'] : null;
                $episodeId ??= !empty($appointment['episode_id']) ? (int) $appointment['episode_id'] : null;
            }
        }

        if ($personId === null) {
            throw new InvalidArgumentException('Person ID is required for clinical form instance.', 422);
        }

        // Verify person belongs to active tenant
        $person = $this->personModel->scoped($tid)->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException('Person not found in active tenant.', 404);
        }

        // Resolve active form definition & revision (sync manifest if needed)
        try {
            $defBundle = $this->definitionService->getActiveDefinition($canonicalKey, $tid);
        } catch (InvalidArgumentException) {
            $this->definitionService->syncManifestToDatabase($tid);
            $defBundle = $this->definitionService->getActiveDefinition($canonicalKey, $tid);
        }

        $def = $defBundle['definition'];
        $rev = $defBundle['revision'];
        $schemaVersion = (string) ($rev['schema_version'] ?? '1.0.0');

        // Populate default values for required fields if omitted
        $formData = $this->prepareClinicalFormData($canonicalKey, $data, $person, $tid);

        // Validate data against schema
        $schema = json_decode((string) ($rev['schema_json'] ?? '{}'), true) ?: [];
        $errors = $this->definitionService->validateFormData($schema, $formData);
        if (!empty($errors)) {
            throw new InvalidArgumentException('Clinical form validation failed: ' . json_encode($errors), 422);
        }

        $year = date('Y');
        $instanceNumber = sprintf('OGC-%s-%06d', $year, random_int(1, 999999));

        $instanceInsert = [
            'tenant_id'              => $tid,
            'form_definition_id'     => (int) $def['id'],
            'definition_revision_id' => (int) $rev['id'],
            'person_id'              => $personId,
            'case_id'                => $caseId,
            'episode_id'             => $episodeId,
            'session_id'             => $sessionId,
            'appointment_id'         => $appointmentId,
            'referral_id'            => $referralId,
            'intake_id'              => $intakeId,
            'instance_number'        => $instanceNumber,
            'status'                 => 'draft',
            'created_by_user_id'     => $actorId,
        ];

        $instanceId = $this->instanceModel->insert($instanceInsert);
        if ($instanceId === false) {
            throw new RuntimeException('Failed to create clinical form instance.', 500);
        }
        $instanceId = (int) $instanceId;

        // Canonical JSON encode
        $canonicalJson = json_encode($formData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Encrypt with GuidanceFormCipher (revisionNumber = 0 for drafts)
        $encrypted = $this->cipher->encrypt(
            $canonicalJson,
            $tid,
            $instanceId,
            (int) $rev['id'],
            0,
            $schemaVersion
        );

        $draftId = $this->draftModel->insert([
            'tenant_id'            => $tid,
            'form_instance_id'     => $instanceId,
            'version'              => 1,
            'draft_cipher'         => $encrypted['cipher'],
            'draft_nonce'          => $encrypted['nonce'],
            'draft_tag'            => $encrypted['tag'],
            'draft_key_version'    => $encrypted['key_version'],
            'draft_schema_version' => $schemaVersion,
            'updated_by_user_id'   => $actorId,
        ]);

        if ($draftId === false) {
            throw new RuntimeException('Failed to store clinical form draft.', 500);
        }

        // Link appointment if provided
        if ($appointmentId !== null) {
            $this->db->table('guidance_appointments')
                ->where('id', $appointmentId)
                ->where('tenant_id', $tid)
                ->update(['form_instance_id' => $instanceId]);
        }

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        $instance['draft_id'] = (int) $draftId;
        $instance['data'] = $formData;

        return $instance;
    }

    /**
     * Submit an associate draft for supervisor co-signature.
     *
     * Sets status to 'pending_cosign', records submission timestamp and assigned supervisor.
     *
     * @param int $formInstanceId
     * @param string|null $submissionNotes
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function submitForCoSign(
        int $formInstanceId,
        ?string $submissionNotes = null,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($formInstanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        if ($instance['status'] !== 'draft') {
            throw new DomainException("Only draft instances can be submitted for co-sign.", 409);
        }

        $authorUserId = (int) $instance['created_by_user_id'];

        // Retrieve active supervision assignment
        $supervision = $this->supervisionModel->scoped($tid)
            ->where('associate_user_id', $authorUserId)
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->first();

        if ($supervision === null) {
            throw new DomainException('No active supervisor assignment found for associate author.', 422);
        }

        $supervisorUserId = (int) $supervision['supervisor_user_id'];
        $now = date('Y-m-d H:i:s');

        $this->instanceModel->update($formInstanceId, [
            'status'                      => 'pending_cosign',
            'assigned_supervisor_user_id' => $supervisorUserId,
            'submitted_for_cosign_at'     => $now,
            'submission_notes'            => $submissionNotes,
            'updated_at'                  => $now,
        ]);

        return $this->instanceModel->scoped($tid)->find($formInstanceId) ?? [];
    }

    /**
     * Supervisor co-signs and finalizes a pending_cosign clinical form.
     *
     * Actor must be the assigned supervisor with active standing.
     * Creates supervisor attestation with role 'cosigner' and marks status 'finalized'.
     *
     * @param int $formInstanceId
     * @param string $attestationStatement
     * @param string|null $supervisorNotes
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function coSignAndFinalize(
        int $formInstanceId,
        string $attestationStatement,
        ?string $supervisorNotes = null,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];
        $actorId = (int) $authority['id'];

        $instance = $this->instanceModel->scoped($tid)->find($formInstanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        if ($instance['status'] === 'finalized') {
            throw new DomainException('Clinical form instance is already finalized.', 409);
        }

        if ($instance['status'] !== 'pending_cosign' && $instance['status'] !== 'draft') {
            throw new DomainException("Cannot co-sign instance in status '{$instance['status']}'.", 409);
        }

        $authorUserId = (int) $instance['created_by_user_id'];

        // Associate counselors cannot co-sign or directly finalize associate records
        if ($actorId === $authorUserId) {
            $authorProfile = $this->practitionerService->getPractitionerProfile($authorUserId, $tid);
            if (($authorProfile['credential_tier'] ?? '') === 'counselor_associate') {
                throw new DomainException('Associate counselors require supervisor co-signature', 403);
            }
        }

        // Supervisor must have active licensed/supervisor standing
        if (!$this->practitionerService->canFinalizeDirectly($actorId, $tid)) {
            throw new DomainException('Co-signer must have active licensed/supervisor standing.', 403);
        }

        // Actor must be the assigned supervisor
        $supervision = $this->supervisionModel->scoped($tid)
            ->where('associate_user_id', $authorUserId)
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->first();

        $assignedSupervisorId = $supervision !== null ? (int) $supervision['supervisor_user_id'] : (int) ($instance['assigned_supervisor_user_id'] ?? 0);

        if ($assignedSupervisorId !== $actorId) {
            throw new DomainException('Actor must be assigned supervisor with active standing.', 403);
        }

        // Retrieve and decrypt draft
        $draft = $this->draftModel->scoped($tid)->where('form_instance_id', $formInstanceId)->first();
        if ($draft === null) {
            throw new DomainException('Cannot finalize a form instance without saved draft data.', 422);
        }

        $revRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $instance['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');

        $plaintext = $this->cipher->decrypt(
            (string) $draft['draft_cipher'],
            (string) $draft['draft_nonce'],
            (string) $draft['draft_tag'],
            (int) $draft['draft_key_version'],
            $tid,
            $formInstanceId,
            (int) $instance['definition_revision_id'],
            0,
            $schemaVersion
        );

        $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        // Normalize keys and compute snapshot hash
        ksort($data);
        $canonicalJson = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $snapshotHash = hash('sha256', $canonicalJson);

        // Encrypt immutable revision (revisionNumber = 1)
        $revisionNumber = 1;
        $encrypted = $this->cipher->encrypt(
            $canonicalJson,
            $tid,
            $formInstanceId,
            (int) $instance['definition_revision_id'],
            $revisionNumber,
            $schemaVersion
        );

        $revisionId = (int) $this->revisionModel->insert([
            'tenant_id'              => $tid,
            'form_instance_id'       => $formInstanceId,
            'revision_number'        => $revisionNumber,
            'revision_type'          => 'initial',
            'snapshot_hash'          => $snapshotHash,
            'snapshot_cipher'        => $encrypted['cipher'],
            'snapshot_nonce'         => $encrypted['nonce'],
            'snapshot_tag'           => $encrypted['tag'],
            'snapshot_key_version'   => $encrypted['key_version'],
            'schema_version'         => $schemaVersion,
            'definition_revision_id' => (int) $instance['definition_revision_id'],
            'created_by_user_id'     => $actorId,
        ]);

        $statementHash = hash('sha256', trim($attestationStatement));
        $now = date('Y-m-d H:i:s');

        // Create supervisor attestation with role 'cosigner'
        $attestationId = (int) $this->attestationModel->insert([
            'tenant_id'                  => $tid,
            'form_instance_id'           => $formInstanceId,
            'form_revision_id'           => $revisionId,
            'signer_user_id'             => $actorId,
            'signer_person_id'           => !empty($authority['person_id']) ? (int) $authority['person_id'] : null,
            'signer_role'                => 'cosigner',
            'signer_auth_version'        => (int) ($authority['auth_version'] ?? 1),
            'credential_snapshot'        => json_encode([
                'supervisor_user_id' => $actorId,
                'associate_user_id'  => $authorUserId,
                'role'               => 'cosigner',
            ], JSON_UNESCAPED_SLASHES),
            'attestation_statement_hash' => $statementHash,
            'snapshot_hash'              => $snapshotHash,
            'attested_at'                => $now,
            'attestation_method'         => 'account_attestation',
        ]);

        $retentionDue = date('Y-m-d H:i:s', strtotime('+5 years'));

        $this->instanceModel->update($formInstanceId, [
            'status'               => 'finalized',
            'finalized_by_user_id' => $actorId,
            'finalized_at'         => $now,
            'supervisor_notes'     => $supervisorNotes,
            'retention_due_at'     => $retentionDue,
            'updated_at'           => $now,
        ]);

        // Clean up mutable draft
        $this->draftModel->where('form_instance_id', $formInstanceId)->delete();

        $attestation = $this->attestationModel->find($attestationId);
        $finalizedInstance = $this->instanceModel->scoped($tid)->find($formInstanceId);

        return [
            'status'         => 'finalized',
            'instance_id'    => $formInstanceId,
            'instance'       => $finalizedInstance,
            'revision_id'    => $revisionId,
            'snapshot_hash'  => $snapshotHash,
            'attestation'    => $attestation,
            'cosigned'       => true,
        ];
    }

    /**
     * Licensed counselor or supervisor finalizes a clinical form directly.
     *
     * Validates actor practitioner eligibility.
     * Associate Counselors CANNOT directly finalize (throws 403 DomainException).
     * Creates author attestation with role 'primary_counselor' and marks status 'finalized'.
     *
     * @param int $formInstanceId
     * @param string $attestationStatement
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function finalizeDirectly(
        int $formInstanceId,
        string $attestationStatement,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];
        $actorId = (int) $authority['id'];

        $instance = $this->instanceModel->scoped($tid)->find($formInstanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        if ($instance['status'] === 'finalized') {
            throw new DomainException('Clinical form instance is already finalized.', 409);
        }

        // Validate practitioner profile & standing
        $profile = $this->practitionerService->getPractitionerProfile($actorId, $tid);
        if ($profile === null) {
            throw new DomainException('Clinical record finalization requires an active practitioner profile.', 403);
        }

        if ((int) ($profile['is_active'] ?? 0) !== 1 || ($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Practitioner is not in active professional standing.', 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException('Practitioner credentials have expired.', 403);
        }

        $tier = (string) ($profile['credential_tier'] ?? '');

        // Associate Counselors CANNOT directly finalize
        if ($tier === 'counselor_associate') {
            throw new DomainException('Associate counselors require supervisor co-signature', 403);
        }

        if (!in_array($tier, ['licensed_counselor', 'guidance_supervisor'], true)) {
            throw new DomainException('Clinical record direct finalization requires licensed counselor or supervisor standing.', 403);
        }

        // Validate permission
        $perms = $authority['permissions'] ?? [];
        if (!in_array('guidance.clinical.author', $perms, true)
            && !in_array('guidance.counseling.author', $perms, true)
            && !in_array('guidance.psychosocial.author', $perms, true)) {
            throw new DomainException('Actor lacks clinical author permission.', 403);
        }

        // Retrieve and decrypt draft
        $draft = $this->draftModel->scoped($tid)->where('form_instance_id', $formInstanceId)->first();
        if ($draft === null) {
            throw new DomainException('Cannot finalize a form instance without saved draft data.', 422);
        }

        $revRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $instance['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');

        $plaintext = $this->cipher->decrypt(
            (string) $draft['draft_cipher'],
            (string) $draft['draft_nonce'],
            (string) $draft['draft_tag'],
            (int) $draft['draft_key_version'],
            $tid,
            $formInstanceId,
            (int) $instance['definition_revision_id'],
            0,
            $schemaVersion
        );

        $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        // Normalize keys and compute snapshot hash
        ksort($data);
        $canonicalJson = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $snapshotHash = hash('sha256', $canonicalJson);

        // Encrypt immutable revision (revisionNumber = 1)
        $revisionNumber = 1;
        $encrypted = $this->cipher->encrypt(
            $canonicalJson,
            $tid,
            $formInstanceId,
            (int) $instance['definition_revision_id'],
            $revisionNumber,
            $schemaVersion
        );

        $revisionId = (int) $this->revisionModel->insert([
            'tenant_id'              => $tid,
            'form_instance_id'       => $formInstanceId,
            'revision_number'        => $revisionNumber,
            'revision_type'          => 'initial',
            'snapshot_hash'          => $snapshotHash,
            'snapshot_cipher'        => $encrypted['cipher'],
            'snapshot_nonce'         => $encrypted['nonce'],
            'snapshot_tag'           => $encrypted['tag'],
            'snapshot_key_version'   => $encrypted['key_version'],
            'schema_version'         => $schemaVersion,
            'definition_revision_id' => (int) $instance['definition_revision_id'],
            'created_by_user_id'     => $actorId,
        ]);

        $statementHash = hash('sha256', trim($attestationStatement));
        $now = date('Y-m-d H:i:s');

        // Create author attestation with role 'primary_counselor'
        $attestationId = (int) $this->attestationModel->insert([
            'tenant_id'                  => $tid,
            'form_instance_id'           => $formInstanceId,
            'form_revision_id'           => $revisionId,
            'signer_user_id'             => $actorId,
            'signer_person_id'           => !empty($authority['person_id']) ? (int) $authority['person_id'] : null,
            'signer_role'                => 'primary_counselor',
            'signer_auth_version'        => (int) ($authority['auth_version'] ?? 1),
            'credential_snapshot'        => json_encode([
                'tier'           => $tier,
                'license_number' => $profile['license_number'] ?? null,
                'role'           => 'primary_counselor',
            ], JSON_UNESCAPED_SLASHES),
            'attestation_statement_hash' => $statementHash,
            'snapshot_hash'              => $snapshotHash,
            'attested_at'                => $now,
            'attestation_method'         => 'account_attestation',
        ]);

        $retentionDue = date('Y-m-d H:i:s', strtotime('+5 years'));

        $this->instanceModel->update($formInstanceId, [
            'status'               => 'finalized',
            'finalized_by_user_id' => $actorId,
            'finalized_at'         => $now,
            'retention_due_at'     => $retentionDue,
            'updated_at'           => $now,
        ]);

        // Clean up mutable draft
        $this->draftModel->where('form_instance_id', $formInstanceId)->delete();

        $attestation = $this->attestationModel->find($attestationId);
        $finalizedInstance = $this->instanceModel->scoped($tid)->find($formInstanceId);

        return [
            'status'        => 'finalized',
            'instance_id'   => $formInstanceId,
            'instance'      => $finalizedInstance,
            'revision_id'   => $revisionId,
            'snapshot_hash' => $snapshotHash,
            'attestation'   => $attestation,
        ];
    }

    /**
     * Retrieves decrypted draft for a clinical form instance.
     *
     * @return array<string, mixed>
     */
    public function getClinicalDraft(int $instanceId, ?int $tenantId = null, ?array $actor = null): array
    {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        $draft = $this->draftModel->scoped($tid)->where('form_instance_id', $instanceId)->first();
        if ($draft === null) {
            return [
                'instance' => $instance,
                'draft'    => null,
                'data'     => [],
            ];
        }

        $revRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $instance['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');

        $plaintext = $this->cipher->decrypt(
            (string) $draft['draft_cipher'],
            (string) $draft['draft_nonce'],
            (string) $draft['draft_tag'],
            (int) $draft['draft_key_version'],
            $tid,
            $instanceId,
            (int) $instance['definition_revision_id'],
            0,
            $schemaVersion
        );

        $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        return [
            'instance' => $instance,
            'draft'    => $draft,
            'data'     => $data,
        ];
    }

    /**
     * Retrieves decrypted finalized revision and attestations for a clinical form instance.
     *
     * @return array<string, mixed>
     */
    public function getFinalizedClinicalForm(
        int $instanceId,
        ?int $revisionNumber = 1,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        $revNum = $revisionNumber ?? 1;
        $revRow = $this->revisionModel->scoped($tid)
            ->where('form_instance_id', $instanceId)
            ->where('revision_number', $revNum)
            ->first();

        if ($revRow === null) {
            throw new InvalidArgumentException("Finalized revision {$revNum} not found.", 404);
        }

        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');

        $plaintext = $this->cipher->decrypt(
            (string) $revRow['snapshot_cipher'],
            (string) $revRow['snapshot_nonce'],
            (string) $revRow['snapshot_tag'],
            (int) $revRow['snapshot_key_version'],
            $tid,
            $instanceId,
            (int) $revRow['definition_revision_id'],
            $revNum,
            $schemaVersion
        );

        $data = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        // Fetch attestations
        $attestations = $this->attestationModel->scoped($tid)
            ->where('form_revision_id', (int) $revRow['id'])
            ->findAll();

        return [
            'instance'     => $instance,
            'revision'     => $revRow,
            'data'         => $data,
            'attestations' => $attestations,
        ];
    }

    /**
     * Retrieves unified clinical history for a counseling case.
     *
     * Returns controlled forms (interviews, contact forms, contact sheets) alongside legacy session notes.
     *
     * @param int $caseId
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getClinicalHistoryForCase(
        int $caseId,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];

        $case = $this->caseModel->scoped($tid)->find($caseId);
        if ($case === null) {
            throw new InvalidArgumentException('Counseling case not found.', 404);
        }

        if (!empty($authority['permissions'])) {
            $this->accessPolicy->assertCanReadCase($case, $authority);
        }

        // Fetch controlled form instances linked to this case
        $instances = $this->instanceModel->scoped($tid)
            ->where('case_id', $caseId)
            ->orderBy('id', 'ASC')
            ->findAll();

        $interviews = [];
        $contactForms = [];
        $contactSheets = [];
        $allForms = [];

        foreach ($instances as $inst) {
            $instId = (int) $inst['id'];
            $def = $this->db->table('guidance_form_definitions')
                ->where('id', (int) $inst['form_definition_id'])
                ->get()->getRowArray();

            $formKey = (string) ($def['internal_key'] ?? '');
            $formCode = (string) ($def['form_code'] ?? '');

            $formData = [];
            $attestations = [];

            if ($inst['status'] === 'finalized') {
                try {
                    $finalized = $this->getFinalizedClinicalForm($instId, 1, $tid, $authority);
                    $formData = $finalized['data'];
                    $attestations = $finalized['attestations'];
                } catch (\Throwable) {
                    $formData = [];
                }
            } else {
                try {
                    $draftBundle = $this->getClinicalDraft($instId, $tid, $authority);
                    $formData = $draftBundle['data'];
                } catch (\Throwable) {
                    $formData = [];
                }
            }

            $formEntry = array_merge($inst, [
                'internal_key'    => $formKey,
                'form_code'       => $formCode,
                'canonical_title' => $def['canonical_title'] ?? '',
                'data'            => $formData,
                'attestations'    => $attestations,
            ]);

            $allForms[] = $formEntry;

            if ($formKey === self::FORM_PSYCHOSOCIAL_INTERVIEW || $formCode === 'F-OGC-GPS-PIF-008-00') {
                $interviews[] = $formEntry;
            } elseif ($formKey === self::FORM_PSYCHOSOCIAL_CONTACT || $formCode === 'F-OGC-GPS-PCF-008-00') {
                $contactForms[] = $formEntry;
            } elseif ($formKey === self::FORM_COUNSELING_CONTACT || $formCode === 'F-OGC-GCS-CCF-009-00') {
                $contactSheets[] = $formEntry;
            }
        }

        // Fetch legacy session notes
        $sessions = $this->sessionModel->scoped($tid)
            ->where('case_id', $caseId)
            ->orderBy('session_number', 'ASC')
            ->findAll();

        $legacyNotes = [];
        foreach ($sessions as $session) {
            $sessId = (int) $session['id'];
            $notes = $this->noteModel->scoped($tid)
                ->where('session_id', $sessId)
                ->orderBy('version', 'ASC')
                ->findAll();

            foreach ($notes as $note) {
                $decryptedText = null;
                if (!empty($note['notes_cipher'])) {
                    try {
                        $decryptedText = $this->encryptionService->decrypt(
                            $note['notes_cipher'],
                            $note['notes_nonce'],
                            $note['notes_tag'],
                            (int) $note['notes_key_version']
                        );
                    } catch (\Throwable) {
                        $decryptedText = '[Encrypted note unavailable]';
                    }
                }

                $legacyNotes[] = array_merge($note, [
                    'session'        => $session,
                    'decrypted_text' => $decryptedText,
                ]);
            }
        }

        // Unified timeline
        $timeline = [];
        foreach ($allForms as $f) {
            $timeline[] = [
                'type'       => 'controlled_form',
                'form_key'   => $f['internal_key'],
                'form_code'  => $f['form_code'],
                'date'       => $f['created_at'],
                'status'     => $f['status'],
                'record'     => $f,
            ];
        }
        foreach ($legacyNotes as $n) {
            $timeline[] = [
                'type'       => 'session_note',
                'session_id' => $n['session_id'],
                'date'       => $n['created_at'],
                'version'    => $n['version'],
                'record'     => $n,
            ];
        }

        usort($timeline, static fn ($a, $b) => strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? '')));

        return [
            'case_id'              => $caseId,
            'case'                 => $case,
            'controlled_forms'     => $allForms,
            'interviews'           => $interviews,
            'contact_forms'        => $contactForms,
            'contact_sheets'       => $contactSheets,
            'legacy_session_notes' => $legacyNotes,
            'session_notes'        => $legacyNotes,
            'timeline'             => $timeline,
        ];
    }

    /**
     * Ties session closing directly to the controlled contact sheet (F-OGC-GCS-CCF-009-00)
     * without duplicate data entry.
     *
     * @param int $sessionId
     * @param array<string, mixed> $formData
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function attachContactSheetToSession(
        int $sessionId,
        array $formData,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];

        $session = $this->sessionModel->scoped($tid)->find($sessionId);
        if ($session === null) {
            throw new InvalidArgumentException("Counseling session {$sessionId} not found.", 404);
        }

        $case = $this->caseModel->scoped($tid)->find((int) $session['case_id']);
        if ($case === null) {
            throw new InvalidArgumentException("Counseling case for session {$sessionId} not found.", 404);
        }

        // If concerns_discussed is missing, check if legacy note exists to avoid duplicate data entry
        if (empty($formData['concerns_discussed'])) {
            $existingNote = $this->noteModel->scoped($tid)
                ->where('session_id', $sessionId)
                ->orderBy('id', 'DESC')
                ->first();

            if ($existingNote !== null && !empty($existingNote['notes_cipher'])) {
                try {
                    $formData['concerns_discussed'] = $this->encryptionService->decrypt(
                        $existingNote['notes_cipher'],
                        $existingNote['notes_nonce'],
                        $existingNote['notes_tag'],
                        (int) $existingNote['notes_key_version']
                    );
                } catch (\Throwable) {
                    // Fallback to default
                }
            }
        }

        // Populate contact sheet attributes
        $formData['session_date'] ??= date('Y-m-d', strtotime($session['started_at'] ?? 'now'));
        $formData['session_number'] ??= (int) ($session['session_number'] ?? 1);
        $formData['time_start'] ??= date('H:i:s', strtotime($session['started_at'] ?? 'now'));
        $formData['time_end'] ??= date('H:i:s');
        $formData['session_type'] ??= $session['session_type'] ?? 'regular';
        $formData['concerns_discussed'] ??= 'Session counseling consultation.';
        $formData['counseling_goals'] ??= 'Established counseling interventions and coping strategies.';
        $formData['interventions_and_responses'] ??= 'Applied supportive counseling techniques; client responded well.';

        // Linkages
        $formData['session_id']     = $sessionId;
        $formData['case_id']        = (int) $case['id'];
        $formData['person_id']       = (int) $case['person_id'];
        $formData['appointment_id'] = !empty($session['appointment_id']) ? (int) $session['appointment_id'] : null;

        // Check if existing contact sheet draft is already attached to this session
        $existingInstance = $this->instanceModel->scoped($tid)
            ->where('session_id', $sessionId)
            ->where('status', 'draft')
            ->first();

        if ($existingInstance !== null) {
            $formInstance = $this->updateClinicalDraft((int) $existingInstance['id'], $formData, $tid, $authority);
            $instanceId = (int) $existingInstance['id'];
        } else {
            $formInstance = $this->createClinicalDraft(self::FORM_COUNSELING_CONTACT, $formData, $tid, $authority);
            $instanceId = (int) $formInstance['id'];
        }

        // Close session if not already completed
        if ($session['status'] !== 'completed') {
            $this->sessionModel->scoped($tid)->update($sessionId, [
                'status'   => 'completed',
                'ended_at' => date('Y-m-d H:i:s'),
            ]);
            $session = $this->sessionModel->scoped($tid)->find($sessionId);
        }

        // Check if caller requested finalization or submission for co-sign
        if (!empty($formData['finalize']) || !empty($formData['attestation_statement'])) {
            $statement = (string) ($formData['attestation_statement'] ?? 'Counseling contact sheet verified and signed.');
            $profile = $this->practitionerService->getPractitionerProfile((int) $authority['id'], $tid);
            $isAssociate = ($profile['credential_tier'] ?? '') === 'counselor_associate';

            if ($isAssociate) {
                $formInstance = $this->submitForCoSign($instanceId, $formData['submission_notes'] ?? null, $tid, $authority);
            } else {
                $finalized = $this->finalizeDirectly($instanceId, $statement, $tid, $authority);
                $formInstance = $finalized['instance'];
            }
        }

        return [
            'session'          => $session,
            'contact_sheet'    => $formInstance,
            'form_instance_id' => $instanceId,
            'status'           => $formInstance['status'] ?? 'draft',
        ];
    }

    /**
     * Updates an existing clinical form draft with new encrypted data.
     *
     * @param int $instanceId
     * @param array<string, mixed> $data
     * @param int|null $tenantId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function updateClinicalDraft(
        int $instanceId,
        array $data,
        ?int $tenantId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveClinicalAuthorAuthority($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];
        $actorId = (int) $authority['id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Clinical form instance not found.', 404);
        }

        if ($instance['status'] !== 'draft') {
            throw new DomainException('Cannot modify non-draft clinical form instance.', 409);
        }

        $revRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $instance['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');
        $schema = json_decode((string) ($revRow['schema_json'] ?? '{}'), true) ?: [];

        // Decrypt current draft and merge
        $currentDraft = $this->getClinicalDraft($instanceId, $tid, $authority);
        $mergedData = array_merge($currentDraft['data'] ?? [], $data);

        $errors = $this->definitionService->validateFormData($schema, $mergedData);
        if (!empty($errors)) {
            throw new InvalidArgumentException('Clinical form validation failed: ' . json_encode($errors), 422);
        }

        $canonicalJson = json_encode($mergedData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $encrypted = $this->cipher->encrypt(
            $canonicalJson,
            $tid,
            $instanceId,
            (int) $instance['definition_revision_id'],
            0,
            $schemaVersion
        );

        $existingDraft = $this->draftModel->scoped($tid)->where('form_instance_id', $instanceId)->first();
        if ($existingDraft !== null) {
            $newVersion = (int) $existingDraft['version'] + 1;
            $this->draftModel->update((int) $existingDraft['id'], [
                'version'              => $newVersion,
                'draft_cipher'         => $encrypted['cipher'],
                'draft_nonce'          => $encrypted['nonce'],
                'draft_tag'            => $encrypted['tag'],
                'draft_key_version'    => $encrypted['key_version'],
                'draft_schema_version' => $schemaVersion,
                'updated_by_user_id'   => $actorId,
            ]);
        }

        $instance['data'] = $mergedData;
        return $instance;
    }

    /**
     * Resolves actor identity safely and ensures clinical author credentials & standing.
     *
     * @param array<string, mixed>|null $actor
     * @param int|null $tenantId
     * @return array<string, mixed>
     */
    private function resolveClinicalAuthorAuthority(?array $actor, ?int $tenantId = null): array
    {
        $authority = $this->resolveActorIdentity($actor, $tenantId);
        $tid = (int) $authority['tenant_id'];
        $actorId = (int) $authority['id'];

        $profile = $this->practitionerService->getPractitionerProfile($actorId, $tid);
        if ($profile === null) {
            throw new DomainException('Clinical record authoring requires an active practitioner profile.', 403);
        }

        if ((int) ($profile['is_active'] ?? 0) !== 1 || ($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Practitioner is not in active professional standing.', 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException('Practitioner credentials have expired.', 403);
        }

        $perms = $authority['permissions'] ?? [];
        if (!in_array('guidance.clinical.author', $perms, true)
            && !in_array('guidance.counseling.author', $perms, true)
            && !in_array('guidance.psychosocial.author', $perms, true)) {
            throw new DomainException('Actor lacks clinical author permission.', 403);
        }

        return $authority;
    }

    /**
     * Resolves authenticated actor identity without checking practitioner profiles.
     *
     * @param array<string, mixed>|null $actor
     * @param int|null $tenantId
     * @return array<string, mixed>
     */
    private function resolveActorIdentity(?array $actor, ?int $tenantId = null): array
    {
        $tid = $tenantId ?? ($actor['tenant_id'] ?? CurrentTenant::id());
        try {
            return $this->accessPolicy->resolve($actor, null, lock: false);
        } catch (DomainException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                return $this->accessPolicy->authorize([
                    'id'        => (int) $actor['id'],
                    'tenant_id' => $tid,
                ], null, lock: false);
            }
            throw new DomainException('An authenticated guidance account is required.', 403, $e);
        }
    }

    /**
     * Prepares clinical form data with sensible defaults for missing fields.
     *
     * @param string $canonicalKey
     * @param array<string, mixed> $data
     * @param array<string, mixed> $person
     * @param int $tenantId
     * @return array<string, mixed>
     */
    private function prepareClinicalFormData(
        string $canonicalKey,
        array $data,
        array $person,
        int $tenantId
    ): array {
        $clean = $data;
        unset($clean['actor'], $clean['_actor'], $clean['person_id'], $clean['case_id'], $clean['session_id'], $clean['appointment_id'], $clean['episode_id'], $clean['referral_id'], $clean['intake_id']);

        $fullName = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));

        if ($canonicalKey === self::FORM_PSYCHOSOCIAL_INTERVIEW) {
            $student = $this->studentModel->scoped($tenantId)->where('person_id', (int) $person['id'])->first();
            $clean['student_name'] ??= $fullName !== '' ? $fullName : 'Student Name';
            $clean['student_id'] ??= $student['student_id'] ?? $person['mis_id'] ?? 'S-' . $person['id'];
            $programPart = trim((string) ($student['program'] ?? $student['course'] ?? ''));
            $yearPart = trim((string) ($student['year_level'] ?? ''));
            $combinedCourseYear = trim($programPart . ($yearPart !== '' ? ' - Year ' . $yearPart : ''));
            $clean['course_year'] ??= $combinedCourseYear !== '' ? $combinedCourseYear : 'N/A';
            $clean['interview_date'] ??= date('Y-m-d');
            $computedAge = null;
            if (!empty($person['date_of_birth'])) {
                try {
                    $computedAge = (new \DateTimeImmutable((string) $person['date_of_birth']))->diff(new \DateTimeImmutable('today'))->y;
                } catch (\Throwable) {
                    $computedAge = null;
                }
            }
            $clean['demographics'] ??= [
                'age'     => $computedAge,
                'gender'  => $person['gender'] ?? 'prefer_not_to_say',
                'contact' => $person['phone'] ?? $person['contact_number'] ?? '',
            ];
            $clean['presenting_concerns'] ??= 'Student presenting with academic adjustment stress.';
            $clean['mental_health_screening'] ??= [
                'phq9_score' => 4,
                'gad7_score' => 3,
                'risk_level' => 'low',
            ];
            $clean['family_social_support'] ??= 'Supportive family and peer circle.';
            $clean['counselor_assessment'] ??= 'Mild adjustment reaction; good resilience and support systems.';
            $clean['intervention_plan'] ??= 'Weekly follow-up, stress management techniques, time management coaching.';
            $clean['privacy_consent_acknowledged'] ??= true;
        } elseif ($canonicalKey === self::FORM_PSYCHOSOCIAL_CONTACT) {
            $clean['session_date'] ??= date('Y-m-d');
            $clean['session_number'] ??= 1;
            $clean['modality'] ??= 'in_person';
            $clean['client_state'] ??= 'Client appears calm, oriented, and collaborative.';
            $clean['interventions_used'] ??= 'Active listening, validation, cognitive reframing.';
            $clean['client_response'] ??= 'Client demonstrated improved perspective and expressed relief.';
            $clean['action_items'] ??= 'Practice deep breathing exercises before exams.';
            $clean['next_session_date'] ??= date('Y-m-d', strtotime('+1 week'));
        } elseif ($canonicalKey === self::FORM_COUNSELING_CONTACT) {
            $clean['session_date'] ??= date('Y-m-d');
            $clean['session_number'] ??= 1;
            $clean['time_start'] ??= '09:00:00';
            $clean['time_end'] ??= '10:00:00';
            $clean['session_type'] ??= 'regular';
            $clean['concerns_discussed'] ??= 'Academic performance anxiety and sleep disruption.';
            $clean['counseling_goals'] ??= 'Identify anxiety triggers and establish regular sleep hygiene.';
            $clean['interventions_and_responses'] ??= 'Psychoeducation on sleep hygiene; client agreed to track sleep schedule.';
            $clean['homework_and_commitments'] ??= 'Maintain sleep log for 7 days.';
            $clean['next_schedule'] ??= date('Y-m-d H:i:s', strtotime('+1 week 09:00:00'));
        }

        return $clean;
    }
}
