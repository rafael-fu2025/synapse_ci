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

        return view('portal/index', [
            'title'        => 'My Health Portal',
            'pageTitle'    => match ($user['role']) {
                'student' => 'Student Health Portal',
                'employee' => 'Employee Health Portal',
                default => 'My Health Portal',
            },
            'chart'        => $chart,
            'appointments' => $this->historyService->getPortalAppointments($personId, $filters),
            'filters'      => $filters,
            'activeTickets' => $activeTickets,
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

    /** @return array{service: string, status: string, sort: string} */
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

        return ['service' => $service, 'status' => $status, 'sort' => $sort];
    }

    private function getString(string $key): string
    {
        $value = $this->request->getGet($key);
        return is_string($value) ? strtolower(trim($value)) : '';
    }
}
