<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Dedicated hosts constrain workspace routing; existing route/domain guards still authorize data. */
final class PortalHostFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $host = PortalContext::hostPortal();
        if ($host === null) return null; // Single-host development/path fallback.
        $actor = (new AuthenticatedUser())->current();
        if ($actor === null) return AuthenticatedUser::denied($request, false, 'Please sign in to continue.', '/auth/login');
        $json = $request->isAJAX() || str_contains(strtolower($request->getHeaderLine('Accept')), 'json')
            || strtolower($request->getHeaderLine('HX-Request')) === 'true';
        $get = strtoupper($request->getMethod()) === 'GET';
        if (!PortalContext::compatibleHost($actor['role'])) {
            if (!$get || $json) return $this->deny('This account cannot use this workstation.', PortalContext::landing($actor['role']));
            return PortalContext::accessNotice($actor['role']);
        }
        $route = PortalContext::routePortal();
        if ($route === null || $route === $host) return null;
        if (!PortalContext::isRolePermitted($actor['role'], $route)) {
            return $this->deny('Department access forbidden.', PortalContext::landing($actor['role']));
        }
        $url = PortalContext::urlFor($route, service('uri')->getRoutePath());
        // Encode query values, never concatenate an untrusted return URL/Host header.
        $query = $request->getGet();
        if ($query !== []) $url .= '?' . http_build_query($query);
        if (!$get || $json) return $this->deny('Open this workspace on its own portal. Requests are not forwarded between hosts.', $url);
        return redirect()->to($url)->setHeader('Cache-Control', 'private, no-store');
    }

    private function deny(string $message, string $url): ResponseInterface
    {
        return service('response')->setStatusCode(403)->setHeader('Cache-Control', 'private, no-store')
            ->setJSON(['success' => false, 'message' => $message, 'portal_url' => $url]);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
