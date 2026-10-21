<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Scheduling\QueueService;
use App\Services\Scheduling\SchedulingClock;

final class QueueController extends BaseController
{
    private QueueService $queueService;

    public function __construct()
    {
        $this->queueService = new QueueService();
    }

    /**
     * Full page Queue Board view.
     */
    public function index(string $unit = 'clinic')
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';
        try {
            $queueDate = $this->queueDate();
            $summary = $this->queueService->getBoardSummary($unit, $queueDate);
        } catch (\InvalidArgumentException $e) {
            return $this->queueError($unit, $e);
        }

        return view('queue/index', [
            'title'     => ($unit === 'clinic') ? 'Clinic Queue' : 'Guidance Queue',
            'pageTitle' => ($unit === 'clinic') ? 'Today’s Clinic Queue' : 'Today’s Guidance Queue',
            'unit'      => $unit,
            'queueDate' => $queueDate,
            'summary'   => $summary,
            'breadcrumbs' => [
                ['label' => ($unit === 'clinic') ? 'Clinic' : 'Guidance', 'url' => site_url('/' . $unit . '/queue')],
                ['label' => 'Live Queue', 'url' => ''],
            ],
        ]);
    }

    /**
     * HTMX endpoint: returns the Kanban board partial for polling or action updates.
     */
    public function board(string $unit = 'clinic')
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';
        try {
            $queueDate = $this->queueDate();
            $summary = $this->queueService->getBoardSummary($unit, $queueDate);
        } catch (\InvalidArgumentException $e) {
            return $this->queueError($unit, $e);
        }

        return view('queue/board_partial', [
            'unit'      => $unit,
            'queueDate' => $queueDate,
            'summary'   => $summary,
        ]);
    }

    /**
     * Call the next waiting patient in line.
     */
    public function callNext(string $unit = 'clinic')
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';
        return $this->mutate($unit, fn () => $this->queueService->callNext($unit, $this->actorId(), $this->queueDate()));
    }

    /**
     * Call a specific ticket.
     */
    public function call(string $unit, int $id)
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';
        return $this->mutate($unit, fn () => $this->queueService->callTicket($id, $this->actorId(), $unit));
    }

    /**
     * Start consultation.
     */
    public function start(string $unit, int $id)
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';

        return $this->mutate($unit, fn () => $this->queueService->startConsultation($id, $unit));
    }

    /**
     * Complete consultation.
     */
    public function complete(string $unit, int $id)
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';

        return $this->mutate($unit, fn () => $this->queueService->completeConsultation($id, $unit));
    }

    /**
     * Skip patient (60-minute recall window).
     */
    public function skip(string $unit, int $id)
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';

        return $this->mutate($unit, fn () => $this->queueService->skipTicket($id, 60, $unit));
    }

    /**
     * Recall skipped patient to waiting line.
     */
    public function recall(string $unit, int $id)
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';

        return $this->mutate($unit, fn () => $this->queueService->recallTicket($id, $unit));
    }

    /**
     * Register a walk-in patient.
     */
    public function walkin(string $unit = 'clinic')
    {
        $unit = ($unit === 'guidance') ? 'guidance' : 'clinic';
        $personId = (int) $this->request->getPost('person_id');
        $priority = (string) ($this->request->getPost('priority') ?? 'routine');

        if ($personId <= 0) {
            return redirect()->back()->with('error', 'Please select a valid patient from the registry.');
        }

        try {
            (new \App\Services\Scheduling\AppointmentService())->assertSchedulingPermission($unit, (array) session()->get('user'), true);
            $entry = $this->queueService->enqueueWalkIn($unit, $personId, $priority, $this->queueDate());
            return redirect()->to('/' . $unit . '/queue')->with('success', "Ticket {$entry['ticket_code']} issued successfully.");
        } catch (\Exception $e) {
            if (!in_array((int) $e->getCode(), [403, 404, 409, 422], true)) {
                throw $e;
            }
            return $this->queueError($unit, $e);
        }
    }

    public function noShow(string $unit, int $id)
    {
        return $this->mutate($unit, fn () => $this->queueService->markNoShow($unit, $id, (array) session()->get('user')));
    }

    private function queueError(string $unit, \Exception $e)
    {
        $status = in_array((int) $e->getCode(), [403, 404, 409, 422], true) ? (int) $e->getCode() : 422;
        if ($this->request->hasHeader('HX-Request')) {
            return $this->response->setStatusCode($status)->setJSON(['error' => $e->getMessage()]);
        }
        return $this->response->setStatusCode($status)->setBody(view('queue/error', [
            'title' => 'Queue unavailable', 'pageTitle' => 'Queue unavailable', 'unit' => $unit, 'message' => $e->getMessage(),
        ]));
    }

    private function actorId(): int
    {
        $user = session()->get('user');
        $id = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
        if ($id < 1) {
            throw new \DomainException('An authenticated staff account is required.', 403);
        }
        return $id;
    }

    private function queueDate(): string
    {
        $date = $this->request->getPost('date');
        if ($date === null || $date === '') {
            $date = $this->request->getGet('date');
        }
        if ($date === null || $date === '') {
            return SchedulingClock::today();
        }
        $parsed = is_string($date) && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $date)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, SchedulingClock::timezone()) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Invalid queue date.', 422);
        }
        return $date;
    }

    private function mutate(string $unit, callable $operation)
    {
        try {
            $this->queueDate();
        } catch (\InvalidArgumentException $e) {
            return $this->queueError($unit, $e);
        }
        try {
            $operation();
            return $this->renderBoardOrRedirect($unit);
        } catch (\Exception $e) {
            $status = (int) $e->getCode();
            if (!in_array($status, [403, 404, 409, 422], true)) {
                throw $e;
            }
            if ($this->request->hasHeader('HX-Request')) {
                return $this->queueError($unit, $e);
            }
            return redirect()->to('/' . $unit . '/queue')->with('error', $e->getMessage());
        }
    }

    /**
     * Helper to return HTMX fragment or redirect.
     */
    private function renderBoardOrRedirect(string $unit)
    {
        if ($this->request->hasHeader('HX-Request')) {
            $queueDate = $this->queueDate();
            $summary = $this->queueService->getBoardSummary($unit, $queueDate);
            return view('queue/board_partial', [
                'unit'      => $unit,
                'queueDate' => $queueDate,
                'summary'   => $summary,
            ]);
        }

        return redirect()->to('/' . $unit . '/queue');
    }
}
