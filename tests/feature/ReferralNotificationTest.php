<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\MisClientInterface;
use App\Models\AppointmentModel;
use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\NotificationService;
use App\Services\PortalContext;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

/** Integration only; run through run-fresh-database-tests.py, never the main schema. */
final class ReferralNotificationTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private array $tenants = [];
    private int $tenant;
    private int $patient;
    private array $clinic;
    private array $guidance;
    private ?string $trigger = null;

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects($this->never())->method($method);
        }
        Services::injectMock('misClient', $mis);
        $this->tenant = $this->tenant();
        CurrentTenant::set($this->tenant);
        $this->patient = $this->person();
        $this->clinic = $this->account('clinic_staff');
        $this->guidance = $this->account('counsellor');
    }

    protected function tearDown(): void
    {
        if ($this->trigger !== null) {
            $this->db->query('DROP TRIGGER IF EXISTS ' . $this->db->protectIdentifiers($this->trigger));
        }
        foreach ($this->tenants as $tenant) {
            foreach (['notifications', 'workflow_events', 'referrals', 'appointments', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $tenant)->delete();
            }
            $this->db->table('tenants')->where('id', $tenant)->delete();
        }
        Services::resetSingle('misClient');
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function tenant(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('ref_notice_'), 'name' => 'Notification fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->tenants[] = $id;
        return $id;
    }

    private function person(?int $tenant = null): int
    {
        return (int) (new PersonModel())->insert(['tenant_id' => $tenant ?? $this->tenant, 'kind' => 'employee',
            'mis_id' => uniqid('ref_notice_person_'), 'first_name' => 'SECRET', 'last_name' => 'PRIVATE',
            'email' => 'private@example.test', 'address' => 'SECRET ADDRESS']);
    }

    private function account(string $role, array $overrides = []): array
    {
        $id = (int) (new UserModel())->insert($overrides + ['tenant_id' => $this->tenant,
            'person_id' => $this->person($overrides['tenant_id'] ?? $this->tenant), 'username' => uniqid('ref_notice_user_'), 'role' => $role, 'status' => 'active']);
        return ['id' => $id, 'tenant_id' => $overrides['tenant_id'] ?? $this->tenant,
            'logged_in' => true, 'role' => 'superadmin', 'permissions' => ['*']];
    }

    private function login(array $actor): void
    {
        $version = (int) $this->db->table('users')->select('auth_version')->where('id', $actor['id'])->get()->getRowArray()['auth_version'];
        session()->set(['tenant_id' => $actor['tenant_id'], 'user' => $actor, '_auth' => [
            'started_at' => time(), 'last_seen_at' => time(), 'version' => $version, 'session_id' => session_id(),
        ]]);
        CurrentTenant::set((int) $actor['tenant_id']);
    }

    private function create(bool $reverse = false, array $overrides = []): array
    {
        CurrentTenant::set($this->tenant);
        $actor = $reverse ? $this->guidance : $this->clinic;
        return (new ReferralService())->createReferral($overrides + ['patient_person_id' => $this->patient,
            'issuer_user_id' => $actor['id'], 'reason_code' => $reverse ? 'medical_assessment' : 'academic_distress',
            'reason_description' => 'SECRET clinical reason', 'request_key' => bin2hex(random_bytes(32))], $actor);
    }

    private function notices(): array
    {
        return $this->db->table('notifications')->where('tenant_id', $this->tenant)->orderBy('id')->get()->getResultArray();
    }

    private function rejected(int $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected notification access rejection.');
        } catch (RuntimeException $e) {
            $this->assertSame($code, $e->getCode(), $e->getMessage());
        }
    }

    public function testPrivateDeliveryDedupeOwnershipAndBothDirections(): void
    {
        $admin = $this->account('superadmin');
        $personless = $this->account('superadmin', ['person_id' => null]);
        $this->account('guidance_admin', ['status' => 'suspended']);
        $bmg = $this->account('bmg_admin');
        $deleted = $this->account('guidance_supervisor');
        $this->db->table('users')->where('id', $deleted['id'])->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $invalidPerson = $this->account('guidance_supervisor');
        $personId = $this->db->table('users')->where('id', $invalidPerson['id'])->get()->getRowArray()['person_id'];
        $this->db->table('people')->where('id', $personId)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $this->account('guidance_admin', ['person_id' => null]);
        $otherTenant = $this->account('counsellor', ['tenant_id' => $this->tenant()]);
        $this->account('guidance_admin', ['person_id' => $this->person((int) $otherTenant['tenant_id'])]);
        $key = bin2hex(random_bytes(32));
        $ref = $this->create(false, ['request_key' => $key]);
        $this->create(false, ['request_key' => $key]);
        $this->db->transBegin();
        try {
            (new NotificationService())->notifyReferral($ref);
            (new NotificationService())->notifyReferral($ref);
            $this->assertTrue($this->db->transCommit());
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        $rows = $this->notices();
        $this->assertCount(1, $rows);
        $this->assertSame((int) $this->guidance['id'], (int) $rows[0]['recipient_user_id']);
        foreach (['recipient_person_id', 'appointment_id', 'appointment_version'] as $column) {
            $this->assertNull($rows[0][$column]);
        }
        $this->assertStringNotContainsString('SECRET', json_encode($rows));
        $this->assertStringNotContainsString('reason', json_encode($rows));
        $service = new NotificationService();
        $late = $this->account('counsellor'); // Same department, but not an original recipient.
        foreach ([$this->clinic, $admin, $personless, $otherTenant, $late, $bmg] as $actor) {
            $this->login($actor);
            $this->assertSame(['items' => [], 'unread_count' => 0], $service->ownInbox());
            $this->rejected(404, fn () => $service->markRead((int) $rows[0]['id']));
            $service->markAllRead();
        }
        $this->login($this->guidance);
        $inbox = $service->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($inbox['items'][0]));
        $this->assertSame('Incoming referral', $inbox['items'][0]['title']);
        $this->assertSame(site_url('/guidance/referrals?tab=incoming'), $inbox['items'][0]['url']);
        $this->assertStringNotContainsString('SECRET', json_encode($inbox));
        $service->markRead((int) $rows[0]['id']);
        $this->assertSame(0, $service->ownInbox()['unread_count']);
        $this->create(true);
        $this->login($this->clinic);
        $this->assertSame(site_url('/clinic/referrals?tab=incoming'), $service->ownInbox()['items'][0]['url']);
    }

    public static function referralDirections(): array
    {
        return ['clinic to guidance' => [false], 'guidance to clinic' => [true]];
    }

    #[DataProvider('referralDirections')]
    public function testClosedReceivedNoticeRetainsHistoryReadStateAndInvalidation(bool $reverse): void
    {
        $ref = $this->create($reverse);
        $receiver = $reverse ? $this->clinic : $this->guidance;
        $sender = $reverse ? $this->guidance : $this->clinic;
        $unit = $ref['target_module'];
        $portal = $reverse ? PortalContext::PORTAL_CLINIC : PortalContext::PORTAL_GUIDANCE;
        $historyKey = $reverse ? 'clinicReferralHistory' : 'guidanceReferralHistory';
        $historyUrl = PortalContext::urlFor($portal, '/' . $unit . '/referrals?tab=history');
        $this->assertSame($historyUrl, NotificationService::destinationUrls()[$historyKey]);
        $notice = $this->notices()[0];
        $id = (int) $notice['id'];
        $service = new NotificationService();
        $referrals = new ReferralService();
        $this->login($receiver);

        foreach (['submitted', 'acknowledged', 'under_review', 'closed'] as $status) {
            match ($status) {
                'acknowledged' => $referrals->acknowledge((int) $ref['id'], (int) $receiver['id'], $receiver),
                'under_review' => $referrals->review((int) $ref['id'], (int) $receiver['id'], $receiver),
                'closed' => $referrals->close((int) $ref['id'], $receiver),
                default => null,
            };
            $inbox = $service->ownInbox();
            $this->assertSame(1, $inbox['unread_count']);
            $this->assertCount(1, $inbox['items']);
            $item = $inbox['items'][0];
            $this->assertSame($id, $item['id']);
            $this->assertNull($item['read_at']);
            $this->assertSame($status === 'closed' ? $historyUrl
                : PortalContext::urlFor($portal, '/' . $unit . '/referrals?tab=incoming'), $item['url']);
            $this->assertSame(['id', 'title', 'message', 'created_at', 'read_at', 'url'], array_keys($item));
            $this->assertStringNotContainsString('SECRET', json_encode($inbox));
            $this->assertTrue($service->canReceiveInvalidation($this->tenant, (int) $receiver['id'], [$id]));
            $this->assertFalse($service->canReceiveInvalidation($this->tenant, (int) $sender['id'], [$id]));
            $this->assertSame([$notice], $this->notices(), 'Transitions must preserve the original received notice.');
        }

        $service->markRead($id);
        $inbox = $service->ownInbox();
        $this->assertSame(0, $inbox['unread_count']);
        $this->assertSame($historyUrl, $inbox['items'][0]['url']);
        $this->assertNotNull($inbox['items'][0]['read_at']);
        $this->assertTrue($service->canReceiveInvalidation($this->tenant, (int) $receiver['id'], [$id]));
        $readNotice = $this->notices()[0];
        $service->markRead($id);
        $service->markAllRead();
        $this->assertSame([$readNotice], $this->notices());
    }

    #[DataProvider('referralDirections')]
    public function testDeletedReferredPatientSuppressesOpenAndClosedNoticesWithoutMarkingThem(bool $reverse): void
    {
        $open = $this->create($reverse);
        $closed = $this->create($reverse);
        $receiver = $reverse ? $this->clinic : $this->guidance;
        $referrals = new ReferralService();
        $referrals->acknowledge((int) $closed['id'], (int) $receiver['id'], $receiver);
        $referrals->close((int) $closed['id'], $receiver);
        $visible = $this->create($reverse, ['patient_person_id' => $this->person()]);
        $rows = $this->notices();
        $hiddenIds = [(int) $rows[0]['id'], (int) $rows[1]['id']];
        $visibleId = (int) $rows[2]['id'];
        $service = new NotificationService();
        $this->login($receiver);
        $this->assertSame(3, $service->ownInbox()['unread_count']);
        foreach ($hiddenIds as $id) {
            $this->assertTrue($service->canReceiveInvalidation($this->tenant, (int) $receiver['id'], [$id]));
        }

        $this->db->table('people')->where('tenant_id', $this->tenant)->where('id', $this->patient)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
        $inbox = $service->ownInbox();
        $this->assertSame(1, $inbox['unread_count']);
        $this->assertSame([$visibleId], array_column($inbox['items'], 'id'));
        $this->assertStringNotContainsString('SECRET', json_encode($inbox));
        foreach ($hiddenIds as $id) {
            $this->assertFalse($service->canReceiveInvalidation($this->tenant, (int) $receiver['id'], [$id]));
            $this->rejected(404, fn () => $service->markRead($id));
        }
        $this->assertFalse($service->canReceiveInvalidation($this->tenant, (int) $receiver['id'], $hiddenIds));
        $this->assertTrue($service->canReceiveInvalidation($this->tenant, (int) $receiver['id'], [$visibleId]));
        $service->markAllRead();
        $after = $this->notices();
        $this->assertSame($rows[0], $after[0]);
        $this->assertSame($rows[1], $after[1]);
        $this->assertNotNull($after[2]['read_at']);
        $this->assertSame(0, $service->ownInbox()['unread_count']);
        $this->assertStringNotContainsString('SECRET', json_encode($after));

        $this->db->table('people')->where('tenant_id', $this->tenant)->where('id', $this->patient)->update(['deleted_at' => null]);
        $restored = $service->ownInbox();
        $this->assertSame(2, $restored['unread_count']);
        $this->assertCount(3, $restored['items']);
        $this->assertSame([$rows[0], $rows[1], $after[2]], $this->notices(), 'Suppression must not erase history or read state.');
    }

    public function testBmgAndForgedSourceCannotCreateNotifications(): void
    {
        $bmg = $this->account('bmg_admin');
        $data = ['patient_person_id' => $this->patient, 'issuer_user_id' => $bmg['id'],
            'source_module' => 'clinic', 'target_module' => 'guidance', 'reason_code' => 'academic_distress'];
        $this->rejected(403, fn () => (new ReferralService())->createReferral($data, $bmg));
        $this->rejected(403, fn () => $this->create(false, ['source_module' => 'guidance']));
        $this->assertSame([], $this->notices());
    }

    public function testFreshRevocationDepartmentAndPersonValidityApplyToReadsAndMarks(): void
    {
        $this->create();
        $row = $this->notices()[0];
        $service = new NotificationService();
        $this->login($this->guidance);
        $this->assertSame(1, $service->ownInbox()['unread_count']);
        foreach (['clinic_staff', 'superadmin', 'employee'] as $role) {
            $this->db->table('users')->where('id', $this->guidance['id'])->update(['role' => $role]);
            $this->assertSame(['items' => [], 'unread_count' => 0], $service->ownInbox());
            $this->rejected(404, fn () => $service->markRead((int) $row['id']));
            $service->markAllRead();
            $this->assertNull($this->notices()[0]['read_at']);
        }
        $this->db->table('users')->where('id', $this->guidance['id'])->update(['role' => 'counsellor']);
        $permission = $this->db->table('permissions')->where('code', 'referrals.read')->get()->getRowArray();
        $grant = ['role' => 'counsellor', 'permission_id' => $permission['id']];
        $this->db->table('role_permissions')->where($grant)->delete();
        try {
            $this->assertSame(['items' => [], 'unread_count' => 0], $service->ownInbox());
            $this->rejected(404, fn () => $service->markRead((int) $row['id']));
            $service->markAllRead();
            $this->create();
            $this->assertCount(1, $this->notices(), 'No delivery without an actual read grant.');
        } finally {
            $this->db->table('role_permissions')->insert($grant);
        }
        foreach ([['status' => 'suspended'], ['status' => 'locked'], ['status' => 'active', 'deleted_at' => date('Y-m-d H:i:s')]] as $change) {
            $this->db->table('users')->where('id', $this->guidance['id'])->update($change);
            $this->login($this->guidance);
            $this->rejected(401, fn () => $service->ownInbox());
            $this->login($this->guidance);
            $this->rejected(401, fn () => $service->markRead((int) $row['id']));
            $this->login($this->guidance);
            $this->rejected(401, fn () => $service->markAllRead());
        }
        $this->db->table('users')->where('id', $this->guidance['id'])->update(['deleted_at' => null, 'status' => 'active', 'person_id' => null]);
        $this->login($this->guidance);
        $this->rejected(401, fn () => $service->ownInbox());
        $this->assertNull($this->notices()[0]['read_at']);
    }

    public function testStoredReferralAndCallerTransactionAreMandatory(): void
    {
        $ref = $this->create();
        $service = new NotificationService();
        $this->rejected(0, fn () => $service->notifyReferral($ref));
        foreach ([['id' => 0], ['tenant_id' => $this->tenant()], ['target_module' => 'clinic'], ['status' => 'closed']] as $change) {
            $this->db->transBegin();
            try {
                $this->rejected(0, fn () => $service->notifyReferral(array_replace($ref, $change)));
            } finally {
                $this->db->transRollback();
            }
        }
        $this->db->table('referrals')->where('id', $ref['id'])->update(['status' => 'closed']);
        $this->db->transBegin();
        try {
            $this->rejected(0, fn () => $service->notifyReferral($ref));
        } finally {
            $this->db->transRollback();
        }
        $this->assertCount(1, $this->notices());
    }

    public function testInsertFailureRollsBackWholeReferralAndPartialFanout(): void
    {
        $this->account('guidance_admin');
        $this->trigger = $this->db->prefixTable(uniqid('ref_notice_fail_'));
        $trigger = $this->db->protectIdentifiers($this->trigger);
        $table = $this->db->protectIdentifiers($this->db->prefixTable('notifications'));
        // Fail the second notification regardless of recipient enumeration order.
        $this->assertNotFalse($this->db->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON {$table} FOR EACH ROW
            BEGIN IF NEW.tenant_id = {$this->tenant} AND EXISTS (SELECT 1 FROM {$table} WHERE tenant_id = {$this->tenant})
            THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture notification failure'; END IF; END"));
        $failed = false;
        try {
            $this->create();
        } catch (\Throwable $e) {
            $failed = true;
        }
        $this->assertTrue($failed);
        $this->assertSame(0, $this->db->table('referrals')->where('tenant_id', $this->tenant)->countAllResults());
        $this->assertSame([], $this->notices());
    }

    public function testPersonalAppointmentContractSurvivesAndMergedInboxIsBounded(): void
    {
        $person = (int) $this->db->table('users')->where('id', $this->guidance['id'])->get()->getRowArray()['person_id'];
        $id = (new AppointmentModel())->insert(['tenant_id' => $this->tenant, 'unit' => 'clinic', 'person_id' => $person,
            'starts_at' => '2026-10-20 09:00:00', 'ends_at' => '2026-10-20 09:30:00', 'local_date' => '2026-10-20',
            'status' => 'confirmed', 'type' => 'routine', 'booked_by_user_id' => $this->clinic['id'], 'version' => 1]);
        $appointment = (new AppointmentModel())->scoped()->find($id);
        $service = new NotificationService();
        $this->db->transBegin();
        try {
            $service->notifyConfirmation($appointment);
            $service->notifyConfirmation($appointment);
            $this->assertTrue($this->db->transCommit());
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
        $this->create();
        $this->login($this->guidance);
        $inbox = $service->ownInbox();
        $this->assertSame(2, $inbox['unread_count']);
        $this->assertSame('Appointment confirmed', $inbox['items'][1]['title']);
        $this->assertSame('Your appointment has been confirmed. Open your portal to see details.', $inbox['items'][1]['message']);
        $this->assertSame(site_url('/portal#my-appointments'), $inbox['items'][1]['url']);
        $this->db->table('users')->where('id', $this->guidance['id'])->update(['role' => 'employee']);
        $this->assertSame(1, $service->ownInbox()['unread_count']);
        $service->markAllRead();
        $this->assertNull($this->notices()[1]['read_at'], 'Revoked staff notice must stay unread.');
        $this->db->table('users')->where('id', $this->guidance['id'])->update(['role' => 'counsellor']);
        for ($i = 0; $i < 101; $i++) {
            $this->create();
        }
        $inbox = $service->ownInbox();
        $this->assertCount(10, $inbox['items']);
        $this->assertSame(100, $inbox['unread_count']);
        $ids = array_column($inbox['items'], 'id');
        $sorted = $ids;
        rsort($sorted);
        $this->assertSame($sorted, $ids);
        $service->markAllRead();
        $this->assertSame(0, $service->ownInbox()['unread_count']);
    }
}
