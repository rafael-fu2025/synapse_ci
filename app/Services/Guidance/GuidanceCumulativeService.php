<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Libraries\TablePage;
use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;

/** Person-level Guidance metadata; clinical content stays in its audited readers. */
final class GuidanceCumulativeService
{
    public const TABS = ['overview', 'cases', 'sessions', 'assessments', 'coordination', 'forms', 'appointments'];

    public const PAGE_PARAMS = [
        'authorizedCases' => 'cases_page', 'episodes' => 'episodes_page',
        'sessions' => 'sessions_page', 'followups' => 'followups_page',
        'miAttempts' => 'mi_page', 'pendingAssessments' => 'pending_mi_page', 'surveys' => 'surveys_page',
        'intakes' => 'intakes_page', 'bridgeReferrals' => 'referrals_page',
        'transfers' => 'transfers_page', 'assignments' => 'assignments_page', 'safetyReviews' => 'safety_page',
        'forms' => 'forms_page', 'sharedAppointments' => 'scheduled_page',
        'guidanceAppointments' => 'slips_page', 'callSlips' => 'call_slips_page',
    ];

    private BaseConnection $db;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidancePractitionerService $practitionerService;
    private array $tables = [];

    public function __construct(
        ?BaseConnection $db = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?GuidancePractitionerService $practitionerService = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->accessPolicy = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = $practitionerService
            ?? new GuidancePractitionerService(accessPolicy: $this->accessPolicy, db: $this->db);
    }

