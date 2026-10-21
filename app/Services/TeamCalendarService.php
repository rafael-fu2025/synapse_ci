<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * One-time department events and live task due dates, never appointments/availability.
 * All operations refresh identity/membership through TeamTaskService::context().
 * Text is plain text; callers must escape it. Stored event timestamps are raw UTC.
 *
 * listing filters: date (Manila YYYY-MM-DD; default today), view (month/week/agenda;
 * default month), mine (''|'1'), status (scheduled default, cancelled, or all).
 * Returns exactly actor,members,canManage,events,tasks,totalEvents,totalTasks,
 * truncated,date,view,mine,startDate,endDate,prevDate,nextDate. Date ranges are
 * Manila dates, end exclusive. Both lists have independent limits of 250.
 * Tasks are live stored task fields with integer IDs/version and decoded checklist.
 * Events include current eligible participants [{id,name,role}]; detail and write
 * results additionally include participant_ids (the same eligible IDs) and canEdit.
 *
 * Create requires title/starts_at/ends_at; defaults description/location='',
 * participant_ids=[]. Update replaces all six editable fields, plus version.
 * Picker times must be actual YYYY-MM-DDTHH:mm in Asia/Manila, duration (0,31d].
 * Cancel reads only version; current-version repeats are no-ops, stale versions
 * always fail 409. Cancellation is terminal; updates to cancelled events fail 409.
 * Unknown input keys are ignored. Participant lists are unique positive IDs,
 * at most 100, active in the same tenant/department; create/update revalidate them.
 * Cancellation never changes participants or requires their continued eligibility.
 *
 * RuntimeException codes: 401 unauthenticated, 403 wrong department/not editor,
 * 404 scoped event missing, 409 stale/terminal/nested transaction, 422 invalid
 * input/ineligible participant, 503 unavailable schema or database failure.
 * No sensitive event text is written to logs, activity, or notifications.
 */
final class TeamCalendarService
{
    public const LIST_LIMIT = 250;
    public const PARTICIPANT_LIMIT = 100;
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function context(string $unit): array
    {
        return $this->guard(fn (): array => (new TeamTaskService())->context($unit));
    }

    public function listing(string $unit, array $filters = []): array
    {
        return $this->guard(function () use ($unit, $filters): array {
            $context = $this->context($unit);
            $this->ready(true);
            $range = $this->range($filters);
            $actor = $context['actor'];
            $start = $this->date($range['startDate'])->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $end = $this->date($range['endDate'])->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $events = $this->scope('team_events', $unit, $actor)->where('starts_at <', $end)->where('ends_at >', $start);
            $status = array_key_exists('status', $filters) ? $filters['status'] : 'scheduled';
            if (!in_array($status, ['scheduled', 'cancelled', 'all'], true)) {
                throw new RuntimeException('Invalid status filter.', 422);
            }
            if ($status !== 'all') { $events->where('status', $status); }
            if ($range['mine'] === '1') {
                $attending = $this->scope('team_event_participants', $unit, $actor)->select('event_id')->where('user_id', $actor['id']);
                $events->groupStart()->where('created_by_user_id', $actor['id'])->orWhereIn('id', $attending)->groupEnd();
            }
            $tasks = $this->scope('team_tasks', $unit, $actor)
                ->where('due_date >=', $range['startDate'])->where('due_date <', $range['endDate']);
            if ($range['mine'] === '1') { $tasks->where('assignee_user_id', $actor['id']); }
            $totalEvents = (clone $events)->countAllResults();
            $totalTasks = (clone $tasks)->countAllResults();
            $eventRows = $events->orderBy('starts_at', 'ASC')->orderBy('id', 'ASC')->limit(self::LIST_LIMIT)->get()->getResultArray();
            $eventRows = $this->decorate($eventRows, $unit, $context);
            $taskRows = $tasks->orderBy('due_date', 'ASC')->orderBy('id', 'ASC')->limit(self::LIST_LIMIT)->get()->getResultArray();
            foreach ($taskRows as &$task) {
                foreach (['id', 'tenant_id', 'created_by_user_id', 'version'] as $key) { $task[$key] = (int) $task[$key]; }
                $task['assignee_user_id'] = $task['assignee_user_id'] === null ? null : (int) $task['assignee_user_id'];
                $task['checklist'] = json_decode($task['checklist'], true, 512, JSON_THROW_ON_ERROR);
            }
            unset($task);
            return $context + ['events' => $eventRows, 'tasks' => $taskRows,
                'totalEvents' => $totalEvents, 'totalTasks' => $totalTasks,
                'truncated' => $totalEvents > self::LIST_LIMIT || $totalTasks > self::LIST_LIMIT] + $range;
        });
    }

