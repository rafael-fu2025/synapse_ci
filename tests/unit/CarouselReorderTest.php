<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminCarouselController;
use App\Models\CarouselSlideModel;
use App\Services\Carousel\CarouselService;
use App\Services\CurrentTenant;

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;

/** Real controller/service/model against isolated SQLite; no application migrations. */
final class CarouselReorderTest extends CIUnitTestCase
{

    private AdminCarouselController $controller;
    private IncomingRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        CurrentTenant::set(1);
        session()->remove(['success', 'error']);
        $this->db = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '',
            'DBDebug' => true,
        ], false);
        $this->db->query('CREATE TABLE carousel_slides (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, sort_order INTEGER NOT NULL,
            created_at TEXT, updated_at TEXT
        )');
        $this->db->table('carousel_slides')->insertBatch([
            ['id' => 11, 'tenant_id' => 1, 'sort_order' => 50],
            ['id' => 12, 'tenant_id' => 1, 'sort_order' => 60],
            ['id' => 21, 'tenant_id' => 2, 'sort_order' => 70],
        ]);
        $model = new CarouselSlideModel($this->db);
        $reflection = new ReflectionClass(AdminCarouselController::class);
        $this->controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('slideModel')->setValue($this->controller, $model);
        $reflection->getProperty('carousel')->setValue($this->controller, new CarouselService($model, static fn () => true));

        $this->request = Services::incomingrequest(null, false);
        $this->request->setMethod('POST');
        $this->request->removeHeader('X-Requested-With');
        $this->request->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $this->request->setHeader('Accept', 'application/json');
        Services::injectMock('request', $this->request);
        $response = new Response(config('App'));
        Services::injectMock('response', $response);
        $this->controller->initController($this->request, $response, new NullLogger());
    }

    protected function tearDown(): void
    {
        $this->db->close();
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function reorder(array $post, bool $ajax = true): ResponseInterface
    {
        if ($ajax) {
            $this->request->setHeader('X-Requested-With', 'XMLHttpRequest');
        }
        $this->request->setGlobal('post', $post);
        return $this->controller->reorder();
    }

    private function assertJsonResponse(ResponseInterface $response, int $status, bool $success): array
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Location'));
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($success, $payload['success']);
        self::assertNotEmpty($payload['message']);
        self::assertArrayNotHasKey('redirect', $payload);
        self::assertSame(csrf_token(), $payload['csrf']['name']);
        self::assertSame(csrf_hash(), $payload['csrf']['hash']);
        self::assertNotEmpty($payload['csrf']['hash']);
        self::assertNull(session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
        return $payload;
    }

    private function orders(): array
    {
        return array_map('intval', array_column(
            $this->db->table('carousel_slides')->orderBy('id')->get()->getResultArray(),
            'sort_order'
        ));
    }

    public function testAjaxReorderPersistsWithoutNavigationOrFlashAndReturnsCurrentCsrf(): void
    {
        // Simulate the token having rotated before the controller handles the request.
        service('security')->generateHash();
        $payload = $this->assertJsonResponse($this->reorder(['order' => ' 12,0,-3,11 ']), 200, true);
        self::assertSame('Slide order updated.', $payload['message']);
        self::assertSame([2, 1, 70], $this->orders());
    }

    public static function invalidOrders(): iterable
    {
        yield 'missing' => [[]];
        yield 'empty' => [['order' => '']];
        yield 'nonpositive and nonnumeric' => [['order' => '0,-1,abc, ,']];
        yield 'array' => [['order' => ['11', '12']]];
        yield 'foreign only' => [['order' => '21']];
        yield 'unknown only' => [['order' => '99999']];
    }

    #[DataProvider('invalidOrders')]
    public function testAjaxInvalidOrderFailsWithoutWritesOrFlash(array $post): void
    {
        $this->assertJsonResponse($this->reorder($post), 422, false);
        self::assertSame([50, 60, 70], $this->orders());
    }

    public function testMixedTenantOrderOnlyUpdatesOwnedSlides(): void
    {
        $this->assertJsonResponse($this->reorder(['order' => '21,12,99999,11']), 200, true);
        self::assertSame([2, 1, 70], $this->orders());
    }

    public function testAjaxReorderPreservesExistingFlashData(): void
    {
        session()->setFlashdata('success', 'Earlier action');
        $response = $this->reorder(['order' => '12,11']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Earlier action', session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public function testOrdinaryPostKeepsRedirectAndSuccessFlash(): void
    {
        $response = $this->reorder(['order' => '12,11'], false);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(site_url('/admin/carousel'), $response->getHeaderLine('Location'));
        self::assertSame('Slide order updated.', session()->getFlashdata('success'));
        self::assertSame([2, 1, 70], $this->orders());
    }

    #[DataProvider('invalidOrders')]
    public function testOrdinaryInvalidPostKeepsRedirectAndErrorFlash(array $post): void
    {
        $response = $this->reorder($post, false);
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(site_url('/admin/carousel'), $response->getHeaderLine('Location'));
        self::assertSame('No ordering supplied.', session()->getFlashdata('error'));
        self::assertSame([50, 60, 70], $this->orders());
    }

    public function testAjaxLookupExceptionReturnsSafeJsonWithCsrfAndNoFlash(): void
    {
        $this->db->query('DROP TABLE carousel_slides');
        $payload = $this->assertJsonResponse($this->reorder(['order' => '12,11']), 500, false);
        self::assertSame('Unable to update slide order. Please try again.', $payload['message']);
    }

    public function testAjaxWriteExceptionReturnsSafeJsonWithCsrfAndNoFlash(): void
    {
        $this->db->query("CREATE TRIGGER reject_reorder BEFORE UPDATE ON carousel_slides
            BEGIN SELECT RAISE(ABORT, 'private database failure'); END");
        $payload = $this->assertJsonResponse($this->reorder(['order' => '12,11']), 500, false);
        self::assertSame('Unable to update slide order. Please try again.', $payload['message']);
        self::assertSame([50, 60, 70], $this->orders());
    }
}
