<?php

declare(strict_types=1);

namespace App\Services\Referrals;

use App\Libraries\TablePage;
use App\Models\PersonModel;
use App\Services\Clinic\EncounterService;
use App\Services\NotificationService;
use App\Models\ReferralModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\GuidanceCaseService;
use Config\Database;
use RuntimeException;

final class ReferralService
{
    private ReferralModel $referralModel;
    private PersonModel $personModel;
    private GuidanceCaseService $caseService;

    public function __construct(
        ?ReferralModel $referralModel = null,
        ?PersonModel $personModel = null,
        ?GuidanceCaseService $caseService = null
    ) {
        $this->referralModel = $referralModel ?? new ReferralModel();
        $this->personModel = $personModel ?? new PersonModel();
        $this->caseService = $caseService ?? new GuidanceCaseService();
    }

    public static function positiveId(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match('/^[1-9][0-9]*$/D', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new RuntimeException('A positive integer ID is required.', 422);
        }
        return (int) $value;
    }

    public static function ownModule(array $actor): ?string
    {
        return match ($actor['role'] ?? null) {
            'clinic_admin', 'clinic_staff' => 'clinic',
            'guidance_admin', 'guidance_supervisor', 'counsellor' => 'guidance',
            'superadmin' => null,
            default => throw new RuntimeException('Referral staff role required.', 403),
        };
    }

    /** Refresh explicit identities as well: caller-provided roles/grants are never authority. */
    public function authorize(array $actor, string $permission, ?string $module = null): array
    {
        $id = self::positiveId($actor['id'] ?? null);
        $tenant = self::positiveId($actor['tenant_id'] ?? null);
        if ($tenant !== CurrentTenant::id()) {
            throw new RuntimeException('Referral tenant forbidden.', 403);
        }
        $fresh = (new AuthenticatedUser())->forIdentity($id, $tenant);
        if ($fresh === null) {
            throw new RuntimeException('Active referral staff identity required.', 403);
        }
        $own = self::ownModule($fresh);
        if ($fresh['role'] !== 'superadmin'
            && (!in_array($permission, $fresh['permissions'], true) || ($module !== null && $own !== $module))) {
            throw new RuntimeException('Referral permission or department forbidden.', 403);
        }
        return $fresh;
    }

    private function actor(int $userId, ?array $context, string $permission, ?string $module = null): array
    {
        self::positiveId($userId);
        if ($context !== null && self::positiveId($context['id'] ?? null) !== $userId) {
            throw new RuntimeException('Referral actor impersonation forbidden.', 403);
        }
        return $this->authorize($context ?? ['id' => $userId, 'tenant_id' => CurrentTenant::id()], $permission, $module);
    }

    public function context(array $actor, ?string $requestedUnit = null): string
    {
        $fresh = $this->authorize($actor, 'referrals.read');
        $own = self::ownModule($fresh);
        if ($requestedUnit !== null && !in_array($requestedUnit, ['clinic', 'guidance'], true)) {
            throw new RuntimeException('Invalid referral workstation.', 422);
        }
        if ($own !== null) {
            if ($requestedUnit !== null && $requestedUnit !== $own) {
                throw new RuntimeException('Referral workstation forbidden.', 403);
            }
            return $own;
        }
        if ($requestedUnit === null) {
            throw new RuntimeException('Select an explicit clinic or guidance workstation.', 422);
        }
        return $requestedUnit;
    }

    public function reasonOptions(string $target): array
    {
        return match ($target) {
            'guidance' => [
                'anxiety_stress' => 'Stress support', 'academic_distress' => 'Academic support',
                'grief_trauma' => 'Grief or difficult experience support', 'behavioral_concern' => 'Behavioral support',
                'personal_support' => 'Personal support', 'career_guidance' => 'Career guidance',
                'family_support' => 'Family support', 'other' => 'Other support',
            ],
            'clinic' => [
                'medical_assessment' => 'Medical assessment', 'medical_followup' => 'Medical follow-up',
                'physical_symptoms' => 'Physical symptoms', 'health_advice' => 'Health advice', 'other' => 'Other health concern',
            ],
            default => throw new RuntimeException('Invalid referral destination.', 422),
        };
    }

