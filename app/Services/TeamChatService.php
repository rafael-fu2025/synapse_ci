<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * One immutable text channel per tenant/department. All calls refresh task authorization.
 * Text is returned verbatim, not HTML; callers must escape it. No drafts or previews.
 * RuntimeException codes: 401/403 auth, 422 input, 409 nonce conflict or ambient
 * transaction, 503 unavailable schema/storage. No database exception is exposed.
 */
final class TeamChatService
{
    public const PAGE_LIMIT = 40;
    private BaseConnection $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** Same actor, members and canManage contract as TeamTaskService. */
    public function context(string $unit): array
    {
        $context = (new TeamTaskService())->context($unit);
        $this->ready();
        return $context;
    }

    /** before: positive id; after: nonnegative id; mutually exclusive, fixed 40/page. */
    public function history(string $unit, array $filters = []): array
    {
        $context = $this->context($unit);
        $this->keys($filters, ['before', 'after']);
        if (array_key_exists('before', $filters) && array_key_exists('after', $filters)) {
            throw new RuntimeException('Use before or after, not both.', 422);
        }
        $after = array_key_exists('after', $filters);
        $cursor = $after ? $this->integer($filters['after'], true)
            : (array_key_exists('before', $filters) ? $this->integer($filters['before'], false) : null);
        return $this->storage(function () use ($unit, $context, $after, $cursor): array {
            $actor = $context['actor'];
            $query = $this->scope('team_chat_messages', $unit, $actor);
            if ($cursor !== null) { $query->where('id ' . ($after ? '>' : '<'), $cursor); }
            $rows = $query->orderBy('id', $after ? 'ASC' : 'DESC')->limit(self::PAGE_LIMIT + 1)->get()->getResultArray();
            $more = count($rows) > self::PAGE_LIMIT;
            $rows = array_slice($rows, 0, self::PAGE_LIMIT);
            if (!$after) { $rows = array_reverse($rows); }
            $messages = array_map(fn (array $row): array => $this->message($row, $context), $rows);
            return ['messages' => $messages, 'has_more' => $more,
                'next_before' => !$after && $more ? $messages[0]['id'] : null] + $this->summary($unit, $actor);
        });
    }

    /** Only body, client_nonce (UUID v4), and optional nullable task_id are accepted. */
    public function send(string $unit, array $input): array
    {
        $context = $this->context($unit);
        $data = $this->payload($input);
        $actor = $context['actor'];
        return $this->storage(function () use ($unit, $context, $actor, $data): array {
            $this->independent();
            $existing = $this->nonce($unit, $actor, $data['client_nonce']);
            if ($existing !== null) { return $this->retry($existing, $data, $context); }
            $this->db->resetTransStatus();
            if (!$this->db->transBegin()) { throw new RuntimeException('Team chat unavailable.', 503); }
            $duplicate = false;
            try {
                if ($data['task_id'] !== null) {
                    $query = $this->scope('team_tasks', $unit, $actor)->where('id', $data['task_id'])
                        ->whereIn('status', ['todo', 'in_progress', 'blocked']);
                    $task = $this->db->DBDriver === 'MySQLi'
                        ? $this->db->query($query->getCompiledSelect() . ' FOR UPDATE')->getRowArray()
                        : $query->get()->getRowArray();
                    if ($task === null) { throw new RuntimeException('Task must be active in this department.', 422); }
                }
                // Bind text separately so driver SQL error strings do not contain the body.
                $table = $this->db->protectIdentifiers($this->db->prefixTable('team_chat_messages'));
                $sql = 'INSERT INTO ' . $table
                    . ' (tenant_id, unit, sender_user_id, client_nonce, body, task_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)';
                $mysql = $this->db->DBDriver === 'MySQLi';
                $statement = $mysql ? $this->db->connID->prepare($sql) : $this->db->prepare(static fn () => $sql);
                try {
                    $now = gmdate('Y-m-d H:i:s');
                    if ($mysql) {
                        // CI's MySQL prepared binder treats numeric strings as doubles.
                        // Explicit string binding preserves bodies like "000123" verbatim.
                        $statement->bind_param('isissis', $actor['tenant_id'], $unit, $actor['id'],
                            $data['client_nonce'], $data['body'], $data['task_id'], $now);
                        $ok = $statement->execute();
                    } else {
                        $ok = $statement->execute($actor['tenant_id'], $unit, $actor['id'], $data['client_nonce'],
                            $data['body'], $data['task_id'], $now);
                    }
                    if ($ok === false) {
                        throw new RuntimeException('Team chat unavailable.', 503);
                    }
                } catch (Throwable $e) {
                    // Drivers differ in how duplicate-key errors are reported. Only an
                    // exact scoped nonce row after rollback can resolve a failed insert.
                    $duplicate = true;
                    throw $e;
                } finally {
                    $statement->close();
                }
                $row = $this->nonce($unit, $actor, $data['client_nonce']);
                if ($row === null || !$this->db->transStatus() || !$this->db->transCommit()) {
                    throw new RuntimeException('Team chat unavailable.', 503);
                }
            } catch (Throwable $e) {
                $this->db->transRollback();
                $this->db->resetTransStatus();
                // Re-read outside the failed transaction: MySQL's old snapshot may not see the winner.
                if ($duplicate) {
                    $fresh = $this->context($unit);
                    $row = $this->nonce($unit, $fresh['actor'], $data['client_nonce']);
                    if ($row !== null) { return $this->retry($row, $data, $fresh); }
                }
                throw $e;
            }
            return $this->message($row, $context);
        });
    }

