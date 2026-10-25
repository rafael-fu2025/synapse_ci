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
            service('security')->generateHash();
            if (strtoupper($request->getMethod()) === 'POST'
                && trim($request->getUri()->getRoutePath(), '/') === 'auth/login') {
                // Discard the rejected credentials; recover with a fresh GET, never replay the POST.
                session()->remove('_ci_old_input');
                $redirect = redirect()->to(site_url('auth/login'), 303)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->with('warning', 'Your sign-in page has expired or is no longer valid. Please sign in again.');
                if (config('Security')->csrfProtection === 'cookie') {
                    $redirect->withCookies();
                }
                return $redirect;
            }
            if (strtoupper($request->getMethod()) === 'POST'
                && trim($request->getUri()->getRoutePath(), '/') === 'auth/logout') {
                // Require a new, explicit submission; never sign out or replay a rejected POST.
                $redirect = redirect()->to(site_url('auth/logout/confirm'), 303)
                    ->setHeader('Cache-Control', 'private, no-store')
                    ->with('logout_warning', 'Sign out was not completed because your security token expired or was invalid. Please confirm below to sign out.');
                if (config('Security')->csrfProtection === 'cookie') {
                    $redirect->withCookies();
                }
                return $redirect;
            }
            return $response->setBody(esc($message . ' This request was not processed. A fresh security token was issued; reload this page and retry.'));
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
