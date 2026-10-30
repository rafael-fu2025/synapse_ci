<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AiInsightsController;
use App\Services\Ai\AiAccess;
use App\Services\Ai\AiPermissions;
use App\Services\Ai\InsightApplication;
use App\Services\Ai\PayloadCipher;
use App\Services\AuthenticatedUser;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Security;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;

require_once __DIR__ . '/AiApplicationTest.php';

/** Only identity and application construction are replaced; all handlers and PHP views are real. */
final class AiInsightsHttpController extends AiInsightsController
{
    public int $applicationCalls = 0;
    public array $permissionsChecked = [];

    public function __construct(public ?array $identity, private InsightApplication $injectedApplication)
    {
    }

    protected function actor(string $permission): array
    {
        $this->permissionsChecked[] = $permission;
        return AiAccess::assertActor($this->identity, 1, $permission);
    }

    protected function application(): InsightApplication
    {
        ++$this->applicationCalls;
        return $this->injectedApplication;
    }
}

final class AiHttpTest extends CIUnitTestCase
{
    use AiApplicationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        $security = (new ReflectionClass(Security::class))->newInstanceWithoutConstructor();
        $security->csrfProtection = 'cookie';
        Factories::injectMock('config', Security::class, $security);
        Services::resetSingle('security');
        // Auth failures may use the shared auth response helper, but never a real session handler.
        $session = $this->getMockBuilder(Session::class)->disableOriginalConstructor()->getMock();
        $session->method('get')->willReturn(null);
        $session->method('getFlashdata')->willReturn(null);
        Services::injectMock('session', $session);
        $this->setUpControl();
        $this->setUpStore();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
        parent::tearDown();
    }

    private function controller(string $method = 'POST', array $post = [], array $get = [],
        ?InsightApplication $application = null, ?array $actor = null, string $contentType = 'application/x-www-form-urlencoded'): AiInsightsHttpController
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod($method);
        $request->setHeader('Accept', 'application/json');
        $request->setHeader('Content-Type', $contentType);
        $request->setGlobal('get', $get);
        $request->setGlobal('post', [csrf_token() => csrf_hash()] + $post);
        Services::injectMock('request', $request);
        $response = new Response(config('App'));
        Services::injectMock('response', $response);
        $controller = new AiInsightsHttpController($actor ?? self::fixtureActor(), $application ?? $this->application());
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    private function json(ResponseInterface $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode(), $response->getBody());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $body = json_decode($response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(['name' => csrf_token(), 'hash' => csrf_hash()], $body['csrf']);
        self::assertSame('', $response->getHeaderLine('X-Synapse-Auth'));
        self::assertSame('', $response->getHeaderLine('X-Synapse-Login'));
        self::assertSame('', $response->getHeaderLine('Location'));
        return $body;
    }

    public function testRegisteredRoutesUseGetForReadsAndPostWithAllMutationFilters(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        $reads = ['clinic/insights' => 'index', 'clinic/insights/panel' => 'panel'];
        $mutations = ['clinic/insights/prepare' => 'prepare', 'clinic/insights/generate' => 'generate',
            'clinic/insights/([0-9]+)/review' => 'review/$1', 'clinic/insights/jobs/([0-9]+)/cancel' => 'cancel/$1'];
        foreach ($reads as $path => $handler) {
            self::assertStringEndsWith('AiInsightsController::' . $handler, $routes->getRoutes('GET')[$path]);
            self::assertArrayNotHasKey($path, $routes->getRoutes('POST'));
        }
        foreach ($mutations as $path => $handler) {
            self::assertStringEndsWith('AiInsightsController::' . $handler, $routes->getRoutes('POST')[$path]);
            foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
                self::assertArrayNotHasKey($path, $routes->getRoutes($method));
            }
            foreach (['auth', 'web_csrf', 'portal_host', 'web_access', 'portal_access:clinic'] as $filter) {
                self::assertContains($filter, $routes->getFiltersForRoute($path, 'POST'), $path);
            }
        }
    }

    public function testGetMutationIsDeniedBeforeIdentityApplicationOrCollectors(): void
    {
        foreach (['prepare', 'generate', 'review', 'cancel'] as $action) {
            $controller = $this->controller('GET', application: $this->application(forbidCollection: true));
            $response = in_array($action, ['review', 'cancel'], true) ? $controller->$action(1) : $controller->$action();
            self::assertSame(405, $response->getStatusCode());
            self::assertSame('POST', $response->getHeaderLine('Allow'));
            self::assertSame([], $controller->permissionsChecked);
            self::assertSame(0, $controller->applicationCalls);
            self::assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
            self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        }
        $this->assertNoAiRows();
    }

    public static function arbitraryFields(): iterable
    {
        foreach (['url', 'baseUrl', 'prompt', 'messages', 'model', 'date', 'start_date', 'end_date', 'tenant_id', 'person_id'] as $field) {
            foreach (['prepare', 'generate'] as $action) {
                yield $action . '/' . $field => [$action, [$field => 'untrusted'], [], 'application/x-www-form-urlencoded'];
            }
        }
        yield 'query date' => ['prepare', [], ['date' => '2026-01-01'], 'application/x-www-form-urlencoded'];
        yield 'query URL' => ['generate', [], ['url' => 'https://evil.invalid'], 'application/x-www-form-urlencoded'];
        yield 'JSON' => ['prepare', [], [], 'application/json'];
        yield 'multipart' => ['generate', [], [], 'multipart/form-data'];
    }

    #[DataProvider('arbitraryFields')]
    public function testArbitraryInputIsRejectedBeforeApplicationOrCollectors(string $action, array $post, array $get, string $type): void
    {
        $controller = $this->controller(post: $post, get: $get, application: $this->application(forbidCollection: true), contentType: $type);
        $body = $this->json($controller->$action(), 422);
        self::assertFalse($body['success']);
        self::assertSame('invalid_request', $body['reason']);
        self::assertSame(0, $controller->applicationCalls);
        $this->assertNoAiRows();
    }

    public function testGetPanelOnlyReadsStoredStateWithoutSourceCollectionOrInference(): void
    {
        $this->control->resume($this->profile, 1);
        $controller = $this->controller('GET', application: $this->application(forbidCollection: true));
        $body = $this->json($controller->panel(), 200);
        self::assertTrue($body['success']);
        self::assertStringContainsString('No AI drafts yet. Request a draft', $body['html']);
        self::assertSame(['ai.insights.read'], $controller->permissionsChecked);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', $body['html']);
        self::assertStringNotContainsString('gateway.example.test', $body['html']);
        $this->assertNoAiRows();
    }

    public function testUninstalledEncryptedStoreRendersCleanUnavailablePanelWithoutInstallingTables(): void
    {
        $this->fixtureDb->close();
        $this->setUpStore(false);
        $body = $this->json($this->controller('GET', application: $this->application(forbidCollection: true))->panel(), 200);
        self::assertTrue($body['success']);
        self::assertStringContainsString('Secure storage for insights is not ready', $body['html']);
        self::assertStringNotContainsString('SYNTHETIC-SECRET', $body['html']);
        self::assertSame([], $this->fixtureDb->listTables());
        self::assertSame(0, $this->requests);
    }

    public function testHttpPrepareThenEnqueueAndDifferentAuthorizedUserDedupeWithoutDispatch(): void
    {
        $this->seedSource();
        $body = $this->json($this->controller()->prepare(), 200);
        self::assertTrue($body['success']);
        self::assertStringContainsString('240', $body['baseline']);
        self::assertStringNotContainsString('PRIVATE', $body['baseline']);
        $this->control->resume($this->profile, 1);
        $queued = $this->json($this->controller()->generate(), 202);
        $reused = $this->json($this->controller(actor: self::fixtureActor(['id' => 11]))->generate(), 202);
        self::assertFalse($queued['reused']);
        self::assertTrue($reused['reused']);
        self::assertSame($queued['job_id'], $reused['job_id']);
        self::assertSame('queued', $queued['status']);
        self::assertSame(0, $this->requests);
        self::assertSame(1, $this->fixtureDb->table('ai_jobs')->countAllResults());
    }

    public static function invalidVersions(): iterable
    {
        foreach ([null, '', '0', '-1', '01', '1.0', '1e0', ' 1', 'true', ['1'], str_repeat('9', 30)] as $version) {
            yield [$version];
        }
    }

    #[DataProvider('invalidVersions')]
    public function testReviewRequiresStrictPositiveVersionInBodyBeforeApplication(mixed $version): void
    {
        $controller = $this->controller(post: ['version' => $version, 'decision' => 'accept'], application: $this->application(forbidCollection: true));
        self::assertSame('invalid_review', $this->json($controller->review(1), 422)['reason']);
        self::assertSame(0, $controller->applicationCalls);
        $this->assertNoAiRows();
    }

    public function testQueryVersionCannotReplaceBodyAndCancelRejectsAdditionalInput(): void
    {
        $app = $this->application(forbidCollection: true);
        $review = $this->controller(post: ['decision' => 'accept'], get: ['version' => '1'], application: $app);
        self::assertSame('invalid_request', $this->json($review->review(1), 422)['reason']);
        self::assertSame(0, $review->applicationCalls);
        $cancel = $this->controller(post: ['prompt' => 'untrusted'], application: $app);
        self::assertSame('invalid_request', $this->json($cancel->cancel(1), 422)['reason']);
        self::assertSame(0, $cancel->applicationCalls);
        $this->assertNoAiRows();
    }

    public function testReviewUsesBodyVersionAndDecisionSpecificGrantWithCasConflict(): void
    {
        $this->seedSource();
        $this->control->resume($this->profile, 1);
        $this->application()->generate(self::fixtureActor());
        $this->worker()->run(1);
        $row = $this->store->listInsights(1, 'clinic')[0];
        $id = $row['id'];
        $reader = self::fixtureActor(['role' => 'superadmin', 'permissions' => ['ai.insights.read']]);
        $this->controller(post: ['version' => '1', 'decision' => 'accept'], actor: $reader)->review($id);
        self::assertSame(403, service('response')->getStatusCode());
        self::assertSame(0, $this->fixtureDb->table('ai_reviews')->countAllResults());
        self::assertTrue($this->json($this->controller(post: ['version' => '1', 'decision' => 'accept'])->review($id), 200)['success']);
        self::assertSame('review_conflict', $this->json($this->controller(post: ['version' => '1', 'decision' => 'reject'])->review($id), 409)['reason']);
        $reviewer = self::fixtureActor(['permissions' => ['ai.insights.read', 'ai.insights.review']]);
        self::assertSame(403, $this->controller(post: ['version' => '2', 'decision' => 'approve'], actor: $reviewer)->review($id)->getStatusCode());
        $approved = $this->json($this->controller(post: ['version' => '2', 'decision' => 'approve'])->review($id), 200);
        self::assertStringContainsString('Nothing was published', $approved['message']);
        self::assertSame('approved', $this->store->getInsight(1, 'clinic', $id)['status']);
        self::assertSame(3, $this->store->getInsight(1, 'clinic', $id)['version']);
        self::assertSame(2, $this->fixtureDb->table('ai_reviews')->countAllResults());
    }

    public function testAuthenticationAndAuthorizationUseSharedContractWithoutRedirectingPost(): void
    {
        $controller = $this->controller(application: $this->application(forbidCollection: true));
        $controller->identity = null;
        $response = $controller->prepare();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('authentication_required', $response->getHeaderLine('X-Synapse-Auth'));
        self::assertSame(site_url('auth/login'), $response->getHeaderLine('X-Synapse-Login'));
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['message' => AuthenticatedUser::AUTHENTICATION_MESSAGE], json_decode($response->getBody(), true));
        self::assertSame(0, $controller->applicationCalls);
        $controller = $this->controller(actor: self::fixtureActor(['role' => 'superadmin', 'permissions' => []]), application: $this->application(forbidCollection: true));
        $response = $controller->generate();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('X-Synapse-Auth'));
        self::assertSame('', $response->getHeaderLine('X-Synapse-Login'));
        self::assertSame('', $response->getHeaderLine('Location'));
        self::assertSame(0, $controller->applicationCalls);
        $this->assertNoAiRows();
    }

    public function testProvider401IsAJobFailureNotBrowserAuthenticationOrLogin(): void
    {
        $this->seedSource();
        $this->control->resume($this->profile, 1);
        $job = $this->application()->generate(self::fixtureActor());
        self::assertSame(['processed' => 0, 'failed' => 1, 'outcome_unknown' => 0], $this->worker(providerStatus: 401)->run(1));
        self::assertSame('failed', $this->store->job(1, 'clinic', $job['id'])['status']);
                self::assertSame('generation_failed', $this->store->job(1, 'clinic', $job['id'])['reason']);
        $body = $this->json($this->controller('GET', application: $this->application(forbidCollection: true))->panel(), 200);
        self::assertTrue($body['success']);
        self::assertStringContainsString('The draft could not be created.', $body['html']);
        self::assertSame(1, $this->requests);
    }

    public function testRealPhpViewsEscapeStoredNarrativeProvenanceEvidenceProgramsAndAttributes(): void
    {
        $this->seedSource();
        $this->control->resume($this->profile, 1);
        $this->application()->generate(self::fixtureActor());
        $this->worker()->run(1);
        $row = $this->store->listInsights(1, 'clinic')[0];
        $draft = $row['draft'];
        $attack = '<img src=x onerror="alert(1)">';
        foreach (['summary', 'interpretation'] as $key) { $draft['narrative'][$key] = $attack; }
        $draft['narrative']['alternative_explanations'] = [$attack];
        $draft['provenance']['model'] = $attack;
        $draft['provenance']['prompt_version'] = $attack;
        $draft['evidence']['limitations'] = [$attack];
        $draft['programs'][0]['title'] = $attack;
        $draft['programs'][0]['objective'] = $attack;
        $draft['programs'][0]['references'][0] = ['title' => $attack, 'url' => 'https://example.test/" onmouseover="alert(1)'];
        // Defense in depth: the rendering boundary must escape even an already-stored hostile draft.
        $cipher = new PayloadCipher($this->profile->config->encryptionKey);
        $this->fixtureDb->table('ai_insights')->where('id', $row['id'])->update([
            'payload' => $cipher->encrypt(1, 'clinic', 'insight:' . $row['job_id'], $draft),
        ]);
        $body = $this->json($this->controller('GET', application: $this->application(forbidCollection: true))->panel(), 200);
        self::assertStringNotContainsString($attack, $body['html']);
        self::assertStringNotContainsString('<img', $body['html']);
        self::assertGreaterThanOrEqual(8, substr_count($body['html'], '&lt;img'));
        $dom = new \DOMDocument();
        @$dom->loadHTML($body['html']);
        $xpath = new \DOMXPath($dom);
        self::assertSame(0, $xpath->query('//*[@onerror or @onmouseover]')->length);
        self::assertStringContainsString('name="version" value="1"', $body['html']);
    }

    public function testPermissionCatalogAndSeederAreAdditiveCatalogOnlyWithoutRunningSeeds(): void
    {
        $catalog = AiPermissions::catalog();
        self::assertSame(['ai.insights.read', 'ai.insights.generate', 'ai.insights.review', 'ai.insights.approve',
            'ai.configuration.read', 'ai.configuration.write', 'ai.configuration.test'], array_column($catalog, 'code'));
        self::assertSame(['ai'], array_values(array_unique(array_column($catalog, 'module'))));
        $source = file_get_contents(APPPATH . 'Database/Seeds/AiPermissionsSeeder.php');
        self::assertStringContainsString('AiPermissions::catalog()', $source);
        self::assertStringContainsString("table('permissions')", $source);
        foreach (['role_permissions', 'user_permissions', 'truncate(', 'delete(', 'update(', 'PermissionsAndRolesSeeder'] as $operation) {
            self::assertStringNotContainsString($operation, $source);
        }
        $this->assertNoAiRows();
    }
}
