<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Controllers\ClinicController;
use App\Controllers\ClinicInventoryController;
use App\Services\Clinic\InventoryService;
use App\Services\CurrentTenant;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\View\View;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

/** Controller HTTP contracts use a mock connection and renderer, never live data. */
final class DirectoryPaginationHttpTest extends CIUnitTestCase
{
    private array $connections;

    protected function setUp(): void
    {
        parent::setUp();
        Services::resetSingle('response');
        Services::resetSingle('renderer');
        $this->connections = (new \ReflectionProperty(Database::class, 'instances'))->getValue();
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
        Services::resetSingle('renderer');
        Services::resetSingle('response');
        CurrentTenant::reset();
        session()->remove('user');
        parent::tearDown();
    }

    private function database(?array $actor = ['role'=>'clinic_staff', 'status'=>'active'], array $grants = [['code'=>'clinic.patients.read']], bool $unavailable = false): InventoryService
    {
        CurrentTenant::set(81);
        $db = $this->createMock(BaseConnection::class);
        $db->method('table')->willReturnCallback(function ($table) use ($actor, $grants, $unavailable) {
            if ($unavailable) {
                throw new \RuntimeException('Secret database connection details');
            }
            $builder = $this->createMock(BaseBuilder::class);
            foreach (['select','where','whereIn','join','orderBy'] as $method) {
                $builder->method($method)->willReturnSelf();
            }
            $result = $this->createMock(BaseResult::class);
            $result->method('getRowArray')->willReturn($actor);
            $result->method('getResultArray')->willReturn($table === 'role_permissions rp' ? $grants : []);
            $builder->method('get')->willReturn($result);
            $builder->method('countAllResults')->willReturn($table === 'role_permissions rp' ? 1 : 0);
            return $builder;
        });
        $db->method('prefixTable')->willReturnArgument(0);
        $db->method('protectIdentifiers')->willReturnArgument(0);
        $result = $this->createMock(BaseResult::class);
        $result->method('getRowArray')->willReturn(['filtered'=>0, 'low'=>0, 'expired'=>0, 'quarantined'=>0, 'near'=>0]);
        $result->method('getResultArray')->willReturn([]);
        $db->method('query')->willReturn($result);
        (new \ReflectionProperty(Database::class, 'instances'))->setValue(null, ['tests'=>$db]);
        $inventory = new InventoryService($db);
        (new \ReflectionProperty(InventoryService::class, 'installed'))->setValue($inventory, true);
        return $inventory;
    }

    private function invoke(string $type, array $query, bool $ajax = false)
    {
        $request = $this->createMock(IncomingRequest::class);
        $request->method('getGet')->willReturnCallback(static fn ($key = null) => $key === null ? $query : ($query[$key] ?? null));
        $request->method('isAJAX')->willReturn($ajax);
        $class = $type === 'patients' ? ClinicController::class : ClinicInventoryController::class;
        $controller = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach (['request'=>$request, 'response'=>service('response')] as $property=>$value) {
            (new \ReflectionProperty($class, $property))->setValue($controller, $value);
        }
        if ($type === 'inventory') {
            (new \ReflectionProperty($class, 'inventory'))->setValue($controller, $this->inventory);
        }
        return $type === 'patients' ? $controller->patients() : $controller->index();
    }

    private InventoryService $inventory;

    public static function endpoints(): iterable
    {
        yield ['patients'];
        yield ['inventory'];
    }

