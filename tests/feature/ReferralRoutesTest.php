<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Integrations\Mis\MisClientInterface;
use App\Models\ClinicEncounterModel;
use App\Models\CounsellingCaseModel;
use App\Models\PersonModel;
use App\Models\ReferralModel;
use App\Models\UserModel;
use App\Services\AuthenticatedUser;
use App\Services\CurrentTenant;
use App\Services\Referrals\ReferralService;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\View\View;
use Config\Services;
use DOMDocument;
use DOMXPath;
use Tests\Support\FreshDatabaseTestCase;

/** Real route/filter/template contracts; run through run-fresh-database-tests.py. */
final class ReferralRoutesTest extends FreshDatabaseTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate = true;
    protected $refresh = false;
    protected $namespace = 'App';
    protected $seed = 'App\Database\Seeds\PermissionsAndRolesSeeder';

    private int $tenant;
    private int $patient;
    private array $clinic;
    private array $guidance;
    private array $admin;
    private array $grants = [];

    protected function setUp(): void
    {
        parent::setUp();
        AuthenticatedUser::clear();
        Services::resetSingle('renderer');
        $mis = $this->createMock(MisClientInterface::class);
        foreach (['studentLogin', 'employeeLogin', 'searchStudents', 'searchEmployees', 'fetchDepartments', 'fetchPrograms', 'fetchYearLevels'] as $method) {
            $mis->expects($this->never())->method($method);
        }
        Services::injectMock('misClient', $mis);
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug' => uniqid('ref_routes_'), 'name' => 'Referral route fixtures',
            'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $this->tenant = (int) $this->db->insertID();
        CurrentTenant::set($this->tenant);
        $this->patient = $this->person();
        $this->clinic = $this->account('clinic_staff');
        $this->guidance = $this->account('counsellor');
        $this->admin = $this->account('superadmin');
    }

    protected function tearDown(): void
    {
        foreach ($this->grants as [$role, $id, $existed]) {
            $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $id)->delete();
            if ($existed) {
                $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]);
            }
        }
        if (isset($this->tenant)) {
            foreach (['notifications', 'referrals', 'clinic_encounters', 'counselling_cases', 'users', 'people'] as $table) {
                $this->db->table($table)->where('tenant_id', $this->tenant)->delete();
            }
            $this->db->table('tenants')->where('id', $this->tenant)->delete();
        }
        foreach (['renderer', 'security', 'misClient'] as $service) {
            Services::resetSingle($service);
        }
        service('superglobals')->setCookieArray([]);
        session()->remove(['user', 'tenant_id']);
        AuthenticatedUser::clear();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function person(array $overrides = []): int
    {
        return (int) (new PersonModel())->insert($overrides + ['tenant_id' => $this->tenant, 'kind' => 'student',
            'mis_id' => uniqid('ref_route_patient_'), 'first_name' => 'Route', 'last_name' => 'Patient',
            'address' => 'PRIVATE ADDRESS', 'email' => 'private@example.test', 'phone' => 'PRIVATE PHONE']);
    }

    private function account(string $role): array
    {
        $id = (int) (new UserModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $this->person(['email' => 'staff@example.test']),
            'username' => uniqid('ref_route_staff_'), 'role' => $role, 'status' => 'active']);
        // Cached authority is deliberately forged; database identity/grants must win.
        return ['id' => $id, 'tenant_id' => $this->tenant, 'role' => 'superadmin',
            'permissions' => ['*'], 'logged_in' => true, 'name' => 'Route tester'];
    }

    private function authSession(array $actor): array
    {
        $version = (int) $this->db->table('users')->select('auth_version')->where('id', $actor['id'])->get()->getRowArray()['auth_version'];
        return ['user' => $actor, 'tenant_id' => $this->tenant, '_auth' => [
            'started_at' => time(), 'last_seen_at' => time(), 'version' => $version, 'session_id' => session_id(),
        ]];
    }

    private function getAs(array $actor, string $route, array $query = [])
    {
        AuthenticatedUser::clear();
        return $this->withSession($this->authSession($actor))->withHeaders([])
            ->get($route . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    private function postAs(array $actor, string $route, array $data = [], bool $csrf = true, bool $ajax = true)
    {
        AuthenticatedUser::clear();
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        if ($csrf) {
            $data[config('Security')->tokenName] = $hash;
        }
        return $this->withSession($this->authSession($actor))
            ->withHeaders($ajax ? ['X-Requested-With' => 'XMLHttpRequest'] : [])->post($route, $data);
    }

    private function payload(string $unit = 'clinic'): array
    {
        return ['patient_person_id' => $this->patient, 'request_key' => bin2hex(random_bytes(32)),
            'priority' => 'routine', 'reason_code' => $unit === 'clinic' ? 'academic_distress' : 'medical_assessment',
            'reason_description' => '  Brief shared support request.  '];
    }

    private function referral(string $source = 'clinic'): array
    {
        return (new ReferralService())->createReferral($this->payload($source)
            + ['issuer_user_id' => ($source === 'clinic' ? $this->clinic : $this->guidance)['id']],
            $source === 'clinic' ? $this->clinic : $this->guidance);
    }

    private function record(int $id): array
    {
        return (new ReferralModel())->scoped()->find($id);
    }

    private function latest(): array
    {
        return (new ReferralModel())->scoped()->orderBy('id', 'DESC')->first();
    }

    private function countReferrals(): int
    {
        return $this->db->table('referrals')->where('tenant_id', $this->tenant)->countAllResults();
    }

    private function revoke(string $role, string $code): void
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        $this->assertNotNull($permission, 'Expected seeded permission: ' . $code);
        $id = (int) $permission['id'];
        $key = $role . ':' . $id;
        if (!isset($this->grants[$key])) {
            $this->grants[$key] = [$role, $id, $this->db->table('role_permissions')->where('role', $role)
                ->where('permission_id', $id)->countAllResults() > 0];
        }
        $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $id)->delete();
    }

    private function captureView(array &$data): void
    {
        $renderer = $this->createMock(View::class);
        $renderer->method('setData')->willReturnCallback(static function ($values) use (&$data, $renderer) {
            $data = $values;
            return $renderer;
        });
        $renderer->method('render')->willReturn('referral view contract');
        Services::injectMock('renderer', $renderer);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    private function encounter(int $patient): int
    {
        return (int) (new ClinicEncounterModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $patient,
            'attending_user_id' => $this->clinic['id'], 'status' => 'in_progress', 'started_at' => date('Y-m-d H:i:s'),
            'chief_complaint' => 'PRIVATE COMPLAINT', 'assessment_notes' => 'PRIVATE ASSESSMENT']);
    }

    private function caseId(int $patient, int $owner): int
    {
        return (int) (new CounsellingCaseModel())->insert(['tenant_id' => $this->tenant, 'person_id' => $patient,
            'counsellor_user_id' => $owner, 'case_number' => uniqid('CASE-'), 'title' => 'Support case',
            'category' => 'personal', 'status' => 'open', 'opened_at' => date('Y-m-d H:i:s'),
            'summary_notes' => 'PRIVATE COUNSELLING SUMMARY']);
    }

    public function testBothStaffRoutesDeriveStoredRoutingAndReturnSentRedirectWithoutRoutingFields(): void
    {
        foreach (['clinic' => $this->clinic, 'guidance' => $this->guidance] as $unit => $actor) {
            $payload = $this->payload($unit);
            $response = $this->postAs($actor, $unit . '/referrals/store', $payload);
            $response->assertStatus(200);
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertTrue($json['success']);
            $this->assertSame(site_url('/' . $unit . '/referrals?tab=sent'), $json['redirect']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $ref = $this->latest();
            $this->assertSame($unit, $ref['source_module']);
            $this->assertSame($unit === 'clinic' ? 'guidance' : 'clinic', $ref['target_module']);
            $this->assertSame($this->patient, (int) $ref['patient_person_id']);
            $this->assertSame($actor['id'], (int) $ref['issuer_user_id']);
            $this->assertSame($payload['request_key'], $ref['request_key']);
            $this->assertSame('submitted', $ref['status']);
            $this->assertSame('Brief shared support request.', $ref['reason_description']);
            $this->postAs($actor, $unit . '/referrals/store', $payload)->assertStatus(200);
        }
        $this->assertSame(2, $this->countReferrals());
        $this->postAs($this->clinic, 'clinic/referrals/store', $this->payload(), true, false)
            ->assertRedirectTo(site_url('/clinic/referrals?tab=sent'));
    }

    public function testForgedRoutingAndInvalidKeysSummariesAndDestinationReasonsNeverInsert(): void
    {
        foreach (['clinic' => $this->clinic, 'guidance' => $this->guidance] as $unit => $actor) {
            $other = $unit === 'clinic' ? 'guidance' : 'clinic';
            $invalid = [
                [['source_module' => $other], 403], [['source_module' => ['bad']], 422],
                [['target_module' => $unit], 422], [['target_module' => ['bad']], 422],
                [['reason_code' => $unit === 'clinic' ? 'medical_assessment' : 'academic_distress'], 422],
            ];
            foreach (['', 'abc', str_repeat('g', 64), ['bad'], null] as $key) {
                $invalid[] = [['request_key' => $key], 422];
            }
            foreach (['', '   ', str_repeat('x', 2001), ['bad'], null] as $summary) {
                $invalid[] = [['reason_description' => $summary], 422];
            }
            foreach ($invalid as [$changes, $status]) {
                $response = $this->postAs($actor, $unit . '/referrals/store', array_replace($this->payload($unit), $changes));
                $response->assertStatus($status);
                $this->assertFalse(json_decode($response->getJSON(), true)['success']);
                $this->assertSame('', $response->response()->getHeaderLine('Location'));
            }
            $payload = $this->payload($unit);
            unset($payload['request_key']);
            $this->postAs($actor, $unit . '/referrals/store', $payload)->assertStatus(422);
        }
        $this->assertSame(0, $this->countReferrals());
        foreach (['x', str_repeat('x', 2000)] as $summary) {
            $this->postAs($this->clinic, 'clinic/referrals/store', array_replace($this->payload(),
                ['reason_description' => $summary]))->assertStatus(200);
        }
    }

    public function testLegacyBookmarksUseOwnCanonicalRouteAndAdminMustChooseExplicitWorkstation(): void
    {
        foreach (['clinic' => $this->clinic, 'guidance' => $this->guidance] as $unit => $actor) {
            $this->getAs($actor, 'referrals')->assertRedirectTo(site_url('/' . $unit . '/referrals'));
            $this->getAs($actor, 'referrals', ['target' => $unit])->assertRedirectTo(site_url('/' . $unit . '/referrals'));
            $this->getAs($actor, 'referrals', ['target' => $unit === 'clinic' ? 'guidance' : 'clinic'])->assertStatus(403);
        }
        $chooser = $this->getAs($this->admin, 'referrals');
        $chooser->assertStatus(200);
        $chooser->assertSee(site_url('/clinic/referrals'));
        $chooser->assertSee(site_url('/guidance/referrals'));
        $this->postAs($this->admin, 'referrals/store', $this->payload())->assertStatus(422);
        $this->assertSame(0, $this->countReferrals());
        foreach (['clinic', 'guidance'] as $unit) {
            $this->getAs($this->admin, 'referrals', ['target' => $unit])->assertRedirectTo(site_url('/' . $unit . '/referrals'));
            $this->getAs($this->admin, $unit . '/referrals')->assertStatus(200);
            $this->postAs($this->admin, $unit . '/referrals/store', $this->payload($unit))->assertStatus(200);
            $this->assertSame($unit, $this->latest()['source_module']);
        }
        foreach (['bad', '', ['clinic']] as $bad) {
            $this->getAs($this->admin, 'referrals', ['target' => $bad])->assertStatus(422);
        }
    }

    public function testCreateFormUsesCanonicalActionOpaqueKeyAndDestinationReasonsWithoutRouteInputs(): void
    {
        foreach (['clinic' => $this->clinic, 'guidance' => $this->guidance] as $unit => $actor) {
            $response = $this->getAs($actor, $unit . '/referrals/create');
            $response->assertStatus(200);
            $xpath = $this->xpath($response->response()->getBody());
            $this->assertCount(1, $xpath->query('//form[@action="' . site_url('/' . $unit . '/referrals/store') . '"]'));
            $keys = $xpath->query('//input[@name="request_key"]');
            $this->assertCount(1, $keys);
            $this->assertMatchesRegularExpression('/^[a-fA-F0-9]{64}$/D', $keys->item(0)->getAttribute('value'));
            $this->assertCount(0, $xpath->query('//*[@name="source_module" or @name="target_module" or @name="workstation"]'));
            $this->assertCount(1, $xpath->query('//form[@action="' . site_url('/' . $unit . '/referrals/store') . '"]//input[@name="' . csrf_token() . '"]'));
            $this->assertCount(1, $xpath->query('//textarea[@name="reason_description" and @required and @maxlength="2000"]'));
            $values = [];
            foreach ($xpath->query('//select[@name="reason_code"]/option') as $option) {
                if ($option->getAttribute('value') !== '') {
                    $values[] = $option->getAttribute('value');
                }
            }
            $this->assertSame(array_keys((new ReferralService())->reasonOptions($unit === 'clinic' ? 'guidance' : 'clinic')), $values);
        }
    }

    public function testOptionalCreatePrefillIsSamePatientMinimalAndDoesNotCopyClinicalNotes(): void
    {
        $encounter = $this->encounter($this->patient);
        $data = [];
        $this->captureView($data);
        $this->getAs($this->clinic, 'clinic/referrals/create')->assertStatus(200);
        $this->assertNull($data['patient']);
        $this->assertNull($data['sourceEncounterId']);
        foreach ([['person_id' => $this->patient], ['source_encounter_id' => $encounter],
            ['person_id' => $this->patient, 'source_encounter_id' => $encounter]] as $query) {
            $this->getAs($this->clinic, 'clinic/referrals/create', $query)->assertStatus(200);
            $this->assertSame($this->patient, (int) $data['patient']['id']);
            $this->assertSame(['id', 'first_name', 'last_name', 'mis_id', 'kind'], array_keys($data['patient']));
            $this->assertArrayNotHasKey('encounter', $data);
            $this->assertStringNotContainsString('PRIVATE', json_encode($data['patient']));
        }
        $this->getAs($this->clinic, 'clinic/referrals/create', ['person_id' => $this->person(), 'source_encounter_id' => $encounter])->assertStatus(422);
        $this->getAs($this->clinic, 'clinic/referrals/create', ['source_encounter_id' => PHP_INT_MAX])->assertStatus(404);
        $this->getAs($this->clinic, 'clinic/referrals/create', ['person_id' => PHP_INT_MAX])->assertStatus(404);
        $this->getAs($this->guidance, 'guidance/referrals/create', ['source_encounter_id' => $encounter])->assertStatus(403);
        $this->postAs($this->clinic, 'clinic/referrals/store', $this->payload() + ['source_encounter_id' => $encounter])->assertStatus(200);
        $this->assertSame($encounter, (int) $this->latest()['source_encounter_id']);
    }

    public function testFreshReadGrantsRolesAndActiveStatusOverrideCachedSessionAuthority(): void
    {
        foreach (['clinic' => [$this->clinic, 'clinic_staff'], 'guidance' => [$this->guidance, 'counsellor']] as $unit => [$actor, $role]) {
            $this->getAs($actor, $unit . '/referrals')->assertStatus(200);
            $this->revoke($role, 'referrals.read');
            $this->getAs($actor, $unit . '/referrals', ['fragment' => '1'])->assertStatus(403);
            $this->getAs($actor, $unit . '/referrals/create')->assertStatus(403);
            $this->postAs($actor, $unit . '/referrals/store', $this->payload($unit))->assertStatus(403);
        }
        $actor = $this->account('superadmin');
        (new UserModel())->update($actor['id'], ['status' => 'suspended']);
        $this->getAs($actor, 'clinic/referrals')->assertRedirectTo('/auth/login');
        foreach (['student', 'employee', 'bmg_admin', 'audit_reader'] as $role) {
            $this->getAs($this->account($role), 'referrals')->assertStatus(403);
        }
        $this->assertSame(0, $this->countReferrals());
    }

    public function testTabsCannotSwitchDepartmentAndSentIncomingHistoryContainOnlyTheirRows(): void
    {
        $out = $this->referral();
        $in = $this->referral('guidance');
        $closed = $this->referral();
        $this->postAs($this->guidance, 'guidance/referrals/' . $closed['id'] . '/acknowledge')->assertStatus(200);
        $this->postAs($this->guidance, 'guidance/referrals/' . $closed['id'] . '/close')->assertStatus(200);
        foreach (['clinic' => $this->clinic, 'guidance' => $this->guidance] as $unit => $actor) {
            foreach (['incoming', 'sent', 'history'] as $tab) {
                $response = $this->getAs($actor, $unit . '/referrals', ['tab' => $tab, 'target' => $unit === 'clinic' ? 'guidance' : 'clinic']);
                $response->assertStatus(200);
                $expected = $tab === 'history' ? $closed : (($unit === 'clinic') === ($tab === 'sent') ? $out : $in);
                foreach ([$out, $in, $closed] as $ref) {
                    if ($ref['id'] === $expected['id']) {
                        $response->assertSee($ref['referral_code']);
                    } else {
                        $response->assertDontSee($ref['referral_code']);
                    }
                }
                $this->getAs($actor, ($unit === 'clinic' ? 'guidance' : 'clinic') . '/referrals', ['tab' => $tab, 'fragment' => '1'])->assertStatus(403);
            }
        }
        $this->getAs($this->clinic, 'clinic/referrals', ['tab' => 'bad'])->assertStatus(422);
    }

    public function testLiveFragmentPaginatesAndSearchesSentAndHistoryWithStableCounts(): void
    {
        $refs = [];
        for ($i = 0; $i < 27; $i++) {
            $refs[] = $this->referral();
        }
        foreach (['sent', 'history'] as $tab) {
            if ($tab === 'history') {
                foreach ($refs as $ref) {
                    $this->postAs($this->guidance, 'guidance/referrals/' . $ref['id'] . '/acknowledge')->assertStatus(200);
                    $this->postAs($this->guidance, 'guidance/referrals/' . $ref['id'] . '/close')->assertStatus(200);
                }
            }
            $full = $this->getAs($this->clinic, 'clinic/referrals', ['tab' => $tab]);
            $full->assertStatus(200);
            $full->assertSee('data-live-table');
            $full->assertSee('live-table.js');
            $response = $this->getAs($this->clinic, 'clinic/referrals', ['tab' => $tab, 'fragment' => '1', 'page' => '999', 'per_page' => '25']);
            $response->assertStatus(200);
            $body = $response->response()->getBody();
            $this->assertStringNotContainsString('<!DOCTYPE', strtoupper($body));
            $this->assertStringNotContainsString('live-table.js', $body);
            $xpath = $this->xpath($body);
            $meta = $xpath->query('//*[@data-table-pagination]');
            $this->assertCount(1, $meta);
            foreach (['page' => 2, 'per-page' => 25, 'total' => 27, 'filtered' => 27] as $key => $value) {
                $this->assertSame((string) $value, $meta->item(0)->getAttribute('data-' . $key));
            }
            $this->assertCount(2, $xpath->query('//table/tbody/tr'));
            $response->assertSee($refs[0]['referral_code']);
            $response->assertSee($refs[1]['referral_code']);
            $response->assertDontSee($refs[2]['referral_code']);
            $search = $this->getAs($this->clinic, 'clinic/referrals', ['tab' => $tab, 'fragment' => '1', 'q' => $refs[10]['referral_code']]);
            $search->assertStatus(200);
            $search->assertSee($refs[10]['referral_code']);
            $search->assertDontSee($refs[9]['referral_code']);
            $meta = $this->xpath($search->response()->getBody())->query('//*[@data-table-pagination]')->item(0);
            $this->assertNotNull($meta);
            $this->assertSame('1', $meta->getAttribute('data-filtered'));
            $this->assertSame('27', $meta->getAttribute('data-total'));
        }
    }

    public function testReceivingActionsRequireAcknowledgementCorrectContextAndProperState(): void
    {
        foreach (['clinic', 'guidance'] as $source) {
            $ref = $this->referral($source);
            $target = $ref['target_module'];
            $sender = $source === 'clinic' ? $this->clinic : $this->guidance;
            $receiver = $source === 'clinic' ? $this->guidance : $this->clinic;
            $base = $target . '/referrals/' . $ref['id'];
            foreach (['acknowledge', 'review', 'close', 'handoff'] as $action) {
                $this->postAs($sender, $source . '/referrals/' . $ref['id'] . '/' . $action, ['mode' => 'create'])->assertStatus(403);
            }
            $this->getAs($sender, $source . '/referrals/' . $ref['id'] . '/handoff')->assertStatus(403);
            $this->getAs($receiver, $base . '/handoff')->assertStatus(409);
            foreach (['review', 'close', 'handoff'] as $action) {
                $this->postAs($receiver, $base . '/' . $action, ['mode' => 'create'])->assertStatus(409);
            }
            $this->assertSame('submitted', $this->record((int) $ref['id'])['status']);
            $this->postAs($receiver, $base . '/acknowledge')->assertStatus(200);
            $this->assertSame('acknowledged', $this->record((int) $ref['id'])['status']);
            $this->postAs($receiver, $base . '/acknowledge')->assertStatus(409);
            $this->postAs($receiver, $base . '/review')->assertStatus(200);
            $this->assertSame('under_review', $this->record((int) $ref['id'])['status']);
            $this->postAs($receiver, $base . '/review')->assertStatus(409);
            $response = $this->postAs($receiver, $base . '/close');
            $response->assertStatus(200);
            $this->assertSame(site_url('/' . $target . '/referrals?tab=history'), json_decode($response->getJSON(), true)['redirect']);
            $this->assertSame('closed', $this->record((int) $ref['id'])['status']);
            $this->postAs($receiver, $base . '/close')->assertStatus(409);
            $this->getAs($receiver, $base . '/handoff')->assertStatus(409);
        }
    }

    public function testIncomingButtonsFollowAcknowledgementAndSourceSentViewHasNoMutations(): void
    {
        foreach (['clinic', 'guidance'] as $source) {
            $ref = $this->referral($source);
            $receiver = $source === 'clinic' ? $this->guidance : $this->clinic;
            $sender = $source === 'clinic' ? $this->clinic : $this->guidance;
            $base = site_url('/' . $ref['target_module'] . '/referrals/' . $ref['id']);
            $response = $this->getAs($receiver, $ref['target_module'] . '/referrals');
            $response->assertSee($base . '/acknowledge');
            foreach (['handoff', 'review', 'close'] as $action) {
                $response->assertDontSee($base . '/' . $action);
            }
            $sent = $this->getAs($sender, $source . '/referrals', ['tab' => 'sent']);
            foreach (['acknowledge', 'review', 'close', 'handoff'] as $action) {
                $sent->assertDontSee('/referrals/' . $ref['id'] . '/' . $action);
            }
            $this->postAs($receiver, $ref['target_module'] . '/referrals/' . $ref['id'] . '/acknowledge')->assertStatus(200);
            $response = $this->getAs($receiver, $ref['target_module'] . '/referrals');
            $response->assertDontSee($base . '/acknowledge');
            foreach (['handoff', 'review', 'close'] as $action) {
                $response->assertSee($base . '/' . $action);
            }
        }
    }

    public function testFreshActionGrantsAreRequiredEvenWithCachedWildcard(): void
    {
        foreach (['acknowledge' => 'referrals.acknowledge', 'review' => 'referrals.review', 'close' => 'referrals.close'] as $action => $permission) {
            $ref = $this->referral();
            if ($action !== 'acknowledge') {
                (new ReferralService())->acknowledge((int) $ref['id'], $this->guidance['id'], $this->guidance);
            }
            $before = $this->record((int) $ref['id'])['status'];
            $this->revoke('counsellor', $permission);
            $this->postAs($this->guidance, 'guidance/referrals/' . $ref['id'] . '/' . $action)->assertStatus(403);
            $this->assertSame($before, $this->record((int) $ref['id'])['status']);
            // Restore between scenarios so setup can acknowledge the next referral.
            $permissionId = (int) $this->db->table('permissions')->where('code', $permission)->get()->getRowArray()['id'];
            [$role, $id, $existed] = $this->grants['counsellor:' . $permissionId];
            if ($existed) {
                $this->db->table('role_permissions')->insert(['role' => $role, 'permission_id' => $id]);
            }
        }
    }

    public function testHandoffModalIsReadOnlyMinimalAndLinkingHonorsPatientAndClinicalOwnership(): void
    {
        $ref = $this->referral();
        $other = $this->account('counsellor');
        $ownCase = $this->caseId($this->patient, $this->guidance['id']);
        $foreignOwner = $this->caseId($this->patient, $other['id']);
        $wrongPatient = $this->caseId($this->person(), $this->guidance['id']);
        $base = 'guidance/referrals/' . $ref['id'];
        $this->postAs($this->guidance, $base . '/acknowledge')->assertStatus(200);
        $before = $this->db->table('counselling_cases')->where('tenant_id', $this->tenant)->countAllResults();
        $data = [];
        $this->captureView($data);
        $this->getAs($this->guidance, $base . '/handoff')->assertStatus(200);
        $this->assertTrue($data['formModalPage']);
        $this->assertSame(site_url('/' . $base . '/handoff'), $data['formAction']);
        $this->assertSame(['id', 'first_name', 'last_name', 'mis_id', 'kind'], array_keys($data['patient']));
        $this->assertSame([$ownCase], array_map('intval', array_column($data['cases'], 'id')));
        $this->assertSame([], $data['encounters']);
        $this->assertStringNotContainsString('PRIVATE', json_encode([$data['patient'], $data['cases']]));
        $this->assertSame($before, $this->db->table('counselling_cases')->where('tenant_id', $this->tenant)->countAllResults());
        $this->assertSame('acknowledged', $this->record((int) $ref['id'])['status']);
        Services::resetSingle('renderer');
        $modal = $this->getAs($this->guidance, $base . '/handoff');
        $modal->assertStatus(200);
        $this->assertCount(0, $this->xpath($modal->response()->getBody())->query('//*[@name="patient_person_id" or @name="person_id"]'));
        foreach ([$foreignOwner, $wrongPatient] as $id) {
            $this->postAs($this->guidance, $base . '/handoff', ['mode' => 'link', 'target_id' => $id])->assertStatus(422);
        }
        $this->postAs($this->guidance, $base . '/handoff', ['mode' => 'link', 'target_id' => $ownCase])->assertStatus(200);
        $this->assertSame($ownCase, (int) $this->record((int) $ref['id'])['target_case_id']);
        $this->assertSame('converted_to_case', $this->record((int) $ref['id'])['status']);
    }

    public function testHandoffCreatesReceivingOwnedClinicalRecordsAndClinicCanLinkExistingVisit(): void
    {
        foreach (['clinic', 'guidance'] as $source) {
            $ref = $this->referral($source);
            $receiver = $source === 'clinic' ? $this->guidance : $this->clinic;
            $base = $ref['target_module'] . '/referrals/' . $ref['id'];
            $this->postAs($receiver, $base . '/acknowledge')->assertStatus(200);
            $response = $this->postAs($receiver, $base . '/handoff?fragment=1', ['mode' => 'create', 'title' => 'Receiving team support',
                'category' => 'personal', 'patient_person_id' => $this->person(), 'counsellor_user_id' => $this->admin['id'],
                'attending_user_id' => $this->admin['id']]);
            $response->assertStatus(200);
            $stored = $this->record((int) $ref['id']);
            $clinic = $ref['target_module'] === 'clinic';
            $id = (int) $stored[$clinic ? 'target_encounter_id' : 'target_case_id'];
            $model = $clinic ? new ClinicEncounterModel() : new CounsellingCaseModel();
            $clinical = $model->scoped()->find($id);
            $this->assertSame($this->patient, (int) $clinical['person_id']);
            $this->assertSame($receiver['id'], (int) $clinical[$clinic ? 'attending_user_id' : 'counsellor_user_id']);
            $this->assertSame(site_url(($clinic ? '/clinic/encounters/' : '/guidance/cases/') . $id), json_decode($response->getJSON(), true)['redirect']);
            $this->postAs($receiver, $base . '/handoff', ['mode' => 'create', 'title' => 'Retry', 'category' => 'personal'])->assertStatus(200);
            $this->assertSame($id, (int) $this->record((int) $ref['id'])[$clinic ? 'target_encounter_id' : 'target_case_id']);
        }
        $ref = $this->referral('guidance');
        $base = 'clinic/referrals/' . $ref['id'];
        $this->postAs($this->clinic, $base . '/acknowledge')->assertStatus(200);
        $this->postAs($this->clinic, $base . '/handoff', ['mode' => 'link', 'target_id' => $this->encounter($this->person())])->assertStatus(422);
        $encounter = $this->encounter($this->patient);
        $this->postAs($this->clinic, $base . '/handoff', ['mode' => 'link', 'target_id' => $encounter])->assertStatus(200);
        $this->assertSame($encounter, (int) $this->record((int) $ref['id'])['target_encounter_id']);
    }

    public function testHandoffRechecksClinicalReadAndCreateGrants(): void
    {
        $ref = $this->referral();
        $base = 'guidance/referrals/' . $ref['id'];
        $this->postAs($this->guidance, $base . '/acknowledge')->assertStatus(200);
        foreach (['guidance.cases.read', 'guidance.cases.create'] as $permission) {
            $this->revoke('counsellor', $permission);
            $this->getAs($this->guidance, $base . '/handoff')->assertStatus(403);
            $this->postAs($this->guidance, $base . '/handoff', ['mode' => 'create', 'title' => 'Support', 'category' => 'personal'])->assertStatus(403);
            $this->assertNull($this->record((int) $ref['id'])['target_case_id']);
            $id = (int) $this->db->table('permissions')->where('code', $permission)->get()->getRowArray()['id'];
            if ($this->grants['counsellor:' . $id][2]) {
                $this->db->table('role_permissions')->insert(['role' => 'counsellor', 'permission_id' => $id]);
            }
        }
    }

    public function testAllMutationRoutesRejectMissingInvalidAndMalformedCsrfWithoutChanges(): void
    {
        foreach (['clinic', 'guidance'] as $source) {
            $ref = $this->referral($source);
            $unit = $ref['target_module'];
            $receiver = $source === 'clinic' ? $this->guidance : $this->clinic;
            $base = $unit . '/referrals/' . $ref['id'];
            $routes = [$source . '/referrals/store' => [$source === 'clinic' ? $this->clinic : $this->guidance, $this->payload($source)]];
            foreach (['acknowledge', 'review', 'close', 'handoff'] as $action) {
                $routes[$base . '/' . $action] = [$receiver, ['mode' => 'create', 'title' => 'Support', 'category' => 'personal']];
            }
            $before = $this->countReferrals();
            foreach ($routes as $route => [$actor, $payload]) {
                foreach ([null, str_repeat('0', 64), ['bad']] as $token) {
                    $data = $token === null ? $payload : $payload + [csrf_token() => $token];
                    $response = $this->postAs($actor, $route, $data, false);
                    $response->assertStatus(403);
                    $this->assertFalse(json_decode($response->getJSON(), true)['success']);
                    $this->assertSame($before, $this->countReferrals());
                    $this->assertSame('submitted', $this->record((int) $ref['id'])['status']);
                }
            }
        }
    }

    public function testMaliciousPatientIssuerAndReferralSummaryAreEscapedInFullAndFragmentViews(): void
    {
        $malicious = '<img src=x onerror=alert(1)>';
        (new PersonModel())->update($this->patient, ['first_name' => $malicious]);
        $issuer = (new UserModel())->find($this->clinic['id']);
        (new PersonModel())->update($issuer['person_id'], ['first_name' => $malicious]);
        $this->postAs($this->clinic, 'clinic/referrals/store', array_replace($this->payload(), ['reason_description' => $malicious]))->assertStatus(200);
        $maliciousCode = '<svg onload=alert(2)>';
        (new ReferralModel())->update($this->latest()['id'], ['referral_code' => $maliciousCode]);
        foreach ([[], ['fragment' => '1']] as $query) {
            $response = $this->getAs($this->guidance, 'guidance/referrals', $query);
            $response->assertStatus(200);
            $body = $response->response()->getBody();
            $this->assertStringContainsString(esc($malicious), $body);
            $this->assertStringNotContainsString($malicious, $body);
            $this->assertStringContainsString(esc($maliciousCode), $body);
            $this->assertStringNotContainsString($maliciousCode, $body);
            $this->assertCount(0, $this->xpath($body)->query('//*[@data-table-content]//*[@onerror or @onload]'));
            foreach (['PRIVATE ADDRESS', 'PRIVATE PHONE', 'private@example.test'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
            $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        }
    }
}
