<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Team;

use App\Services\TeamCalendarService;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use stdClass;

final class CalendarController extends TeamApiController
{
    public function index(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $filters = $this->query(['date', 'view', 'mine', 'status']);
            $result = (new TeamCalendarService($this->teamAccess()))->listing($unit, $filters);
            $items = [];
            foreach ($result['events'] as $event) {
                $event['canEdit'] = $result['canManage']
                    || (int) $event['created_by_user_id'] === (int) $result['actor']['id'];
                $item = TeamPresenter::calendarEvent($event);
                $item['sort_at'] = $item['starts_at'];
                $items[] = $item;
            }
            foreach ($result['tasks'] as $task) {
                $items[] = TeamPresenter::calendarTask($task);
            }
            usort($items, static fn (array $a, array $b): int => [$a['sort_at'], $a['id']] <=> [$b['sort_at'], $b['id']]);
            foreach ($items as &$item) { unset($item['sort_at']); }
            unset($item);
            return $this->teamOk(['data' => $items, 'meta' => [
                'date' => $result['date'], 'view' => $result['view'],
                'scope' => $result['mine'] === '1' ? 'mine' : 'team',
                'start_date' => $result['startDate'], 'end_date' => $result['endDate'],
                'total_events' => (int) $result['totalEvents'], 'total_tasks' => (int) $result['totalTasks'],
                'truncated' => (bool) $result['truncated'],
            ]]);
        });
    }

    public function show(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $this->query([]);
            return $this->teamOk(['data' => TeamPresenter::calendarEvent(
                (new TeamCalendarService($this->teamAccess()))->detail($unit, $this->id($id)), true
            )]);
        });
    }

    public function create(string $unit): ResponseInterface
    {
        return $this->teamRun(function () use ($unit): ResponseInterface {
            $body = $this->body();
            $this->only($body, ['title', 'description', 'location', 'starts_at', 'ends_at', 'participant_ids', 'client_nonce']);
            $nonce = $this->nonce($body);
            unset($body['client_nonce']);
            $input = $this->eventInput($body);
            $event = (new TeamCalendarService($this->teamAccess()))->create($unit, $input, $nonce);
            return $this->teamOk(['data' => TeamPresenter::calendarEvent($event, true)], 201);
        });
    }

    public function update(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $body = $this->body();
            $this->only($body, ['title', 'description', 'location', 'starts_at', 'ends_at', 'participant_ids', 'version']);
            $event = (new TeamCalendarService($this->teamAccess()))->update(
                $unit, $this->id($id), $this->eventInput($body)
            );
            return $this->teamOk(['data' => TeamPresenter::calendarEvent($event, true)]);
        });
    }

    public function cancel(string $unit, string $id): ResponseInterface
    {
        return $this->teamRun(function () use ($unit, $id): ResponseInterface {
            $body = $this->body();
            $this->only($body, ['version']);
            $event = (new TeamCalendarService($this->teamAccess()))->cancel($unit, $this->id($id), $body);
            return $this->teamOk(['data' => TeamPresenter::calendarEvent($event, true)]);
        });
    }

    private function eventInput(array $body): array
    {
        if (array_key_exists('participant_ids', $body)) {
            $body['participant_ids'] = $this->scalarList($body['participant_ids'], 'participant_ids');
        }
        foreach ($body as $value) {
            if ($value instanceof stdClass || is_array($value) && !array_is_list($value)) {
                throw new RuntimeException('Nested objects are unsupported.', 422);
            }
        }
        return $body;
    }
}