    public function detail(string $unit, int $id): array
    {
        return $this->guard(function () use ($unit, $id): array {
            $context = $this->context($unit);
            $this->ready();
            return $this->event($unit, $id, $context);
        });
    }

    public function create(string $unit, array $input): array
    {
        return $this->transaction(function () use ($unit, $input): array {
            $context = $this->context($unit);
            $this->ready();
            $data = $this->editable($input, false);
            $ids = $data['participant_ids'];
            unset($data['participant_ids']);
            $this->validateParticipants($ids, $context);
            $now = gmdate('Y-m-d H:i:s');
            $this->must($this->db->table('team_events')->insert($data + [
                'tenant_id' => $context['actor']['tenant_id'], 'unit' => $unit,
                'created_by_user_id' => $context['actor']['id'], 'status' => 'scheduled',
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $id = (int) $this->db->insertID();
            $this->replaceParticipants($unit, $id, $ids, $context['actor']);
            return $this->event($unit, $id, $context);
        });
    }

    public function update(string $unit, int $id, array $input): array
    {
        return $this->change($unit, $id, $input, false);
    }

    public function cancel(string $unit, int $id, array $input): array
    {
        return $this->change($unit, $id, $input, true);
    }

    private function change(string $unit, int $id, array $input, bool $cancel): array
    {
        return $this->transaction(function () use ($unit, $id, $input, $cancel): array {
            $context = $this->context($unit);
            $this->ready();
            $event = $this->event($unit, $id, $context);
            if (!$event['canEdit']) { throw new RuntimeException('You cannot edit this event.', 403); }
            $version = $this->positive($input['version'] ?? null, 'version');
            if ($version !== $event['version']) { throw new RuntimeException('Event changed. Reload before saving.', 409); }
            if (!$cancel && $event['status'] === 'cancelled') { throw new RuntimeException('Cancelled events cannot be edited.', 409); }
            $data = $cancel ? ['status' => 'cancelled'] : $this->editable($input, true);
            $ids = $cancel ? [] : $data['participant_ids'];
            unset($data['participant_ids']);
            if (!$cancel) { $this->validateParticipants($ids, $context); }
            if ($cancel && $event['status'] === 'cancelled') { return $event; }
            $this->must($this->scope('team_events', $unit, $context['actor'])->where('id', $id)->where('version', $version)
                ->update($data + ['version' => $version + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]));
            if ($this->db->affectedRows() !== 1) { throw new RuntimeException('Event changed. Reload before saving.', 409); }
            if (!$cancel) { $this->replaceParticipants($unit, $id, $ids, $context['actor']); }
            return $this->event($unit, $id, $context);
        });
    }

    private function event(string $unit, int $id, array $context): array
    {
        $row = $this->scope('team_events', $unit, $context['actor'])->where('id', $id)->get()->getRowArray();
        if ($row === null) { throw new RuntimeException('Event not found.', 404); }
        $event = $this->decorate([$row], $unit, $context)[0];
        $event['participant_ids'] = array_column($event['participants'], 'id');
        $event['canEdit'] = $context['canManage'] || $event['created_by_user_id'] === (int) $context['actor']['id'];
        return $event;
    }

    /** Batch hydration never exposes historical/ineligible attendee identities. */
    private function decorate(array $rows, string $unit, array $context): array
    {
        if ($rows === []) { return []; }
        $members = array_column($context['members'], null, 'id');
        $participants = [];
        $children = $this->scope('team_event_participants', $unit, $context['actor'])
            ->whereIn('event_id', array_column($rows, 'id'))->orderBy('user_id', 'ASC')->get()->getResultArray();
        foreach ($children as $child) {
            if (isset($members[$child['user_id']])) { $participants[$child['event_id']][] = $members[$child['user_id']]; }
        }
        foreach ($rows as &$row) {
            foreach (['id', 'tenant_id', 'created_by_user_id', 'version'] as $key) { $row[$key] = (int) $row[$key]; }
            $row['participants'] = $participants[$row['id']] ?? [];
        }
        unset($row);
        return $rows;
    }


    private function validateParticipants(array $ids, array $context): void
    {
        $members = array_column($context['members'], null, 'id');
        // Stable lock order; a concurrent suspension/role change cannot pass validation
        // from the earlier context snapshot and then commit before these writes.
        foreach ($ids as $id) {
            if (!isset($members[$id])) { throw new RuntimeException('Participants must be active department members in this tenant.', 422); }
            $query = $this->db->table('users')->where('id', $id)->where('tenant_id', $context['actor']['tenant_id'])
                ->where('status', 'active')->where('deleted_at', null)->where('role', $members[$id]['role']);
            $row = $this->db->DBDriver === 'MySQLi'
                ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray() : $query->get()->getRowArray();
            if ($row === null) { throw new RuntimeException('Participants must be active department members in this tenant.', 422); }
        }
    }

    private function replaceParticipants(string $unit, int $id, array $ids, array $actor): void
    {
        $this->must($this->scope('team_event_participants', $unit, $actor)->where('event_id', $id)->delete());
        foreach ($ids as $userId) {
            $this->must($this->db->table('team_event_participants')->insert([
                'tenant_id' => $actor['tenant_id'], 'unit' => $unit, 'event_id' => $id, 'user_id' => $userId,
            ]));
        }
    }

    private function editable(array $input, bool $full): array
    {
        $fields = ['title', 'description', 'location', 'starts_at', 'ends_at', 'participant_ids'];
        if ($full && array_diff($fields, array_keys($input)) !== []) { throw new RuntimeException('Supply all editable event fields.', 422); }
        $input += ['description' => '', 'location' => '', 'participant_ids' => []];
        $start = $this->picker($input['starts_at'] ?? null);
        $end = $this->picker($input['ends_at'] ?? null);
        $duration = $end->getTimestamp() - $start->getTimestamp();
        if ($duration <= 0 || $duration > 31 * 86400) { throw new RuntimeException('Event duration must be positive and at most 31 days.', 422); }
        $ids = $input['participant_ids'];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::PARTICIPANT_LIMIT) {
            throw new RuntimeException('participant_ids must be a list of at most 100 IDs.', 422);
        }
        $ids = array_map(fn (mixed $id): int => $this->positive($id, 'participant_ids'), $ids);
        if (count(array_unique($ids)) !== count($ids)) { throw new RuntimeException('Participant IDs must be unique.', 422); }
        sort($ids, SORT_NUMERIC);
        $utc = new DateTimeZone('UTC');
        return ['title' => $this->text($input['title'] ?? null, 'title', 160, true),
            'description' => $this->text($input['description'], 'description', 4000),
            'location' => $this->text($input['location'], 'location', 160),
            'starts_at' => $start->setTimezone($utc)->format('Y-m-d H:i:s'),
            'ends_at' => $end->setTimezone($utc)->format('Y-m-d H:i:s'), 'participant_ids' => $ids];
    }

    private function picker(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\z/', $value)) {
            throw new RuntimeException('Use a valid Manila datetime in YYYY-MM-DDTHH:mm format.', 422);
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d\TH:i') !== $value || substr($value, 0, 4) === '0000') {
            throw new RuntimeException('Use a valid Manila datetime in YYYY-MM-DDTHH:mm format.', 422);
        }
        // Keep stored UTC values in the portable four-digit SQL datetime range too.
        $this->bounded($date->setTimezone(new DateTimeZone('UTC')));
        return $date;
    }

    private function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value)
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new RuntimeException('Use a valid date in YYYY-MM-DD format.', 422);
        }
        return new DateTimeImmutable($value, new DateTimeZone('Asia/Manila'));
    }

    private function range(array $filters): array
    {
        $date = $this->date(array_key_exists('date', $filters) ? $filters['date']
            : (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d'));
        $view = array_key_exists('view', $filters) ? $filters['view'] : 'month';
        $mine = array_key_exists('mine', $filters) ? $filters['mine'] : '';
        if (!in_array($view, ['month', 'week', 'agenda'], true) || !in_array($mine, ['', '1'], true)) {
            throw new RuntimeException('Invalid calendar view or mine filter.', 422);
        }
        if ($view === 'month') {
            $first = $date->modify('first day of this month');
            $start = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
            $last = $date->modify('last day of this month');
            $end = $last->modify('+' . (8 - (int) $last->format('N')) . ' days');
            $prev = $first->modify('-1 month');
            $next = $first->modify('+1 month');
        } elseif ($view === 'week') {
            $start = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
            $end = $start->modify('+7 days');
            $prev = $date->modify('-7 days');
            $next = $date->modify('+7 days');
        } else {
            $start = $date;
            $end = $date->modify('+30 days');
            $prev = $date->modify('-30 days');
            $next = $end;
        }
        foreach ([$start, $end, $prev, $next] as $bound) { $this->bounded($bound); }
        return ['date' => $date->format('Y-m-d'), 'view' => $view, 'mine' => $mine,
            'startDate' => $start->format('Y-m-d'), 'endDate' => $end->format('Y-m-d'),
            'prevDate' => $prev->format('Y-m-d'), 'nextDate' => $next->format('Y-m-d')];
    }

    private function bounded(DateTimeImmutable $date): void
    {
        if ((int) $date->format('Y') < 1 || (int) $date->format('Y') > 9999) {
            throw new RuntimeException('Calendar range exceeds supported years 0001..9999.', 422);
        }
    }

    private function positive(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]]) === false) {
            throw new RuntimeException('Invalid ' . $field . '.', 422);
        }
        return (int) $value;
    }

    private function text(mixed $value, string $field, int $limit, bool $required = false): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit
            || ($required && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('Invalid ' . $field . '; maximum ' . $limit . ' characters.', 422);
        }
        return trim($value);
    }

    private function scope(string $table, string $unit, array $actor): BaseBuilder
    {
        return $this->db->table($table)->where('tenant_id', $actor['tenant_id'])->where('unit', $unit);
    }

    private function ready(bool $tasks = false): void
    {
        foreach (array_merge(['team_events', 'team_event_participants'], $tasks ? ['team_tasks'] : []) as $table) {
            if (!$this->db->tableExists($table)) { throw new RuntimeException('Team calendar is unavailable; run the team migrations.', 503); }
        }
    }

    private function must(bool $success): void
    {
        if (!$success) { throw new RuntimeException('Team calendar write failed.', 503); }
    }

    private function guard(callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $e) {
            if ($e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422, 503], true)) { throw $e; }
            throw new RuntimeException('Team calendar is unavailable.', 503, $e);
        }
    }

    private function transaction(callable $operation): array
    {
        return $this->guard(function () use ($operation): array {
            if ($this->db->transDepth !== 0) { throw new RuntimeException('An independent team calendar transaction is required.', 409); }
            $this->db->resetTransStatus();
            $this->must($this->db->transBegin());
            try {
                $result = $operation();
                if (!$this->db->transStatus()) { throw new RuntimeException('Team calendar transaction failed.', 503); }
                $this->must($this->db->transCommit());
                return $result;
            } catch (Throwable $e) {
                try { $this->db->transRollback(); }
                finally { $this->db->resetTransStatus(); }
                throw $e;
            }
        });
    }
}
