<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\PortalQueueService;
use App\Services\AuthenticatedUser;
use App\Services\Clinic\PatientHistoryService;


final class PortalController extends BaseController
{
    private PatientHistoryService $historyService;

    public function __construct()
    {
        $this->historyService = new PatientHistoryService();
    }

    /**
     * Student & Employee Personal Health Portal home.
     */
    public function index()
    {
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }
        $required = match (trim($this->request->getUri()->getPath(), '/')) {
            'portal/records' => ['portal.records.read'],
            'portal/appointments' => ['portal.appointments.read'],
            default => ['portal.records.read', 'portal.appointments.read'],
        };
        foreach ($required as $permission) {
            if (!in_array($permission, $user['permissions'], true)) {
                return AuthenticatedUser::denied($this->request, true, 'Permission required: ' . $permission, '/');
            }
        }
        $personId = (int) ($user['person_id'] ?? 0);
        if ($personId <= 0) {
            return AuthenticatedUser::denied($this->request, true, 'An identified person profile is required.', '/');
        }

        $chart = [];
        $activeTickets = [];

        if ($personId > 0) {
            $chart = $this->historyService->getPatientChart($personId, includeAppointments: false);

            $activeTickets = (new PortalQueueService())->snapshot()['tickets'];
        }

        $filters = $this->appointmentFilters();
        $appointmentPage = $this->historyService->getPortalAppointmentsPage($personId, $filters);
        $filters['page'] = $appointmentPage['pagination']['page'];
        $filters['per_page'] = $appointmentPage['pagination']['per_page'];

        $pendingCallSlips = [];
        $activeMiAssignments = [];
        $tenantId = (int) ($user['tenant_id'] ?? \App\Services\CurrentTenant::id());
        $db = \Config\Database::connect();
        try {
            if ($db->tableExists('guidance_call_slips')) {
                $pendingCallSlips = $db->table('guidance_call_slips')
                    ->where('tenant_id', $tenantId)
                    ->where('person_id', $personId)
                    ->where('deleted_at', null)
                    ->whereIn('attendance_status', ['pending', 'acknowledged'])
                    ->orderBy('call_date', 'ASC')
                    ->orderBy('id', 'DESC')
                    ->limit(10)
                    ->get()
                    ->getResultArray();
            }
            if ($db->tableExists('guidance_mi_assignments')
                && (($user['role'] ?? '') === 'superadmin'
                    || in_array('guidance.assessments.take', $user['permissions'] ?? [], true))) {
                $activeMiAssignments = $db->table('guidance_mi_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('person_id', $personId)
                    ->where('deleted_at', null)
                    ->whereIn('status', ['assigned', 'in_progress'])
                    ->orderBy('id', 'DESC')
                    ->limit(5)
                    ->get()
                    ->getResultArray();
            }
        } catch (\Throwable) {
            // Optional Guidance self-service tables may be absent in minimal schema runs.
        }

        $pinnedAdvisories = [];
        $unreadAnnouncementsCount = 0;
        try {
            $announcementService = new \App\Services\AnnouncementService();
            $pinnedAdvisories = $announcementService->pinnedAdvisories(3);
            $announcementSummary = $announcementService->portalListing(1, ['per_page' => 1]);
            $unreadAnnouncementsCount = (int) ($announcementSummary['counts']['unread'] ?? 0);
        } catch (\Throwable) {
            // Optional announcements schema guard.
        }

        $availableSurveysCount = 0;
        try {
            $surveySummary = (new \App\Services\Guidance\SurveyService())->available(['per_page' => 1]);
            $availableSurveysCount = (int) ($surveySummary['counts']['available'] ?? 0);
        } catch (\Throwable) {
            // Optional survey schema guard.
        }

        return view('portal/index', [
            'title'        => 'My Health Portal',
            'pageTitle'    => match ($user['role']) {
                'student' => 'Student Health Portal',
                'employee' => 'Employee Health Portal',
                default => 'My Health Portal',
            },
            'chart'        => $chart,
            'appointments' => $appointmentPage['appointments'],
            'pagination'   => $appointmentPage['pagination'],
            'filters'      => $filters,
            'activeTickets' => $activeTickets,
            'pendingCallSlips' => $pendingCallSlips,
            'activeMiAssignments' => $activeMiAssignments,
            'pinnedAdvisories' => $pinnedAdvisories,
            'unreadAnnouncementsCount' => $unreadAnnouncementsCount,
            'availableSurveysCount' => $availableSurveysCount,
            'breadcrumbs'  => [
                ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                ['label' => 'Personal Workspace', 'url' => ''],
            ],
        ]);
    }

    /**
     * Read-only appointment detail for the signed-in person.
     */
    public function appointmentDetail(int $id)
    {
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }
        if (!in_array('portal.appointments.read', $user['permissions'], true)) {
            return AuthenticatedUser::denied($this->request, true, 'Permission required: portal.appointments.read', '/');
        }

        $personId = (int) ($user['person_id'] ?? 0);
        if ($personId <= 0) {
            return AuthenticatedUser::denied($this->request, true, 'An identified person profile is required.', '/');
        }

        $appointment = $this->historyService->getPortalAppointment($personId, $id);
        if ($appointment === null) {
            return $this->response->setStatusCode(404)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setBody('Appointment not found.');
        }

