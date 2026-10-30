<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\V1\LoginCarouselController;
use App\Services\Carousel\CarouselService;
use App\Services\CurrentTenant;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;

/** Real public route, controller, service and model; private SQLite, no application migrations. */
final class MobileLoginCarouselTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private array $connections;
    private array $savedSession;
    private string|false $tenantEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantEnv = getenv('SYNAPSE_TENANT_ID');
        putenv('SYNAPSE_TENANT_ID=1');
        CurrentTenant::reset();
        $this->savedSession = $_SESSION ?? [];
        $_SESSION = [];

        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $instances = new ReflectionProperty(Database::class, 'instances');
        $this->connections = $instances->getValue();
        $instances->setValue(null, ['tests' => $this->db]);
        $this->db->query('CREATE TABLE carousel_slides (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, title TEXT, subtitle TEXT,
            light_image TEXT, dark_image TEXT, fit_mode TEXT, sort_order INTEGER NOT NULL,
            is_active INTEGER NOT NULL, created_at TEXT, updated_at TEXT
        )');
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(Database::class, 'instances'))->setValue(null, $this->connections);
        $this->db->close();
        $_SESSION = $this->savedSession;
        putenv($this->tenantEnv === false ? 'SYNAPSE_TENANT_ID' : 'SYNAPSE_TENANT_ID=' . $this->tenantEnv);
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function slide(int $id, array $overrides = []): void
    {
        $this->db->table('carousel_slides')->insert(array_replace([
            'id' => $id,
            'tenant_id' => 1,
            'title' => 'Slide ' . $id,
            'subtitle' => null,
            'light_image' => 'assets/img/brand/fuel-bg.jpg',
            'dark_image' => null,
            'fit_mode' => 'cover',
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ], $overrides));
    }

    private function payload(TestResponse $result, int $status = 200): array
    {
        $result->assertStatus($status);
        $cacheDirectives = array_map('trim', explode(',', $result->response()->getHeaderLine('Cache-Control')));
        self::assertContains('private', $cacheDirectives);
        self::assertContains('no-store', $cacheDirectives);
        self::assertStringContainsString('application/json', $result->response()->getHeaderLine('Content-Type'));
        return json_decode((string) $result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPublicGetRouteDoesNotChangeAuthenticatedRoutes(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        self::assertStringEndsWith('LoginCarouselController::index', $routes->getRoutes('GET')['api/v1/auth/carousel']);
        self::assertSame([], $routes->getFiltersForRoute('api/v1/auth/carousel', 'GET'));
        self::assertArrayNotHasKey('api/v1/auth/carousel', $routes->getRoutes('POST'));
        self::assertContains('api_auth', $routes->getFiltersForRoute('api/v1/me', 'GET'));
        self::assertContains('api_auth', $routes->getFiltersForRoute('api/v1/auth/logout', 'POST'));

        $payload = $this->payload($this->get('api/v1/auth/carousel'));
        self::assertNotEmpty($payload['data']['slides']);
    }

    public function testProjectionMatchesWebSourceOrderAndOmitsInactiveForeignAndAdminFields(): void
    {
        $this->slide(30, ['sort_order' => 9]);
        $this->slide(20, ['title' => null, 'subtitle' => 'Health services', 'fit_mode' => 'contain_blur',
            'light_image' => 'uploads/carousel/slide_light_123.webp',
            'dark_image' => 'assets/img/brand/FoundationU-Dark.png']);
        $this->slide(10);
        $this->slide(1, ['is_active' => 0, 'sort_order' => 0]);
        $this->slide(2, ['tenant_id' => 2, 'sort_order' => 0]);
        $source = (new CarouselService())->getSlidesForLogin();
        self::assertSame([10, 20, 30], array_map('intval', array_column($source, 'id')));

        $payload = $this->payload($this->get('api/v1/auth/carousel'));
        self::assertSame(['data'], array_keys($payload));
        self::assertSame(['slides'], array_keys($payload['data']));
        $slides = $payload['data']['slides'];
        self::assertSame([10, 20, 30], array_column($slides, 'id'));
        foreach ($slides as $index => $slide) {
            self::assertSame(['id', 'title', 'subtitle', 'light_image', 'dark_image', 'fit_mode'], array_keys($slide));
            $expected = array_intersect_key($source[$index], array_flip(array_keys($slide)));
            $expected['id'] = (int) $expected['id'];
            self::assertEquals($expected, $slide);
        }
        self::assertNull($slides[1]['title']);
        self::assertSame('Health services', $slides[1]['subtitle']);
        self::assertSame('contain_blur', $slides[1]['fit_mode']);
    }

    public function testCallerCannotSelectTenantOrInactiveSlides(): void
    {
        $this->slide(10);
        $this->slide(20, ['tenant_id' => 2]);
        $this->slide(30, ['is_active' => 0]);
        $payload = $this->payload($this->withHeaders(['X-Tenant-ID' => '2'])->get(
            'api/v1/auth/carousel?tenant_id=2&tenant=2&is_active=0&include_inactive=1&sort_order=desc'
        ));
        self::assertSame([10], array_column($payload['data']['slides'], 'id'));
    }

    public function testEnvironmentDefaultTenantIsUsedLikeWebLogin(): void
    {
        putenv('SYNAPSE_TENANT_ID=2');
        $this->slide(10);
        $this->slide(20, ['tenant_id' => 2]);
        $payload = $this->payload($this->get('api/v1/auth/carousel?tenant_id=1'));
        self::assertSame([20], array_column($payload['data']['slides'], 'id'));
    }

    public function testEmptyActiveSourceUsesExactWebLoginFallback(): void
    {
        $this->slide(10, ['is_active' => 0]);
        $this->slide(20, ['tenant_id' => 2]);
        $payload = $this->payload($this->get('api/v1/auth/carousel'));
        self::assertSame(['data' => ['slides' => (new CarouselService())->getSlidesForLogin()]], $payload);
    }

    public static function unsafePaths(): iterable
    {
        yield 'https URL' => ['https://example.com/image.jpg'];
        yield 'http URL' => ['http://example.com/image.jpg'];
        yield 'protocol relative' => ['//example.com/image.jpg'];
        yield 'data URL' => ['data:image/png;base64,abc'];
        yield 'absolute local' => ['/assets/img/brand/fuel-bg.jpg'];
        yield 'Windows local' => ['C:\\private\\image.jpg'];
        yield 'other public directory' => ['uploads/private/image.jpg'];
        yield 'parent traversal' => ['assets/img/brand/../../../env.jpg'];
        yield 'embedded traversal' => ['assets/img/brand/sub/../image.jpg'];
        yield 'dot segment' => ['assets/img/brand/./image.jpg'];
        yield 'encoded traversal' => ['assets/img/brand/%2e%2e/image.jpg'];
        yield 'double encoded traversal' => ['assets/img/brand/%252e%252e/image.jpg'];
        yield 'backslash traversal' => ['uploads/carousel/..\\image.jpg'];
        yield 'query' => ['assets/img/brand/fuel-bg.jpg?file=private'];
        yield 'fragment' => ['assets/img/brand/fuel-bg.jpg#private'];

        yield 'newline' => ["assets/img/brand/fuel-bg.jpg\n"];
        yield 'nonimage file' => ['uploads/carousel/private.php'];
        yield 'nested upload' => ['uploads/carousel/private/image.jpg'];
        yield 'empty' => [''];
        yield 'null' => [null];
        yield 'too long' => ['assets/img/brand/' . str_repeat('a', 240) . '.jpg'];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeLightOmitsSlideAndUnsafeDarkBecomesNull(?string $path): void
    {
        $this->slide(10, ['light_image' => $path]);
        $this->slide(20, ['dark_image' => $path]);
        $this->slide(30, ['light_image' => 'assets/img/brand/campus/health-light.JPEG']);
        $slides = $this->payload($this->get('api/v1/auth/carousel'))['data']['slides'];
        self::assertSame([20, 30], array_column($slides, 'id'));
        self::assertNull($slides[0]['dark_image']);
        self::assertSame('assets/img/brand/campus/health-light.JPEG', $slides[1]['light_image']);
    }

    public function testNulAndNonstringPathsAreRejectedAtProjectionBoundary(): void
    {
        // SQLite's driver truncates NUL text, so exercise these raw values before database transport.
        $imagePath = new ReflectionMethod(LoginCarouselController::class, 'imagePath');
        $controller = new LoginCarouselController();
        foreach (["uploads/carousel/image.jpg\0.png", ['assets/img/brand/fuel-bg.jpg'], 123, false] as $path) {
            self::assertNull($imagePath->invoke($controller, $path));
        }
    }

    public function testAllUnsafeSlidesReturnAnEmptyListWithoutInventingFallback(): void
    {
        $this->slide(10, ['light_image' => 'https://example.com/image.jpg']);
        self::assertSame(['data' => ['slides' => []]], $this->payload($this->get('api/v1/auth/carousel')));
    }

    public function testUnknownFitModeDefaultsToCover(): void
    {
        $this->slide(10, ['fit_mode' => 'invalid']);
        $slides = $this->payload($this->get('api/v1/auth/carousel'))['data']['slides'];
        self::assertSame('cover', $slides[0]['fit_mode']);
    }

    public function testDatabaseFailureReturnsOrdinaryUnavailableNotFallbackOrDatabaseDetails(): void
    {
        $this->db->query('DROP TABLE carousel_slides');
        $payload = $this->payload($this->get('api/v1/auth/carousel'), 503);
        self::assertSame(['error' => [
            'code' => 'service_unavailable',
            'message' => 'The service is temporarily unavailable. Please try again shortly.',
        ]], $payload);
    }
}
