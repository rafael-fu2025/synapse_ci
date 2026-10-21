<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AnnouncementService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\NotificationService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\InventoryTestDatabase;

/** Requires main's engagement services/migration; never runs against the default database. */
final class AnnouncementWorkflowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;
    protected $namespace = 'App';

    private AnnouncementService $service;
    private int $tenant;
    private array $admin;
    private array $employee;
    private array $student;
    private array $permissions = [];
    private array $grants = [];

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        $this->assertSame('tests', config('Database')->defaultGroup, 'Domain services must use the isolated tests group.');
        parent::setUp();
        $this->assertTrue($this->migrations->setNamespace('App')->latest('tests'));
        $this->service = new AnnouncementService();
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('announcement_test_'), 'name' => 'Announcement fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->admin = $this->account('clinic_admin', 'employee');
        $this->employee = $this->account('clinic_staff', 'employee');
        $this->student = $this->account('student', 'student');
        $this->grant('clinic_admin', 'clinic.announcements.manage');
        $this->grant('guidance_admin', 'guidance.announcements.manage');
        $this->grant('guidance_supervisor', 'guidance.announcements.manage');
        $this->as($this->admin);
    }

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            foreach (['notifications', 'announcements_reads', 'announcements', 'audit_events', 'student_profiles', 'employee_profiles', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $this->tenant)->delete();
            }
            $this->db->table('tenants')->where('id', $this->tenant)->delete();
        }
        foreach ($this->grants as [$role, $id, $existed]) {
            $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $id)->delete();
            if ($existed) { $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]); }
        }
        foreach ($this->permissions as $id) { $this->db->table('permissions')->where('id', $id)->delete(); }
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        Services::resetSingle('renderer');
        parent::tearDown();
    }

    private function account(string $role, string $profile): array
    {
        $person = (int) (new PersonModel())->insert(['tenant_id' => $this->tenant, 'kind' => $profile,
            'mis_id' => uniqid('announcement_person_'), 'first_name' => 'Announcement', 'last_name' => 'Tester']);
        $now = date('Y-m-d H:i:s');
        $this->db->table($profile . '_profiles')->insert(['tenant_id' => $this->tenant, 'person_id' => $person, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('announcement_user_'), 'role' => $role, 'status' => 'active']);
        return ['id' => $id, 'tenant_id' => $this->tenant, 'person_id' => $person, 'role' => 'superadmin', 'permissions' => ['*'], 'logged_in' => true];
    }

    private function as(array $actor): void
    {
        AuthenticatedUser::clear();
        session()->set(['user' => $actor, 'tenant_id' => $this->tenant]);
        CurrentTenant::set($this->tenant);
    }

    private function grant(string $role, string $code): int
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        if ($permission === null) {
            $this->db->table('permissions')->insert(['code' => $code, 'name' => 'Announcement test permission', 'module' => explode('.', $code)[0]]);
            $id = (int) $this->db->insertID();
            $this->permissions[] = $id;
        } else { $id = (int) $permission['id']; }
        $key = $role . ':' . $id;
        $existed = $this->db->table('role_permissions')->where(['role' => $role, 'permission_id' => $id])->countAllResults() > 0;
        $this->grants[$key] ??= [$role, $id, $existed];
        if (!$existed) { $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]); }
        return $id;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + ['title' => 'Campus notice', 'body' => 'Plain text notice, not a clinical record.', 'audience' => 'all', 'starts_at' => '', 'ends_at' => ''];
    }

    private function inputTime(int $offset): string
    {
        return (new \DateTimeImmutable('@' . (time() + $offset)))
            ->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d\TH:i');
    }

    private function published(array $overrides = [], string $unit = 'clinic'): array
    {
        $row = $this->service->create($unit, $this->payload($overrides));
        return $this->service->publish((int) $row['id'], $unit, $row['version']);
    }

    private function rejected(int $status, callable $operation): void
    {
        try { $operation(); $this->fail('Expected announcement rejection.'); }
        catch (RuntimeException $e) { $this->assertSame($status, $e->getCode(), $e->getMessage()); }
    }

    private function tenantCount(string $table): int
    {
        return $this->db->table($table)->where('tenant_id', $this->tenant)->countAllResults();
    }

    public function testDraftPublishImmutabilityArchiveAndOneNotificationFanout(): void
    {
        $row = $this->service->create('clinic', $this->payload(['status' => 'published', 'unit' => 'guidance', 'tenant_id' => 999]));
        $this->assertSame('draft', $row['status']);
        $this->assertSame('clinic', $row['unit']);
        $this->assertSame($this->tenant, (int) $row['tenant_id']);
        $this->assertSame(0, $this->tenantCount('notifications'));
        $row = $this->service->update((int) $row['id'], 'clinic', $this->payload(['title' => 'Updated', 'version' => $row['version']]));
        $this->assertSame('Updated', $row['title']);
        $published = $this->service->publish((int) $row['id'], 'clinic', $row['version']);
        $this->assertSame('published', $published['status']);
        $this->assertNotNull($published['published_at']);
        $notifications = $this->tenantCount('notifications');
        $this->assertGreaterThan(0, $notifications);
        $this->rejected(409, fn () => $this->service->publish((int) $row['id'], 'clinic', $published['version']));
        $this->rejected(409, fn () => $this->service->update((int) $row['id'], 'clinic', $this->payload(['version' => $published['version']])));
        $this->rejected(409, fn () => $this->service->draft((int) $row['id'], 'clinic'));
        $archived = $this->service->archive((int) $row['id'], 'clinic', $published['version']);
        $this->assertSame('archived', $archived['status']);
        $this->assertSame($notifications, $this->tenantCount('notifications'));
        $this->rejected(409, fn () => $this->service->publish((int) $row['id'], 'clinic', $archived['version']));
        $this->as($this->student);
        $this->rejected(404, fn () => $this->service->show((int) $row['id']));
    }

    public function testFreshRolesPermissionsDepartmentAndInactiveIdentity(): void
    {
        $row = $this->service->create('clinic', $this->payload());
        $this->as($this->employee);
        $this->grant('clinic_staff', 'clinic.announcements.manage');
        $this->rejected(403, fn () => $this->service->create('clinic', $this->payload()));
        $this->as($this->admin);
        $this->rejected(403, fn () => $this->service->listing('guidance'));
        $this->rejected(422, fn () => $this->service->listing('anything'));
        $permission = $this->grant('clinic_admin', 'clinic.announcements.manage');
        $this->db->table('role_permissions')->where(['role' => 'clinic_admin', 'permission_id' => $permission])->delete();
        $this->rejected(403, fn () => $this->service->publish((int) $row['id'], 'clinic', $row['version']));
        $this->grant('clinic_admin', 'clinic.announcements.manage');
        $this->db->table('users')->where('id', $this->admin['id'])->update(['role' => 'clinic_staff']);
        $this->rejected(403, fn () => $this->service->draft((int) $row['id'], 'clinic'));
        $this->db->table('users')->where('id', $this->admin['id'])->update(['status' => 'suspended']);
        $this->rejected(401, fn () => $this->service->listing('clinic'));
    }

    public function testGuidanceManagersAndExplicitSuperadminUnits(): void
    {
        foreach (['guidance_admin', 'guidance_supervisor', 'superadmin'] as $role) {
            $this->as($this->account($role, 'employee'));
            $row = $this->published([], 'guidance');
            $this->assertSame('guidance', $row['unit']);
            $this->rejected($role === 'superadmin' ? 404 : 403, fn () => $this->service->archive((int) $row['id'], 'clinic', $row['version']));
        }
    }

    public function testPortalUsesProfilesNotRolesAndIncludesBothDepartments(): void
    {
        $employeeNotice = $this->published(['audience' => 'employees']);
        $studentNotice = $this->published(['audience' => 'students']);
        $guidanceAdmin = $this->account('guidance_admin', 'employee');
        $this->as($guidanceAdmin);
        $guidanceNotice = $this->published([], 'guidance');
        $this->as($this->employee);
        $ids = array_column($this->service->portalListing()['announcements'], 'id');
        $this->assertContains($employeeNotice['id'], $ids);
        $this->assertContains($guidanceNotice['id'], $ids);
        $this->assertNotContains($studentNotice['id'], $ids);
        $this->rejected(404, fn () => $this->service->read((int) $studentNotice['id']));
        // An operational staff role with only a student profile is still a student audience.
        $studentStaff = $this->account('clinic_staff', 'student');
        $this->as($studentStaff);
        $this->assertSame($studentNotice['id'], $this->service->show((int) $studentNotice['id'])['id']);
        $this->rejected(404, fn () => $this->service->show((int) $employeeNotice['id']));
    }

    public function testManilaInputStoresUtcAndPublicationDoesNotConvertStoredDatesAgain(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $before = gmdate('Y-m-d H:i:s');
            $row = $this->service->create('clinic', $this->payload([
                'starts_at' => '2099-01-02T00:15', 'ends_at' => '2099-01-02T01:15',
            ]));
            $this->assertSame('2099-01-01 16:15:00', $row['starts_at']);
            $this->assertSame('2099-01-01 17:15:00', $row['ends_at']);
            $this->assertGreaterThanOrEqual($before, $row['created_at']);
            $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $row['created_at']);
            $row = $this->service->update((int) $row['id'], 'clinic', $this->payload([
                'version' => $row['version'], 'starts_at' => '2099-01-02T00:30', 'ends_at' => '2099-01-02T01:30',
            ]));
            $row = $this->service->publish((int) $row['id'], 'clinic', $row['version']);
            $this->assertSame('2099-01-01 16:30:00', $row['starts_at']);
            $this->assertSame('2099-01-01 17:30:00', $row['ends_at']);
            $this->assertGreaterThanOrEqual($before, $row['published_at']);
            $this->assertLessThanOrEqual(gmdate('Y-m-d H:i:s'), $row['published_at']);
            $this->as($this->student);
            $this->rejected(404, fn () => $this->service->show((int) $row['id']));
        } finally { date_default_timezone_set($previousTimezone); }
    }

    public function testAudienceUsesActualProfileRatherThanPersonKindOrCachedRole(): void
    {
        $employees = $this->published(['audience' => 'employees']);
        $students = $this->published(['audience' => 'students']);
        $this->db->table('people')->where('tenant_id', $this->tenant)->where('id', $this->employee['person_id'])->update(['kind' => 'student']);
        $this->db->table('people')->where('tenant_id', $this->tenant)->where('id', $this->student['person_id'])->update(['kind' => 'employee']);
        $this->as($this->employee);
        $this->assertSame($employees['id'], $this->service->show((int) $employees['id'])['id']);
        $this->rejected(404, fn () => $this->service->show((int) $students['id']));
        $this->as($this->student);
        $this->assertSame($students['id'], $this->service->show((int) $students['id'])['id']);
        $this->rejected(404, fn () => $this->service->show((int) $employees['id']));
    }

    public function testRemovingAnActualProfileImmediatelyRevokesAudienceAccessAndReadRights(): void
    {
        $notice = $this->published(['audience' => 'employees']);
        $this->as($this->employee);
        $this->service->read((int) $notice['id']);
        $this->db->table('employee_profiles')->where('tenant_id', $this->tenant)->where('person_id', $this->employee['person_id'])->delete();
        // The same session still has a staff role, but the audience membership has gone.
        $this->assertSame([], $this->service->portalListing()['announcements']);
        $before = $this->tenantCount('audit_events');
        $this->rejected(404, fn () => $this->service->show((int) $notice['id']));
        $this->rejected(404, fn () => $this->service->read((int) $notice['id']));
        $this->assertSame(1, $this->tenantCount('announcements_reads'));
        $this->assertSame($before, $this->tenantCount('audit_events'));
    }

    public function testNotificationRecipientsFollowProfilesNotOperationalRoles(): void
    {
        $employeeStudentRole = $this->account('student', 'employee');
        $studentStaffRole = $this->account('clinic_staff', 'student');
        $notice = $this->published(['audience' => 'employees', 'body' => 'PRIVATE NOTIFICATION BODY SENTINEL']);
        $rows = $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->where('announcement_id', $notice['id'])->get()->getResultArray();
        $recipients = array_map('intval', array_column($rows, 'recipient_person_id'));
        $this->assertContains($employeeStudentRole['person_id'], $recipients);
        $this->assertContains($this->employee['person_id'], $recipients);
        $this->assertNotContains($studentStaffRole['person_id'], $recipients);
        $this->assertNotContains($this->student['person_id'], $recipients);
        $this->assertSame(count($recipients), count(array_unique($recipients)));
        $this->assertStringNotContainsString('PRIVATE NOTIFICATION BODY SENTINEL', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function publicationNotification(array $notice, array $recipient): array
    {
        $row = $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->where('announcement_id', $notice['id'])->where('recipient_person_id', $recipient['person_id'])
            ->where('type', 'announcement_published')->get()->getRowArray();
        $this->assertNotNull($row, 'Publication must have delivered a personal bell notification.');
        return $row;
    }

    public function testPublicationBellPayloadIsGenericAndInboxesFollowOwnProfileAudience(): void
    {
        $private = ['title' => 'PRIVATE BELL TITLE SENTINEL', 'body' => 'PRIVATE BELL BODY SENTINEL'];
        $all = $this->published($private);
        $students = $this->published($private + ['audience' => 'students']);
        $this->as($this->account('guidance_admin', 'employee'));
        $employees = $this->published($private + ['audience' => 'employees'], 'guidance');
        $bell = new NotificationService();
        foreach ([[$this->student, $students, $employees], [$this->employee, $employees, $students]] as [$actor, $own, $other]) {
            $ownNotification = $this->publicationNotification($own, $actor);
            $sharedNotification = $this->publicationNotification($all, $actor);
            $this->as($actor);
            $inbox = $bell->ownInbox();
            $this->assertSame(2, $inbox['unread_count']);
            $this->assertCount(2, $inbox['items']);
            $this->assertEqualsCanonicalizing([(int) $ownNotification['id'], (int) $sharedNotification['id']], array_column($inbox['items'], 'id'));
            foreach ($inbox['items'] as $item) {
                $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($item));
                $this->assertSame('New announcement', $item['title']);
                $this->assertSame('A campus announcement is available in your portal.', $item['message']);
                $this->assertSame(site_url('/portal/announcements'), $item['url']);
                $this->assertNull($item['read_at']);
            }
            $json = json_encode($inbox, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($private['title'], $json);
            $this->assertStringNotContainsString($private['body'], $json);
            $this->assertSame(0, $this->db->table('notifications')->where('tenant_id', $this->tenant)
                ->where('announcement_id', $other['id'])->where('recipient_person_id', $actor['person_id'])->countAllResults());
        }
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
    }

    public function testPublicationBellHidesFutureExpiredAndArchivedAndRejectsMarkRead(): void
    {
        $visible = $this->published();
        $future = $this->published(['starts_at' => $this->inputTime(3600)]);
        $expired = $this->published(['ends_at' => $this->inputTime(3600)]);
        $archived = $this->published();
        $this->service->archive((int) $archived['id'], 'clinic', $archived['version']);
        $this->db->table('announcements')->where('tenant_id', $this->tenant)->where('id', $expired['id'])
            ->update(['ends_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $visibleNotification = $this->publicationNotification($visible, $this->student);
        $hidden = array_map(fn (array $notice): array => $this->publicationNotification($notice, $this->student), [$future, $expired, $archived]);
        $this->as($this->student);
        $bell = new NotificationService();
        $inbox = $bell->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame([(int) $visibleNotification['id']], array_column($inbox['items'], 'id'));
        foreach ($hidden as $notification) {
            $this->rejected(404, fn () => $bell->markRead((int) $notification['id']));
        }
        $bell->markAllRead();
        $this->assertSame(0, $bell->ownInbox()['unread_count']);
        $this->assertNotNull($this->publicationNotification($visible, $this->student)['read_at']);
        foreach ([$future, $expired, $archived] as $notice) {
            $this->assertNull($this->publicationNotification($notice, $this->student)['read_at'], 'Bulk read must not change hidden notification state.');
        }
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
    }

    public function testPublicationBellRejectsOtherRecipientsAndForeignTenantAccounts(): void
    {
        $notice = $this->published();
        $studentNotification = $this->publicationNotification($notice, $this->student);
        $employeeNotification = $this->publicationNotification($notice, $this->employee);
        $bell = new NotificationService();
        $this->as($this->employee);
        $this->assertSame([(int) $employeeNotification['id']], array_column($bell->ownInbox()['items'], 'id'));
        $this->rejected(404, fn () => $bell->markRead((int) $studentNotification['id']));
        $bell->markAllRead();
        $this->assertNotNull($this->publicationNotification($notice, $this->employee)['read_at']);
        $this->assertNull($this->publicationNotification($notice, $this->student)['read_at']);
        $original = $this->tenant;
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('announcement_bell_foreign_'), 'name' => 'Foreign bell fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreignTenant = (int) $this->db->insertID();
        try {
            $this->tenant = $foreignTenant;
            CurrentTenant::set($foreignTenant);
            $this->as($this->account('student', 'student'));
            $this->assertSame(['items' => [], 'unread_count' => 0], $bell->ownInbox());
            $this->rejected(404, fn () => $bell->markRead((int) $studentNotification['id']));
            $bell->markAllRead();
        } finally {
            $this->tenant = $original;
            $this->as($this->student);
            foreach (['student_profiles', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $foreignTenant)->delete();
            }
            $this->db->table('tenants')->where('id', $foreignTenant)->delete();
        }
        $this->assertNull($this->publicationNotification($notice, $this->student)['read_at']);
        $this->assertSame(1, $bell->ownInbox()['unread_count']);
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
    }

    public function testPublicationBellRevokedProfileHidesAndCannotBeMarkedRead(): void
    {
        $notice = $this->published(['audience' => 'employees']);
        $notification = $this->publicationNotification($notice, $this->employee);
        $this->as($this->employee);
        $bell = new NotificationService();
        $this->assertSame(1, $bell->ownInbox()['unread_count']);
        $this->db->table('employee_profiles')->where('tenant_id', $this->tenant)->where('person_id', $this->employee['person_id'])->delete();
        $this->assertSame(['items' => [], 'unread_count' => 0], $bell->ownInbox());
        $this->rejected(404, fn () => $bell->markRead((int) $notification['id']));
        $bell->markAllRead();
        $this->assertNull($this->publicationNotification($notice, $this->employee)['read_at']);
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
    }

    public function testPublicationBellReadStateIsPersonalAndSeparateFromAnnouncementReadMarkers(): void
    {
        $first = $this->published();
        $second = $this->published();
        $notification = $this->publicationNotification($first, $this->student);
        $this->as($this->student);
        $bell = new NotificationService();
        $bell->markRead((int) $notification['id']);
        $readAt = $this->publicationNotification($first, $this->student)['read_at'];
        $this->assertNotNull($readAt);
        $bell->markRead((int) $notification['id']);
        $this->assertSame($readAt, $this->publicationNotification($first, $this->student)['read_at']);
        $this->assertSame(1, $bell->ownInbox()['unread_count']);
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
        $this->assertNull($this->service->show((int) $first['id'])['read_at']);
        $bell->markAllRead();
        $this->assertSame(0, $bell->ownInbox()['unread_count']);
        $this->assertNotNull($this->publicationNotification($second, $this->student)['read_at']);
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
        $this->assertNull($this->service->show((int) $second['id'])['read_at']);
        $this->as($this->employee);
        $this->assertSame(2, $bell->ownInbox()['unread_count']);
        foreach ([$first, $second] as $notice) {
            $this->assertNull($this->publicationNotification($notice, $this->employee)['read_at']);
        }
        $this->as($this->student);
        $this->service->read((int) $first['id']);
        $this->assertSame(1, $this->tenantCount('announcements_reads'));
        $this->assertNull($this->service->show((int) $second['id'])['read_at']);
    }

    public function testPublicationBellMarkAllReadIncludesItemsBeyondThePreviewAndOnlyOwnRecipient(): void
    {
        for ($i = 0; $i < 12; $i++) { $this->published(); }
        $this->as($this->student);
        $bell = new NotificationService();
        $inbox = $bell->ownInbox();
        $this->assertCount(10, $inbox['items']);
        $this->assertSame(12, $inbox['unread_count']);
        $bell->markAllRead();
        $this->assertSame(0, $bell->ownInbox()['unread_count']);
        $this->assertSame(12, $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->where('type', 'announcement_published')->where('recipient_person_id', $this->student['person_id'])
            ->where('read_at IS NOT NULL', null, false)->countAllResults());
        $bell->markAllRead();
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
        $this->as($this->employee);
        $this->assertSame(12, $bell->ownInbox()['unread_count']);
        $this->assertSame(12, $this->db->table('notifications')->where('tenant_id', $this->tenant)
            ->where('type', 'announcement_published')->where('recipient_person_id', $this->employee['person_id'])
            ->where('read_at', null)->countAllResults());
    }

    public function testReadHistoryRequiresOwnDepartmentManagementAndReturnsMinimalFields(): void
    {
        $notice = $this->published();
        $this->as($this->student);
        $this->service->read((int) $notice['id']);
        $this->rejected(403, fn () => $this->service->reads((int) $notice['id'], 'clinic'));
        $this->as($this->employee);
        $this->rejected(403, fn () => $this->service->reads((int) $notice['id'], 'clinic'));
        $this->as($this->account('guidance_admin', 'employee'));
        $this->rejected(404, fn () => $this->service->reads((int) $notice['id'], 'guidance'));
        $this->as($this->admin);
        $history = $this->service->reads((int) $notice['id'], 'clinic');
        $this->assertCount(1, $history['reads']);
        $keys = array_keys($history['reads'][0]);
        sort($keys);
        $this->assertSame(['first_name', 'id', 'last_name', 'read_at'], $keys);
    }

    public function testVisibilityWindowsAndReadIdempotenceWithoutGetMutation(): void
    {
        $visible = $this->published();
        $future = $this->published(['starts_at' => $this->inputTime(3600)]);
        $expired = $this->published(['ends_at' => $this->inputTime(3600)]);
        $draft = $this->service->create('clinic', $this->payload());
        $this->db->table('announcements')->where('id', $expired['id'])->update(['ends_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->as($this->student);
        $before = $this->tenantCount('audit_events');
        $this->service->portalListing();
        $this->assertNull($this->service->show((int) $visible['id'])['read_at']);
        $this->assertSame(0, $this->tenantCount('announcements_reads'));
        $this->assertSame($before, $this->tenantCount('audit_events'));
        foreach ([$future, $expired, $draft] as $hidden) {
            $this->rejected(404, fn () => $this->service->show((int) $hidden['id']));
            $this->rejected(404, fn () => $this->service->read((int) $hidden['id']));
        }
        $read = $this->service->read((int) $visible['id']);
        $this->assertNotNull($read['read_at']);
        $this->assertSame($read['read_at'], $this->service->read((int) $visible['id'])['read_at']);
        $this->assertSame(1, $this->tenantCount('announcements_reads'));
        $this->as($this->employee);
        $this->assertNull($this->service->show((int) $visible['id'])['read_at']);
    }

    public function testValidationStaleVersionsAndCallerOwnedTransactions(): void
    {
        foreach ([['title' => []], ['title' => str_repeat('x', 161)], ['body' => ' '], ['body' => str_repeat('x', 10001)],
            ['body' => "bad\0text"], ['audience' => ['all']], ['audience' => 'staff'], ['starts_at' => '2026-02-30T12:00'],
                        ['starts_at' => '2026-10-20 12:00:00'], ['starts_at' => '2026-10-20T12:00Z'], ['starts_at' => '1969-12-31T12:00'],
            ['starts_at' => '2026-10-20T12:00', 'ends_at' => '2026-10-20T11:00']] as $invalid) {
            $this->rejected(422, fn () => $this->service->create('clinic', $this->payload($invalid)));
        }
        $this->assertSame(0, $this->tenantCount('announcements'));
        $row = $this->service->create('clinic', $this->payload());
        $updated = $this->service->update((int) $row['id'], 'clinic', $this->payload(['version' => $row['version']]));
        $this->rejected(409, fn () => $this->service->publish((int) $row['id'], 'clinic', $row['version']));
        $this->rejected(409, fn () => $this->service->archive((int) $row['id'], 'clinic', []));
        $this->db->transBegin();
        try {
            $this->rejected(409, fn () => $this->service->publish((int) $row['id'], 'clinic', $updated['version']));
            $this->assertSame(1, $this->db->transDepth);
        } finally { $this->db->transRollback(); }
    }

    public function testTenantScopeAndAuditContainsNoContent(): void
    {
        $row = $this->published(['title' => 'PRIVATE TITLE SENTINEL', 'body' => 'PRIVATE BODY SENTINEL']);
        $audit = $this->db->table('audit_events')->where('tenant_id', $this->tenant)->get()->getResultArray();
        $json = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE TITLE SENTINEL', $json);
        $this->assertStringNotContainsString('PRIVATE BODY SENTINEL', $json);
        // Even a valid superadmin in a different tenant cannot address this notice.
        $original = $this->tenant;
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('announcement_other_'), 'name' => 'Foreign fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $foreignTenant = (int) $this->db->insertID();
        try {
            $this->tenant = $foreignTenant;
            CurrentTenant::set($foreignTenant);
            $foreign = $this->account('superadmin', 'employee');
            $this->as($foreign);
            $this->assertSame(0, $this->service->listing('clinic')['pagination']['total']);
            $this->assertSame(0, $this->service->portalListing()['pagination']['total']);
            $this->rejected(404, fn () => $this->service->show((int) $row['id']));
            $this->rejected(404, fn () => $this->service->read((int) $row['id']));
            $this->rejected(404, fn () => $this->service->draft((int) $row['id'], 'clinic'));
            $this->rejected(404, fn () => $this->service->archive((int) $row['id'], 'clinic', $row['version']));
        } finally {
            $this->tenant = $original;
            $this->as($this->admin);
            foreach (['employee_profiles', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $foreignTenant)->delete();
            }
            $this->db->table('tenants')->where('id', $foreignTenant)->delete();
        }
    }

    public function testMigrationRestartPreservesContentAndReadHistoryAndRefusesRollback(): void
    {
        $notice = $this->published();
        $this->as($this->student);
        $read = $this->service->read((int) $notice['id']);
        require_once APPPATH . 'Database/Migrations/2026-10-17-000001_CreateAnnouncements.php';
        $migration = new \App\Database\Migrations\CreateAnnouncements(\Config\Database::forge('tests'));
        $migration->up();
        $migration->up();
        $this->assertSame($notice['body'], $this->service->show((int) $notice['id'])['body']);
        $this->assertSame($read['read_at'], $this->service->show((int) $notice['id'])['read_at']);
        try { $migration->down(); $this->fail('Forward-only migration must refuse rollback.'); }
        catch (RuntimeException $e) { $this->assertStringContainsString('forward-only', $e->getMessage()); }
        $this->assertSame(1, $this->tenantCount('announcements'));
        $this->assertSame(1, $this->tenantCount('announcements_reads'));
    }

    public function testStaffPortalAndReadHistoryPaginationAreFixedAt25(): void
    {
        for ($i = 0; $i < 26; $i++) { $this->published(['title' => 'Notice ' . $i]); }
        $this->assertCount(25, $this->service->listing('clinic')['announcements']);
        $this->assertCount(1, $this->service->listing('clinic', 2)['announcements']);
        $this->as($this->student);
        $this->assertCount(25, $this->service->portalListing()['announcements']);
        $this->assertCount(1, $this->service->portalListing(2)['announcements']);
        $this->rejected(422, fn () => $this->service->portalListing(['1']));
        $notice = $this->service->portalListing()['announcements'][0];
        $this->service->read((int) $notice['id']);
        for ($i = 0; $i < 25; $i++) {
            $this->as($this->account('student', 'student'));
            $this->service->read((int) $notice['id']);
        }
        $this->as($this->admin);
        $history = $this->service->reads((int) $notice['id'], 'clinic');
        $this->assertSame(25, $history['pagination']['per_page']);
        $this->assertSame(26, $history['pagination']['total']);
        $this->assertCount(25, $history['reads']);
        $this->assertCount(1, $this->service->reads((int) $notice['id'], 'clinic', 2)['reads']);
    }
}
