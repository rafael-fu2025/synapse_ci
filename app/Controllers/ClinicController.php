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
use App\Services\Clinic\PatientHistoryService;
use App\Services\CurrentTenant;
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

        $triageCounts = [
            'emergency' => $db->table('queue_entries')->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('priority', 'emergency')->countAllResults(),
            'urgent'    => $db->table('queue_entries')->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('priority', 'urgent')->countAllResults(),

            'routine'   => $db->table('queue_entries')->where('tenant_id', $tenantId)->where('unit', 'clinic')->where('priority', 'routine')->countAllResults(),
        ];

        $recentEncounters = $db->table('clinic_encounters e')
            ->join('people p', 'p.id = e.person_id AND p.tenant_id = e.tenant_id')
            ->join('users u', 'u.id = e.attending_user_id AND u.tenant_id = e.tenant_id', 'left')
            ->where('e.tenant_id', $tenantId)
            ->select('e.id, e.chief_complaint, e.status, e.started_at, p.first_name, p.last_name, p.mis_id, p.kind, u.username as attending_name')
            ->orderBy('e.id', 'DESC')->limit(6)->get()->getResultArray();

        $days = [];
        $dailyEncounters = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $days[] = date('M j', strtotime($d));
            $dailyEncounters[] = $db->table('clinic_encounters')
                ->where('tenant_id', $tenantId)->where('started_at >=', $d . ' 00:00:00')
                ->where('started_at <', date('Y-m-d', strtotime($d . ' +1 day')) . ' 00:00:00')->countAllResults();
        }

        $user = session()->get('user') ?? [];

        return view('clinic/dashboard', [
            'title'             => 'Clinic Dashboard',
            'pageTitle'         => 'Clinic Workstation Dashboard',
            'user'              => $user,
            'queueWaiting'      => $queueWaiting,
            'queueCalled'       => $queueCalled,
            'queueCompleted'    => $queueCompleted,
            'encountersCount'   => $encountersCount,
            'appointmentsToday' => $appointmentsToday,
            'medicinesCount'    => $medicinesCount,
            'inventoryInstalled' => $inventoryInstalled,
            'triageCounts'      => $triageCounts,
            'recentEncounters'  => $recentEncounters,
            'chartDays'         => $days,
            'chartSeries'       => $dailyEncounters,
            'breadcrumbs'       => [
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

        $encounters = $this->encounterModel->getListWithPatients($status, $date);

        return view('clinic/encounters/index', [
            'title'       => 'Clinic Encounters',
            'pageTitle'   => 'Clinical Encounters',
            'encounters'  => $encounters,
            'status'      => $status,
            'date'        => $date,
            'breadcrumbs' => [
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

        $availableMedicines = $canDispense ? $this->medicineModel->getInStockList() : [];

        return view('clinic/encounters/stepper', [
            'title'              => 'Visit: ' . $encounterPackage['patient']['last_name'],
            'pageTitle'          => 'Clinical Consultation Stepper',
            'encounter'          => $encounterPackage,
            'healthProfile'      => $healthProfile,
            'studentProfile'     => $studentProfile,
            'employeeProfile'    => $employeeProfile,
            'availableMedicines' => $availableMedicines,
            'dispenseKey'        => $dispenseKey,
            'canDispense'        => $canDispense,
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
                $servicesRendered
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
            return $this->inventoryFormResponse(true, 'Medicine dispensed and inventory deducted via FEFO.', $redirect, 200, $result);
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
            $this->authorizeEncounter(true);
            $this->encounterService->completeEncounter($id, $this->inventoryPostString('disposition'),
                $this->inventoryPostString('disposition_notes'), $this->inventoryPostString('follow_up_date'));
            return $this->inventoryFormResponse(true, 'Visit completed and sealed successfully.', '/clinic/encounters', 200);
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/encounters/' . $id);
        }
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

        return view('clinic/patients/chart', [
            'title'       => 'Patient Chart: ' . $chart['person']['last_name'],
            'pageTitle'   => 'Patient Medical Record',
            'chart'       => $chart,
            'canStartVisit' => (new \App\Services\Clinic\PatientDirectoryService())->assertAccess($this->inventoryActorId()),
            'breadcrumbs' => [
                ['label' => 'Clinic', 'url' => site_url('/clinic/encounters')],
                ['label' => 'Patients', 'url' => site_url('/clinic/patients')],
                ['label' => $chart['person']['last_name'] . ', ' . $chart['person']['first_name'], 'url' => ''],
            ],
        ]);
    }

    /**
     * Update health profile.
     */
    public function updateHealth(int $personId)
    {
        $this->historyService->updateHealthProfile($personId, [
            'blood_type'                     => $this->request->getPost('blood_type'),
            'allergies'                      => $this->request->getPost('allergies'),
            'chronic_conditions'             => $this->request->getPost('chronic_conditions'),
            'emergency_contact_name'         => $this->request->getPost('emergency_contact_name'),
            'emergency_contact_phone'        => $this->request->getPost('emergency_contact_phone'),
            'emergency_contact_relationship' => $this->request->getPost('emergency_contact_relationship'),
        ]);

        return redirect()->to('/clinic/patients/' . $personId)->with('success', 'Health profile updated successfully.');
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

            return redirect()->to('/clinic/encounters/' . $encounter['encounter']['id']);
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/queue');
        }
    }

    private function authorizeEncounter(bool $write = false, bool $create = false): int
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
        if (!in_array($actor['role'], ['clinic_admin', 'clinic_staff'], true)) {
            throw new \RuntimeException('Clinic encounter role not permitted.', 403);
        }
        $permission = $create ? 'clinic.encounters.create' : ($write ? 'clinic.encounters.write' : 'clinic.encounters.read');
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

    private function inventoryFormResponse(bool $success, string $message, string $redirect, int $status, array $data = [])
    {
        if ($this->request->isAJAX()) {
            if ($success) {
                session()->setFlashdata('success', $message);
            }
            return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')->setJSON([
                'success' => $success, 'message' => $message, 'redirect' => site_url($redirect),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ] + $data);
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
            return redirect()->to('/clinic/encounters/' . $encounter['encounter']['id']);
        } catch (\Throwable $e) {
            return $this->inventoryFailure($e, '/clinic/patients/' . $personId);
        }
    }
}
