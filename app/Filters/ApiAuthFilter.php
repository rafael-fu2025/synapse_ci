<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\Api\ApiContext;
use App\Services\Api\TokenService;
use App\Services\AuthenticatedUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;

/** Bearer-token authentication for the mobile API; browser sessions are never accepted here. */
final class ApiAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        ApiContext::clear();
        if (!$request instanceof IncomingRequest) {
            return $this->deny(401, 'invalid_token', AuthenticatedUser::AUTHENTICATION_MESSAGE);
        }

        // Bound unauthenticated traffic before any token work, then per-token traffic.
        $throttler = service('throttler');
        $ipKey = 'api-auth-ip-' . hash('sha256', $request->getIPAddress());
        if (!$throttler->check($ipKey, 120, 60)) {
            return $this->deny(429, 'rate_limited', 'Too many API requests. Please wait before trying again.');
        }

        if (preg_match('/^Bearer ([0-9a-f]{64})$/i', $request->getHeaderLine('Authorization'), $match) !== 1) {
            return $this->deny(401, 'invalid_token', AuthenticatedUser::AUTHENTICATION_MESSAGE);
        }
        // A browser session and a bearer credential must never mix on one request.
        if ((string) $request->getCookie(config('Session')->cookieName) !== '') {
            return $this->deny(401, 'invalid_token', 'Session cookies cannot authenticate API requests.');
        }
        if (!$throttler->check('api-auth-token-' . hash('sha256', $match[1]), 240, 60)) {
            return $this->deny(429, 'rate_limited', 'Too many API requests. Please wait before trying again.');
        }

        try {
            $resolved = (new TokenService())->resolve(strtolower($match[1]));
        } catch (RuntimeException) {
            return $this->deny(503, 'service_unavailable', 'The service is temporarily unavailable. Please try again shortly.');
        }
        if ($resolved === null) {
            return $this->deny(401, 'invalid_token', AuthenticatedUser::REAUTHENTICATION_MESSAGE);
        }
        ApiContext::set($resolved['identity'], $resolved['token_hash']);
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        ApiContext::clear();
    }

    private function deny(int $status, string $code, string $message): ResponseInterface
    {
        $response = service('response')->setStatusCode($status)->setHeader('Cache-Control', 'private, no-store');
        if ($status === 429) {
            $response->setHeader('Retry-After', '60');
        }
        return $response->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}
