<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use DOMDocument;
use DOMXPath;
use Tests\Support\InventoryDatabaseTestCase;

/** Real routes, SQL and templates; fixtures belong only to the isolated harness. */
final class DirectoryPaginationRoutesTest extends InventoryDatabaseTestCase
{
    use FeatureTestTrait;

    protected $migrate = false;
    private array $grants = [];

    protected function setUp(): void
    {
        if ((int) (config('Database')->tests['port'] ?? 0) !== 33307) {
            $this->markTestSkipped('Requires the parent-owned isolated inventory harness on port 33307.');
        }
        parent::setUp();
        Services::resetSingle('renderer');
    }

    protected function tearDown(): void
    {
        foreach ($this->grants as [$role, $id, $existed]) {
            $this->db->table('role_permissions')->where('role', $role)->where('permission_id', $id)->delete();
            if ($existed) {
                $this->db->table('role_permissions')->insert(['role'=>$role, 'permission_id'=>$id]);
            }
        }
        Services::resetSingle('renderer');
        Services::resetSingle('security');
        service('superglobals')->setCookieArray([]);
        session()->remove(['user', 'tenant_id']);
        parent::tearDown();
    }

    private function actorSession(mixed $id = null, string $role = 'superadmin'): array
    {
        return ['tenant_id'=>$this->tenantId, 'user'=>[
            'id'=>$id ?? $this->actorId, 'role'=>$role, 'name'=>'Pagination route tester',
            'logged_in'=>true, 'permissions'=>['clinic.patients.read', 'clinic.encounters.create', 'clinic.inventory.read'],
        ]];
    }

    private function grant(string $code, bool $enabled): void
    {
        $permission = $this->db->table('permissions')->where('code', $code)->get()->getRowArray();
        if ($permission === null) {
            $this->db->table('permissions')->insert(['code'=>$code, 'name'=>$code, 'module'=>'clinic']);
            $permission = ['id'=>$this->db->insertID()];
        }
        $id = (int) $permission['id'];
        $key = 'clinic_staff:' . $id;
        if (!isset($this->grants[$key])) {
            $exists = $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $id)->countAllResults() > 0;
            $this->grants[$key] = ['clinic_staff', $id, $exists];
        }
        $this->db->table('role_permissions')->where('role', 'clinic_staff')->where('permission_id', $id)->delete();
        if ($enabled) {
            $this->db->table('role_permissions')->insert(['role'=>'clinic_staff', 'permission_id'=>$id]);
        }
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

