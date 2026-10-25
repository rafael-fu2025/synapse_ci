<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\AuthController;
use App\Filters\WebCsrfFilter;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class LogoutConfirmationTest extends CIUnitTestCase
{
    public static function confirmations(): iterable
    {
        foreach (['', 'index.php'] as $indexPage) {
            foreach ([false, true] as $warning) {
                yield [$indexPage, $warning];
            }
        }
    }

    #[RunInSeparateProcess]
    #[DataProvider('confirmations')]
    public function testConfirmationIsReadOnlyAndOffersProtectedExplicitPost(string $indexPage, bool $warning): void
    {
        helper(['url', 'form']);
        $app = config(App::class);
        $app->indexPage = $indexPage;
        $request = new IncomingRequest($app, new SiteURI($app, 'auth/logout/confirm'), null, new UserAgent());
        $request->setMethod('GET');
        $request->setGlobal('get', ['return' => 'https://evil.invalid', 'next' => '//evil.invalid']);
        Services::injectMock('request', $request);
        Services::injectMock('response', new Response($app));
        session()->set(['user' => ['id' => 42], '_auth' => ['version' => 7], 'tenant_id' => 3]);
        if ($warning) session()->setFlashdata('logout_warning', 'Sign out was not completed. <script>unsafe</script>');
        session()->getFlashdata('logout_warning'); // Initialize framework flashdata bookkeeping before the snapshot.
        $before = $_SESSION;
        $id = session_id();
        self::assertNull((new WebCsrfFilter())->before($request));
        // Bypass constructor-only model dependencies: this action must not need auth or carousel services.
        $controller = (new \ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
        $html = $controller->confirmLogout();
        self::assertSame($before, $_SESSION);
        self::assertSame($id, session_id());
        self::assertStringContainsString('no-store', service('response')->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('method="post" action="' . esc(site_url('auth/logout'), 'attr') . '"', $html);
        self::assertStringContainsString(csrf_field(), $html);
        self::assertStringContainsString('href="' . esc(site_url('/'), 'attr') . '">Cancel</a>', $html);
        self::assertStringContainsString('type="submit"', $html);
        self::assertStringNotContainsString('evil.invalid', $html);
        self::assertStringNotContainsString('<script>unsafe</script>', $html);
        if ($warning) self::assertStringContainsString('Sign out was not completed.', $html);
        else self::assertStringNotContainsString('Sign out was not completed.', $html);
    }

    #[RunInSeparateProcess]
    public function testRoutesKeepLogoutPostProtectedAndGetNonMutating(): void
    {
        $routes = Services::routes();
        $routes->loadRoutes();
        $get = $routes->getRoutes('GET');
        $post = $routes->getRoutes('POST');
        self::assertStringEndsWith('AuthController::confirmLogout', $get['auth/logout/confirm']);
        self::assertStringEndsWith('AuthController::logout', $post['auth/logout']);
        self::assertContains('web_csrf', $routes->getFiltersForRoute('auth/logout', 'POST'));
        session()->set('user', ['id' => 42]);
        $before = $_SESSION;
        $response = $get['auth/logout']();
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('POST', $response->getHeaderLine('Allow'));
        self::assertSame($before, $_SESSION);
    }
}
