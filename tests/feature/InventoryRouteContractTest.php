<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ClinicInventoryController;
use App\Filters\AppointmentCsrfFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/** Route/filter checks deliberately do not connect to or migrate a database. */
final class InventoryRouteContractTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function tearDown(): void
    {
        Services::resetSingle('security');
        service('superglobals')->setCookieArray([]);
        parent::tearDown();
    }

    private function actorWithoutId(): array
    {
        return ['tenant_id' => 1, 'user' => ['role' => 'superadmin', 'logged_in' => true, 'permissions' => []]];
    }

    private function token(): string
    {
        $hash = service('security')->generateHash();
        service('superglobals')->setCookie(config('Cookie')->prefix . config('Security')->cookieName, $hash);
        Services::resetSingle('security');
        return $hash;
    }

    public function testEveryInventoryPostAndEncounterBoundaryRejectsMalformedCsrfBeforeDatabaseAccess(): void
    {
        foreach (['clinic/inventory/store', 'clinic/inventory/1/update', 'clinic/inventory/1/receive',
            'clinic/inventory/batches/1/adjust', 'clinic/inventory/batches/1/status',
            'clinic/inventory/operations/1/reverse', 'clinic/encounters/1/dispense',
            'clinic/encounters/1/complete', 'clinic/encounters/start-for-ticket/1'] as $path) {
            foreach ([null, str_repeat('0', 64), ['malformed']] as $badToken) {
                $this->token();
                $payload = $badToken === null ? [] : [config('Security')->tokenName => $badToken];
                $response = $this->withSession($this->actorWithoutId())
                    ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post($path, $payload);
                $response->assertStatus(403);
                $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
                $this->assertFalse($json['success']);
                $this->assertTrue($json['reload']);
                $this->assertSame(csrf_token(), $json['csrf']['name']);
                $this->assertNotEmpty($json['csrf']['hash']);
                $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
            }
        }
    }

    public function testValidCsrfReachesExplicitActorGuardAndReturnsRotatedToken(): void
    {
        foreach (['clinic/inventory/store', 'clinic/inventory/1/receive', 'clinic/encounters/1/dispense', 'clinic/encounters/1/complete'] as $path) {
            $token = $this->token();
            $response = $this->withSession($this->actorWithoutId())->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->post($path, [config('Security')->tokenName => $token]);
            $response->assertStatus(403);
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($json['success']);
            $this->assertArrayNotHasKey('reload', $json);
            $this->assertNotSame($token, $json['csrf']['hash']);
        }
    }

    public function testInventoryRoutesRetainAuthenticationAndClinicPortalGate(): void
    {
        $this->withSession([])->withHeaders([])->get('clinic/inventory')->assertRedirectTo('/auth/login');
        $patient = ['tenant_id' => 1, 'user' => ['id' => 1, 'role' => 'student', 'logged_in' => true]];
        $this->withSession($patient)->get('clinic/inventory')->assertRedirectTo('/portal');
    }

    public function testLegacyTicketStartGetIs405WithoutConstructingClinicController(): void
    {
        $response = $this->withSession($this->actorWithoutId())->withHeaders([])->get('clinic/encounters/start-for-ticket/1');
        $response->assertStatus(405);
        $this->assertSame('POST', $response->response()->getHeaderLine('Allow'));
    }

    public function testMissingActorReadIsPlain403AndMissingCsrfPlainPostIs403(): void
    {
        $response = $this->withSession($this->actorWithoutId())->withHeaders([])->get('clinic/inventory');
        $response->assertStatus(403);
        $this->assertFalse($response->isRedirect());
        $this->token();
        $this->withSession($this->actorWithoutId())->post('clinic/inventory/store', [])->assertStatus(403);
    }
    public static function invalidCatalogKeys(): iterable
    {
        foreach ([null, '', [], ['key'], ' ', 'with space', 'slash/key', "line\nbreak", "null\0byte", 'nonascii-é', str_repeat('x', 129)] as $key) {
            yield [$key];
        }
    }

    private function catalogKey(mixed $value, bool $required): ?string
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->expects($this->once())->method('getPost')->with('idempotency_key')->willReturn($value);
        $controller = (new \ReflectionClass(ClinicInventoryController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(ClinicInventoryController::class, 'request'))->setValue($controller, $request);
        return (new \ReflectionMethod(ClinicInventoryController::class, 'catalogKey'))->invoke($controller, $required);
    }

    #[DataProvider('invalidCatalogKeys')]
    public function testCatalogCreateKeyContractRejectsMissingAndMalformedKeys(mixed $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(422);
        $this->expectExceptionMessage('ASCII idempotency key');
        $this->catalogKey($key, true);
    }

    public function testCatalogKeyContractPreservesAcceptedKeyAndAllowsUnkeyedVersionedUpdate(): void
    {
        foreach (['x', 'HTTP.catalog_key:retry-1', bin2hex(random_bytes(32)), str_repeat('x', 128)] as $key) {
            $this->assertSame($key, $this->catalogKey($key, true));
            $this->assertSame($key, $this->catalogKey($key, false));
        }
        $this->assertNull($this->catalogKey(null, false));
    }

    public function testModalFormRetainsAValidRetryKeyWithoutDatabaseAccess(): void
    {
        $key = 'inventory.retained-retry-key';
        session()->setFlashdata('_ci_old_input', ['get' => [], 'post' => ['idempotency_key' => $key]]);
        $renderer = $this->createMock(\CodeIgniter\View\View::class);
        $renderer->expects($this->once())->method('setData')->with($this->callback(function (array $data) use ($key): bool {
            $this->assertSame($key, $data['idempotencyKey']);
            $this->assertSame(site_url('/clinic/inventory/store'), $data['formAction']);
            $this->assertTrue($data['formModalPage']);
            $this->assertSame(['Clinic', 'Medicine Inventory', 'Add Medicine'], array_column($data['breadcrumbs'], 'label'));
            return true;
        }), 'raw')->willReturnSelf();
        $renderer->expects($this->once())->method('render')->with('inventory/catalog', $this->anything(), $this->anything())->willReturn('retained-key form');
        Services::injectMock('renderer', $renderer);
        try {
            $controller = (new \ReflectionClass(ClinicInventoryController::class))->newInstanceWithoutConstructor();
            $result = (new \ReflectionMethod(ClinicInventoryController::class, 'form'))->invoke(
                $controller, 'catalog', 'Add Medicine', '/clinic/inventory/store', '/clinic/inventory', ['medicine' => null]
            );
            $this->assertSame('retained-key form', $result);
        } finally {
            Services::resetSingle('renderer');
            session()->remove('_ci_old_input');
        }
    }

    public function testInventoryBreadcrumbHierarchyAndActiveDestination(): void
    {
        $controller = (new \ReflectionClass(ClinicInventoryController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ClinicInventoryController::class, 'breadcrumbs');
        $medicine = ['id' => 23, 'name' => 'Medicine & strength'];
        foreach ([
            [null, null, ['Clinic', 'Medicine Inventory']],
            [$medicine, null, ['Clinic', 'Medicine Inventory', 'Medicine & strength']],
            [null, 'Add Medicine', ['Clinic', 'Medicine Inventory', 'Add Medicine']],
            [$medicine, 'Receive Medicine', ['Clinic', 'Medicine Inventory', 'Medicine & strength', 'Receive Medicine']],
        ] as [$item, $action, $labels]) {
            $crumbs = $method->invoke($controller, $item, $action);
            $this->assertSame($labels, array_column($crumbs, 'label'));
            $this->assertSame(site_url('/clinic/encounters'), $crumbs[0]['url']);
            $this->assertSame('', $crumbs[array_key_last($crumbs)]['url']);
            if (count($crumbs) > 2) {
                $this->assertSame(site_url('/clinic/inventory'), $crumbs[1]['url']);
            }
            if ($item !== null && $action !== null) {
                $this->assertSame(site_url('/clinic/inventory/23'), $crumbs[2]['url']);
            }
        }
    }

    private function pendingController(bool $ajax, string $role = 'superadmin'): ClinicInventoryController
    {
        session()->set('user', ['id' => 7, 'role' => $role, 'logged_in' => true]);
        $db = $this->createMock(\CodeIgniter\Database\BaseConnection::class);
        $actor = $this->createMock(\CodeIgniter\Database\BaseBuilder::class);
        $rows = $this->createMock(\CodeIgniter\Database\BaseResult::class);
        $rows->method('getRowArray')->willReturn(['id' => 7, 'status' => 'active', 'role' => $role]);
        $actor->method('where')->willReturnSelf();
        $actor->method('get')->willReturn($rows);
        $db->expects($this->once())->method('table')->with('users')->willReturn($actor);
        $db->expects($role === 'superadmin' ? $this->once() : $this->never())->method('tableExists')->willReturn(false);
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('transBegin');
        $request = $this->createMock(IncomingRequest::class);
        $request->method('isAJAX')->willReturn($ajax);
        $controller = (new \ReflectionClass(ClinicInventoryController::class))->newInstanceWithoutConstructor();
        $controller->initController($request, service('response'), service('logger'));
        (new \ReflectionProperty(ClinicInventoryController::class, 'inventory'))->setValue($controller, new \App\Services\Clinic\InventoryService($db));
        return $controller;
    }

    public function testPendingInventoryReadsAndCatalogFormsRenderAvailabilityViewWith503(): void
    {
        foreach (['index' => [], 'show' => [1], 'create' => [], 'edit' => [1], 'receiveForm' => [1],
            'adjustForm' => [1], 'statusForm' => [1], 'reverseForm' => [1]] as $action => $args) {
            $renderer = $this->createMock(\CodeIgniter\View\View::class);
            $renderer->expects($this->once())->method('setData')->with($this->callback(function (array $data): bool {
                $this->assertSame('Clinic Inventory', $data['title']);
                $this->assertSame('Medicine Inventory', $data['pageTitle']);
                $this->assertSame(\App\Services\Clinic\InventoryService::SETUP_PENDING_MESSAGE, $data['message']);
                $this->assertArrayNotHasKey('formAction', $data);
                return true;
            }), 'raw')->willReturnSelf();
            $renderer->expects($this->once())->method('render')->with('inventory/unavailable', $this->anything(), $this->anything())
                ->willReturn('Inventory temporarily unavailable');
            Services::injectMock('renderer', $renderer);
            try {
                $response = $this->pendingController(false)->{$action}(...$args);
                $this->assertSame(503, $response->getStatusCode());
                $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
                $this->assertSame('Inventory temporarily unavailable', $response->getBody());
            } finally {
                Services::resetSingle('renderer');
            }
        }
    }

    public function testPendingAjaxReadsAndStockPostsPreserveErrorAndCsrfContractBeforePayloadOrStockQueries(): void
    {
        foreach (['index' => [], 'create' => [], 'store' => [], 'update' => [1], 'receive' => [1],
            'adjust' => [1], 'status' => [1], 'reverse' => [1]] as $action => $args) {
            session()->remove('success');
            $response = $this->pendingController(true)->{$action}(...$args);
            $this->assertSame(503, $response->getStatusCode());
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertFalse($json['success']);
            $this->assertSame(\App\Services\Clinic\InventoryService::SETUP_PENDING_MESSAGE, $json['message']);
            $this->assertArrayHasKey('redirect', $json);
            $this->assertSame(csrf_token(), $json['csrf']['name']);
            $this->assertNotEmpty($json['csrf']['hash']);
            $this->assertNull(session()->getFlashdata('success'));
        }
    }

    public function testAuthoritativeAccountRoleDenialPrecedesReadinessAndDoesNotLeakSetup(): void
    {
        $response = $this->pendingController(false, 'student')->index();
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('migration', $response->getBody());
    }

    public function testEncounterDispenseAjaxReadinessFailurePreservesErrorContract(): void
    {
        $controller = (new \ReflectionClass(\App\Controllers\ClinicController::class))->newInstanceWithoutConstructor();
        $request = $this->createMock(IncomingRequest::class);
        $request->method('isAJAX')->willReturn(true);
        $controller->initController($request, service('response'), service('logger'));
        $response = (new \ReflectionMethod($controller, 'inventoryFailure'))->invoke($controller,
            new \RuntimeException(\App\Services\Clinic\InventoryService::SETUP_PENDING_MESSAGE, 503), '/clinic/encounters/1');
        $this->assertSame(503, $response->getStatusCode());
        $json = json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($json['success']);
        $this->assertSame(\App\Services\Clinic\InventoryService::SETUP_PENDING_MESSAGE, $json['message']);
        $this->assertSame(csrf_token(), $json['csrf']['name']);
        $this->assertNotEmpty($json['csrf']['hash']);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testEncounterUsesReadinessAndDoesNotRestoreLegacyStockWriterApi(): void
    {
        $source = str_replace("\r\n", "\n", file_get_contents(APPPATH . 'Controllers/ClinicController.php'));
        $this->assertStringContainsString('$canDispense = $inventory->isInstalled();', $source);
        $this->assertStringContainsString('$availableMedicines = $canDispense ? $this->medicineModel->getInStockList() : [];', $source);
        $this->assertStringContainsString('$inventory->assertAccess($actorId, true);' . "\n" . '            $inventory->assertInstalled();', $source);
        $this->assertStringNotContainsString('->dispenseMedicine(', $source);
        $this->assertStringNotContainsString('->deductStock(', $source);
    }

    public function testInventoryRoutesHaveAuthenticationPortalAndPostCsrfFilters(): void
    {
        $routes = service('routes');
        $routes->loadRoutes();
        $expected = [
            'GET' => ['', 'create', '([0-9]+)/edit', '([0-9]+)', '([0-9]+)/receive',
                'batches/([0-9]+)/adjust', 'batches/([0-9]+)/status', 'operations/([0-9]+)/reverse'],
            'POST' => ['store', '([0-9]+)/update', '([0-9]+)/receive',
                'batches/([0-9]+)/adjust', 'batches/([0-9]+)/status', 'operations/([0-9]+)/reverse'],
        ];
        foreach ($expected as $verb => $paths) {
            $registered = $routes->getRoutes($verb);
            foreach ($paths as $path) {
                $route = rtrim('clinic/inventory/' . $path, '/');
                $this->assertArrayHasKey($route, $registered);
                $filters = (array) ($routes->getRoutesOptions($route, $verb)['filter'] ?? []);
                $this->assertContains('auth', $filters);
                $this->assertContains('portal_access:clinic', $filters);
                if ($verb === 'POST') {
                    $this->assertContains('inventory_csrf', $filters);
                }
            }
        }
        $this->assertSame(AppointmentCsrfFilter::class, config('Filters')->aliases['inventory_csrf']);
    }

    public function testEncounterStockAndCompletionPostsUseCsrfAndTicketStartIsPostOnly(): void
    {
        $routes = service('routes');
        $routes->loadRoutes();
        foreach (['clinic/encounters/([0-9]+)/dispense', 'clinic/encounters/([0-9]+)/complete',
            'clinic/encounters/start-for-ticket/([0-9]+)'] as $route) {
            $this->assertArrayHasKey($route, $routes->getRoutes('POST'));
            $filters = (array) ($routes->getRoutesOptions($route, 'POST')['filter'] ?? []);
            $this->assertContains('auth', $filters);
            $this->assertContains('portal_access:clinic', $filters);
            $this->assertContains('inventory_csrf', $filters);
            if (str_contains($route, 'start-for-ticket')) {
                $this->assertInstanceOf(\Closure::class, $routes->getRoutes('GET')[$route]);
            } else {
                $this->assertArrayNotHasKey($route, $routes->getRoutes('GET'));
            }
        }
    }
}
