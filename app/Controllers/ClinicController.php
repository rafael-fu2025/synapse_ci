<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ClinicEncounterModel;
use App\Models\ClinicMedicineModel;
use App\Models\HealthProfileModel;
use App\Models\PersonModel;
use App\Models\QueueEntryModel;
use App\Models\StudentProfileModel;
use App\Models\EmployeeProfileModel;
use App\Services\Clinic\EncounterService;
use App\Services\Clinic\InventoryService;
use App\Services\Clinic\IsolationTrackingService;
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
use App\Services\DashboardCache;
use App\Services\Reporting\ClinicMedicalCertificateLatex;
use App\Services\Reporting\ClinicReportInsights;
use App\Services\Reporting\ClinicSummaryReportLatex;
use App\Services\Reporting\ClinicSummaryReportService;
use App\Services\Reporting\ExportPdf;
use App\Services\Reporting\LatexReportCompiler;
use App\Services\Scheduling\SchedulingClock;
use Config\Database;

final class ClinicController extends BaseController
{
    private EncounterService $encounterService;
    private PatientHistoryService $historyService;
    private ClinicEncounterModel $encounterModel;
    private ClinicMedicineModel $medicineModel;
    private PersonModel $personModel;

    public function __construct()
    {
        $this->encounterService = new EncounterService();
        $this->historyService = new PatientHistoryService();
        $this->encounterModel = new ClinicEncounterModel();
        $this->medicineModel = new ClinicMedicineModel();
        $this->personModel = new PersonModel();
    }