    /** The legacy name is retained for existing callers and cumulative-record links. */
    public function getStudentCumulativeRecord(int $personId, ?array $actor = null, array $query = []): array
    {
        $authority = $this->accessPolicy->resolve($actor, 'guidance.cases.read');
        $tenantId = (int) $authority['tenant_id'];
        $activeTab = $query['tab'] ?? 'overview';
        if (!is_string($activeTab) || !in_array($activeTab, self::TABS, true)) {
            throw new InvalidArgumentException('Invalid Guidance record section.', 422);
        }
        $pages = [];
        foreach (self::PAGE_PARAMS as $key => $parameter) {
            $pages[$key] = TablePage::forList($query, $parameter);
        }

        $person = $this->db->table('people')
            ->select('id, tenant_id, kind, mis_id, first_name, middle_name, last_name, date_of_birth, gender, email, phone, address')
            ->where('tenant_id', $tenantId)->where('id', $personId)
            ->whereIn('kind', ['student', 'employee'])->where('deleted_at', null)
            ->get()->getRowArray();
        if ($person === null) {
            throw new InvalidArgumentException('Guidance client not found.', 404);
        }

        $studentProfile = $person['kind'] === 'student' ? $this->db->table('student_profiles')
            ->select('program, year_level, section, department')
            ->where('tenant_id', $tenantId)->where('person_id', $personId)->get()->getRowArray() : null;
        $employeeProfile = $person['kind'] === 'employee' ? $this->db->table('employee_profiles')
            ->select('department, position')
            ->where('tenant_id', $tenantId)->where('person_id', $personId)->get()->getRowArray() : null;

        $can = [
            'queue' => $this->hasPermission($authority, 'guidance.queue.read'),
            'assessments' => $this->hasPermission($authority, 'guidance.assessments.view'),
            'surveys' => $this->hasPermission($authority, 'guidance.responses.read'),
            'intake' => $this->hasPermission($authority, 'guidance.intake.process'),
            'referrals' => $this->hasPermission($authority, 'referrals.read'),
            'safety' => $this->hasPermission($authority, 'guidance.safety.triage'),
            'schedule' => $this->hasPermission($authority, 'guidance.schedule.read'),
            'callSlips' => $this->hasPermission($authority, 'guidance.call_slips.issue'),
            'forms' => $this->hasAnyPermission($authority, [
                'guidance.clinical.author', 'guidance.clinical.cosign', 'guidance.forms.manage',
                'guidance.psychosocial.author', 'guidance.counseling.author',
            ]),
            'documents' => $this->hasPermission($authority, 'guidance.documents.print'),
        ];

        // Only the minimal authorization scope is loaded across tabs, never private titles or notes.
        $caseScope = $this->db->table('counselling_cases')
            ->select('id, tenant_id, person_id, counsellor_user_id, status')
            ->where('tenant_id', $tenantId)->where('person_id', $personId)->where('deleted_at', null)
            ->get()->getResultArray();
        $authorizedScope = [];
        foreach ($caseScope as $case) {
            try {
                $this->accessPolicy->assertCanReadCase($case, $authority);
                $authorizedScope[(int) $case['id']] = $case;
            } catch (DomainException $error) {
                if ($error->getCode() !== 403) {
                    throw $error;
                }
            }
        }
        $caseIds = array_keys($authorizedScope);
        $episodeScope = $this->hasTable('guidance_episodes')
            ? (new GuidanceEpisodeService(accessPolicy: $this->accessPolicy, db: $this->db))
                ->getPersonEpisodeSummaries($personId, $authority)
            : [];
        $episodeIds = array_map('intval', array_column($episodeScope, 'id'));
        $formIds = $can['forms'] || $can['schedule'] || $can['callSlips']
            ? $this->permittedFormIds($tenantId, $personId, $authority) : [];

        $builders = array_fill_keys(array_keys(self::PAGE_PARAMS), null);
        $builders['authorizedCases'] = $caseIds === [] ? null : $this->db->table('counselling_cases c')
            ->select('c.id, c.case_number, c.title, c.category, c.status, c.opened_at, c.closed_at, c.counsellor_user_id')
            ->where('c.tenant_id', $tenantId)->where('c.person_id', $personId)
            ->where('c.deleted_at', null)->whereIn('c.id', $caseIds);
        $builders['sessions'] = $this->caseHistory('counselling_sessions', 's', $tenantId, $caseIds)
            ?->select('s.id, s.case_id, s.session_number, s.session_type, s.status, s.started_at, s.ended_at, s.counsellor_user_id, c.case_number')
            ->where('s.deleted_at', null);
        $builders['followups'] = $this->caseHistory('guidance_followups', 'f', $tenantId, $caseIds)
            ?->select('f.id, f.case_id, f.due_date, f.status, f.completed_at, c.case_number');

        if ($can['assessments'] && $this->hasTable('guidance_mi_assignments')) {
            $builders['pendingAssessments'] = $this->personHistory('guidance_mi_assignments', 'a', $tenantId, $personId, softDelete: true)
                ->select('a.id, a.status, a.academic_term, a.academic_year, a.assigned_at, a.due_at')
                ->whereIn('a.status', ['assigned', 'in_progress']);
            if ($this->hasTable('guidance_mi_submissions')) {
                $builders['miAttempts'] = $this->personHistory('guidance_mi_submissions', 's', $tenantId, $personId, softDelete: true)
                    ->select('s.id, s.assignment_id, s.status, s.domain_scores_json, s.attested_at, s.created_at, a.academic_term, a.academic_year')
                    ->join('guidance_mi_assignments a', 'a.id = s.assignment_id AND a.tenant_id = s.tenant_id AND a.person_id = s.person_id')
                    ->where('a.deleted_at', null);
            }
        }
        if ($can['surveys'] && $this->hasTable('survey_responses') && $this->hasTable('surveys')) {
            $hasInterview = SurveyService::hasInterviewColumns($this->db);
            $builders['surveys'] = $this->personHistory('survey_responses', 'r', $tenantId, $personId)
                ->select('r.id AS response_id, r.survey_id, r.submitted_at, s.title, s.audience'
                    . ($hasInterview ? ', r.interviewed_by_user_id, r.interviewed_at' : ''))
                ->join('surveys s', 's.id = r.survey_id AND s.tenant_id = r.tenant_id');
            if ($this->db->fieldExists('deleted_at', 'surveys')) {
                $builders['surveys']->where('s.deleted_at', null);
            }
        }
        if ($can['intake']) {
            $builders['intakes'] = $this->personHistory('guidance_intake_referrals', 'i', $tenantId, $personId, 'subject_person_id')
                ?->select('i.id, i.referral_number, i.source_type, i.status, i.linked_case_id, i.submitted_at, i.created_at');
            $this->allowLinkedContext($builders['intakes'], 'i.linked_case_id', $caseIds);
        }
        if ($can['referrals']) {
            $builders['bridgeReferrals'] = $this->personHistory('referrals', 'r', $tenantId, $personId, 'patient_person_id')
                ?->select('r.id, r.referral_code, r.source_module, r.target_module, r.status, r.created_at')
                ->groupStart()->where('r.source_module', 'guidance')->orWhere('r.target_module', 'guidance')->groupEnd();
        }
        $builders['transfers'] = $this->caseHistory('guidance_case_transfers', 't', $tenantId, $caseIds)
            ?->select('t.id, t.case_id, t.from_counsellor_user_id, t.to_counsellor_user_id, t.transfer_type, t.status, t.requested_at, t.responded_at, c.case_number');
        $builders['assignments'] = $this->caseHistory('guidance_case_assignments', 'a', $tenantId, $caseIds)
            ?->select('a.id, a.case_id, a.counsellor_user_id, a.assignment_type, a.assigned_at, a.relinquished_at, c.case_number');
        if ($can['safety']) {
            // Safety plans and risk narratives remain on authorized case/intake workspaces.
            $builders['safetyReviews'] = $this->caseHistory('guidance_safety_assessments', 's', $tenantId, $caseIds)
                ?->select('s.id, s.case_id, s.assessor_user_id, s.assessed_at, c.case_number')
                ->where('s.person_id', $personId);
        }
        if ($can['schedule']) {
            $builders['sharedAppointments'] = $this->personHistory('appointments', 'a', $tenantId, $personId, softDelete: true)
                ?->select('a.id, a.provider_user_id, a.starts_at, a.ends_at, a.status, a.type')->where('a.unit', 'guidance');
            $builders['guidanceAppointments'] = $this->personHistory('guidance_appointments', 'a', $tenantId, $personId, softDelete: true)
                ?->select('a.id, a.appointment_number, a.counselor_user_id, a.scheduled_date, a.scheduled_time, a.status, a.form_instance_id');
            $this->allowLinkedContext($builders['guidanceAppointments'], 'a.episode_id', $episodeIds);
            $this->allowDocumentContext($builders['guidanceAppointments'], 'a', $tenantId, $personId, $caseIds, $episodeIds, $formIds);
        }
        if ($can['callSlips']) {
            $builders['callSlips'] = $this->personHistory('guidance_call_slips', 's', $tenantId, $personId, softDelete: true)
                ?->select('s.id, s.call_slip_number, s.issuing_counselor_user_id, s.call_date, s.call_time, s.attendance_status, s.form_instance_id');
            $this->allowLinkedContext($builders['callSlips'], 's.episode_id', $episodeIds);
            $this->allowDocumentContext($builders['callSlips'], 's', $tenantId, $personId, $caseIds, $episodeIds, $formIds);
        }
        if ($can['forms'] && $this->hasTable('guidance_form_definitions')) {
            $builders['forms'] = $this->personHistory('guidance_form_instances', 'f', $tenantId, $personId)
                ?->select('f.id, f.instance_number, f.case_id, f.session_id, f.status, f.privacy_notice_id, f.created_by_user_id, f.finalized_at, f.created_at, d.internal_key, d.form_code, d.canonical_title')
                ->join('guidance_form_definitions d', 'd.id = f.form_definition_id AND d.tenant_id = f.tenant_id');
            $this->allowLinkedContext($builders['forms'], 'f.case_id', $caseIds);
            if ($this->hasTable('guidance_form_instances') && $this->db->fieldExists('episode_id', 'guidance_form_instances')) {
                $this->allowLinkedContext($builders['forms'], 'f.episode_id', $episodeIds);
            }
            $this->allowSessionContext($builders['forms'], 'f', $tenantId, $caseIds);
            if ($builders['forms'] !== null) {
                if ($formIds === []) {
                    $builders['forms']->where('f.id', 0);
                } else {
                    $builders['forms']->whereIn('f.id', $formIds);
                }
            }
            if ($builders['forms'] !== null && $this->db->fieldExists('assigned_supervisor_user_id', 'guidance_form_instances')) {
                $builders['forms']->select('f.assigned_supervisor_user_id, f.submitted_for_cosign_at');
            }
        }

        $totals = [];
        $historyPagination = [];
        $recordQuery = ['tab' => $activeTab, 'per_page' => $pages['authorizedCases']->perPage];
        foreach (self::PAGE_PARAMS as $key => $parameter) {
            $totals[$key] = $key === 'episodes' ? count($episodeScope) : $this->count($builders[$key]);
            $historyPagination[$key] = $pages[$key]->metadata($totals[$key], $totals[$key]);
            if ($historyPagination[$key]['page'] > 1) {
                $recordQuery[$parameter] = $historyPagination[$key]['page'];
            }
        }

        $histories = array_fill_keys(array_keys(self::PAGE_PARAMS), []);
        $tabHistories = match ($activeTab) {
            'cases' => ['authorizedCases', 'episodes'],
            'sessions' => ['sessions', 'followups'],
            'assessments' => ['miAttempts', 'pendingAssessments', 'surveys'],
            'coordination' => ['intakes', 'bridgeReferrals', 'transfers', 'assignments', 'safetyReviews'],
            'forms' => ['forms'],
            'appointments' => ['sharedAppointments', 'guidanceAppointments', 'callSlips'],
            default => [],
        };
        $order = [
            'authorizedCases' => ['c.opened_at', 'c.id'], 'sessions' => ['s.started_at', 's.id'],
            'followups' => ['f.due_date', 'f.id'], 'miAttempts' => ['s.created_at', 's.id'],
            'pendingAssessments' => ['a.assigned_at', 'a.id'], 'surveys' => ['r.submitted_at', 'r.id'],
            'intakes' => ['i.created_at', 'i.id'], 'bridgeReferrals' => ['r.created_at', 'r.id'],
            'transfers' => ['t.requested_at', 't.id'], 'assignments' => ['a.assigned_at', 'a.id'],
            'safetyReviews' => ['s.assessed_at', 's.id'], 'forms' => ['f.created_at', 'f.id'],
            'sharedAppointments' => ['a.starts_at', 'a.id'], 'guidanceAppointments' => ['a.scheduled_date', 'a.scheduled_time', 'a.id'],
            'callSlips' => ['s.call_date', 's.call_time', 's.id'],
        ];
        foreach ($tabHistories as $key) {
            $meta = $historyPagination[$key];
            if ($key === 'episodes') {
                $histories[$key] = array_slice($episodeScope, ($meta['page'] - 1) * $meta['per_page'], $meta['per_page']);
            } elseif ($builders[$key] !== null) {
                $builder = clone $builders[$key];
                foreach ($order[$key] as $column) {
                    $builder->orderBy($column, 'DESC');
                }
                $histories[$key] = $builder->get($meta['per_page'], ($meta['page'] - 1) * $meta['per_page'], false)->getResultArray();
            }
        }

        $pendingFollowups = $builders['followups'] === null ? 0
            : (clone $builders['followups'])->where('f.status', 'pending')->countAllResults(false);

        $directory = $this->practitionerService->getPractitionerDirectory($tenantId);
        foreach ($histories['authorizedCases'] as &$case) {
            $handler = $directory[(int) $case['counsellor_user_id']] ?? [];
            $case['handler_name'] = $handler['name'] ?? 'Guidance practitioner';
            $case['handler_tier'] = $handler['tier_label'] ?? 'Guidance Staff';
            $case['service_mode'] = $handler['service_label'] ?? 'Counseling';
            $case['is_consultation'] = $this->accessPolicy->isConsultationPartner($authorizedScope[(int) $case['id']], $authority);
        }
        unset($case);
        foreach ($histories['episodes'] as &$episode) {
            $episode['handler_name'] = $directory[(int) ($episode['primary_counselor_user_id'] ?? 0)]['name'] ?? 'Unassigned';
            if (!$can['intake']) {
                unset($episode['intake_referral_id']);
            }
        }
        unset($episode);
        foreach ($histories['miAttempts'] as &$attempt) {
            $scores = json_decode((string) ($attempt['domain_scores_json'] ?? ''), true);
            $attempt['domain_scores'] = is_array($scores) ? $scores : [];
            unset($attempt['domain_scores_json']);
        }
        unset($attempt);
        foreach ($histories['surveys'] as &$survey) {
            $interviewerId = (int) ($survey['interviewed_by_user_id'] ?? 0);
            $survey['interviewer_name'] = $directory[$interviewerId]['name'] ?? null;
            $survey['interviewer_tier'] = $directory[$interviewerId]['tier_label'] ?? null;
        }
        unset($survey);
        $this->addAttestationMetadata($histories['forms'], $tenantId);

        $queueVisits = 0;
        $queueTotal = 0;
        if ($can['queue'] && $this->hasTable('queue_entries')) {
            $queue = $this->personHistory('queue_entries', 'q', $tenantId, $personId)->where('q.unit', 'guidance');
            $queueVisits = (clone $queue)->where('q.status', 'done')->countAllResults(false);
            $queueTotal = (clone $queue)->where('q.status !=', 'cancelled')->countAllResults(false);
        }
        $activeCases = count(array_filter($authorizedScope, static fn (array $case): bool => $case['status'] !== 'closed'));

        (new AuditService(null, $this->db))->log(
            'guidance.student_cumulative.viewed', 'people', (string) $personId, 'viewed',
            ['authorized_cases' => count($caseIds), 'section' => $activeTab],
            (int) $authority['id'], (string) $authority['role']
        );

        return $histories + [
            'person' => $person, 'studentProfile' => $studentProfile, 'employeeProfile' => $employeeProfile,
            'practitionerDirectory' => $directory, 'activeTab' => $activeTab,
            'can' => $can, 'totals' => $totals, 'historyPagination' => $historyPagination, 'recordQuery' => $recordQuery,
            'canViewResponses' => $can['surveys'], 'canProcessIntake' => $can['intake'],
            'metrics' => [
                'cases_total' => count($caseIds), 'cases_active' => $activeCases,
                'cases_closed' => count($caseIds) - $activeCases, 'episodes_total' => count($episodeScope),
                'sessions_total' => $totals['sessions'], 'followups_pending' => $pendingFollowups,
                'queue_visits_completed' => $queueVisits, 'queue_visits_total' => $queueTotal,
                'shared_appointments_total' => $totals['sharedAppointments'], 'guidance_appointments' => $totals['guidanceAppointments'],
                'call_slips_issued' => $totals['callSlips'], 'intake_referrals_total' => $totals['intakes'],
                'mi_attempts_total' => $totals['miAttempts'], 'mi_pending_total' => $totals['pendingAssessments'],
                'surveys_completed' => $totals['surveys'], 'forms_total' => $totals['forms'],
            ],
        ];
    }

