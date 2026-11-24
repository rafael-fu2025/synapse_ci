<?php

declare(strict_types=1);

namespace App\Services\Clinic;

use App\Libraries\TablePage;
use App\Models\AppointmentModel;
use App\Models\ClinicEncounterAddendumModel;
use App\Models\ClinicEncounterModel;
use App\Models\ClinicEnrollmentClearanceModel;
use App\Models\ClinicIsolationModel;
use App\Models\ClinicLabResultModel;
use App\Models\ClinicVitalsModel;
use App\Models\EmployeeProfileModel;
use App\Models\HealthProfileModel;
use App\Models\PersonModel;
use App\Models\StudentProfileModel;
use App\Services\CurrentTenant;
use App\Services\Scheduling\SchedulingClock;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final class PatientHistoryService
{
    private const PORTAL_ACTIVE_STATUSES = ['requested', 'scheduled', 'confirmed', 'checked_in'];

    private PersonModel $personModel;
    private StudentProfileModel $studentModel;
    private EmployeeProfileModel $employeeModel;
    private HealthProfileModel $healthModel;
    private ClinicEncounterModel $encounterModel;
    private ClinicVitalsModel $vitalsModel;
    private AppointmentModel $appointmentModel;
    private ?BaseConnection $db = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db;
        $this->personModel = new PersonModel($db);
        $this->studentModel = new StudentProfileModel($db);
        $this->employeeModel = new EmployeeProfileModel($db);
        $this->healthModel = new HealthProfileModel($db);
        $this->encounterModel = new ClinicEncounterModel($db);
        $this->vitalsModel = new ClinicVitalsModel($db);
        $this->appointmentModel = new AppointmentModel($db);
    }

    /**
     * Builds a comprehensive patient health chart. Portal and print callers retain
     * complete history unless the authorized staff route opts into named pages.
     *
     * @param array<string, mixed>|null $historyQuery Null retains the legacy full-history contract.
     * @return array<string, mixed>
     */
    public function getPatientChart(int $personId, bool $includeAppointments = true, ?array $historyQuery = null): array
    {
        $person = $this->personModel->scoped()->find($personId);
        if ($person === null) {
            throw new \InvalidArgumentException("Person ID {$personId} not found.");
        }
        if (empty($person['email']) && !empty($person['first_name']) && !empty($person['last_name'])) {
            $person['email'] = \App\Services\UniversityEmail::derive(
                (string) $person['first_name'],
                (string) $person['last_name']
            );
        }

        $studentProfile = ($person['kind'] === 'student') ? $this->studentModel->select('program, year_level, section, department')->findByPersonId($personId) : null;
        $employeeProfile = ($person['kind'] === 'employee') ? $this->employeeModel->select('department, position, employment_status, is_teaching')->findByPersonId($personId) : null;
        $healthProfile = $this->healthModel->findByPersonId($personId);

        $db = $this->db ?? \Config\Database::connect();
        $tenant = CurrentTenant::id();
        $historyPages = [];
        $historyPagination = [];
        if ($historyQuery !== null) {
            foreach (['visits', 'labs', 'clearances', 'isolation'] as $list) {
                $historyPages[$list] = TablePage::forList($historyQuery, $list . '_page');
                if (!in_array($historyPages[$list]->perPage, [25, 50, 100], true)) {
                    throw new \InvalidArgumentException('Per page must be 25, 50 or 100.', 422);
                }
                $historyPagination[$list] = $historyPages[$list]->metadata(0, 0);
            }
        }

        $encounterQuery = $db->table('clinic_encounters')
            ->where('clinic_encounters.tenant_id', $tenant)
            ->where('clinic_encounters.person_id', $personId)
            ->where('clinic_encounters.deleted_at', null);
        $encounterPage = $this->chartHistoryPage((clone $encounterQuery)
            ->select('clinic_encounters.*, u.username as attending_name, enc_u.username as encoder_name')
            ->join('users u', 'u.id = clinic_encounters.attending_user_id AND u.tenant_id = clinic_encounters.tenant_id', 'left')
            ->join('users enc_u', 'enc_u.id = clinic_encounters.encoded_by_user_id AND enc_u.tenant_id = clinic_encounters.tenant_id', 'left')
            ->orderBy('COALESCE(clinic_encounters.visited_at, clinic_encounters.started_at)', 'DESC', false)
            ->orderBy('clinic_encounters.id', 'DESC'), $historyPages['visits'] ?? null);
        $encounters = $encounterPage['rows'];
        $openVisit = null;
        if ($historyQuery !== null) {
            $historyPagination['visits'] = $encounterPage['pagination'];
            // An older unfinished encounter must never disappear behind a history page.
            $openVisit = (clone $encounterQuery)->where('clinic_encounters.status', 'in_progress')
                ->orderBy('COALESCE(clinic_encounters.visited_at, clinic_encounters.started_at)', 'DESC', false)
                ->orderBy('clinic_encounters.id', 'DESC')->get(1)->getRowArray();
        }

        // Recent vitals trend
        $vitalsHistory = $this->vitalsModel->scoped()
            ->select('clinic_vitals.*, e.started_at')
            ->join('clinic_encounters e', 'e.id = clinic_vitals.encounter_id AND e.tenant_id = clinic_vitals.tenant_id AND e.deleted_at IS NULL')
            ->where('e.person_id', $personId)
            ->orderBy('clinic_vitals.recorded_at', 'DESC')
            ->findAll(10);

        $treatments = $db->table('clinic_treatments t')
            ->select('t.encounter_id, t.diagnosis, t.treatment_plan, t.services_rendered')
            ->join('clinic_encounters e', 'e.id = t.encounter_id AND e.tenant_id = t.tenant_id AND e.deleted_at IS NULL')
            ->where('t.tenant_id', $tenant)->where('e.person_id', $personId)
            ->orderBy('e.started_at', 'DESC')->get()->getResultArray();
        $dispensations = $db->table('clinic_dispensations d')
            ->select('d.encounter_id, d.quantity, d.instructions, d.created_at')
            ->join('clinic_encounters e', 'e.id = d.encounter_id AND e.tenant_id = d.tenant_id AND e.deleted_at IS NULL')
            ->join('clinic_medicines m', 'm.id = d.medicine_id AND m.tenant_id = d.tenant_id')
            ->join('clinic_medicine_batches b', 'b.id = d.batch_id AND b.medicine_id = d.medicine_id AND b.tenant_id = d.tenant_id')
            ->where('d.tenant_id', $tenant)->where('e.person_id', $personId);
        if ((new InventoryService($db))->isInstalled()) {
            $dispensations->select('COALESCE(d.snapshot_name, m.name) AS medicine_name, COALESCE(d.snapshot_strength, m.strength) AS strength, COALESCE(d.snapshot_unit, m.unit_of_measure) AS unit_of_measure, COALESCE(d.snapshot_batch_number, b.batch_number) AS batch_number, COALESCE(d.snapshot_expiration_date, b.expiration_date) AS expiration_date, correction.id AS reversed_by_operation_id, correction.reversal_mode AS correction_mode', false)
                ->join('inventory_operations correction', 'correction.reversal_of = d.operation_id AND correction.tenant_id = d.tenant_id', 'left');
        } else {
            $dispensations->select('m.name AS medicine_name, m.strength, m.unit_of_measure, b.batch_number, b.expiration_date');
        }
        $dispensations = $dispensations->orderBy('d.created_at', 'DESC')->orderBy('d.id', 'DESC')->get()->getResultArray();

        $appointments = [];
        if ($includeAppointments) {
            // Never hide an actionable request behind the capped history list.
            $activeAppointments = $this->appointmentModel->scoped()
                ->select('appointments.*')
                ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
                ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
                ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
                ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
                ->where('appointments.person_id', $personId)
                ->whereIn('appointments.status', ['requested', 'scheduled', 'confirmed', 'checked_in'])
                ->orderBy('appointments.starts_at', 'ASC')
                ->findAll();
            $recentAppointments = $this->appointmentModel->scoped()
                ->select('appointments.*')
                ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
                ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
                ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
                ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
                ->where('appointments.person_id', $personId)
                ->whereIn('appointments.status', ['completed', 'cancelled', 'no_show'])
                ->orderBy('appointments.starts_at', 'DESC')
                ->findAll(10);
            $appointments = array_merge($activeAppointments, $recentAppointments);
        }

        $currentSy = self::currentSchoolYear();
        $syWindow = self::schoolYearUtcBounds($currentSy);
        $syVisits = 0;
        $topComplaints = [];
        $totalVisits = count($encounters);
        $lastVisitAt = $encounters[0]['visited_at'] ?? ($encounters[0]['started_at'] ?? null);
        if ($historyQuery !== null) {
            $visitAt = 'COALESCE(' . $db->protectIdentifiers('clinic_encounters.visited_at') . ', '
                . $db->protectIdentifiers('clinic_encounters.started_at') . ')';
            $summary = (clone $encounterQuery)
                ->select('COUNT(*) AS total_visits, MAX(' . $visitAt . ') AS last_visit_at, '
                    . 'SUM(CASE WHEN ' . $visitAt . ' >= ' . $db->escape($syWindow[0])
                    . ' AND ' . $visitAt . ' < ' . $db->escape($syWindow[1])
                    . ' THEN 1 ELSE 0 END) AS current_sy_visits', false)
                ->get()->getRowArray();
            $totalVisits = (int) ($summary['total_visits'] ?? 0);
            $syVisits = (int) ($summary['current_sy_visits'] ?? 0);
            $lastVisitAt = $summary['last_visit_at'] ?? null;
            $complaint = 'TRIM(' . $db->protectIdentifiers('clinic_encounters.chief_complaint') . ')';
            $complaints = (clone $encounterQuery)->select($complaint . ' AS label, COUNT(*) AS complaint_count', false)
                ->where($complaint . " <> ''", null, false)->groupBy($complaint, false)
                ->orderBy('complaint_count', 'DESC')->orderBy('MAX(' . $visitAt . ')', 'DESC', false)
                ->orderBy('MAX(' . $db->protectIdentifiers('clinic_encounters.id') . ')', 'DESC', false)
                ->get(5)->getResultArray();
            foreach ($complaints as $row) {
                $topComplaints[] = ['label' => $row['label'], 'count' => (int) $row['complaint_count']];
            }
        } else {
            $complaintCounts = [];
            foreach ($encounters as $enc) {
                $ts = (string) ($enc['visited_at'] ?? $enc['started_at'] ?? '');
                if ($ts >= $syWindow[0] && $ts < $syWindow[1]) {
                    $syVisits++;
                }
                $label = trim((string) ($enc['chief_complaint'] ?? ''));
                if ($label !== '') {
                    $complaintCounts[$label] = ($complaintCounts[$label] ?? 0) + 1;
                }
            }
            arsort($complaintCounts);
            foreach (array_slice($complaintCounts, 0, 5, true) as $label => $cnt) {
                $topComplaints[] = ['label' => $label, 'count' => $cnt];
            }
        }
        $visitSummary = [
            'total_visits'        => $totalVisits,
            'current_sy_visits'   => $syVisits,
            'current_school_year' => $currentSy,
            'last_visit_at'       => $lastVisitAt,
            'top_complaints'      => $topComplaints,
        ];

        $labResults = [];
        if ($db->tableExists('clinic_lab_results')) {
            $labPage = $this->chartHistoryPage($db->table('clinic_lab_results l')
                ->select('l.*, u.username AS recorded_by_name')
                ->join('users u', 'u.id = l.recorded_by_user_id AND u.tenant_id = l.tenant_id', 'left')
                ->where('l.tenant_id', $tenant)->where('l.person_id', $personId)->where('l.deleted_at', null)
                ->orderBy('COALESCE(l.submitted_at, l.created_at)', 'DESC', false)->orderBy('l.id', 'DESC'),
                $historyPages['labs'] ?? null);
            $labResults = $labPage['rows'];
            if ($historyQuery !== null) {
                $historyPagination['labs'] = $labPage['pagination'];
            }
        }
        $enrollmentClearances = [];
        if ($db->tableExists('clinic_enrollment_clearances')) {
            $clearancePage = $this->chartHistoryPage($db->table('clinic_enrollment_clearances c')
                ->select('c.*, u.username AS cleared_by_name')
                ->join('users u', 'u.id = c.cleared_by_user_id AND u.tenant_id = c.tenant_id', 'left')
                ->where('c.tenant_id', $tenant)->where('c.person_id', $personId)->where('c.deleted_at', null)
                ->orderBy('c.school_year', 'DESC')->orderBy('c.id', 'DESC'), $historyPages['clearances'] ?? null);
            $enrollmentClearances = $clearancePage['rows'];
            if ($historyQuery !== null) {
                $historyPagination['clearances'] = $clearancePage['pagination'];
            }
        }
        $isolationCases = [];
        if ($db->tableExists('clinic_isolation_cases')) {
            $isolationPage = $this->chartHistoryPage($db->table('clinic_isolation_cases i')
                ->select('i.*, rep.username AS reported_by_name, clr.username AS cleared_by_name')
                ->join('users rep', 'rep.id = i.reported_by_user_id AND rep.tenant_id = i.tenant_id', 'left')
                ->join('users clr', 'clr.id = i.cleared_by_user_id AND clr.tenant_id = i.tenant_id', 'left')
                ->where('i.tenant_id', $tenant)->where('i.person_id', $personId)->where('i.deleted_at', null)
                ->orderBy('i.isolation_start_date', 'DESC')->orderBy('i.id', 'DESC'), $historyPages['isolation'] ?? null);
            $isolationCases = $isolationPage['rows'];
            if ($historyQuery !== null) {
                $historyPagination['isolation'] = $isolationPage['pagination'];
            }
        }

        $historyState = [];
        if ($historyQuery !== null) {
            $historyState = ['history_pagination' => $historyPagination, 'open_visit' => $openVisit];
        }

        return $historyState + [
            'person'                => $person,
            'student_profile'       => $studentProfile,
            'employee_profile'      => $employeeProfile,
            'health_profile'        => $healthProfile,
            'encounters'            => $encounters,
            'visit_summary'         => $visitSummary,
            'vitals_history'        => $vitalsHistory,
            'appointments'          => $appointments,
            'treatment_history'     => $treatments,
            'dispensation_history'  => $dispensations,
            'lab_results'           => $labResults,
            'enrollment_clearances' => $enrollmentClearances,
            'isolation_cases'       => $isolationCases,
        ];
    }

    /** Counts the full scoped history before applying a staff cursor. */
    private function chartHistoryPage(BaseBuilder $builder, ?TablePage $page): array
    {
        if ($page === null) {
            return ['rows' => $builder->get()->getResultArray()];
        }
        $total = (clone $builder)->countAllResults();
        $pagination = $page->metadata($total, $total);
        $rows = $builder->get($page->perPage, ($pagination['page'] - 1) * $page->perPage)->getResultArray();
        return compact('rows', 'pagination');
    }

    /**
     * Returns the signed-in person's appointments with portal filters applied.
     *
     * @param array{service?: string, status?: string, sort?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getPortalAppointments(int $personId, array $filters = []): array
    {
        return $this->orderedPortalAppointments($personId, $filters)->findAll();
    }

    /**
     * Returns a counted, bounded page without changing the mobile API's list contract.
     *
     * @param array{service?: string, status?: string, sort?: string, page?: int|string, per_page?: int|string} $filters
     * @return array{appointments: array, pagination: array}
     */
    public function getPortalAppointmentsPage(int $personId, array $filters = []): array
    {
        $perPage = filter_var($filters['per_page'] ?? 25, FILTER_VALIDATE_INT);
        $perPage = in_array($perPage, [20, 25, 50, 100], true) ? $perPage : 25;
        $page = max(1, (int) filter_var($filters['page'] ?? 1, FILTER_VALIDATE_INT));
        $total = $this->portalAppointmentQuery($personId)->countAllResults();
        $filtered = $this->portalAppointmentQuery($personId, $filters)->countAllResults();
        $pages = max(1, (int) ceil($filtered / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'appointments' => $this->orderedPortalAppointments($personId, $filters)->findAll($perPage, $offset),
            'pagination' => [
                'page' => $page, 'pages' => $pages, 'per_page' => $perPage,
                'total' => $total, 'filtered' => $filtered,
                'from' => $filtered === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $filtered),
            ],
        ];
    }

    private function portalAppointmentQuery(int $personId, array $filters = []): AppointmentModel
    {
        $service = (string) ($filters['service'] ?? 'all');
        if (!in_array($service, ['all', 'clinic', 'guidance'], true)) {
            $service = 'all';
        }

        $status = (string) ($filters['status'] ?? 'all');
        $statuses = match ($status) {
            'active' => self::PORTAL_ACTIVE_STATUSES,
            'requested' => ['requested'],
            'confirmed' => ['scheduled', 'confirmed'],
            'checked_in' => ['checked_in'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'no_show' => ['no_show'],
            default => [],
        };

        $builder = $this->appointmentModel->scoped()->where('appointments.person_id', $personId);

        if ($service !== 'all') {
            $builder->where('appointments.unit', $service);
        }
        if ($statuses !== []) {
            $builder->whereIn('appointments.status', $statuses);
        }

        return $builder;
    }

    private function orderedPortalAppointments(int $personId, array $filters): AppointmentModel
    {
        $builder = $this->portalAppointmentQuery($personId, $filters)
            ->select('appointments.*')
            ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
            ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
            ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
            ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left');
        $sort = $filters['sort'] ?? 'upcoming';
        if (in_array($sort, ['date_asc', 'date_desc'], true)) {
            $direction = $sort === 'date_asc' ? 'ASC' : 'DESC';
            return $builder->orderBy('appointments.starts_at', $direction)->orderBy('appointments.id', $direction);
        }

        // Match the portal's active-first order, including deterministic ties across pages.
        $db = $builder->builder()->db();
        $status = $db->protectIdentifiers('appointments.status');
        $startsAt = $db->protectIdentifiers('appointments.starts_at');
        $id = $db->protectIdentifiers('appointments.id');
        $active = "{$status} IN ('requested', 'scheduled', 'confirmed', 'checked_in')";
        return $builder->orderBy("CASE WHEN {$active} THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy("CASE WHEN {$active} THEN {$startsAt} END", 'ASC', false)
            ->orderBy("CASE WHEN {$active} THEN {$id} END", 'ASC', false)
            ->orderBy('appointments.starts_at', 'DESC')
            ->orderBy('appointments.id', 'DESC');
    }

    /** Returns one appointment only when it belongs to the given person. */
    public function getPortalAppointment(int $personId, int $appointmentId): ?array
    {
        return $this->appointmentModel->scoped()
            ->select('appointments.*')
            ->select("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', provider.first_name, provider.last_name)), ''), u.username) AS provider_name", false)
            ->join('department_staff staff', 'staff.id = appointments.staff_assignment_id AND staff.tenant_id = appointments.tenant_id AND staff.unit = appointments.unit', 'left')
            ->join('people provider', 'provider.id = staff.person_id AND provider.tenant_id = staff.tenant_id', 'left')
            ->join('users u', 'u.id = appointments.provider_user_id AND u.tenant_id = appointments.tenant_id', 'left')
            ->where('appointments.person_id', $personId)
            ->where('appointments.id', $appointmentId)
            ->first();
    }

    /**
     * Update health profile (allergies, chronic conditions, blood type, emergency contact).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateHealthProfile(int $personId, array $data): array
    {
        $existing = $this->healthModel->findByPersonId($personId);
        $clean = static fn (mixed $v): ?string => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $pick = static fn (string $k) => array_key_exists($k, $data) ? $clean($data[$k]) : ($existing[$k] ?? null);
        $payload = [
            'tenant_id'                      => CurrentTenant::id(),
            'person_id'                      => $personId,
            'blood_type'                     => $clean($data['blood_type'] ?? null),
            'allergies'                      => $clean($data['allergies'] ?? null),
            'chronic_conditions'             => $clean($data['chronic_conditions'] ?? null),
            'medical_history_notes'          => $pick('medical_history_notes'),
            'surgical_history'               => $pick('surgical_history'),
            'immunization_notes'             => $pick('immunization_notes'),
            'family_history'                 => $pick('family_history'),
            'emergency_contact_name'         => $clean($data['emergency_contact_name'] ?? null),
            'emergency_contact_phone'        => $clean($data['emergency_contact_phone'] ?? null),
            'emergency_contact_relationship' => $clean($data['emergency_contact_relationship'] ?? null),
        ];

        if ($existing !== null) {
            $this->healthModel->update($existing['id'], $payload);
        } else {
            $this->healthModel->insert($payload);
        }

        return $this->healthModel->findByPersonId($personId);
    }

    /**
     * Record a diagnostic / laboratory result (for enrollment exam or next-day encounter follow-up).
     * Automatically appends a signed addendum if linked to a sealed encounter.
     */
    public function recordLabResult(int $personId, array $data, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new \InvalidArgumentException('Authenticated clinician is required.', 422);
        }
        $person = $this->personModel->scoped()->find($personId);
        if ($person === null) {
            throw new \InvalidArgumentException("Person ID {$personId} not found.", 404);
        }
        $testName = trim((string) ($data['test_name'] ?? ''));
        if ($testName === '' || mb_strlen($testName) > 100) {
            throw new \InvalidArgumentException('Laboratory test name is required (1 to 100 characters).', 422);
        }
        $resultStatus = trim((string) ($data['result_status'] ?? 'pending'));
        if (!in_array($resultStatus, ['pending', 'normal', 'abnormal', 'inconclusive'], true)) {
            throw new \InvalidArgumentException('Invalid lab result status.', 422);
        }
        $contextType = trim((string) ($data['context_type'] ?? 'encounter_followup'));
        if (!in_array($contextType, ['encounter_followup', 'enrollment_annual', 'other'], true)) {
            throw new \InvalidArgumentException('Invalid laboratory context type.', 422);
        }
        $schoolYear = trim((string) ($data['school_year'] ?? self::currentSchoolYear()));
        if (!preg_match('/\A\d{4}-\d{4}\z/D', $schoolYear)) {
            throw new \InvalidArgumentException('School year must follow YYYY-YYYY format.', 422);
        }
        $summary = trim((string) ($data['result_summary'] ?? ''));
        $notes = trim((string) ($data['clinical_notes'] ?? ''));
        $encounterId = null;
        if (!empty($data['encounter_id'])) {
            $encId = filter_var($data['encounter_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($encId === false) {
                throw new \InvalidArgumentException('Invalid encounter reference.', 422);
            }
            $enc = $this->encounterModel->scoped()->where('person_id', $personId)->find($encId);
            if ($enc === null) {
                throw new \InvalidArgumentException('Linked encounter not found for this patient.', 404);
            }
            $encounterId = $encId;
        }

        $nowUtc = gmdate('Y-m-d H:i:s');
        $submittedAt = $resultStatus !== 'pending' ? $nowUtc : null;
        $requestedAt = !empty($data['requested_date']) && preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', (string) $data['requested_date'])
            ? trim((string) $data['requested_date']) . ' 00:00:00'
            : $nowUtc;

        $db = \Config\Database::connect();
        $model = new ClinicLabResultModel($db);
        $labId = $model->insert([
            'tenant_id'           => CurrentTenant::id(),
            'person_id'           => $personId,
            'encounter_id'        => $encounterId,
            'school_year'         => $schoolYear,
            'context_type'        => $contextType,
            'test_name'           => $testName,
            'result_status'       => $resultStatus,
            'result_summary'      => $summary !== '' ? $summary : null,
            'clinical_notes'      => $notes !== '' ? $notes : null,
            'requested_at'        => $requestedAt,
            'submitted_at'        => $submittedAt,
            'recorded_by_user_id' => $actorUserId,
        ]);
        if ($labId === false) {
            throw new \RuntimeException('Could not save laboratory result.');
        }

        if ($encounterId !== null) {
            $enc = $this->encounterModel->scoped()->find($encounterId);
            if ($enc !== null && in_array($enc['status'], ['completed', 'referred'], true)) {
                $addendumNote = sprintf(
                    '[%s · %s] %s%s',
                    $testName,
                    strtoupper($resultStatus),
                    $summary !== '' ? $summary : 'Result logged',
                    $notes !== '' ? ' — ' . $notes : ''
                );
                (new ClinicEncounterAddendumModel($db))->insert([
                    'tenant_id'      => CurrentTenant::id(),
                    'encounter_id'   => $encounterId,
                    'addendum_type'  => 'lab_followup',
                    'note'           => $addendumNote,
                    'lab_result_id'  => (int) $labId,
                    'author_user_id' => $actorUserId,
                ]);
            }
        }

        return $model->scoped()->find((int) $labId);
    }

    /**
     * Create or update a school-year enrollment / annual health clearance checklist (Clinic internal; no BFO link).
     */
    public function upsertEnrollmentClearance(int $personId, array $data, int $actorUserId): array
    {
        if ($actorUserId < 1) {
            throw new \InvalidArgumentException('Authenticated clinician is required.', 422);
        }
        $person = $this->personModel->scoped()->find($personId);
        if ($person === null) {
            throw new \InvalidArgumentException("Person ID {$personId} not found.", 404);
        }
        $schoolYear = trim((string) ($data['school_year'] ?? self::currentSchoolYear()));
        if (!preg_match('/\A\d{4}-\d{4}\z/D', $schoolYear)) {
            throw new \InvalidArgumentException('School year must follow YYYY-YYYY format.', 422);
        }
        $semester = trim((string) ($data['semester'] ?? '1st Semester'));
        if (!in_array($semester, ['1st Semester', '2nd Semester', 'Summer', 'Annual'], true)) {
            throw new \InvalidArgumentException('Choose a valid enrollment term.', 422);
        }
        $category = trim((string) ($data['applicant_category'] ?? ($person['kind'] === 'employee' ? 'employee' : 'freshman')));
        if (!in_array($category, ['freshman', 'transferee', 'returning', 'employee'], true)) {
            throw new \InvalidArgumentException('Invalid applicant category.', 422);
        }
        $status = trim((string) ($data['clearance_status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'cleared', 'hold_followup'], true)) {
            throw new \InvalidArgumentException('Invalid clearance status.', 422);
        }

        $checklistKeys = ['physical_exam', 'cbc', 'urinalysis', 'chest_xray', 'fecalysis', 'dental_exam'];
        $submittedChecklist = is_array($data['checklist'] ?? null) ? $data['checklist'] : [];
        $checklist = [];
        foreach ($checklistKeys as $item) {
            $checklist[$item] = !empty($submittedChecklist[$item]);
        }

        $remarks = trim((string) ($data['remarks'] ?? ''));
        $model = new ClinicEnrollmentClearanceModel();
        $existing = $model->scoped()
            ->where('person_id', $personId)
            ->where('school_year', $schoolYear)
            ->where('semester', $semester)
            ->first();

        $payload = [
            'tenant_id'          => CurrentTenant::id(),
            'person_id'          => $personId,
            'school_year'        => $schoolYear,
            'semester'           => $semester,
            'applicant_category' => $category,
            'required_labs_json' => json_encode($checklist, JSON_THROW_ON_ERROR),
            'clearance_status'   => $status,
            'remarks'            => $remarks !== '' ? $remarks : null,
            'cleared_by_user_id' => $status === 'cleared' ? $actorUserId : ($existing['cleared_by_user_id'] ?? null),
            'cleared_at'         => $status === 'cleared' ? gmdate('Y-m-d H:i:s') : ($existing['cleared_at'] ?? null),
        ];

        if ($existing !== null) {
            $model->scoped()->update((int) $existing['id'], $payload);
            return $model->scoped()->find((int) $existing['id']);
        }
        $id = $model->insert($payload);
        return $model->scoped()->find((int) $id);
    }

    public static function currentSchoolYear(): string
    {
        $now = SchedulingClock::now();
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $startYear = $month >= 6 ? $year : $year - 1;
        return $startYear . '-' . ($startYear + 1);
    }

    /** @return array{0: string, 1: string} UTC inclusive-start / exclusive-end bounds for YYYY-YYYY school year (June 1 -> May 31) */
    public static function schoolYearUtcBounds(string $schoolYear): array
    {
        $startYear = (int) substr($schoolYear, 0, 4);
        $manila = SchedulingClock::timezone();
        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($startYear . '-06-01 00:00:00', $manila);
        $end = new \DateTimeImmutable(($startYear + 1) . '-06-01 00:00:00', $manila);
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * Update only emergency contact fields, preserving existing clinical fields.
     *
     * @param array{emergency_contact_name?: ?string, emergency_contact_phone?: ?string, emergency_contact_relationship?: ?string} $data
     * @return array<string, mixed>
     */
    public function updateEmergencyContact(int $personId, array $data): array
    {
        $existing = $this->healthModel->findByPersonId($personId);
        $clean = static fn (?string $val): ?string => $val !== null && trim($val) !== '' ? trim($val) : null;
        $payload = [
            'tenant_id'                      => CurrentTenant::id(),
            'person_id'                      => $personId,
            'emergency_contact_name'         => $clean($data['emergency_contact_name'] ?? null),
            'emergency_contact_phone'        => $clean($data['emergency_contact_phone'] ?? null),
            'emergency_contact_relationship' => $clean($data['emergency_contact_relationship'] ?? null),
        ];

        if ($existing !== null) {
            $this->healthModel->update($existing['id'], $payload);
        } else {
            $this->healthModel->insert($payload);
        }

        return $this->healthModel->findByPersonId($personId);
    }
}
