<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidancePractitionerService;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceGovernanceController extends BaseController
{
    private GuidanceActivationGateService $gateService;
    private GuidancePractitionerService $practitionerService;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;
    private ?Closure $actorResolver = null;

    public function __construct(
        ?GuidanceActivationGateService $gateService = null,
        ?GuidancePractitionerService $practitionerService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null,
        ?callable $actorResolver = null
    ) {
        $this->db                  = $db ?? Database::connect();
        $this->gateService         = $gateService ?? new GuidanceActivationGateService(db: $this->db);
        $this->practitionerService = $practitionerService ?? new GuidancePractitionerService(db: $this->db);
        $this->accessPolicy        = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->actorResolver       = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
    }

    /**
     * GET guidance/governance/gates
     * Lists rollout activation gates and recorded multi-authority sign-offs.
     */
    public function gates()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $actor       = $this->freshActor('guidance.cases.read');
            $tenantId    = (int) $actor['tenant_id'];
            $gates       = $this->gateService->getGates($tenantId);
            $canSignOff  = ($actor['role'] ?? '') === 'superadmin'
                || in_array('guidance.forms.manage', $actor['permissions'] ?? [], true);

            $userIds = [];
            foreach ($gates as $gate) {
                foreach ($gate['recorded_signoffs'] ?? [] as $signoff) {
                    if (!empty($signoff['user_id'])) {
                        $userIds[] = (int) $signoff['user_id'];
                    }
                }
            }

            return view('guidance/governance/gates', [
                'title'          => 'Rollout Activation Gates',
                'pageTitle'      => 'Guidance Rollout Activation Gates',
                'gates'          => $gates,
                'authorityRoles' => GuidanceActivationGateService::VALID_AUTHORITY_ROLES,
                'signerNames'    => $this->loadUserNames($userIds, $tenantId),
                'canSignOff'     => $canSignOff,
                'actor'          => $actor,
                'breadcrumbs'    => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Governance', 'url' => site_url('/guidance/governance/gates')],
                    ['label' => 'Activation Gates', 'url' => ''],
                ],
            ]);
        } catch (Throwable $error) {
            return $this->renderReadFailure($error, 'gates');
        }
    }

    /**
     * POST guidance/governance/gates/(:segment)/signoff
     * Records an authority sign-off (guidance_head, qa_office, or dpo) for an activation gate.
     */
    public function signOffGate(string $gateKey)
    {
        return $this->mutate(function (array $actor) use ($gateKey): array {
            $this->requireConfirmation();

            $authorityRole = strtolower(trim((string) $this->request->getPost('authority_role')));
            if (!in_array($authorityRole, GuidanceActivationGateService::VALID_AUTHORITY_ROLES, true)) {
                throw new InvalidArgumentException('Select a valid sign-off authority role.', 422);
            }

            $notes = trim((string) ($this->request->getPost('notes') ?? ''));
            if (mb_strlen($notes) > 1000) {
                throw new InvalidArgumentException('Sign-off notes must be 1000 characters or fewer.', 422);
            }

            $updated = $this->gateService->recordSignoff(
                $gateKey,
                $authorityRole,
                $notes,
                (int) $actor['tenant_id'],
                $actor
            );

            $roleLabel = $this->authorityLabel($authorityRole);
            $message   = (int) ($updated['is_enabled'] ?? 0) === 1
                ? "Sign-off recorded for {$roleLabel}. Activation gate '{$updated['gate_key']}' is now enabled."
                : "Sign-off recorded for {$roleLabel}. Remaining required authorities must sign off before activation.";

            return [
                '/guidance/governance/gates',
                $message,
                $this->workspaceRefresh('/guidance/governance/gates', 'guidance.governance.gates'),
            ];
        }, '/guidance/governance/gates', 'guidance.forms.manage');
    }

    /**
     * GET guidance/governance/practitioners
     * Lists practitioner credential profiles and active supervision assignments.
     */
    public function practitioners()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $actor    = $this->freshActor('guidance.cases.read');
            $tenantId = (int) $actor['tenant_id'];

            $profiles = $this->db->table('guidance_practitioner_profiles gp')
                ->select('gp.*, u.username, u.email, u.role as user_role, u.status as user_status, p.first_name, p.last_name, p.mis_id')
                ->join('users u', 'u.id = gp.user_id AND u.tenant_id = gp.tenant_id')
                ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left')
                ->where('gp.tenant_id', $tenantId)
                ->where('u.deleted_at', null)
                ->orderBy('gp.is_active', 'DESC')
                ->orderBy('gp.credential_tier', 'ASC')
                ->orderBy('u.username', 'ASC')
                ->get()
                ->getResultArray();

            foreach ($profiles as &$profile) {
                $profile['service_scope'] = json_decode((string) ($profile['service_scope_json'] ?? '[]'), true) ?: [];
            }
            unset($profile);

            $supervisions = $this->db->table('guidance_supervision_assignments sa')
                ->select('sa.*, au.username as associate_username, ap.first_name as associate_first_name, ap.last_name as associate_last_name, su.username as supervisor_username, sp.first_name as supervisor_first_name, sp.last_name as supervisor_last_name')
                ->join('users au', 'au.id = sa.associate_user_id AND au.tenant_id = sa.tenant_id')
                ->join('people ap', 'ap.id = au.person_id AND ap.tenant_id = au.tenant_id AND ap.deleted_at IS NULL', 'left')
                ->join('users su', 'su.id = sa.supervisor_user_id AND su.tenant_id = sa.tenant_id')
                ->join('people sp', 'sp.id = su.person_id AND sp.tenant_id = su.tenant_id AND sp.deleted_at IS NULL', 'left')
                ->where('sa.tenant_id', $tenantId)
                ->where('sa.status', 'active')
                ->orderBy('sa.starts_at', 'DESC')
                ->get()
                ->getResultArray();

            $guidanceUsers = $this->db->table('users u')
                ->select('u.id, u.username, u.role, u.email, p.first_name, p.last_name, p.mis_id')
                ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left')
                ->where('u.tenant_id', $tenantId)
                ->where('u.status', 'active')
                ->where('u.deleted_at', null)
                ->whereIn('u.role', ['counsellor', 'guidance_supervisor', 'guidance_admin'])
                ->orderBy('p.last_name', 'ASC')
                ->orderBy('u.username', 'ASC')
                ->get()
                ->getResultArray();

            $permissions = $actor['permissions'] ?? [];
            $isSuper     = ($actor['role'] ?? '') === 'superadmin';

            return view('guidance/governance/practitioners', [
                'title'                  => 'Practitioner Credentials & Supervision',
                'pageTitle'              => 'Guidance Practitioner Credentials & Supervision',
                'profiles'               => $profiles,
                'supervisions'           => $supervisions,
                'guidanceUsers'          => $guidanceUsers,
                'tiers'                  => GuidancePractitionerService::TIERS,
                'availableScopes'        => [
                    'intake'                    => 'Intake & Referral Processing',
                    'counseling'                => 'Counseling Sessions & Notes',
                    'psychosocial'              => 'Psychosocial Support Interviews',
                    'safety_triage'             => 'Clinical Safety Triage & Escalation',
                    'assessment_administration' => 'Assessment Administration',
                    'assessment_interpretation' => 'Assessment Interpretation',
                    'call_slips'                => 'Call Slips & Appointments',
                ],
                'canManagePractitioners' => $isSuper || in_array('guidance.disclosures.govern', $permissions, true),
                'canReassign'            => $isSuper || in_array('guidance.cases.reassign', $permissions, true),
                'actor'                  => $actor,
                'breadcrumbs'            => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Governance', 'url' => site_url('/guidance/governance/gates')],
                    ['label' => 'Practitioners & Supervision', 'url' => ''],
                ],
            ]);
        } catch (Throwable $error) {
            return $this->renderReadFailure($error, 'practitioners');
        }
    }

    /**
     * POST guidance/governance/practitioners/register
     * Registers or updates a practitioner credential profile and capability scope.
     */
    public function registerPractitioner()
    {
        return $this->mutate(function (array $actor): array {
            $userId = (int) $this->request->getPost('user_id');
            if ($userId <= 0) {
                throw new InvalidArgumentException('Select a valid guidance staff account.', 422);
            }

            $tier = trim((string) $this->request->getPost('credential_tier'));
            if (!in_array($tier, GuidancePractitionerService::TIERS, true)) {
                throw new InvalidArgumentException('Select a valid practitioner credential tier.', 422);
            }

            $licenseRaw    = trim((string) ($this->request->getPost('license_number') ?? ''));
            $licenseNumber = $licenseRaw !== '' ? mb_substr($licenseRaw, 0, 64) : null;

            $expiryRaw = trim((string) ($this->request->getPost('credential_expiry') ?? ''));
            if ($expiryRaw !== '' && strtotime($expiryRaw) === false) {
                throw new InvalidArgumentException('Enter a valid credential expiry date.', 422);
            }
            $expiry = $expiryRaw !== '' ? $expiryRaw : null;

            $rawScope = $this->request->getPost('service_scope');
            if (is_string($rawScope)) {
                $scopes = array_filter(array_map('trim', explode(',', $rawScope)));
            } elseif (is_array($rawScope)) {
                $scopes = array_filter(array_map(static fn ($item): string => is_string($item) ? trim($item) : '', $rawScope));
            } else {
                $scopes = [];
            }

            $this->practitionerService->registerProfile(
                $userId,
                $tier,
                $licenseNumber,
                $expiry,
                array_values($scopes),
                $actor
            );

            return [
                '/guidance/governance/practitioners',
                'Practitioner credential profile saved.',
                $this->workspaceRefresh('/guidance/governance/practitioners', 'guidance.governance.practitioners'),
            ];
        }, '/guidance/governance/practitioners', 'guidance.disclosures.govern');
    }

    /**
     * POST guidance/governance/practitioners/supervision
     * Assigns an active supervisor to a counselor associate.
     */
    public function assignSupervisor()
    {
        return $this->mutate(function (array $actor): array {
            $associateUserId  = (int) $this->request->getPost('associate_user_id');
            $supervisorUserId = (int) $this->request->getPost('supervisor_user_id');
            if ($associateUserId <= 0 || $supervisorUserId <= 0) {
                throw new InvalidArgumentException('Select both an associate and a supervisor.', 422);
            }

            $coSignRaw      = $this->request->getPost('co_sign_required');
            $coSignRequired = $coSignRaw === null ? true : in_array((string) $coSignRaw, ['1', 'true', 'on', 'yes'], true);

            $this->practitionerService->assignSupervisor(
                $associateUserId,
                $supervisorUserId,
                $coSignRequired,
                $actor
            );

            return [
                '/guidance/governance/practitioners',
                'Supervision assignment updated.',
                $this->workspaceRefresh('/guidance/governance/practitioners', 'guidance.governance.practitioners'),
            ];
        }, '/guidance/governance/practitioners', 'guidance.cases.reassign');
    }

    private function freshActor(?string $permission = null): array
    {
        if ($this->actorResolver !== null) {
            $actor = ($this->actorResolver)($permission);
            if (!is_array($actor)) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
            }
            return $actor;
        }

        try {
            return $this->accessPolicy->current($permission);
        } catch (RuntimeException $error) {
            if ((int) $error->getCode() === 401 || str_contains(strtolower($error->getMessage()), 'sign in')) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401, $error);
            }
            throw $error;
        }
    }

    private function requireConfirmation(): void
    {
        if ($this->request->getPost('confirmed') !== '1') {
            throw new InvalidArgumentException('Explicit confirmation is required.', 422);
        }
    }

    private function wantsJson(): bool
    {
        return $this->request->isAJAX()
            || strtolower($this->request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'application/json')
            || $this->request->is('json');
    }

    private function safeFailure(Throwable $error, string $operation, int $recordId = 0): array
    {
        $status = (int) $error->getCode();
        if (!in_array($status, [401, 403, 404, 409, 422], true)) {
            $status = 500;
            log_message('error', 'Guidance governance error: operation={operation} record_id={record_id} exception={exception_class} code={error_code}', [
                'operation'       => $operation,
                'record_id'       => $recordId,
                'exception_class' => get_class($error),
                'error_code'      => $error->getCode(),
            ]);
        }

        if ($status === 422 && ($error instanceof InvalidArgumentException || $error instanceof DomainException)) {
            $msg = trim($error->getMessage());
            return [422, $msg !== '' ? $msg : 'Please check the required fields and try again.'];
        }

        return [$status, match ($status) {
            401 => AuthenticatedUser::AUTHENTICATION_MESSAGE,
            403 => 'You are not authorized to perform this action.',
            404 => 'The requested governance record was not found.',
            409 => 'The record could not be updated. Refresh and try again.',
            422 => 'Please check the required fields and try again.',
            default => 'Unable to complete the governance request. Please try again later.',
        }];
    }

    private function renderReadFailure(Throwable $error, string $operation)
    {
        [$status, $message] = $this->safeFailure($error, $operation);
        if ($status === 401 && !$this->wantsJson()) {
            return AuthenticatedUser::denied($this->request, false, $message, '/auth/login');
        }
        if ($this->wantsJson()) {
            return $this->response->setStatusCode($status)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setJSON(['success' => false, 'message' => $message]);
        }

        return $this->response->setStatusCode($status)
            ->setHeader('Cache-Control', 'private, no-store')
            ->setContentType('text/html')
            ->setBody('<div class="alert alert-danger m-3">' . esc($message) . '</div>');
    }

    private function mutate(callable $operation, string $fallback, string $permission)
    {
        try {
            [$url, $message, $refresh] = array_pad($operation($this->freshActor($permission)), 3, null);
        } catch (Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'mutation');
            if ($this->wantsJson()) {
                return $this->response->setStatusCode($status)
                    ->setHeader('Cache-Control', 'no-store')
                    ->setJSON([
                        'success' => false,
                        'message' => $message,
                        'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
                    ]);
            }
            if ($status === 401) {
                return AuthenticatedUser::denied($this->request, false, $message, '/auth/login');
            }
            return ($status === 422 ? redirect()->back() : redirect()->to($fallback))->with('error', $message);
        }

        $url = site_url($url);
        if ($this->wantsJson()) {
            if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            $payload = [
                'success'  => true,
                'message'  => $message,
                'redirect' => $url,
                'csrf'     => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ];
            if ($refresh !== null) {
                $payload['refresh'] = $refresh;
            }
            return $this->response->setStatusCode(200)
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON($payload);
        }

        return redirect()->to($url)->with('success', $message);
    }

    private function workspaceRefresh(string $url, string $rolloutOwner): ?array
    {
        if (!config(\Config\RefreshRegions::class)->enables($rolloutOwner)) {
            return null;
        }

        return $this->refreshRegions($rolloutOwner, ['workspace'], site_url($url));
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, string>
     */
    private function loadUserNames(array $userIds, int $tenantId): array
    {
        $ids = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('users u')
            ->select('u.id, u.username, p.first_name, p.last_name')
            ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left')
            ->where('u.tenant_id', $tenantId)
            ->whereIn('u.id', $ids)
            ->get()
            ->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $full = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
            $map[(int) $row['id']] = $full !== '' ? $full : (string) $row['username'];
        }

        return $map;
    }

    private function authorityLabel(string $role): string
    {
        return match ($role) {
            'guidance_head' => 'Guidance Head',
            'qa_office'     => 'QA Office',
            'dpo'           => 'Data Protection Officer (DPO)',
            default         => ucwords(str_replace('_', ' ', $role)),
        };
    }
}
