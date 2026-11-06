<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Team;

use App\Services\TeamChatService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use stdClass;

final class ChatController extends TeamApiController
{
    public function history(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $result = (new TeamChatService(null, null, $this->teamAccess()))
                ->history($unit, $this->query(['before', 'after', 'around']));
            $result['messages'] = array_map([TeamPresenter::class, 'chatMessage'], $result['messages']);
            return $this->teamOk(['data' => [
                'messages' => $result['messages'], 'has_more' => (bool) $result['has_more'],
                'next_before' => $result['next_before'] === null ? null : (int) $result['next_before'],
                'last_read_id' => (int) $result['last_read_id'], 'unread_count' => (int) $result['unread_count'],
                'latest_id' => (int) $result['latest_id'],
            ]]);
        });
    }

    public function tasks(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $result = (new TeamChatService(null, null, $this->teamAccess()))->tasks($unit, $this->query(['q']));
            return $this->teamOk(['data' => $result['tasks'], 'meta' => [
                'unit' => $unit, 'total' => count($result['tasks']), 'limit' => TeamChatService::PAGE_LIMIT,
            ]]);
        });
    }

    public function send(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $input = $this->body();
            $this->only($input, ['body', 'client_nonce', 'task_ids', 'mentions', 'reply_to_id']);
            $this->nonce($input);
            if (array_key_exists('task_ids', $input)) {
                $input['task_ids'] = $this->scalarList($input['task_ids'], 'task_ids');
            }
            if (array_key_exists('mentions', $input)) {
                $mentions = $this->objectList($input['mentions'], 'mentions');
                foreach ($mentions as $mention) {
                    $this->only($mention, ['user_id', 'start', 'length']);
                }
                $input['mentions'] = $mentions;
            }
            foreach ($input as $value) {
                if ($value instanceof stdClass) {
                    throw new RuntimeException('Nested objects are unsupported.', 422);
                }
            }
            $message = (new TeamChatService(null, null, $this->teamAccess()))->send($unit, $input);
            return $this->teamOk(['data' => TeamPresenter::chatMessage($message)], 201);
        });
    }

    public function read(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $input = $this->body();
            $this->only($input, ['last_read_id']);
            $summary = (new TeamChatService(null, null, $this->teamAccess()))->markRead($unit, $input);
            return $this->teamOk(['data' => [
                'last_read_id' => (int) $summary['last_read_id'],
                'unread_count' => (int) $summary['unread_count'],
                'latest_id' => (int) $summary['latest_id'],
            ]]);
        });
    }
}
