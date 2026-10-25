<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Role-derived department tasks, without patient or attachment relationships.
 *
 * Units: clinic, guidance, facilities (facilities uses PortalContext's bmg roles).
 * Task arrays contain the stored fields, including server-owned tenant_id/unit;
 * identifiers and version are integers, assignee_user_id is ?int, due_date is
 * ?string (YYYY-MM-DD), and timestamps are UTC strings (Y-m-d H:i:s).
 * checklist is always a list of {text:string, done:bool}. Input accepts a PHP
 * list or JSON list (at most 48 KiB), max 20 items, text 1..160 characters,
 * strict boolean done, and no extra item keys. Updates may omit checklist to
 * preserve it; an explicit [] clears it. Checklist text never enters activity.
 * Text is returned as text, not HTML; presentation layers must escape it.
 *
 * RuntimeException codes: 401 unauthenticated, 403 forbidden, 404 scoped task
 * missing, 409 stale version/caller-owned transaction, 422 invalid input or
 * ineligible assignee, 503 unavailable tables/failed write. Unknown input keys
 * are ignored, never persisted. Empty assignee/date strings normalize to null.
 */
final class TeamTaskService
{
    public const PAGE_LIMIT = 100;
    public const COMMENTS_LIMIT = 50;
    public const ACTIVITY_LIMIT = 50;
    public const CHECKLIST_LIMIT = 20;
    public const CHECKLIST_BYTES_LIMIT = 49152;
    private const STATUSES = ['todo', 'in_progress', 'blocked', 'done'];
    private const PRIORITIES = ['low', 'normal', 'high'];
    private BaseConnection $db;
    private TeamsNotifications $notifications;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** actor is the refreshed AuthenticatedUser identity; members expose only id/name/role. */
    public function context(string $unit): array
    {
        $actor = $this->actor($unit);
        return ['actor' => $actor, 'members' => $this->members($unit, $actor),
            'canManage' => $this->manages($unit, $actor['role'])];
    }

    /** Fixed 100/page. Counts apply mine/q/priority, but not status, for status-tab summaries. */
    public function listing(string $unit, array $filters = []): array
    {
        $context = $this->context($unit);
        $this->ready();
        $actor = $context['actor'];
        $page = $this->positive($filters['page'] ?? 1, 'page');
        $query = $this->text($filters['q'] ?? '', 'q', 160, false);
        $status = $this->optionalChoice($filters['status'] ?? '', self::STATUSES, 'status');
        $priority = $this->optionalChoice($filters['priority'] ?? '', self::PRIORITIES, 'priority');
        $mine = $filters['mine'] ?? '';
        if (!in_array($mine, ['', null, '0', '1'], true)) {
            throw new RuntimeException('Invalid mine filter; use "1".', 422);
        }
        $builder = $this->scope('team_tasks', $unit, $actor);
        if ($mine === '1') { $builder->where('assignee_user_id', $actor['id']); }
        if ($priority !== null) { $builder->where('priority', $priority); }
        if ($query !== '') {
            // The builder binds SQL values but does not escape user-supplied LIKE wildcards.
            $escape = $this->db->likeEscapeChar;
            $query = str_replace([$escape, '%', '_'], [$escape . $escape, $escape . '%', $escape . '_'], $query);
            $builder->groupStart()->like('title', $query, 'both', true)->orLike('description', $query, 'both', true)->groupEnd();
        }
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ((clone $builder)->select('status, COUNT(*) AS total', false)->groupBy('status')->get()->getResultArray() as $row) {
            $counts[$row['status']] = (int) $row['total'];
        }
        $counts['total'] = array_sum($counts);
        if ($status !== null) { $builder->where('status', $status); }
        $total = (clone $builder)->countAllResults();
        $pages = max(1, (int) ceil($total / self::PAGE_LIMIT));
        $page = min($page, $pages);
        $tasks = $builder->orderBy('id', 'DESC')->limit(self::PAGE_LIMIT, ($page - 1) * self::PAGE_LIMIT)->get()->getResultArray();
        $tasks = array_map(fn (array $row): array => $this->task($row), $tasks);
        return ['tasks' => $tasks, 'counts' => $counts, 'total' => $total, 'page' => $page, 'pages' => $pages,
            'limit' => self::PAGE_LIMIT] + $context;
    }

    /**
     * Flat task + canEdit, comments, activity, comments_total, activity_total,
     * comments_limit=50, activity_limit=50. Child rows are newest-first by id.
     * Comments: id/tenant_id/unit/task_id/actor_user_id/body/created_at.
     * Activity: id/tenant_id/unit/task_id/actor_user_id/event/created_at and
     * nullable old_status/new_status. No free-form activity metadata is stored.
     */
    public function detail(string $unit, int $id): array
    {
        $actor = $this->actor($unit);
        $this->ready();
        $task = $this->record($unit, $id, $actor);
        $task['canEdit'] = $this->canEdit($task, $actor);
        foreach (['comments' => self::COMMENTS_LIMIT, 'activity' => self::ACTIVITY_LIMIT] as $kind => $limit) {
            $builder = $this->scope('team_task_' . $kind, $unit, $actor)->where('task_id', $id);
            $task[$kind . '_total'] = (clone $builder)->countAllResults();
            $task[$kind . '_limit'] = $limit;
            $task[$kind] = $builder->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
            foreach ($task[$kind] as &$entry) {
                foreach (['id', 'tenant_id', 'task_id', 'actor_user_id'] as $field) { $entry[$field] = (int) $entry[$field]; }
            }
            unset($entry);
        }
        return $task;
    }

    /** Defaults: description='', checklist=[], status=todo, priority=normal, assignee/due_date=null. */
    public function create(string $unit, array $input): array
    {
        return $this->transaction(function () use ($unit, $input): array {
            $actor = $this->actor($unit);
            $this->ready();
            $data = $this->editable($input, false);
            $this->assignee($unit, $data['assignee_user_id'], $actor);
            $now = gmdate('Y-m-d H:i:s');
            $this->must($this->db->table('team_tasks')->insert($data + [
                'tenant_id' => $actor['tenant_id'], 'unit' => $unit, 'created_by_user_id' => $actor['id'],
                'version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]));
            $task = $this->record($unit, (int) $this->db->insertID(), $actor);
            $this->activity($task, $actor, 'created');
            $this->notifications->enqueue($task, $actor, TeamsNotifications::taskPlan(null, $task));
            return $task;
        });
    }

    /** Replace the original six editable fields + version; optional checklist, omitted = preserve. */
    public function update(string $unit, int $id, array $input): array
    {
        return $this->change($unit, $id, $input, false);
    }

    /** Only status and version are read; all other input is ignored. */
    public function move(string $unit, int $id, array $input): array
    {
        return $this->change($unit, $id, $input, true);
    }

    /** Input: body (1..2000 characters). Does not change the task's edit version. */
    public function comment(string $unit, int $id, array $input): void
    {
        $this->transaction(function () use ($unit, $id, $input): array {
            $actor = $this->actor($unit);
            $this->ready();
            $task = $this->record($unit, $id, $actor, true);
            $body = $this->text($input['body'] ?? null, 'body', 2000);
            $this->assignee($unit, $task['assignee_user_id'], $actor);
            $this->must($this->db->table('team_task_comments')->insert([
                'tenant_id' => $actor['tenant_id'], 'unit' => $unit, 'task_id' => $id,
                'actor_user_id' => $actor['id'], 'body' => $body, 'created_at' => gmdate('Y-m-d H:i:s'),
            ]));
            $commentId = (int) $this->db->insertID();
            $this->activity($task, $actor, 'commented');
            $this->notifications->enqueue($task, $actor, TeamsNotifications::taskPlan($task, $task, true), $commentId);
            return [];
        });
    }

    /** UI helper only: mutations always obtain their own authoritative actor and scoped task. */
    public function canEdit(array $task, array $actor): bool
    {
        $unit = $task['unit'] ?? '';
        if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)
            || (int) ($task['tenant_id'] ?? 0) <= 0
            || (int) ($task['tenant_id'] ?? 0) !== (int) ($actor['tenant_id'] ?? 0)
            || (int) ($actor['id'] ?? 0) <= 0
            || !in_array($actor['role'] ?? '', $this->roles($unit), true)) {
            return false;
        }
        return $this->manages($unit, $actor['role'])
            || (int) ($task['created_by_user_id'] ?? 0) === (int) $actor['id']
            || (int) ($task['assignee_user_id'] ?? 0) === (int) $actor['id'];
    }

    private function change(string $unit, int $id, array $input, bool $move): array
    {
        return $this->transaction(function () use ($unit, $id, $input, $move): array {
            $actor = $this->actor($unit);
            $this->ready();
            $task = $this->record($unit, $id, $actor, true);
            if (!$this->canEdit($task, $actor)) { throw new RuntimeException('You cannot edit this task.', 403); }
            $version = $this->positive($input['version'] ?? null, 'version');
            if ($version !== $task['version']) { throw new RuntimeException('Task changed. Reload before saving.', 409); }
            $data = $move ? ['status' => $this->choice($input['status'] ?? null, self::STATUSES, 'status')]
                : $this->editable($input, true);
            $this->assignee($unit, $move ? $task['assignee_user_id'] : $data['assignee_user_id'], $actor);
            $unchanged = true;
            foreach ($data as $field => $value) {
                if (($field === 'checklist' ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value) !== $task[$field]) {
                    $unchanged = false;
                }
            }
            if ($unchanged) { return $task; }
            $this->must($this->scope('team_tasks', $unit, $actor)->where('id', $id)->where('version', $version)
                ->update($data + ['version' => $version + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]));
            if ($this->db->affectedRows() !== 1) { throw new RuntimeException('Task changed. Reload before saving.', 409); }
            $updated = $this->record($unit, $id, $actor);
            $this->activity($updated, $actor, $move ? 'moved' : 'updated', $task['status'], $updated['status']);
            $this->notifications->enqueue($updated, $actor, TeamsNotifications::taskPlan($task, $updated));
            return $updated;
        });
    }

    private function actor(string $unit): array
    {
        $roles = $this->roles($unit);
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) { throw new RuntimeException('Authentication required.', 401); }
        if (!in_array($actor['role'], $roles, true)) { throw new RuntimeException('Department membership required.', 403); }
        return $actor;
    }

    private function roles(string $unit): array
    {
        if (!in_array($unit, ['clinic', 'guidance', 'facilities'], true)) {
            throw new RuntimeException('Invalid task department.', 422);
        }
        return PortalContext::allowedRoles($unit === 'facilities' ? PortalContext::PORTAL_BMG : $unit);
    }

    private function manages(string $unit, string $role): bool
    {
        return $role === 'superadmin' || in_array($role, match ($unit) {
            'clinic' => ['clinic_admin'], 'guidance' => ['guidance_admin', 'guidance_supervisor'],
            'facilities' => ['bmg_admin'], default => [],
        }, true);
    }

    private function members(string $unit, array $actor): array
    {
        $builder = $this->db->table('users u')->select('u.id,u.username,u.role')
            ->where('u.tenant_id', $actor['tenant_id'])->where('u.status', 'active')->where('u.deleted_at', null)
            ->whereIn('u.role', $this->roles($unit));
        $names = $this->db->tableExists('people')
            && array_diff(['id', 'tenant_id', 'first_name', 'last_name', 'deleted_at'], $this->db->getFieldNames('people')) === []
            && $this->db->fieldExists('person_id', 'users');
        if ($names) {
            $builder->select('p.first_name,p.last_name')->join('people p',
                'p.id = u.person_id AND p.tenant_id = u.tenant_id AND p.deleted_at IS NULL', 'left');
        }
        $members = [];
        foreach ($builder->orderBy('u.id', 'ASC')->get()->getResultArray() as $row) {
            $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $members[] = ['id' => (int) $row['id'], 'name' => $name !== '' ? $name : $row['username'], 'role' => $row['role']];
        }
        return $members;
    }

    private function scope(string $table, string $unit, array $actor): BaseBuilder
    {
        return $this->db->table($table)->where('tenant_id', $actor['tenant_id'])->where('unit', $unit);
    }

    private function record(string $unit, int $id, array $actor, bool $lock = false): array
    {
        $builder = $this->scope('team_tasks', $unit, $actor)->where('id', $id);
        $row = $this->row($builder, $lock);
        if ($row === null) { throw new RuntimeException('Task not found.', 404); }
        return $this->task($row);
    }

    private function row(BaseBuilder $builder, bool $lock): ?array
    {
        // SQLite serializes writes; MySQL locks the task and assignee until activity commits.
        return $lock && $this->db->DBDriver === 'MySQLi'
            ? $this->db->query($builder->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
            : $builder->get()->getRowArray();
    }

    private function assignee(string $unit, ?int $id, array $actor): void
    {
        if ($id === null) { return; }
        $row = $this->row($this->db->table('users')->where('id', $id)->where('tenant_id', $actor['tenant_id'])
            ->where('status', 'active')->where('deleted_at', null)->whereIn('role', $this->roles($unit)), true);
        if ($row === null) { throw new RuntimeException('Assignee must be an active member of this department and tenant.', 422); }
    }

    private function task(array $row): array
    {
        foreach (['id', 'tenant_id', 'created_by_user_id', 'version'] as $key) { $row[$key] = (int) $row[$key]; }
        $row['assignee_user_id'] = $row['assignee_user_id'] === null ? null : (int) $row['assignee_user_id'];
        try { $row['checklist'] = $this->checklist($row['checklist']); }
        catch (RuntimeException $e) { throw new RuntimeException('Stored task checklist is invalid.', 503, $e); }
        return $row;
    }

    private function editable(array $input, bool $full): array
    {
        $defaults = ['description' => '', 'status' => 'todo', 'priority' => 'normal', 'assignee_user_id' => null, 'due_date' => null];
        if ($full && array_diff(array_merge(['title'], array_keys($defaults)), array_keys($input)) !== []) {
            throw new RuntimeException('Supply all editable task fields.', 422);
        }
        $input += $defaults;
        $date = $input['due_date'];
        if ($date === '') { $date = null; }
        if ($date !== null && (!is_string($date) || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date)
            || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
            throw new RuntimeException('Use a valid due_date in YYYY-MM-DD format.', 422);
        }
        $id = $input['assignee_user_id'];
        $data = [
            'title' => $this->text($input['title'] ?? null, 'title', 160),
            'description' => $this->text($input['description'], 'description', 4000, false),
            'status' => $this->choice($input['status'], self::STATUSES, 'status'),
            'priority' => $this->choice($input['priority'], self::PRIORITIES, 'priority'),
            'assignee_user_id' => $id === null || $id === '' ? null : $this->positive($id, 'assignee_user_id'),
            'due_date' => $date,
        ];
        // Omitted on update: leave the locked row's stored checklist untouched.
        if (!$full || array_key_exists('checklist', $input)) {
            $checklist = $this->checklist(array_key_exists('checklist', $input) ? $input['checklist'] : []);
            $data['checklist'] = json_encode($checklist, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        return $data;
    }

    private function checklist(mixed $value): array
    {
        $json = is_string($value);
        if ($json) {
            if (strlen($value) > self::CHECKLIST_BYTES_LIMIT) {
                throw new RuntimeException('Checklist JSON exceeds 48 KiB.', 422);
            }
            try { $value = json_decode($value, false, 4, JSON_THROW_ON_ERROR); }
            catch (\JsonException $e) { throw new RuntimeException('Checklist must be a JSON list.', 422, $e); }
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::CHECKLIST_LIMIT) {
            throw new RuntimeException('Checklist must be a list of at most 20 items.', 422);
        }
        $items = [];
        foreach ($value as $item) {
            if ($json && $item instanceof \stdClass) { $item = (array) $item; }
            if (!is_array($item) || count($item) !== 2 || !array_key_exists('text', $item)
                || !array_key_exists('done', $item) || !is_bool($item['done'])) {
                throw new RuntimeException('Each checklist item requires only text and boolean done.', 422);
            }
            $items[] = ['text' => $this->text($item['text'], 'checklist text', 160), 'done' => $item['done']];
        }
        // Array input is bounded before encoding too: <=20 records, each exactly two validated fields.
        if (strlen(json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > self::CHECKLIST_BYTES_LIMIT) {
            throw new RuntimeException('Checklist payload exceeds 48 KiB.', 422);
        }
        return $items;
    }

    private function positive(mixed $value, string $field): int
    {
        if ((!is_int($value) && !is_string($value))
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - 1]]) === false) {
            throw new RuntimeException('Invalid ' . $field . '.', 422);
        }
        return (int) $value;
    }

    private function text(mixed $value, string $field, int $limit, bool $required = true): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit
            || ($required && trim($value) === '') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new RuntimeException('Invalid ' . $field . '; maximum ' . $limit . ' characters.', 422);
        }
        return trim($value);
    }

    private function choice(mixed $value, array $choices, string $field): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) { throw new RuntimeException('Invalid ' . $field . '.', 422); }
        return $value;
    }

    private function optionalChoice(mixed $value, array $choices, string $field): ?string
    {
        return $value === '' || $value === null ? null : $this->choice($value, $choices, $field);
    }

    private function activity(array $task, array $actor, string $event, ?string $old = null, ?string $new = null): void
    {
        $this->must($this->db->table('team_task_activity')->insert([
            'tenant_id' => $actor['tenant_id'], 'unit' => $task['unit'], 'task_id' => $task['id'],
            'actor_user_id' => $actor['id'], 'event' => $event, 'old_status' => $old, 'new_status' => $new,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]));
    }

    private function ready(): void
    {
        foreach (['team_tasks', 'team_task_comments', 'team_task_activity'] as $table) {
            if (!$this->db->tableExists($table)) { throw new RuntimeException('Team tasks are unavailable; run the team task migration.', 503); }
        }
    }

    private function must(bool $success): void
    {
        if (!$success) { throw new RuntimeException('Team task write failed.', 503); }
    }

    private function transaction(callable $operation): array
    {
        if ($this->db->transDepth !== 0) { throw new RuntimeException('An independent team task transaction is required.', 409); }
        $this->notifications = new TeamsNotifications($this->db);
        $this->db->resetTransStatus();
        $this->must($this->db->transBegin());
        try {
            $result = $operation();
            if (!$this->db->transStatus()) { throw new RuntimeException('Team task transaction failed.', 503); }
            $this->must($this->db->transCommit());
            $this->notifications->publishCommitted();
            return $result;
        } catch (Throwable $e) {
            try { $this->db->transRollback(); }
            finally { $this->db->resetTransStatus(); }
            if ($e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 404, 409, 422, 503], true)) { throw $e; }
            throw new RuntimeException('Team task write failed.', 503, $e);
        }
    }
}
