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
            $filters = array_intersect_key($this->request->getGet(), array_flip(['mine', 'q', 'status', 'priority', 'page', 'archived']));
            $service = new TeamTaskService();
            $contextCheck = $service->context($unit);
            $actor = $contextCheck['actor'] ?? [];
            $perms = is_array($actor['permissions'] ?? null) ? $actor['permissions'] : [];
            $scope = (new \App\Services\Archive\ArchiveAccessPolicy())->allowedScope($actor);
            $unitAllowed = in_array($actor['role'] ?? '', \App\Services\Archive\ArchiveAccessPolicy::ROLES_MAIN, true)
                && in_array($unit, $scope['units'] ?? [], true);
            $canViewArchived = $unitAllowed
                && (($actor['role'] ?? '') === 'superadmin' || in_array('archive.read', $perms, true) || in_array('archive.manage', $perms, true));
            $canArchive = $unitAllowed && (bool) ($scope['can_manage'] ?? false);
            if (($filters['archived'] ?? '') === '1' && !$canViewArchived) {
                $filters['archived'] = '';
            }
            $data = $service->listing($unit, $filters);
            $archivedMode = ($filters['archived'] ?? '') === '1';
            $mode = $archivedMode ? 'list' : ($this->request->getGet('view') ?? 'board');
            if (!in_array($mode, ['board', 'list'], true)) { throw new RuntimeException('Invalid task view.', 422); }
            $filters += ['mine' => '', 'q' => '', 'status' => '', 'priority' => '', 'archived' => ''];
            $data['memberNames'] = array_column($data['members'], 'name', 'id');
            $assigneeIds = array_values(array_filter(array_column($data['tasks'], 'assignee_user_id')));
            $data['memberPhotos'] = (new \App\Services\TeamAccess())->memberPhotos($unit, $data['actor'], $assigneeIds);
            foreach ($data['tasks'] as &$task) {
                $task['canEdit'] = $service->canEdit($task, $data['actor']);
            }
            unset($task);
            return view('teams/index', $data + $this->shared($unit) + [
                'filters'         => $filters,
                'mode'            => $mode,
                'archivedMode'    => $archivedMode,
                'canArchive'      => $canArchive,
                'canViewArchived' => $canViewArchived,
                'title'           => ucfirst($unit) . ' Teams',
            ]);
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
            try { $task = $service->detailPage($unit, $id, $this->request->getGet()); }
            catch (\InvalidArgumentException $e) { throw new RuntimeException($e->getMessage(), 422, $e); }
            $actor = $context['actor'] ?? [];
            $scope = (new \App\Services\Archive\ArchiveAccessPolicy())->allowedScope($actor);
            $canArchive = in_array($actor['role'] ?? '', \App\Services\Archive\ArchiveAccessPolicy::ROLES_MAIN, true)
                && (bool) ($scope['can_manage'] ?? false)
                && in_array($unit, $scope['units'] ?? [], true);

            $extraIds = array_merge(
                $task['assignee_user_id'] ? [(int) $task['assignee_user_id']] : [],
                array_column($task['comments'] ?? [], 'actor_user_id')
            );
            return view('teams/show', $context + $this->shared($unit) + [
                'task' => $task, 'title' => 'Team task #' . $id,
                'canArchive' => $canArchive,
                'memberNames' => array_column($context['members'], 'name', 'id'),
                'memberPhotos' => (new \App\Services\TeamAccess())->memberPhotos($unit, $context['actor'], $extraIds),
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

    /**
     * Reversibly archive a completed team task.
     */
    public function archive(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            (new TeamTaskService())->context($unit);
            $db = \Config\Database::connect();
            $row = $db->table('team_tasks')
                ->where('tenant_id', \App\Services\CurrentTenant::id())
                ->where('unit', $unit)
                ->where('id', $id)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Task not found.', 404);
            }
            $reason = trim((string) ($this->request->getPost('reason') ?? ''));
            if ($reason === '') {
                $reason = 'Archived from ' . $unit . ' team workspace';
            }
            (new \App\Services\Archive\ArchiveService($db))->archive('team_task', $id, $reason);

            return $this->success('Team task archived reversibly.', $unit);
        });
    }

    /**
     * Restore a previously archived team task.
     */
    public function restore(string $unit, int $id)
    {
        return $this->respond(function () use ($unit, $id) {
            (new TeamTaskService())->context($unit);
            $db = \Config\Database::connect();
            $row = $db->table('team_tasks')
                ->where('tenant_id', \App\Services\CurrentTenant::id())
                ->where('unit', $unit)
                ->where('id', $id)
                ->get()
                ->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Task not found.', 404);
            }
            $reason = trim((string) ($this->request->getPost('reason') ?? ''));
            if ($reason === '') {
                $reason = 'Restored from ' . $unit . ' team archive';
            }
            (new \App\Services\Archive\ArchiveService($db))->restore('team_task', $id, $reason);

            return $this->success('Team task restored from archive.', $unit, $id);
        });
    }

    /** Serve a unit-member avatar photo scoped to the active tenant and team unit. */
    public function memberPhoto(string $unit, int $userId)
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        try {
            $access = new \App\Services\TeamAccess();
            $context = $access->context($unit);
            $target = $access->memberPhotoTarget($unit, $context['actor'], $userId);
            if ($target === null) {
                return $this->response->setStatusCode(404)->setBody('Photo unavailable.');
            }
            $photos = new \App\Services\ProfilePhotoService();
            $bytes = $target['person_id'] > 0
                ? @file_get_contents($photos->path($target['tenant_id'], $target['person_id']))
                : false;
            $type = 'image/webp';
            if ($bytes === false) {
                $bytes = \App\Services\ProfileAvatar::svg($target['person']);
                $type = 'image/svg+xml';
            }
            return $this->response->setHeader('Content-Type', $type)
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cross-Origin-Resource-Policy', 'same-origin')
                ->setBody($bytes);
        } catch (Throwable $e) {
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 422], true) ? $e->getCode() : 403;
            return $this->response->setStatusCode($status)->setBody('Photo unavailable.');
        }
    }

    private function shared(string $unit): array
    {
        return ['unit' => $unit,
            'baseUrl' => site_url($unit . '/teams'), 'statuses' => self::STATUSES, 'priorities' => self::PRIORITIES];
    }

    private function success(string $message, string $unit, ?int $id = null)
    {
        $redirect = site_url($unit . '/teams' . ($id === null ? '' : '/' . $id));
        if ($this->request->getHeaderLine('X-Synapse-Feedback') !== 'toast') {
            session()->setFlashdata('success', $message);
        }
        if ($this->json()) {
            $payload = ['success' => true, 'message' => $message, 'redirect' => $redirect,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]];
            $owner = 'teams.tasks.' . $unit;
            if (config(\Config\RefreshRegions::class)->enables($owner)) {
                $payload['refresh'] = $this->request->getHeaderLine('X-Synapse-Refresh-Owner') === $owner
                    ? $this->refreshRegions($owner, ['navigation', 'results'])
                    : $this->refreshNavigation($redirect);
            }
            return $this->response->setJSON($payload);
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
