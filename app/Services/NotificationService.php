<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use Config\Database;
use RuntimeException;

final class NotificationService
{
    private const TEAM_EVENT_TITLES = [
        'team_event_added' => 'Team event invitation',
        'team_event_removed' => 'Team event participation removed',
        'team_event_updated' => 'Team event updated',
        'team_event_cancelled' => 'Team event cancelled',
    ];
    private const TEAM_TASK_TITLES = [
        'team_task_assigned' => 'Team task assigned',
        'team_task_unassigned' => 'Team task assignment removed',
        'team_task_updated' => 'Team task updated',
        'team_task_status' => 'Team task status changed',
        'team_task_commented' => 'Team task comment',
    ];
    /** Fixed destinations shared by inbox JSON and its server-rendered client allowlist. */
    public static function destinationUrls(): array
    {
        return [
            // Resolve the host before appending a fragment: the server cannot route it.
            'portal' => PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal') . '#my-appointments',
            'clinicReferrals' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/referrals?tab=incoming'),
            'guidanceReferrals' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/referrals?tab=incoming'),
            'clinicReferralHistory' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/referrals?tab=history'),
            'guidanceReferralHistory' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/referrals?tab=history'),
            'announcements' => PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal/announcements'),
            'surveys' => PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal/surveys'),
            'clinicTeams' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/teams'),
            'clinicTeamCalendar' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/teams/calendar'),
            'guidanceTeams' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/teams'),
            'guidanceTeamCalendar' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/teams/calendar'),
            'facilitiesTeams' => PortalContext::urlFor(PortalContext::PORTAL_BMG, 'facilities/teams'),
            'facilitiesTeamCalendar' => PortalContext::urlFor(PortalContext::PORTAL_BMG, 'facilities/teams/calendar'),
            'clinicAppointments' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/appointments'),
            'guidanceAppointments' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/appointments'),
            'clinicReferralSent' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/referrals?tab=sent'),
            'guidanceReferralSent' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/referrals?tab=sent'),
            'guidanceCases' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/cases'),
            'clinicInventory' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/inventory'),
            'facilitiesOperations' => PortalContext::urlFor(PortalContext::PORTAL_BMG, 'facilities'),
        ];
    }
    /** Uses the caller's transaction. Pass returned IDs to NotificationInvalidation only after commit. */
    public function notifyConfirmation(array $appointment): array
    {
        $db = Database::connect();
        if ($db->transDepth < 1 || !$db->transStatus()) {
            throw new RuntimeException('Confirmation notifications require an active transaction.');
        }
        foreach (['id', 'tenant_id', 'person_id', 'version'] as $key) {
            if (filter_var($appointment[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException('Invalid confirmation notification.');
            }
        }
        if ((int) $appointment['tenant_id'] !== CurrentTenant::id()) {
            throw new RuntimeException('Invalid confirmation notification.');
        }
        $table = $db->protectIdentifiers($db->prefixTable('appointments'));
        $stored = $db->query("SELECT id, tenant_id, person_id, version, status FROM {$table} WHERE tenant_id=? AND id=? AND deleted_at IS NULL FOR UPDATE",
            [$appointment['tenant_id'], $appointment['id']])->getRowArray();
        if ($stored === null || $stored['status'] !== 'confirmed' || ($appointment['status'] ?? null) !== 'confirmed'
            || (int) $stored['person_id'] !== (int) $appointment['person_id']
            || (int) $stored['version'] !== (int) $appointment['version']) {
            throw new RuntimeException('Invalid confirmation notification.');
        }
        $person = $db->table('people')->where('tenant_id', $stored['tenant_id'])->where('id', $stored['person_id'])
            ->where('deleted_at', null)->get()->getRowArray();
        if ($person === null) {
            throw new RuntimeException('Invalid confirmation recipient.');
        }
        $key = ['tenant_id' => $stored['tenant_id'], 'appointment_id' => $stored['id'],
            'type' => 'appointment_confirmed', 'appointment_version' => $stored['version']];
        // The appointment lock serializes retries; do not suppress unrelated SQL failures.
        $existing = $db->table('notifications')->where($key)->get()->getRowArray();
        if ($existing !== null) {
            if ((int) $existing['recipient_person_id'] !== (int) $stored['person_id']) {
                throw new RuntimeException('Confirmation recipient changed.');
            }
            return [];
        }
        if (!$db->table('notifications')->insert($key + ['recipient_person_id' => $stored['person_id'],
            'created_at' => gmdate('Y-m-d H:i:s'), 'read_at' => null])) {
            throw new RuntimeException('Unable to save confirmation notification.');
        }
        return [(int) $db->insertID()];
    }

    /** The referral row lock serializes delivery retries inside the create transaction. */
    public function notifyReferral(array $referral): array
    {
        $db = Database::connect();
        if ($db->transDepth < 1 || !$db->transStatus()) {
            throw new RuntimeException('Referral notifications require an active transaction.');
        }
        foreach (['id', 'tenant_id'] as $key) {
            if (filter_var($referral[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                throw new RuntimeException('Invalid referral notification.');
            }
        }
        if ((int) $referral['tenant_id'] !== CurrentTenant::id()) {
            throw new RuntimeException('Invalid referral notification.');
        }
        $table = $db->protectIdentifiers($db->prefixTable('referrals'));
        $stored = $db->query("SELECT id, tenant_id, source_module, target_module, status FROM {$table} WHERE tenant_id=? AND id=? FOR UPDATE",
            [$referral['tenant_id'], $referral['id']])->getRowArray();
        if ($stored === null || $stored['status'] !== 'submitted' || ($referral['status'] ?? null) !== 'submitted'
            || !in_array($stored['target_module'], ['clinic', 'guidance'], true)
            || $stored['source_module'] !== ($stored['target_module'] === 'clinic' ? 'guidance' : 'clinic')
            || ($referral['source_module'] ?? null) !== $stored['source_module']
            || ($referral['target_module'] ?? null) !== $stored['target_module']) {
            throw new RuntimeException('Invalid referral notification.');
        }
        $recipients = $this->receivingUsers((int) $stored['tenant_id'])
            ->whereIn('u.role', self::departmentRoles($stored['target_module']))->get()->getResultArray();
        $inserted = [];
        foreach ($recipients as $recipient) {
            $key = ['tenant_id' => $stored['tenant_id'], 'referral_id' => $stored['id'],
                'recipient_user_id' => $recipient['id'], 'type' => 'referral_received'];
            $existing = $db->table('notifications')->where($key)->get()->getRowArray();
            if ($existing !== null) {
                if ($existing['recipient_person_id'] !== null || $existing['appointment_id'] !== null
                    || $existing['appointment_version'] !== null) {
                    throw new RuntimeException('Invalid referral notification shape.');
                }
                continue;
            }
            if (!$db->table('notifications')->insert($key + ['recipient_person_id' => null,
                'appointment_id' => null, 'appointment_version' => null,
                'created_at' => gmdate('Y-m-d H:i:s'), 'read_at' => null])) {
                throw new RuntimeException('Unable to save referral notification.');
            }
            $inserted[] = (int) $db->insertID();
        }
        return $inserted;
    }

    private static function departmentRoles(string $module): array
    {
        return $module === 'clinic' ? ['clinic_admin', 'clinic_staff']
            : ['guidance_admin', 'guidance_supervisor', 'counsellor'];
    }

    /** No superadmin wildcard: an actual department role and read grant are required. */
    private function receivingUsers(int $tenant): BaseBuilder
    {
        return Database::connect()->table('users u')->distinct()->select('u.id')
            ->join('tenants t', 't.id=u.tenant_id')
            ->join('people p', 'p.tenant_id=u.tenant_id AND p.id=u.person_id')
            ->join('role_permissions rp', 'rp.role=u.role')
            ->join('permissions perm', 'perm.id=rp.permission_id')
            ->where('u.tenant_id', $tenant)->where('u.status', 'active')->where('u.deleted_at', null)
            ->where('t.is_active', 1)->where('t.deleted_at', null)->where('p.deleted_at', null)
            ->where('perm.code', 'referrals.read');
    }

    /** Latest ten personal and currently authorized staff items; unread_count saturates at 100. */
    public function ownInbox(): array
    {
        $scope = $this->ownScope();
        if ($scope === null) {
            return ['items' => [], 'unread_count' => 0];
        }
        return $this->inboxResult($scope, 10);
    }

    /** Paginate the same current-resource authority as the bell, never raw notification history. */
    public function ownPage(int $page = 1, string $filter = 'all'): array
    {
        if ($page < 1 || $page > 1000000 || !in_array($filter, ['all', 'unread'], true)) {
            throw new \InvalidArgumentException('Invalid notification page or filter.', 422);
        }
        $scope = $this->ownScope();
        $perPage = 20;
        $total = 0;
        if ($scope !== null) {
            $query = $this->inbox($scope);
            if ($filter === 'unread') $query->where('read_at', null);
            $total = $query->countAllResults();
        }
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $result = $scope === null ? ['items' => [], 'unread_count' => 0]
            : $this->inboxResult($scope, $perPage, ($page - 1) * $perPage, $filter === 'unread');
        return $result + ['total' => $total, 'page' => $page, 'pages' => $pages, 'filter' => $filter, 'per_page' => $perPage];
    }

    private function inboxResult(array $scope, int $limit, int $offset = 0, bool $unreadOnly = false): array
    {
        $db = Database::connect();
        $referrals = $db->protectIdentifiers($db->prefixTable('referrals'));
        $notifications = $db->protectIdentifiers($db->prefixTable('notifications'));
        $workflow = $db->protectIdentifiers($db->prefixTable('workflow_events'));
        $workflowAvailable = WorkflowNotifications::available($db);
        $query = $this->inbox($scope);
        if ($unreadOnly) $query->where('read_at', null);
        $rows = $query->select('id,type,created_at,read_at')
            ->select($workflowAvailable ? "(SELECT event_kind FROM {$workflow} WHERE tenant_id={$notifications}.tenant_id AND id={$notifications}.workflow_event_id) AS workflow_kind" : 'NULL AS workflow_kind', false)
            ->select($workflowAvailable ? "(SELECT unit FROM {$workflow} WHERE tenant_id={$notifications}.tenant_id AND id={$notifications}.workflow_event_id) AS workflow_unit" : 'NULL AS workflow_unit', false)
            ->select($this->teamsAvailable() ? 'team_unit' : 'NULL AS team_unit', false)
            ->select($scope['recipient_person_id'] === null ? 'NULL AS target_module'
                : "(SELECT target_module FROM {$referrals} WHERE tenant_id={$notifications}.tenant_id AND id={$notifications}.referral_id) AS target_module", false)
            ->select($scope['recipient_person_id'] === null ? 'NULL AS referral_status'
                : "(SELECT referral_row.status FROM {$referrals} referral_row WHERE referral_row.tenant_id={$notifications}.tenant_id AND referral_row.id={$notifications}.referral_id) AS referral_status", false)
            ->orderBy('id', 'DESC')->limit($limit, $offset)->get()->getResultArray();
        $unread = $this->inbox($scope)->select('id')->where('read_at', null)->limit(100)->get()->getResultArray();
        $destinations = self::destinationUrls();
        return ['items' => array_map(static function (array $row) use ($destinations): array {
            $row['created_at'] = NotificationTime::forDisplay($row['created_at']);
            $row['read_at'] = NotificationTime::forDisplay($row['read_at']);
            if ($row['type'] === 'workflow_alert') {
                $notice = WorkflowNotifications::presentation($row['workflow_kind'], $row['workflow_unit'], $destinations);
                return ['id' => (int) $row['id'], 'title' => $notice['title'], 'message' => $notice['message'],
                    'created_at' => $row['created_at'], 'read_at' => $row['read_at'], 'url' => $notice['url']];
            }
            return [
            'id' => (int) $row['id'],
            'title' => match ($row['type']) {
                            'referral_received' => 'Incoming referral',
                            'announcement_published' => 'New announcement',
                            'survey_published' => 'New survey',
                            default => self::TEAM_EVENT_TITLES[$row['type']] ?? self::TEAM_TASK_TITLES[$row['type']] ?? 'Appointment confirmed',
                        },
            'message' => match ($row['type']) {
                'referral_received' => 'A new referral is available in your incoming referrals inbox.',
                'announcement_published' => 'A campus announcement is available in your portal.',
                'survey_published' => 'An identified Guidance survey is available in your portal.',
                'team_event_added' => 'You have been included in a team event. Open the team calendar for details.',
                'team_event_removed' => 'Your participation in a team event has been removed. Open the team calendar for details.',
                'team_event_updated' => 'A team event has changed. Open the team calendar for details.',
                'team_event_cancelled' => 'A team event has been cancelled. Open the team calendar for details.',
                'team_task_assigned' => 'A team task has been assigned to you. Open Teams for details.',
                'team_task_unassigned' => 'A team task is no longer assigned to you. Open Teams for details.',
                'team_task_updated' => 'A team task has changed. Open Teams for details.',
                'team_task_status' => 'A team task status has changed. Open Teams for details.',
                'team_task_commented' => 'A comment was added to a team task. Open Teams for details.',
                default => 'Your appointment has been confirmed. Open your portal to see details.',
            },
            'created_at' => $row['created_at'], 'read_at' => $row['read_at'],
            'url' => match ($row['type']) {
                'referral_received' => $destinations[$row['referral_status'] === 'closed'
                                    ? ($row['target_module'] === 'clinic' ? 'clinicReferralHistory' : 'guidanceReferralHistory')
                                    : ($row['target_module'] === 'clinic' ? 'clinicReferrals' : 'guidanceReferrals')],
                'announcement_published' => $destinations['announcements'],
                'survey_published' => $destinations['surveys'],
                'team_event_added', 'team_event_removed', 'team_event_updated', 'team_event_cancelled'
                    => $destinations[$row['team_unit'] . 'TeamCalendar'],
                'team_task_assigned', 'team_task_unassigned', 'team_task_updated', 'team_task_status', 'team_task_commented'
                    => $destinations[$row['team_unit'] . 'Teams'],
                default => $destinations['portal'],
            },
            ];
        }, $rows), 'unread_count' => count($unread)];
    }

    public function markRead(int $id): void
    {
        $scope = $this->ownScope();
        if ($scope === null || $id <= 0 || $this->inbox($scope)->where('id', $id)->get()->getRowArray() === null) {
            throw new RuntimeException('Notification not found.', 404);
        }
        if (!$this->inbox($scope)->where('id', $id)->where('read_at', null)->update(['read_at' => gmdate('Y-m-d H:i:s')])) {
            throw new RuntimeException('Unable to mark notification read.');
        }
        if (Database::connect()->affectedRows() > 0) {
            $this->invalidateRead($scope, [$id]);
        }
    }

    public function markAllRead(): void
    {
        $scope = $this->ownScope();
        if ($scope === null) return;
        $ids = [];
        try {
            if (config(\Config\Realtime::class)->enabled && Database::connect()->transDepth === 0) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'],
                    $this->inbox($scope)->select('id')->where('read_at', null)->get()->getResultArray());
            }
        } catch (\Throwable) {
            // Hint collection is optional; preserve the original read-state write.
        }
        if (!$this->inbox($scope)->where('read_at', null)->update(['read_at' => gmdate('Y-m-d H:i:s')])) {
            throw new RuntimeException('Unable to mark notifications read.');
        }
        if ($ids !== [] && Database::connect()->affectedRows() > 0) {
            $this->invalidateRead($scope, $ids);
        }
    }

    private function invalidateRead(array $scope, array $ids): void
    {
        try {
            (new NotificationInvalidation())->publishCommitted($scope['tenant_id'], $ids);
        } catch (\Throwable) {
            // Optional realtime setup must not change the outcome of a committed write.
        }
    }

    /** Reuse the inbox authority, including current referral grants and publication audience. */
    public function canReceiveInvalidation(int $tenant, int $user, array $ids): bool
    {
        $actor = (new AuthenticatedUser())->forIdentity($user, $tenant);
        if ($actor === null || $ids === []) return false;
        return $this->inbox(['tenant_id' => $tenant, 'recipient_user_id' => $user,
            'recipient_person_id' => $actor['person_id'] === null ? null : (int) $actor['person_id']])->select('id')->whereIn('id', $ids)
            ->limit(1)->get()->getRowArray() !== null;
    }

    private function ownScope(): ?array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new RuntimeException('Please sign in to continue.', 401);
        }
        // AuthenticatedUser refreshes and verifies the same-tenant, live person link.
        return [
            'tenant_id' => (int) $actor['tenant_id'],
            'recipient_person_id' => $actor['person_id'] === null ? null : (int) $actor['person_id'],
                        'recipient_user_id' => (int) $actor['id'],
        ];
    }

