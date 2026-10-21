<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Scheduling\AvailabilityWorkspaceContext as Context;
use CodeIgniter\Test\CIUnitTestCase;
use Config\App;
use PHPUnit\Framework\Attributes\DataProvider;

final class AvailabilityWorkspaceContextTest extends CIUnitTestCase
{
    public function testDefaultsAndAuthorizedRosterSelection(): void
    {
        $this->assertSame(Context::DEFAULTS, Context::fromGet([], []));
        $input = ['q' => '  José 医師  ', 'provider' => '42', 'day' => '0', 'section' => 'exceptions'];
        $expected = ['q' => 'José 医師', 'provider' => '42', 'day' => 0, 'section' => 'exceptions'];
        $this->assertSame($expected, Context::fromGet($input, [['id' => 42]]));
        $expected['provider'] = '';
        $this->assertSame($expected, Context::fromGet($input, [['id' => 43]]));
        $this->assertSame($expected, Context::fromGet($input, []));
    }

    public function testUtf8CharacterLimitAndEveryDayAndSection(): void
    {
        foreach (range(0, 6) as $day) {
            foreach (['enrollment', 'weekly', 'exceptions'] as $section) {
                $context = Context::fromGet(['q' => str_repeat('医', 120), 'day' => (string) $day, 'section' => $section], []);
                $this->assertSame($day, $context['day']);
                $this->assertSame($section, $context['section']);
                $this->assertSame(str_repeat('医', 120), $context['q']);
            }
        }
        $this->assertSame((string) PHP_INT_MAX, Context::fromGet(['provider' => (string) PHP_INT_MAX], [['id' => PHP_INT_MAX]])['provider']);
    }

    public static function malformedContexts(): iterable
    {
        foreach ([[], null, true, 12, str_repeat('a', 121), str_repeat('医', 121), "bad\xFF", '<script>alert(1)</script>', "a\nb", "a\0b"] as $value) {
            yield ['q', $value];
        }
        foreach ([[], null, false, 1.5, '0', '-1', '1e2', '1.0', '//evil.test', '42&section=enrollment', (string) PHP_INT_MAX . '0'] as $value) {
            yield ['provider', $value];
        }
        foreach ([[], null, true, 1.0, '', '-1', '7', '1e0', 'weekly'] as $value) {
            yield ['day', $value];
        }
        foreach ([[], null, 1, '', 'Weekly', '//evil.test', 'weekly#exceptions'] as $value) {
            yield ['section', $value];
        }
    }

    #[DataProvider('malformedContexts')]
    public function testMalformedGetIsControlledValidationError(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Context::fromGet([$key => $value], []);
    }

    #[DataProvider('malformedContexts')]
    public function testMalformedReturnContextFallsBackWithoutThrowing(string $key, mixed $value): void
    {
        $post = ['return_q' => 'valid', 'return_provider' => '42', 'return_day' => '6', 'return_section' => 'exceptions'];
        $post['return_' . $key] = $value;
        $this->assertSame(Context::DEFAULTS, Context::fromPost($post));
    }

    public function testPostUsesOnlyReturnFieldsAndDoesNotInferTemplateSelections(): void
    {
        $post = ['return_q' => 'Find', 'return_provider' => '42', 'return_day' => '3', 'return_section' => 'weekly',
            'staff_assignment_ids' => ['99'], 'days' => ['Monday'], 'provider' => '99',
            'redirect' => 'https://evil.test', 'return_url' => '//evil.test'];
        $context = Context::fromPost($post);
        $this->assertSame(['q' => 'Find', 'provider' => '42', 'day' => 3, 'section' => 'weekly'], $context);
        $this->assertSame(Context::DEFAULTS, Context::fromPost(['staff_assignment_ids' => ['99'], 'days' => ['Monday']]));
        $this->assertSame(['q' => 'Find', 'provider' => '99', 'day' => 0, 'section' => 'weekly'], Context::withSubmitted($context, '99', '0'));
        $this->assertSame(['q' => 'Find', 'provider' => '99', 'day' => 3, 'section' => 'weekly'], Context::withSubmitted($context, '99'));
        $this->assertSame($context, Context::withSubmitted($context, [], []));
        $this->assertSame('99', Context::withSubmitted($context, ' +99 ', ' 0 ')['provider']);
    }

