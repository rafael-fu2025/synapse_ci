<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filters\BmgPrivateToolbar;
use CodeIgniter\Debug\Toolbar;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class BmgPrivateToolbarTest extends CIUnitTestCase
{
    #[DataProvider('sensitiveRequests')]
    public function testSensitiveRequestsNeverPrepareToolbar(string $path, string $method, bool $ajax, string $contentType): void
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/' . $path), null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('X-Device-Token', 'test-raw-device-token');
        $request->setGlobal('post', ['wifi_password' => 'test-wifi-password', 'token' => 'test-raw-token']);
        if ($ajax) {
            $request->setHeader('X-Requested-With', 'XMLHttpRequest');
        } else {
            $this->assertFalse($request->hasHeader('X-Requested-With'));
        }
        $body = $contentType === 'application/octet-stream' ? "\x00\xffcompiled-firmware\x00" : '{"token":"test-one-time-token"}';
        $response = (new Response(config('App')))->setContentType($contentType)->setBody($body);
        $headers = $response->headers();
        $toolbar = $this->getMockBuilder(Toolbar::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $toolbar->expects($this->never())->method('prepare');
        Services::injectMock('toolbar', $toolbar);

        $this->assertNull((new BmgPrivateToolbar())->after($request, $response));
        $this->assertSame($body, $response->getBody());
        $this->assertSame($headers, $response->headers());
        $this->assertSame('test-raw-device-token', $request->getHeaderLine('X-Device-Token'));
        $this->assertSame('test-wifi-password', $request->getPost('wifi_password'));
    }

    public static function sensitiveRequests(): iterable
    {
        foreach (['', 'campus/index.php/'] as $prefix) {
            foreach (['', '/'] as $slash) {
                yield $prefix . 'plain build' . $slash => [$prefix . 'facilities/unit/12/usb/build' . $slash, 'POST', false, 'text/html'];
                yield $prefix . 'ajax build' . $slash => [$prefix . 'facilities/unit/12/usb/build' . $slash, 'POST', true, 'application/json'];
                yield $prefix . 'build GET' . $slash => [$prefix . 'facilities/unit/12/usb/build' . $slash, 'GET', false, 'text/html'];
                yield $prefix . 'binary part' . $slash => [$prefix . 'facilities/unit/12/usb/releases/v1-2-alpha/parts/0' . $slash, 'GET', false, 'application/octet-stream'];
                yield $prefix . 'single release' . $slash => [$prefix . 'facilities/unit/1/usb/releases/a/parts/123' . $slash, 'POST', false, 'application/octet-stream'];
                yield $prefix . 'credential' . $slash => [$prefix . 'facilities/unit/12/device' . $slash, 'POST', false, 'text/html'];
                yield $prefix . 'credential GET' . $slash => [$prefix . 'facilities/unit/12/device' . $slash, 'GET', false, 'text/html'];
            }
            foreach (['0', '01', '00012', 'invalid', '%30%31'] as $unit) {
                foreach (['usb/build', 'usb/build/extra', 'device', 'device/extra', 'usb/releases/alpha/parts/0', 'usb/releases/ALPHA/parts/-1'] as $endpoint) {
                    yield $prefix . $unit . $endpoint => [$prefix . 'facilities/unit/' . $unit . '/' . $endpoint, 'POST', false, 'application/json'];
                }
            }
            foreach (['usb/releases/alpha--beta/parts/0', 'usb/releases/alpha/parts/0/extra'] as $endpoint) {
                yield $prefix . $endpoint => [$prefix . 'facilities/unit/12/' . $endpoint, 'GET', false, 'application/octet-stream'];
            }
            foreach (['GET', 'POST'] as $method) {
                foreach (['', '/', '/heartbeat', '/nested/resource'] as $suffix) {
                    yield $prefix . 'API ' . $method . $suffix => [$prefix . 'api/v1/devices/bmg' . $suffix, $method, false, 'application/json'];
                }
            }
        }
    }

    #[DataProvider('ordinaryPaths')]
    public function testOtherRequestsDelegateToParentExactlyOnce(string $path): void
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/' . $path), null, new UserAgent());
        $response = (new Response(config('App')))->setContentType('text/html')->setBody('<html>Dashboard</html>');
        $toolbar = $this->getMockBuilder(Toolbar::class)->disableOriginalConstructor()->onlyMethods(['prepare'])->getMock();
        $toolbar->expects($this->once())->method('prepare')->with($this->identicalTo($request), $this->identicalTo($response));
        Services::injectMock('toolbar', $toolbar);

        $this->assertNull((new BmgPrivateToolbar())->after($request, $response, ['unchanged']));
    }

    public static function ordinaryPaths(): iterable
    {
        foreach ([
            'dashboard',
            'facilities/unit/12',
            'facilities/unit/12/usb',
            'facilities/unit/12/usb/build-status',
            'facilities/unit/12/devices',
            'facilities/unit/12/usb/releases/alpha/manifest',
            'api/v1/devices/bmg-other',
            'notapi/v1/devices/bmg',
            'notfacilities/unit/12/usb/build',
        ] as $path) {
            yield $path => [$path];
        }
    }

    public function testToolbarAliasUsesPrivateFilter(): void
    {
        $this->assertSame(BmgPrivateToolbar::class, (new \Config\Filters())->aliases['toolbar']);
    }
}
