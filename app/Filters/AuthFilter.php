<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\AuthenticatedUser;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

final class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $user = (new AuthenticatedUser())->current();
        if ($user === null) {
            return AuthenticatedUser::denied($request, false, 'Please sign in to continue.', '/auth/login');
        }

        foreach ($arguments ?? [] as $permission) {
            if ($user['role'] !== 'superadmin' && !in_array($permission, $user['permissions'], true)) {
                return AuthenticatedUser::denied($request, true, 'You do not have permission to access that resource.', '/');
            }
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