    /** Explicit visible-message acknowledgement; history never advances this cursor. */
    public function markRead(string $unit, array $input): array
    {
        $actor = $this->context($unit)['actor'];
        $this->keys($input, ['last_read_id']);
        $id = $this->integer($input['last_read_id'] ?? null, true);
        return $this->storage(function () use ($unit, $actor, $id): array {
            $this->independent();
            if ($id !== 0 && $this->scope('team_chat_messages', $unit, $actor)->where('id', $id)->countAllResults() !== 1) {
                throw new RuntimeException('Read message must exist in this department.', 422);
            }
            $sql = $this->db->table('team_chat_reads')->set([
                'tenant_id' => $actor['tenant_id'], 'unit' => $unit, 'user_id' => $actor['id'], 'last_read_message_id' => 0,
            ])->getCompiledInsert();
            // Do not reset an existing cursor, including concurrent first acknowledgements.
            $sql .= $this->db->DBDriver === 'MySQLi'
                ? ' ON DUPLICATE KEY UPDATE last_read_message_id = last_read_message_id'
                : ' ON CONFLICT (tenant_id, unit, user_id) DO NOTHING';
            if ($this->db->query($sql) === false
                || !$this->scope('team_chat_reads', $unit, $actor)->where('user_id', $actor['id'])
                    ->where('last_read_message_id <', $id)->update(['last_read_message_id' => $id])) {
                throw new RuntimeException('Team chat unavailable.', 503);
            }
            return $this->summary($unit, $actor);
        });
    }

    private function summary(string $unit, array $actor): array
    {
        $read = $this->scope('team_chat_reads', $unit, $actor)->where('user_id', $actor['id'])->get()->getRowArray();
        $last = (int) ($read['last_read_message_id'] ?? 0);
        $latest = $this->scope('team_chat_messages', $unit, $actor)->selectMax('id', 'latest')->get()->getRowArray();
        $latest = (int) ($latest['latest'] ?? 0);
        $unread = $this->scope('team_chat_messages', $unit, $actor)->where('id >', $last)->where('id <=', $latest)
            ->where('sender_user_id !=', $actor['id'])->countAllResults();
        return ['last_read_id' => $last, 'unread_count' => $unread, 'latest_id' => $latest];
    }