    /** Older isolated fixtures/deployments must not acquire dependencies on absent Teams tables. */
    private function teamsAvailable(): bool
    {
        $db = Database::connect();
        if (array_diff(['team_unit', 'team_event_id', 'team_task_id', 'team_version', 'team_comment_id'],
            $db->getFieldNames('notifications')) !== []) return false;
        foreach (['team_events' => ['id', 'tenant_id', 'unit', 'version'],
            'team_tasks' => ['id', 'tenant_id', 'unit', 'version'],
            'team_task_comments' => ['id', 'tenant_id', 'unit', 'task_id']] as $table => $fields) {
            if (!$db->tableExists($table) || array_diff($fields, $db->getFieldNames($table)) !== []) return false;
        }
        return true;
    }

    private function inbox(array $scope): BaseBuilder
    {
        $db = Database::connect();
        $notifications = $db->protectIdentifiers($db->prefixTable('notifications'));
        $query = $db->table('notifications')->where('tenant_id', $scope['tenant_id'])->groupStart()->where('1=0', null, false);
        // A null person ID must never turn a personal-recipient comparison into IS NULL.
        // Break-glass identities may receive only explicitly addressed Teams notifications.
        if ($scope['recipient_person_id'] !== null) {
            $eligible = $this->receivingUsers($scope['tenant_id'])
                ->join('referrals r', 'r.tenant_id=u.tenant_id')
                ->join('people referral_patient', 'referral_patient.tenant_id=r.tenant_id AND referral_patient.id=r.patient_person_id AND referral_patient.deleted_at IS NULL')
                ->where('u.id', $scope['recipient_user_id'])
                ->where("r.id = {$notifications}.referral_id", null, false)
                ->groupStart()
                    ->groupStart()->where('r.target_module', 'clinic')->whereIn('u.role', self::departmentRoles('clinic'))->groupEnd()
                    ->orGroupStart()->where('r.target_module', 'guidance')->whereIn('u.role', self::departmentRoles('guidance'))->groupEnd()
                ->groupEnd()->getCompiledSelect();
            $announcements = EngagementNotifications::visibleQuery($scope, 'announcement');
            $surveys = EngagementNotifications::visibleQuery($scope, 'survey');
            $query->orGroupStart()->where('type', 'appointment_confirmed')
                    ->where('recipient_person_id', $scope['recipient_person_id'])
                    ->where('recipient_user_id', null)->where('referral_id', null)
                    ->where('announcement_id', null)->where('survey_id', null)->groupEnd()
                ->orGroupStart()->where('type', 'referral_received')->where('recipient_user_id', $scope['recipient_user_id'])
                    ->where('recipient_person_id', null)->where('appointment_id', null)->where('appointment_version', null)
                    ->where('announcement_id', null)->where('survey_id', null)
                    ->where("EXISTS ({$eligible})", null, false)->groupEnd()
                ->orGroupStart()->where('type', 'announcement_published')->where('recipient_person_id', $scope['recipient_person_id'])
                    ->where('recipient_user_id', null)->where('appointment_id', null)->where('appointment_version', null)
                    ->where('referral_id', null)->where('survey_id', null)->where("EXISTS ({$announcements})", null, false)->groupEnd()
                ->orGroupStart()->where('type', 'survey_published')->where('recipient_person_id', $scope['recipient_person_id'])
                    ->where('recipient_user_id', null)->where('appointment_id', null)->where('appointment_version', null)
                    ->where('referral_id', null)->where('announcement_id', null)->where("EXISTS ({$surveys})", null, false)->groupEnd();
        }
        if ($this->teamsAvailable()) {
            $this->teamsInbox($query, $scope, $notifications);
        }
        if (WorkflowNotifications::available($db)) {
            $events = (new WorkflowNotifications($db))->eligibleEvents($scope, $notifications)->getCompiledSelect();
            $query->orGroupStart()->where('type', 'workflow_alert')->where('appointment_id', null)->where('appointment_version', null)
                ->where('referral_id', null)->where('announcement_id', null)->where('survey_id', null)
                ->where('team_unit', null)->where('team_event_id', null)->where('team_task_id', null)
                ->where('team_version', null)->where('team_comment_id', null)
                ->where('EXISTS (' . $events . ')', null, false)->groupEnd();
        }
        return $query->groupEnd();
    }

