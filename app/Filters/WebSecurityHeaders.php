<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\SecureHeaders;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Required after-filter, including rejected requests and unknown routes. */
final class WebSecurityHeaders extends SecureHeaders
{
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $framePolicy = $response->getHeaderLine('X-Frame-Options');
        parent::after($request, $response, $arguments);
        if (strtoupper($framePolicy) === 'DENY') {
            $response->setHeader('X-Frame-Options', 'DENY');
        }
        $response->setHeader('X-XSS-Protection', '0');
        $response->setHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), serial=(self)');
        $response->setHeader('Cache-Control', 'private, no-store');
        if (!$response->hasHeader('Content-Security-Policy')) {
            // Enforce low-risk navigation/plugin restrictions while strict script/style CSP is observed.
            $response->setHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'");
        }
        return $response;
    }
}
