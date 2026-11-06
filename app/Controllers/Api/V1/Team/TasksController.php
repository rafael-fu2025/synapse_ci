<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Team;

use App\Services\TeamTaskService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use stdClass;

final class TasksController extends TeamApiController
{
    public function index(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $filters = $this->query(['mine', 'q', 'status', 'priority', 'page']);
            $service = new TeamTaskService($this->teamAccess());
            $result = $service->listing($unit, $filters);
            foreach ($result['tasks'] as &$task) {
                $task['canEdit'] = $service->canEdit($task, $result['actor']);
            }
            unset($task);
            return $this->teamOk([
                'data' => array_map(fn (array $task): array => TeamPresenter::taskSummary($task, $result['members']), $result['tasks']),
                'meta' => [
                    'counts' => $result['counts'], 'total' => (int) $result['total'],
                    'page' => (int) $result['page'], 'pages' => (int) $result['pages'],
                    'limit' => (int) $result['limit'], 'scope' => ($filters['mine'] ?? '') === '1' ? 'mine' : 'team',
                ],
            ]);
        });
    }

    public function show(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $this->query([]);
            $service = new TeamTaskService($this->teamAccess());
            $context = $service->context($unit);
            return $this->teamOk(['data' => TeamPresenter::taskDetail($service->detail($unit, $this->id($id)), $context['members'])]);
        });
    }

    public function create(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $body = $this->body();
            $this->only($body, ['title', 'description', 'status', 'priority', 'assignee_user_id', 'due_date', 'checklist', 'client_nonce']);
            $nonce = $this->nonce($body);
            unset($body['client_nonce']);
            $input = $this->taskInput($body, false);
            $service = new TeamTaskService($this->teamAccess());
            $task = $service->create($unit, $input, $nonce);
            $context = $service->context($unit);
            $task['canEdit'] = $service->canEdit($task, $context['actor']);
            return $this->teamOk(['data' => TeamPresenter::taskSummary($task, $context['members'])], 201);
        });
    }

    public function update(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $input = $this->taskInput($this->body(), true);
            $service = new TeamTaskService($this->teamAccess());
            $task = $service->update($unit, $this->id($id), $input);
            $context = $service->context($unit);
            $task['canEdit'] = $service->canEdit($task, $context['actor']);
            return $this->teamOk(['data' => TeamPresenter::taskSummary($task, $context['members'])]);
        });
    }

    public function status(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $input = $this->body();
            $this->only($input, ['status', 'version']);
            $service = new TeamTaskService($this->teamAccess());
            $task = $service->move($unit, $this->id($id), $input);
            $context = $service->context($unit);
            $task['canEdit'] = $service->canEdit($task, $context['actor']);
            return $this->teamOk(['data' => TeamPresenter::taskSummary($task, $context['members'])]);
        });
    }

    public function comment(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $input = $this->body();
            $this->only($input, ['body', 'client_nonce']);
            $nonce = $this->nonce($input);
            unset($input['client_nonce']);
            $service = new TeamTaskService($this->teamAccess());
            $service->comment($unit, $this->id($id), $input, $nonce);
            $context = $service->context($unit);
            return $this->teamOk(['data' => TeamPresenter::taskDetail($service->detail($unit, $this->id($id)), $context['members'])], 201);
        });
    }

    private function taskInput(array $input, bool $update): array
    {
        $allowed = ['title', 'description', 'status', 'priority', 'assignee_user_id', 'due_date', 'checklist'];
        if ($update) { $allowed[] = 'version'; }
        $this->only($input, $allowed);
        if (array_key_exists('checklist', $input)) {
            $items = $this->objectList($input['checklist'], 'checklist');
            foreach ($items as $item) {
                $this->only($item, ['text', 'done']);
                if (!is_bool($item['done'] ?? null)) {
                    throw new RuntimeException('Checklist done must be boolean.', 422);
                }
            }
            $input['checklist'] = $items;
        }
        foreach ($input as $value) {
            if ($value instanceof stdClass) {
                throw new RuntimeException('Nested objects are unsupported.', 422);
            }
        }
        return $input;
    }
}
