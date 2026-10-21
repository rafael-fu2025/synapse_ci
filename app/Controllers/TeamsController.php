<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TeamTaskService;
use RuntimeException;
use Throwable;

final class TeamsController extends BaseController
{
    private const STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'blocked' => 'Blocked', 'done' => 'Done'];
    private const PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];

    public function index(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $filters = array_intersect_key($this->request->getGet(), array_flip(['mine', 'q', 'status', 'priority', 'page']));
            $data = (new TeamTaskService())->listing($unit, $filters);
            $mode = $this->request->getGet('view') ?? 'board';
            if (!in_array($mode, ['board', 'list'], true)) { throw new RuntimeException('Invalid task view.', 422); }
            $filters += ['mine' => '', 'q' => '', 'status' => '', 'priority' => ''];
            $data['memberNames'] = array_column($data['members'], 'name', 'id');
            foreach ($data['tasks'] as &$task) {
                $task['canEdit'] = (new TeamTaskService())->canEdit($task, $data['actor']);
            }
            unset($task);
            return view('teams/index', $data + $this->shared($unit) + ['filters' => $filters, 'mode' => $mode, 'title' => ucfirst($unit) . ' Teams']);
        });
    }

    public function create(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $context = (new TeamTaskService())->context($unit);
            return view('teams/form', $context + $this->shared($unit) + [
                'task' => null, 'title' => 'New team task', 'formAction' => site_url($unit . '/teams/store'),
                'cancelUrl' => site_url($unit . '/teams'), 'formModalPage' => true,
            ]);
        });
    }

    public function show(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            $service = new TeamTaskService();
            $context = $service->context($unit);
            $task = $service->detail($unit, $id);
            return view('teams/show', $context + $this->shared($unit) + [
                'task' => $task, 'title' => 'Team task #' . $id,
                'memberNames' => array_column($context['members'], 'name', 'id'),
                'cancelUrl' => site_url($unit . '/teams'), 'formModalPage' => true,
            ]);
        });
    }

    public function edit(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            $service = new TeamTaskService();
            $context = $service->context($unit);
            $task = $service->detail($unit, $id);
            if (!$task['canEdit']) { throw new RuntimeException('You cannot edit this task.', 403); }
            return view('teams/form', $context + $this->shared($unit) + [
                'task' => $task, 'title' => 'Edit team task #' . $id,
                'formAction' => site_url($unit . '/teams/' . $id . '/update'),
                'cancelUrl' => site_url($unit . '/teams'), 'formModalPage' => true,
            ]);
        });
    }

    public function store(string $unit)
    {
        return $this->respond(function () use ($unit) {
            (new TeamTaskService())->create($unit, $this->request->getPost());
            return $this->success('Team task created.', $unit);
        });
    }

    public function update(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            (new TeamTaskService())->update($unit, $id, $this->request->getPost());
            return $this->success('Team task updated.', $unit);
        });
    }

    public function move(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            (new TeamTaskService())->move($unit, $id, $this->request->getPost());
            return $this->success('Task status updated.', $unit);
        });
    }

    public function comment(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            (new TeamTaskService())->comment($unit, $id, $this->request->getPost());
            return $this->success('Comment added.', $unit, $id);
        });
    }

    private function shared(string $unit): array
    {
        return ['unit' => $unit,
            'baseUrl' => site_url($unit . '/teams'), 'statuses' => self::STATUSES, 'priorities' => self::PRIORITIES];
    }

    private function success(string $message, string $unit, ?int $id = null)
    {
        $redirect = site_url($unit . '/teams' . ($id === null ? '' : '/' . $id));
        session()->setFlashdata('success', $message);
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
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422], true)
                ? $e->getCode() : 503;
            // Do not log task/comment bodies or SQL parameter values.
            if ($status === 503) { log_message('error', 'Teams operation failed ({type}). Verify team task migrations and database availability.', ['type' => get_class($e)]); }
            $message = $status === 503 ? 'Teams is not available yet. Ask the administrator to apply the Teams database migration, or retry later.' : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->json()) {
                return $this->response->setJSON(['success' => false, 'message' => $message,
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
