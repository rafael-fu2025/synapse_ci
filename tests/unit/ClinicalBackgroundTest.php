<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\ClinicController;
use App\Controllers\GuidanceController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/** Response-only isolation: no database-backed controller constructors or real clinical records. */
final class ClinicalBackgroundTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        session()->remove(['success', 'error']);
        session()->set('user', ['id' => 42]);
    }

    protected function tearDown(): void
    {
        session()->remove(['success', 'error', 'user']);
        foreach (['request', 'response', 'redirectresponse'] as $service) Services::resetSingle($service);
        parent::tearDown();
    }

    private function controller(string $class, bool $xhr, string $feedback): object
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        foreach (['X-Requested-With', 'Accept', 'X-Synapse-Feedback', 'HX-Request'] as $header) $request->removeHeader($header);
        if ($xhr) $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        if ($feedback !== '') $request->setHeader('X-Synapse-Feedback', $feedback);
        $response = new Response(config('App'));
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        Services::injectMock('redirectresponse', new RedirectResponse(config('App')));
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    private function invoke(object $controller, string $method, mixed ...$args): ResponseInterface
    {
        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller, ...$args);
    }

    private function success(object $controller): ResponseInterface
    {
        return $controller instanceof GuidanceController
            ? $this->invoke($controller, 'mutate', static fn (int $actor): array => ['/guidance/cases/42', 'Saved.'])
            : $this->invoke($controller, 'inventoryFormResponse', true, 'Saved.', '/clinic/patients/42', 200);
    }

    private function payload(ResponseInterface $response, int $status, bool $success): array
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Location'));
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($success, $payload['success']);
        self::assertSame(['name' => csrf_token(), 'hash' => csrf_hash()], $payload['csrf']);
        return $payload;
    }

    public static function transports(): iterable
    {
        foreach ([GuidanceController::class, ClinicController::class] as $class) {
            foreach ([false, true] as $xhr) {
                foreach (['', 'toast', 'other'] as $feedback) yield [$class, $xhr, $feedback];
            }
        }
    }

    #[DataProvider('transports')]
    public function testToastSuppressesOnlyOptedInAjaxFlashAndNativeRedirectIsUnchanged(string $class, bool $xhr, string $feedback): void
    {
        $controller = $this->controller($class, $xhr, $feedback);
        $response = $this->success($controller);
        if ($xhr) {
            $payload = $this->payload($response, 200, true);
            self::assertSame('Saved.', $payload['message']);
            self::assertStringContainsString('/42', $payload['redirect']);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertStringContainsString('/42', $response->getHeaderLine('Location'));
        }
        self::assertSame($xhr && $feedback === 'toast' ? null : 'Saved.', session()->getFlashdata('success'));
    }

    public static function failures(): iterable
    {
        foreach ([GuidanceController::class, ClinicController::class] as $class) {
            foreach ([403, 404, 409, 422, 500] as $status) yield [$class, $status];
        }
    }

    #[DataProvider('failures')]
    public function testJsonErrorsContainCsrfWithoutFlashingOrLeakingClinicalException(string $class, int $status): void
    {
        $controller = $this->controller($class, true, 'toast');
        $error = new RuntimeException('Storage detail must not leak', $status);
        $response = $controller instanceof GuidanceController
            ? $this->invoke($controller, 'mutate', static function () use ($error): never { throw $error; })
            : $this->invoke($controller, 'inventoryFailure', $error, '/clinic/patients/42');
        $payload = $this->payload($response, $status, false);
        if ($controller instanceof GuidanceController || $status === 500) self::assertNotSame($error->getMessage(), $payload['message']);
        self::assertNull(session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public function testToastPreservesEarlierFeedback(): void
    {
        foreach ([GuidanceController::class, ClinicController::class] as $class) {
            $controller = $this->controller($class, true, 'toast');
            session()->setFlashdata('success', 'Earlier success');
            session()->setFlashdata('error', 'Earlier error');
            $this->success($controller);
            self::assertSame('Earlier success', session()->getFlashdata('success'));
            self::assertSame('Earlier error', session()->getFlashdata('error'));
        }
    }

    public function testHealthAjaxUnexpectedFailureReturnsSafeJsonAndNativeStillThrows(): void
    {
        // Uninitialized service deliberately fails before any DB access.
        $controller = $this->controller(ClinicController::class, true, 'toast');
        $payload = $this->payload($controller->updateHealth(42), 500, false);
        self::assertSame('Clinic request failed.', $payload['message']);
        $native = $this->controller(ClinicController::class, false, '');
        $this->expectException(\Error::class);
        $native->updateHealth(42);
    }

    public function testOnlySameWorkspaceFormsOptInAndClinicalRoutingContractsRemain(): void
    {
        $guidance = file_get_contents(APPPATH . 'Views/guidance/cases/view.php');
        preg_match_all('~<form\b.*?</form>~s', $guidance, $forms);
        self::assertCount(8, $forms[0]);
        foreach ($forms[0] as $form) {
            self::assertStringContainsString('data-background-submit', $form);
            self::assertStringContainsString('csrf_field()', $form);
        }
        self::assertStringContainsString('name="action" value="draft"', $guidance);
        self::assertStringContainsString('name="action" value="finalize"', $guidance);
        self::assertSame(2, substr_count($guidance, 'formaction='));
        $chart = file_get_contents(APPPATH . 'Views/clinic/patients/chart.php');
        preg_match_all('~<form\b.*?</form>~s', $chart, $forms);
        self::assertCount(2, $forms[0]);
        self::assertStringNotContainsString('data-background-submit', $forms[0][0]);
        self::assertStringContainsString('/start-direct/', $forms[0][0]);
        self::assertStringContainsString('data-background-submit', $forms[0][1]);
        self::assertStringContainsString(". '/health'", $forms[0][1]);
        $controller = file_get_contents(APPPATH . 'Controllers/GuidanceController.php');
        self::assertStringNotContainsString('withInput()', $controller);
        self::assertStringContainsString("getDecryptedSessionNote((int) \$activeSession['id'])", $controller);
    }
}
