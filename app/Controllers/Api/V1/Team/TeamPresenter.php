<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Team;

use DateTimeImmutable;
use DateTimeZone;

/** Privacy-minimal Team API projections; no service/database row is returned directly. */
final class TeamPresenter
{
    public static function member(array $member): array
    {
        return ['id' => (int) $member['id'], 'name' => (string) $member['name'], 'role' => (string) $member['role']];
    }

    public static function taskSummary(array $task, array $members = []): array
    {
        $checklist = is_array($task['checklist'] ?? null) ? $task['checklist'] : [];
        $done = count(array_filter($checklist, static fn (array $item): bool => ($item['done'] ?? false) === true));
        $memberNames = array_column($members, 'name', 'id');
        $assigneeId = $task['assignee_user_id'] ?? null;
        return [
            'id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'status' => (string) $task['status'],
            'priority' => (string) $task['priority'],
            'assignee' => $assigneeId === null ? null : [
                'id' => (int) $assigneeId,
                'name' => (string) ($memberNames[(int) $assigneeId] ?? 'Former member'),
            ],
            'due_date' => $task['due_date'] === null ? null : (string) $task['due_date'],
            'checklist_progress' => ['done' => $done, 'total' => count($checklist)],
            'version' => (int) $task['version'],
            'can_edit' => (bool) ($task['canEdit'] ?? false),
            'created_at' => self::time((string) $task['created_at']),
            'updated_at' => self::time((string) $task['updated_at']),
        ];
    }

    public static function taskDetail(array $task, array $members): array
    {
        $names = array_column($members, 'name', 'id');
        $comments = array_map(static fn (array $comment): array => [
            'id' => (int) $comment['id'],
            'actor' => ['id' => (int) $comment['actor_user_id'],
                'name' => (string) ($names[(int) $comment['actor_user_id']] ?? 'Former member')],
            'body' => (string) $comment['body'],
            'created_at' => self::time((string) $comment['created_at']),
        ], $task['comments'] ?? []);
        $activity = array_map(static fn (array $entry): array => [
            'id' => (int) $entry['id'],
            'actor' => ['id' => (int) $entry['actor_user_id'],
                'name' => (string) ($names[(int) $entry['actor_user_id']] ?? 'Former member')],
            'event' => (string) $entry['event'],
            'old_status' => $entry['old_status'] === null ? null : (string) $entry['old_status'],
            'new_status' => $entry['new_status'] === null ? null : (string) $entry['new_status'],
            'created_at' => self::time((string) $entry['created_at']),
        ], $task['activity'] ?? []);
        return self::taskSummary($task, $members) + [
            'description' => (string) $task['description'],
            'checklist' => array_map(static fn (array $item): array => [
                'text' => (string) $item['text'], 'done' => (bool) $item['done'],
            ], $task['checklist'] ?? []),
            'comments' => $comments,
            'activity' => $activity,
            'comments_total' => (int) $task['comments_total'],
            'comments_limit' => (int) $task['comments_limit'],
            'activity_total' => (int) $task['activity_total'],
            'activity_limit' => (int) $task['activity_limit'],
        ];
    }

    public static function calendarEvent(array $event, bool $detail = false): array
    {
        $item = [
            'type' => 'event',
            'id' => (int) $event['id'],
            'title' => (string) $event['title'],
            'status' => (string) $event['status'],
            'starts_at' => self::time((string) $event['starts_at']),
            'ends_at' => self::time((string) $event['ends_at']),
            'location' => (string) $event['location'],
            'participants' => array_map([self::class, 'member'], $event['participants'] ?? []),
            'version' => (int) $event['version'],
            'can_edit' => (bool) ($event['canEdit'] ?? false),
            'created_at' => self::time((string) $event['created_at']),
            'updated_at' => self::time((string) $event['updated_at']),
        ];
        if ($detail) {
            $item['description'] = (string) $event['description'];
        }
        return $item;
    }

    public static function calendarTask(array $task): array
    {
        return [
            'type' => 'task_deadline',
            'id' => (int) $task['id'],
            'title' => (string) $task['title'],
            'status' => (string) $task['status'],
            'priority' => (string) $task['priority'],
            'due_date' => (string) $task['due_date'],
            'assignee_user_id' => $task['assignee_user_id'] === null ? null : (int) $task['assignee_user_id'],
            'version' => (int) $task['version'],
            'sort_at' => (string) $task['due_date'] . 'T00:00:00+08:00',
        ];
    }

    public static function chatMessage(array $message): array
    {
        return [
            'id' => (int) $message['id'],
            'body' => (string) $message['body'],
            'sender' => ['id' => (int) $message['sender_user_id'], 'name' => (string) $message['sender_name']],
            'created_at' => self::time((string) $message['created_at']),
            'client_nonce' => $message['client_nonce'] === null ? null : (string) $message['client_nonce'],
            'tasks' => array_map(static fn (array $task): array => [
                'id' => (int) $task['id'], 'title' => (string) $task['title'],
            ], $message['tasks'] ?? []),
            'mentions' => array_map(static fn (array $mention): array => [
                'user_id' => (int) $mention['user_id'], 'name' => (string) $mention['name'],
                'start' => (int) $mention['start'], 'length' => (int) $mention['length'],
            ], $message['mentions'] ?? []),
            'reply_to' => ($message['reply_to'] ?? null) === null ? null : [
                'id' => (int) $message['reply_to']['id'],
                'sender_name' => (string) $message['reply_to']['sender_name'],
                'body' => (string) $message['reply_to']['body'],
            ],
        ];
    }

    public static function time(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->format(DATE_RFC3339);
    }
}