    private function hasPermission(array $authority, string $permission): bool
    {
        return in_array($permission, $authority['permissions'] ?? [], true);
    }

    private function hasAnyPermission(array $authority, array $permissions): bool
    {
        return array_intersect($permissions, $authority['permissions'] ?? []) !== [];
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= $this->db->tableExists($table);
    }

    /** Authorize minimal form context before any titles, counts, or preview links are selected. */
    private function permittedFormIds(int $tenantId, int $personId, array $authority): array
    {
        if (!$this->hasTable('guidance_form_instances')) {
            return [];
        }
        $fields = array_intersect([
            'id', 'tenant_id', 'person_id', 'created_by_user_id', 'form_definition_id', 'definition_revision_id',
            'case_id', 'session_id', 'episode_id', 'appointment_id', 'referral_id', 'intake_id',
        ], $this->db->getFieldNames('guidance_form_instances'));
        $instances = $this->db->table('guidance_form_instances')->select($fields)
            ->where('tenant_id', $tenantId)->where('person_id', $personId)->get()->getResultArray();
        $policy = null;
        $ids = [];
        foreach ($instances as $instance) {
            try {
                $policy ??= new GuidanceFormContextPolicy(db: $this->db);
                $policy->assertInstanceAccess($instance, $authority, false, true);
            } catch (DomainException | InvalidArgumentException $error) {
                if (!in_array($error->getCode(), [403, 404, 422], true)) {
                    throw $error;
                }
                continue;
            }
            $ids[] = (int) $instance['id'];
        }
        return $ids;
    }

