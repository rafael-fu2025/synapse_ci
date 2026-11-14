<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceConsentReceiptModel;
use App\Models\GuidanceFormDraftModel;
use App\Models\GuidanceFormInstanceModel;
use App\Models\GuidanceFormRevisionModel;
use App\Models\GuidancePrivacyNoticeModel;
use App\Models\GuidanceRetentionPolicyModel;
use App\Models\PersonModel;
use App\Services\Crypto\GuidanceFormCipher;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

class GuidanceFormService
{
    private GuidanceFormInstanceModel $instanceModel;
    private GuidanceFormDraftModel $draftModel;
    private GuidanceFormRevisionModel $revisionModel;
    private GuidancePrivacyNoticeModel $privacyNoticeModel;
    private GuidanceRetentionPolicyModel $retentionPolicyModel;
    private GuidanceConsentReceiptModel $consentReceiptModel;
    private PersonModel $personModel;
    private GuidanceFormCipher $cipher;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidanceFormDefinitionService $definitionService;
    private FormAttestationService $attestationService;
    private GuidanceDocumentService $documentService;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceFormInstanceModel $instanceModel = null,
        ?GuidanceFormDraftModel $draftModel = null,
        ?GuidanceFormRevisionModel $revisionModel = null,
        ?GuidancePrivacyNoticeModel $privacyNoticeModel = null,
        ?GuidanceRetentionPolicyModel $retentionPolicyModel = null,
        ?GuidanceConsentReceiptModel $consentReceiptModel = null,
        ?PersonModel $personModel = null,
        ?GuidanceFormCipher $cipher = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?GuidanceFormDefinitionService $definitionService = null,
        ?FormAttestationService $attestationService = null,
        ?GuidanceDocumentService $documentService = null,
        ?BaseConnection $db = null
    ) {
        $this->instanceModel = $instanceModel ?? new GuidanceFormInstanceModel();
        $this->draftModel = $draftModel ?? new GuidanceFormDraftModel();
        $this->revisionModel = $revisionModel ?? new GuidanceFormRevisionModel();
        $this->privacyNoticeModel = $privacyNoticeModel ?? new GuidancePrivacyNoticeModel();
        $this->retentionPolicyModel = $retentionPolicyModel ?? new GuidanceRetentionPolicyModel();
        $this->consentReceiptModel = $consentReceiptModel ?? new GuidanceConsentReceiptModel();
        $this->personModel = $personModel ?? new PersonModel();
        $this->cipher = $cipher ?? new GuidanceFormCipher();
        $this->db = $db ?? Database::connect();
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->definitionService = $definitionService ?? new GuidanceFormDefinitionService();
        $this->attestationService = $attestationService ?? new FormAttestationService();
        $this->documentService = $documentService ?? new GuidanceDocumentService(db: $this->db);
    }

    /**
     * Creates a new controlled form instance in 'draft' status.
     */
    public function createInstance(
        int $personId,
        string $internalKey,
        ?int $caseId = null,
        ?int $sessionId = null,
        ?int $referralId = null,
        ?int $appointmentId = null,
        ?array $actor = null
    ): array {
        $authority = $this->resolveFormWriteAuthority($actor);
        $tid = (int) $authority['tenant_id'];

        // Verify person belongs to tenant
        $person = $this->personModel->scoped($tid)->find($personId);
        if ($person === null) {
            throw new InvalidArgumentException('Person not found in active tenant.', 404);
        }

        // Resolve active definition and revision
        $defBundle = $this->definitionService->getActiveDefinition($internalKey, $tid);
        $def = $defBundle['definition'];
        $rev = $defBundle['revision'];

        $year = date('Y');
        $instanceNumber = sprintf('OGC-%s-%06d', $year, random_int(1, 999999));

        $instanceId = $this->instanceModel->insert([
            'tenant_id' => $tid,
            'form_definition_id' => (int) $def['id'],
            'definition_revision_id' => (int) $rev['id'],
            'person_id' => $personId,
            'case_id' => $caseId,
            'session_id' => $sessionId,
            'referral_id' => $referralId,
            'appointment_id' => $appointmentId,
            'instance_number' => $instanceNumber,
            'status' => 'draft',
            'created_by_user_id' => (int) $authority['id'],
        ]);

        if ($instanceId === false) {
            throw new RuntimeException('Failed to create guidance form instance.', 500);
        }

        return $this->instanceModel->find((int) $instanceId);
    }

    /**
     * Creates a controlled form instance in draft status and saves initial draft data.
     *
     * @param int $personId
     * @param string $internalKey
     * @param array<string, mixed> $data
     * @param int|null $caseId
     * @param int|null $sessionId
     * @param int|null $referralId
     * @param int|null $appointmentId
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function createDraft(
        int $personId,
        string $internalKey,
        array $data = [],
        ?int $caseId = null,
        ?int $sessionId = null,
        ?int $referralId = null,
        ?int $appointmentId = null,
        ?array $actor = null
    ): array {
        $instance = $this->createInstance(
            $personId,
            $internalKey,
            $caseId,
            $sessionId,
            $referralId,
            $appointmentId,
            $actor
        );

        if (!empty($data)) {
            $this->saveDraft((int) $instance['id'], $data, $actor);
        }

        return $this->instanceModel->find((int) $instance['id']);
    }

    /**
     * Saves or updates the single mutable encrypted draft for a form instance.
     */
    public function saveDraft(int $instanceId, array $data, ?array $actor = null): array
    {
        $authority = $this->resolveFormWriteAuthority($actor);
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Form instance not found.', 404);
        }

        if ($instance['status'] !== 'draft') {
            throw new DomainException('Cannot modify non-draft form instance.', 409);
        }

        // Resolve definition revision
        $defRev = $this->definitionService->getActiveDefinition((string) $instance['form_definition_id'], $tid);
        // Load schema
        $revRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $instance['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $schema = json_decode((string) ($revRow['schema_json'] ?? '{}'), true) ?: [];

        // Validate data
        $errors = $this->definitionService->validateFormData($schema, $data);
        if (!empty($errors)) {
            throw new InvalidArgumentException('Form validation failed: ' . json_encode($errors), 422);
        }

        // Canonical JSON encode
        $plaintext = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Encrypt with AAD binding (revisionNumber = 0 for drafts)
        $defRevId = (int) $instance['definition_revision_id'];
        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');

        $encrypted = $this->cipher->encrypt(
            $plaintext,
            $tid,
            $instanceId,
            $defRevId,
            0,
            $schemaVersion
        );

        $existingDraft = $this->draftModel->scoped($tid)->where('form_instance_id', $instanceId)->first();
        if ($existingDraft !== null) {
            $newVersion = (int) $existingDraft['version'] + 1;
            $this->draftModel->update((int) $existingDraft['id'], [
                'version' => $newVersion,
                'draft_cipher' => $encrypted['cipher'],
                'draft_nonce' => $encrypted['nonce'],
                'draft_tag' => $encrypted['tag'],
                'draft_key_version' => $encrypted['key_version'],
                'draft_schema_version' => $schemaVersion,
                'updated_by_user_id' => (int) $authority['id'],
            ]);
            $draftId = (int) $existingDraft['id'];
        } else {
            $draftId = (int) $this->draftModel->insert([
                'tenant_id' => $tid,
                'form_instance_id' => $instanceId,
                'version' => 1,
                'draft_cipher' => $encrypted['cipher'],
                'draft_nonce' => $encrypted['nonce'],
                'draft_tag' => $encrypted['tag'],
                'draft_key_version' => $encrypted['key_version'],
                'draft_schema_version' => $schemaVersion,
                'updated_by_user_id' => (int) $authority['id'],
            ]);
        }

        return [
            'instance_id' => $instanceId,
            'draft_id' => $draftId,
            'status' => 'draft_saved',
        ];
    }

    /**
     * Reads and decrypts the draft for an instance.
     */
    public function getDraft(int $instanceId, ?array $actor = null): array
    {
        $authority = $this->resolveFormReadAuthority($actor);
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Form instance not found.', 404);
        }

        $draft = $this->draftModel->scoped($tid)->where('form_instance_id', $instanceId)->first();
        if ($draft === null) {
            return [
                'instance' => $instance,
                'draft' => null,
                'data' => [],
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
            'draft' => $draft,
            'data' => $data,
        ];
    }

    /**
     * Finalizes a form instance: freezes immutable encrypted snapshot and attestation.
     */
    public function finalizeInstance(
        int $instanceId,
        string $idempotencyKey,
        ?array $actor = null,
        array $options = []
    ): array {
        if ($actor !== null && isset($actor['signer_capacity']) && !isset($options['signer_capacity'])) {
            $options['signer_capacity'] = $actor['signer_capacity'];
        }
        $authority = $this->resolveFormWriteAuthority($actor);
        if (isset($options['signer_capacity'])) {
            $authority['signer_capacity'] = $options['signer_capacity'];
        }
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Form instance not found.', 404);
        }

        $authorUserId = (int) $instance['created_by_user_id'];
        $actorId = (int) $authority['id'];
        $pTable = $this->db->protectIdentifiers($this->db->prefixTable('guidance_practitioner_profiles'));
        $hasProfile = $this->db->query("SELECT id FROM {$pTable} WHERE tenant_id = ? AND user_id = ? AND is_active = 1", [$tid, $authorUserId])->getRowArray();
        if ($hasProfile !== null) {
            // If the author is an associate and this is the associate submitting:
            if ($actorId === $authorUserId && !$this->accessPolicy->canFinalizeDirectly($actorId, $tid)) {
                // Transition draft -> pending_cosign
                $this->instanceModel->update($instanceId, [
                    'status' => 'pending_cosign',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                return [
                    'instance_id' => $instanceId,
                    'status' => 'pending_cosign',
                    'requires_cosign' => true,
                ];
            }
            // Otherwise, validate supervisor/practitioner standing to finalize
            $this->accessPolicy->assertCanFinalizeNote($authority, $authorUserId, $tid);
        }

        if ($instance['status'] !== 'draft' && $instance['status'] !== 'pending_cosign') {
            throw new DomainException('Only draft or pending_cosign instances can be finalized.', 409);
        }

        $draft = $this->draftModel->scoped($tid)->where('form_instance_id', $instanceId)->first();
        if ($draft === null) {
            throw new DomainException('Cannot finalize a form instance without saved draft data.', 422);
        }

        $revRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $instance['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $defRow = $this->db->table('guidance_form_definitions')
            ->where('id', (int) $instance['form_definition_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        $schemaVersion = (string) ($revRow['schema_version'] ?? '1.0.0');

        // Decrypt draft data
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

        // Normalize keys and compute canonical snapshot hash
        ksort($data);
        $canonicalJson = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $snapshotHash = hash('sha256', $canonicalJson);

        // Encrypt immutable revision (revisionNumber = 1)
        $revisionNumber = 1;
        $encrypted = $this->cipher->encrypt(
            $canonicalJson,
            $tid,
            $instanceId,
            (int) $instance['definition_revision_id'],
            $revisionNumber,
            $schemaVersion
        );

        $revisionId = (int) $this->revisionModel->insert([
            'tenant_id' => $tid,
            'form_instance_id' => $instanceId,
            'revision_number' => $revisionNumber,
            'revision_type' => 'initial',
            'snapshot_hash' => $snapshotHash,
            'snapshot_cipher' => $encrypted['cipher'],
            'snapshot_nonce' => $encrypted['nonce'],
            'snapshot_tag' => $encrypted['tag'],
            'snapshot_key_version' => $encrypted['key_version'],
            'schema_version' => $schemaVersion,
            'definition_revision_id' => (int) $instance['definition_revision_id'],
            'created_by_user_id' => (int) $authority['id'],
        ]);

        // Record digital attestation
        $attestation = $this->attestationService->recordAttestation(
            $authority,
            $instance,
            $revRow,
            $revisionId,
            $snapshotHash,
            options: $options
        );

        // Calculate retention due date (5 years default)
        $retentionDue = date('Y-m-d H:i:s', strtotime('+5 years'));

        // Update instance to finalized
        $this->instanceModel->update($instanceId, [
            'status' => 'finalized',
            'finalized_by_user_id' => (int) $authority['id'],
            'finalized_at' => date('Y-m-d H:i:s'),
            'retention_due_at' => $retentionDue,
        ]);

        // Clean up mutable draft
        $this->draftModel->where('form_instance_id', $instanceId)->delete();

        // Enqueue document generation job
        $job = $this->documentService->queueJob(
            $instanceId,
            $revisionId,
            'final_issuance',
            $idempotencyKey,
            $tid
        );

        return [
            'instance_id' => $instanceId,
            'revision_id' => $revisionId,
            'snapshot_hash' => $snapshotHash,
            'attestation' => $attestation,
            'job' => $job,
            'status' => 'finalized',
        ];
    }

    /**
     * Retrieves and decrypts a finalized revision record.
     */
    public function getFinalizedRevision(
        int $instanceId,
        ?int $revisionNumber = 1,
        ?array $actor = null
    ): array {
        $authority = $this->accessPolicy->resolve($actor, 'guidance.cases.read');
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Form instance not found.', 404);
        }

        $revNum = $revisionNumber ?? 1;
        $revRow = $this->revisionModel->scoped($tid)
            ->where('form_instance_id', $instanceId)
            ->where('revision_number', $revNum)
            ->first();

        if ($revRow === null) {
            throw new InvalidArgumentException("Finalized revision {$revNum} not found.", 404);
        }

        $defRevRow = $this->db->table('guidance_form_definition_revisions')
            ->where('id', (int) $revRow['definition_revision_id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

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

        // Fetch attestation
        $attestation = $this->db->table('guidance_form_attestations')
            ->where('form_revision_id', (int) $revRow['id'])
            ->where('tenant_id', $tid)
            ->get()->getRowArray();

        return [
            'instance' => $instance,
            'revision' => $revRow,
            'data' => $data,
            'attestation' => $attestation,
        ];
    }

    /**
     * Explicitly submit an instance for co-sign (draft -> pending_cosign).
     */
    public function submitForCoSign(int $instanceId, ?array $actor = null): array
    {
        $authority = $this->resolveFormWriteAuthority($actor);
        $tid = (int) $authority['tenant_id'];

        $instance = $this->instanceModel->scoped($tid)->find($instanceId);
        if ($instance === null) {
            throw new InvalidArgumentException('Form instance not found.', 404);
        }

        if ($instance['status'] !== 'draft') {
            throw new DomainException('Only draft instances can be submitted for co-sign.', 409);
        }

        $this->instanceModel->update($instanceId, [
            'status' => 'pending_cosign',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->instanceModel->scoped($tid)->find($instanceId);
    }

    /**
     * Supervisor co-signs and finalizes a pending_cosign instance.
     */
    public function coSignInstance(int $instanceId, string $idempotencyKey, ?array $actor = null): array
    {
        return $this->finalizeInstance($instanceId, $idempotencyKey, $actor);
    }

    /**
     * Resolves actor authority for form reading.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    private function resolveFormReadAuthority(?array $actor): array
    {
        try {
            $authority = $this->accessPolicy->resolve($actor, null, lock: false);
        } catch (DomainException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                $authority = $this->accessPolicy->authorize([
                    'id'        => (int) $actor['id'],
                    'tenant_id' => $tenantId,
                ], null, lock: false);
            } else {
                throw new DomainException('An authenticated guidance account is required.', 403, $e);
            }
        }

        $perms = $authority['permissions'] ?? [];
        if (!in_array('guidance.cases.read', $perms, true)
            && !in_array('guidance.cases.read_any', $perms, true)
            && !in_array('guidance.appointments.manage', $perms, true)
            && !in_array('guidance.call_slips.issue', $perms, true)
            && !in_array('guidance.forms.manage', $perms, true)) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }
        return $authority;
    }

    /**
     * Resolves actor authority for form creation/saving, permitting cases.write, appointments.manage, call_slips.issue, or forms.manage.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    private function resolveFormWriteAuthority(?array $actor): array
    {
        try {
            $authority = $this->accessPolicy->resolve($actor, null, lock: false);
        } catch (DomainException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            if ($actor !== null && isset($actor['id'])) {
                $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
                $authority = $this->accessPolicy->authorize([
                    'id'        => (int) $actor['id'],
                    'tenant_id' => $tenantId,
                ], null, lock: false);
            } else {
                throw new DomainException('An authenticated guidance account is required.', 403, $e);
            }
        }

        $perms = $authority['permissions'] ?? [];
        if (!in_array('guidance.cases.write', $perms, true)
            && !in_array('guidance.appointments.manage', $perms, true)
            && !in_array('guidance.call_slips.issue', $perms, true)
            && !in_array('guidance.forms.manage', $perms, true)) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }
        return $authority;
    }
}