    /**
     * Clinic Workstation Main Dashboard.
     */
    public function dashboard()
    {
        $tenantId = CurrentTenant::id();
        $db = Database::connect();

        $today = SchedulingClock::today();
        $rangeKey = (string) ($this->request->getGet('range') ?? '7d');
        if (!in_array($rangeKey, ['7d', '30d', 'sy'], true)) {
            $rangeKey = '7d';
        }
        $cacheKey = $rangeKey === '7d' ? 'clinic.dashboard' : ('clinic.dashboard.' . $rangeKey);

        // A workstation reload must not re-run the whole summary; the payload
        // describes today only, so a short TTL cannot cross a day boundary.
        $metrics = DashboardCache::remember($cacheKey, DashboardCache::TTL, static function () use ($db, $tenantId, $today, $rangeKey): array {
            $queueWaiting = $db->table('queue_entries')
                ->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('queue_date', $today)
                ->where('status', 'waiting')->countAllResults();
            $queueCalled = $db->table('queue_entries')
                ->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('queue_date', $today)
                ->where('status', 'called')->countAllResults();
            $queueCompleted = $db->table('queue_entries')
                ->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('queue_date', $today)
                ->where('status', 'done')->countAllResults();

            $encountersCount = $db->table('clinic_encounters')
                ->where('tenant_id', $tenantId)->where('status', 'completed')->countAllResults();

            $appointmentsToday = $db->table('appointments')
                ->where('tenant_id', $tenantId)->where('unit', 'clinic')
                ->where('local_date', $today)->countAllResults();

            $inventoryInstalled = (new InventoryService($db))->isInstalled();
            $medicinesCount = $inventoryInstalled ? $db->table('clinic_medicines')
                ->where('tenant_id', $tenantId)->where('is_active', 1)->countAllResults() : null;

            $inventoryAlerts = ['low_stock' => 0, 'expired_batches' => 0, 'near_expiry_batches' => 0];
            if ($inventoryInstalled) {
                $medRows = (new InventoryService($db))->listMedicines('', 'all', 1, 1);
                $inventoryAlerts['low_stock'] = (int) ($medRows['counts']['low'] ?? 0);
                $nearLimit = date('Y-m-d', strtotime($today . ' +90 days'));
                $inventoryAlerts['expired_batches'] = (int) $db->table('clinic_medicine_batches')
                    ->where('tenant_id', $tenantId)->where('remaining_quantity >', 0)
                    ->where('expiration_date <', $today)->countAllResults();
                $inventoryAlerts['near_expiry_batches'] = (int) $db->table('clinic_medicine_batches')
                    ->where('tenant_id', $tenantId)->where('status', 'active')->where('remaining_quantity >', 0)
                    ->where('expiration_date >=', $today)->where('expiration_date <=', $nearLimit)->countAllResults();
            }

            $activeIsolationCount = 0;
            $isolationAlerts = [];
            if ($db->tableExists('clinic_isolation_cases')) {
                $activeIsolationCount = (int) $db->table('clinic_isolation_cases')
                    ->where('tenant_id', $tenantId)->where('deleted_at', null)
                    ->where('clearance_status', 'active_isolation')->countAllResults();
                $isolationAlerts = (new IsolationTrackingService($db))->anonymizedThresholdAlerts();
            }

            // Today's active queue by priority
            $triageCounts = ['emergency' => 0, 'urgent' => 0, 'routine' => 0];
            foreach ($db->table('queue_entries')
                ->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('queue_date', $today)
                ->whereIn('status', ['waiting', 'called', 'in_consultation'])
                ->select('priority, COUNT(*) AS total')->groupBy('priority')
                ->get()->getResultArray() as $triageRow) {
                if (isset($triageCounts[$triageRow['priority']])) {
                    $triageCounts[$triageRow['priority']] = (int) $triageRow['total'];
                }
            }

            $hasVisitedAt = $db->fieldExists('visited_at', 'clinic_encounters');
            $timeCol = $hasVisitedAt ? 'COALESCE(e.visited_at, e.started_at)' : 'e.started_at';

            $recentEncounters = $db->table('clinic_encounters e')
                ->join('people p', 'p.id = e.person_id AND p.tenant_id = e.tenant_id')
                ->join('users u', 'u.id = e.attending_user_id AND u.tenant_id = e.tenant_id', 'left')
                ->where('e.tenant_id', $tenantId)->where('e.deleted_at', null)
                ->select('e.id, e.chief_complaint, e.status, e.started_at, ' . $timeCol . ' AS visited_at, p.first_name, p.last_name, p.mis_id, p.kind, u.username as attending_name', false)
                ->orderBy($timeCol, 'DESC', false)->orderBy('e.id', 'DESC')->limit(6)->get()->getResultArray();

            if ($rangeKey === 'sy') {
                $syStartYear = (int) substr(PatientHistoryService::currentSchoolYear(), 0, 4);
                $manilaTz = SchedulingClock::timezone();
                $utcTz = new \DateTimeZone('UTC');
                $currentMonthStart = new \DateTimeImmutable(SchedulingClock::now()->format('Y-m-01 00:00:00'), $manilaTz);
                $syStartMonth = new \DateTimeImmutable($syStartYear . '-06-01 00:00:00', $manilaTz);
                $ranges = [];
                $days = [];
                for ($cursor = $syStartMonth; $cursor <= $currentMonthStart; $cursor = $cursor->modify('+1 month')) {
                    $next = $cursor->modify('+1 month');
                    $ranges[] = [
                        $cursor->setTimezone($utcTz)->format('Y-m-d H:i:s'),
                        $next->setTimezone($utcTz)->format('Y-m-d H:i:s'),
                    ];
                    $days[] = $cursor->format('M Y');
                }
            } else {
                $dayCount = $rangeKey === '30d' ? 30 : 7;
                $ranges = SchedulingClock::businessDayRanges($dayCount);
                $days = array_map(static fn (string $businessDay): string => date('M j', strtotime($businessDay)), array_keys($ranges));
            }
            $dailyEncounters = array_fill(0, count($ranges), 0);
            foreach ($db->table('clinic_encounters e')
                ->where('e.tenant_id', $tenantId)->where('e.deleted_at', null)
                ->where($timeCol . ' >= ' . $db->escape($ranges[array_key_first($ranges)][0]), null, false)
                ->where($timeCol . ' < ' . $db->escape($ranges[array_key_last($ranges)][1]), null, false)
                ->select($timeCol . ' AS effective_at', false)->get()->getResultArray() as $encounterRow) {
                $bucket = SchedulingClock::businessDayIndex((string) $encounterRow['effective_at'], $ranges);
                if ($bucket !== null) {
                    $dailyEncounters[$bucket]++;
                }
            }

            return [
                'queueWaiting'         => $queueWaiting,
                'queueCalled'          => $queueCalled,
                'queueCompleted'       => $queueCompleted,
                'encountersCount'      => $encountersCount,
                'appointmentsToday'    => $appointmentsToday,
                'medicinesCount'       => $medicinesCount,
                'inventoryInstalled'   => $inventoryInstalled,
                'inventoryAlerts'      => $inventoryAlerts,
                'activeIsolationCount' => $activeIsolationCount,
                'isolationAlerts'      => $isolationAlerts,
                'triageCounts'         => $triageCounts,
                'recentEncounters'     => $recentEncounters,
                'chartDays'            => $days,
                'chartSeries'          => $dailyEncounters,
            ];
        });

        $user = session()->get('user') ?? [];

        return view('clinic/dashboard', $metrics + [
            'title'       => 'Clinic Dashboard',
            'pageTitle'   => 'Clinic Workstation Dashboard',
            'user'        => $user,
            'rangeKey'    => $rangeKey,
            'breadcrumbs' => [
                ['label' => 'Clinic', 'url' => site_url('/clinic')],
                ['label' => 'Dashboard', 'url' => ''],
            ],
        ]);
    }