    private function count(?BaseBuilder $builder): int
    {
        return $builder === null ? 0 : (clone $builder)->countAllResults(false);
    }

    private function personHistory(
        string $table, string $alias, int $tenantId, int $personId,
        string $personColumn = 'person_id', bool $softDelete = false
    ): ?BaseBuilder {
        if (!$this->hasTable($table)) {
            return null;
        }
        $builder = $this->db->table($table . ' ' . $alias)
            ->where($alias . '.tenant_id', $tenantId)->where($alias . '.' . $personColumn, $personId);
        if ($softDelete) {
            $builder->where($alias . '.deleted_at', null);
        }
        return $builder;
    }

    private function caseHistory(string $table, string $alias, int $tenantId, array $caseIds): ?BaseBuilder
    {
        if ($caseIds === [] || !$this->hasTable($table)) {
            return null;
        }
        return $this->db->table($table . ' ' . $alias)
            ->join('counselling_cases c', 'c.id = ' . $alias . '.case_id AND c.tenant_id = ' . $alias . '.tenant_id')
            ->where($alias . '.tenant_id', $tenantId)->whereIn($alias . '.case_id', $caseIds)
            ->where('c.deleted_at', null);
    }

    /** Optional links must be either absent or inside the actor's authorized scope. */
    private function allowLinkedContext(?BaseBuilder $builder, string $column, array $ids): void
    {
        if ($builder === null) {
            return;
        }
        $builder->groupStart()->where($column, null)->orWhere($column, 0);
        if ($ids !== []) {
            $builder->orWhereIn($column, $ids);
        }
        $builder->groupEnd();
    }