    #[DataProvider('endpoints')]
    public function testFullAndFragmentHaveSameViewDataAndPrivateHeaders(string $type): void
    {
        $this->inventory = $this->database();
        session()->set('user', ['id'=>5, 'role'=>'superadmin', 'permissions'=>['clinic.encounters.create']]);
        $dataSets = [];
        $renderer = $this->createMock(View::class);
        $renderer->method('setData')->willReturnCallback(static function ($data) use (&$dataSets, $renderer) {
            $dataSets[] = $data;
            return $renderer;
        });
        $renderedViews = [];
        $renderer->method('render')->willReturnCallback(static function ($view) use (&$renderedViews) { $renderedViews[] = $view; return 'results'; });
        Services::injectMock('renderer', $renderer);
        foreach ([[], ['fragment'=>'1']] as $query) {
            $response = $this->invoke($type, $query);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertSame('X-Requested-With', $response->getHeaderLine('Vary'));
            $this->assertSame($query === [] ? 'results' : '<div data-table-content>results</div>', $response->getBody());
            $data = $dataSets[array_key_last($dataSets)];
            $this->assertSame(['page'=>1, 'per_page'=>25, 'total'=>0, 'filtered'=>0, 'pages'=>1, 'from'=>0, 'to'=>0], $data['pagination']);
            $this->assertSame(site_url('/clinic/' . ($type === 'patients' ? 'patients' : 'inventory')), $data['baseUrl']);
            $this->assertSame(['q'=>'', $type === 'patients' ? 'kind' : 'status'=>'all', 'per_page'=>25], $data['filters']);
            if ($type === 'patients') {
                $this->assertFalse($data['canStartVisit'], 'Session permissions must not grant Start Visit.');
            } else {
                $this->assertSame(['low'=>0, 'expired'=>0, 'quarantined'=>0, 'near'=>0], $data['counts']);
            }
        }
        $prefix = $type === 'patients' ? 'clinic/patients/' : 'inventory/';
        $this->assertSame([$prefix . 'index', $prefix . '_results'], $renderedViews);
        $this->assertSame($dataSets[0], $dataSets[1]);
    }

    #[DataProvider('endpoints')]
    public function testInvalidParametersAre422JsonForFragmentsAndEscapedForFullRequests(string $type): void
    {
        $this->inventory = $this->database();
        session()->set('user', ['id'=>5]);
        foreach (['q'=>[], 'page'=>'1000001', 'per_page'=>'26', 'kind'=>[], 'status'=>[]] as $key=>$value) {
            $response = $this->invoke($type, [$key=>$value, 'fragment'=>'1']);
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame(['message'], array_keys(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)));
            $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
        Services::resetSingle('response');
        $response = $this->invoke($type, ['status'=>'<script>']);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Invalid medicine status.', $response->getBody());
        $this->assertStringNotContainsString('<script>', $response->getBody());
    }

    #[DataProvider('endpoints')]
    public function testFragmentDenialsAre403AndOutages503WithoutLoginHtmlOrSecrets(string $type): void
    {
        session()->set('user', ['id'=>5, 'role'=>'superadmin']);
        foreach ([null, ['role'=>'clinic_staff','status'=>'disabled'], ['role'=>'student','status'=>'active']] as $actor) {
            $this->inventory = $this->database($actor);
            $response = $this->invoke($type, ['fragment'=>'1']);
            $this->assertSame(403, $response->getStatusCode());
            $this->assertSame(['message'], array_keys(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)));
            $this->assertFalse($response->hasHeader('Location'));
        }
        $this->inventory = $this->database(unavailable: true);
        $response = $this->invoke($type, ['fragment'=>'1']);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertStringNotContainsString('Secret', $response->getBody());
        $this->assertSame(['message'], array_keys(json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR)));
    }

    public function testInventoryFragmentSetupPendingIsMessageOnlyAndPrivate(): void
    {
        session()->set('user', ['id'=>5]);
        $this->inventory = $this->database();
        (new \ReflectionProperty(InventoryService::class, 'installed'))->setValue($this->inventory, false);
        $response = $this->invoke('inventory', ['fragment'=>'1']);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(['message'=>InventoryService::SETUP_PENDING_MESSAGE], json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Location'));
    }

    public function testPatientReadPermissionAndCreatePermissionAreFreshAndIndependent(): void
    {
        session()->set('user', ['id'=>5, 'permissions'=>['clinic.patients.read']]);
        $this->inventory = $this->database(grants: [['code'=>'clinic.encounters.create']]);
        $response = $this->invoke('patients', ['fragment'=>'1']);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('clinic.patients.read', $response->getBody());
        $this->database(grants: [['code'=>'clinic.patients.read'], ['code'=>'clinic.encounters.create']]);
        $this->assertTrue((new \App\Services\Clinic\PatientDirectoryService())->assertAccess(5));
    }
}
