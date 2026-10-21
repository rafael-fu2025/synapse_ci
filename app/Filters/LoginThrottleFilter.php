<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\LoginRateLimiter;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

final class LoginThrottleFilter implements FilterInterface
{
    public function __construct(private ?LoginRateLimiter $limiter = null)
    {
    }

    public function before(RequestInterface $request, $arguments = null)
    {
        if (!$request instanceof IncomingRequest || strtoupper($request->getMethod()) !== 'POST') {
            return null;
        }
        session()->remove('_ci_old_input');
        try {
            $limiter = $this->limiter ?? service('loginRateLimiter');
            // Framework proxy handling uses App.proxyIPs; do not trust raw forwarded headers here.
            $wait = $limiter->consume($request->getIPAddress(), $request->getPost('identifier'));
        } catch (Throwable $e) {
            // No exception messages, credential payloads, or IP/identifier values in logs/responses.
            log_message('error', 'Login limiter unavailable: {type}', ['type' => get_class($e)]);
            return $this->failure($request, 503, 'Sign-in is temporarily unavailable. Please try again shortly.', 60);
        }
        return $wait > 0
            ? $this->failure($request, 429, 'Too many sign-in attempts. Please wait before trying again.', $wait)
            : null;
    }

    private function failure(IncomingRequest $request, int $status, string $message, int $retryAfter): ResponseInterface
    {
        $response = service('response')->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('Retry-After', (string) max(1, $retryAfter))
            ->setHeader('X-CSRF-TOKEN', csrf_hash())->setHeader('X-CSRF-NAME', csrf_token())
            ->setHeader('X-CSRF-HEADER', csrf_header());
        if ($request->isAJAX() || strtolower($request->getHeaderLine('HX-Request')) === 'true'
            || str_contains(strtolower($request->getHeaderLine('Accept')), 'json')) {
            return $response->setJSON(['success' => false, 'message' => $message,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()]]);
        }
        return $response->setContentType('text/html')->setBody(
            '<!doctype html><html lang="en"><meta charset="utf-8"><title>Sign-in unavailable</title>' .
            '<main><h1>' . ($status === 429 ? 'Too many sign-in attempts' : 'Sign-in unavailable') . '</h1>' .
            '<p>' . esc($message) . '</p><a href="' . esc(site_url('/auth/login'), 'attr') . '">Return to sign in</a></main></html>'
        );
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