    public function testReturnUrlIsFixedAndQueryIsEncodedWithBaseAndIndexPage(): void
    {
        helper('url');
        $context = Context::fromPost(['return_q' => 'https://evil.test/?a=1&provider=99#fragment', 'return_provider' => '42',
            'return_day' => '0', 'return_section' => 'exceptions', 'return_url' => 'https://evil.test']);
        foreach (['', 'index.php'] as $indexPage) {
            foreach (['clinic', 'guidance'] as $unit) {
                $config = new App();
                $config->baseURL = 'https://example.test/nested/app/';
                $config->indexPage = $indexPage;
                $url = site_url(Context::path($unit, $context), null, $config);
                $expectedPath = '/nested/app/' . ($indexPage === '' ? '' : 'index.php/') . $unit . '/appointments/availability';
                $this->assertSame('example.test', parse_url($url, PHP_URL_HOST));
                $this->assertSame($expectedPath, parse_url($url, PHP_URL_PATH));
                $this->assertNull(parse_url($url, PHP_URL_FRAGMENT));
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame(['q' => $context['q'], 'provider' => '42', 'day' => '0', 'section' => 'exceptions'], $query);
            }
        }
        $this->assertStringStartsWith('/https%3A%2F%2Fevil.test/appointments/availability?', Context::path('https://evil.test', $context));
    }

    public function testControllerWiresContextWithoutChangingSchedulingPayloadsOrResponseContract(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/AppointmentController.php');
        $availability = $this->methodSource($source, 'availability');
        $this->assertStringContainsString("setHeader('Cache-Control', 'private, no-store')", $availability);
        $this->assertStringNotContainsString('formModalPage', $availability);
        $this->assertStringContainsString('getAvailability($unit, $this->actor())', $availability);
        $this->assertStringContainsString('AvailabilityWorkspaceContext::fromGet($this->request->getGet(), $providers)', $availability);
        $this->assertStringContainsString("'workspaceContext' => \$workspaceContext", $availability);
        $this->assertStringContainsString("'returnQuery' => AvailabilityWorkspaceContext::query(\$workspaceContext)", $availability);
        foreach (['getAvailabilityExceptions', 'departmentStaff'] as $method) {
            $this->assertStringContainsString($method . '($unit, $this->actor())', $availability);
        }
        foreach (['availabilityStore' => 'saveAvailability', 'availabilityTemplateStore' => 'applyDepartmentTemplate',
            'availabilityEnroll' => 'enrollDepartmentStaff', 'changeAvailabilityException' => 'saveAvailabilityException'] as $method => $service) {
            $body = $this->methodSource($source, $method);
            $this->assertStringContainsString('$redirect = $this->availabilityReturnPath($unit);', $body);
            $this->assertStringContainsString('$this->appointmentService->' . $service . '($unit,', $body);
            $this->assertStringContainsString('$this->actor()', $body);
            $this->assertStringContainsString('$this->status($e)', $body);
            $this->assertStringNotContainsString("'return_", $body);
        }
        $this->assertStringContainsString('$this->availabilityReturnPath($unit, true, true)', $this->methodSource($source, 'availabilityStore'));
        $this->assertStringContainsString('$this->availabilityReturnPath($unit, true)', $this->methodSource($source, 'changeAvailabilityException'));
        $this->assertStringNotContainsString('availabilityReturnPath($unit, true', $this->methodSource($source, 'availabilityTemplateStore'));
        $response = $this->methodSource($source, 'respondToForm');
        $this->assertStringContainsString("session()->setFlashdata('success', \$message)", $response);
        $this->assertStringContainsString("'redirect' => site_url(\$redirect)", $response);
        $this->assertStringContainsString("redirect()->back()->withInput()->with('error', \$message)", $response);
    }

    private function methodSource(string $source, string $method): string
    {
        preg_match('/(?:public|private) function ' . preg_quote($method, '/') . '\([^\n]*\).*?(?=\n    (?:public|private) function|\n\}\s*\z)/s', $source, $match);
        $this->assertNotEmpty($match, 'Controller method exists: ' . $method);
        return $match[0];
    }
}
