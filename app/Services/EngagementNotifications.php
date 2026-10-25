<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use Config\Database;
use RuntimeException;

/** Publication fanout shares the existing bell; content is re-authorized on every inbox read. */
final class EngagementNotifications
{
    /** Return inserted IDs for NotificationInvalidation::publishCommitted after the parent commits. */
    public static function publish(string $kind, int $id, array $actor): array
    {
        $db = Database::connect();
        if ($db->transDepth < 1 || !$db->transStatus()) { throw new RuntimeException('Publication requires an active transaction.'); }
        [$table, $column, $type] = self::shape($kind);
        $name = $db->protectIdentifiers($db->prefixTable($table));
        $current = EngagementAccess::actor();
        if ((int) $current['id'] !== (int) ($actor['id'] ?? 0) || (int) $current['tenant_id'] !== (int) ($actor['tenant_id'] ?? 0)) {
            throw new RuntimeException('Invalid publication actor.', 403);
        }
        $row = $db->query("SELECT * FROM {$name} WHERE tenant_id=? AND id=? FOR UPDATE", [$current['tenant_id'], $id])->getRowArray();
        if ($row === null || $row['status'] !== 'published' || $row['published_at'] === null) { throw new RuntimeException('Invalid publication.'); }
        $unit = $kind === 'survey' ? 'guidance' : $row['unit'];
        EngagementAccess::actor($kind === 'survey' ? 'guidance.surveys.manage' : $unit . '.announcements.manage', $unit);
        $people = $db->table('people p')->distinct()->select('p.id')
            ->join('users u', 'u.tenant_id=p.tenant_id AND u.person_id=p.id')
            ->join('tenants t', 't.id=p.tenant_id')
            ->join('student_profiles sp', 'sp.tenant_id=p.tenant_id AND sp.person_id=p.id', 'left')
            ->join('employee_profiles ep', 'ep.tenant_id=p.tenant_id AND ep.person_id=p.id', 'left')
            ->where('p.tenant_id', $current['tenant_id'])->where('p.deleted_at', null)
            ->where('u.status', 'active')->where('u.deleted_at', null)->where('t.is_active', 1)->where('t.deleted_at', null);
        if ($row['audience'] === 'students') { $people->where('sp.id IS NOT NULL', null, false); }
        elseif ($row['audience'] === 'employees') { $people->where('ep.id IS NOT NULL', null, false); }
        elseif ($row['audience'] === 'all') { $people->groupStart()->where('sp.id IS NOT NULL', null, false)->orWhere('ep.id IS NOT NULL', null, false)->groupEnd(); }
        else { throw new RuntimeException('Invalid publication audience.'); }
        EngagementAudience::recipients($people, $row);
        $inserted = [];
        foreach ($people->get()->getResultArray() as $person) {
            $key = ['tenant_id' => $current['tenant_id'], 'recipient_person_id' => $person['id'], $column => $id, 'type' => $type];
            if ($db->table('notifications')->where($key)->countAllResults() > 0) { continue; }
            if (!$db->table('notifications')->insert($key + ['created_at' => gmdate('Y-m-d H:i:s'), 'read_at' => null])) {
                throw new RuntimeException('Unable to save publication notifications.');
            }
            $inserted[] = (int) $db->insertID();
        }
        return $inserted;
    }

    /** Correlated EXISTS for the existing notifications query, with fresh profile membership. */
    public static function visibleQuery(array $scope, string $kind): string
    {
        $db = Database::connect();
        [$table, $column] = self::shape($kind);
        $actor = (new AuthenticatedUser())->forIdentity((int) $scope['recipient_user_id'], (int) $scope['tenant_id']);
        $notices = $db->protectIdentifiers($db->prefixTable('notifications'));
        $builder = $db->table($table . ' e')->select('e.id')->where('e.tenant_id', $scope['tenant_id'])
            ->where("e.id={$notices}.{$column}", null, false)->where('e.status', 'published')
            ->where('e.published_at IS NOT NULL', null, false)
            ->groupStart()->where('e.starts_at', null)->orWhere('e.starts_at <=', gmdate('Y-m-d H:i:s'))->groupEnd()
            ->groupStart()->where('e.ends_at', null)->orWhere('e.ends_at >', gmdate('Y-m-d H:i:s'))->groupEnd();
        if ($kind === 'announcement') { $builder->whereIn('e.unit', ['clinic', 'guidance']); }
        return EngagementAudience::apply($builder, $actor ?? [], 'e')->getCompiledSelect();
    }

    private static function shape(string $kind): array
    {
        return match ($kind) {
            'announcement' => ['announcements', 'announcement_id', 'announcement_published'],
            'survey' => ['surveys', 'survey_id', 'survey_published'],
            default => throw new RuntimeException('Invalid publication kind.'),
        };
    }
}
