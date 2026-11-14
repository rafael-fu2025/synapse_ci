<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidanceActivationGateModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Service for Phase 8 Rollout Activation Gates & v1 Signer Capacity Policy.
 *
 * Enforces multi-authority signoff (Guidance Head, QA Office, DPO) before
 * production activation of gated policies and blocks unverified guardian
 * digital attestations in v1.
 */
final class GuidanceActivationGateService
{
    public const GATE_CANONICAL_FORMS          = 'canonical_forms_signoff';
    public const GATE_SAFETY_ESCALATION_SOP    = 'safety_escalation_sop_signoff';
    public const GATE_PRACTITIONER_CREDENTIALS = 'practitioner_credentials_signoff';
    public const GATE_PRIVACY_RETENTION_DPO    = 'privacy_retention_dpo_signoff';
    public const GATE_MI_REPRODUCTION          = 'mi_reproduction_signoff';
    public const GATE_EVALUATION_COHORT_POLICY = 'evaluation_cohort_policy_signoff';
    public const GATE_GUARDIAN_IDENTITY        = 'guardian_identity_workflow';

    public const VALID_AUTHORITY_ROLES = [
        'guidance_head',
        'qa_office',
        'dpo',
    ];

    public const ALLOWED_V1_SIGNER_CAPACITIES = [
        'student',
        'staff',
        'practitioner',
        'counselor',
        'counsellor',
        'counselor_associate',
        'licensed_counselor',
        'supervisor',
        'guidance_supervisor',
        'guidance_admin',
        'qualified_assessor',
        'employee',
        'faculty',
        'superadmin',
    ];

    public const GUARDIAN_SIGNER_CAPACITIES = [
        'guardian',
        'parent',
        'parent_guardian',
        'legal_guardian',
        'simulated_guardian',
    ];

    public const DEFAULT_GATES = [
        self::GATE_CANONICAL_FORMS => [
            'gate_key'    => self::GATE_CANONICAL_FORMS,
            'title'       => 'Canonical Form Codes, Titles, and Revisions Signoff',
            'description' => 'Canonical form codes/titles/revisions (General Referral 00 vs footer 01, Appointment code mismatch, Counseling Contact Form/Sheet title)',
        ],
        self::GATE_SAFETY_ESCALATION_SOP => [
            'gate_key'    => self::GATE_SAFETY_ESCALATION_SOP,
            'title'       => 'Safety, Abuse, and Self-Harm Escalation SOP Signoff',
            'description' => 'Official safety/abuse/self-harm escalation SOP, after-hours owner, deadlines, and permitted channels',
        ],
        self::GATE_PRACTITIONER_CREDENTIALS => [
            'gate_key'    => self::GATE_PRACTITIONER_CREDENTIALS,
            'title'       => 'Practitioner Credentials, Supervision, Co-Sign, and Closure Rules Signoff',
            'description' => 'Counselor Associate/Counselor/qualified-assessor credential, supervision, co-sign, and closure rules',
        ],
        self::GATE_PRIVACY_RETENTION_DPO => [
            'gate_key'    => self::GATE_PRIVACY_RETENTION_DPO,
            'title'       => 'Privacy Notices, Retention, Disclosures, and Guardian Identity DPO Signoff',
            'description' => 'Final privacy notices, legal bases, signer-capacity rules, retention periods, disclosure/printing policy, and guardian identity process',
        ],
        self::GATE_MI_REPRODUCTION => [
            'gate_key'    => self::GATE_MI_REPRODUCTION,
            'title'       => 'Multiple Intelligence Survey Reproduction and Interpretation Policy Signoff',
            'description' => 'MI reproduction/adaptation authority, exact reviewed wording/mapping, intended population, interpretation copy, and result-release policy',
        ],
        self::GATE_EVALUATION_COHORT_POLICY => [
            'gate_key'    => self::GATE_EVALUATION_COHORT_POLICY,
            'title'       => 'Service Evaluation Cohort Threshold and Comment Review Policy Signoff',
            'description' => 'Minimum evaluation aggregate cohort and comment-review policy',
        ],
    ];

    private GuidanceActivationGateModel $gateModel;
    private BaseConnection $db;

    public function __construct(
        ?GuidanceActivationGateModel $gateModel = null,
        ?BaseConnection $db = null
    ) {
        $this->db        = $db ?? ($gateModel !== null ? $gateModel->db : Database::connect());
        $this->gateModel = $gateModel ?? new GuidanceActivationGateModel($this->db);
    }

    /**
     * Retrieve all activation gates for a tenant.
     *
     * @param int|null $tenantId
     * @return array<int, array<string, mixed>>
     */
    public function getGates(?int $tenantId = null): array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $this->ensureDefaultGatesSeeded($tid);

        $rows = $this->gateModel->getAllGates($tid);

