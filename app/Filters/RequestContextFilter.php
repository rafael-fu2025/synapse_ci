<?php

declare(strict_types=1);

namespace App\Filters;

use App\Services\CurrentTenant;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Prevent process-static tenant state from crossing HTTP request boundaries. */
final class RequestContextFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null): void
    {
        CurrentTenant::reset();
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
        CurrentTenant::reset();
    }
}
