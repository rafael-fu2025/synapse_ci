<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Models\GuidancePractitionerProfileModel;
use App\Models\GuidanceSupervisionAssignmentModel;
use App\Models\UserModel;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Service managing Guidance Practitioner credential tiers, supervision assignments,
 * service capability scopes, and clinical record finalization/co-sign eligibility.
 */
class GuidancePractitionerService
{
    public const TIERS = [
        'counselor_associate',
        'licensed_counselor',
        'guidance_supervisor',
        'qualified_assessor',
    ];

    public const QUALIFIED_TIERS = self::TIERS;

    public const STANDINGS = [
        'active',
        'suspended',
        'lapsed',
    ];

    private GuidancePractitionerProfileModel $profileModel;
    private GuidanceSupervisionAssignmentModel $supervisionModel;
    private UserModel $userModel;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;

    public function __construct(
        ?GuidancePractitionerProfileModel $profileModel = null,
        ?GuidanceSupervisionAssignmentModel $supervisionModel = null,
        ?UserModel $userModel = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->profileModel = $profileModel ?? new GuidancePractitionerProfileModel();
        $this->supervisionModel = $supervisionModel ?? new GuidanceSupervisionAssignmentModel();
        $this->userModel = $userModel ?? new UserModel();
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
    }

    /**
     * Register or update a practitioner profile with credential tier, license, and capability scopes.
     *
     * @param array<int, string> $scope
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function registerProfile(
        int $userId,
        string $tier,
        ?string $licenseNumber,
        ?string $expiry,
        array $scope,
        ?array $actor = null
    ): array {
        if (!in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException("Invalid credential tier: {$tier}");
        }

        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());

        // Validate user belongs to this tenant
        $user = $this->db->table('users')
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if ($user === null) {
            throw new InvalidArgumentException("User {$userId} not found in tenant {$tenantId}.", 404);
        }

        $personId = !empty($user['person_id']) ? (int) $user['person_id'] : null;
        $formattedExpiry = ($expiry !== null && trim($expiry) !== '')
            ? date('Y-m-d', strtotime($expiry))
            : null;

        $scopes = array_values(array_unique(array_filter($scope, 'is_string')));
        $scopeJson = json_encode($scopes, JSON_UNESCAPED_SLASHES);
        $now = date('Y-m-d H:i:s');

        $existing = $this->profileModel->scoped($tenantId)->where('user_id', $userId)->first();
        if ($existing !== null) {
            $this->profileModel->scoped($tenantId)->update((int) $existing['id'], [
                'person_id' => $personId,
                'credential_tier' => $tier,
                'license_number' => $licenseNumber,
                'credential_expiry' => $formattedExpiry,
                'professional_standing' => 'active',
                'service_scope_json' => $scopeJson,
                'is_active' => 1,
                'updated_at' => $now,
            ]);
            $profileId = (int) $existing['id'];
        } else {
            $profileId = (int) $this->profileModel->insert([
                'tenant_id' => $tenantId,
                'person_id' => $personId,
                'user_id' => $userId,
                'credential_tier' => $tier,
                'license_number' => $licenseNumber,
                'credential_expiry' => $formattedExpiry,
                'professional_standing' => 'active',
                'service_scope_json' => $scopeJson,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $profile = $this->profileModel->scoped($tenantId)->find($profileId);
        if ($profile !== null) {
            $profile['service_scope'] = json_decode((string) ($profile['service_scope_json'] ?? '[]'), true) ?: [];
        }

        return $profile ?? [];
    }

    /**
     * Assign a supervisor to a counselor associate, retiring any previous active supervision.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function assignSupervisor(
        int $associateUserId,
        int $supervisorUserId,
        bool $coSignRequired = true,
        ?array $actor = null
    ): array {
        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());

        if ($associateUserId === $supervisorUserId) {
            throw new DomainException('Associate cannot supervise themselves.', 422);
        }

        $associate = $this->db->table('users')
            ->where('id', $associateUserId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if ($associate === null) {
            throw new InvalidArgumentException("Associate user {$associateUserId} not found.", 404);
        }

        $supervisor = $this->db->table('users')
            ->where('id', $supervisorUserId)
            ->where('tenant_id', $tenantId)
            ->where('deleted_at IS NULL')
            ->get()->getRowArray();
        if ($supervisor === null) {
            throw new InvalidArgumentException("Supervisor user {$supervisorUserId} not found.", 404);
        }

        $now = date('Y-m-d H:i:s');

        // Close prior active supervision
        $this->db->table('guidance_supervision_assignments')
            ->where('tenant_id', $tenantId)
            ->where('associate_user_id', $associateUserId)
            ->where('status', 'active')
            ->update([
                'status' => 'completed',
                'ends_at' => $now,
            ]);

        $assignmentId = (int) $this->supervisionModel->insert([
            'tenant_id' => $tenantId,
            'associate_user_id' => $associateUserId,
            'supervisor_user_id' => $supervisorUserId,
            'status' => 'active',
            'co_sign_required' => $coSignRequired ? 1 : 0,
            'starts_at' => $now,
            'ends_at' => null,
            'created_at' => $now,
        ]);

        return $this->supervisionModel->scoped($tenantId)->find($assignmentId) ?? [];
    }

    /**
     * Retrieve practitioner profile with decoded service scope array.
     *
     * @return array<string, mixed>|null
     */
    public function getPractitionerProfile(int $userId, ?int $tenantId = null): ?array
    {
        $tenantId ??= CurrentTenant::id();
        $profile = $this->profileModel->scoped($tenantId)->where('user_id', $userId)->first();
        if ($profile !== null) {
            $profile['service_scope'] = json_decode((string) ($profile['service_scope_json'] ?? '[]'), true) ?: [];
        }

        return $profile;
    }