    public function createReferral(array $data, ?array $actor = null): array
    {
        $issuer = self::positiveId($data['issuer_user_id'] ?? null);
        $fresh = $this->actor($issuer, $actor, 'referrals.create');
        $source = self::ownModule($fresh);
        $workstation = $data['workstation'] ?? null;
        if ($workstation !== null && (!is_string($workstation) || !in_array($workstation, ['clinic', 'guidance'], true))) {
            throw new RuntimeException('Invalid referral workstation.', 422);
        }
        if ($source === null) {
            if ($workstation === null) {
                throw new RuntimeException('Select an explicit referral workstation.', 422);
            }
            $source = $workstation;
        } elseif ($workstation !== null && $workstation !== $source) {
            throw new RuntimeException('Referral workstation forbidden.', 403);
        }
        $target = $source === 'clinic' ? 'guidance' : 'clinic';
        foreach (['source_module' => $source, 'target_module' => $target] as $field => $expected) {
            if (array_key_exists($field, $data) && $data[$field] !== $expected) {
                throw new RuntimeException('Forged referral routing.', is_string($data[$field]) && $field === 'source_module' ? 403 : 422);
            }
        }
        $key = $data['request_key'] ?? null;
        if (array_key_exists('request_key', $data) && (!is_string($key) || !preg_match('/^[a-fA-F0-9]{64}$/D', $key))) {
            throw new RuntimeException('Request key must be 64 hexadecimal characters.', 422);
        }
        $key = $key === null ? null : strtolower($key);
        $patient = self::positiveId($data['patient_person_id'] ?? null);
        $encounter = $data['source_encounter_id'] ?? null;
        if ($encounter !== null && $encounter !== '') {
            $encounter = self::positiveId($encounter);
            if ($source !== 'clinic') {
                throw new RuntimeException('Only clinic referrals may reference an encounter.', 422);
            }
        } else {
            $encounter = null;
        }
        $reason = $data['reason_code'] ?? null;
        $description = $data['reason_description'] ?? null;
        $priority = $data['priority'] ?? 'routine';
        if (!is_string($reason) || !array_key_exists($reason, $this->reasonOptions($target))
            || !is_string($description) || trim($description) === '' || mb_strlen(trim($description)) > 2000
            || !in_array($priority, ['routine', 'urgent', 'crisis'], true)) {
            throw new RuntimeException('Invalid referral reason or priority.', 422);
        }

        $description = trim($description);
        return $this->transaction(function () use ($actor, $issuer, $source, $target, $patient, $encounter, $reason, $description, $priority, $key): array {
            $fresh = $this->actor($issuer, $actor, 'referrals.create', $source);
            $this->referralModel->lockTenant();
            $payload = [
                'patient_person_id' => $patient, 'source_module' => $source, 'target_module' => $target,
                'source_encounter_id' => $encounter, 'reason_code' => $reason,
                'reason_description' => $description, 'priority' => $priority,
            ];
            if ($key !== null) {
                $db = Database::connect();
                $table = $db->protectIdentifiers($db->prefixTable('referrals'));
                $existing = $db->query("SELECT * FROM {$table} WHERE tenant_id = ? AND issuer_user_id = ? AND request_key = ? FOR UPDATE",
                    [CurrentTenant::id(), $issuer, $key])->getRowArray();
                if ($existing !== null) {
                    foreach ($payload as $field => $value) {
                        if (($value === null && $existing[$field] !== null)
                            || ($value !== null && (string) $value !== (string) $existing[$field])) {
                            throw new RuntimeException('Request key already used for a different referral.', 409);
                        }
                    }
                    return $this->visibleReferral($existing, $fresh);
                }
            }
            $this->patient($patient);
            if ($encounter !== null && Database::connect()->table('clinic_encounters')
                ->where('id', $encounter)->where('tenant_id', CurrentTenant::id())
                ->where('person_id', $patient)->where('deleted_at', null)->get()->getRowArray() === null) {
                throw new RuntimeException('Source encounter not found for this patient.', 422);
            }
            $id = $this->referralModel->insert([
                'tenant_id' => CurrentTenant::id(),
                'referral_code' => $this->referralModel->nextReferralCode(),
                'patient_person_id' => $patient,
                'source_module' => $source,
                'target_module' => $target,
                'source_encounter_id' => $encounter,
                'issuer_user_id' => $issuer,
                'priority' => $priority,
                'reason_code' => trim($reason),
                'reason_description' => $description !== null ? trim($description) : null,
                'status' => 'submitted',
                'request_key' => $key,
            ]);
            if ($id === false) {
                throw new RuntimeException('Referral could not be created.', 422);
            }
            $referral = $this->record((int) $id);
            (new NotificationService())->notifyReferral($referral);
            return $this->visibleReferral($referral, $fresh);
        });
    }

