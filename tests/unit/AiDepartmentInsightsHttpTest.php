<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiInsightsController;
use App\Services\Ai\AiFailure;
use App\Services\Ai\AnalysisCatalog;
use App\Services\Ai\InsightApplication;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Security;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/** No application, collectors, configured database, session, or network is constructed. */
final class DepartmentInsightsControllerProbe extends AiInsightsController
{
    public array $authority = [];
    public int $applicationCalls = 0;

    public function workstationUnit(): string { return $this->unit(); }

    protected function actor(string $permission): array
    {
        $this->authority[] = [$permission, $this->unit()];
        return ['id' => 10, 'tenant_id' => 1];
    }

    protected function application(): InsightApplication
    {
        ++$this->applicationCalls;
        throw new AiFailure('disabled');
    }
}

/** Run with --no-configuration --bootstrap tests/_support/fresh-framework-bootstrap.php. */
final class AiDepartmentInsightsHttpTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $security = (new ReflectionClass(Security::class))->newInstanceWithoutConstructor();
        $security->csrfProtection = 'cookie';
        Factories::injectMock('config', Security::class, $security);
        Services::resetSingle('security');
    }

    private function controller(string $path, array $post = [], array $get = [], string $method = 'POST',
        string $contentType = 'application/x-www-form-urlencoded'): DepartmentInsightsControllerProbe
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com' . $path), null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Content-Type', $contentType);
        $request->setGlobal('get', $get);
        $request->setGlobal('post', [csrf_token() => csrf_hash()] + $post);
        $response = new Response(config('App'));
        $controller = new DepartmentInsightsControllerProbe();
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    public static function workstations(): iterable
    {
        foreach (['clinic' => 'clinic', 'guidance' => 'guidance', 'facilities' => 'bmg'] as $path => $unit) {
            foreach (['', '/panel', '/prepare', '/generate', '/47/review', '/jobs/31/cancel'] as $suffix) {
                yield $path . $suffix => ['/' . $path . '/insights' . $suffix, $unit];
            }
            yield 'subdirectory ' . $path => ['/humus/index.php/' . $path . '/insights', $unit];
        }
    }

    #[DataProvider('workstations')]
    public function testScopeIsOwnedByWorkstationPath(string $path, string $unit): void
    {
        $controller = $this->controller($path, ['unit' => 'clinic', 'tenant_id' => 999], ['unit' => 'clinic']);
        self::assertSame($unit, $controller->workstationUnit());
    }

    public function testOnlyFixedSameUnitAnalysisCodesAreSelectedAndOmissionUsesFirst(): void
    {
        $select = new ReflectionMethod(AiInsightsController::class, 'analysisCode');
        foreach (['clinic' => 'clinic', 'guidance' => 'guidance', 'facilities' => 'bmg'] as $path => $unit) {
            $analyses = AnalysisCatalog::forUnit($unit);
            self::assertSame($analyses[0]['code'], $select->invoke($this->controller('/' . $path . '/insights/prepare')));
            foreach ($analyses as $analysis) {
                self::assertSame($analysis['code'], $select->invoke($this->controller('/' . $path . '/insights/generate',
                    ['analysis_code' => $analysis['code']])));
            }
        }
    }

    public static function invalidSelections(): iterable
    {
        foreach (['clinic', 'guidance', 'facilities'] as $path) {
            foreach ([null, '', 1, ['guidance.activity'], 'guidance.activity ', 'unknown', 'bmg.activity'] as $code) {
                yield [$path, ['analysis_code' => $code], [], 'application/x-www-form-urlencoded'];
            }
            foreach (AnalysisCatalog::definitions() as $definition) {
                if ($definition['unit'] !== ($path === 'facilities' ? 'bmg' : $path)) {
                    yield [$path, ['analysis_code' => $definition['code']], [], 'application/x-www-form-urlencoded'];
                }
            }
            foreach (['unit', 'department', 'tenant', 'tenant_id', 'prompt', 'model', 'url', 'person_id', 'date'] as $key) {
                yield [$path, [$key => 'untrusted'], [], 'application/x-www-form-urlencoded'];
            }
            yield [$path, [], ['analysis_code' => 'clinic.activity'], 'application/x-www-form-urlencoded'];
            yield [$path, [], [], 'application/json'];
            yield [$path, [], [], 'multipart/form-data'];
        }
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionNeverConstructsApplication(string $path, array $post, array $get, string $type): void
    {
        foreach (['prepare', 'generate'] as $action) {
            $controller = $this->controller('/' . $path . '/insights/' . $action, $post, $get, contentType: $type);
            $response = $controller->$action();
            self::assertSame(422, $response->getStatusCode());
            $body = json_decode($response->getBody(), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame('invalid_request', $body['reason']);
            self::assertFalse($body['success']);
            self::assertSame(0, $controller->applicationCalls);
            self::assertSame([['ai.insights.generate', $path === 'facilities' ? 'bmg' : $path]], $controller->authority);
            self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
    }

    public function testNonPostMutationsAreRejectedBeforeIdentityAndApplication(): void
    {
        foreach (['clinic', 'guidance', 'facilities'] as $path) {
            foreach (['prepare', 'generate', 'review', 'cancel'] as $action) {
                foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
                    $controller = $this->controller('/' . $path . '/insights', method: $method);
                    $response = in_array($action, ['review', 'cancel'], true) ? $controller->$action(47) : $controller->$action();
                    self::assertSame(405, $response->getStatusCode());
                    self::assertSame('POST', $response->getHeaderLine('Allow'));
                    self::assertSame([], $controller->authority);
                    self::assertSame(0, $controller->applicationCalls);
                }
            }
        }
    }

    public function testReviewAndCancelDoNotAcceptAnAnalysisOrScopeOverride(): void
    {
        foreach (['guidance', 'facilities'] as $path) {
            foreach (['review', 'cancel'] as $action) {
                $controller = $this->controller('/' . $path . '/insights',
                    ['analysis_code' => 'clinic.activity', 'unit' => 'clinic', 'version' => '1', 'decision' => 'accept']);
                self::assertSame(422, $controller->$action(47)->getStatusCode());
                self::assertSame(0, $controller->applicationCalls);
            }
        }
    }
}
