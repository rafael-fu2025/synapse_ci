<?php

declare(strict_types=1);

namespace App\Services\Guidance;

use App\Services\Audit\AuditService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use InvalidArgumentException;

/**
 * Consolidates a student's or employee's longitudinal non-clinical Guidance profile,
 * Multiple Intelligence attempts, survey/routine interview status, service frequency,
 * call slips/appointments, and only the cases the actor is authorized to read.
 */
final class GuidanceCumulativeService
{
    private BaseConnection $db;
    private GuidanceAccessPolicy $accessPolicy;
    private GuidancePractitionerService $practitionerService;

    public function __construct(
        ?BaseConnection $db = null,
        ?GuidanceAccessPolicy $accessPolicy = null,
        ?GuidancePractitionerService $practitionerService = null
    ) {
        $this->db                  = $db ?? Database::connect();
        $this->accessPolicy        = $accessPolicy ?? new GuidanceAccessPolicy(db: $this->db);
        $this->practitionerService = $practitionerService ?? new GuidancePractitionerService(accessPolicy: $this->accessPolicy, db: $this->db);
    }

    /**
     * Loads the audited Cumulative Guidance Record for a tenant-scoped person.
     *
     * @param array<string, mixed>|null $actor
     * @return array<string, mixed>
     */
    public function getStudentCumulativeRecord(int $personId, ?array $actor = null): array
    {
        $authority = $this->accessPolicy->resolve($actor, 'guidance.cases.read');
        $tenantId  = (int) $authority['tenant_id'];

        $person = $this->db->table('people')
            ->where('tenant_id', $tenantId)
            ->where('id', $personId)
            ->where('deleted_at IS NULL')
            ->get()
            ->getRowArray();

        if ($person === null) {
            throw new InvalidArgumentException('Person record not found.', 404);
        }

        $studentProfile = null;
        $employeeProfile = null;
        if (($person['kind'] ?? '') === 'student') {
            $studentProfile = $this->db->table('student_profiles')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->get()
                ->getRowArray();
        } elseif (($person['kind'] ?? '') === 'employee') {
            $employeeProfile = $this->db->table('employee_profiles')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->get()
                ->getRowArray();
        }

        $practitionerDirectory = $this->practitionerService->getPractitionerDirectory($tenantId);

        // 1. Service frequency & engagement counts ("kapila na siya nibisita")
        $queueCompleted = (int) $this->db->table('queue_entries')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->where('unit', 'guidance')
            ->where('status', 'completed')
            ->countAllResults();

        $queueTotal = (int) $this->db->table('queue_entries')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->where('unit', 'guidance')
            ->where('status !=', 'cancelled')
            ->countAllResults();

        $sharedAppointmentsTotal = (int) $this->db->table('appointments')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->where('unit', 'guidance')
            ->countAllResults();

        $guidanceAppointments = [];
        if ($this->db->tableExists('guidance_appointments')) {
            $guidanceAppointments = $this->db->table('guidance_appointments')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->where('deleted_at IS NULL')
                ->orderBy('scheduled_date', 'DESC')
                ->orderBy('scheduled_time', 'DESC')
                ->limit(25)
                ->get()
                ->getResultArray();
        }

        $callSlips = [];
        if ($this->db->tableExists('guidance_call_slips')) {
            $callSlips = $this->db->table('guidance_call_slips')
                ->where('tenant_id', $tenantId)
                ->where('person_id', $personId)
                ->where('deleted_at IS NULL')
                ->orderBy('call_date', 'DESC')
                ->orderBy('call_time', 'DESC')
                ->limit(25)
                ->get()
                ->getResultArray();
        }

        // 2. Longitudinal Multiple Intelligence history (all attempts preserved across dates)
        $miAttempts = [];
        if ($this->db->tableExists('guidance_mi_submissions') && $this->db->tableExists('guidance_mi_assignments')) {
            $rows = $this->db->table('guidance_mi_submissions s')
                ->select('s.id, s.assignment_id, s.status, s.domain_scores_json, s.attested_at, s.created_at, a.academic_term, a.academic_year, a.assigned_at, a.completed_at')
                ->join('guidance_mi_assignments a', 'a.id = s.assignment_id AND a.tenant_id = s.tenant_id', 'inner')
                ->where('s.tenant_id', $tenantId)
                ->where('s.person_id', $personId)
                ->where('s.deleted_at IS NULL')
                ->orderBy('s.created_at', 'DESC')
                ->limit(20)
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $scores = json_decode((string) ($row['domain_scores_json'] ?? ''), true);
                $row['domain_scores'] = is_array($scores) ? $scores : [];
                $miAttempts[] = $row;
            }
        }

