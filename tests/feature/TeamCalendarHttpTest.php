<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Database\Migrations\CreateTeamEvents;
use App\Database\Migrations\CreateTeamTasks;
use App\Services\AuthenticatedUser;
use App\Services\TeamCalendarService;
use App\Services\TeamTaskService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/** Application routes/filters/controllers, with only private migrated SQLite data. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TeamCalendarHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        config('Database')->defaultGroup = 'tests';
        config('Database')->tests = [
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'calendar_http_',
            'DBDebug' => true, 'foreignKeys' => true, 'busyTimeout' => 1000,
        ];
        $this->db = Database::connect();
        require_once ROOTPATH . 'tests/_support/TeamsNotificationFixture.php';
        \Tests\Support\TeamsNotificationFixture::install($this->db);
        $this->assertSame(':memory:', $this->db->database);
        $this->db->query('CREATE TABLE calendar_http_tenants (id INTEGER PRIMARY KEY, is_active INTEGER, deleted_at TEXT)');
        $this->db->query('CREATE TABLE calendar_http_people (id INTEGER PRIMARY KEY, tenant_id INTEGER, first_name TEXT, last_name TEXT, mis_id TEXT, email TEXT, deleted_at TEXT)');
        $this->db->query('CREATE TABLE calendar_http_employee_profiles (person_id INTEGER, tenant_id INTEGER, is_teaching INTEGER)');
        $this->db->query('CREATE TABLE calendar_http_users (id INTEGER PRIMARY KEY, tenant_id INTEGER, person_id INTEGER, username TEXT, email TEXT, role TEXT, status TEXT, auth_version INTEGER DEFAULT 1, deleted_at TEXT)');
        $this->db->query('CREATE TABLE calendar_http_permissions (id INTEGER PRIMARY KEY, code TEXT)');
        $this->db->query('CREATE TABLE calendar_http_role_permissions (role TEXT, permission_id INTEGER)');
        $this->db->table('tenants')->insertBatch([['id' => 1, 'is_active' => 1], ['id' => 2, 'is_active' => 1]]);
        foreach ([1 => 'clinic_staff', 2 => 'clinic_staff', 3 => 'clinic_admin', 4 => 'counsellor',
            5 => 'facilities_op', 6 => 'student', 7 => 'superadmin', 8 => 'clinic_staff', 9 => 'clinic_staff'] as $id => $role) {
            $tenant = $id === 8 ? 2 : 1;
            $this->db->table('people')->insert(['id' => $id, 'tenant_id' => $tenant, 'first_name' => 'Person', 'last_name' => (string) $id]);
            $this->db->table('users')->insert(['id' => $id, 'tenant_id' => $tenant, 'person_id' => $id,
                'username' => 'user' . $id, 'role' => $role, 'status' => $id === 9 ? 'suspended' : 'active']);
        }
        require_once APPPATH . 'Database/Migrations/2026-10-18-000002_CreateTeamTasks.php';
        require_once APPPATH . 'Database/Migrations/2026-10-19-000001_CreateTeamEvents.php';
        (new CreateTeamTasks())->up();
        (new CreateTeamEvents())->up();
        helper(['form', 'url']);
    }

    protected function tearDown(): void
    {
        AuthenticatedUser::clear();
        $this->db->close();
        parent::tearDown();
    }

    private function identity(int $id): array
    {
        $tenant = $id === 8 ? 2 : 1;
        // Cached privilege is intentionally forged; membership must come from SQL.
        return ['tenant_id' => $tenant, 'user' => ['id' => $id, 'tenant_id' => $tenant, 'logged_in' => true,
            'role' => 'superadmin', 'name' => 'Forged admin', 'permissions' => ['*']],
            '_auth' => ['started_at' => time(), 'last_seen_at' => time(), 'version' => 1, 'session_id' => session_id()]];
    }

    private function requestAs(int $id, string $method, string $path, array $data = [], bool $json = true, bool $csrf = true)
    {
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        Services::resetSingle('redirectresponse');
        if ($method === 'POST' && $csrf) {
            $hash = service('security')->generateHash();
            service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
            Services::resetSingle('security');
            $data[config('Security')->tokenName] = $hash;
        }
        return $this->withSession($this->identity($id))
            ->withHeaders($json ? ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'] : [])
            ->call($method, $path, $data);
    }

    private function input(array $changes = []): array
    {
        return $changes + ['title' => 'Internal planning', 'description' => '', 'location' => '',
            'starts_at' => '2026-10-01T09:00', 'ends_at' => '2026-10-01T10:00', 'participant_ids' => []];
    }

    private function event(array $changes = [], int $actor = 1, string $unit = 'clinic'): array
    {
        session()->set($this->identity($actor));
        return (new TeamCalendarService())->create($unit, $this->input($changes));
    }

    private function json($result, int $status): array
    {
        $this->assertSame($status, $result->response()->getStatusCode(), $result->getBody());
        $this->assertStringContainsString('no-store', $result->response()->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('private', $result->response()->getHeaderLine('Cache-Control'));
        $json = json_decode($result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($status < 400, $json['success']);
        $this->assertSame(csrf_token(), $json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);
        return $json;
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        return new \DOMXPath($dom);
    }

    public function testAllDepartmentRoutesAndManilaRedirectsWithoutClinicalGrants(): void
    {
        foreach (['clinic' => 1, 'guidance' => 4, 'facilities' => 5] as $unit => $actor) {
            $base = "$unit/teams/calendar";
            $this->requestAs($actor, 'GET', "$base?date=2026-10-01")->assertStatus(200);
            $form = $this->requestAs($actor, 'GET', "$base/events/create?date=2026-10-01&fragment=1");
            $form->assertStatus(200);
            $this->assertStringContainsString('value="2026-10-01T09:00"', $form->getBody());
            $this->assertStringContainsString('value="2026-10-01T10:00"', $form->getBody());
            $json = $this->json($this->requestAs($actor, 'POST', "$base/events/store", $this->input([
                'tenant_id' => 2, 'unit' => 'other', 'status' => 'cancelled', 'created_by_user_id' => 8,
            ])), 200);
            $this->assertSame(site_url($base) . '?date=2026-10-01', $json['redirect']);
            $row = $this->db->table('team_events')->where('unit', $unit)->get()->getRowArray();
            $this->assertSame(1, (int) $row['tenant_id']);
            $this->assertSame($actor, (int) $row['created_by_user_id']);
            $this->assertSame('scheduled', $row['status']);
            $this->assertSame('2026-10-01 01:00:00', $row['starts_at']);
            $id = $row['id'];
            foreach (['', '/edit'] as $suffix) {
                $this->requestAs($actor, 'GET', "$base/events/$id$suffix?fragment=1")->assertStatus(200);
            }
            $this->json($this->requestAs($actor, 'POST', "$base/events/$id/update", $this->input(['version' => 1, 'title' => 'Updated'])), 200);
            $this->json($this->requestAs($actor, 'POST', "$base/events/$id/cancel", ['version' => 2]), 200);
            $row = $this->db->table('team_events')->where('id', $id)->get()->getRowArray();
            $this->assertSame('cancelled', $row['status']);
            $this->assertSame(3, (int) $row['version']);
        }
        $this->assertSame(0, $this->db->table('team_tasks')->countAllResults());
        $this->assertSame(0, $this->db->table('team_task_activity')->countAllResults());
    }

    public function testEveryIdRouteScopesTenantDepartmentAndEditorFromPersistedRole(): void
    {
        $id = $this->event(['participant_ids' => [2]])['id'];
        $base = "clinic/teams/calendar/events/$id";
        foreach (['GET' => ['', '/edit'], 'POST' => ['/update', '/cancel']] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $this->requestAs(8, $method, $base . $suffix)->assertStatus(404);
                $this->requestAs(4, $method, $base . $suffix)->assertStatus(403);
                $this->requestAs(6, $method, $base . $suffix)->assertStatus(403);
                $this->requestAs(7, $method, "guidance/teams/calendar/events/$id$suffix")->assertStatus(404);
            }
        }
        $show = $this->requestAs(2, 'GET', "$base?fragment=1");
        $show->assertStatus(200);
        $this->assertStringNotContainsString('Cancel event', $show->getBody());
        $this->assertStringNotContainsString('Edit event', $show->getBody());
        $this->requestAs(2, 'GET', "$base/edit?fragment=1")->assertStatus(403);
        foreach (['update', 'cancel'] as $action) {
            $this->json($this->requestAs(2, 'POST', "$base/$action", $this->input(['version' => 1])), 403);
        }
        $this->requestAs(3, 'GET', "$base/edit?fragment=1")->assertStatus(200);
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            foreach (['', '/events/create'] as $suffix) {
                $this->requestAs(6, 'GET', "$unit/teams/calendar$suffix")->assertStatus(403);
            }
            $this->requestAs(6, 'POST', "$unit/teams/calendar/events/store", $this->input())->assertStatus(403);
        }
        $this->assertSame(1, $this->db->table('team_events')->countAllResults());
        $this->assertSame(1, (int) $this->db->table('team_events')->get()->getRowArray()['version']);
        $this->withSession([])->withHeaders([])->get('clinic/teams/calendar')->assertRedirectTo('/auth/login');
    }

    public function testAllWritesRequireCsrfAndGetCannotMutate(): void
    {
        $id = $this->event()['id'];
        foreach (['clinic', 'guidance', 'facilities'] as $unit) {
            foreach (['store', "$id/update", "$id/cancel"] as $action) {
                foreach ([[], [csrf_token() => str_repeat('0', 64)]] as $input) {
                    $json = $this->json($this->requestAs(7, 'POST', "$unit/teams/calendar/events/$action", $input, csrf: false), 403);
                    $this->assertTrue($json['reload'], 'Registered web_csrf filter must reject before controller execution.');
                }
                $this->assertNoRoute('GET', "$unit/teams/calendar/events/$action");
            }
        }
        $this->assertNoRoute('POST', 'clinic/teams/calendar');
        $this->assertNoRoute('POST', 'clinic/teams/calendar/events/create');
        $this->assertSame(1, $this->db->table('team_events')->countAllResults());
        $this->assertSame(1, (int) $this->db->table('team_events')->get()->getRowArray()['version']);
    }

    private function assertNoRoute(string $method, string $path): void
    {
        try {
            $result = $this->requestAs(7, $method, $path);
            $this->assertContains($result->response()->getStatusCode(), [404, 405]);
        } catch (\CodeIgniter\Exceptions\PageNotFoundException $e) {
            $this->assertStringContainsString("Can't find a route", $e->getMessage());
        }
    }

    public function testRevokedAuthenticationCannotUseCachedAdminSession(): void
    {
        $id = $this->event()['id'];
        $this->db->table('users')->where('id', 1)->update(['auth_version' => 2]);
        foreach (['GET' => ['', '/events/create', "/events/$id", "/events/$id/edit"],
            'POST' => ['/events/store', "/events/$id/update", "/events/$id/cancel"]] as $method => $suffixes) {
            foreach ($suffixes as $suffix) {
                $this->requestAs(1, $method, 'clinic/teams/calendar' . $suffix, $this->input(['version' => 1]))->assertStatus(401);
            }
        }
        $this->assertSame(1, (int) $this->db->table('team_events')->get()->getRowArray()['version']);
    }

    public function testValidationStaleVersionsAndTerminalCancellationAreAtomic(): void
    {
        $id = $this->event(['participant_ids' => [2]])['id'];
        $base = "clinic/teams/calendar/events/$id";
        foreach ([['title' => ''], ['starts_at' => '2026-02-30T09:00'], ['ends_at' => '2026-10-01T09:00'],
            ['ends_at' => '2026-11-02T09:01'], ['participant_ids' => [8]], ['participant_ids' => [4]],
            ['participant_ids' => [9]], ['participant_ids' => [2, 2]]] as $bad) {
            $this->json($this->requestAs(1, 'POST', 'clinic/teams/calendar/events/store', $this->input($bad)), 422);
            $this->json($this->requestAs(1, 'POST', "$base/update", $this->input($bad + ['version' => 1])), 422);
        }
        $this->json($this->requestAs(1, 'POST', "$base/update", $this->input(['version' => 1, 'title' => 'Changed', 'participant_ids' => [1]])), 200);
        foreach (['update', 'cancel'] as $action) {
            $this->json($this->requestAs(1, 'POST', "$base/$action", $this->input(['version' => 1])), 409);
        }
        $this->assertSame([1], array_map('intval', array_column($this->db->table('team_event_participants')->get()->getResultArray(), 'user_id')));
        // HTML unchecked checkboxes omit the key; update must clear the selection.
        $input = $this->input(['version' => 2, 'title' => 'Cleared']);
        unset($input['participant_ids']);
        $this->json($this->requestAs(1, 'POST', "$base/update", $input), 200);
        $this->assertSame(0, $this->db->table('team_event_participants')->countAllResults());
        $this->json($this->requestAs(3, 'POST', "$base/cancel", ['version' => 3]), 200);
        $this->json($this->requestAs(1, 'POST', "$base/cancel", ['version' => 4]), 200);
        $this->json($this->requestAs(1, 'POST', "$base/cancel", ['version' => 3]), 409);
        $this->json($this->requestAs(1, 'POST', "$base/update", $this->input(['version' => 4])), 409);
        $this->requestAs(1, 'GET', "$base/edit")->assertStatus(409);
        $row = $this->db->table('team_events')->get()->getRowArray();
        $this->assertSame('Cleared', $row['title']);
        $this->assertSame('cancelled', $row['status']);
        $this->assertSame(4, (int) $row['version']);
        $this->assertSame(1, $this->db->table('team_events')->countAllResults());
    }

    public function testMineIncludesCreatorOrParticipantButOnlyAssignedTasksAndNoCrossScopeChildren(): void
    {
        $own = $this->event(['title' => 'Own event']);
        $this->event(['title' => 'Attending event', 'participant_ids' => [1]], actor: 2);
        $hidden = $this->event(['title' => 'Other event'], actor: 2);
        $this->event(['title' => 'Foreign tenant event'], actor: 8);
        $this->event(['title' => 'Foreign department event'], actor: 4, unit: 'guidance');
        // Simulate historical corrupt children; composite FKs normally prevent these.
        $this->db->disableForeignKeyChecks();
        $this->db->table('team_event_participants')->insert(['tenant_id' => 2, 'unit' => 'clinic', 'event_id' => $hidden['id'], 'user_id' => 1]);
        $this->db->table('team_event_participants')->insert(['tenant_id' => 1, 'unit' => 'guidance', 'event_id' => $own['id'], 'user_id' => 4]);
        $this->db->enableForeignKeyChecks();
        session()->set($this->identity(2));
        (new TeamTaskService())->create('clinic', ['title' => 'Assigned deadline', 'assignee_user_id' => 1, 'due_date' => '2026-10-01']);
        session()->set($this->identity(1));
        (new TeamTaskService())->create('clinic', ['title' => 'Created not assigned', 'due_date' => '2026-10-01']);
        foreach (['month', 'week', 'agenda'] as $view) {
            $result = $this->requestAs(1, 'GET', "clinic/teams/calendar?date=2026-10-01&view=$view&mine=1", json: false);
            $result->assertStatus(200);
            foreach (['Own event', 'Attending event', 'Assigned deadline'] as $title) $this->assertStringContainsString($title, $result->getBody());
            foreach (['Other event', 'Foreign tenant event', 'Foreign department event', 'Created not assigned'] as $title) $this->assertStringNotContainsString($title, $result->getBody());
        }
        $show = $this->requestAs(1, 'GET', 'clinic/teams/calendar/events/' . $own['id'] . '?fragment=1');
        $this->assertStringContainsString('Participants (0)', $show->getBody());
        $this->assertStringNotContainsString('Person 4', $show->getBody());
    }

    public function testMultiDayRenderingUsesExclusiveMidnightInAllViews(): void
    {
        $id = $this->event(['title' => 'Spanning midnight', 'starts_at' => '2026-09-30T23:00', 'ends_at' => '2026-10-02T00:00'])['id'];
        foreach (['month', 'week', 'agenda'] as $view) {
            $result = $this->requestAs(1, 'GET', "clinic/teams/calendar?date=2026-09-30&view=$view", json: false);
            $result->assertStatus(200);
            $xp = $this->xpath($result->getBody());
            $this->assertSame(2, $xp->query('//a[contains(@class,"team-calendar-item") and contains(@href,"/events/' . $id . '")]')->length);
            foreach (['2026-09-30' => '11:00 PM', '2026-10-01' => 'Continues', '2026-10-02' => null] as $date => $label) {
                $container = $view === 'agenda' ? 'section[contains(@class,"team-calendar-agenda-day")]' : 'td';
                $nodes = $xp->query('//' . $container . '[.//time[@datetime="' . $date . '"]]//a[contains(@class,"team-calendar-item")]');
                $this->assertSame($label === null ? 0 : 1, $nodes->length, "$view $date");
                if ($label !== null) $this->assertStringContainsString($label, $nodes->item(0)->textContent);
            }
        }
    }

    public function testEscapingDirectAndFragmentContractsAndPickerValues(): void
    {
        $payload = '<img src=x onerror="window.calendarXss=1">';
        $id = $this->event(['title' => $payload, 'description' => '</textarea>' . $payload, 'location' => $payload, 'participant_ids' => [2]])['id'];
        $this->db->table('people')->where('id', 2)->update(['first_name' => $payload]);
        foreach ([false, true] as $fragment) {
            foreach (['create', (string) $id, "$id/edit"] as $path) {
                $result = $this->requestAs(1, 'GET', 'clinic/teams/calendar/events/' . $path . ($fragment ? '?fragment=1' : ''), json: false);
                $result->assertStatus(200);
                $html = (string) $result->response()->getBody();
                $this->assertStringNotContainsString($payload, $html);
                $this->assertSame(1, substr_count($html, 'id="appFormModal"'));
                $this->assertStringContainsString('data-bs-backdrop="static"', $html);
                $this->assertStringContainsString('data-bs-keyboard="false"', $html);
                $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
                $this->assertStringContainsString('data-modal-submit', $html);
                if ($fragment) {
                    $this->assertStringNotContainsString('<html', $html);
                    $this->assertStringNotContainsString('<script', $html);
                    $this->assertStringNotContainsString('data-modal-direct', $html);
                } else {
                    $this->assertStringContainsString('data-modal-direct="true"', $html);
                    $this->assertStringContainsString('assets/js/form-modal.js', $html);
                }
                if ($path === "$id/edit") {
                    $xp = $this->xpath($html);
                    foreach (['starts_at' => '2026-10-01T09:00', 'ends_at' => '2026-10-01T10:00', 'version' => '1'] as $name => $value) {
                        $this->assertSame($value, $xp->query('//input[@name="' . $name . '"]')->item(0)->getAttribute('value'));
                    }
                    $this->assertSame(1, $xp->query('//input[@name="participant_ids[]" and @value="2" and @checked]')->length);
                }
            }
        }
        foreach (['month', 'week', 'agenda'] as $view) {
            $html = $this->requestAs(1, 'GET', "clinic/teams/calendar?date=2026-10-01&view=$view", json: false)->getBody();
            $this->assertStringContainsString('&lt;img', $html);
            $this->assertStringNotContainsString($payload, $html);
        }
    }

    public function testMissingSchemaReturnsSanitizedPrivateErrors(): void
    {
        $id = $this->event()['id'];
        $this->db->query('DROP TABLE calendar_http_team_event_participants');
        foreach (['GET' => ['', "/events/$id", "/events/$id/edit"],
            'POST' => ['/events/store', "/events/$id/update", "/events/$id/cancel"]] as $method => $paths) {
            foreach ($paths as $path) {
                $json = $this->json($this->requestAs(1, $method, 'clinic/teams/calendar' . $path, $this->input(['version' => 1])), 503);
                $this->assertSame('The team calendar is temporarily unavailable. Ask the administrator to verify calendar migrations or retry later.', $json['message']);
                $this->assertDoesNotMatchRegularExpression('/SQL|calendar_http_|SELECT|INSERT|Exception/', $json['message']);
            }
        }
        $result = $this->requestAs(1, 'GET', 'clinic/teams/calendar', json: false);
        $result->assertStatus(503);
        $this->assertStringContainsString('text/plain', $result->response()->getHeaderLine('Content-Type'));
        $this->assertStringNotContainsString('calendar_http_', $result->getBody());
        $this->assertSame(1, (int) $this->db->table('team_events')->get()->getRowArray()['version']);
    }

    public function testNonAjaxRedirectsValidationAndCancelledFilter(): void
    {
        $base = 'clinic/teams/calendar';
        $this->requestAs(1, 'POST', "$base/events/store", $this->input(['title' => 'History event']), json: false)
            ->assertRedirectTo('/clinic/teams/calendar?date=2026-10-01');
        $id = $this->db->table('team_events')->get()->getRowArray()['id'];
        $result = $this->requestAs(1, 'POST', "$base/events/store", $this->input(['title' => '']), json: false);
        $result->assertStatus(422);
        $this->assertStringContainsString('text/plain', $result->response()->getHeaderLine('Content-Type'));
        foreach (['view=invalid', 'mine[]=1', 'status=invalid', 'date=2026-02-30'] as $query) {
            $this->requestAs(1, 'GET', "$base?$query")->assertStatus(422);
        }
        $this->requestAs(1, 'GET', "$base/events/create?date=2026-02-30")->assertStatus(422);
        $this->requestAs(1, 'POST', "$base/events/$id/cancel", ['version' => 1], json: false)
            ->assertRedirectTo('/clinic/teams/calendar?date=2026-10-01');
        $html = $this->requestAs(1, 'GET', "$base?date=2026-10-01", json: false)->getBody();
        $this->assertStringNotContainsString('History event', $html);
        foreach (['cancelled', 'all'] as $status) {
            $html = $this->requestAs(1, 'GET', "$base?date=2026-10-01&status=$status", json: false)->getBody();
            $this->assertStringContainsString('History event', $html);
            $this->assertStringContainsString('is-complete', $html);
        }
    }
}
