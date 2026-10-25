<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AppointmentController;
use App\Controllers\ReferralController;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

/** Response helpers and view opt-ins only: no database mutations or migrations. */
final class AppointmentReferralBackgroundResponseTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper(['url', 'form']);
        session()->remove(['success', 'error']);
    }

    protected function tearDown(): void
    {
        session()->remove(['success', 'error']);
        foreach (['request', 'response', 'redirectresponse'] as $service) {
            Services::resetSingle($service);
        }
        parent::tearDown();
    }

    private function controller(string $class, string $transport, string $feedback): object
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        foreach (['X-Requested-With', 'Accept', 'X-Synapse-Feedback'] as $header) {
            $request->removeHeader($header);
        }
        if ($transport === 'xhr') $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        if ($transport === 'accept') $request->setHeader('Accept', 'application/json');
        if ($feedback !== '') $request->setHeader('X-Synapse-Feedback', $feedback);
        $response = new Response(config('App'));
        Services::injectMock('request', $request);
        Services::injectMock('response', $response);
        Services::injectMock('redirectresponse', new RedirectResponse(config('App')));
        // Skip constructors so response-only tests cannot instantiate database-backed services.
        $controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $controller->initController($request, $response, new NullLogger());
        return $controller;
    }

    private function invoke(object $controller, string $method, mixed ...$args): ResponseInterface
    {
        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller, ...$args);
    }

    private function success(object $controller, string $target): ResponseInterface
    {
        return $controller instanceof AppointmentController
            ? $this->invoke($controller, 'respondToForm', 'Saved.', true, $target)
            : $this->invoke($controller, 'success', 'Saved.', $target);
    }

    private function json(ResponseInterface $response, int $status, bool $success): array
    {
        self::assertSame($status, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Location'));
        $payload = json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($success, $payload['success']);
        self::assertSame(['name' => csrf_token(), 'hash' => csrf_hash()], $payload['csrf']);
        self::assertNotEmpty($payload['csrf']['hash']);
        return $payload;
    }

    public static function successCases(): iterable
    {
        foreach ([AppointmentController::class, ReferralController::class] as $class) {
            foreach (['native', 'xhr', 'accept'] as $transport) {
                foreach (['', 'toast', 'other'] as $feedback) {
                    yield $class . ':' . $transport . ':' . $feedback => [$class, $transport, $feedback];
                }
            }
        }
    }

    #[DataProvider('successCases')]
    public function testOnlyExplicitToastFeedbackSuppressesSuccessFlash(string $class, string $transport, string $feedback): void
    {
        $controller = $this->controller($class, $transport, $feedback);
        $target = $class === AppointmentController::class ? '/clinic/appointments' : '/guidance/referrals?tab=history';
        $response = $this->success($controller, $target);
        if ($transport === 'xhr' || ($transport === 'accept' && $class === ReferralController::class)) {
            $payload = $this->json($response, 200, true);
            self::assertSame('Saved.', $payload['message']);
            self::assertSame(site_url($target), $payload['redirect']);
        } else {
            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame(site_url($target), $response->getHeaderLine('Location'));
        }
        self::assertSame($feedback === 'toast' ? null : 'Saved.', session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public static function controllers(): iterable
    {
        yield [AppointmentController::class];
        yield [ReferralController::class];
    }

    #[DataProvider('controllers')]
    public function testToastDoesNotClearEarlierFlash(string $class): void
    {
        $controller = $this->controller($class, 'xhr', 'toast');
        session()->setFlashdata('success', 'Earlier success');
        session()->setFlashdata('error', 'Earlier error');
        $this->success($controller, '/clinic/appointments');
        self::assertSame('Earlier success', session()->getFlashdata('success'));
        self::assertSame('Earlier error', session()->getFlashdata('error'));
    }

    public static function failures(): iterable
    {
        foreach ([AppointmentController::class, ReferralController::class] as $class) {
            foreach ([401, 403, 404, 409, 422, 503] as $status) {
                yield $class . ':' . $status => [$class, $status];
            }
        }
    }

    #[DataProvider('failures')]
    public function testToastErrorsKeepJsonStatusMessageAndCsrf(string $class, int $status): void
    {
        $controller = $this->controller($class, 'xhr', 'toast');
        $response = $class === AppointmentController::class
            ? $this->invoke($controller, 'respondToForm', 'Rejected.', false, '/clinic/appointments', $status)
            : $this->invoke($controller, 'failure', new RuntimeException('Rejected.', $status));
        $payload = $this->json($response, $status, false);
        self::assertSame($class === ReferralController::class && $status === 503
            ? 'Referral operation could not be completed. Please retry or contact support.' : 'Rejected.', $payload['message']);
        self::assertNull(session()->getFlashdata('success'));
        self::assertNull(session()->getFlashdata('error'));
    }

    public function testOnlyRequestedRowFormsOptInWithoutOverridingRefreshUrl(): void
    {
        $expected = [
            'appointments/index.php' => ['/appointments/cancel/', '/appointments/no-show/', '/appointments/cancel/'],
            'portal/index.php' => ['/cancel/'],
            'referrals/_results.php' => ['/acknowledge', '/review', '/close'],
        ];
        foreach ($expected as $view => $actions) {
            $source = file_get_contents(APPPATH . 'Views/' . $view);
            preg_match_all('~<form\b.*?</form>~s', $source, $matches);
            $marked = array_values(array_filter($matches[0], static fn (string $form): bool => str_contains($form, 'data-background-submit')));
            self::assertCount(count($actions), $marked, $view);
            self::assertSame(count($actions), substr_count($source, 'data-background-submit'), $view);
            foreach ($actions as $index => $action) {
                $form = $marked[$index];
                self::assertStringContainsString($action, $form);
                self::assertStringContainsString('method="POST"', $form);
                self::assertStringContainsString('csrf_field()', $form);
                self::assertStringNotContainsString('data-modal-submit', $form);
                self::assertStringNotContainsString('data-background-refresh', $form);
                if (!in_array($action, ['/acknowledge', '/review'], true)) {
                    self::assertStringContainsString('data-confirm=', $form);
                }
                if ($action === '/appointments/no-show/') {
                    self::assertStringContainsString('name="version"', $form);
                }
            }
        }
    }
}
