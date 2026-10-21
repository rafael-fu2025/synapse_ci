<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Explicit hosts only. Keep deployment hosts in App::$allowedHostnames as well. */
final class PortalSites extends BaseConfig
{
    public array $families = [
        'local' => [
            'main' => 'synapse.localhost', 'clinic' => 'clinic.localhost',
            'guidance' => 'guidance.localhost', 'bmg' => 'bmg.localhost',
        ],
        'university' => [
            'main' => 'synapse.foundationu.com', 'clinic' => 'clinic.foundationu.com',
            'guidance' => 'guidance.foundationu.com', 'bmg' => 'bmg.foundationu.com',
        ],
    ];

    public array $aliases = [
        'facilities.localhost' => ['family' => 'local', 'portal' => 'bmg'],
    ];
}