    /**
     * Retrieve current active supervision record for an associate.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveSupervision(int $associateUserId, ?int $tenantId = null): ?array
    {
        $tenantId ??= CurrentTenant::id();

        return $this->supervisionModel->scoped($tenantId)
            ->where('associate_user_id', $associateUserId)
            ->where('status', 'active')
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Checks if tier is licensed_counselor or guidance_supervisor with active standing.
     * If counselor_associate, returns false (requires co-sign).
     * Superadmin/admin role NEVER bypasses this check.
     */
    public function canFinalizeDirectly(int $userId, ?int $tenantId = null): bool
    {
        $tenantId ??= CurrentTenant::id();
        $profile = $this->getPractitionerProfile($userId, $tenantId);
        if ($profile === null) {
            return false;
        }

        if ((int) ($profile['is_active'] ?? 0) !== 1) {
            return false;
        }

        if (($profile['professional_standing'] ?? '') !== 'active') {
            return false;
        }

        if (!empty($profile['credential_expiry'])) {
            if (strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
                return false;
            }
        }

        $tier = (string) ($profile['credential_tier'] ?? '');
        if ($tier === 'counselor_associate') {
            return false;
        }

        return in_array($tier, ['licensed_counselor', 'guidance_supervisor'], true);
    }

    /**
     * Ensures active profile has the required scope (e.g. 'counseling', 'psychosocial', 'intake', 'assessment_interpretation').
     * Throws DomainException (403) on failure.
     */
    public function assertEligibilityForService(int $userId, string $serviceCapability, ?int $tenantId = null): void
    {
        $tenantId ??= CurrentTenant::id();
        $profile = $this->getPractitionerProfile($userId, $tenantId);
        if ($profile === null) {
            throw new DomainException("Practitioner profile not found for user {$userId}.", 403);
        }

        if ((int) ($profile['is_active'] ?? 0) !== 1 || ($profile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException("Practitioner is not in active professional standing.", 403);
        }

        if (!empty($profile['credential_expiry']) && strtotime((string) $profile['credential_expiry']) < strtotime(date('Y-m-d'))) {
            throw new DomainException("Practitioner credentials have expired.", 403);
        }

        $scope = $profile['service_scope'] ?? [];
        if (!in_array($serviceCapability, $scope, true)) {
            throw new DomainException("Practitioner lacks required '{$serviceCapability}' capability scope.", 403);
        }
    }

    /**
     * Validates whether an actor can finalize a record authored by $authorUserId.
     * Enforces that associate records require supervisor co-sign.
     *
     * @return array<string, mixed>
     */
    public function validateNoteFinalization(
        int $authorUserId,
        int $finalizerUserId,
        ?int $tenantId = null
    ): array {
        $tenantId ??= CurrentTenant::id();

        $authorProfile = $this->getPractitionerProfile($authorUserId, $tenantId);
        if ($authorProfile === null) {
            throw new DomainException("Author practitioner profile not found for user {$authorUserId}.", 403);
        }

        if ((int) ($authorProfile['is_active'] ?? 0) !== 1 || ($authorProfile['professional_standing'] ?? '') !== 'active') {
            throw new DomainException('Author practitioner is not in active standing.', 403);
        }

        $isAssociate = ($authorProfile['credential_tier'] ?? '') === 'counselor_associate';

        if ($isAssociate) {
            // Associate authored record
            if ($finalizerUserId === $authorUserId) {
                throw new DomainException('Associate authored records require supervisor co-sign to reach finalized.', 403);
            }

            // Finalizer must be a supervisor with active standing
            if (!$this->canFinalizeDirectly($finalizerUserId, $tenantId)) {
                throw new DomainException('Co-signer must have active licensed/supervisor standing.', 403);
            }

            // Check supervisor assignment or permission
            $supervision = $this->getActiveSupervision($authorUserId, $tenantId);
            $isAssigned = $supervision !== null && (int) $supervision['supervisor_user_id'] === $finalizerUserId;

            return [
                'can_finalize' => true,
                'requires_cosign' => true,
                'is_supervisor' => true,
                'is_assigned_supervisor' => $isAssigned,
            ];
        }

        // Licensed counselor or guidance supervisor author
        if ($finalizerUserId === $authorUserId) {
            if (!$this->canFinalizeDirectly($authorUserId, $tenantId)) {
                throw new DomainException('Author practitioner lacks active standing to finalize.', 403);
            }

            return [
                'can_finalize' => true,
                'requires_cosign' => false,
                'is_supervisor' => false,
            ];
        }

        // Non-author finalizer on licensed author record
        if (!$this->canFinalizeDirectly($finalizerUserId, $tenantId)) {
            throw new DomainException('Clinical record finalization requires active practitioner standing.', 403);
        }

        return [
            'can_finalize' => true,
            'requires_cosign' => false,
            'is_supervisor' => true,
        ];
    }

    /**
     * Submit an associate record from 'draft' to 'pending_cosign'.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function submitForCoSign(
        int $recordId,
        string $recordType = 'form_instance',
        ?array $actor = null
    ): array {
        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
        $actorId = (int) ($actor['id'] ?? 0);

        if ($recordType === 'form_instance') {
            $instance = $this->db->table('guidance_form_instances')
                ->where('id', $recordId)
                ->where('tenant_id', $tenantId)
                ->get()->getRowArray();
            if ($instance === null) {
                throw new InvalidArgumentException("Form instance {$recordId} not found.", 404);
            }

            if ($instance['status'] !== 'draft') {
                throw new DomainException("Cannot submit record for co-sign: current status is '{$instance['status']}'.", 409);
            }

            $this->db->table('guidance_form_instances')
                ->where('id', $recordId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'status' => 'pending_cosign',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return $this->db->table('guidance_form_instances')->where('id', $recordId)->get()->getRowArray() ?? [];
        }

        throw new InvalidArgumentException("Unsupported record type: {$recordType}");
    }

    /**
     * Supervisor co-signs a 'pending_cosign' record, transitioning it to 'finalized'.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function coSignRecord(
        int $recordId,
        string $recordType = 'form_instance',
        ?string $notes = null,
        ?array $actor = null
    ): array {
        $tenantId = (int) ($actor['tenant_id'] ?? CurrentTenant::id());
        $supervisorUserId = (int) ($actor['id'] ?? 0);

        if ($recordType === 'form_instance') {
            $instance = $this->db->table('guidance_form_instances')
                ->where('id', $recordId)
                ->where('tenant_id', $tenantId)
                ->get()->getRowArray();
            if ($instance === null) {
                throw new InvalidArgumentException("Form instance {$recordId} not found.", 404);
            }

            if ($instance['status'] !== 'pending_cosign' && $instance['status'] !== 'draft') {
                throw new DomainException("Cannot co-sign record: current status is '{$instance['status']}'.", 409);
            }

            $authorUserId = (int) $instance['created_by_user_id'];
            $this->validateNoteFinalization($authorUserId, $supervisorUserId, $tenantId);

            $now = date('Y-m-d H:i:s');
            $this->db->table('guidance_form_instances')
                ->where('id', $recordId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'status' => 'finalized',
                    'finalized_by_user_id' => $supervisorUserId,
                    'finalized_at' => $now,
                    'updated_at' => $now,
                ]);

            return $this->db->table('guidance_form_instances')->where('id', $recordId)->get()->getRowArray() ?? [];
        }

        throw new InvalidArgumentException("Unsupported record type: {$recordType}");
    }

    /**
     * Human-readable credential tier label distinguishing Associates (PSP) from Licensed Counselors.
     */
    public static function tierDisplayLabel(?string $tier, ?string $fallbackRole = null): string
    {
        return match ($tier) {
            'counselor_associate' => 'Counselor Associate',
            'licensed_counselor'  => 'Licensed Counselor',
            'guidance_supervisor' => 'Guidance Supervisor',
            'qualified_assessor'  => 'Qualified Assessor',
            default               => match ($fallbackRole) {
                'guidance_supervisor' => 'Guidance Supervisor',
                'guidance_admin'      => 'Guidance Admin',
                'counsellor'          => 'Guidance Counselor',
                default               => 'Guidance Staff',
            },
        };
    }

    /**
     * Clinical service distinction requested by Guidance (PSP for Associates vs. Counseling for Licensed staff).
     */
    public static function serviceModeLabel(?string $tier, ?string $fallbackRole = null): string
    {
        return match ($tier) {
            'counselor_associate' => 'Psychosocial Processing (PSP)',
            'qualified_assessor'  => 'Psychological Assessment',
            'licensed_counselor'  => 'Counseling',
            'guidance_supervisor' => 'Counseling & Supervision',
            default               => match ($fallbackRole) {
                'guidance_supervisor' => 'Counseling & Supervision',
                'counsellor'          => 'Counseling',
                default               => 'Guidance Services',
            },
        };
    }

    /**
     * Returns a tenant-scoped directory of Guidance users keyed by user_id with tier and PSP/Counseling labels.
     *
     * @return array<int, array{name: string, tier: ?string, tier_label: string, service_label: string, is_associate: bool}>
     */
    public function getPractitionerDirectory(int $tenantId): array
    {
        $hasProfiles = $this->db->tableExists('guidance_practitioner_profiles');
        $builder = $this->db->table('users u')
            ->select('u.id, u.username, u.role, p.first_name, p.last_name' . ($hasProfiles ? ', gp.credential_tier' : ''))
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left');

        if ($hasProfiles) {
            $builder->join('guidance_practitioner_profiles gp', 'gp.user_id = u.id AND gp.tenant_id = u.tenant_id AND gp.is_active = 1', 'left');
        }

        $rows = $builder
            ->where('u.tenant_id', $tenantId)
            ->where('u.deleted_at IS NULL')
            ->get()
            ->getResultArray();

        $directory = [];
        foreach ($rows as $row) {
            $uid  = (int) ($row['id'] ?? 0);
            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            $tier = isset($row['credential_tier']) && is_string($row['credential_tier']) ? $row['credential_tier'] : null;
            $role = isset($row['role']) && is_string($row['role']) ? $row['role'] : null;
            $directory[$uid] = [
                'name'          => $name !== '' ? $name : (string) ($row['username'] ?? 'Staff #' . $uid),
                'tier'          => $tier,
                'tier_label'    => self::tierDisplayLabel($tier, $role),
                'service_label' => self::serviceModeLabel($tier, $role),
                'is_associate'  => $tier === 'counselor_associate',
            ];
        }

        return $directory;
    }
}

