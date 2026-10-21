<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\WebSecurityHeaders;
use CodeIgniter\HTTP\ContentSecurityPolicy;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\ContentSecurityPolicy as PolicyConfig;
use Config\Services;

final class WebSecurityHeadersTest extends CIUnitTestCase
{
    public function testRequiredHeadersProtectSuccessErrorsAndRedirects(): void
    {
        foreach ([200, 302, 401, 403, 404, 429, 500, 503] as $status) {
            $response = (new Response(config('App')))->setStatusCode($status);
            (new WebSecurityHeaders())->after(Services::incomingrequest(null, false), $response);
            $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
            $this->assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
            $this->assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'));
            $this->assertSame('0', $response->getHeaderLine('X-XSS-Protection'));
            $this->assertStringContainsString('serial=(self)', $response->getHeaderLine('Permissions-Policy'));
            $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
            foreach (["frame-ancestors 'self'", "form-action 'self'", "base-uri 'self'", "object-src 'none'"] as $rule) {
                $this->assertStringContainsString($rule, $response->getHeaderLine('Content-Security-Policy'));
            }
            $this->assertSame($status, $response->getStatusCode());
        }
    }

    public function testStrongerExistingPolicyIsNotReplaced(): void
    {
        $response = (new Response(config('App')))->setHeader('X-Frame-Options', 'DENY')
            ->setHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        (new WebSecurityHeaders())->after(Services::incomingrequest(null, false), $response);
        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame("default-src 'none'; frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testStrictCandidateIsReportOnlyAndDoesNotBlockInlineUi(): void
    {
        $config = new PolicyConfig();
        $this->assertTrue($config->reportOnly);
        $this->assertNull($config->reportURI);
        $this->assertNull($config->reportTo);
        $response = (new Response(config('App')))->setBody('<script>window.fixture = true;</script>');
        (new WebSecurityHeaders())->after(Services::incomingrequest(null, false), $response);
        (new ContentSecurityPolicy($config))->finalize($response);
        $report = $response->getHeaderLine('Content-Security-Policy-Report-Only');
        $enforced = $response->getHeaderLine('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self'", $report);
        $this->assertStringContainsString('https://fonts.googleapis.com', $report);
        $this->assertStringContainsString('https://fonts.gstatic.com', $report);
        $this->assertStringNotContainsString('unsafe-inline', $report);
        $this->assertStringNotContainsString('unsafe-eval', $report);
        $this->assertStringNotContainsString('script-src', $enforced);
        $this->assertStringNotContainsString('style-src', $enforced);
        $this->assertStringContainsString('window.fixture = true', $response->getBody());
    }

    public function testFilterAndRouteWiringProtectsEarlyRejectionsWithoutDisablingCsrf(): void
    {
        $config = new \Config\Filters();
        $this->assertSame(WebSecurityHeaders::class, $config->aliases['web_secureheaders']);
        $this->assertContains('web_secureheaders', $config->required['after']);
        $source = file_get_contents(APPPATH . 'Config/Routes.php');
        $this->assertStringContainsString("['filter' => ['web_csrf', 'login_throttle']]", $source);
        $this->assertStringContainsString("\$routes->post('auth/login', 'AuthController::attemptLogin'", $source);
        $app = file_get_contents(APPPATH . 'Config/App.php');
        $this->assertStringContainsString('public bool $CSPEnabled = true;', $app);
    }
}
