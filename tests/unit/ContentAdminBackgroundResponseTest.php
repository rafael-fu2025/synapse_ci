<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AdminCarouselController;
use App\Controllers\AnnouncementController;
use App\Controllers\AuditController;
use App\Controllers\QuestionBankController;
use App\Controllers\SurveyController;
use App\Models\AuditEventModel;
use App\Services\Audit\AuditService;
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
use RuntimeException;

/** Response contracts only; no business mutations, application migrations or browser runner. */
final class ContentAdminBackgroundResponseTest extends CIUnitTestCase
{
    private const CONTENT = [
        AnnouncementController::class => '/clinic/announcements',
        SurveyController::class => '/guidance/surveys',
        QuestionBankController::class => '/guidance/question-bank',
        AdminCarouselController::class => '/admin/carousel',
    ];

    private IncomingRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        session()->remove(['success', 'error']);
    }

    protected function tearDown(): void
    {
        session()->remove(['success', 'error']);
        CurrentTenant::reset();
        parent::tearDown();
    }

    private function controller(string $class, string $transport, string $feedback): object
    {
        $this->request = Services::incomingrequest(null, false);
        $this->request->setMethod('POST');
        foreach (['X-Requested-With', 'Accept', 'X-Synapse-Feedback'] as $header) {
            $this->request->removeHeader($header);
        }
        if ($transport === 'xhr') $this->request->setHeader('X-Requested-With', 'XMLHttpRequest');
        if ($transport === 'accept') $this->request->setHeader('Accept', 'application/json');
        if ($feedback !== '') $this->request->setHeader('X-Synapse-Feedback', $feedback);
        $response = new Response(config('App'));
        Services::injectMock('request', $this->request);
        Services::injectMock('response', $response);
        Services::injectMock('redirectresponse', new RedirectResponse(config('App')));
        // Response helpers do not need constructor-owned database/services.
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $controller->initController($this->request, $response, new NullLogger());
        return $controller;
    }

    private function invoke(object $controller, string $method, mixed ...$args): ResponseInterface
    {
        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller, ...$args);
    }

    private function json(ResponseInterface $response, int $status, bool $success): array
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Location'));
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($success, $payload['success']);
        self::assertNotEmpty($payload['message']);
        self::assertSame(['name' => csrf_token(), 'hash' => csrf_hash()], $payload['csrf']);
        self::assertNotEmpty($payload['csrf']['hash']);
        return $payload;
    }

    public static function contentSuccessCases(): iterable
    {
        foreach (self::CONTENT as $class => $target) {
            foreach (['native', 'xhr', 'accept'] as $transport) {
                foreach (['', 'toast', 'other'] as $feedback) {
                    yield $class . ':' . $transport . ':' . $feedback => [$class, $target, $transport, $feedback];
                }
            }
        }
    }

    #[DataProvider('contentSuccessCases')]
    public function testSuccessHelpersOnlySuppressFlashForExplicitToastHeader(
        string $class, string $target, string $transport, string $feedback
    ): void {
        $controller = $this->controller($class, $transport, $feedback);
        service('security')->generateHash();
        $response = $class === AdminCarouselController::class
            ? $this->invoke($controller, 'respond', 'Saved.')
            : ($class === AnnouncementController::class
                ? $this->invoke($controller, 'success', 'Saved.', $target)
                : $this->invoke($controller, 'success', 'Saved.'));
        $json = $transport === 'xhr' || ($transport === 'accept'
            && in_array($class, [AnnouncementController::class, QuestionBankController::class], true));
        if ($json) {
            $payload = $this->json($response, 200, true);
            self::assertSame(site_url($target), $payload['redirect']);
            self::assertSame('Saved.', $payload['message']);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame(site_url($target), $response->getHeaderLine('Location'));
        }
        self::assertSame($feedback === 'toast' ? null : 'Saved.', session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public static function contentControllers(): iterable
    {
        foreach (self::CONTENT as $class => $target) yield [$class];
    }

    #[DataProvider('contentControllers')]
    public function testToastSuccessDoesNotClearUnrelatedFlash(string $class): void
    {
        $controller = $this->controller($class, 'xhr', 'toast');
        session()->setFlashdata('success', 'Earlier success');
        session()->setFlashdata('error', 'Earlier error');
        if ($class === AdminCarouselController::class) $this->invoke($controller, 'respond', 'Saved.');
        elseif ($class === AnnouncementController::class) $this->invoke($controller, 'success', 'Saved.', '/clinic/announcements');
        else $this->invoke($controller, 'success', 'Saved.');
        self::assertSame('Earlier success', session()->getFlashdata('success'));
        self::assertSame('Earlier error', session()->getFlashdata('error'));
    }

    public static function contentFailures(): iterable
    {
        foreach (self::CONTENT as $class => $target) {
            foreach ($class === AdminCarouselController::class ? [422] : [401, 403, 404, 409, 422, 503] as $status) {
                yield $class . ':' . $status => [$class, $status];
            }
        }
    }

    #[DataProvider('contentFailures')]
    public function testToastErrorsPreserveStatusMessageAndCsrfWithoutFlash(string $class, int $status): void
    {
        $controller = $this->controller($class, 'xhr', 'toast');
        $exception = new RuntimeException('Request rejected.', $status);
        if ($class === AdminCarouselController::class) $response = $this->invoke($controller, 'respond', 'Request rejected.', false);
        elseif ($class === SurveyController::class) $response = $this->invoke($controller, 'failure', $exception);
        else $response = $this->invoke($controller, 'respond', static function () use ($exception) { throw $exception; });
        $payload = $this->json($response, $status, false);
        if ($status !== 503) self::assertSame('Request rejected.', $payload['message']);
        self::assertNull(session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public static function auditCases(): iterable
    {
        foreach (['valid', 'broken', 'invalid-id'] as $outcome) {
            foreach (['native', 'xhr', 'accept'] as $transport) {
                foreach (['', 'toast', 'other'] as $feedback) {
                    yield $outcome . ':' . $transport . ':' . $feedback => [$outcome, $transport, $feedback];
                }
            }
        }
    }

    #[DataProvider('auditCases')]
    public function testAuditVerificationJsonAndNativeContracts(string $outcome, string $transport, string $feedback): void
    {
        $controller = $this->controller(AuditController::class, $transport, $feedback);
        CurrentTenant::set(1);
        // Isolated minimal read fixture: never connect to the application database or run migrations.
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        try {
            $db->query('CREATE TABLE audit_events (id TEXT, tenant_id INTEGER, prev_hash TEXT)');
            if ($outcome !== 'valid') {
                $db->table('audit_events')->insert([
                    'id' => $outcome === 'invalid-id' ? 'bad-id' : '42',
                    'tenant_id' => 1, 'prev_hash' => 'broken-link',
                ]);
            }
            (new ReflectionClass($controller))->getProperty('auditService')->setValue(
                $controller, new AuditService(new AuditEventModel($db), $db)
            );
            $response = $controller->verify();
            $valid = $outcome === 'valid';
            if ($transport === 'native') {
                self::assertInstanceOf(RedirectResponse::class, $response);
                self::assertSame(site_url('/admin/audit'), $response->getHeaderLine('Location'));
                $message = session()->getFlashdata($valid ? 'success' : 'error');
            } else {
                $payload = $this->json($response, $valid ? 200 : 422, $valid);
                self::assertArrayNotHasKey('redirect', $payload);
                self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
                $message = $payload['message'];
            }
            if ($transport !== 'native' || $feedback !== 'toast') {
                self::assertStringContainsString($valid ? 'No external checkpoint was checked.' : 'INTEGRITY ALERT:', $message);
                if (!$valid) self::assertStringContainsString($outcome === 'broken' ? 'audit event ID #42' : 'an invalid event ID', $message);
            }
            self::assertSame($feedback === 'toast' ? null : $message, session()->getFlashdata($valid ? 'success' : 'error'));
            self::assertNull(session()->getFlashdata($valid ? 'error' : 'success'));
        } finally {
            $db->close();
        }
    }

    public function testOnlyTheSevenRequestedNativeActionsAreMarked(): void
    {
        $expected = [
            'announcements/index.php' => ['publish', 'archive'],
            'announcements/show.php' => ['read'],
            'surveys/index.php' => ['publish', 'close'],
            'question_bank/index.php' => ['archive'],
            'admin/audit.php' => ['verify'],
        ];
        foreach ($expected as $view => $actions) {
            $source = file_get_contents(APPPATH . 'Views/' . $view);

            $marked = array_values(array_filter(explode("\n", $source), static fn ($line) => str_contains($line, 'data-background-submit')));
            self::assertCount(count($actions), $marked, $view);
            foreach ($actions as $index => $action) {
                self::assertStringContainsString('/' . $action, $marked[$index]);
                self::assertStringNotContainsString('data-modal-submit', $marked[$index]);
                if (in_array($action, ['publish', 'archive', 'close'], true)) self::assertStringContainsString('data-confirm=', $marked[$index]);
                if (($view === 'announcements/index.php') || $view === 'question_bank/index.php') self::assertStringContainsString('name="version"', $marked[$index]);
            }
            self::assertStringContainsString('csrf_field()', $source);
        }
    }
}
