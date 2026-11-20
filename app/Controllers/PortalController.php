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

        $tenantId = (int) ($user['tenant_id'] ?? \App\Services\CurrentTenant::id());
        $db = \Config\Database::connect();
        $rows = [];
        try {
            if ($db->tableExists('guidance_call_slips')) {
                $rows = $db->table('guidance_call_slips')
                    ->where('tenant_id', $tenantId)
                    ->where('person_id', $personId)
                    ->where('deleted_at', null)
                    ->orderBy('call_date', 'DESC')
                    ->orderBy('id', 'DESC')
                    ->limit(50)
                    ->get()
                    ->getResultArray();
            }
        } catch (\Throwable) {
            // Optional Guidance self-service schema guard.
        }

        $counts = ['all' => count($rows), 'pending' => 0, 'acknowledged' => 0, 'history' => 0];
        foreach ($rows as $row) {
            $status = (string) ($row['attendance_status'] ?? 'pending');
            if ($status === 'pending') {
                $counts['pending']++;
            } elseif ($status === 'acknowledged') {
                $counts['acknowledged']++;
            } else {
                $counts['history']++;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $rank = static fn (string $s): int => match ($s) {
                'pending' => 0,
                'acknowledged' => 1,
                default => 2,
            };
            $ra = $rank((string) ($a['attendance_status'] ?? ''));
            $rb = $rank((string) ($b['attendance_status'] ?? ''));
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            if ($ra < 2) {
                return strcmp((string) ($a['call_date'] ?? ''), (string) ($b['call_date'] ?? ''))
                    ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
            }
            return strcmp((string) ($b['call_date'] ?? ''), (string) ($a['call_date'] ?? ''))
                ?: ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        });

        $callSlips = array_values(array_filter($rows, static function (array $row) use ($filter): bool {
            $status = (string) ($row['attendance_status'] ?? 'pending');
            return match ($filter) {
                'pending' => $status === 'pending',
                'acknowledged' => $status === 'acknowledged',
                'history' => !in_array($status, ['pending', 'acknowledged'], true),
                default => true,
            };
        }));

        return view('portal/call_slips', [
            'title'         => 'Guidance Call Slips',
            'pageTitle'     => 'Guidance Call Slips',
            'callSlips'     => $callSlips,
            'filter'        => $filter,
            'counts'        => $counts,
            'baseUrl'       => site_url('/portal/call-slips'),
            'portalBackUrl' => site_url('/portal'),
            'breadcrumbs'   => [
                ['label' => 'My Health Portal', 'url' => site_url('/portal')],
                ['label' => 'Guidance Call Slips', 'url' => ''],
            ],
        ]);
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
