<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\QueueEntryModel;
use App\Services\AuthenticatedUser;
use App\Services\Clinic\PatientHistoryService;
use App\Services\Scheduling\SchedulingClock;

final class PortalController extends BaseController
{
    private PatientHistoryService $historyService;
    private QueueEntryModel $queueModel;

    public function __construct()
    {
        $this->historyService = new PatientHistoryService();
        $this->queueModel = new QueueEntryModel();
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
        $activeTicket = null;

        if ($personId > 0) {
            $chart = $this->historyService->getPatientChart($personId);

            // Check if patient has an active waiting room ticket today
            $activeTicket = $this->queueModel->scoped()
                ->where('person_id', $personId)
                ->where('queue_date', SchedulingClock::today())
                ->whereIn('status', ['waiting', 'called', 'in_consultation'])
                ->first();
        }

        return view('portal/index', [
            'title'        => 'My Health Portal',
            'pageTitle'    => 'Student & Employee Health Portal',
            'chart'        => $chart,
            'activeTicket' => $activeTicket,
            'breadcrumbs'  => [
                ['label' => 'Campus Portal', 'url' => site_url('/portal')],
                ['label' => 'Personal Workspace', 'url' => ''],
            ],
        ]);
    }
}
