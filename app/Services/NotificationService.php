<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Database\BaseBuilder;
use Config\Database;
use RuntimeException;

final class NotificationService
{
    /** Fixed destinations shared by inbox JSON and its server-rendered client allowlist. */
    public static function destinationUrls(): array
    {
        return [
            // Resolve the host before appending a fragment: the server cannot route it.
            'portal' => PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal') . '#my-appointments',
            'clinicReferrals' => PortalContext::urlFor(PortalContext::PORTAL_CLINIC, 'clinic/referrals?tab=incoming'),
            'guidanceReferrals' => PortalContext::urlFor(PortalContext::PORTAL_GUIDANCE, 'guidance/referrals?tab=incoming'),
            'announcements' => PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal/announcements'),
            'surveys' => PortalContext::urlFor(PortalContext::PORTAL_MAIN, 'portal/surveys'),
        ];
    }
    /** Uses the caller's connection/transaction; never commits independently. */
    public function notifyConfirmation(array $appointment): void
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
            return;
        }
        if (!$db->table('notifications')->insert($key + ['recipient_person_id' => $stored['person_id'],
            'created_at' => date('Y-m-d H:i:s'), 'read_at' => null])) {
            throw new RuntimeException('Unable to save confirmation notification.');
        }
    }

    /** The referral row lock serializes delivery retries inside the create transaction. */
    public function notifyReferral(array $referral): void
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
                'created_at' => date('Y-m-d H:i:s'), 'read_at' => null])) {
                throw new RuntimeException('Unable to save referral notification.');
            }
        }
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
        $db = Database::connect();
                $referrals = $db->protectIdentifiers($db->prefixTable('referrals'));
                $notifications = $db->protectIdentifiers($db->prefixTable('notifications'));
                $rows = $this->inbox($scope)->select('id,type,created_at,read_at')
                    ->select("(SELECT target_module FROM {$referrals} WHERE tenant_id={$notifications}.tenant_id AND id={$notifications}.referral_id) AS target_module", false)
                    ->orderBy('id', 'DESC')->limit(10)->get()->getResultArray();
        $unread = $this->inbox($scope)->select('id')->where('read_at', null)->limit(100)->get()->getResultArray();
        $destinations = self::destinationUrls();
        return ['items' => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'title' => match ($row['type']) {
                            'referral_received' => 'Incoming referral',
                            'announcement_published' => 'New announcement',
                            'survey_published' => 'New survey',
                            default => 'Appointment confirmed',
                        },
            'message' => match ($row['type']) {
                'referral_received' => 'A new referral is available in your incoming referrals inbox.',
                'announcement_published' => 'A campus announcement is available in your portal.',
                'survey_published' => 'An identified Guidance survey is available in your portal.',
                default => 'Your appointment has been confirmed. Open your portal to see details.',
            },
            'created_at' => $row['created_at'], 'read_at' => $row['read_at'],
            'url' => match ($row['type']) {
                'referral_received' => $destinations[$row['target_module'] === 'clinic' ? 'clinicReferrals' : 'guidanceReferrals'],
                'announcement_published' => $destinations['announcements'],
                'survey_published' => $destinations['surveys'],
                default => $destinations['portal'],
            },
        ], $rows), 'unread_count' => count($unread)];
    }

    public function markRead(int $id): void
    {
        $scope = $this->ownScope();
        if ($scope === null || $id <= 0 || $this->inbox($scope)->where('id', $id)->get()->getRowArray() === null) {
            throw new RuntimeException('Notification not found.', 404);
        }
        if (!$this->inbox($scope)->where('id', $id)->where('read_at', null)->update(['read_at' => date('Y-m-d H:i:s')])) {
            throw new RuntimeException('Unable to mark notification read.');
        }
    }

    public function markAllRead(): void
    {
        $scope = $this->ownScope();
        if ($scope !== null && !$this->inbox($scope)->where('read_at', null)->update(['read_at' => date('Y-m-d H:i:s')])) {
            throw new RuntimeException('Unable to mark notifications read.');
        }
    }

    private function ownScope(): ?array
    {
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) {
            throw new RuntimeException('Please sign in to continue.', 401);
        }
        // AuthenticatedUser refreshes and verifies the same-tenant, live person link.
        return empty($actor['person_id']) ? null : [
            'tenant_id' => (int) $actor['tenant_id'], 'recipient_person_id' => (int) $actor['person_id'],
                        'recipient_user_id' => (int) $actor['id'],
        ];
    }

    private function inbox(array $scope): BaseBuilder
    {
        $db = Database::connect();
        $notifications = $db->protectIdentifiers($db->prefixTable('notifications'));
        $eligible = $this->receivingUsers($scope['tenant_id'])
            ->join('referrals r', 'r.tenant_id=u.tenant_id')
            ->where('u.id', $scope['recipient_user_id'])
            ->where("r.id = {$notifications}.referral_id", null, false)
            ->groupStart()
                ->groupStart()->where('r.target_module', 'clinic')->whereIn('u.role', self::departmentRoles('clinic'))->groupEnd()
                ->orGroupStart()->where('r.target_module', 'guidance')->whereIn('u.role', self::departmentRoles('guidance'))->groupEnd()
            ->groupEnd()->getCompiledSelect();
        $announcements = EngagementNotifications::visibleQuery($scope, 'announcement');
        $surveys = EngagementNotifications::visibleQuery($scope, 'survey');
        return $db->table('notifications')->where('tenant_id', $scope['tenant_id'])->groupStart()
            ->groupStart()->where('type', 'appointment_confirmed')
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
                ->where('referral_id', null)->where('announcement_id', null)->where("EXISTS ({$surveys})", null, false)->groupEnd()
            ->groupEnd();
    }
}
