<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\NotificationService;
use RuntimeException;

/** Synthetic fixtures shared only by the guarded publication workflow tests. */
trait PublicationRecipientFixtures
{
    private function establishPublicationSession(array $actor): void
    {
        AuthenticatedUser::clear();
        $authority = new AuthenticatedUser();
        $identity = $authority->forIdentity((int) $actor['id'], (int) $actor['tenant_id']);
        $this->assertNotNull($identity, 'Login fixture must resolve a live DB identity.');
        $now = time();
        // Match AuthService's establishment contract, but retain forged cached grants
        // until current() proves that DB authority replaces them rather than merging.
        session()->set(['user' => $actor, 'tenant_id' => $identity['tenant_id'], '_auth' => [
            'started_at' => $now, 'last_seen_at' => $now,
            'version' => $identity['auth_version'], 'session_id' => session_id(),
        ]]);
        $this->assertSame($identity, $authority->current());
        $this->assertSame($identity, session('user'));
    }

    protected function publicationRecipientMatrix(callable $account): array
    {
        $cases = [];
        foreach (['clinic_admin', 'clinic_staff', 'guidance_admin', 'guidance_supervisor', 'counsellor', 'bmg_admin', 'facilities_op'] as $role) {
            foreach (['employee', 'student', 'both', 'none'] as $profile) {
                $actor = $account($role, $profile === 'student' ? 'student' : 'employee');
                if ($profile === 'both') {
                    $now = date('Y-m-d H:i:s');
                    $this->db->table('student_profiles')->insert(['tenant_id' => $this->tenant,
                        'person_id' => $actor['person_id'], 'created_at' => $now, 'updated_at' => $now]);
                } elseif ($profile === 'none') {
                    $this->db->table('employee_profiles')->where('tenant_id', $this->tenant)
                        ->where('person_id', $actor['person_id'])->delete();
                }
                $cases[$role . '-' . $profile] = ['actor' => $actor, 'profile' => $profile, 'active' => true];
            }
        }
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => null,
            'username' => uniqid('publication_breakglass_'), 'role' => 'superadmin', 'status' => 'active']);
        $cases['personless-superadmin'] = ['actor' => ['id' => $id, 'tenant_id' => $this->tenant,
            'person_id' => null, 'role' => 'superadmin', 'permissions' => ['*'], 'logged_in' => true],
            'profile' => 'none', 'active' => true];
        foreach (['suspended', 'deleted-user', 'deleted-person'] as $state) {
            $actor = $account('facilities_op', 'employee');
            $table = $state === 'deleted-person' ? 'people' : 'users';
            $this->db->table($table)->where('tenant_id', $this->tenant)
                ->where('id', $table === 'people' ? $actor['person_id'] : $actor['id'])
                ->update($state === 'suspended' ? ['status' => 'suspended'] : ['deleted_at' => date('Y-m-d H:i:s')]);
            $cases[$state] = ['actor' => $actor, 'profile' => 'employee', 'active' => false];
        }
        return $cases;
    }

    protected function assertPublicationRecipientMatrix(string $kind, int $id, string $audience, array $cases,
        callable $login, callable $open): void
    {
        $rows = $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->where($kind . '_id', $id)->get()->getResultArray();
        $people = array_map('intval', array_column($rows, 'recipient_person_id'));
        $this->assertSame(count($people), count(array_unique($people)), 'Both profiles must not duplicate delivery.');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertNull($row['recipient_user_id']);
            $this->assertSame($kind . '_published', $row['type']);
        }
        $this->assertStringNotContainsString('SYNTHETIC PRIVATE', json_encode($rows, JSON_THROW_ON_ERROR));
        $bell = new NotificationService();
        foreach ($cases as $label => $case) {
            $actor = $case['actor'];
            $eligible = $case['active'] && ($case['profile'] === 'both'
                || ($audience === 'all' && $case['profile'] !== 'none')
                || ($audience === 'employees' && $case['profile'] === 'employee')
                || ($audience === 'students' && $case['profile'] === 'student'));
            $owned = array_values(array_filter($rows, static fn (array $row): bool =>
                $actor['person_id'] !== null && (int) $row['recipient_person_id'] === (int) $actor['person_id']));
            $this->assertCount($eligible ? 1 : 0, $owned, $label . ': fanout must follow actual profiles, never role.');
            if (!$case['active']) {
                $this->assertNull((new AuthenticatedUser())->forIdentity((int) $actor['id'], (int) $actor['tenant_id']));
                continue;
            }
            $login($actor);
            $inbox = $bell->ownInbox();
            $this->assertSame($eligible ? 1 : 0, $inbox['unread_count'], $label);
            $this->assertSame(array_map('intval', array_column($owned, 'id')), array_column($inbox['items'], 'id'), $label);
            $this->assertStringNotContainsString('SYNTHETIC PRIVATE', json_encode($inbox, JSON_THROW_ON_ERROR));
            if ($eligible) {
                $open();
                $item = $inbox['items'][0];
                $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($item));
                $this->assertSame($kind === 'survey' ? 'New survey' : 'New announcement', $item['title']);
                $this->assertSame(NotificationService::destinationUrls()[$kind === 'survey' ? 'surveys' : 'announcements'], $item['url']);
                $this->assertNull($item['read_at']);
                $bell->markRead($item['id']);
                $read = $bell->ownInbox();
                $this->assertSame(0, $read['unread_count']);
                $this->assertNotNull($read['items'][0]['read_at']);
                $bell->markRead($item['id']);
                $this->assertSame($read, $bell->ownInbox(), 'Read retry must be idempotent.');
            } else {
                try { $open(); $this->fail($label . ': ineligible content must be hidden.'); }
                catch (RuntimeException $e) {
                    // Survey forms explicitly forbid personless break-glass identities;
                    // profile-bearing identities outside the audience receive not-found.
                    $status = $kind === 'survey' && $actor['person_id'] === null ? 403 : 404;
                    $this->assertSame($status, $e->getCode(), $e->getMessage());
                }
            }
            $foreign = (int) $rows[0]['id'];
            if (!$eligible || $foreign !== (int) $owned[0]['id']) {
                $before = $this->db->table('notifications')->where('id', $foreign)->get()->getRowArray();
                try { $bell->markRead($foreign); $this->fail($label . ': another recipient must be hidden.'); }
                catch (RuntimeException $e) { $this->assertSame(404, $e->getCode(), $e->getMessage()); }
                $this->assertSame($before, $this->db->table('notifications')->where('id', $foreign)->get()->getRowArray());
            }
            $bell->markAllRead();
            $this->assertSame(0, $bell->ownInbox()['unread_count']);
        }
    }
}