    /**
     * Encounters list view.
     */
    public function encounters()
    {
        $status = (string) $this->request->getGet('status');
        $date = (string) $this->request->getGet('date');
        $filters = [
            'q'           => (string) $this->request->getGet('q'),
            'from'        => (string) $this->request->getGet('from'),
            'to'          => (string) $this->request->getGet('to'),
            'complaint'   => (string) $this->request->getGet('complaint'),
            'disposition' => (string) $this->request->getGet('disposition'),
        ];

        try {
            $page = \App\Libraries\TablePage::forList($this->request->getGet());
        } catch (\InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setBody(esc($e->getMessage()));
        }
        $result = $this->encounterModel->getListPage($page, $status, $date, $filters);

        return view('clinic/encounters/index', [
            'title'            => 'Clinic Encounters',
            'pageTitle'        => 'Clinical Encounters',
            'encounters'       => $result['encounters'],
            'pagination'       => $result['pagination'],
            'status'           => $status,
            'date'             => $date,
            'filters'          => $filters,
            'complaintCatalog' => EncounterService::COMPLAINT_CATALOG,
            'dispositions'     => ClinicReportInsights::DISPOSITIONS,
            'breadcrumbs'      => [
                ['label' => 'Clinic', 'url' => site_url('/clinic/encounters')],
                ['label' => 'Encounters', 'url' => ''],
            ],
        ]);
    }

