<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AnnouncementService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\InventoryTestDatabase;

/** Real route/filter/view tests. Main integrates the routes; no test-only route replacements. */
final class AnnouncementRoutesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $DBGroup = 'tests';
    protected $migrate = false;
    protected $refresh = false;
    protected $namespace = 'App';

    private int $tenant;
    private array $admin;
    private array $student;
    private array $staff;

    protected function setUp(): void
    {
        InventoryTestDatabase::verify();
        $this->assertSame('tests', config('Database')->defaultGroup, 'Domain services must use the isolated tests group.');
        parent::setUp();
        $this->assertTrue($this->migrations->setNamespace('App')->latest('tests'));
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('announcement_routes_'), 'name' => 'Announcement route fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->admin = $this->account('superadmin', 'employee');
        $this->student = $this->account('student', 'student');
        $this->staff = $this->account('clinic_staff', 'employee');
    }

    protected function tearDown(): void
    {
        if (isset($this->tenant)) {
            foreach (['notifications', 'announcements_reads', 'announcements', 'audit_events', 'student_profiles', 'employee_profiles', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $this->tenant)->delete();
            }
            $this->db->table('tenants')->where('id', $this->tenant)->delete();
        }
        foreach (['renderer', 'security'] as $service) { Services::resetSingle($service); }
        service('superglobals')->setCookieArray([]);
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function account(string $role, string $profile): array
    {
        $person = (int) (new PersonModel())->insert(['tenant_id' => $this->tenant, 'kind' => $profile,
            'mis_id' => uniqid('announcement_route_person_'), 'first_name' => 'Route', 'last_name' => 'Tester']);
        $now = date('Y-m-d H:i:s');
        $this->db->table($profile . '_profiles')->insert(['tenant_id' => $this->tenant, 'person_id' => $person, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $person,
            'username' => uniqid('announcement_route_user_'), 'role' => $role, 'status' => 'active']);
        return ['id' => $id, 'tenant_id' => $this->tenant, 'person_id' => $person,
            'role' => 'superadmin', 'permissions' => ['*'], 'logged_in' => true];
    }

    private function getAs(array $actor, string $route)
    {
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        return $this->withSession(['user' => $actor, 'tenant_id' => $this->tenant])->withHeaders([])->get($route);
    }

    private function postAs(array $actor, string $route, array $payload = [], bool $validCsrf = true)
    {
        AuthenticatedUser::clear();
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        if ($validCsrf) { $payload[config('Security')->tokenName] = $hash; }
        return $this->withSession(['user' => $actor, 'tenant_id' => $this->tenant])
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post($route, $payload);
    }

    private function payload(): array
    {
        return ['title' => '<script>alert("title")</script>', 'body' => '<img src=x onerror=alert(1)>', 'audience' => 'all'];
    }

    private function published(string $unit = 'clinic'): array
    {
        session()->set(['user' => $this->admin, 'tenant_id' => $this->tenant]);
        $service = new AnnouncementService();
        $row = $service->create($unit, $this->payload());
        return $service->publish((int) $row['id'], $unit, $row['version']);
    }

    private function json($response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testExplicitDepartmentRoutesDraftModalAndPublishArchiveWorkflow(): void
    {
        foreach (['clinic', 'guidance'] as $unit) {
            $base = $unit . '/announcements';
            $this->getAs($this->admin, $base)->assertStatus(200);
            $form = $this->getAs($this->admin, $base . '/create?fragment=1');
            $form->assertStatus(200);
            $html = $form->getBody();
            $this->assertStringContainsString('data-app-form-modal', $html);
            $this->assertStringContainsString('data-modal-submit', $html);
            $this->assertStringContainsString('maxlength="160"', $html);
            $this->assertStringContainsString('maxlength="10000"', $html);
            $this->assertStringContainsString('/' . $base . '/store', $html);
            $this->assertStringNotContainsString('name="tenant_id"', $html);
            $this->assertStringNotContainsString('name="unit"', $html);
            $stored = $this->postAs($this->admin, $base . '/store', $this->payload());
            $stored->assertStatus(200);
            $json = $this->json($stored);
            $this->assertTrue($json['success']);
            $this->assertSame(site_url('/' . $base), $json['redirect']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $row = $this->db->table('announcements')->where(['tenant_id' => $this->tenant, 'unit' => $unit])->orderBy('id', 'DESC')->get()->getRowArray();
            $this->assertSame('draft', $row['status']);
            $edit = $this->getAs($this->admin, $base . '/' . $row['id'] . '/edit?fragment=1');
            $edit->assertStatus(200);
            $this->assertStringContainsString('&lt;script&gt;', $edit->getBody());
            $this->assertStringNotContainsString('<img src=x', $edit->getBody());
            $this->postAs($this->admin, $base . '/' . $row['id'] . '/update', ['version' => $row['version']] + $this->payload())->assertStatus(200);
            $version = (int) $row['version'] + 1;
            $index = $this->getAs($this->admin, $base);
            $this->assertStringContainsString('data-confirm-title="Publish announcement"', $index->getBody());
            $this->assertStringContainsString('data-app-modal-open', $index->getBody());
            $this->assertStringNotContainsString('confirm(', $index->getBody());
            $publish = $this->postAs($this->admin, $base . '/' . $row['id'] . '/publish', ['version' => $version]);
            $publish->assertStatus(200);
            $this->assertTrue($this->json($publish)['success']);
            $this->getAs($this->admin, $base . '/' . $row['id'] . '/edit')->assertStatus(409);
            $this->postAs($this->admin, $base . '/' . $row['id'] . '/archive', ['version' => $version + 1])->assertStatus(200);
        }
    }

    public function testManilaFormRoundTripAndPortalDateLabels(): void
    {
        $base = 'clinic/announcements';
        $input = $this->payload() + ['starts_at' => '2099-01-02T00:15', 'ends_at' => '2099-01-02T01:15'];
        $this->postAs($this->admin, $base . '/store', $input)->assertStatus(200);
        $row = $this->db->table('announcements')->where('tenant_id', $this->tenant)->get()->getRowArray();
        $this->assertSame('2099-01-01 16:15:00', $row['starts_at']);
        $edit = $this->getAs($this->admin, $base . '/' . $row['id'] . '/edit?fragment=1');
        $edit->assertStatus(200);
        $this->assertStringContainsString('Asia/Manila', $edit->getBody());
        $this->assertStringContainsString('value="2099-01-02T00:15"', $edit->getBody());
        $this->assertStringContainsString('value="2099-01-02T01:15"', $edit->getBody());
        $this->postAs($this->admin, $base . '/' . $row['id'] . '/update', $input + ['version' => $row['version']])->assertStatus(200);
        $this->postAs($this->admin, $base . '/' . $row['id'] . '/publish', ['version' => (int) $row['version'] + 1])->assertStatus(200);
        $stored = $this->db->table('announcements')->where('id', $row['id'])->get()->getRowArray();
        $this->assertSame($row['starts_at'], $stored['starts_at']);
        $this->assertSame($row['ends_at'], $stored['ends_at']);
        // Use known UTC instants to verify display independently of today's clock.
        $this->db->table('announcements')->where('id', $row['id'])->where('tenant_id', $this->tenant)
            ->update(['starts_at' => null, 'published_at' => '2020-01-01 23:30:00']);
        $list = $this->getAs($this->student, 'portal/announcements');
        $list->assertStatus(200);
        $this->assertStringContainsString('Published at (Asia/Manila)', $list->getBody());
        $this->assertStringContainsString('2020-01-02 07:30', $list->getBody());
        $show = $this->getAs($this->student, 'portal/announcements/' . $row['id']);
        $show->assertStatus(200);
        $this->assertStringContainsString('Published 2020-01-02 07:30 (Asia/Manila)', $show->getBody());
        $this->assertStringContainsString('Available until 2099-01-02 01:15 (Asia/Manila)', $show->getBody());
    }

    public function testPortalNeverDisclosesOtherReadersAndIgnoresForgedReadOwnership(): void
    {
        $notice = $this->published();
        $path = 'portal/announcements/' . $notice['id'];
        $this->db->table('people')->where('tenant_id', $this->tenant)->where('id', $this->admin['person_id'])
            ->update(['first_name' => 'PRIVATE OTHER READER', 'email' => 'private-reader@example.test', 'address' => 'PRIVATE HOME ADDRESS']);
        $this->postAs($this->admin, $path . '/read')->assertStatus(200);
        foreach (['portal/announcements?person_id=' . $this->admin['person_id'], $path . '?reads=' . $notice['id']] as $url) {
            $page = $this->getAs($this->student, $url);
            $page->assertStatus(200);
            foreach (['PRIVATE OTHER READER', 'private-reader@example.test', 'PRIVATE HOME ADDRESS', 'Read history'] as $private) {
                $this->assertStringNotContainsString($private, $page->getBody());
            }
        }
        $this->assertStringContainsString('Mark as read', $this->getAs($this->student, $path)->getBody());
        $forgedSession = $this->student;
        $forgedSession['person_id'] = $this->admin['person_id'];
        $this->postAs($forgedSession, $path . '/read', [
            'person_id' => $this->admin['person_id'], 'tenant_id' => $this->tenant + 1000, 'announcement_id' => $notice['id'] + 1000,
        ])->assertStatus(200);
        $reads = $this->db->table('announcements_reads')->where('tenant_id', $this->tenant)->where('announcement_id', $notice['id'])->get()->getResultArray();
        $this->assertCount(2, $reads);
        $this->assertContains($this->student['person_id'], array_map('intval', array_column($reads, 'person_id')));
        $this->getAs($this->staff, 'clinic/announcements?reads=' . $notice['id'])->assertStatus(403);
    }

    public function testPortalAudienceFilteringAndDirectLinksRejectHiddenNotices(): void
    {
        session()->set(['user' => $this->admin, 'tenant_id' => $this->tenant]);
        $service = new AnnouncementService();
        $row = $service->create('clinic', [
            'title' => 'PRIVATE EMPLOYEE NOTICE', 'body' => 'PRIVATE EMPLOYEE BODY', 'audience' => 'employees',
        ]);
        $service->publish((int) $row['id'], 'clinic', $row['version']);
        $path = 'portal/announcements/' . $row['id'];
        $list = $this->getAs($this->student, 'portal/announcements?audience=employees&unit=clinic');
        $list->assertStatus(200);
        $this->assertStringNotContainsString('PRIVATE EMPLOYEE NOTICE', $list->getBody());
        $hidden = $this->getAs($this->student, $path);
        $hidden->assertStatus(404);
        $this->assertStringNotContainsString('PRIVATE EMPLOYEE BODY', $hidden->getBody());
        $this->postAs($this->student, $path . '/read', ['audience' => 'employees'])->assertStatus(404);
        $this->getAs($this->staff, $path)->assertStatus(200);
        $this->assertSame(0, $this->db->table('announcements_reads')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testPortalGetDoesNotMarkReadAndPostIsPersonOwned(): void
    {
        $clinic = $this->published();
        $this->published('guidance');
        $before = $this->db->table('audit_events')->where('tenant_id', $this->tenant)->countAllResults();
        $list = $this->getAs($this->student, 'portal/announcements');
        $list->assertStatus(200);
        $this->assertStringContainsString('Clinic', $list->getBody());
        $this->assertStringContainsString('Guidance', $list->getBody());
        $this->assertStringContainsString('&lt;script&gt;', $list->getBody());
        $path = 'portal/announcements/' . $clinic['id'];
        $show = $this->getAs($this->student, $path);
        $show->assertStatus(200);
        $this->assertStringContainsString('&lt;img', $show->getBody());
        $this->assertStringNotContainsString('<img src=x', $show->getBody());
        $this->assertStringContainsString('Mark as read', $show->getBody());
        $this->assertStringContainsString('no-store', $show->response()->getHeaderLine('Cache-Control'));
        $this->assertSame(0, $this->db->table('announcements_reads')->where('tenant_id', $this->tenant)->countAllResults());
        $this->assertSame($before, $this->db->table('audit_events')->where('tenant_id', $this->tenant)->countAllResults());
        foreach ([1, 2] as $attempt) { $this->postAs($this->student, $path . '/read')->assertStatus(200); }
        $reads = $this->db->table('announcements_reads')->where('tenant_id', $this->tenant)->get()->getResultArray();
        $this->assertCount(1, $reads);
        $this->assertSame($this->student['person_id'], (int) $reads[0]['person_id']);
        $this->getAs($this->staff, 'portal/announcements')->assertStatus(200);
        $this->assertStringContainsString('Mark as read', $this->getAs($this->staff, $path)->getBody());
    }

    private function assertMutationGetRejected(array $actor, string $path): void
    {
        try {
            $response = $this->getAs($actor, $path);
            $this->assertContains($response->response()->getStatusCode(), [404, 405]);
        } catch (PageNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testEveryMutationRejectsMissingCsrfAndGetCannotMutate(): void
    {
        $notice = $this->published();
        foreach (['clinic', 'guidance'] as $unit) {
            foreach (['store', $notice['id'] . '/update', $notice['id'] . '/publish', $notice['id'] . '/archive'] as $suffix) {
                $path = $unit . '/announcements/' . $suffix;
                $this->postAs($this->admin, $path, $this->payload(), false)->assertStatus(403);
                $this->assertMutationGetRejected($this->admin, $path);
            }
        }
        $path = 'portal/announcements/' . $notice['id'] . '/read';
        $this->postAs($this->student, $path, [], false)->assertStatus(403);
        $this->assertMutationGetRejected($this->student, $path);
        $this->assertSame(0, $this->db->table('announcements_reads')->where('tenant_id', $this->tenant)->countAllResults());
    }

    public function testStaffCannotPublishAndValidationErrorsMatchSharedModalShape(): void
    {
        $notice = $this->published();
        $this->getAs($this->staff, 'clinic/announcements')->assertStatus(403);
        $this->postAs($this->staff, 'clinic/announcements/' . $notice['id'] . '/publish', ['version' => $notice['version']])->assertStatus(403);
        $invalid = $this->postAs($this->admin, 'clinic/announcements/store', ['title' => [], 'body' => 'Text', 'audience' => 'all']);
        $invalid->assertStatus(422);
        $json = $this->json($invalid);
        $this->assertFalse($json['success']);
        $this->assertNotEmpty($json['message']);
        $this->assertSame(csrf_token(), $json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);
        $this->withSession([])->withHeaders(['Accept' => 'application/json'])->get('portal/announcements')->assertStatus(401);
    }
}
