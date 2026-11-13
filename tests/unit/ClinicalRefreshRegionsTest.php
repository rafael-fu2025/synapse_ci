<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ClinicController;
use App\Controllers\ClinicInventoryController;
use App\Controllers\GuidanceController;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Psr\Log\NullLogger;
use ReflectionClass;

/** Response and markup contracts only; no application database or clinical/stock writes. */
final class ClinicalRefreshRegionsTest extends CIUnitTestCase
{
    /** @var array<string, mixed> */
    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        session()->set('user', ['id' => 1]);
        $this->environment = $_ENV;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environment;
        session()->remove(['user', 'success', 'error']);
        foreach (['request', 'response', 'redirectresponse'] as $service) {
            Services::resetSingle($service);
        }
        Factories::reset('config');
        parent::tearDown();
    }

    private function controller(string $class): object
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('X-Synapse-Feedback', 'toast');
        $response = new Response(config('App'));
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        Services::injectMock('redirectresponse', new RedirectResponse(config('App')));

        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    private function invoke(object $controller, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller, ...$arguments);
    }

    /** @return array<string, mixed> */
    private function payload(ResponseInterface $response): array
    {
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Location'));
        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function enable(string $owner): void
    {
        $_ENV['refreshRegions.enabled'] = 'true';
        $_ENV['refreshRegions.owners'] = $owner;
        Factories::reset('config');
    }

    public function testGuidanceMutationUsesDetailRegionsOnlyForAllowedCaseOwner(): void
    {
        $this->enable('guidance.case.42');
        $controller = $this->controller(GuidanceController::class);
        service('security')->generateHash();
        $descriptor = $this->invoke($controller, 'caseRefresh', 42);
        $response = $this->invoke($controller, 'mutate', static fn (int $actor): array => [
            '/guidance/cases/42', 'Saved.', $descriptor,
        ]);
        $payload = $this->payload($response);

        self::assertSame(site_url('/guidance/cases/42'), $payload['redirect']);
        self::assertSame([
            'mode' => 'regions', 'owner' => 'guidance.case.42', 'regions' => ['workspace'],
            'url' => site_url('/guidance/cases/42'),
        ], $payload['refresh']);
        self::assertNull($this->invoke($controller, 'caseRefresh', 41));
    }

    public function testGuidanceTrueDestinationUsesExplicitNavigateDescriptor(): void
    {
        $this->enable('guidance.case-list');
        $controller = $this->controller(GuidanceController::class);

        self::assertSame([
            'mode' => 'navigate', 'url' => site_url('/guidance/cases/42'), 'history' => 'push',
        ], $this->invoke($controller, 'navigationRefresh', '/guidance/cases/42', 'guidance.case-list'));
        self::assertNull($this->invoke($controller, 'navigationRefresh', '/guidance/cases/42', 'guidance.case.42'));
    }

    public function testClinicResponsesExposePatientEncounterAndDestinationPlans(): void
    {
        $this->enable('clinic.patient.7');
        $clinic = $this->controller(ClinicController::class);
        service('security')->generateHash();
        $patient = $this->invoke($clinic, 'patientRefresh', 7);
        $payload = $this->payload($this->invoke(
            $clinic,
            'inventoryFormResponse',
            true,
            'Health profile updated.',
            '/clinic/patients/7',
            200,
            [],
            $patient,
        ));
        self::assertSame([
            'mode' => 'regions', 'owner' => 'clinic.patient.7', 'regions' => ['profile', 'history'],
            'url' => site_url('/clinic/patients/7'),
        ], $payload['refresh']);

        $this->enable('clinic.encounter.9');
        $clinic = $this->controller(ClinicController::class);
        self::assertSame([
            'mode' => 'regions', 'owner' => 'clinic.encounter.9',
            'regions' => ['dispensed-items', 'dispense-form'], 'url' => site_url('/clinic/encounters/9'),
        ], $this->invoke($clinic, 'encounterRefresh', 9));

        $this->enable('clinic.encounters');
        $clinic = $this->controller(ClinicController::class);
        self::assertSame([
            'mode' => 'navigate', 'url' => site_url('/clinic/encounters'), 'history' => 'push',
        ], $this->invoke($clinic, 'navigationRefresh', '/clinic/encounters', 'clinic.encounters'));
    }

    public function testInventoryDetailResponseIsAtomicAndCreateNavigates(): void
    {
        $this->enable('inventory.detail.11');
        $inventory = $this->controller(ClinicInventoryController::class);
        service('security')->generateHash();
        $descriptor = $this->invoke($inventory, 'detailRefresh', 11);
        $payload = $this->payload($this->invoke(
            $inventory,
            'respond',
            true,
            'Stock updated.',
            '/clinic/inventory/11',
            ['operation_id' => 91],
            422,
            $descriptor,
        ));
        self::assertSame(91, $payload['operation_id']);
        self::assertSame([
            'mode' => 'regions', 'owner' => 'inventory.detail.11',
            'regions' => ['summary', 'batches', 'operations', 'movements'],
        ], $payload['refresh']);

        $this->enable('inventory.catalog');
        $inventory = $this->controller(ClinicInventoryController::class);
        self::assertSame([
            'mode' => 'navigate', 'url' => site_url('/clinic/inventory/11'), 'history' => 'push',
        ], $this->invoke($inventory, 'navigationRefresh', 11));
    }

    public function testInventoryCatalogSourceSelectsMountedCatalogOwner(): void
    {
        $this->enable('inventory.catalog');
        $inventory = $this->controller(ClinicInventoryController::class);
        service('request')->setHeader('X-Synapse-Refresh-Owner', 'inventory.catalog');
        self::assertSame([
            'mode' => 'regions', 'owner' => 'inventory.catalog', 'regions' => ['catalog'],
        ], $this->invoke($inventory, 'detailRefresh', 11));
    }

    public function testOwnedViewsHaveUniqueStableRegionsAndNoFetchedInlineScripts(): void
    {
        $contracts = [
            'guidance/cases/index.php' => ['guidance.case-list', ['list']],
            'guidance/cases/view.php' => ['guidance.case.', ['workspace']],
            'clinic/patients/chart.php' => ['clinic.patient.', ['profile', 'history']],
            'clinic/encounters/index.php' => ['clinic.encounters', ['list']],
            'clinic/encounters/stepper.php' => ['clinic.encounter.', ['dispensed-items', 'dispense-form']],
            'inventory/index.php' => ['inventory.catalog', ['catalog']],
            'inventory/show.php' => ['inventory.detail.', ['summary', 'batches', 'operations', 'movements']],
        ];

        foreach ($contracts as $view => [$owner, $regions]) {
            $source = file_get_contents(APPPATH . 'Views/' . $view);
            self::assertSame(1, substr_count($source, 'data-refresh-owner='), $view);
            self::assertStringContainsString($owner, $source, $view);
            self::assertSame(count($regions), substr_count($source, 'data-refresh-region='), $view);
            foreach ($regions as $region) {
                self::assertSame(1, substr_count($source, 'data-refresh-region="' . $region . '"'), $view . ':' . $region);
            }
            $ownerStart = strpos($source, 'data-refresh-owner=');
            $contentEnd = strpos($source, "<?= \$this->endSection() ?>", $ownerStart);
            self::assertIsInt($ownerStart, $view . ' owner start');
            self::assertIsInt($contentEnd, $view . ' content end');
            $ownedSource = substr($source, $ownerStart, $contentEnd - $ownerStart);
            self::assertStringNotContainsString('<script>', $ownedSource, $view . ' must not put inline code in fetched owner content');
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $ownedSource, $view . ' must not put executable attributes in fetched owner content');
        }
        self::assertStringContainsString('result.refresh', file_get_contents(PUBLICPATH . 'assets/js/encounter-stepper.js'));
    }
}
