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

/**
 * Metadata-only controlled-form graph boundary. No ciphertext is loaded or decrypted here.
 *
 * Callers must supply authority freshly resolved by GuidanceAccessPolicy, not transport hints.
 * It is reloaded on this connection as well, so injected collaborators cannot supply foreign
 * grants. Public services still enforce their operation-specific permission/credential gates.
 */
final class GuidanceFormContextPolicy
{
    private BaseConnection $db;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidancePractitionerService $practitionerService;

    private const LINKS = ['person_id', 'case_id', 'session_id', 'episode_id', 'appointment_id', 'referral_id', 'intake_id'];
    private const OPERATIONAL_FORMS = [
        'guidance_appointment_form' => 'F-OGC-GAP-006-00',
        'guidance_call_slip' => 'F-OGC-GSP-CSP-007-00',
    ];
    private const TABLES = [
        'case' => ['counselling_cases', ['id', 'tenant_id', 'person_id', 'counsellor_user_id', 'deleted_at']],
        'session' => ['counselling_sessions', ['id', 'tenant_id', 'case_id', 'appointment_id', 'deleted_at']],
        'episode' => ['guidance_episodes', ['id', 'tenant_id', 'person_id', 'case_id', 'intake_referral_id', 'primary_counselor_user_id', 'deleted_at']],
        'appointment' => ['guidance_appointments', ['id', 'tenant_id', 'person_id', 'episode_id', 'form_instance_id', 'deleted_at']],
        'referral' => ['referrals', ['id', 'tenant_id', 'patient_person_id', 'target_case_id', 'source_module', 'target_module', 'deleted_at']],
        'intake' => ['guidance_intake_referrals', ['id', 'tenant_id', 'subject_person_id', 'linked_case_id', 'clinic_referral_id', 'deleted_at']],
    ];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
        $this->accessPolicy = new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = new GuidancePractitionerService(
            profileModel: new GuidancePractitionerProfileModel($this->db),
            supervisionModel: new GuidanceSupervisionAssignmentModel($this->db),
            userModel: new UserModel($this->db),
            accessPolicy: $this->accessPolicy,
            db: $this->db
        );
    }

    /**
     * Validate an existing instance without changing stored context or granting access
     * from its stored supervisor snapshot. A null direct case can have an effective case
     * through its links; a conflicting non-null direct case/person is never repaired.
     *
     * @return array<string,mixed> Validated metadata, effective IDs, and refreshed authority.
     */
    public function assertInstanceAccess(
        array $instance,
        array $authority,
        bool $write = false,
        bool $allowSupervisor = false
    ): array {
        $authority = $this->accessPolicy->authorize($authority, lock: false);
        $tenantId = (int) $authority['tenant_id'];
        if ((int) ($instance['tenant_id'] ?? 0) !== $tenantId) {
            throw new InvalidArgumentException('Form instance not found.', 404);
        }
        $instanceId = $this->id($instance['id'] ?? null, false, true);
        $authorId = $this->id($instance['created_by_user_id'] ?? null, false, true);
        // Historical creators may be inactive/deleted; only the current reader's authority
        // must be active. The creator reference must still belong to this tenant.
        $this->row('users', $authorId, $tenantId, ['id', 'tenant_id'], false);
        $definition = $this->definition($instance, $tenantId, false);
        $context = $this->graph($instance, $tenantId, false, $instanceId);
        $context['definition'] = $definition;
        $context['authority'] = $authority;
        $context['is_operational'] = $this->isOperational($definition);
        $context['supervision'] = $this->currentSupervision($authorId, $tenantId);
        $context['is_current_supervisor'] = $context['supervision'] !== null
            && (int) $context['supervision']['supervisor_user_id'] === (int) $authority['id']
            && $this->accessPolicy->canFinalizeDirectly((int) $authority['id'], $tenantId);

        $this->assertPermission($definition, $authority, $write);
        $this->assertEpisodeAccess($context, $authority, $write);
        $isSupervisor = $allowSupervisor && $context['is_current_supervisor'];
        if ($context['case'] !== null) {
            try {
                if ($write) {
                    $this->accessPolicy->assertCanWriteCase($context['case'], $authority);
                } else {
                    $this->accessPolicy->assertCanReadCase($context['case'], $authority);
                }
            } catch (DomainException $exception) {
                if ($exception->getCode() !== 403 || !$isSupervisor) {
                    throw $exception;
                }
            }
        } elseif (!$context['is_operational']
            && (int) $authority['id'] !== $authorId
            && !$isSupervisor
            && !(!$write && in_array('guidance.cases.read_any', $authority['permissions'], true))) {
            throw new DomainException('Form access requires its creator or current assigned supervisor.', 403);
        }

        return $context;
    }

    /**
     * Creation-only inference: infer an omitted person/case/episode/intake/referral from
     * the explicit linked graph before inserting. This never searches a person's latest
     * case/intake and never runs against an existing instance to overwrite its context.
     */
    public function assertCreationAccess(array $links, array $authority): array
    {
        $authority = $this->accessPolicy->authorize($authority, lock: false);
        $tenantId = (int) $authority['tenant_id'];
        if (isset($links['tenant_id']) && (int) $links['tenant_id'] !== $tenantId) {
            throw new InvalidArgumentException('Form context not found in active tenant.', 404);
        }
        $definition = $this->definition($links, $tenantId, true);
        $context = $this->graph($links, $tenantId, true);
        $this->assertPermission($definition, $authority, true);
        $this->assertEpisodeAccess($context, $authority, true);
        if ($context['case'] !== null) {
            $this->accessPolicy->assertCanWriteCase($context['case'], $authority);
        }
        $context['definition'] = $definition;
        $context['authority'] = $authority;
        $context['is_operational'] = $this->isOperational($definition);
        return $context;
    }

    /** @return array<string,mixed> */
    private function graph(array $links, int $tenantId, bool $creation, ?int $instanceId = null): array
    {
        $ids = [];
        foreach (self::LINKS as $field) {
            $ids[$field] = $this->id($links[$field] ?? null, $creation);
        }
        if (!$creation && $ids['person_id'] === null) {
            throw new InvalidArgumentException('Person not found in active tenant.', 404);
        }
        $context = array_fill_keys(array_keys(self::TABLES), null);
        $context['shared_appointment'] = null;
        $context['call_slip'] = null;

        // Operational services create the form before their appointment/call-slip row.
        // These reverse links therefore validate the stored graph, not creation inputs.
        if ($instanceId !== null) {
            foreach (['guidance_appointments', 'guidance_call_slips'] as $table) {
                if (!$this->db->tableExists($table)) continue;
                $fields = $table === 'guidance_appointments'
                    ? self::TABLES['appointment'][1]
                    : ['id', 'tenant_id', 'person_id', 'episode_id', 'form_instance_id', 'deleted_at'];
                $rows = $this->db->table($table)->select($this->columns($table, $fields))
                    ->where('form_instance_id', $instanceId)->get()->getResultArray();
                foreach ($rows as $row) {
                    $this->assertRow($row, $tenantId);
                    $this->mergeId($ids, 'person_id', $this->id($row['person_id'] ?? null, $creation, true));
                    $this->mergeId($ids, 'episode_id', $this->id($row['episode_id'] ?? null, $creation));
                    if ($table === 'guidance_appointments') {
                        $this->mergeId($ids, 'appointment_id', (int) $row['id']);
                    } else {
                        if ($context['call_slip'] !== null && (int) $context['call_slip']['id'] !== (int) $row['id']) {
                            throw new InvalidArgumentException('Form call-slip linkage is inconsistent.', 404);
                        }
                        $context['call_slip'] = $row;
                    }
                }
            }
        }

        // Load every direct and implied edge exactly once, merging only agreeing IDs.
        do {
            $loaded = false;
            foreach (self::TABLES as $kind => [$table, $fields]) {
                $field = $kind . '_id';
                if ($ids[$field] === null || $context[$kind] !== null) continue;
                $row = $this->row($table, $ids[$field], $tenantId, $fields);
                $context[$kind] = $row;
                $loaded = true;
                if ($kind === 'case') {
                    $this->mergeId($ids, 'person_id', $this->id($row['person_id'] ?? null, $creation, true));
                } elseif ($kind === 'session') {
                    $this->mergeId($ids, 'case_id', $this->id($row['case_id'] ?? null, $creation, true));
                    $sharedId = $this->id($row['appointment_id'] ?? null, $creation);
                    if ($sharedId !== null) {
                        // Sessions use shared appointments, not guidance_appointments.
                        // Equal numeric IDs across those two tables are not the same link.
                        $shared = $this->row('appointments', $sharedId, $tenantId,
                            ['id', 'tenant_id', 'person_id', 'unit', 'deleted_at']);
                        if (($shared['unit'] ?? '') !== 'guidance') {
                            throw new InvalidArgumentException('Session appointment context is inconsistent.', 404);
                        }
                        $this->mergeId($ids, 'person_id', $this->id($shared['person_id'] ?? null, $creation, true));
                        $context['shared_appointment'] = $shared;
                    }
                } elseif ($kind === 'episode') {
                    $this->mergeId($ids, 'person_id', $this->id($row['person_id'] ?? null, $creation, true));
                    $this->mergeId($ids, 'case_id', $this->id($row['case_id'] ?? null, $creation));
                    $this->mergeId($ids, 'intake_id', $this->id($row['intake_referral_id'] ?? null, $creation));
                } elseif ($kind === 'appointment') {
                    $this->mergeId($ids, 'person_id', $this->id($row['person_id'] ?? null, $creation, true));
                    $this->mergeId($ids, 'episode_id', $this->id($row['episode_id'] ?? null, $creation));
                    $boundFormId = $this->id($row['form_instance_id'] ?? null, $creation);
                    if ($instanceId !== null && $boundFormId !== null && $boundFormId !== $instanceId) {
                        throw new InvalidArgumentException('Form appointment linkage is inconsistent.', 404);
                    }
                } elseif ($kind === 'referral') {
                    $this->mergeId($ids, 'person_id', $this->id($row['patient_person_id'] ?? null, $creation, true));
                    $this->mergeId($ids, 'case_id', $this->id($row['target_case_id'] ?? null, $creation));
                } elseif ($kind === 'intake') {
                    $this->mergeId($ids, 'person_id', $this->id($row['subject_person_id'] ?? null, $creation, true));
                    $this->mergeId($ids, 'case_id', $this->id($row['linked_case_id'] ?? null, $creation));
                    $this->mergeId($ids, 'referral_id', $this->id($row['clinic_referral_id'] ?? null, $creation));
                }
            }
        } while ($loaded);

        if ($ids['person_id'] === null) {
            throw new InvalidArgumentException('Person ID is required for form instance.', $creation ? 422 : 404);
        }
        $context['person'] = $this->row('people', $ids['person_id'], $tenantId, ['id', 'tenant_id', 'deleted_at']);
        return array_merge($context, $ids, ['effective_case_id' => $ids['case_id']]);
    }

    /** A case-less episode is not a public person bucket or creation ownership bypass. */
    private function assertEpisodeAccess(array $context, array $authority, bool $write): void
    {
        $episode = $context['episode'];
        if ($episode === null || $context['case'] !== null) return;
        $ownerId = (int) ($episode['primary_counselor_user_id'] ?? 0);
        $actorId = (int) $authority['id'];
        if ($ownerId === $actorId) return;
        if (!$write && in_array('guidance.cases.read_any', $authority['permissions'] ?? [], true)) return;
        $supervision = $ownerId > 0 ? $this->currentSupervision($ownerId, (int) $authority['tenant_id']) : null;
        if ($supervision !== null && (int) $supervision['supervisor_user_id'] === $actorId
            && $this->accessPolicy->canFinalizeDirectly($actorId, (int) $authority['tenant_id'])) return;
        throw new DomainException('Linked episode access forbidden.', 403);
    }

    /** Return only the current, not an expired/revoked/replaced snapshot assignment. */
    private function currentSupervision(int $authorId, int $tenantId): ?array
    {
        if (!$this->db->tableExists('guidance_supervision_assignments')) return null;
        $supervision = $this->practitionerService->getActiveSupervision($authorId, $tenantId);
        if ($supervision === null) return null;
        $now = time();
        foreach (['starts_at', 'ends_at'] as $field) {
            if (empty($supervision[$field])) continue;
            $time = strtotime((string) $supervision[$field]);
            if ($time === false || ($field === 'starts_at' && $time > $now)
                || ($field === 'ends_at' && $time <= $now)) return null;
        }
        return $supervision;
    }

    private function definition(array $links, int $tenantId, bool $creation): array
    {
        $definitionId = $this->id($links['form_definition_id'] ?? null, $creation, true);
        $definition = $this->row('guidance_form_definitions', $definitionId, $tenantId,
            ['id', 'tenant_id', 'internal_key', 'form_code', 'canonical_title', 'is_active']);
        $revisionId = $this->id($links['definition_revision_id'] ?? null, $creation, true);
        $revision = $this->row('guidance_form_definition_revisions', $revisionId, $tenantId,
            ['id', 'tenant_id', 'form_definition_id']);
        if ((int) ($revision['form_definition_id'] ?? 0) !== $definitionId) {
            throw new InvalidArgumentException('Form definition revision context is inconsistent.', 404);
        }
        return $definition;
    }

    private function isOperational(array $definition): bool
    {
        $key = (string) ($definition['internal_key'] ?? '');
        return isset(self::OPERATIONAL_FORMS[$key])
            && (string) ($definition['form_code'] ?? '') === self::OPERATIONAL_FORMS[$key];
    }

    /** Scheduling grants cannot authorize confidential forms merely by selecting a different type. */
    private function assertPermission(array $definition, array $authority, bool $write): void
    {
        $permitted = $write
            ? ['guidance.cases.write', 'guidance.forms.manage', 'guidance.clinical.author',
                'guidance.counseling.author', 'guidance.psychosocial.author', 'guidance.clinical.cosign',
                'guidance.counseling.cosign', 'guidance.psychosocial.cosign']
            : ['guidance.cases.read', 'guidance.cases.read_any', 'guidance.forms.manage',
                'guidance.clinical.author', 'guidance.counseling.author', 'guidance.psychosocial.author',
                'guidance.clinical.cosign', 'guidance.counseling.cosign', 'guidance.psychosocial.cosign'];
        if ($this->isOperational($definition)) {
            $permitted = array_merge($permitted, ['guidance.appointments.manage', 'guidance.call_slips.issue']);
        }
        if (array_intersect($permitted, $authority['permissions'] ?? []) === []) {
            throw new DomainException('Guidance permission forbidden.', 403);
        }
    }

    /** @return array<string,mixed> */
    private function row(string $table, int $id, int $tenantId, array $fields, bool $active = true): array
    {
        if (!$this->db->tableExists($table)) {
            throw new InvalidArgumentException('Linked form context not found.', 404);
        }
        $row = $this->db->table($table)->select($this->columns($table, $fields))
            ->where('id', $id)->where('tenant_id', $tenantId)->get()->getRowArray();
        if ($row === null) {
            $message = $table === 'people' ? 'Person not found in active tenant.' : 'Linked form context not found.';
            throw new InvalidArgumentException($message, 404);
        }
        $this->assertRow($row, $tenantId, $active);
        return $row;
    }

    private function columns(string $table, array $fields): array
    {
        // Optional edges were added over successive schema versions; never SELECT * from
        // clinical tables just to discover which edges are present.
        return array_values(array_intersect($fields, $this->db->getFieldNames($table)));
    }

    private function assertRow(array $row, int $tenantId, bool $active = true): void
    {
        if ((int) ($row['tenant_id'] ?? 0) !== $tenantId || $tenantId !== CurrentTenant::id()
            || ($active && ($row['deleted_at'] ?? null) !== null)) {
            throw new InvalidArgumentException('Linked form context not found.', 404);
        }
    }

    private function mergeId(array &$ids, string $field, ?int $id): void
    {
        if ($id === null) return;
        if ($ids[$field] !== null && $ids[$field] !== $id) {
            throw new InvalidArgumentException('Form person or linkage context is inconsistent.', 404);
        }
        $ids[$field] = $id;
    }

    private function id(mixed $value, bool $creation, bool $required = false): ?int
    {
        if ($value === null || ($creation && $value === '')) {
            if (!$required) return null;
        } elseif (is_int($value) || is_string($value)) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) return (int) $id;
        }
        throw new InvalidArgumentException('Invalid form context identifier.', $creation ? 422 : 404);
    }
}