    private function message(array $row, array $context): array
    {
        $name = 'Former member';
        foreach ($context['members'] as $member) {
            if ((int) $member['id'] === (int) $row['sender_user_id']) { $name = $member['name']; break; }
        }
        $task = $row['task_id'] === null ? null : $this->scope('team_tasks', $row['unit'], $context['actor'])
            ->select('title')->where('id', $row['task_id'])->get()->getRowArray();
        return ['id' => (int) $row['id'], 'body' => $row['body'],
            'task_id' => $row['task_id'] === null ? null : (int) $row['task_id'], 'task_title' => $task['title'] ?? null,
            'sender_user_id' => (int) $row['sender_user_id'], 'sender_name' => $name, 'created_at' => $row['created_at'],
            'client_nonce' => (int) $row['sender_user_id'] === (int) $context['actor']['id'] ? $row['client_nonce'] : null];
    }

    private function nonce(string $unit, array $actor, string $nonce): ?array
    {
        return $this->scope('team_chat_messages', $unit, $actor)->where('sender_user_id', $actor['id'])
            ->where('client_nonce', $nonce)->get()->getRowArray();
    }

    private function retry(array $row, array $data, array $context): array
    {
        if ($row['body'] !== $data['body'] || ($row['task_id'] === null ? null : (int) $row['task_id']) !== $data['task_id']) {
            throw new RuntimeException('Client nonce already used for different contents.', 409);
        }
        return $this->message($row, $context);
    }

    private function payload(array $input): array
    {
        $this->keys($input, ['body', 'client_nonce', 'task_id']);
        $body = $input['body'] ?? null;
        if (!is_string($body) || !mb_check_encoding($body, 'UTF-8') || mb_strlen($body, 'UTF-8') > 2000
            || preg_match('/\S/u', $body) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $body)) {
            throw new RuntimeException('Body must contain 1 to 2000 text characters.', 422);
        }
        $nonce = $input['client_nonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $nonce)) {
            throw new RuntimeException('Client nonce must be a UUID v4.', 422);
        }
        return ['body' => $body, 'client_nonce' => strtolower($nonce),
            'task_id' => ($input['task_id'] ?? null) === null ? null : $this->integer($input['task_id'], false)];
    }

    private function integer(mixed $value, bool $zero): int
    {
        if ((!is_int($value) && !is_string($value))
            || !preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A[1-9][0-9]*\z/', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $zero ? 0 : 1]]) === false) {
            throw new RuntimeException('Invalid message or task identifier.', 422);
        }
        return (int) $value;
    }

    private function keys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) { throw new RuntimeException('Unsupported input fields.', 422); }
    }

    private function scope(string $table, string $unit, array $actor): BaseBuilder
    {
        return $this->db->table($table)->where('tenant_id', $actor['tenant_id'])->where('unit', $unit);
    }

    private function independent(): void
    {
        if ($this->db->transDepth !== 0) { throw new RuntimeException('An independent team chat write is required.', 409); }
    }

    private function ready(): void
    {
        $this->storage(function (): array {
            foreach (['team_chat_messages' => ['id', 'tenant_id', 'unit', 'sender_user_id', 'client_nonce', 'body', 'task_id', 'created_at'],
                'team_chat_reads' => ['tenant_id', 'unit', 'user_id', 'last_read_message_id'],
                'team_tasks' => ['id', 'tenant_id', 'unit', 'title', 'status']] as $table => $fields) {
                if (!$this->db->tableExists($table) || array_diff($fields, $this->db->getFieldNames($table)) !== []) {
                    throw new RuntimeException('Team chat unavailable; run the team chat migration.', 503);
                }
            }
            return [];
        });
    }

    private function storage(callable $operation): array
    {
        try { return $operation(); }
        catch (Throwable $e) {
            if ($e instanceof RuntimeException && in_array($e->getCode(), [401, 403, 409, 422, 503], true)) { throw $e; }
            // Never chain driver exceptions: their SQL/error details may contain user content.
            throw new RuntimeException('Team chat unavailable.', 503);
        }
    }
}