    private function allowSessionContext(?BaseBuilder $builder, string $alias, int $tenantId, array $caseIds): void
    {
        if ($builder === null) {
            return;
        }
        $sessionAlias = $alias . '_session';
        $builder->join('counselling_sessions ' . $sessionAlias,
            $sessionAlias . '.id = ' . $alias . '.session_id AND ' . $sessionAlias . '.tenant_id = ' . $alias . '.tenant_id', 'left');
        $builder->groupStart()->where($alias . '.session_id', null)->orWhere($alias . '.session_id', 0);
        if ($caseIds !== []) {
            $builder->orGroupStart()->where($sessionAlias . '.tenant_id', $tenantId)
                ->where($sessionAlias . '.deleted_at', null)->whereIn($sessionAlias . '.case_id', $caseIds)
                ->groupStart()->where($alias . '.case_id', null)->orWhere($alias . '.case_id', 0)
                ->orWhere($sessionAlias . '.case_id = ' . $alias . '.case_id', null, false)->groupEnd()->groupEnd();
        }
        $builder->groupEnd();
    }

    private function allowDocumentContext(
        ?BaseBuilder $builder, string $alias, int $tenantId, int $personId,
        array $caseIds, array $episodeIds, array $formIds
    ): void {
        if ($builder === null) {
            return;
        }
        $this->allowLinkedContext($builder, $alias . '.form_instance_id', $formIds);
        if (!$this->hasTable('guidance_form_instances')) {
            $builder->where($alias . '.form_instance_id', null);
            return;
        }
        $documentAlias = $alias . '_document';
        $builder->join('guidance_form_instances ' . $documentAlias,
            $documentAlias . '.id = ' . $alias . '.form_instance_id AND ' . $documentAlias . '.tenant_id = ' . $alias . '.tenant_id', 'left');
        $builder->groupStart()->where($alias . '.form_instance_id', null)
            ->orGroupStart()->where($documentAlias . '.tenant_id', $tenantId)->where($documentAlias . '.person_id', $personId)->groupEnd()->groupEnd();
        $this->allowLinkedContext($builder, $documentAlias . '.case_id', $caseIds);
        if ($this->db->fieldExists('episode_id', 'guidance_form_instances')) {
            $this->allowLinkedContext($builder, $documentAlias . '.episode_id', $episodeIds);
        }
        $this->allowSessionContext($builder, $documentAlias, $tenantId, $caseIds);
    }

    private function addAttestationMetadata(array &$forms, int $tenantId): void
    {
        if ($forms === [] || !$this->hasTable('guidance_form_attestations')) {
            return;
        }
        $ids = array_map('intval', array_column($forms, 'id'));
        $attestations = $this->db->table('guidance_form_attestations')
            ->select('form_instance_id, signer_role, attested_at')
            ->where('tenant_id', $tenantId)->whereIn('form_instance_id', $ids)
            ->orderBy('attested_at', 'DESC')->get()->getResultArray();
        $byForm = [];
        foreach ($attestations as $attestation) {
            $byForm[(int) $attestation['form_instance_id']][] = [
                'signer_role' => $attestation['signer_role'], 'attested_at' => $attestation['attested_at'],
            ];
        }
        foreach ($forms as &$form) {
            $form['attestations'] = $byForm[(int) $form['id']] ?? [];
        }
        unset($form);
    }
}
