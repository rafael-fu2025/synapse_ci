<?php

declare(strict_types=1);

namespace Tests\Unit;


use App\Controllers\QueueController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;

/** Response-only tests: no controller/service constructors, DB fixtures or migrations. */
final class QueueBackgroundTest extends CIUnitTestCase
{
    private QueueController $controller;
    private IncomingRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        session()->remove(['success', 'error', 'user']);
        $this->request = Services::incomingrequest(null, false);
        $this->request->setMethod('POST');
        foreach (['HX-Request', 'X-Requested-With', 'X-Synapse-Feedback'] as $header) {
            $this->request->removeHeader($header);
        }
        $this->request->setGlobal('post', []);
        $this->request->setGlobal('get', []);
        $response = new Response(config('App'));
        Services::injectMock('request', $this->request);
        Services::injectMock('response', $response);
        Services::injectMock('redirectresponse', new RedirectResponse(config('App')));
        $this->controller = (new ReflectionClass(QueueController::class))->newInstanceWithoutConstructor();
        $this->controller->initController($this->request, $response, new NullLogger());
    }

    protected function tearDown(): void
    {
        session()->remove(['success', 'error', 'user']);
        foreach (['request', 'response', 'redirectresponse'] as $service) Services::resetSingle($service);
        parent::tearDown();
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod(QueueController::class, $method))->invoke($this->controller, ...$args);
    }

    private function background(): void
    {
        $this->request->setHeader('X-Requested-With', 'XMLHttpRequest');
        $this->request->setHeader('X-Synapse-Feedback', 'toast');
    }

    private function payload(ResponseInterface $response, bool $success, int $status): array
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Location'));
        $body = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['success', 'message', 'csrf'], array_keys($body), 'No patient or clinical data in response');
        self::assertSame($success, $body['success']);
        self::assertSame(['name' => csrf_token(), 'hash' => csrf_hash()], $body['csrf']);
        return $body;
    }

    public static function transports(): iterable
    {
        foreach ([false, true] as $xhr) {
            foreach (['', 'toast', 'other'] as $feedback) yield [$xhr, $feedback];
        }
    }

    #[DataProvider('transports')]
    public function testOnlyExplicitXhrToastMutationUsesJson(bool $xhr, string $feedback): void
    {
        if ($xhr) $this->request->setHeader('X-Requested-With', 'XMLHttpRequest');
        if ($feedback !== '') $this->request->setHeader('X-Synapse-Feedback', $feedback);
        $calls = 0;
        $response = $this->invoke('mutate', 'guidance', static function () use (&$calls): void { ++$calls; });
        self::assertSame(1, $calls);
        if ($xhr && $feedback === 'toast') {
            self::assertSame('Queue updated successfully.', $this->payload($response, true, 200)['message']);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame(site_url('/guidance/queue'), $response->getHeaderLine('Location'));
        }
    }

    public static function statuses(): iterable
    {
        foreach ([403, 404, 409, 422, 500] as $status) yield [$status];
    }

    #[DataProvider('statuses')]
    public function testMutationErrorsContainCsrfAndPreserveExistingFlash(int $status): void
    {
        $this->background();
        session()->setFlashdata('success', 'Earlier success');
        session()->setFlashdata('error', 'Earlier error');
        $response = $this->invoke('mutate', 'clinic', static function () use ($status): never {
            throw new \RuntimeException('Service detail', $status);
        });
        $body = $this->payload($response, false, $status);
        self::assertSame($status === 500 ? 'Queue request failed.' : 'Service detail', $body['message']);
        self::assertSame('Earlier success', session()->getFlashdata('success'));
        self::assertSame('Earlier error', session()->getFlashdata('error'));
    }

    public function testInvalidDateStopsOperationAndReturnsJson(): void
    {
        $this->background();
        $this->request->setGlobal('post', ['date' => 'not-a-date']);
        $response = $this->invoke('mutate', 'clinic', static function (): never { self::fail('Must not mutate'); });
        self::assertSame('Invalid queue date.', $this->payload($response, false, 422)['message']);
    }

    public function testInvalidWalkinReturnsJsonWithoutFlashOrTouchingService(): void
    {
        $this->background();
        $body = $this->payload($this->controller->walkin('guidance'), false, 422);
        self::assertSame('Please select a valid patient from the registry.', $body['message']);
        self::assertNull(session()->getFlashdata('error'));
        self::assertFalse((new ReflectionClass(QueueController::class))->getProperty('queueService')->isInitialized($this->controller));
    }

    public function testNativeInvalidWalkinRetainsRedirectAndFlash(): void
    {
        $response = $this->controller->walkin('clinic');
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('Please select a valid patient from the registry.', session()->getFlashdata('error'));
    }

    public function testHtmxErrorHasPriorityEvenWhenToastHeadersArePresent(): void
    {
        $this->background();
        $this->request->setHeader('HX-Request', 'true');
        $response = $this->invoke('mutate', 'clinic', static function (): never { throw new \DomainException('No permission.', 403); });
        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['error' => 'No permission.'], json_decode($response->getBody(), true));
        self::assertFalse($this->invoke('wantsBackgroundFeedback'));
    }

    public function testHtmxSuccessStillEntersBoardFragmentPath(): void
    {
        $this->background();
        $this->request->setHeader('HX-Request', 'true');
        // Uninitialized QueueService proves the fragment path was selected; no DB read is possible.
        try {
            $this->invoke('renderBoardOrRedirect', 'clinic');
            self::fail('HTMX must render the board, not return toast JSON.');
        } catch (\Error $error) {
            self::assertStringContainsString('queueService', $error->getMessage());
        }
    }

    public function testTicketJsonIncludesOnlyMessageAndCsrfWithoutFlashing(): void
    {
        $this->background();
        $body = $this->payload($this->invoke('queueJson', true, 'Ticket C001 issued successfully.'), true, 200);
        self::assertSame('Ticket C001 issued successfully.', $body['message']);
        self::assertNull(session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public function testWalkinKeepsPermissionGateBeforeEnqueueAndNativeSuccessFlash(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/QueueController.php');
        $start = strpos($source, 'public function walkin(');
        $walkin = substr($source, $start, strpos($source, 'public function noShow(', $start) - $start);
        self::assertStringContainsString("assertSchedulingPermission(\$unit, (array) session()->get('user'), true)", $walkin);
        self::assertLessThan(strpos($walkin, 'enqueueWalkIn('), strpos($walkin, 'assertSchedulingPermission('));
        self::assertStringContainsString('Ticket {$entry[\'ticket_code\']} issued successfully.', $walkin);
        self::assertStringContainsString("->with('success', \$message)", $walkin);
    }
}

