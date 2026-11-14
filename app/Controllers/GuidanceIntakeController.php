<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthenticatedUser;
use App\Services\Guidance\GuidanceAccessPolicy;
use App\Services\Guidance\GuidanceActivationGateService;
use App\Services\Guidance\GuidanceIntakeService;
use App\Services\Guidance\GuidanceSafetyService;
use App\Services\PortalContext;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class GuidanceIntakeController extends BaseController
{
    private GuidanceIntakeService $intakeService;
    private GuidanceSafetyService $safetyService;
    private GuidanceActivationGateService $gateService;
    private GuidanceAccessPolicy $accessPolicy;
    private BaseConnection $db;
    private ?Closure $actorResolver = null;
    private ?Closure $portalActorResolver = null;

    public function __construct(
        ?GuidanceIntakeService $intakeService = null,
        ?GuidanceSafetyService $safetyService = null,
        ?GuidanceActivationGateService $gateService = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?BaseConnection $db = null,
        ?callable $actorResolver = null,
        ?callable $portalActorResolver = null
    ) {
        $this->db                  = $db ?? Database::connect();
        $this->intakeService       = $intakeService ?? new GuidanceIntakeService(db: $this->db);
        $this->safetyService       = $safetyService ?? new GuidanceSafetyService(db: $this->db);
        $this->gateService         = $gateService ?? new GuidanceActivationGateService(db: $this->db);
        $this->accessPolicy        = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->actorResolver       = $actorResolver !== null ? Closure::fromCallable($actorResolver) : null;
        $this->portalActorResolver = $portalActorResolver !== null ? Closure::fromCallable($portalActorResolver) : null;
    }

    // -------------------------------------------------------------------------
    // Portal Referral Submission (Teachers / Staff / Self)
    // -------------------------------------------------------------------------

    /**
     * GET portal/referrals/create
     * Renders the General Referral intake form for faculty, staff, and self-referrals.
     */
    public function portalCreate()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $user     = $this->portalActor();
            $tenantId = (int) $user['tenant_id'];
            $catalog  = $this->intakeService->getConcernCatalog();
            $gate     = $this->gateService->getGate(GuidanceActivationGateService::GATE_CANONICAL_FORMS, $tenantId);
            $gateOpen = $gate !== null && (int) ($gate['is_enabled'] ?? 0) === 1;

            $selfPerson = null;
            if (!empty($user['person_id'])) {
                $selfPerson = $this->db->table('people')
                    ->where('id', (int) $user['person_id'])
                    ->where('tenant_id', $tenantId)
                    ->where('deleted_at', null)
                    ->get()
                    ->getRowArray();
            }

            // Referrer's own recent referrals (non-clinical metadata only)
            $myReferrals = $this->db->table('guidance_intake_referrals gir')
                ->select('gir.id, gir.referral_number, gir.source_type, gir.priority_hint, gir.status, gir.submitted_at, gir.created_at, p.first_name, p.last_name, p.mis_id')
                ->join('people p', 'p.id = gir.subject_person_id AND p.tenant_id = gir.tenant_id AND p.deleted_at IS NULL', 'left')
                ->where('gir.tenant_id', $tenantId)
                ->where('gir.referrer_user_id', (int) $user['id'])
                ->orderBy('gir.id', 'DESC')
                ->limit(15)
                ->get()
                ->getResultArray();

            return view('portal/referrals/create', [
                'title'               => 'Guidance General Referral',
                'pageTitle'           => 'Submit Guidance Referral',
                'catalog'             => $catalog,
                'priorityHints'       => GuidanceIntakeService::PRIORITY_HINTS,
                'canonicalFormsGate'  => $gate,
                'canonicalFormsOpen'  => $gateOpen,
                'selfPerson'          => $selfPerson,
                'myReferrals'         => $myReferrals,
                'user'                => $user,
                'breadcrumbs'         => [
                    ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                    ['label' => 'Guidance Referral', 'url' => ''],
                ],
            ]);
        } catch (Throwable $error) {
            return $this->renderReadFailure($error, 'portalCreate');
        }
    }

    /**
     * POST portal/referrals/resolve-subject
     * Resolves a subject strictly by exact MIS ID (no directory browsing or wildcards).
     */
    public function resolveSubject()
    {
        $this->response->setHeader('Cache-Control', 'no-store');

        try {
            $user     = $this->portalActor();
            $tenantId = (int) $user['tenant_id'];

            $misId = (string) ($this->request->getPost('mis_id') ?? '');
            if ($misId === '' && str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')) {
                $json = $this->request->getJSON(true);
                if (is_array($json)) {
                    $misId = (string) ($json['mis_id'] ?? '');
                }
            }

            $person = $this->intakeService->resolveSubject($misId, $tenantId);

            return $this->response->setStatusCode(200)->setJSON([
                'success' => true,
                'person'  => $person,
                'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $error) {
            [$status, $message] = $this->safeFailure($error, 'resolveSubject');
            return $this->response->setStatusCode($status)->setJSON([
                'success' => false,
                'message' => $message,
                'csrf'    => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }

    /**
     * POST portal/referrals/store
     * Creates a draft referral and optionally submits it live (gated by canonical_forms_signoff).
     */
    public function portalStore()
    {
        return $this->mutateWithActor(
            fn (): array => $this->portalActor(),
            function (array $actor): array {
                $tenantId       = (int) $actor['tenant_id'];
                $action         = strtolower(trim((string) ($this->request->getPost('action') ?? 'submit')));
                $keepDraft      = $action === 'draft' || $this->request->getPost('keep_draft') === '1';
                $existingId     = (int) ($this->request->getPost('referral_id') ?? 0);

                if ($existingId > 0 && !$keepDraft) {
                    $existing = $this->intakeService->getReferral($existingId, $tenantId, false);
                    if ((int) $existing['referrer_user_id'] !== (int) $actor['id']) {
                        throw new DomainException('Only the original referrer may submit this draft.', 403);
                    }
                    $this->gateService->assertGateEnabled(GuidanceActivationGateService::GATE_CANONICAL_FORMS, $tenantId);
                    $submitted = $this->intakeService->submitReferral($existingId, $actor);

                    return [
                        '/portal/referrals/create',
                        "Referral {$submitted['referral_number']} submitted to Guidance.",
                        $this->workspaceRefresh('/portal/referrals/create', 'portal.referrals'),
                    ];
                }

                [$sourceType, $subjectPersonId, $selections, $narrative, $priorActions, $priorityHint] = $this->parseValidatedIntakeInput(
                    $actor,
                    $tenantId,
                    ['self', 'employee_teacher', 'staff_on_behalf', 'faculty', 'staff', 'peer'],
                    'employee_teacher'
                );

                if (!$keepDraft) {
                    $this->gateService->assertGateEnabled(GuidanceActivationGateService::GATE_CANONICAL_FORMS, $tenantId);
                }

                $draft = $this->intakeService->createDraft(
                    $subjectPersonId,
                    $sourceType,
                    $selections,
                    $narrative,
                    $priorActions,
                    $priorityHint,
                    $actor
                );

                if ($keepDraft) {
                    return [
                        '/portal/referrals/create',
                        "Draft referral {$draft['referral_number']} saved.",
                        $this->workspaceRefresh('/portal/referrals/create', 'portal.referrals'),
                    ];
                }

                $submitted = $this->intakeService->submitReferral((int) $draft['id'], $actor);

                return [
                    '/portal/referrals/create',
                    "Referral {$submitted['referral_number']} submitted to Guidance.",
                    $this->workspaceRefresh('/portal/referrals/create', 'portal.referrals'),
                ];
            },
            '/portal/referrals/create'
        );
    }

    /**
     * POST guidance/intake/staff-create
     * Creates a walk-in or teacher-accompanied referral recorded directly by Guidance staff.
     */
    public function staffCreate()
    {
        return $this->mutateWithActor(
            fn (): array => $this->freshActor('guidance.intake.process'),
            function (array $actor): array {
                $tenantId  = (int) $actor['tenant_id'];
                $action    = strtolower(trim((string) ($this->request->getPost('action') ?? 'submit')));
                $keepDraft = $action === 'draft' || $this->request->getPost('keep_draft') === '1';

                [$sourceType, $subjectPersonId, $selections, $narrative, $priorActions, $priorityHint] = $this->parseValidatedIntakeInput(
                    $actor,
                    $tenantId,
                    ['staff_on_behalf', 'employee_teacher'],
                    'staff_on_behalf'
                );

                if (!$keepDraft) {
                    $this->gateService->assertGateEnabled(GuidanceActivationGateService::GATE_CANONICAL_FORMS, $tenantId);
                }

                $draft = $this->intakeService->createDraft(
                    $subjectPersonId,
                    $sourceType,
                    $selections,
                    $narrative,
                    $priorActions,
                    $priorityHint,
                    $actor
                );

                if ($keepDraft) {
                    return [
                        '/guidance/intake',
                        "Walk-in referral draft {$draft['referral_number']} saved.",
                        $this->workspaceRefresh('/guidance/intake', 'guidance.intake'),
                    ];
                }

                $submitted = $this->intakeService->submitReferral((int) $draft['id'], $actor);

                return [
                    '/guidance/intake/' . (int) $submitted['id'],
                    "Referral {$submitted['referral_number']} submitted to Guidance intake queue.",
                    $this->workspaceRefresh('/guidance/intake', 'guidance.intake'),
                ];
            },
            '/guidance/intake'
        );
    }

    /**
     * Parses and validates shared General Referral concern-catalog and subject fields.
     *
     * @param array<string, mixed> $actor
     * @param array<int, string> $allowedSources
     * @return array{0: string, 1: int, 2: array<int|string, mixed>, 3: string, 4: ?string, 5: string}
     */
    private function parseValidatedIntakeInput(
        array $actor,
        int $tenantId,
        array $allowedSources,
        string $defaultSource
    ): array {
        $sourceType = trim((string) ($this->request->getPost('source_type') ?? $defaultSource));
        if (!in_array($sourceType, $allowedSources, true)) {
            throw new InvalidArgumentException('Select a valid referral source type.', 422);
        }

        $subjectPersonId = (int) ($this->request->getPost('subject_person_id') ?? 0);
        if ($sourceType === 'self') {
            $subjectPersonId = (int) ($actor['person_id'] ?? 0);
            if ($subjectPersonId <= 0) {
                throw new InvalidArgumentException('Your account does not have a linked person profile for self-referral.', 422);
            }
        } elseif ($subjectPersonId <= 0) {
            $misId = trim((string) ($this->request->getPost('mis_id') ?? ''));
            if ($misId === '') {
                throw new InvalidArgumentException('Look up the student or employee using an exact MIS ID first.', 422);
            }
            $resolved        = $this->intakeService->resolveSubject($misId, $tenantId);
            $subjectPersonId = (int) $resolved['person_id'];
        }

        $catalog     = $this->intakeService->getConcernCatalog();
        $validCodes  = array_keys($catalog['checklist'] ?? []);
        $rawReasons  = $this->request->getPost('reasons') ?? $this->request->getPost('selections') ?? [];
        $reasonCodes = [];
        if (is_array($rawReasons)) {
            foreach ($rawReasons as $code) {
                if (is_string($code) && in_array($code, $validCodes, true)) {
                    $reasonCodes[] = $code;
                }
            }
        }
        $reasonCodes = array_values(array_unique($reasonCodes));
        if ($reasonCodes === []) {
            throw new InvalidArgumentException('Select at least one concern from the catalog.', 422);
        }

        $otherSpecify = trim((string) ($this->request->getPost('reasons_other_specify') ?? ''));
        $selections   = $otherSpecify !== ''
            ? ['items' => $reasonCodes, 'other_specify' => mb_substr($otherSpecify, 0, 500)]
            : $reasonCodes;

        $narrative = trim((string) ($this->request->getPost('reasons_narrative') ?? $this->request->getPost('narrative') ?? ''));
        if ($narrative === '') {
            throw new InvalidArgumentException('Referral narrative is required.', 422);
        }

        $priorActionsRaw = trim((string) ($this->request->getPost('prior_actions_taken') ?? $this->request->getPost('prior_actions') ?? ''));
        $priorActions    = $priorActionsRaw !== '' ? $priorActionsRaw : null;

        $priorityHint = trim((string) ($this->request->getPost('priority_hint') ?? 'routine'));
        if (!in_array($priorityHint, GuidanceIntakeService::PRIORITY_HINTS, true)) {
            throw new InvalidArgumentException('Select a valid priority attention hint.', 422);
        }

        return [$sourceType, $subjectPersonId, $selections, $narrative, $priorActions, $priorityHint];
    }

    // -------------------------------------------------------------------------
    // Guidance Intake Triage & Queue
    // -------------------------------------------------------------------------

    /**
     * GET guidance/intake
     * Lists tenant-scoped guidance intake referrals and incoming Clinic referrals eligible for bridging.
     */
    public function index()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $actor    = $this->freshActor('guidance.intake.process');
            $tenantId = (int) $actor['tenant_id'];

            $statusFilter   = trim((string) ($this->request->getGet('status') ?? ''));
            $priorityFilter = trim((string) ($this->request->getGet('priority_hint') ?? ''));

            $builder = $this->db->table('guidance_intake_referrals gir')
                ->select('gir.id, gir.referral_number, gir.source_type, gir.clinic_referral_id, gir.subject_person_id, gir.referrer_name_snapshot, gir.referrer_department_snapshot, gir.priority_hint, gir.status, gir.assigned_counsellor_user_id, gir.linked_case_id, gir.submitted_at, gir.received_at, gir.triaged_at, gir.created_at, p.first_name, p.last_name, p.mis_id, p.kind, u.username as assigned_counsellor_username')
                ->join('people p', 'p.id = gir.subject_person_id AND p.tenant_id = gir.tenant_id AND p.deleted_at IS NULL', 'left')
                ->join('users u', 'u.id = gir.assigned_counsellor_user_id AND u.tenant_id = gir.tenant_id', 'left')
                ->where('gir.tenant_id', $tenantId);

            if ($statusFilter !== '' && in_array($statusFilter, GuidanceIntakeService::STATUSES, true)) {
                $builder->where('gir.status', $statusFilter);
            }
            if ($priorityFilter !== '' && in_array($priorityFilter, GuidanceIntakeService::PRIORITY_HINTS, true)) {
                $builder->where('gir.priority_hint', $priorityFilter);
            }

            $referrals = $builder
                ->orderBy("CASE gir.priority_hint WHEN 'crisis' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END", '', false)
                ->orderBy('gir.created_at', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();

            // Incoming Clinic referrals targeting guidance (read-only view + bridge status)
            $clinicReferrals = $this->db->table('referrals r')
                ->select('r.id, r.referral_code, r.priority, r.reason_code, r.status, r.created_at, p.first_name, p.last_name, p.mis_id, u.username as issuer_name, gir.id as bridged_intake_id, gir.referral_number as bridged_referral_number')
                ->join('people p', 'p.id = r.patient_person_id AND p.tenant_id = r.tenant_id AND p.deleted_at IS NULL', 'left')
                ->join('users u', 'u.id = r.issuer_user_id AND u.tenant_id = r.tenant_id', 'left')
                ->join('guidance_intake_referrals gir', 'gir.clinic_referral_id = r.id AND gir.tenant_id = r.tenant_id', 'left')
                ->where('r.tenant_id', $tenantId)
                ->where('r.target_module', 'guidance')
                ->whereIn('r.status', ['submitted', 'acknowledged'])
                ->orderBy("CASE r.priority WHEN 'crisis' THEN 1 WHEN 'urgent' THEN 2 ELSE 3 END", '', false)
                ->orderBy('r.created_at', 'DESC')
                ->limit(50)
                ->get()
                ->getResultArray();

            $canonicalGateEnabled = $this->gateService->isGateEnabled(
                GuidanceActivationGateService::GATE_CANONICAL_FORMS,
                $tenantId
            );

            return view('guidance/intake/index', [
                'title'                => 'Guidance Intake & Safety Queue',
                'pageTitle'            => 'Guidance Intake Queue',
                'referrals'            => $referrals,
                'clinicReferrals'      => $clinicReferrals,
                'statuses'             => GuidanceIntakeService::STATUSES,
                'priorityHints'        => GuidanceIntakeService::PRIORITY_HINTS,
                'concernCatalog'       => $this->intakeService->getConcernCatalog(),
                'canonicalGateEnabled' => $canonicalGateEnabled,
                'statusFilter'         => $statusFilter,
                'priorityFilter'       => $priorityFilter,
                'actor'                => $actor,
                'breadcrumbs'          => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Intake Queue', 'url' => ''],
                ],
            ]);
        } catch (Throwable $error) {
            return $this->renderReadFailure($error, 'index');
        }
    }

    /**
     * GET guidance/intake/(:num)
     * Renders decrypted intake referral detail, chronology, workflow actions, and safety triage/escalation panel.
     */
    public function show(int $id)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');

        try {
            $actor    = $this->freshActor('guidance.intake.process');
            $tenantId = (int) $actor['tenant_id'];
            $referral = $this->intakeService->getReferral($id, $tenantId, true);

            $subjectPerson = $this->db->table('people')
                ->where('id', (int) $referral['subject_person_id'])
                ->where('tenant_id', $tenantId)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            $studentProfile = null;
            if ($subjectPerson !== null && ($subjectPerson['kind'] ?? '') === 'student') {
                $studentProfile = $this->db->table('student_profiles')
                    ->where('person_id', (int) $subjectPerson['id'])
                    ->where('tenant_id', $tenantId)
                    ->get()
                    ->getRowArray();
            }

            $counselors = $this->db->table('users u')
                ->select('u.id, u.username, u.role, p.first_name, p.last_name')
                ->join('people p', 'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left')
                ->where('u.tenant_id', $tenantId)
                ->where('u.status', 'active')
                ->where('u.deleted_at', null)
                ->whereIn('u.role', ['counsellor', 'guidance_supervisor', 'guidance_admin'])
                ->orderBy('p.last_name', 'ASC')
                ->get()
                ->getResultArray();

            $existingCases = $this->db->table('counselling_cases')
                ->select('id, case_number, title, status, category, opened_at')
                ->where('tenant_id', $tenantId)
                ->where('person_id', (int) $referral['subject_person_id'])
                ->where('deleted_at', null)
                ->orderBy('id', 'DESC')
                ->limit(10)
                ->get()
                ->getResultArray();

            $permissions        = $actor['permissions'] ?? [];
            $isSuper            = ($actor['role'] ?? '') === 'superadmin';
            $hasSafetyTriage    = $isSuper || in_array('guidance.safety.triage', $permissions, true);
            $isQualifiedSafety  = $hasSafetyTriage && $this->safetyService->canPerformSafetyAssessment((int) $actor['id'], $tenantId);
            $safetyGate         = $this->gateService->getGate(GuidanceActivationGateService::GATE_SAFETY_ESCALATION_SOP, $tenantId);
            $safetyGateOpen     = $safetyGate !== null && (int) ($safetyGate['is_enabled'] ?? 0) === 1;

            $safetyHistory = null;
            $safetyDenied  = false;
            try {
                $safetyHistory = $this->safetyService->getSafetyHistory((int) $referral['subject_person_id'], $actor);
            } catch (DomainException) {
                $safetyDenied = true;
            }

            $catalog = $this->intakeService->getConcernCatalog();

            return view('guidance/intake/view', [
                'title'             => 'Referral ' . $referral['referral_number'],
                'pageTitle'         => 'Intake Referral ' . $referral['referral_number'],
                'referral'          => $referral,
                'subjectPerson'     => $subjectPerson,
                'studentProfile'    => $studentProfile,
                'counselors'        => $counselors,
                'existingCases'     => $existingCases,
                'catalogChecklist'  => $catalog['checklist'] ?? [],
                'priorityHints'     => GuidanceIntakeService::PRIORITY_HINTS,
                'riskLevels'        => GuidanceSafetyService::RISK_LEVELS,
                'escalationChannels'=> GuidanceSafetyService::ESCALATION_CHANNELS,
                'safetyHistory'     => $safetyHistory,
                'safetyDenied'      => $safetyDenied,
                'isQualifiedSafety' => $isQualifiedSafety,
                'safetyGate'        => $safetyGate,
                'safetyGateOpen'    => $safetyGateOpen,
                'canLinkCase'       => $isSuper || in_array('guidance.cases.write', $permissions, true),
                'actor'             => $actor,
                'breadcrumbs'       => [
                    ['label' => 'Guidance', 'url' => site_url('/guidance')],
                    ['label' => 'Intake Queue', 'url' => site_url('/guidance/intake')],
                    ['label' => $referral['referral_number'], 'url' => ''],
                ],
            ]);
        } catch (Throwable $error) {
            return $this->renderReadFailure($error, 'show', $id);
        }
    }

    /**
     * POST guidance/intake/(:num)/receive
     */
    public function receive(int $id)
    {
        return $this->mutateGuidance(function (array $actor) use ($id): array {
            $this->intakeService->receiveReferral($id, $actor);
            return [
                '/guidance/intake/' . $id,
                'Referral marked as received.',
                $this->intakeRefresh($id),
            ];
        }, '/guidance/intake/' . $id, 'guidance.intake.process');
    }

    /**
     * POST guidance/intake/(:num)/triage
     */
    public function triage(int $id)
    {
        return $this->mutateGuidance(function (array $actor) use ($id): array {
            $priorityHint = trim((string) $this->request->getPost('priority_hint'));
            if (!in_array($priorityHint, GuidanceIntakeService::PRIORITY_HINTS, true)) {
                throw new InvalidArgumentException('Select a valid priority attention hint.', 422);
            }
            $triageNotesRaw = trim((string) ($this->request->getPost('triage_notes') ?? ''));
            $triageNotes    = $triageNotesRaw !== '' ? $triageNotesRaw : null;

            $this->intakeService->triageReferral($id, $priorityHint, $triageNotes, $actor);

            return [
                '/guidance/intake/' . $id,
                'Intake priority attention hint and triage notes updated.',
                $this->intakeRefresh($id),
            ];
        }, '/guidance/intake/' . $id, 'guidance.safety.triage');
    }

    /**
     * POST guidance/intake/(:num)/accept
     */
    public function accept(int $id)
    {
        return $this->mutateGuidance(function (array $actor) use ($id): array {
            $assignedRaw = $this->request->getPost('assigned_counsellor_user_id');
            $assignedId  = ($assignedRaw !== null && $assignedRaw !== '') ? (int) $assignedRaw : null;
            if ($assignedId !== null && $assignedId <= 0) {
                $assignedId = null;
            }

            $this->intakeService->acceptReferral($id, $assignedId, $actor);

            return [
                '/guidance/intake/' . $id,
                'Referral accepted into Guidance caseload.',
                $this->intakeRefresh($id),
            ];
        }, '/guidance/intake/' . $id, 'guidance.intake.process');
    }

    /**
     * POST guidance/intake/(:num)/decline
     */
    public function decline(int $id)
    {
        return $this->mutateGuidance(function (array $actor) use ($id): array {
            $this->requireConfirmation();
            $reason = trim((string) ($this->request->getPost('decline_reason') ?? $this->request->getPost('reason') ?? ''));
            if ($reason === '') {
                throw new InvalidArgumentException('A decline reason is required.', 422);
            }

            $this->intakeService->declineReferral($id, $reason, $actor);

            return [
                '/guidance/intake/' . $id,
                'Referral declined.',
                $this->intakeRefresh($id),
            ];
        }, '/guidance/intake/' . $id, 'guidance.intake.process');
    }

    /**
     * POST guidance/intake/(:num)/link-case
     */
    public function linkCase(int $id)
    {
        return $this->mutateGuidance(function (array $actor) use ($id): array {
            $existingRaw    = $this->request->getPost('existing_case_id');
            $existingCaseId = ($existingRaw !== null && $existingRaw !== '') ? (int) $existingRaw : null;
            if ($existingCaseId !== null && $existingCaseId <= 0) {
                $existingCaseId = null;
            }

            $linked = $this->intakeService->linkToCase($id, $existingCaseId, $actor);

            return [
                '/guidance/intake/' . $id,
                'Referral linked to Counseling Case #' . ($linked['linked_case_id'] ?? ''),
                $this->intakeRefresh($id),
            ];
        }, '/guidance/intake/' . $id, 'guidance.cases.write');
    }

    /**
     * POST guidance/intake/(:num)/withdraw
     * Permitted only by the original referrer prior to triage.
     */
    public function withdraw(int $id)
    {
        return $this->mutateWithActor(
            fn (): array => $this->portalActor(),
            function (array $actor) use ($id): array {
                $this->requireConfirmation();
                $this->intakeService->withdrawReferral($id, $actor);

                $isGuidanceStaff = PortalContext::isRolePermitted((string) ($actor['role'] ?? ''), PortalContext::PORTAL_GUIDANCE);
                $redirect        = $isGuidanceStaff ? '/guidance/intake/' . $id : '/portal/referrals/create';

                return [
                    $redirect,
                    'Referral withdrawn prior to triage.',
                    $isGuidanceStaff ? $this->intakeRefresh($id) : $this->workspaceRefresh('/portal/referrals/create', 'portal.referrals'),
                ];
            },
            '/portal/referrals/create'
        );
    }

    /**
     * POST guidance/intake/(:num)/external-handoff
     */
    public function externalHandoff(int $id)
    {
        return $this->mutateGuidance(function (array $actor) use ($id): array {
            $this->requireConfirmation();
            $facility = trim((string) ($this->request->getPost('external_facility') ?? $this->request->getPost('facility_name') ?? ''));
            $notes    = trim((string) ($this->request->getPost('handoff_notes') ?? $this->request->getPost('notes') ?? ''));
            if ($facility === '') {
                throw new InvalidArgumentException('External facility name is required.', 422);
            }

            $this->intakeService->externalHandoff($id, $facility, $notes, $actor);

            return [
                '/guidance/intake/' . $id,
                'External handoff recorded.',
                $this->intakeRefresh($id),
            ];
        }, '/guidance/intake/' . $id, 'guidance.intake.process');
    }

    /**
     * POST guidance/intake/bridge-clinic/(:num)
     */
    public function bridgeClinic(int $clinicReferralId)
    {
        return $this->mutateGuidance(function (array $actor) use ($clinicReferralId): array {
            $bridged = $this->intakeService->bridgeClinicReferral($clinicReferralId, $actor);
            $target  = '/guidance/intake/' . (int) $bridged['id'];

            return [
                $target,
                "Clinic referral bridged as {$bridged['referral_number']}.",
                $this->navigationRefresh($target, 'guidance.intake'),
            ];
        }, '/guidance/intake', 'guidance.intake.process');
    }

    // -------------------------------------------------------------------------
    // Safety Triage & Crisis Escalation Endpoints
    // -------------------------------------------------------------------------

    /**
     * POST guidance/safety/assess
     * Records an immutable clinical safety assessment (requires qualified practitioner eligibility).
     */
    public function recordSafetyAssessment()
    {
        $intakeReferralId = (int) ($this->request->getPost('intake_referral_id') ?? 0);
        $caseId           = (int) ($this->request->getPost('case_id') ?? 0);
        $fallback         = $intakeReferralId > 0
            ? '/guidance/intake/' . $intakeReferralId
            : ($caseId > 0 ? '/guidance/cases/' . $caseId : '/guidance/intake');

        return $this->mutateGuidance(function (array $actor) use ($intakeReferralId, $caseId, $fallback): array {
            $personId = (int) ($this->request->getPost('person_id') ?? 0);
            if ($personId <= 0) {
                throw new InvalidArgumentException('Subject person_id is required.', 422);
            }

            $riskLevel = trim((string) ($this->request->getPost('risk_level') ?? ''));
            if (!in_array($riskLevel, GuidanceSafetyService::RISK_LEVELS, true)) {
                throw new InvalidArgumentException('Select a valid clinical risk level.', 422);
            }

            $selfHarm     = in_array((string) $this->request->getPost('self_harm_risk'), ['1', 'true', 'on', 'yes'], true);
            $harmToOthers = in_array((string) $this->request->getPost('harm_to_others_risk'), ['1', 'true', 'on', 'yes'], true);

            $rawIndicators = $this->request->getPost('indicators');
            if (is_string($rawIndicators)) {
                $indicators = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $rawIndicators) ?: [])));
            } elseif (is_array($rawIndicators)) {
                $indicators = array_values(array_filter(array_map(static fn ($v): string => is_string($v) ? trim($v) : '', $rawIndicators)));
            } else {
                $indicators = [];
            }

            $immediateActions = trim((string) ($this->request->getPost('immediate_actions_taken') ?? ''));
            if ($immediateActions === '') {
                throw new InvalidArgumentException('Immediate clinical actions taken is required.', 422);
            }

            $safetyPlanRaw = trim((string) ($this->request->getPost('safety_plan') ?? ''));
            $safetyPlan    = $safetyPlanRaw !== '' ? $safetyPlanRaw : null;

            $this->safetyService->recordSafetyAssessment(
                $personId,
                $riskLevel,
                $selfHarm,
                $harmToOthers,
                $indicators,
                $immediateActions,
                $safetyPlan,
                $intakeReferralId > 0 ? $intakeReferralId : null,
                $caseId > 0 ? $caseId : null,
                $actor
            );

            return [
                $fallback,
                'Authoritative clinical safety assessment recorded.',
                $intakeReferralId > 0 ? $this->intakeRefresh($intakeReferralId) : null,
            ];
        }, $fallback, 'guidance.safety.triage');
    }

    /**
     * POST guidance/safety/escalate
     * Dispatches a crisis escalation event (enforces practitioner eligibility and safety_escalation_sop_signoff gate).
     */
    public function recordEscalation()
    {
        $intakeReferralId = (int) ($this->request->getPost('intake_referral_id') ?? 0);
        $caseId           = (int) ($this->request->getPost('case_id') ?? 0);
        $fallback         = $intakeReferralId > 0
            ? '/guidance/intake/' . $intakeReferralId
            : ($caseId > 0 ? '/guidance/cases/' . $caseId : '/guidance/intake');

        return $this->mutateGuidance(function (array $actor) use ($intakeReferralId, $fallback): array {
            $tenantId = (int) $actor['tenant_id'];

            // Enforce practitioner eligibility and live safety escalation SOP activation gate
            $this->safetyService->assertProfessionalEligibility((int) $actor['id'], $tenantId);
            $this->gateService->assertGateEnabled(GuidanceActivationGateService::GATE_SAFETY_ESCALATION_SOP, $tenantId);

            $assessmentId = (int) ($this->request->getPost('safety_assessment_id') ?? 0);
            if ($assessmentId <= 0) {
                throw new InvalidArgumentException('Select the linked clinical safety assessment.', 422);
            }

            $channel = trim((string) ($this->request->getPost('escalation_channel') ?? $this->request->getPost('channel') ?? ''));
            if (!in_array($channel, GuidanceSafetyService::ESCALATION_CHANNELS, true)) {
                throw new InvalidArgumentException('Select a valid crisis escalation channel.', 422);
            }

            $recipient = trim((string) ($this->request->getPost('recipient_agency_or_person') ?? $this->request->getPost('recipient') ?? ''));
            $summary   = trim((string) ($this->request->getPost('summary_notes') ?? $this->request->getPost('summary') ?? ''));
            if ($recipient === '' || $summary === '') {
                throw new InvalidArgumentException('Recipient and handover summary notes are required.', 422);
            }

            $this->safetyService->recordEscalation($assessmentId, $channel, $recipient, $summary, $actor);

            return [
                $fallback,
                'Crisis safety escalation logged and routed.',
                $intakeReferralId > 0 ? $this->intakeRefresh($intakeReferralId) : null,
            ];
        }, $fallback, 'guidance.safety.triage');
    }

    /**
     * POST guidance/safety/escalations/(:num)/ack
     * Acknowledges receipt of a logged safety escalation.
     */
    public function acknowledgeEscalation(int $id)
    {
        $intakeReferralId = (int) ($this->request->getPost('intake_referral_id') ?? 0);
        $caseId           = (int) ($this->request->getPost('case_id') ?? 0);
        $fallback         = $intakeReferralId > 0
            ? '/guidance/intake/' . $intakeReferralId
            : ($caseId > 0 ? '/guidance/cases/' . $caseId : '/guidance/intake');

        return $this->mutateGuidance(function (array $actor) use ($id, $intakeReferralId, $fallback): array {
            $tenantId = (int) $actor['tenant_id'];
            $this->safetyService->assertProfessionalEligibility((int) $actor['id'], $tenantId);

            $acknowledgedBy = trim((string) ($this->request->getPost('acknowledged_by') ?? ($actor['name'] ?? '')));
            if ($acknowledgedBy === '') {
                throw new InvalidArgumentException('Acknowledging officer or respondent name is required.', 422);
            }

            $this->safetyService->acknowledgeEscalation($id, $acknowledgedBy, $actor);

            return [
                $fallback,
                'Safety escalation acknowledgement recorded.',
                $intakeReferralId > 0 ? $this->intakeRefresh($intakeReferralId) : null,
            ];
        }, $fallback, 'guidance.safety.triage');
    }

    // -------------------------------------------------------------------------
    // Shared Helpers
    // -------------------------------------------------------------------------

    private function portalActor(): array
    {
        if ($this->portalActorResolver !== null) {
            $user = ($this->portalActorResolver)();
            if (!is_array($user)) {
                throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
            }
            return $user;
        }

        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            throw new RuntimeException(AuthenticatedUser::AUTHENTICATION_MESSAGE, 401);
        }

        return $user;
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
            log_message('error', 'Guidance intake error: operation={operation} record_id={record_id} exception={exception_class} code={error_code}', [
                'operation'       => $operation,
                'record_id'       => $recordId,
                'exception_class' => get_class($error),
                'error_code'      => $error->getCode(),
            ]);
        }

        if ($status === 403 && $error instanceof DomainException && str_contains($error->getMessage(), 'Activation gate')) {
            return [403, $error->getMessage()];
        }
        if ($status === 403 && $error instanceof DomainException && str_contains($error->getMessage(), 'Professional eligibility')) {
            return [403, 'Clinical safety triage requires an active qualified guidance practitioner profile.'];
        }
        if ($status === 422 && ($error instanceof InvalidArgumentException || $error instanceof DomainException)) {
            $msg = trim($error->getMessage());
            return [422, $msg !== '' ? $msg : 'Please check the required fields and try again.'];
        }
        if ($status === 404 && $operation === 'resolveSubject') {
            return [404, 'No matching person found for that exact MIS ID. Directory searches and wildcards are not permitted.'];
        }

        return [$status, match ($status) {
            401 => AuthenticatedUser::AUTHENTICATION_MESSAGE,
            403 => 'You are not authorized to perform this action.',
            404 => 'The requested referral or safety record was not found.',
            409 => 'The record could not be updated in its current state. Refresh and try again.',
            422 => 'Please check the required fields and try again.',
            default => 'Unable to complete the intake request. Please try again later.',
        }];
    }

    private function renderReadFailure(Throwable $error, string $operation, int $recordId = 0)
    {
        [$status, $message] = $this->safeFailure($error, $operation, $recordId);
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

    private function mutateGuidance(callable $operation, string $fallback, string $permission)
    {
        return $this->mutateWithActor(fn (): array => $this->freshActor($permission), $operation, $fallback);
    }

    private function mutateWithActor(callable $actorProvider, callable $operation, string $fallback)
    {
        try {
            [$url, $message, $refresh] = array_pad($operation($actorProvider()), 3, null);
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

    private function intakeRefresh(int $referralId): ?array
    {
        return $this->workspaceRefresh('/guidance/intake/' . $referralId, 'guidance.intake.' . $referralId);
    }

    private function workspaceRefresh(string $url, string $rolloutOwner): ?array
    {
        if (!config(\Config\RefreshRegions::class)->enables($rolloutOwner)) {
            return null;
        }

        return $this->refreshRegions($rolloutOwner, ['workspace'], site_url($url));
    }

    private function navigationRefresh(string $url, string $rolloutOwner): ?array
    {
        if (!config(\Config\RefreshRegions::class)->enables($rolloutOwner)) {
            return null;
        }

        return $this->refreshNavigation(site_url($url));
    }
}