    private function assertPage($response, int $page, int $perPage, int $filtered, int $total, int $rows, bool $fragment): void
    {
        $response->assertStatus(200);
        $http = $response->response();
        $body = $http->getBody();
        $this->assertStringContainsString('private', $http->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $http->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('X-Requested-With', $http->getHeaderLine('Vary'));
        $xpath = $this->xpath($body);
        $metadata = $xpath->query('//*[@data-table-pagination]');
        $this->assertCount(1, $metadata);
        foreach (['page'=>$page, 'per-page'=>$perPage, 'filtered'=>$filtered, 'total'=>$total] as $key=>$value) {
            $this->assertSame((string) $value, $metadata->item(0)->getAttribute('data-' . $key));
        }
        $this->assertCount($rows, $xpath->query('//table/tbody/tr'));
        if ($fragment) {
            $this->assertCount(1, $xpath->query('//*[@data-table-content]'));
            $this->assertStringNotContainsString('<!DOCTYPE', strtoupper($body));
            $this->assertStringNotContainsString('live-table.js', $body);
            $this->assertCount(0, $xpath->query('//script'));
        } else {
            $this->assertStringContainsString('data-live-table', $body);
            $this->assertStringContainsString('live-table.js', $body);
        }
        foreach (['1974-02-03', '0900-PRIVATE-PHONE', 'PRIVATE-DIRECTORY-ADDRESS'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function testRealFullViewsAndFragmentsPaginateMoreThanFiftyMatchesAndClampFarPages(): void
    {
        $now = date('Y-m-d H:i:s');
        $people = [];
        $medicines = [];
        for ($i = 0; $i < 60; $i++) {
            $people[] = ['tenant_id'=>$this->tenantId, 'kind'=>'student', 'mis_id'=>sprintf('route-page-%03d', $i),
                'first_name'=>'Pagination', 'last_name'=>'Routepatient', 'phone'=>'0900-PRIVATE-PHONE',
                'date_of_birth'=>'1974-02-03', 'address'=>'PRIVATE-DIRECTORY-ADDRESS', 'created_at'=>$now, 'updated_at'=>$now];
            $medicines[] = ['tenant_id'=>$this->tenantId, 'name'=>sprintf('Route pagination %03d', $i),
                'category'=>'Test', 'dosage_form'=>'Tablet', 'unit_of_measure'=>'tablet', 'minimum_stock_level'=>5,
                'total_stock'=>0, 'is_active'=>1, 'version'=>1, 'created_at'=>$now, 'updated_at'=>$now];
        }
        $this->db->table('people')->insertBatch($people);
        $this->db->table('clinic_medicines')->insertBatch($medicines);
        foreach (['patients'=>'Pagination Routepatient', 'inventory'=>'Route pagination'] as $directory=>$query) {
            $path = 'clinic/' . $directory . '?' . http_build_query(['q'=>$query]);
            $full = $this->withSession($this->actorSession())->withHeaders([])->get($path);
            $this->assertPage($full, 1, 25, 60, 61, 25, false);
            $fragment = $this->withSession($this->actorSession())->withHeaders(['X-Requested-With'=>'XMLHttpRequest'])
                ->get($path . '&fragment=1&page=1000000');
            $this->assertPage($fragment, 3, 25, 60, 61, 10, true);
            $fragment->assertSee('Showing 51–60 of 60');
            if ($directory === 'patients') {
                $fragment->assertSee('route-page-050');
                $fragment->assertSee('route-page-059');
                $fragment->assertDontSee('route-page-049');
            } else {
                $fragment->assertSee('Route pagination 050');
                $fragment->assertSee('Route pagination 059');
                $fragment->assertDontSee('Route pagination 049');
            }
            $large = $this->withSession($this->actorSession())->withHeaders([])->get($path . '&per_page=100&fragment=1');
            $this->assertPage($large, 1, 100, 60, 61, 60, true);
            $empty = $this->withSession($this->actorSession())->get('clinic/' . $directory . '?q=no-such-route-fixture&fragment=1&page=1000000');
            $this->assertPage($empty, 1, 25, 0, 61, 1, true);
            $empty->assertSee('Showing 0–0 of 0');
        }
    }

    public function testFreshReadAndCreateGrantsControlRealPatientFragmentsIndependently(): void
    {
        $staff = $this->user('clinic_staff');
        $this->grant('clinic.patients.read', true);
        $this->grant('clinic.encounters.create', false);
        $path = 'clinic/patients?fragment=1';
        $reader = $this->withSession($this->actorSession($staff))->get($path);
        $reader->assertStatus(200);
        $reader->assertSee('Ledger');
        $reader->assertDontSee('Start Visit');
        $this->grant('clinic.encounters.create', true);
        $writer = $this->withSession($this->actorSession($staff))->get($path);
        $writer->assertStatus(200);
        $writer->assertSee('Start Visit');
        $this->grant('clinic.patients.read', false);
        $denied = $this->withSession($this->actorSession($staff))->get($path);
        $denied->assertStatus(403);
        $this->assertSame(['message'=>'Missing permission: clinic.patients.read'], json_decode($denied->getJSON(), true, 512, JSON_THROW_ON_ERROR));
        $this->grant('clinic.inventory.read', false);
        $this->withSession($this->actorSession($staff))->get('clinic/inventory?fragment=1')->assertStatus(403);
    }

    public function testInvalidQueriesAre422OnActualAuthorizedRoutes(): void
    {
        foreach (['patients','inventory'] as $directory) {
            foreach (['q%5B0%5D=bad&fragment=1', 'page=0&fragment=1', 'page%5B%5D=1&fragment=1',
                'per_page=26&fragment=1', 'per_page%5B%5D=25&fragment=1', 'kind=staff&fragment=1',
                'status=expired&fragment=1', 'q%5B%5D=nested&fragment=1', 'fragment%5B%5D=1'] as $query) {
                $response = $this->withSession($this->actorSession())->withHeaders(['X-Requested-With'=>'XMLHttpRequest'])
                    ->get('clinic/' . $directory . '?' . $query);
                $response->assertStatus(422);
                $this->assertSame(['message'], array_keys(json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR)));
                $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
            }
            $this->withSession($this->actorSession())->withHeaders([])->get('clinic/' . $directory . '?page=1000001')->assertStatus(422);
        }
    }

    public function testAnonymousAndPortalDeniedFragmentsKeepExistingRedirectContract(): void
    {
        foreach (['patients','inventory'] as $directory) {
            $path = 'clinic/' . $directory . '?fragment=1';
            $this->withSession([])->withHeaders(['X-Requested-With'=>'XMLHttpRequest'])->get($path)->assertRedirectTo('/auth/login');
            $this->withSession($this->actorSession($this->actorId, 'student'))->get($path)->assertRedirectTo('/portal');
        }
    }

    public function testForbiddenDatabaseActorStatesAndMalformedIdsDoNotReturnDirectoryHtml(): void
    {
        $inactive = $this->user('clinic_staff');
        $this->db->table('users')->where('id', $inactive)->update(['status'=>'suspended']);
        $nonClinic = $this->user('student');
        $now = date('Y-m-d H:i:s');
        $this->db->table('tenants')->insert(['slug'=>uniqid('pagination_route_'), 'name'=>'Other route tenant', 'is_active'=>1, 'created_at'=>$now, 'updated_at'=>$now]);
        $foreign = $this->user('superadmin', (int) $this->db->insertID());
        foreach (['patients','inventory'] as $directory) {
            foreach ([$inactive, $nonClinic, $foreign, '0', '-1', 'not-an-id', ['nested']] as $id) {
                $response = $this->withSession($this->actorSession($id))->withHeaders(['X-Requested-With'=>'XMLHttpRequest'])
                    ->get('clinic/' . $directory . '?fragment=1');
                $response->assertStatus(403);
                $this->assertSame(['message'], array_keys(json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR)));
                $this->assertStringNotContainsString('<table', $response->response()->getBody());
                $this->assertFalse($response->isRedirect());
            }
        }
    }
}
