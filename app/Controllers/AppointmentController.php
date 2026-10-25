<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AppointmentModel;
use App\Services\AuthenticatedUser;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AvailabilityWorkspaceContext;
use App\Services\Scheduling\QueueService;
use App\Services\Scheduling\SchedulingClock;

final class AppointmentController extends BaseController
{
    private AppointmentService $appointmentService;
    private AppointmentModel $appointmentModel;
    private QueueService $queueService;

    public function __construct()
    {
        $this->appointmentService = new AppointmentService();
        $this->appointmentModel = new AppointmentModel();
        $this->queueService = new QueueService();
    }

    public function index(string $unit = 'clinic')
    {
        try {
            $this->appointmentService->assertStaff($unit, $this->actor());
            $date = (string) ($this->request->getGet('date') ?: SchedulingClock::today());
            $this->appointmentService->validateDate($date);
            return view('appointments/index', [
                'title' => ucfirst($unit) . ' Appointments', 'pageTitle' => ucfirst($unit) . ' Appointments',
                'unit' => $unit, 'formModalPage' => false, 'date' => $date,
                'appointments' => $this->appointmentModel->getForDay($unit, $date),
                'requests' => $this->appointmentModel->getPendingRequests($unit),
                'breadcrumbs' => [['label' => ucfirst($unit), 'url' => site_url('/' . $unit . '/appointments')],
                    ['label' => 'Appointments Calendar', 'url' => '']],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function create(string $unit = 'clinic')
    {
        return $this->requestForm($unit, false);
    }

    public function personalCreate(string $unit)
    {
        return $this->requestForm($unit, true);
    }

    private function requestForm(string $unit, bool $personal)
    {
        try {
            $this->appointmentService->assertUnit($unit);
            $person = null;
            if ($personal) {
                $actor = (new AuthenticatedUser())->current();
                if ($actor === null) throw new \DomainException('Please sign in to continue.', 401);
                $fresh = $this->appointmentService->assertPersonalPermission($actor, 'portal.appointments.create');
                $person = $this->appointmentService->person((int) $fresh['person_id']);
            } else {
                $this->appointmentService->assertStaff($unit, $this->actor());
            }
            $base = $personal ? '/portal/appointments/' . $unit : '/' . $unit . '/appointments';
            $cancel = $personal ? '/portal' : $base;
            return view('appointments/create', [
                'title' => 'Book New ' . ucfirst($unit) . ' Appointment',
                'pageTitle' => 'Book New ' . ucfirst($unit) . ' Appointment',
                'unit' => $unit, 'bookingMode' => $personal ? 'personal' : 'staff', 'person' => $person,
                'formAction' => site_url($base . '/store'), 'cancelUrl' => site_url($cancel), 'formModalPage' => true,
                'breadcrumbs' => [['label' => 'Appointments', 'url' => site_url($cancel)], ['label' => 'New Request', 'url' => '']],
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function store(string $unit = 'clinic')
    {
        return $this->storeRequest($unit, false);
    }

    public function personalStore(string $unit)
    {
        return $this->storeRequest($unit, true);
    }

    private function storeRequest(string $unit, bool $personal)
    {
        $redirect = $personal ? '/portal' : '/' . $unit . '/appointments';
        try {
            if (!$personal) {
                $this->appointmentService->assertStaff($unit, $this->actor());
            }
            $data = ['date' => $this->postString('date'), 'preferred_time' => $this->postString('preferred_time'),
                'type' => $this->postString('type'), 'reason' => $this->postString('reason')];
            if (!$personal) {
                $data['person_id'] = (int) $this->postString('person_id');
            }
            $this->appointmentService->requestAppointment($unit, $data, $this->actor(), $personal);
            return $this->respondToForm('Appointment requested. Department staff will confirm the time and assign a provider.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function confirm(string $unit, int $id)
    {
        try {
            $this->appointmentService->assertStaff($unit, $this->actor());
            $appointment = $this->appointmentService->appointment($unit, $id);
            if ($appointment['status'] !== 'requested') {
                throw new \RuntimeException('Appointment changed. Reload before confirming.', 409);
            }
            $base = '/' . $unit . '/appointments';
            return view('appointments/confirm', [
                'appointment' => $appointment, 'providers' => $this->appointmentService->providers($unit),
                'unit' => $unit, 'title' => 'Confirm ' . ucfirst($unit) . ' Appointment',
                'pageTitle' => 'Confirm ' . ucfirst($unit) . ' Appointment', 'formModalPage' => true,
                'cancelUrl' => site_url($base), 'formAction' => site_url($base . '/' . $id . '/confirm'),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function confirmStore(string $unit, int $id)
    {
        $redirect = '/' . $unit . '/appointments';
        try {
            $this->appointmentService->confirmAppointment($unit, $id, [
                'staff_assignment_id' => $this->postString('staff_assignment_id'),
                'starts_at' => $this->postString('starts_at'), 'ends_at' => $this->postString('ends_at'),
                'version' => $this->postString('version'),
            ], $this->actor());
            return $this->respondToForm('Appointment confirmed and provider assigned.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function checkIn(string $unit, int $id)
    {
        try {
            $entry = $this->queueService->enqueueAppointment($id, $unit, $this->actor());
            return $this->respondToForm('Patient checked in. Queue ticket ' . $entry['ticket_code'] . ' issued.', true, '/' . $unit . '/queue');
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, '/' . $unit . '/appointments', $this->status($e));
        }
    }

    public function noShow(string $unit, int $id)
    {
        $redirect = '/' . $unit . '/appointments';
        try {
            $version = filter_var($this->postString('version'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($version === false) {
                throw new \InvalidArgumentException('An appointment version is required.');
            }
            $this->appointmentService->markNoShow($unit, $id, $version, $this->actor());
            return $this->respondToForm('Appointment and linked unstarted tickets marked no-show. No clinical records were closed.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function cancel(string $unit, int $id)
    {
        return $this->cancelRequest($unit, $id, false);
    }

    public function personalCancel(string $unit, int $id)
    {
        return $this->cancelRequest($unit, $id, true);
    }

    private function cancelRequest(string $unit, int $id, bool $personal)
    {
        $redirect = $personal ? '/portal' : '/' . $unit . '/appointments';
        try {
            $reason = $this->postString('reason') ?: ($personal ? 'Cancelled by patient' : 'Cancelled by staff');
            $this->appointmentService->cancelAppointment($id, $reason, $unit, $this->actor(), $personal);
            return $this->respondToForm('Appointment cancelled.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function availability(string $unit)
    {
        $this->response->setHeader('Cache-Control', 'private, no-store');
        try {
            $availability = $this->appointmentService->getAvailability($unit, $this->actor());
            $providers = $this->appointmentService->providers($unit);
            $workspaceContext = AvailabilityWorkspaceContext::fromGet($this->request->getGet(), $providers);
            $base = '/' . $unit . '/appointments';
            return view('appointments/availability', [
                'unit' => $unit, 'providers' => $providers,
                'workspaceContext' => $workspaceContext,
                'returnQuery' => AvailabilityWorkspaceContext::query($workspaceContext),
                'availability' => $availability, 'formAction' => site_url($base . '/availability'),
                'exceptions' => $this->appointmentService->getAvailabilityExceptions($unit, $this->actor()),
                'departmentStaff' => $this->appointmentService->departmentStaff($unit, $this->actor()),
                'templateAction' => site_url($base . '/availability/template'),
                'enrollmentAction' => site_url($base . '/availability/enroll'),
                'cancelUrl' => site_url($base), 'title' => ucfirst($unit) . ' Provider Availability',
                'pageTitle' => ucfirst($unit) . ' Provider Availability',
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }
    }

    public function availabilityStore(string $unit)
    {
        $redirect = $this->availabilityReturnPath($unit);
        try {
            $this->appointmentService->saveAvailability($unit, [
                'staff_assignment_id' => $this->postString('staff_assignment_id'),
                'day_of_week' => $this->postString('day_of_week'),
                'start_time' => $this->postString('start_time'), 'end_time' => $this->postString('end_time'),
                'slot_duration_minutes' => $this->postString('slot_duration_minutes'),
                'is_active' => $this->postString('is_active'),
            ], $this->actor());
            $redirect = $this->availabilityReturnPath($unit, true, true);
            return $this->respondToForm('Provider availability saved.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function availabilityExceptionStore(string $unit)
    {
        return $this->changeAvailabilityException($unit, false);
    }

    public function availabilityExceptionRemove(string $unit)
    {
        return $this->changeAvailabilityException($unit, true);
    }

    private function changeAvailabilityException(string $unit, bool $remove)
    {
        $redirect = $this->availabilityReturnPath($unit);
        try {
            $data = [];
            foreach (['id', 'token', 'staff_assignment_id', 'exception_date', 'exception_type', 'custom_start_time', 'custom_end_time', 'reason'] as $key) {
                $data[$key] = $this->postString($key);
            }
            $data['id'] = $data['id'] === '' ? '0' : $data['id'];
            $this->appointmentService->saveAvailabilityException($unit, $data, $this->actor(), $remove);
            $redirect = $this->availabilityReturnPath($unit, true);
            return $this->respondToForm('Availability exception ' . ($remove ? 'removed' : 'saved') . '. Existing appointments were preserved; review any affected reservations separately.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function availabilityTemplateStore(string $unit)
    {
        $redirect = $this->availabilityReturnPath($unit);
        try {
            $result = $this->appointmentService->applyDepartmentTemplate($unit, [
                'staff_assignment_ids' => $this->request->getPost('staff_assignment_ids'),
                'days' => $this->request->getPost('days'),
                'start_time' => $this->postString('start_time'),
                'end_time' => $this->postString('end_time'),
                'slot_duration_minutes' => $this->postString('slot_duration_minutes'),
            ], $this->actor());
            return $this->respondToForm('Department availability applied. Created: ' . $result['created']
                . ' staff/day windows. Skipped: ' . $result['skipped'] . ' existing staff/day schedules (preserved).', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    public function availabilityEnroll(string $unit)
    {
        $redirect = $this->availabilityReturnPath($unit);
        try {
            $personIds = $this->request->getPost('person_ids');
            if (!is_array($personIds)) {
                throw new \InvalidArgumentException('Select employees to enroll for scheduling.');
            }
            $result = $this->appointmentService->enrollDepartmentStaff($unit, $personIds, $this->actor());
            return $this->respondToForm('Scheduling enrollment saved. Created: ' . $result['created']
                . '; skipped: ' . $result['skipped'] . ' (already enrolled). No login accounts or access permissions were changed.', true, $redirect);
        } catch (\Exception $e) {
            return $this->respondToForm($e->getMessage(), false, $redirect, $this->status($e));
        }
    }

    private function availabilityReturnPath(string $unit, bool $submittedProvider = false, bool $submittedDay = false): string
    {
        $context = AvailabilityWorkspaceContext::fromPost($this->request->getPost());
        if ($submittedProvider) {
            $context = AvailabilityWorkspaceContext::withSubmitted($context,
                $this->request->getPost('staff_assignment_id'),
                $submittedDay ? $this->request->getPost('day_of_week') : null);
        }
        return AvailabilityWorkspaceContext::path($unit, $context);
    }

    public function slots()
    {
        try {
            $actor = (new AuthenticatedUser())->current();
            if ($actor === null) throw new \DomainException('Please sign in to continue.', 401);
            $unit = (string) ($this->request->getGet('unit') ?? 'clinic');
            $this->appointmentService->assertSchedulingPermission($unit, $actor, write: false);
            $slots = $this->appointmentService->getAvailableSlots($unit,
                (int) $this->request->getGet('staff_assignment_id'), (string) ($this->request->getGet('date') ?? SchedulingClock::today()));
            return $this->response->setJSON(['slots' => $slots]);
        } catch (\Exception $e) {
            return $this->response->setStatusCode($this->status($e))->setJSON(['slots' => [], 'message' => $e->getMessage()]);
        }
    }

    private function actor(): array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) throw new \DomainException('Please sign in to continue.', 401);
        return $actor;
    }

    private function postString(string $key): string
    {
        $value = $this->request->getPost($key);
        if ($value !== null && !is_scalar($value)) {
            throw new \InvalidArgumentException('Invalid ' . $key . ' value.');
        }
        return (string) $value;
    }

    private function status(\Exception $e): int
    {
        return in_array($e->getCode(), [401, 403, 404, 409, 503], true) ? $e->getCode() : 422;
    }

    private function errorResponse(\Exception $e)
    {
        return $this->response->setStatusCode($this->status($e))->setBody(esc($e->getMessage()));
    }

    private function respondToForm(string $message, bool $success, string $redirect, int $status = 422)
    {
        if ($this->request->isAJAX()) {
            if ($success && $this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
                session()->setFlashdata('success', $message);
            }
            return $this->response->setStatusCode($success ? 200 : $status)->setJSON([
                'success' => $success, 'message' => $message, 'redirect' => site_url($redirect),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
        if ($success) {
            $response = redirect()->to($redirect);
            return $this->request->getHeaderLine('X-Synapse-Feedback') === 'toast'
                ? $response : $response->with('success', $message);
        }
        return redirect()->back()->withInput()->with('error', $message);
    }
}
