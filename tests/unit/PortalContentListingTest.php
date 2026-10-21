<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PersonModel;
use App\Models\UserModel;
use App\Services\AnnouncementService;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Guidance\SurveyService;
use CodeIgniter\Test\DatabaseTestTrait;
use RuntimeException;
use Tests\Support\FreshDatabaseTestCase;

/** Only the guarded runner's disposable MySQL schema; never operational/shared data. */
final class PortalContentListingTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';
    private array $student;
    private array $employee;
    private array $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db->transBegin();
        $this->student = $this->account('student', 1);
        $this->employee = $this->account('employee', 1);
        $this->db->table('tenants')->insert(['slug' => uniqid('feed_tenant_'), 'name' => 'Synthetic portal tenant', 'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $this->foreign = $this->account('student', (int) $this->db->insertID());
        $this->login($this->student);
        $actor = (new AuthenticatedUser())->current();
        self::assertSame($this->student['person_id'], $actor['person_id']);
        self::assertSame(1, $this->db->table('student_profiles')->where('tenant_id', 1)->where('person_id', $actor['person_id'])->countAllResults());
        self::assertSame(['all', 'students'], \App\Services\EngagementAudience::audiences($actor));
    }

    protected function tearDown(): void
    {
        if (isset($this->student)) $this->db->transRollback();
        AuthenticatedUser::clear();
        parent::tearDown();
    }

    private function account(string $role, int $tenant): array
    {
        CurrentTenant::set($tenant);
        $person = (int) (new PersonModel())->insert(['tenant_id' => $tenant, 'kind' => $role, 'mis_id' => uniqid('feed_'), 'first_name' => 'Synthetic', 'last_name' => 'Reader']);
        $ok = $this->db->table($role === 'student' ? 'student_profiles' : 'employee_profiles')->insert(['tenant_id' => $tenant, 'person_id' => $person, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')] + ($role === 'student' ? ['year_level' => 3, 'enrollment_type' => 'continuing'] : []));
        self::assertTrue($ok, json_encode($this->db->error()));
        $id = (int) (new UserModel())->insert(['tenant_id' => $tenant, 'person_id' => $person, 'username' => uniqid('feed_user_'), 'role' => $role, 'status' => 'active']);
        return ['id' => $id, 'tenant_id' => $tenant, 'person_id' => $person, 'role' => $role, 'logged_in' => true];
    }

    private function login(array $actor): void
    {
        AuthenticatedUser::clear();
        session()->set(['user' => $actor, 'tenant_id' => $actor['tenant_id'], '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]]);
    }

    private function content(string $table, array $changes = []): int
    {
        $row = ['tenant_id' => 1, 'title' => 'Campus wellbeing', 'audience' => 'all', 'status' => 'published', 'published_at' => gmdate('Y-m-d H:i:s', time() - 3600), 'created_by' => $this->student['id'], 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')];
        $row += $table === 'surveys' ? ['description' => 'Feedback for your campus', 'questions_json' => '[]'] : ['unit' => 'clinic', 'body' => 'A published campus notice.'];
        self::assertTrue($this->db->table($table)->insert(array_replace($row, $changes)));
        return (int) $this->db->insertID();
    }

    private function response(int $survey, array $actor): void
    {
        $this->db->table('survey_responses')->insert(['tenant_id' => $actor['tenant_id'], 'survey_id' => $survey, 'person_id' => $actor['person_id'], 'submitted_by' => $actor['id'], 'payload_cipher' => 'synthetic-not-decrypted', 'payload_nonce' => str_repeat('x', 12), 'payload_tag' => str_repeat('x', 16), 'payload_key_version' => 1, 'submitted_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function testAnnouncementFeedRetainsAudienceTenantWindowAndTargetingScopes(): void
    {
        $all = $this->content('announcements');
        $students = $this->content('announcements', ['audience' => 'students']);
        $employees = $this->content('announcements', ['audience' => 'employees']);
        $this->content('announcements', ['tenant_id' => $this->foreign['tenant_id'], 'created_by' => $this->foreign['id']]);
        $this->content('announcements', ['status' => 'draft']);
        $this->content('announcements', ['ends_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->content('announcements', ['starts_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->content('announcements', ['audience' => 'students', 'target_year_levels' => '[1]']);
        $service = new AnnouncementService();
        self::assertEqualsCanonicalizing([$all, $students], array_column($service->portalListing()['announcements'], 'id'));
        $this->login($this->employee);
        self::assertEqualsCanonicalizing([$all, $employees], array_column($service->portalListing()['announcements'], 'id'));
    }

    public function testAnnouncementSearchDepartmentReadCountsAndPagination(): void
    {
        $read = $this->content('announcements', ['title' => '100% wellbeing', 'unit' => 'guidance']);
        $this->content('announcements', ['title' => '1000 wellbeing']);
        $this->db->table('announcements_reads')->insert(['tenant_id' => 1, 'announcement_id' => $read, 'person_id' => $this->student['person_id'], 'read_at' => gmdate('Y-m-d H:i:s')]);
        $service = new AnnouncementService();
        $result = $service->portalListing(1, ['q' => '100%', 'unit' => 'guidance', 'status' => 'read']);
        self::assertSame(['all' => 1, 'unread' => 0, 'read' => 1], $result['counts']);
        self::assertCount(1, $result['announcements']);
        self::assertArrayHasKey('excerpt', $result['announcements'][0]);
        $this->login($this->employee);
        self::assertSame(0, (new AnnouncementService())->portalListing(1, ['status' => 'read'])['pagination']['total']);
        for ($i = 0; $i < 25; $i++) $this->content('announcements');
        $page = (new AnnouncementService())->portalListing(2);
        self::assertSame(27, $page['pagination']['total']);
        self::assertCount(2, $page['announcements']);
    }

    public function testSurveyCardsHaveScopedClosedHistoryAndPersonOwnedCompletion(): void
    {
        $open = $this->content('surveys');
        $submitted = $this->content('surveys', ['audience' => 'students']);
        $closed = $this->content('surveys', ['status' => 'closed']);
        $expired = $this->content('surveys', ['ends_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->content('surveys', ['audience' => 'employees']);
        $this->content('surveys', ['tenant_id' => $this->foreign['tenant_id'], 'created_by' => $this->foreign['id']]);
        $this->content('surveys', ['status' => 'draft']);
        $this->content('surveys', ['starts_at' => gmdate('Y-m-d H:i:s', time() + 3600)]);
        $this->content('surveys', ['status' => 'closed', 'published_at' => null]);
        $this->content('surveys', ['audience' => 'students', 'target_year_levels' => '[1]']);
        $this->response($submitted, $this->student);
        $this->response($open, $this->employee);
        $service = new SurveyService();
        $result = $service->available();
        self::assertSame(['all' => 4, 'available' => 1, 'submitted' => 1, 'closed' => 2], $result['counts']);
        self::assertEqualsCanonicalizing([$open, $submitted, $closed, $expired], array_column($result['items'], 'id'));
        self::assertEquals([$open], array_column($service->available(['status' => 'available'])['items'], 'id'));
        self::assertEquals([$submitted], array_column($service->available(['status' => 'submitted'])['items'], 'id'));
        foreach ([$closed, $expired] as $id) {
            try { $service->form($id); self::fail('Closed survey opened for participation.'); }
            catch (RuntimeException $e) { self::assertSame(404, $e->getCode()); }

        }
        $this->login($this->employee);
        self::assertSame(1, $service->available()['counts']['submitted']);
        self::assertNotContains($submitted, array_map('intval', array_column($service->available()['items'], 'id')));
    }

    public function testSurveySearchCountsAndPagesAreNotLimitedToTheCurrentPage(): void
    {
        for ($i = 0; $i < 26; $i++) $this->content('surveys', ['title' => 'Wellbeing ' . $i]);
        $service = new SurveyService();
        $result = $service->available(['q' => 'Wellbeing', 'page' => 2]);
        self::assertSame(26, $result['counts']['available']);
        self::assertCount(1, $result['items']);
        self::assertSame(2, $result['pagination']['page']);
        self::assertCount(0, $service->available(['q' => 'No matching title'])['items']);
    }

    public function testUnlinkedAdministrativeIdentityHasNoPersonalSurveyCards(): void
    {
        $this->content('surveys');
        CurrentTenant::set(1);
        $id = (int) (new UserModel())->insert(['tenant_id' => 1, 'username' => uniqid('feed_admin_'), 'role' => 'superadmin', 'status' => 'active']);
        $this->login(['id' => $id, 'tenant_id' => 1, 'person_id' => null, 'role' => 'superadmin', 'logged_in' => true]);
        $result = (new SurveyService())->available();
        self::assertFalse($result['hasProfile']);
        self::assertSame([], $result['items']);
        self::assertSame(0, $result['counts']['all']);
    }

    public function testMalformedFiltersAreRejectedRatherThanBroadeningScope(): void
    {
        foreach ([['q' => []], ['status' => ['all']], ['status' => 'draft'], ['page' => 0], ['per_page' => 1000]] as $query) {
            try { (new SurveyService())->available($query); self::fail('Invalid survey filters accepted.'); }
            catch (RuntimeException $e) { self::assertSame(422, $e->getCode()); }
        }
        $this->expectException(RuntimeException::class);
        (new AnnouncementService())->portalListing(1, ['unit' => 'other']);
    }
}
