<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Security\Exceptions\SecurityException;
use WeakMap;

/** Browser writes only; hardware token-authenticated routes are not attached to this filter. */
class WebCsrfFilter implements FilterInterface
{
    private static ?WeakMap $verified = null;

    public function before(RequestInterface $request, $arguments = null)
    {
        if (!$request instanceof IncomingRequest || !in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return null;
        }
        self::$verified ??= new WeakMap();
        if (isset(self::$verified[$request])) { return null; }
        try {
            service('security')->verify($request);
            self::$verified[$request] = true;
        } catch (SecurityException $e) {
            $message = 'Your security token is missing, invalid, or expired. Reload this page and try again.';
            $response = service('response')->setStatusCode(403)->setHeader('Cache-Control', 'private, no-store');
            if ($request->isAJAX() || strtolower($request->getHeaderLine('HX-Request')) === 'true'
                || str_contains(strtolower($request->getHeaderLine('Accept')), 'json')) {
                service('security')->generateHash();
                return $response->setJSON(['success' => false, 'message' => $message, 'reload' => true,
                    'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
            }
            return $response->setBody(esc($message));
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $response->setHeader('X-CSRF-TOKEN', csrf_hash());
        $response->setHeader('X-CSRF-NAME', csrf_token());
        $response->setHeader('X-CSRF-HEADER', csrf_header());
        if ($response instanceof \CodeIgniter\HTTP\RedirectResponse && config('Security')->csrfProtection === 'cookie') {
            $response->withCookies();
        }
    }
}