    /** Same predicate for inbox, unread counts, read writes and realtime authorization. */
    private function teamsInbox(BaseBuilder $query, array $scope, string $notifications): void
    {
        $db = Database::connect();
        $recipient = $db->table('users u')->select('1', false)
            ->join('tenants t', 't.id=u.tenant_id')
            ->join('people p', 'p.tenant_id=u.tenant_id AND p.id=u.person_id AND p.deleted_at IS NULL', 'left')
            ->where('u.tenant_id', $scope['tenant_id'])->where('u.id', $scope['recipient_user_id'])
            ->where('u.status', 'active')->where('u.deleted_at', null)->where('t.is_active', 1)->where('t.deleted_at', null)
            ->groupStart()->where('p.id IS NOT NULL', null, false)
                ->orGroupStart()->where('u.person_id', null)->where('u.role', 'superadmin')->groupEnd()->groupEnd()
            ->groupStart();
        foreach (['clinic' => PortalContext::PORTAL_CLINIC, 'guidance' => PortalContext::PORTAL_GUIDANCE,
            'facilities' => PortalContext::PORTAL_BMG] as $unit => $portal) {
            $recipient->orGroupStart()->where("{$notifications}.team_unit", $unit)
                ->whereIn('u.role', PortalContext::allowedRoles($portal))->groupEnd();
        }
        $recipient = $recipient->groupEnd()->getCompiledSelect();
        $event = $db->table('team_events e')->select('1', false)
            ->where("e.tenant_id={$notifications}.tenant_id AND e.unit={$notifications}.team_unit
                AND e.id={$notifications}.team_event_id AND e.version>={$notifications}.team_version", null, false)->getCompiledSelect();
        $task = $db->table('team_tasks task')->select('1', false)
            ->where("task.tenant_id={$notifications}.tenant_id AND task.unit={$notifications}.team_unit
                AND task.id={$notifications}.team_task_id AND task.version>={$notifications}.team_version", null, false);
        $comment = (clone $task)->join('team_task_comments c', 'c.tenant_id=task.tenant_id AND c.unit=task.unit AND c.task_id=task.id')
            ->where("c.id={$notifications}.team_comment_id", null, false)->getCompiledSelect();
        $task = $task->getCompiledSelect();
        // Participation/assignment is intentionally not required: removal notices remain readable.
        $query->orGroupStart()->where('recipient_user_id', $scope['recipient_user_id'])
            ->where('recipient_person_id', null)->where('appointment_id', null)->where('appointment_version', null)
            ->where('referral_id', null)->where('announcement_id', null)->where('survey_id', null)
            ->where('team_version >', 0)->where("EXISTS ({$recipient})", null, false)
            ->groupStart()
                ->groupStart()->whereIn('type', array_keys(self::TEAM_EVENT_TITLES))->where('team_event_id >', 0)
                    ->where('team_task_id', null)->where('team_comment_id', null)->where("EXISTS ({$event})", null, false)->groupEnd()
                ->orGroupStart()->whereIn('type', ['team_task_assigned', 'team_task_unassigned', 'team_task_updated', 'team_task_status'])
                    ->where('team_task_id >', 0)->where('team_event_id', null)->where('team_comment_id', null)
                    ->where("EXISTS ({$task})", null, false)->groupEnd()
                ->orGroupStart()->where('type', 'team_task_commented')->where('team_task_id >', 0)->where('team_comment_id >', 0)
                    ->where('team_event_id', null)->where("EXISTS ({$comment})", null, false)->groupEnd()
            ->groupEnd()->groupEnd();
    }
}