    public function acknowledge(int $referralId, int $userId, ?array $actor = null): array
    {
        return $this->mutate($referralId, $userId, $actor, 'referrals.acknowledge', ['submitted'], [
            'status' => 'acknowledged', 'assigned_user_id' => $userId, 'acknowledged_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function review(int $referralId, int $userId, ?array $actor = null): array
    {
        return $this->mutate($referralId, $userId, $actor, 'referrals.review', ['acknowledged'], [
            'status' => 'under_review', 'assigned_user_id' => $userId,
        ]);
    }

    public function convertToCase(int $referralId, int $counsellorUserId, string $caseTitle, string $category, ?array $actor = null): array
    {
        $ref = $this->record($referralId);
        $fresh = $this->actor($counsellorUserId, $actor, 'referrals.review', 'guidance');
        if ($ref['target_module'] !== 'guidance') {
            throw new RuntimeException('Only incoming guidance referrals can become cases.', 403);
        }
        $result = $this->handoff($referralId, ['mode' => 'create', 'title' => $caseTitle, 'category' => $category], $fresh);
        $case = $this->targetRecord($result['referral'], $result['target_id'], $fresh);
        return ['referral' => $result['referral'], 'case' => ['case' => $case]];
    }

    /** Target identity is mandatory; nullable signature preserves existing call compatibility. */
    public function close(int $referralId, ?array $actor = null): array
    {
        return $this->mutate($referralId, $actor !== null ? self::positiveId($actor['id'] ?? null) : null,
            $actor, 'referrals.close', ['acknowledged', 'under_review', 'converted_to_case'], [
                'status' => 'closed', 'closed_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /** Counts ignore search; pagination.total is the selected tab count, filtered includes q/kind. */
    public function listing(string $unit, string $tab, array $query, array $actor): array
    {
        $this->context($actor, $unit);
        $fresh = $this->authorize($actor, 'referrals.read', $unit);
        if (!in_array($tab, ['incoming', 'sent', 'history'], true)) {
            throw new RuntimeException('Invalid referral tab.', 422);
        }
        $page = TablePage::fromQuery($query);
        $counts = [];
        foreach (['incoming', 'sent', 'history'] as $name) {
            $counts[$name] = $this->listBuilder($unit, $name)->countAllResults();
        }
        $builder = $this->listBuilder($unit, $tab);
        if ($page->kind !== 'all') {
            $builder->where('p.kind', $page->kind);
        }
        if ($page->q !== '') {
            $builder->groupStart()->like('r.referral_code', $page->q)->orLike('p.first_name', $page->q)
                ->orLike('p.last_name', $page->q)->orLike('p.mis_id', $page->q)
                ->orLike('u.username', $page->q)->orLike('ip.first_name', $page->q)
                ->orLike('ip.last_name', $page->q)->groupEnd();
        }
        $filtered = (clone $builder)->countAllResults();
        $pagination = $page->metadata($counts[$tab], $filtered);
        $rows = $builder->select("r.*, p.first_name, p.last_name, p.mis_id, p.kind, COALESCE(NULLIF(TRIM(CONCAT(ip.first_name, ' ', ip.last_name)), ''), u.username) AS issuer_name", false)
            ->orderBy('r.created_at', 'DESC')->orderBy('r.id', 'DESC')
            ->limit($page->perPage, ($pagination['page'] - 1) * $page->perPage)->get()->getResultArray();
        $rows = array_map(fn (array $row): array => $this->visibleReferral($row, $fresh), $rows);
        return ['referrals' => $rows, 'pagination' => $pagination, 'counts' => $counts,
            'filters' => ['unit' => $unit, 'tab' => $tab, 'q' => $page->q, 'kind' => $page->kind,
                'per_page' => $page->perPage]];
    }

    /** Clinical identifiers are capabilities, not part of the shared referral summary. */
    private function visibleReferral(array $ref, array $actor): array
    {
        $clinic = $ref['target_module'] === 'clinic';
        $targetField = $clinic ? 'target_encounter_id' : 'target_case_id';
        $targetId = $ref[$targetField] ?? null;
        $ref['has_target'] = !empty($targetId) || $ref['status'] === 'converted_to_case';
        $ref['can_open_target'] = !empty($targetId)
            && $this->canReadClinicalId($ref, (int) $targetId, $ref['target_module'], $actor);
        $ref['target_encounter_id'] = $clinic && $ref['can_open_target'] ? $targetId : null;
        $ref['target_case_id'] = !$clinic && $ref['can_open_target'] ? $targetId : null;
        if (!empty($ref['source_encounter_id'])
            && !$this->canReadClinicalId($ref, (int) $ref['source_encounter_id'], 'clinic', $actor)) {
            $ref['source_encounter_id'] = null;
        }
        return $ref;
    }

    private function canReadClinicalId(array $ref, int $id, string $module, array $actor): bool
    {
        $superadmin = $actor['role'] === 'superadmin';
        if (!$superadmin && (self::ownModule($actor) !== $module
            || !in_array($module === 'clinic' ? 'clinic.encounters.read' : 'guidance.cases.read', $actor['permissions'], true))) {
            return false;
        }
        $builder = Database::connect()->table($module === 'clinic' ? 'clinic_encounters' : 'counselling_cases')
            ->select('id')->where('tenant_id', CurrentTenant::id())->where('person_id', $ref['patient_person_id'])
            ->where('id', $id)->where('deleted_at', null);
        if ($module === 'guidance' && !$superadmin && !in_array('guidance.cases.read_any', $actor['permissions'], true)) {
            $builder->where('counsellor_user_id', $actor['id']);
        }
        return $builder->get()->getRowArray() !== null;
    }

    private function listBuilder(string $unit, string $tab): \CodeIgniter\Database\BaseBuilder
    {
        $builder = Database::connect()->table('referrals r')->where('r.tenant_id', CurrentTenant::id())
            ->join('people p', 'p.id = r.patient_person_id AND p.tenant_id = r.tenant_id AND p.deleted_at IS NULL')
            ->join('users u', 'u.id = r.issuer_user_id AND u.tenant_id = r.tenant_id')
            ->join('people ip', 'ip.id = u.person_id AND ip.tenant_id = r.tenant_id AND ip.deleted_at IS NULL', 'left');
        if ($tab === 'history') {
            $builder->where('r.status', 'closed')->groupStart()->where('r.source_module', $unit)
                ->orWhere('r.target_module', $unit)->groupEnd();
        } else {
            $builder->where($tab === 'incoming' ? 'r.target_module' : 'r.source_module', $unit)->where('r.status !=', 'closed');
        }
        return $builder;
    }

    public function handoffOptions(int $id, array $actor): array
    {
        $ref = $this->record($id);
        $fresh = $this->handoffActor($ref, $actor);
        $this->validateLinks($ref);
        $patient = Database::connect()->table('people')->select('id,first_name,last_name,mis_id,kind')
            ->where('tenant_id', CurrentTenant::id())->where('id', $ref['patient_person_id'])
            ->where('deleted_at', null)->get()->getRowArray();
        $targets = $this->targetBuilder($ref, $fresh)->orderBy('id', 'DESC')->get()->getResultArray();
        return ['referral' => $this->visibleReferral($ref, $fresh), 'patient' => $patient,
            'encounters' => $ref['target_module'] === 'clinic' ? $targets : [],
            'cases' => $ref['target_module'] === 'guidance' ? $targets : []];
    }

    public function handoff(int $id, array $data, array $actor): array
    {
        return $this->transaction(function () use ($id, $data, $actor): array {
            $ref = $this->record($id, true);
            $fresh = $this->handoffActor($ref, $actor);
            $this->validateLinks($ref);
            $clinic = $ref['target_module'] === 'clinic';
            $field = $clinic ? 'target_encounter_id' : 'target_case_id';
            if (!empty($ref[$field])) {
                $target = $this->targetRecord($ref, (int) $ref[$field], $fresh, true);
                return $this->handoffResult($ref, (int) $target['id'], false, $fresh);
            }
            if ($ref['status'] === 'converted_to_case') {
                throw new RuntimeException('Legacy conversion has no linked target; manual reconciliation required.', 409);
            }
            $mode = $data['mode'] ?? null;
            if (!in_array($mode, ['create', 'link'], true)) {
                throw new RuntimeException('Choose create or link.', 422);
            }
            if ($mode === 'link') {
                $target = $this->targetRecord($ref, self::positiveId($data['target_id'] ?? null), $fresh, true);
                $targetId = (int) $target['id'];
            } else {
                // Keep new downstream work within this referral transaction.
                $this->referralModel->lockTenant();
                $summary = 'Referral ' . $ref['referral_code'] . ' from ' . ucfirst($ref['source_module']) . ': '
                    . mb_substr(trim((string) ($ref['reason_description'] ?? $ref['reason_code'])), 0, 2000);
                if ($clinic) {
                    $package = (new EncounterService())->startEncounter((int) $ref['patient_person_id'], (int) $fresh['id'], null, null, $summary);
                    $targetId = self::positiveId($package['encounter']['id'] ?? null);
                } else {
                    $title = $data['title'] ?? null;
                    $category = $data['category'] ?? null;
                    if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 255
                        || !in_array($category, ['personal', 'academic', 'career', 'family', 'behavioral', 'crisis'], true)) {
                        throw new RuntimeException('Invalid case title or category.', 422);
                    }
                    $package = $this->caseService->createCase((int) $ref['patient_person_id'], (int) $fresh['id'], trim($title), $category, $summary);
                    $targetId = self::positiveId($package['case']['id'] ?? null);
                }
                $this->targetRecord($ref, $targetId, $fresh, true);
            }
            $this->transition($ref, ['acknowledged', 'under_review'], [
                $field => $targetId, 'status' => $clinic ? 'under_review' : 'converted_to_case',
                'assigned_user_id' => $fresh['id'],
            ]);
            return $this->handoffResult($this->record($id), $targetId, $mode === 'create', $fresh);
        });
    }

    private function handoffActor(array $ref, array $actor): array
    {
        $fresh = $this->authorize($actor, 'referrals.read', $ref['target_module']);
        $fresh = $this->authorize($fresh, 'referrals.review', $ref['target_module']);
        $fresh = $this->authorize($fresh, $ref['target_module'] === 'clinic' ? 'clinic.encounters.read' : 'guidance.cases.read', $ref['target_module']);
        $fresh = $this->authorize($fresh, $ref['target_module'] === 'clinic' ? 'clinic.encounters.create' : 'guidance.cases.create', $ref['target_module']);
        if (!in_array($ref['status'], ['acknowledged', 'under_review', 'converted_to_case'], true)) {
            throw new RuntimeException('Referral status does not allow handoff.', 409);
        }
        return $fresh;
    }

    private function targetBuilder(array $ref, array $actor): \CodeIgniter\Database\BaseBuilder
    {
        $clinic = $ref['target_module'] === 'clinic';
        $builder = Database::connect()->table($clinic ? 'clinic_encounters' : 'counselling_cases')
            ->select($clinic ? 'id,person_id,status,started_at,attending_user_id' : 'id,person_id,status,case_number,title,category,counsellor_user_id')
            ->where('tenant_id', CurrentTenant::id())->where('person_id', $ref['patient_person_id'])
            ->where('deleted_at', null)->whereIn('status', $clinic ? ['in_progress'] : ['open', 'active', 'monitoring']);
        if (!$clinic && $actor['role'] !== 'superadmin' && !in_array('guidance.cases.read_any', $actor['permissions'], true)) {
            $builder->where('counsellor_user_id', $actor['id']);
        }
        return $builder;
    }

    private function targetRecord(array $ref, int $id, array $actor, bool $lock = false): array
    {
        $builder = $this->targetBuilder($ref, $actor)->where('id', $id);
        if ($lock) {
            $row = Database::connect()->query($builder->getCompiledSelect() . ' FOR UPDATE')->getRowArray();
        } else {
            $row = $builder->get()->getRowArray();
        }
        if ($row === null) {
            throw new RuntimeException('Open accessible target for this patient not found.', 422);
        }
        return $row;
    }

    private function handoffResult(array $ref, int $id, bool $created, array $actor): array
    {
        return ['referral' => $this->visibleReferral($ref, $actor), 'target_id' => $id,
            'target_url' => ($ref['target_module'] === 'clinic' ? '/clinic/encounters/' : '/guidance/cases/') . $id,
            'created' => $created];
    }

    private function mutate(int $id, ?int $userId, ?array $actor, string $permission, array $from, array $changes): array
    {
        return $this->transaction(function () use ($id, $userId, $actor, $permission, $from, $changes): array {
            $ref = $this->record($id, true);
            if ($userId === null) {
                throw new RuntimeException('Target staff identity required.', 403);
            }
            if ($userId !== null) {
                $fresh = $this->actor($userId, $actor, $permission, $ref['target_module']);
            }
            $this->validateLinks($ref);
            $this->transition($ref, $from, $changes);
            return $this->visibleReferral($this->record($id), $fresh);
        });
    }

    private function validateLinks(array $ref): void
    {
        $this->patient((int) $ref['patient_person_id']);
        if ($ref['source_encounter_id'] !== null) {
            if ($ref['source_module'] !== 'clinic' || Database::connect()->table('clinic_encounters')
                ->where('id', $ref['source_encounter_id'])->where('tenant_id', CurrentTenant::id())
                ->where('person_id', $ref['patient_person_id'])->where('deleted_at', null)->get()->getRowArray() === null) {
                throw new RuntimeException('Invalid source encounter relationship.', 422);
            }
        }
    }

    private function patient(int $id): void
    {
        if ($this->personModel->scoped()->find($id) === null) {
            throw new RuntimeException('Patient not found.', 422);
        }
    }

    private function record(int $id, bool $lock = false): array
    {
        self::positiveId($id);
        if ($lock) {
            $db = Database::connect();
            $table = $db->protectIdentifiers($db->prefixTable('referrals'));
            $ref = $db->query("SELECT * FROM {$table} WHERE tenant_id = ? AND id = ? FOR UPDATE", [CurrentTenant::id(), $id])->getRowArray();
        } else {
            $ref = $this->referralModel->scoped()->find($id);
        }
        if ($ref === null) {
            throw new RuntimeException('Referral not found.', 404);
        }
        if (!in_array($ref['source_module'], ['clinic', 'guidance'], true)
            || !in_array($ref['target_module'], ['clinic', 'guidance'], true)
            || $ref['source_module'] === $ref['target_module']) {
            throw new RuntimeException('Invalid referral module state.', 409);
        }
        return $ref;
    }

    private function transition(array $ref, array $from, array $changes): void
    {
        if (!in_array($ref['status'], $from, true)) {
            throw new RuntimeException('Referral status does not allow this action.', 409);
        }
        // Compare-and-swap serializes competing actions without a driver-specific row lock.
        $db = Database::connect();
        $ok = $db->table('referrals')->where('tenant_id', CurrentTenant::id())->where('id', $ref['id'])
            ->where('status', $ref['status'])->update($changes + ['updated_at' => date('Y-m-d H:i:s')]);
        if (!$ok || $db->affectedRows() !== 1) {
            throw new RuntimeException('Referral changed; reload before retrying.', 409);
        }
    }

    private function transaction(callable $operation): array
    {
        $db = Database::connect();
        if ($db->transDepth !== 0) {
            throw new RuntimeException('Referral operations require an independent transaction.', 409);
        }
        $db->resetTransStatus();
        if (!$db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') || !$db->transBegin()) {
            throw new RuntimeException('Referral transaction could not begin.', 409);
        }
        try {
            $result = $operation();
            if ($db->transStatus() === false) {
                throw new RuntimeException('Referral transaction failed.', 409);
            }
            if (!$db->transCommit()) {
                throw new RuntimeException('Referral transaction could not commit.', 409);
            }
            return $result;
        } catch (\Throwable $e) {
            try {
                $db->transRollback();
            } finally {
                $db->resetTransStatus();
            }
            throw $e;
        }
    }
}
