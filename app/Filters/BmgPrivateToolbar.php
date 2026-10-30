<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class BmgPrivateToolbar extends DebugToolbar
{
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $path = $request->getUri()->getPath();

        // Cover rejected and alternate-ID spellings too: their request bodies can still contain secrets.
        // Skip collection itself; hiding the toolbar UI still persists debugbar data.
        if (preg_match('~(?:^|/)facilities/unit/[^/]+/(?:usb/build(?:/|$)|usb/releases/[^/]+/parts(?:/|$)|device(?:/|$))~', $path)
            || preg_match('~(?:^|/)api/v1/devices/bmg(?:/|$)~', $path)
            || preg_match('~(?:^|/)admin/ai(?:/|$)~i', rawurldecode($path))
            || preg_match('~(?:^|/)(?:clinic|guidance|facilities)/insights(?:/|$)~i', rawurldecode($path))) {
            return null;
        }

        return parent::after($request, $response, $arguments);
    }
}
