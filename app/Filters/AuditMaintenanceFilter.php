<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Required before routing, including device requests, during a ledger cutover. */
final class AuditMaintenanceFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (is_file(WRITEPATH . 'audit-maintenance.flag')) {
            return service('response')->setStatusCode(503)->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('Retry-After', '60')->setJSON(['success' => false, 'message' => 'Synapse is temporarily unavailable for audit maintenance. Please retry shortly.']);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}