        return view('portal/appointment_detail', [
            'title'     => 'Appointment details',
            'pageTitle' => 'Appointment details',
            'appointment' => $appointment,
            'cancelUrl' => site_url('/portal'),
        ]);
    }

    /**
     * Dedicated portal Call Slips screen showing pending acknowledgments, active slips, and history.
     */
    public function callSlips()
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($this->request, false, 'Please sign in to continue.', '/auth/login');
        }
        $personId = (int) ($user['person_id'] ?? 0);
        if ($personId <= 0) {
            return AuthenticatedUser::denied($this->request, true, 'An identified person profile is required.', '/');
        }

        $filter = $this->getString('status');
        if (!in_array($filter, ['all', 'pending', 'acknowledged', 'history'], true)) {
            $filter = 'all';
        }

        try {
            $page = \App\Libraries\TablePage::forList($this->request->getGet(), 'page', 'per_page', 20);
        } catch (\InvalidArgumentException $error) {
            return $this->response->setStatusCode(422)->setBody(esc($error->getMessage()));
        }
        $tenantId = (int) ($user['tenant_id'] ?? \App\Services\CurrentTenant::id());
        $db = \Config\Database::connect();
        $counts = ['all' => 0, 'pending' => 0, 'acknowledged' => 0, 'history' => 0];
        $pagedCallSlips = [];
        $pagination = $page->metadata(0, 0);
        try {
            if ($db->tableExists('guidance_call_slips')) {
                $result = $this->portalCallSlipPage($db, $tenantId, $personId, $filter, $page);
                $counts = $result['counts'];
                $pagedCallSlips = $result['callSlips'];
                $pagination = $result['pagination'];
            }
        } catch (\Throwable) {
            // Optional Guidance self-service schema guard.
        }
        $perPage = $pagination['per_page'];

        return view('portal/call_slips', [
            'title'         => 'Guidance Call Slips',
            'pageTitle'     => 'Guidance Call Slips',
            'callSlips'     => $pagedCallSlips,
            'filter'        => $filter,
            'filters'       => ['status' => $filter, 'per_page' => $perPage],
            'pagination'    => $pagination,
            'counts'        => $counts,
            'baseUrl'       => site_url('/portal/call-slips'),
            'portalBackUrl' => site_url('/portal'),
            'breadcrumbs'   => [
                ['label' => 'My Health Portal', 'url' => site_url('/portal')],
                ['label' => 'Guidance Call Slips', 'url' => ''],
            ],
        ]);
    }

    /** A private, already-authorized read seam; count and filter the complete person scope. */
    private function portalCallSlipPage(\CodeIgniter\Database\BaseConnection $db, int $tenantId, int $personId, string $filter, \App\Libraries\TablePage $page): array
    {
        $builder = $db->table('guidance_call_slips')
            ->where('tenant_id', $tenantId)->where('person_id', $personId)->where('deleted_at', null);
        $status = 'COALESCE(' . $db->protectIdentifiers('attendance_status') . ", 'pending')";
        $active = $status . " IN ('pending', 'acknowledged')";
        $conditions = [
            'pending' => $status . " = 'pending'",
            'acknowledged' => $status . " = 'acknowledged'",
            'history' => 'NOT (' . $active . ')',
        ];
        $counts = ['all' => (clone $builder)->countAllResults()];
        foreach ($conditions as $state => $condition) {
            $counts[$state] = (clone $builder)->where($condition, null, false)->countAllResults();
        }
        $pagination = $page->metadata($counts['all'], $counts[$filter]);
        if ($filter !== 'all') {
            $builder->where($conditions[$filter], null, false);
        }
        $callDate = $db->protectIdentifiers('call_date');
        $callSlips = $builder
            ->orderBy('CASE WHEN ' . $status . " = 'pending' THEN 0 WHEN " . $status . " = 'acknowledged' THEN 1 ELSE 2 END", 'ASC', false)
            ->orderBy('CASE WHEN ' . $active . ' THEN ' . $callDate . ' END', 'ASC', false)
            ->orderBy('CASE WHEN NOT (' . $active . ') THEN ' . $callDate . ' END', 'DESC', false)
            ->orderBy('id', 'DESC')
            ->get($page->perPage, ($pagination['page'] - 1) * $page->perPage)->getResultArray();
        return compact('callSlips', 'pagination', 'counts');
    }

    /** @return array{service: string, status: string, sort: string, page: int, per_page: int} */
    private function appointmentFilters(): array
    {
        $service = $this->getString('service');
        if (!in_array($service, ['all', 'clinic', 'guidance'], true)) {
            $service = 'all';
        }

        $status = $this->getString('status');
        if (!in_array($status, ['all', 'active', 'requested', 'confirmed', 'checked_in', 'completed', 'cancelled', 'no_show'], true)) {
            $status = 'all';
        }

        $sort = $this->getString('sort');
        if (!in_array($sort, ['upcoming', 'date_asc', 'date_desc'], true)) {
            $sort = 'upcoming';
        }

        $perPage = filter_var($this->getString('per_page'), FILTER_VALIDATE_INT);
        $perPage = in_array($perPage, [20, 25, 50, 100], true) ? $perPage : 25;
        $page = max(1, (int) filter_var($this->getString('page'), FILTER_VALIDATE_INT));

        return ['service' => $service, 'status' => $status, 'sort' => $sort, 'page' => $page, 'per_page' => $perPage];
    }

    private function getString(string $key): string
    {
        $value = $this->request->getGet($key);
        return is_string($value) ? strtolower(trim($value)) : '';
    }
}
