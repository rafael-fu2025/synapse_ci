<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\TeamChatService;
use RuntimeException;
use Throwable;

final class TeamChatController extends BaseController
{
    public function index(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $service = new TeamChatService();
            $context = $service->context($unit);
            $before = $this->request->getGet('before');
            $target = $this->request->getGet('message');
            if ($before !== null && $target !== null) throw new RuntimeException('Use a message link or a history cursor, not both.', 422);
            $history = $service->history($unit, $target !== null ? ['around' => $target] : ($before === null ? [] : ['before' => $before]));
            $afterMode = false;
            // Enter at the unread position instead of marking an unseen backlog read.
            if ($target === null && $before === null && $history['unread_count'] > 0 && $history['messages'] !== []
                && $history['last_read_id'] < $history['messages'][0]['id']) {
                $history = $service->history($unit, ['after' => $history['last_read_id']]);
                $afterMode = true;
            }
            return view('teams/chat', $context + [
                'title' => ucfirst($unit) . ' Team Chat', 'unit' => $unit,
                'teamsUrl' => site_url($unit . '/teams'), 'baseUrl' => site_url($unit . '/teams/chat'),
                'history' => $history, 'afterMode' => $afterMode, 'browsingHistory' => $before !== null || $target !== null,
                                'targetMessageId' => $target !== null ? (int) $target : null,
            ]);
        });
    }

    public function history(string $unit)
    {
        return $this->respond(fn () => $this->payload((new TeamChatService())->history($unit, $this->request->getGet())));
    }

    public function tasks(string $unit)
    {
        return $this->respond(fn () => $this->payload((new TeamChatService())->tasks($unit, $this->request->getGet())));
    }

    public function send(string $unit)
    {
        return $this->respond(function () use ($unit) {
            $input = $this->input();
            if (($input['task_id'] ?? null) === '') { unset($input['task_id']); }
            return $this->payload(['message' => (new TeamChatService())->send($unit, $input)]);
        });
    }

    public function read(string $unit)
    {
        return $this->respond(fn () => $this->payload((new TeamChatService())->markRead($unit, $this->input())));
    }

    private function input(): array
    {
        $input = $this->request->getPost();
        unset($input[csrf_token()]);
        return $input;
    }

    private function payload(array $data)
    {
        return $this->response->setJSON(['success' => true, 'data' => $data,
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
    }

    private function respond(callable $operation)
    {
        $this->response->setHeader('Cache-Control', 'no-store, private');
        try { return $operation(); }
        catch (Throwable $e) {
            $status = $e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 409, 422], true) ? $e->getCode() : 503;
            if ($status === 503) { log_message('error', 'Team chat failed ({type}). Check chat migrations and database availability.', ['type' => get_class($e)]); }
            $message = $status === 503 ? 'Team chat is temporarily unavailable. Ask the administrator to verify chat migrations or retry later.' : $e->getMessage();
            $this->response->setStatusCode($status);
            if ($this->request->isAJAX() || str_contains(strtolower($this->request->getHeaderLine('Accept')), 'json')) {
                return $this->response->setJSON(['success' => false, 'message' => $message,
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            return $this->response->setContentType('text/plain')->setBody($message);
        }
    }
}