        // 3. Routine / intake survey history & staff interview status
        $surveys = [];
        if ($this->db->tableExists('survey_responses') && $this->db->tableExists('surveys')) {
            $hasInterviewCols = SurveyService::hasInterviewColumns($this->db);
            $selectCols = 'r.id as response_id, r.survey_id, r.submitted_at, s.title, s.audience'
                . ($hasInterviewCols ? ', r.interviewed_by_user_id, r.interviewed_at' : '');

            $rows = $this->db->table('survey_responses r')
                ->select($selectCols)
                ->join('surveys s', 's.tenant_id = r.tenant_id AND s.id = r.survey_id', 'inner')
                ->where('r.tenant_id', $tenantId)
                ->where('r.person_id', $personId)
                ->orderBy('r.submitted_at', 'DESC')
                ->limit(25)
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $interviewerId = (int) ($row['interviewed_by_user_id'] ?? 0);
                $interviewer   = $interviewerId > 0 ? ($practitionerDirectory[$interviewerId] ?? null) : null;
                $row['interviewer_name']  = $interviewer['name'] ?? ($interviewerId > 0 ? 'Staff #' . $interviewerId : null);
                $row['interviewer_tier']  = $interviewer['tier_label'] ?? null;
                $surveys[] = $row;
            }
        }

        // 4. Intake referral summaries (metadata only; decrypted details stay on intake view)
        $intakes = [];
        if ($this->db->tableExists('guidance_intake_referrals')) {
            $intakes = $this->db->table('guidance_intake_referrals')
                ->select('id, referral_number, source_type, referrer_name_snapshot, priority_hint, status, linked_case_id, submitted_at, created_at')
                ->where('tenant_id', $tenantId)
                ->where('subject_person_id', $personId)
                ->orderBy('created_at', 'DESC')
                ->limit(25)
                ->get()
                ->getResultArray();
        }

        // 5. Authorized cases only (own cases, accepted consultation shares, or read_any oversight)
        $allCases = $this->db->table('counselling_cases')
            ->where('tenant_id', $tenantId)
            ->where('person_id', $personId)
            ->where('deleted_at IS NULL')
            ->orderBy('opened_at', 'DESC')
            ->get()
            ->getResultArray();

        $authorizedCases = [];
        $restrictedCasesCount = 0;
        foreach ($allCases as $case) {
            try {
                $this->accessPolicy->readCase($case, $authority, 'guidance.cases.read');
                $ownerId = (int) ($case['counsellor_user_id'] ?? 0);
                $ownerInfo = $practitionerDirectory[$ownerId] ?? null;
                $case['handler_name']        = $ownerInfo['name'] ?? ('Staff #' . $ownerId);
                $case['handler_tier']        = $ownerInfo['tier_label'] ?? 'Guidance Staff';
                $case['service_mode']        = $ownerInfo['service_label'] ?? 'Counseling';
                $case['is_consultation']     = $this->accessPolicy->isConsultationPartner($case, $authority);
                $authorizedCases[]           = $case;
            } catch (DomainException) {
                $restrictedCasesCount++;
            }
        }

        // 6. Total Guidance sessions count across all cases of this student (aggregate count only)
        $sessionsTotal = (int) $this->db->table('counselling_sessions s')
            ->join('counselling_cases c', 'c.id = s.case_id AND c.tenant_id = s.tenant_id', 'inner')
            ->where('c.tenant_id', $tenantId)
            ->where('c.person_id', $personId)
            ->where('c.deleted_at IS NULL')
            ->where('s.deleted_at IS NULL')
            ->countAllResults();

        $canViewResponses = in_array('guidance.responses.read', $authority['permissions'] ?? [], true)
            || ($authority['role'] ?? '') === 'superadmin';
        $canProcessIntake = in_array('guidance.intake.process', $authority['permissions'] ?? [], true)
            || ($authority['role'] ?? '') === 'superadmin';

        (new AuditService(null, $this->db))->log(
            'guidance.student_cumulative.viewed',
            'people',
            (string) $personId,
            'viewed',
            [
                'authorized_cases' => count($authorizedCases),
                'restricted_cases' => $restrictedCasesCount,
            ],
            (int) $authority['id'],
            (string) $authority['role']
        );

        return [
            'person'                => $person,
            'studentProfile'        => $studentProfile,
            'employeeProfile'       => $employeeProfile,
            'practitionerDirectory' => $practitionerDirectory,
            'metrics'               => [
                'queue_visits_completed'    => $queueCompleted,
                'queue_visits_total'        => $queueTotal,
                'shared_appointments_total' => $sharedAppointmentsTotal,
                'guidance_appointments'     => count($guidanceAppointments),
                'call_slips_issued'         => count($callSlips),
                'intake_referrals_total'    => count($intakes),
                'mi_attempts_total'         => count($miAttempts),
                'surveys_completed'         => count($surveys),
                'sessions_total'            => $sessionsTotal,
                'cases_total'               => count($allCases),
            ],
            'miAttempts'            => $miAttempts,
            'surveys'               => $surveys,
            'callSlips'             => $callSlips,
            'guidanceAppointments'  => $guidanceAppointments,
            'intakes'               => $intakes,
            'authorizedCases'       => $authorizedCases,
            'restrictedCasesCount'  => $restrictedCasesCount,
            'canViewResponses'      => $canViewResponses,
            'canProcessIntake'      => $canProcessIntake,
        ];
    }
}

