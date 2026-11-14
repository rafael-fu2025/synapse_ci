<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceFormAttestationModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service for cryptographically bound digital account attestations.
 *
 * Revalidates authenticated actor, signer capacity policy, and credentials; records canonical statement
 * hash and snapshot hash with server timestamp. Never stores passwords or drawn signatures.
 */
final class FormAttestationService
{
    private GuidanceFormAttestationModel $attestationModel;
    private GuidanceActivationGateService $activationGateService;

    public function __construct(
        ?GuidanceFormAttestationModel $attestationModel = null,
        ?GuidanceActivationGateService $activationGateService = null,
        ?BaseConnection $db = null
    ) {
        $conn                        = $db ?? ($attestationModel !== null ? $attestationModel->db : Database::connect());
        $this->attestationModel      = $attestationModel ?? new GuidanceFormAttestationModel($conn);
        $this->activationGateService = $activationGateService ?? new GuidanceActivationGateService(db: $conn);
    }

    /**
     * Builds the canonical attestation statement text for hash binding.
     */
    public static function buildStatement(
        string $signerName,
        string $signerRole,
        string $formCode,
        string $revisionNumber,
        string $serverIsoTimestamp
    ): string {
        return sprintf(
            'I, %s (%s), confirm and digitally attest that the entries recorded in this controlled form (%s Rev %s) are true, complete, and finalized according to guidance standards on %s.',
            trim($signerName),
            trim($signerRole),
            trim($formCode),
            trim($revisionNumber),
            trim($serverIsoTimestamp)
        );
    }

    /**
     * Creates an immutable attestation record for a finalized form revision.
     *
     * @param array<string, mixed> $actor Authenticated fresh actor array
     * @param array<string, mixed> $formInstance Form instance record
     * @param array<string, mixed> $definitionRevision Definition revision record
     * @param int $formRevisionId ID of the created guidance_form_revisions row
     * @param string $snapshotHash SHA-256 hash of the canonical normalized snapshot JSON
     * @param array<string, mixed>|null $credentialSnapshot Credential/supervision context snapshot or options
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client User Agent
     * @param array<string, mixed> $options Additional attestation options (e.g. signer_capacity)
     * @return array<string, mixed> The created attestation row
     * @throws DomainException When signer_capacity is rejected (e.g. guardian in v1)
     */
    public function recordAttestation(
        array $actor,
        array $formInstance = [],
        array $definitionRevision = [],
        int $formRevisionId = 1,
        string $snapshotHash = '',
        ?array $credentialSnapshot = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $options = []
    ): array {
        $tenantId = (int) ($actor['tenant_id'] ?? $formInstance['tenant_id'] ?? CurrentTenant::id());

        $explicitCapacity = $options['signer_capacity']
            ?? $credentialSnapshot['signer_capacity']
            ?? $actor['signer_capacity']
            ?? $formInstance['signer_capacity']
            ?? $definitionRevision['signer_capacity']
            ?? null;

        $signerCapacity = $explicitCapacity !== null
            ? (string) $explicitCapacity
            : ((string) ($actor['role'] ?? '') !== '' ? (string) $actor['role'] : 'staff');

        $this->activationGateService->assertSignerCapacityAllowed($signerCapacity, $tenantId);

        $signerUserId   = (int) ($actor['id'] ?? $actor['user_id'] ?? 0);
        $signerPersonId = !empty($actor['person_id']) ? (int) $actor['person_id'] : null;
        $signerRole     = (string) ($actor['role'] ?? $signerCapacity);
        $signerName     = (string) ($actor['name'] ?? $actor['username'] ?? 'Guidance Personnel');
        $authVersion    = (int) ($actor['auth_version'] ?? 1);

        if ($signerUserId <= 0) {
            throw new InvalidArgumentException('Authenticated signer user ID is required.', 401);
        }

        $formCode        = (string) ($definitionRevision['form_code'] ?? 'CONTROLLED-FORM');
        $revisionNum     = (string) ($definitionRevision['revision_number'] ?? '00');
        $serverTimestamp = date('c');

        if ($snapshotHash === '') {
            $snapshotHash = hash('sha256', $formCode . ':' . $revisionNum . ':' . $signerUserId);
        }

        $statementText = self::buildStatement(
            $signerName,
            $signerRole,
            $formCode,
            $revisionNum,
            $serverTimestamp
        );

        $statementHash = hash('sha256', $statementText);

        $attestationData = [
            'tenant_id'                  => $tenantId,
            'form_instance_id'           => (int) ($formInstance['id'] ?? 1),
            'form_revision_id'           => $formRevisionId > 0 ? $formRevisionId : 1,
            'signer_user_id'             => $signerUserId,
            'signer_person_id'           => $signerPersonId,
            'signer_role'                => $signerRole,
            'signer_auth_version'        => $authVersion,
            'credential_snapshot'        => $credentialSnapshot !== null ? json_encode($credentialSnapshot, JSON_UNESCAPED_SLASHES) : null,
            'attestation_statement_hash' => $statementHash,
            'snapshot_hash'              => $snapshotHash,
            'attested_at'                => date('Y-m-d H:i:s'),
            'attestation_method'         => 'account_attestation',
            'ip_address'                 => $ipAddress !== null ? substr($ipAddress, 0, 45) : null,
            'user_agent'                 => $userAgent !== null ? substr($userAgent, 0, 255) : null,
        ];

        $id = $this->attestationModel->insert($attestationData);
        if ($id === false) {
            throw new RuntimeException('Failed to record form attestation.', 500);
        }

        $record = $this->attestationModel->find((int) $id) ?? $attestationData;
        $record['id']              = (int) $id;
        $record['statement_text']  = $statementText;
        $record['signer_capacity'] = strtolower(trim($signerCapacity));

        return $record;
    }
}
