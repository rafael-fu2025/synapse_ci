<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\AuthenticatedUser;
use App\Services\PortalContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class PortalAccessFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($request, false, 'Please sign in to access this portal.', '/auth/login');
        }

        $portal = !empty($arguments[0]) ? (string) $arguments[0] : PortalContext::current();
        if (!PortalContext::isRolePermitted($user['role'], $portal)) {
            $meta = PortalContext::metadata($portal);
            return AuthenticatedUser::denied(
                $request,
                true,
                "Access Restricted: This console is reserved for {$meta['title']} authorized staff. You have been directed to the Campus Health Portal.",
                '/portal'
            );
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