        return array_map([$this, 'formatGate'], $rows);
    }

    /**
     * Retrieve a single activation gate by key.
     *
     * @param string $gateKey
     * @param int|null $tenantId
     * @return array<string, mixed>|null
     */
    public function getGate(string $gateKey, ?int $tenantId = null): ?array
    {
        $tid = $tenantId ?? CurrentTenant::id();
        $this->ensureDefaultGatesSeeded($tid);

        $row = $this->gateModel->findByGateKey(trim($gateKey), $tid);
        if ($row === null) {
            return null;
        }

        return $this->formatGate($row);
    }

    /**
     * Check whether an activation gate is enabled.
     *
     * @param string $gateKey
     * @param int|null $tenantId
     * @return bool
     */
    public function isGateEnabled(string $gateKey, ?int $tenantId = null): bool
    {
        $gate = $this->getGate($gateKey, $tenantId);

        return $gate !== null && (int) $gate['is_enabled'] === 1;
    }

    /**
     * Record an official signoff for an activation gate.
     * Automatically sets is_enabled = 1 and approved_at = now() ONLY when all
     * required_signoffs_json authorities have signed off.
     *
     * @param string $gateKey
     * @param string $authorityRole One of ['guidance_head', 'qa_office', 'dpo']
     * @param string $notes
     * @param int|array<string, mixed>|null $tenantId
     * @param int|array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function recordSignoff(
        string $gateKey,
        string $authorityRole,
        string $notes = '',
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

        $tid  = $tenantId ?? (is_array($actor) && isset($actor['tenant_id']) ? (int) $actor['tenant_id'] : null) ?? CurrentTenant::id();
        $role = strtolower(trim($authorityRole));

        if (!in_array($role, self::VALID_AUTHORITY_ROLES, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    "Invalid signoff authority role '%s'. Allowed roles: %s.",
                    $authorityRole,
                    implode(', ', self::VALID_AUTHORITY_ROLES)
                ),
                422
            );
        }

        $cleanKey = trim($gateKey);
        if ($cleanKey === '') {
            throw new InvalidArgumentException('Activation gate key is required.', 422);
        }

        $this->ensureDefaultGatesSeeded($tid);
        $row = $this->gateModel->findByGateKey($cleanKey, $tid);

        if ($row === null && $cleanKey === self::GATE_GUARDIAN_IDENTITY) {
            $now = date('Y-m-d H:i:s');
            $id  = $this->gateModel->insert([
                'tenant_id'              => $tid,
                'gate_key'               => self::GATE_GUARDIAN_IDENTITY,
                'title'                  => 'Guardian Identity Verification Workflow Signoff',
                'description'            => 'Verified digital guardian identity and authority workflow for external guardian attestations.',
                'required_signoffs_json' => json_encode(self::VALID_AUTHORITY_ROLES, JSON_UNESCAPED_SLASHES),
                'recorded_signoffs_json' => json_encode([], JSON_UNESCAPED_SLASHES),
                'is_enabled'             => 0,
                'approved_at'            => null,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
            $row = $this->gateModel->find((int) $id);
        }

        if ($row === null) {
            throw new InvalidArgumentException("Activation gate '{$cleanKey}' not found.", 404);
        }

        $required = $this->decodeRequiredSignoffs((string) ($row['required_signoffs_json'] ?? '[]'));
        $recorded = $this->decodeRecordedSignoffs((string) ($row['recorded_signoffs_json'] ?? '[]'));

        $actorUserId = $this->resolveActorUserId($actor);
        $now         = date('Y-m-d H:i:s');

        $recorded[$role] = [
            'authority_role' => $role,
            'user_id'        => $actorUserId,
            'notes'          => trim($notes),
            'signed_at'      => $now,
        ];

        $allSigned = $required !== [];
        foreach ($required as $reqRole) {
            if (!isset($recorded[$reqRole])) {
                $allSigned = false;
                break;
            }
        }

        $isEnabled  = $allSigned ? 1 : 0;
        $approvedAt = $allSigned ? ($row['approved_at'] ?? $now) : null;

        $this->gateModel->update((int) $row['id'], [
            'recorded_signoffs_json' => json_encode($recorded, JSON_UNESCAPED_SLASHES),
            'is_enabled'             => $isEnabled,
            'approved_at'            => $approvedAt,
        ]);

        $updated = $this->gateModel->find((int) $row['id']);
        if ($updated === null) {
            throw new RuntimeException("Failed to reload activation gate '{$cleanKey}'.", 500);
        }

        return $this->formatGate($updated);
    }

    /**
     * Assert that an activation gate is enabled and approved.
     * Throws 403 DomainException when the gate is not yet approved/enabled.
     *
     * @param string $gateKey
     * @param int|null $tenantId
     * @throws DomainException
     */
    public function assertGateEnabled(string $gateKey, ?int $tenantId = null): void
    {
        $tid  = $tenantId ?? CurrentTenant::id();
        $gate = $this->getGate($gateKey, $tid);

        if ($gate === null) {
            throw new DomainException(
                sprintf("Activation gate '%s' is not configured or enabled.", $gateKey),
                403
            );
        }

        if ((int) $gate['is_enabled'] !== 1 || empty($gate['approved_at'])) {
            $required = $gate['required_signoffs'] ?? self::VALID_AUTHORITY_ROLES;
            $recorded = array_keys($gate['recorded_signoffs'] ?? []);
            $missing  = array_values(array_diff($required, $recorded));

            throw new DomainException(
                sprintf(
                    "Activation gate '%s' is disabled until all required authorities (%s) sign off. Pending: %s.",
                    $gateKey,
                    implode(', ', $required),
                    implode(', ', $missing ?: $required)
                ),
                403
            );
        }
    }

    /**
     * Enforce v1 signer capacity policy:
     * - Authenticated 'student' and 'staff' (or 'practitioner', 'counselor', 'supervisor') are supported.
     * - 'guardian' (or 'parent') is strictly rejected with 403 DomainException unless
     *   'guardian_identity_workflow' is explicitly approved.
     *
     * @param string $signerCapacity
     * @param int|null $tenantId
     * @throws DomainException
     */
    public function assertSignerCapacityAllowed(string $signerCapacity, ?int $tenantId = null): void
    {
        $tid        = $tenantId ?? CurrentTenant::id();
        $normalized = strtolower(trim($signerCapacity));

        if ($normalized === '') {
            throw new DomainException('Signer capacity is required for digital account attestation.', 403);
        }

        $isGuardian = in_array($normalized, self::GUARDIAN_SIGNER_CAPACITIES, true)
            || str_contains($normalized, 'guardian')
            || str_contains($normalized, 'parent');

        if ($isGuardian) {
            if ($this->isGateEnabled(self::GATE_GUARDIAN_IDENTITY, $tid)) {
                return;
            }

            throw new DomainException(
                'Digital guardian attestation is disabled in v1 until guardian identity verification workflow is approved.',
                403
            );
        }

        if (!in_array($normalized, self::ALLOWED_V1_SIGNER_CAPACITIES, true)) {
            throw new DomainException(
                sprintf("Signer capacity '%s' is not permitted for digital account attestation in v1.", $signerCapacity),
                403
            );
        }
    }

    /**
     * Ensure the 6 default activation gates exist for the given tenant.
     */
    private function ensureDefaultGatesSeeded(int $tenantId): void
    {
        $existingCount = $this->gateModel->scoped($tenantId)->countAllResults();
        if ($existingCount > 0) {
            return;
        }

        $now              = date('Y-m-d H:i:s');
        $requiredSignoffs = json_encode(self::VALID_AUTHORITY_ROLES, JSON_UNESCAPED_SLASHES);
        $recordedSignoffs = json_encode([], JSON_UNESCAPED_SLASHES);

        foreach (self::DEFAULT_GATES as $gate) {
            $this->db->table('guidance_activation_gates')->insert([
                'tenant_id'              => $tenantId,
                'gate_key'               => $gate['gate_key'],
                'title'                  => $gate['title'],
                'description'            => $gate['description'],
                'required_signoffs_json' => $requiredSignoffs,
                'recorded_signoffs_json' => $recordedSignoffs,
                'is_enabled'             => 0,
                'approved_at'            => null,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
        }
    }

    /**
     * Format raw database gate row with decoded JSON arrays and normalized types.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatGate(array $row): array
    {
        $required = $this->decodeRequiredSignoffs((string) ($row['required_signoffs_json'] ?? '[]'));
        $recorded = $this->decodeRecordedSignoffs((string) ($row['recorded_signoffs_json'] ?? '[]'));

        return [
            'id'                     => (int) $row['id'],
            'tenant_id'              => (int) $row['tenant_id'],
            'gate_key'               => (string) $row['gate_key'],
            'title'                  => (string) $row['title'],
            'description'            => $row['description'] ?? null,
            'required_signoffs_json' => (string) $row['required_signoffs_json'],
            'recorded_signoffs_json' => (string) $row['recorded_signoffs_json'],
            'required_signoffs'      => $required,
            'recorded_signoffs'      => $recorded,
            'is_enabled'             => (int) $row['is_enabled'],
            'approved_at'            => $row['approved_at'] ?? null,
            'created_at'             => $row['created_at'] ?? null,
            'updated_at'             => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function decodeRequiredSignoffs(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return self::VALID_AUTHORITY_ROLES;
        }

        $result = [];
        foreach ($decoded as $role) {
            if (is_string($role) && trim($role) !== '') {
                $result[] = strtolower(trim($role));
            }
        }

        return $result ?: self::VALID_AUTHORITY_ROLES;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decodeRecordedSignoffs(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || $decoded === []) {
            return [];
        }

        $normalized = [];
        foreach ($decoded as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $normalized[strtolower(trim($key))] = $entry;
            } elseif (is_array($entry) && !empty($entry['authority_role'])) {
                $roleKey = strtolower(trim((string) $entry['authority_role']));
                $normalized[$roleKey] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed>|null $actor
     */
    private function resolveActorUserId(?array $actor): ?int
    {
        if ($actor !== null) {
            foreach (['id', 'user_id', 'actor_user_id'] as $key) {
                if (!empty($actor[$key]) && is_numeric($actor[$key])) {
                    return (int) $actor[$key];
                }
            }
        }

        try {
            $current = (new AuthenticatedUser())->current();
            if (is_array($current) && !empty($current['id'])) {
                return (int) $current['id'];
            }
        } catch (Throwable) {
            // Fall through
        }

        return null;
    }
}
