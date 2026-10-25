<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TeamCalendarService;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

final class TeamCalendarController extends BaseController
{
    public function index(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $filters = array_intersect_key($this->request->getGet(), array_flip(['date', 'view', 'mine', 'status']));
            $data = (new TeamCalendarService())->listing($unit, $filters);
            $days = [];
            $zone = new DateTimeZone('Asia/Manila');
            $end = new DateTimeImmutable($data['endDate'], $zone);
            for ($day = new DateTimeImmutable($data['startDate'], $zone); $day < $end; $day = $day->modify('+1 day')) {
                $days[$day->format('Y-m-d')] = ['date' => $day, 'items' => []];
            }
            foreach ($data['events'] as $event) {
                $start = $this->local($event['starts_at']);
                $finish = $this->local($event['ends_at']);
                foreach ($days as $key => &$day) {
                    if ($start < $day['date']->modify('+1 day') && $finish > $day['date']) {
                        $day['items'][] = ['type' => 'event', 'id' => $event['id'], 'title' => $event['title'],
                            'label' => $start->format('Y-m-d') === $key ? $start->format('g:i A') : 'Continues',
                            'status' => $event['status'], 'url' => site_url($unit . '/teams/calendar/events/' . $event['id'])];
                    }
                }
                unset($day);
            }
            foreach ($data['tasks'] as $task) {
                if (isset($days[$task['due_date']])) {
                    $days[$task['due_date']]['items'][] = ['type' => 'task', 'id' => $task['id'], 'title' => $task['title'],
                        'label' => 'Due', 'status' => $task['status'], 'url' => site_url($unit . '/teams/' . $task['id'])];
                }
            }
            return view('teams/calendar', $data + $this->shared($unit) + [
                'days' => $days, 'status' => $filters['status'] ?? 'scheduled',
                'today' => (new DateTimeImmutable('now', $zone))->format('Y-m-d'),
                'title' => ucfirst($unit) . ' Team Calendar',
            ]);
        });
    }

    public function create(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $context = (new TeamCalendarService())->context($unit);
            $date = $this->request->getGet('date') ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
            if (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
                || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
                throw new RuntimeException('Choose a valid event date.', 422);
            }
            return view('teams/event_form', $context + $this->shared($unit) + [
                'event' => null, 'startsValue' => $date . 'T09:00', 'endsValue' => $date . 'T10:00',
                'title' => 'New team event', 'formAction' => site_url($unit . '/teams/calendar/events/store'),
                'cancelUrl' => site_url($unit . '/teams/calendar'), 'formModalPage' => true,
            ]);
        });
    }

    public function show(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            $service = new TeamCalendarService();
            $context = $service->context($unit);
            $event = $service->detail($unit, $id);
            return view('teams/event_show', $context + $this->shared($unit) + [
                'event' => $event, 'title' => 'Team event #' . $id,
                'startLocal' => $this->local($event['starts_at']), 'endLocal' => $this->local($event['ends_at']),
                'memberNames' => array_column($context['members'], 'name', 'id'),
                'cancelUrl' => site_url($unit . '/teams/calendar'), 'formModalPage' => true,
            ]);
        });
    }

    public function edit(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            $service = new TeamCalendarService();
            $context = $service->context($unit);
            $event = $service->detail($unit, $id);
            if (!$event['canEdit']) { throw new RuntimeException('You cannot edit this event.', 403); }
            if ($event['status'] === 'cancelled') { throw new RuntimeException('Cancelled events cannot be edited.', 409); }
            return view('teams/event_form', $context + $this->shared($unit) + [
                'event' => $event, 'startsValue' => $this->local($event['starts_at'])->format('Y-m-d\TH:i'),
                'endsValue' => $this->local($event['ends_at'])->format('Y-m-d\TH:i'),
                'title' => 'Edit team event #' . $id, 'formAction' => site_url($unit . '/teams/calendar/events/' . $id . '/update'),
                'cancelUrl' => site_url($unit . '/teams/calendar'), 'formModalPage' => true,
            ]);
        });
    }

    public function store(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $event = (new TeamCalendarService())->create($unit, $this->eventInput());
            return $this->success('Team event created. This does not reserve a patient appointment.', $unit, $event);
        });
    }

    public function update(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            $event = (new TeamCalendarService())->update($unit, $id, $this->eventInput());
            return $this->success('Team event updated.', $unit, $event);
        });
    }

    public function cancel(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            $event = (new TeamCalendarService())->cancel($unit, $id, $this->request->getPost());
            return $this->success('Team event cancelled. Patient schedules are unchanged.', $unit, $event);
        });
    }

    private function eventInput(): array
    {
        // An unchecked HTML checkbox group submits no key; an empty list is intentional.
        return $this->request->getPost() + ['participant_ids' => []];
    }

    private function shared(string $unit): array
    {
        return ['unit' => $unit, 'baseUrl' => site_url($unit . '/teams/calendar'), 'teamsUrl' => site_url($unit . '/teams')];
    }

    private function local(string $utc): DateTimeImmutable
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'));
    }

    private function success(string $message, string $unit, array $event)
    {
        $redirect = site_url($unit . '/teams/calendar') . '?' . http_build_query(['date' => $this->local($event['starts_at'])->format('Y-m-d')]);
        if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
            session()->setFlashdata('success', $message);
        }
        if ($this->json()) {
            return $this->response->setJSON(['success' => true, 'message' => $message, 'redirect' => $redirect,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return redirect()->to($redirect)->setHeader('Cache-Control', 'no-store, private');
    }

    private function json(): bool
    {
        return $this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json');
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        try { return $operation(); }
        catch (Throwable $e) {
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422], true) ? $e->getCode() : 503;
            if ($status === 503) { log_message('error', 'Team calendar failed ({type}). Verify calendar migrations and database availability.', ['type' => get_class($e)]); }
            $message = $status === 503 ? 'The team calendar is temporarily unavailable. Ask the administrator to verify calendar migrations or retry later.' : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->json()) {
                return $this->response->setJSON(['success' => false, 'message' => $message, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