    /**
     * 4-step encounter consultation workspace.
     */
    public function encounter(int $id)
    {
        $inventory = new InventoryService();
        try {
            $actorId = $this->authorizeEncounter();
            $encounterPackage = $this->encounterService->getEncounterDetails($id);
        } catch (\Throwable $e) {
            $status = $e instanceof \InvalidArgumentException && $e->getCode() === 0 ? 404 : $this->inventoryErrorStatus($e);
            return $this->response->setStatusCode($status)->setBody(esc($status === 500 ? 'Encounter request failed.' : $e->getMessage()));
        }
        $canDispense = false;
        if ($encounterPackage['encounter']['status'] === 'in_progress') {
            try {
                $inventory->assertAccess($actorId, true);
                $canDispense = $inventory->isInstalled();
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 403) {
                    throw $e;
                }
            }
        }
        $dispenseKey = old('idempotency_key', null, false);
        if (!is_string($dispenseKey) || !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $dispenseKey)) {
            $dispenseKey = bin2hex(random_bytes(32));
        }
        $patientId = (int) $encounterPackage['patient']['id'];

        $healthModel = new HealthProfileModel();
        $studentModel = new StudentProfileModel();
        $empModel = new EmployeeProfileModel();

        $healthProfile = $healthModel->findByPersonId($patientId);
        $studentProfile = $encounterPackage['patient']['kind'] === 'student'
            ? $studentModel->select('program, year_level')->findByPersonId($patientId) : null;
        $employeeProfile = $encounterPackage['patient']['kind'] === 'employee'
            ? $empModel->select('department')->findByPersonId($patientId) : null;

        $availableMedicines = $canDispense ? $this->medicineModel->getDispenseCatalogList() : [];
        $sessionUser = session()->get('user') ?? [];
        $perms = is_array($sessionUser['permissions'] ?? null) ? $sessionUser['permissions'] : [];
        $isSuperadmin = ($sessionUser['role'] ?? '') === 'superadmin';
        $canSeal = $isSuperadmin || in_array('clinic.encounters.seal', $perms, true);
        $canAddendum = $isSuperadmin || in_array('clinic.encounters.addendum', $perms, true);

        return view('clinic/encounters/stepper', [
            'title'              => 'Visit: ' . $encounterPackage['patient']['last_name'],
            'pageTitle'          => 'Clinical Consultation Stepper',
            'encounter'          => $encounterPackage,
            'healthProfile'      => $healthProfile,
            'studentProfile'     => $studentProfile,
            'employeeProfile'    => $employeeProfile,
            'availableMedicines' => $availableMedicines,
            'complaintCatalog'   => EncounterService::COMPLAINT_CATALOG,
            'schoolYear'         => PatientHistoryService::currentSchoolYear(),
            'dispenseKey'        => $dispenseKey,
            'canDispense'        => $canDispense,
            'canSeal'            => $canSeal,
            'canAddendum'        => $canAddendum,
            'breadcrumbs'        => [
                ['label' => 'Clinic', 'url' => site_url('/clinic/encounters')],
                ['label' => 'Encounter #' . $id, 'url' => ''],
            ],
        ]);
    }

    /**
     * Step 1: Save vitals.
     */
    public function saveVitals(int $id)
    {
        $vitalsData = [
            'bp_systolic'  => $this->request->getPost('bp_systolic'),
            'bp_diastolic' => $this->request->getPost('bp_diastolic'),
            'pulse_bpm'    => $this->request->getPost('pulse_bpm'),
            'temp_c'       => $this->request->getPost('temp_c'),
            'spo2_pct'     => $this->request->getPost('spo2_pct'),
            'weight_kg'    => $this->request->getPost('weight_kg'),
            'height_cm'    => $this->request->getPost('height_cm'),
        ];

        try {
            $userId = $this->authorizeEncounter(true);
            $this->encounterService->recordVitals($id, $vitalsData, $userId);
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/encounters/' . $id);
        }

        return redirect()->to('/clinic/encounters/' . $id)->with('success', 'Vitals recorded successfully.');
    }

    /**
     * Step 2: Save assessment and clinical plan.
     */
    public function saveAssessment(int $id)
    {
        try {
            $this->authorizeEncounter(true);
            $chiefComplaint = $this->inventoryPostString('chief_complaint');
            $complaintCategory = $this->inventoryPostString('complaint_category');
            $complaintQualifier = $this->inventoryPostString('complaint_qualifier');
            $visitedAt = $this->inventoryPostString('visited_at');
            $assessmentNotes = $this->inventoryPostString('assessment_notes');
            $diagnosis = $this->inventoryPostString('diagnosis');
            $treatmentPlan = $this->inventoryPostString('treatment_plan');
            $servicesRendered = $this->inventoryPostString('services_rendered');

            $this->encounterService->recordAssessment(
                $id,
                $chiefComplaint,
                $assessmentNotes,
                $diagnosis,
                $treatmentPlan,
                $servicesRendered,
                $complaintCategory,
                $complaintQualifier,
                $visitedAt
            );
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/encounters/' . $id);
        }

        return redirect()->to('/clinic/encounters/' . $id)->with('success', 'Assessment and diagnosis updated.');
    }

    /**
     * Step 3: Dispense medicine via FEFO.
     */
    public function dispense(int $id)
    {
        $redirect = '/clinic/encounters/' . $id;
        try {
            $actorId = $this->inventoryActorId();
            $inventory = new InventoryService();
            $inventory->assertAccess($actorId, true);
            $inventory->assertInstalled();
            $medicineId = $this->request->getPost('medicine_id');
            if ((!is_int($medicineId) && !is_string($medicineId))
                || !preg_match('/\A[1-9][0-9]*\z/D', (string) $medicineId)
                || filter_var($medicineId, FILTER_VALIDATE_INT) === false) {
                throw new \InvalidArgumentException('A positive medicine_id is required.', 422);
            }
            $result = $inventory->dispense($id, (int) $medicineId, $this->request->getPost('quantity'),
                $this->inventoryPostString('instructions'), $actorId, $this->inventoryPostString('idempotency_key'));
            return $this->inventoryFormResponse(true, 'Medicine dispensed and inventory deducted via FEFO.', $redirect, 200, $result,
                $this->encounterRefresh($id));
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, $redirect);
        }
    }

    /**
     * Step 4: Complete encounter and seal visit.
     */
    public function complete(int $id)
    {
        try {
            $actorId = $this->authorizeEncounter(write: true, seal: true);
            $this->encounterService->completeEncounter($id, $this->inventoryPostString('disposition'),
                pendingDraft: $this->encounterDraftFields(), recordedByUserId: $actorId);
            return $this->inventoryFormResponse(true, 'Visit completed and sealed successfully.', '/clinic/encounters', 200, [],
                $this->navigationRefresh('/clinic/encounters', 'clinic.encounters'));
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/encounters/' . $id);
        }
    }

    /**
     * Append a signed addendum or delayed lab result to a sealed encounter.
     */
    public function saveAddendum(int $id)
    {
        try {
            $actorId = $this->authorizeClinicPermission('clinic.encounters.addendum');
            $details = $this->encounterService->getEncounterDetails($id);
            $addendumType = trim((string) ($this->request->getPost('addendum_type') ?? 'clinical_note'));
            $note = (string) ($this->request->getPost('note') ?? '');
            $testName = trim((string) ($this->request->getPost('test_name') ?? ''));

            if ($addendumType === 'lab_followup' && $testName !== '') {
                $this->historyService->recordLabResult((int) $details['patient']['id'], [
                    'encounter_id'   => $id,
                    'test_name'      => $testName,
                    'result_status'  => (string) ($this->request->getPost('result_status') ?? 'normal'),
                    'result_summary' => (string) ($this->request->getPost('result_summary') ?? ''),
                    'clinical_notes' => $note,
                    'school_year'    => PatientHistoryService::currentSchoolYear(),
                    'context_type'   => 'encounter_followup',
                ], $actorId);
            } else {
                $this->encounterService->appendAddendum($id, $actorId, $addendumType, $note);
            }

            return $this->inventoryFormResponse(true, 'Post-visit addendum recorded.', '/clinic/encounters/' . $id, 200);
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/encounters/' . $id);
        }
    }

    public function saveDraft(int $id)
    {
        try {
            $actorId = $this->authorizeEncounter(true);
            $this->encounterService->saveDraft($id, $this->encounterDraftFields(), $actorId);
            if ($this->request->isAJAX()) {
                return $this->response->setHeader('Cache-Control', 'no-store')->setJSON([
                    'success' => true, 'message' => 'Encounter draft saved.',
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
                ]);
            }
            return redirect()->to('/clinic/encounters/' . $id)->with('success', 'Encounter draft saved.');
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/encounters/' . $id);
        }
    }

    private function encounterDraftFields(): array
    {
        return array_intersect_key($this->request->getPost(), array_flip(EncounterService::DRAFT_FIELDS));
    }

    /**
     * Patient Directory view.
     */
    public function patients()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store')->setHeader('Vary', 'X-Requested-With');
        $fragment = $this->request->getGet('fragment') === '1';
        try {
            $directory = new \App\Services\Clinic\PatientDirectoryService();
            $canStartVisit = $directory->assertAccess($this->inventoryActorId());
            $page = \App\Libraries\TablePage::fromQuery($this->request->getGet());
            $directoryData = $directory->paginatePatients($page);
            $patientIds = array_column($directoryData['patients'], 'id');
            $openVisits = $patientIds === [] ? [] : $this->encounterModel->scoped()->select('id, person_id')
                ->whereIn('person_id', $patientIds)->where('status', 'in_progress')->orderBy('id')->findAll();
            $data = $directoryData + [
                'openVisits' => array_column($openVisits, 'id', 'person_id'),
                'title' => 'Patient Directory', 'pageTitle' => 'Patient Registry',
                'searchQuery' => $page->q, 'canStartVisit' => $canStartVisit,
                'baseUrl' => site_url('/clinic/patients'),
                'breadcrumbs' => [
                    ['label' => 'Clinic', 'url' => site_url('/clinic/encounters')],
                    ['label' => 'Patients', 'url' => ''],
                ],
            ];
            $body = $page->fragment
                ? '<div data-table-content>' . view('clinic/patients/_results', $data) . '</div>'
                : view('clinic/patients/index', $data);
            return $this->response->setBody($body);
        } catch (\Throwable $e) {
            $status = $e instanceof \InvalidArgumentException ? 422 : ($e->getCode() === 403 ? 403 : 503);
            if ($status === 503) {
                log_message('error', 'Patient directory unavailable: {exception}', ['exception' => $e]);
            }
            $message = $status === 503 ? 'Patient directory is temporarily unavailable.' : $e->getMessage();
            $this->response->setStatusCode($status);
            return $fragment || $this->request->isAJAX()
                ? $this->response->setJSON(['message' => $message])
                : $this->response->setBody(esc($message));
        }
    }

    /**
     * Patient Health Chart view.
     */
    public function patientChart(int $personId)
    {
        $this->authorizeEncounter();
        $chart = $this->historyService->getPatientChart($personId, includeAppointments: false);
        $sessionUser = session()->get('user') ?? [];
        $perms = is_array($sessionUser['permissions'] ?? null) ? $sessionUser['permissions'] : [];
        $isSuperadmin = ($sessionUser['role'] ?? '') === 'superadmin';
        $currentSy = PatientHistoryService::currentSchoolYear();
        $startYear = (int) substr($currentSy, 0, 4);
        $syOptions = [
            ($startYear - 2) . '-' . ($startYear - 1),
            ($startYear - 1) . '-' . $startYear,
            $currentSy,
            ($startYear + 1) . '-' . ($startYear + 2),
        ];

        return view('clinic/patients/chart', [
            'title'             => 'Patient Chart: ' . $chart['person']['last_name'],
            'pageTitle'         => 'Patient Medical Record',
            'chart'             => $chart,
            'canStartVisit'     => (new \App\Services\Clinic\PatientDirectoryService())->assertAccess($this->inventoryActorId()),
            'canWriteHealth'    => $isSuperadmin || in_array('clinic.patients.write', $perms, true),
            'canWriteClearance' => $isSuperadmin || in_array('clinic.clearances.write', $perms, true),
            'canWriteLabs'      => $isSuperadmin || in_array('clinic.encounters.addendum', $perms, true) || in_array('clinic.patients.write', $perms, true),
            'currentSchoolYear' => $currentSy,
            'schoolYearOptions' => $syOptions,
            'breadcrumbs'       => [
                ['label' => 'Clinic', 'url' => site_url('/clinic/encounters')],
                ['label' => 'Patients', 'url' => site_url('/clinic/patients')],
                ['label' => $chart['person']['last_name'] . ', ' . $chart['person']['first_name'], 'url' => ''],
            ],
        ]);
    }

    /**
     * Update health profile & narrative medical history.
     */
    public function updateHealth(int $personId)
    {
        try {
            $historyService = $this->historyService;
            $this->authorizeClinicPermission('clinic.patients.write');
            $historyService->updateHealthProfile($personId, [
                'blood_type'                     => $this->request->getPost('blood_type'),
                'allergies'                      => $this->request->getPost('allergies'),
                'chronic_conditions'             => $this->request->getPost('chronic_conditions'),
                'medical_history_notes'          => $this->request->getPost('medical_history_notes'),
                'surgical_history'               => $this->request->getPost('surgical_history'),
                'immunization_notes'             => $this->request->getPost('immunization_notes'),
                'family_history'                 => $this->request->getPost('family_history'),
                'emergency_contact_name'         => $this->request->getPost('emergency_contact_name'),
                'emergency_contact_phone'        => $this->request->getPost('emergency_contact_phone'),
                'emergency_contact_relationship' => $this->request->getPost('emergency_contact_relationship'),
            ]);
        } catch (\Throwable $error) {
            if (!$this->request->isAJAX() && $this->inventoryErrorStatus($error) === 500) {
                throw $error;
            }
            return $this->inventoryFailure($error, '/clinic/patients/' . $personId);
        }

        return $this->inventoryFormResponse(true, 'Health profile updated successfully.', '/clinic/patients/' . $personId, 200, [],
            $this->patientRefresh($personId));
    }

    public function saveLabResult(int $personId)
    {
        try {
            $actorId = $this->authorizeClinicPermission('clinic.encounters.addendum');
            $this->historyService->recordLabResult($personId, $this->request->getPost(), $actorId);
            return redirect()->to('/clinic/patients/' . $personId . '#tab-labs')->with('success', 'Laboratory result saved.');
        } catch (\Throwable $e) {
            return redirect()->to('/clinic/patients/' . $personId)->with('error', $e->getMessage());
        }
    }

    public function saveEnrollmentClearance(int $personId)
    {
        try {
            $actorId = $this->authorizeClinicPermission('clinic.clearances.write');
            $this->historyService->upsertEnrollmentClearance($personId, $this->request->getPost(), $actorId);
            return redirect()->to('/clinic/patients/' . $personId . '#tab-clearances')->with('success', 'Enrollment health clearance updated.');
        } catch (\Throwable $e) {
            return redirect()->to('/clinic/patients/' . $personId)->with('error', $e->getMessage());
        }
    }

    /**
     * Helper: Start an encounter directly from an active queue ticket.
     */
    public function startForTicket(int $queueEntryId)
    {
        if (!$this->request->is('post')) {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'POST')->setBody('Method not allowed.');
        }
        try {
            $userId = $this->authorizeEncounter(create: true);
            $queueModel = new QueueEntryModel();
            $ticket = $queueModel->scoped()->where('unit', 'clinic')->find($queueEntryId);
            if ($ticket === null) {
                return redirect()->to('/clinic/queue')->with('error', 'Queue ticket not found.');
            }

            // The service revalidates the ticket and resolves retries under patient/ticket locks.
            $encounter = $this->encounterService->startEncounter(
                (int) $ticket['person_id'],
                $userId,
                queueEntryId: $queueEntryId,
                appointmentId: !empty($ticket['appointment_id']) ? (int) $ticket['appointment_id'] : null
            );

            return $this->inventoryFormResponse(true, 'Encounter opened.', '/clinic/encounters/' . $encounter['encounter']['id'], 200, [],
                $this->navigationRefresh('/clinic/encounters/' . $encounter['encounter']['id'], 'clinic.encounters'));
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/queue');
        }
    }

    private function authorizeEncounter(bool $write = false, bool $create = false, bool $seal = false): int
    {
        $permission = $seal ? 'clinic.encounters.seal'
            : ($create ? 'clinic.encounters.create' : ($write ? 'clinic.encounters.write' : 'clinic.encounters.read'));
        return $this->authorizeClinicPermission($permission);
    }

    private function authorizeClinicPermission(string $permission): int
    {
        $actorId = $this->inventoryActorId();
        $db = Database::connect();
        $actor = $db->table('users')->where('id', $actorId)->where('tenant_id', CurrentTenant::id())
            ->where('deleted_at', null)->get()->getRowArray();
        if ($actor === null || $actor['status'] !== 'active') {
            throw new \RuntimeException('Active clinic account required.', 403);
        }
        if ($actor['role'] === 'superadmin') {
            return $actorId;
        }
        if (!in_array($actor['role'], ['clinic_admin', 'clinic_physician', 'clinic_staff', 'clinic_intern'], true)) {
            throw new \RuntimeException('Clinic workstation role not permitted.', 403);
        }
        $grant = $db->table('role_permissions rp')->join('permissions p', 'p.id=rp.permission_id')
            ->where('rp.role', $actor['role'])->where('p.code', $permission)->countAllResults();
        if (!$grant) {
            throw new \RuntimeException('Missing permission: ' . $permission, 403);
        }
        return $actorId;
    }

    private function inventoryActorId(): int
    {
        $user = session()->get('user');
        $id = is_array($user) ? ($user['id'] ?? null) : null;
        if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]*\z/D', (string) $id)
            || filter_var($id, FILTER_VALIDATE_INT) === false) {
            throw new \RuntimeException('An explicit inventory actor is required.', 403);
        }
        return (int) $id;
    }

    private function inventoryPostString(string $field): string
    {
        $value = $this->request->getPost($field);
        if ($value !== null && !is_string($value)) {
            throw new \InvalidArgumentException('Invalid ' . $field . '.', 422);
        }
        return $value ?? '';
    }

    private function inventoryErrorStatus(\Throwable $e): int
    {
        return in_array($e->getCode(), [403, 404, 409, 422, 503], true) ? $e->getCode()
            : ($e instanceof \InvalidArgumentException ? 422 : 500);
    }

    private function inventoryFailure(\Throwable $e, string $redirect)
    {
        $status = $this->inventoryErrorStatus($e);
        if ($status === 500) {
            log_message('error', 'Clinic inventory request failed: {exception}', ['exception' => $e]);
        }
        return $this->inventoryFormResponse(false, $status === 500 ? 'Clinic request failed.' : $e->getMessage(), $redirect, $status);
    }

    private function encounterRefresh(int $encounterId): ?array
    {
        $owner = 'clinic.encounter.' . $encounterId;
        if (!config(\Config\RefreshRegions::class)->enables($owner)) {
            return null;
        }

        return $this->refreshRegions($owner, ['dispensed-items', 'dispense-form'], site_url('/clinic/encounters/' . $encounterId));
    }

    private function patientRefresh(int $personId): ?array
    {
        $owner = 'clinic.patient.' . $personId;
        if (!config(\Config\RefreshRegions::class)->enables($owner)) {
            return null;
        }

        return $this->refreshRegions($owner, ['profile', 'history'], site_url('/clinic/patients/' . $personId));
    }

    private function navigationRefresh(string $url, string $rolloutOwner): ?array
    {
        if (!config(\Config\RefreshRegions::class)->enables($rolloutOwner)) {
            return null;
        }

        return $this->refreshNavigation(site_url($url));
    }

    private function inventoryFormResponse(
        bool $success,
        string $message,
        string $redirect,
        int $status,
        array $data = [],
        ?array $refresh = null
    )
    {
        if ($this->request->isAJAX()) {
            if ($success && $this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            $payload = [
                'success' => $success, 'message' => $message, 'redirect' => site_url($redirect),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ] + $data;
            if ($success && $refresh !== null) {
                $payload['refresh'] = $refresh;
            }
            return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')->setJSON($payload);
        }
        if (!$success && $status === 503) {
            return $this->response->setStatusCode(503)->setHeader('Cache-Control', 'no-store')
                ->setBody(view('inventory/unavailable', [
                    'title' => 'Clinic Inventory', 'pageTitle' => 'Medicine Inventory',
                    'message' => InventoryService::SETUP_PENDING_MESSAGE,
                ]));
        }
        return $success ? redirect()->to($redirect)->with('success', $message)
            : redirect()->to($redirect)->withInput()->with('error', $message);
    }

    /**
     * Helper: Start an encounter directly from the patient chart or directory.
     */
    public function startDirect(int $personId)
    {
        if (!$this->request->is('post')) {
            return $this->response->setStatusCode(405)->setHeader('Allow', 'POST')->setBody('Method not allowed.');
        }
        try {
            $userId = $this->authorizeEncounter(create: true);
            $encounter = $this->encounterService->startEncounter($personId, $userId);
            return $this->inventoryFormResponse(true, 'Encounter opened.', '/clinic/encounters/' . $encounter['encounter']['id'], 200, [],
                $this->navigationRefresh('/clinic/encounters/' . $encounter['encounter']['id'], 'clinic.encounters'));
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/patients/' . $personId);
        }
    }

    /**
     * Clinic Periodic & CHED Spot-Check Summary Report (weekly, monthly, semester, SY, custom <=366d).
     */
    public function summaryReport()
    {
        try {
            $this->authorizeClinicPermission('clinic.reports.export');
            $service = new ClinicSummaryReportService();
            $summary = $service->buildSummary($this->request->getGet());
            return view('clinic/reports/summary', [
                'title'     => 'Clinic Periodic & CHED Summary Report',
                'pageTitle' => 'Clinic Periodic & CHED Report',
                'summary'   => $summary,
                'presets'   => ClinicSummaryReportService::PRESETS,
                'breadcrumbs' => [
                    ['label' => 'Clinic', 'url' => site_url('/clinic')],
                    ['label' => 'Periodic & CHED Reports', 'url' => ''],
                ],
            ]);
        } catch (\Throwable $e) {
            $status = in_array($e->getCode(), [403, 422], true) || $e instanceof \InvalidArgumentException ? ($e->getCode() ?: 422) : 500;
            return $this->response->setStatusCode($status)->setBody(esc($status === 500 ? 'Unable to load Clinic summary report.' : $e->getMessage()));
        }
    }

    public function summaryReportPdf()
    {
        try {
            $this->authorizeClinicPermission('clinic.reports.export');
            $service = new ClinicSummaryReportService();
            $summary = $service->buildSummary($this->request->getGet());
            $tex = ClinicSummaryReportLatex::source($summary);
            $pdf = LatexReportCompiler::compile($tex);
            $slug = 'clinic-summary-' . preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $summary['period']['preset'])) . '.pdf';
            return ExportPdf::response($this->response, $pdf, $slug);
        } catch (\Throwable $e) {
            $status = in_array($e->getCode(), [403, 422], true) || $e instanceof \InvalidArgumentException ? ($e->getCode() ?: 422) : 503;
            return $this->response->setStatusCode($status)->setBody(esc($e->getMessage()));
        }
    }

    public function encounterCertificate(int $id)
    {
        try {
            $this->authorizeEncounter();
            $details = $this->encounterService->getEncounterDetails($id);
            $user = session()->get('user') ?? [];
            $tex = ClinicMedicalCertificateLatex::forEncounter($details, (string) ($user['name'] ?? $user['username'] ?? 'University Clinic'));
            $pdf = LatexReportCompiler::compile($tex);
            return ExportPdf::response($this->response, $pdf, 'clinic-certificate-enc-' . $id . '.pdf');
        } catch (\Throwable $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 503;
            return $this->response->setStatusCode($status)->setBody(esc($e->getMessage()));
        }
    }

    public function clearanceCertificate(int $clearanceId)
    {
        try {
            $this->authorizeClinicPermission('clinic.clearances.read');
            $db = Database::connect();
            $clearance = $db->table('clinic_enrollment_clearances c')
                ->select('c.*, u.username AS cleared_by_name')
                ->join('users u', 'u.id = c.cleared_by_user_id AND u.tenant_id = c.tenant_id', 'left')
                ->where('c.tenant_id', CurrentTenant::id())->where('c.id', $clearanceId)->where('c.deleted_at', null)
                ->get()->getRowArray();
            if ($clearance === null) {
                throw new \InvalidArgumentException('Clearance record not found.', 404);
            }
            $person = $this->personModel->scoped()->find((int) $clearance['person_id']);
            if ($person === null) {
                throw new \InvalidArgumentException('Patient not found.', 404);
            }
            $user = session()->get('user') ?? [];
            $tex = ClinicMedicalCertificateLatex::forClearance($person, $clearance, (string) ($user['name'] ?? $user['username'] ?? 'University Clinic'));
            $pdf = LatexReportCompiler::compile($tex);
            return ExportPdf::response($this->response, $pdf, 'clinic-clearance-' . $clearanceId . '.pdf');
        } catch (\Throwable $e) {
            $status = in_array($e->getCode(), [403, 404, 422], true) ? $e->getCode() : 503;
            return $this->response->setStatusCode($status)->setBody(esc($e->getMessage()));
        }
    }
}
