<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AppointmentController;
use App\Controllers\ReferralController;
use CodeIgniter\Config\Factories;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Test\CIUnitTestCase;
use Config\RefreshRegions;
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
        unset($_ENV['refreshRegions.enabled'], $_ENV['refreshRegions.owners'], $_SERVER['refreshRegions.enabled'], $_SERVER['refreshRegions.owners']);
        putenv('refreshRegions.enabled');
        putenv('refreshRegions.owners');
    }

    protected function tearDown(): void
    {
        session()->remove(['success', 'error']);
        Factories::reset('config');
        foreach (['request', 'response', 'redirectresponse'] as $service) {
            Services::resetSingle($service);
        }
        parent::tearDown();
    }

    private function controller(string $class, string $transport, string $feedback): object
    {
        $request = Services::incomingrequest(null, false);
        $request->setMethod('POST');
        foreach (['X-Requested-With', 'Accept', 'X-Synapse-Feedback', 'X-Synapse-Refresh-Owner'] as $header) {
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

    private function invoke(object $controller, string $method, mixed ...$args): mixed
    {
        return (new ReflectionClass($controller))->getMethod($method)->invoke($controller, ...$args);
    }

    private function success(object $controller, string $target, ?array $refresh = null): ResponseInterface
    {
        return $controller instanceof AppointmentController
            ? $this->invoke($controller, 'respondToForm', 'Saved.', true, $target, 422, $refresh)
            : $this->invoke($controller, 'success', 'Saved.', $target, $refresh);
    }

    /** @param list<string> $owners */
    private function enableRefreshOwners(array $owners): void
    {
        $config = new RefreshRegions();
        $config->enabled = true;
        $config->owners = $owners;
        Factories::injectMock('config', RefreshRegions::class, $config);
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

    public function testRegionalDescriptorsAreEmittedOnlyForEnabledOwners(): void
    {
        $cases = [
            [AppointmentController::class, 'appointments.staff.clinic', 'staffRefresh', ['clinic'], [
                'mode' => 'regions', 'owner' => 'appointments.staff.clinic',
                'regions' => ['appointments.pending', 'appointments.schedule'],
            ]],
            [AppointmentController::class, 'appointments.portal', 'portalRefresh', [], [
                'mode' => 'regions', 'owner' => 'appointments.portal',
                'regions' => ['appointments.results'],
            ]],
            [ReferralController::class, 'referrals.guidance', 'folderRefresh', ['guidance'], [
                'mode' => 'regions', 'owner' => 'referrals.guidance',
                'regions' => ['referrals.folders', 'referrals.results'],
            ]],
        ];

        foreach ($cases as [$class, $owner, $method, $arguments, $expected]) {
            Factories::reset('config');
            $controller = $this->controller($class, 'xhr', 'toast');
            self::assertNull($this->invoke($controller, $method, ...$arguments), $owner . ' is disabled by default');
            $this->enableRefreshOwners([$owner]);
            $descriptor = $this->invoke($controller, $method, ...$arguments);
            self::assertSame($expected, $descriptor);
            $payload = $this->json($this->success($controller, '/clinic/appointments', $descriptor), 200, true);
            self::assertSame($expected, $payload['refresh']);
        }
    }

    public function testAvailabilityDescriptorPreservesValidatedWorkspaceDestination(): void
    {
        $this->enableRefreshOwners(['availability.guidance']);
        $controller = $this->controller(AppointmentController::class, 'xhr', 'toast');
        $destination = '/guidance/appointments/availability?q=Ada&provider=7&day=4&section=weekly';
        self::assertSame([
            'mode' => 'regions', 'owner' => 'availability.guidance', 'regions' => ['results'],
            'url' => site_url($destination),
        ], $this->invoke($controller, 'availabilityRefresh', 'guidance', $destination));
    }

    public function testEnabledDestinationActionsUseExplicitNavigationDescriptors(): void
    {
        $cases = [
            [AppointmentController::class, 'appointments.staff.clinic', ['appointments.staff.clinic', '/clinic/queue'], '/clinic/queue'],
            [ReferralController::class, 'referrals.guidance', ['guidance', '/guidance/cases/42'], '/guidance/cases/42'],
        ];
        foreach ($cases as [$class, $owner, $arguments, $target]) {
            Factories::reset('config');
            $this->enableRefreshOwners([$owner]);
            $controller = $this->controller($class, 'xhr', 'toast');
            $descriptor = $this->invoke($controller, 'navigationRefresh', ...$arguments);
            self::assertSame(['mode' => 'navigate', 'url' => site_url($target), 'history' => 'push'], $descriptor);
            $payload = $this->json($this->success($controller, $target, $descriptor), 200, true);
            self::assertSame($descriptor, $payload['refresh']);
        }
    }

    public function testDisabledOwnersKeepLegacyJsonPayloadWithoutRefreshHint(): void
    {
        foreach ([AppointmentController::class, ReferralController::class] as $class) {
            Factories::reset('config');
            $controller = $this->controller($class, 'xhr', 'toast');
            $payload = $this->json($this->success($controller, '/clinic/appointments'), 200, true);
            self::assertArrayNotHasKey('refresh', $payload);
        }
    }

    public function testStableRegionalWrappersAreAlwaysRendered(): void
    {
        $appointment = file_get_contents(APPPATH . 'Views/appointments/index.php');
        self::assertStringContainsString('data-refresh-owner="<?= esc($refreshOwner, \'attr\') ?>"', $appointment);
        self::assertSame(1, substr_count($appointment, 'data-refresh-region="appointments.pending"'));
        self::assertSame(1, substr_count($appointment, 'data-refresh-region="appointments.schedule"'));

        $portal = file_get_contents(APPPATH . 'Views/portal/index.php');
        self::assertSame(1, substr_count($portal, 'data-refresh-owner="appointments.portal"'));
        self::assertSame(1, substr_count($portal, 'data-refresh-region="appointments.results"'));

        $referrals = file_get_contents(APPPATH . 'Views/referrals/index.php');
        self::assertStringContainsString('<?php $refreshOwner = \'referrals.\' . $unit; ?>', $referrals);
        self::assertSame(1, substr_count($referrals, 'data-refresh-region="referrals.folders"'));
        self::assertSame(1, substr_count($referrals, 'data-refresh-region="referrals.results"'));
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
